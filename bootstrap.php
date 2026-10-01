<?php
declare(strict_types=1);
/**
 * Общая загрузка: конфиг, учётки БД, сессия, вход, CSRF.
 *
 * Учётки БД (config.php):
 *   db_ro_user / db_ro_pass  — только чтение (проверка, расчёт, подсказки);
 *   db_rw_user / db_rw_pass  — запись, ТОЛЬКО для выполнения сгенерированного SQL;
 *   db_q_user  / db_q_pass   — анкета (INSERT в aux_questionaries + чтение справочников).
 * Для обратной совместимости db_user / db_pass используются, если нужной пары нет.
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

mb_internal_encoding('UTF-8');

// PHP ≥ 8.1 по умолчанию бросает исключения на любую ошибку mysqli, а код написан под классическое поведение
// (false/null + проверка errno, подавление через @). Единый режим для всех версий PHP:
mysqli_report(MYSQLI_REPORT_OFF);

/** Экранирование для HTML. Принимает что угодно (числа, null) — без TypeError при strict_types. */
if (!function_exists('h')) {
    function h($s): string
    {
        if (is_array($s) || is_object($s)) {
            return '';
        }
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** @return array<string,mixed> */
function app_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $path = __DIR__ . '/config.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
    }
    return $cfg;
}

function cfg_pair(string $prefix): array
{
    $c = app_config();
    $user = $c[$prefix . '_user'] ?? ($c['db_user'] ?? null);
    $pass = $c[$prefix . '_pass'] ?? ($c['db_pass'] ?? null);
    return [$user, $pass];
}

$__cfg = app_config();
if (isset($__cfg['db_host'], $__cfg['db_name'])) {
    [$__ro_u, $__ro_p] = cfg_pair('db_ro');
    [$__rw_u, $__rw_p] = cfg_pair('db_rw');
    define('DB_HOST', (string)$__cfg['db_host']);
    define('DB_NAME', (string)$__cfg['db_name']);
    // DB_USER / DB_PASS — чтение (так их ждёт остальной код)
    define('DB_USER', (string)$__ro_u);
    define('DB_PASS', (string)$__ro_p);
    define('DB_RW_USER', (string)$__rw_u);
    define('DB_RW_PASS', (string)$__rw_p);
}
unset($__cfg, $__ro_u, $__ro_p, $__rw_u, $__rw_p);

/**
 * Открыть соединение. При любой ошибке (в том числе исключение mysqli на PHP ≥ 8.1) — null.
 * Подробности (пользователь, хост) пишутся только в журнал ошибок, не на экран.
 */
function db_open(string $host, string $user, string $pass, string $name, string $tag): ?mysqli
{
    try {
        $m = @new mysqli($host, $user, $pass, $name);
    } catch (Throwable $e) {
        error_log("FSBR toolkit: {$tag} connect failed: " . $e->getMessage());
        return null;
    }
    if ($m->connect_errno) {
        error_log("FSBR toolkit: {$tag} connect failed: " . $m->connect_error);
        return null;
    }
    $m->set_charset('utf8mb4');
    return $m;
}

/**
 * Текст JSON в UTF-8: снимает BOM, понимает UTF-16 LE/BE (так сохраняет Блокнот/PowerShell) и cp1251 как запасной вариант.
 */
function json_text_to_utf8(string $raw): string
{
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        return substr($raw, 3);
    }
    if (strncmp($raw, "\xFF\xFE", 2) === 0) {
        return (string)mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }
    if (strncmp($raw, "\xFE\xFF", 2) === 0) {
        return (string)mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    }
    // UTF-16 без BOM: много нулевых байтов
    if (strlen($raw) >= 4 && substr_count(substr($raw, 0, 200), "\0") > 20) {
        $enc = ($raw[0] === "\0") ? 'UTF-16BE' : 'UTF-16LE';
        return (string)mb_convert_encoding($raw, 'UTF-8', $enc);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        return (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
    }
    return $raw;
}

/** Понятное сообщение об ошибке загрузки файла из $_FILES[...]; null — ошибки нет. */
function upload_error_message(?array $f): ?string
{
    if ($f === null || !isset($f['error'])) {
        return null;
    }
    $e = is_array($f['error']) ? UPLOAD_ERR_PARTIAL : (int)$f['error'];
    switch ($e) {
        case UPLOAD_ERR_OK:
        case UPLOAD_ERR_NO_FILE:
            return null; // «нет файла» обрабатывает вызывающий код
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Файл больше допустимого размера загрузки на сервере (upload_max_filesize / post_max_size)';
        case UPLOAD_ERR_PARTIAL:
            return 'Файл загрузился не полностью — повторите';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'На сервере нет места или временной папки для загрузки';
        default:
            return 'Ошибка загрузки файла (код ' . $e . ')';
    }
}

/** Защита от «zip-бомбы»: суммарный распакованный размер архива не больше $max байт. */
function zip_size_ok(ZipArchive $zip, int $max = 200 * 1024 * 1024): bool
{
    $sum = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            return false;
        }
        $sum += (int)$st['size'];
        if ($sum > $max) {
            return false;
        }
    }
    return true;
}

/** Соединение только для чтения; null, если БД недоступна или не настроена. */
function db_ro(): ?mysqli
{
    if (!defined('DB_HOST')) {
        return null;
    }
    return db_open(DB_HOST, DB_USER, DB_PASS, DB_NAME, 'RO');
}

/** Соединение с правом записи — только для выполнения SQL, сформированного приложением. */
function db_rw(): mysqli
{
    if (!defined('DB_HOST')) {
        throw new RuntimeException('Нет настроек БД (config.php)');
    }
    $m = db_open(DB_HOST, DB_RW_USER, DB_RW_PASS, DB_NAME, 'RW');
    if ($m === null) {
        throw new RuntimeException('Не удалось подключиться к БД с правом записи');
    }
    return $m;
}

/** Соединение для анкеты. */
function db_questionnaire(): ?mysqli
{
    $c = app_config();
    if (!isset($c['db_host'], $c['db_name'])) {
        return null;
    }
    [$u, $p] = cfg_pair('db_q');
    return db_open((string)$c['db_host'], (string)$u, (string)$p, (string)$c['db_name'], 'Q');
}

// ---------------------------------------------------------------- сессия

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('FSBRSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $have = $_SESSION['csrf'] ?? '';
    return is_string($sent) && is_string($have) && $have !== '' && hash_equals($have, $sent);
}

/** Прервать запрос, если POST без верного CSRF-токена. */
function csrf_enforce(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (csrf_valid()) {
        return;
    }
    http_response_code(403);
    $tooBig = ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) && empty($_POST) && empty($_FILES);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>403</title><body style="font-family:system-ui;padding:2em">';
    echo $tooBig
        ? '<h1>Файл слишком большой</h1><p>Превышен post_max_size / upload_max_filesize на сервере.</p>'
        : '<h1>Запрос отклонён</h1><p>Неверный или устаревший CSRF-токен. Обновите страницу и повторите действие.</p>';
    echo '<p><a href="?">← на главную</a></p></body>';
    exit;
}

// ---------------------------------------------------------------- вход

function app_data_dir(): string
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $ix = $dir . '/index.html';
    if (!is_file($ix)) {
        @file_put_contents($ix, '');
    }
    return $dir;
}

/** Первая строка файла данных: при прямом открытии по URL сервер выполнит её и ничего не отдаст. */
const DATA_GUARD = "<?php http_response_code(404); exit; ?>\n";

/** Прочитать JSON из защищённого файла данных (старый «голый» JSON тоже читается). */
function data_read_json(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = (string)@file_get_contents($file);
    if (str_starts_with($raw, DATA_GUARD)) {
        $raw = substr($raw, strlen(DATA_GUARD));
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function data_write_json(string $file, array $data, int $flags = 0): void
{
    @file_put_contents($file, DATA_GUARD . json_encode($data, $flags), LOCK_EX);
}

function client_ip(): string
{
    // только REMOTE_ADDR: X-Forwarded-For подделывается клиентом
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 900;

/** @return array{locked:bool,wait:int} */
function login_throttle_state(): array
{
    $file = app_data_dir() . '/login_fail.json.php';
    $key = hash('sha256', client_ip());
    $data = data_read_json($file);
    $row = $data[$key] ?? null;
    if (is_array($row) && ($row['n'] ?? 0) >= LOGIN_MAX_FAILS) {
        $wait = (int)$row['t'] + LOGIN_LOCK_SECONDS - time();
        if ($wait > 0) {
            return ['locked' => true, 'wait' => $wait];
        }
    }
    return ['locked' => false, 'wait' => 0];
}

function login_throttle_record(bool $success): void
{
    $file = app_data_dir() . '/login_fail.json.php';
    $key = hash('sha256', client_ip());
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return;
    }
    flock($fh, LOCK_EX);
    $raw = (string)stream_get_contents($fh);
    if (str_starts_with($raw, DATA_GUARD)) {
        $raw = substr($raw, strlen(DATA_GUARD));
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    $now = time();
    foreach ($data as $k => $row) { // чистка старых записей
        if (!is_array($row) || ($row['t'] ?? 0) + LOGIN_LOCK_SECONDS < $now) {
            unset($data[$k]);
        }
    }
    if ($success) {
        unset($data[$key]);
    } else {
        $n = (int)($data[$key]['n'] ?? 0) + 1;
        $data[$key] = ['n' => $n, 't' => $now];
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, DATA_GUARD . json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function auth_is_logged_in(): bool
{
    if (empty($_SESSION['auth_ok'])) {
        return false;
    }
    $idle = (int)(app_config()['session_idle_seconds'] ?? 28800); // 8 часов
    $last = (int)($_SESSION['auth_last'] ?? 0);
    if ($last > 0 && time() - $last > $idle) {
        unset($_SESSION['auth_ok'], $_SESSION['auth_last']);
        return false;
    }
    $_SESSION['auth_last'] = time();
    return true;
}

function auth_render_login(?string $error, ?string $fatal = null): void
{
    http_response_code($fatal ? 503 : 200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>FSBR Toolkit — вход</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f1419;color:#e7ecf3;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}
.box{background:#1a2332;border-radius:14px;padding:28px;width:340px;max-width:92vw}
h1{font-size:1.2rem;margin:0 0 16px}
label{display:block;margin:12px 0 4px;font-size:.9rem;color:#94a3b8}
input{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#e7ecf3}
button{width:100%;margin-top:18px;background:#3b82f6;color:#fff;border:0;padding:12px;border-radius:8px;font-size:1rem;cursor:pointer}
.err{background:rgba(239,68,68,.15);color:#fca5a5;padding:10px;border-radius:8px;margin-bottom:12px;font-size:.9rem}
code{background:#0f172a;padding:2px 5px;border-radius:4px}
</style></head><body><div class="box">
<h1>FSBR Toolkit</h1>
<?php if ($fatal): ?>
  <div class="err"><?= $e($fatal) ?></div>
<?php else: ?>
  <?php if ($error): ?><div class="err"><?= $e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <input type="hidden" name="do_login" value="1">
    <label>Логин</label>
    <input type="text" name="login" autocomplete="username" required autofocus>
    <label>Пароль</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Войти</button>
  </form>
<?php endif; ?>
</div></body></html>
    <?php
    exit;
}

/** Требовать вход; без настроенного пароля — отказ (fail closed). */
function auth_require(): void
{
    $c = app_config();
    $user = (string)($c['auth_user'] ?? '');
    $hash = (string)($c['auth_pass_hash'] ?? '');
    if ($user === '' || $hash === '') {
        auth_render_login(null, 'Вход не настроен: задайте auth_user и auth_pass_hash в config.php '
            . '(хеш: php tools/hash_password.php). Без этого доступ закрыт.');
    }

    // выход
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['do_logout'])) {
        csrf_enforce();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'],
                'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
        exit;
    }

    if (auth_is_logged_in()) {
        return;
    }

    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['do_login'])) {
        csrf_enforce();
        $st = login_throttle_state();
        if ($st['locked']) {
            $error = 'Слишком много неудачных попыток. Повторите через ' . (int)ceil($st['wait'] / 60) . ' мин.';
        } else {
            $okUser = hash_equals($user, (string)($_POST['login'] ?? ''));
            $okPass = password_verify((string)($_POST['password'] ?? ''), $hash); // всегда считаем хеш
            if ($okUser && $okPass) {
                login_throttle_record(true);
                session_regenerate_id(true);
                $_SESSION['auth_ok'] = true;
                $_SESSION['auth_last'] = time();
                unset($_SESSION['csrf']); // новый токен после входа
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
                exit;
            }
            login_throttle_record(false);
            usleep(400000);
            $error = 'Неверный логин или пароль';
        }
    }
    auth_render_login($error);
}

// ---------------------------------------------------------------- проверка SQL перед выполнением

function sql_normalize(string $s): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $s));
}

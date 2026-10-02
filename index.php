<?php
declare(strict_types=1);
/**
 * FSBR Toolkit: турниры и клубные МБ — проверка → расчёт → SQL
 */
require_once __DIR__ . '/bootstrap.php';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
app_session_start();
auth_require();   // без входа дальше не идём
csrf_enforce();   // все POST — только с верным CSRF-токеном

$tab = $_GET['tab'] ?? $_POST['tab'] ?? 'check';
if (!in_array($tab, ['check', 'rating', 'sql', 'history', 'anketa', 'admin'], true)) {
    $tab = 'check';
}

// JSON с проверки хранится в сессии (не тащим огромный JSON в hidden-поле формы)
if ($tab === 'rating' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['from_check_json'])) {
    $_SESSION['report_json'] = $_POST['from_check_json'];
}
if ($tab === 'rating' && !empty($_SESSION['report_json'])) {
    // ручная загрузка файла/textarea имеет приоритет при POST расчёта
    if (empty($_FILES['json']['tmp_name']) && (empty($_POST['json_text']) || !empty($_POST['from_session']))) {
        $GLOBALS['prefill_json'] = $_SESSION['report_json'];
    } elseif (empty($_FILES['json']['tmp_name']) && empty($_POST['json_text'])) {
        $GLOBALS['prefill_json'] = $_SESSION['report_json'];
    }
}
// при открытии вкладки расчёта всегда подставляем сессию, если нет свежей ручной загрузки
if ($tab === 'rating' && empty($GLOBALS['prefill_json']) && !empty($_SESSION['report_json'])
    && empty($_FILES['json']['tmp_name']) && empty($_POST['json_text'])) {
    $GLOBALS['prefill_json'] = $_SESSION['report_json'];
}

function toolkit_nav(string $active): void {
    $tabs = [
        'check' => '1. Проверка отчёта',
        'rating' => '2. Расчёт РО / ПБ / МБ',
        'sql' => '3. SQL',
        'history' => 'История',
        'anketa' => 'Анкеты',
        'admin' => 'Справочники',
    ];
    echo '<nav class="tabs">';
    foreach ($tabs as $id => $label) {
        $cls = $id === $active ? 'tab active' : 'tab';
        echo '<a class="' . $cls . '" href="?tab=' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</a>';
    }
    echo '</nav>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FSBR Toolkit — отчёты и рейтинг</title>
<style>
:root{--bg:#0f1419;--card:#1a2332;--accent:#3b82f6;--text:#e7ecf3;--muted:#94a3b8;--ok:#22c55e;--err:#ef4444;--warn:#f59e0b}
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:0}
.header{padding:16px 24px 0;max-width:1100px;margin:0 auto}
.header h1{font-size:1.25rem;margin:0 0 4px}
.header .sub{color:var(--muted);font-size:.9rem;margin:0 0 12px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:0;border-bottom:1px solid #2a3548;padding-bottom:0}
.tab{display:inline-block;padding:10px 16px;color:var(--muted);text-decoration:none;border-radius:8px 8px 0 0;font-size:.95rem}
.tab:hover{color:var(--text);background:#1a2332}
.tab.active{color:#fff;background:var(--card);border:1px solid #2a3548;border-bottom-color:var(--card)}
.main{max-width:1100px;margin:0 auto;padding:0 24px 32px}
/* вложенные страницы без своего body padding */
.embed-wrap{padding-top:16px}
</style>
</head>
<body>
<div class="header">
  <form method="post" style="float:right;margin:0"><?= csrf_field() ?><input type="hidden" name="do_logout" value="1"><button type="submit" style="background:none;border:1px solid #2a3548;color:var(--muted);border-radius:6px;padding:4px 10px;cursor:pointer">Выйти</button></form>
  <h1>FSBR Toolkit</h1>
  <p class="sub">Турнирные протоколы и клубные МБ: проверка → расчёт РО/ПБ/МБ → SQL · история загрузок</p>
  <?php toolkit_nav($tab); ?>
</div>
<div class="main embed-wrap">
<?php
// Фатальная ошибка вкладки не должна «съедать» страницу молча: показываем причину на экране.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        echo '<div style="background:#450a0a;color:#fecaca;padding:12px;border-radius:8px;margin:12px 0">Ошибка PHP: '
            . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars(basename($e['file']) . ':' . $e['line'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
});
try {
if ($tab === 'check') {
    require __DIR__ . '/check_body.php';
} elseif ($tab === 'rating') {
    require __DIR__ . '/rating_body.php';
} elseif ($tab === 'sql') {
    require __DIR__ . '/sql_body.php';
} elseif ($tab === 'history') {
    require __DIR__ . '/history_body.php';
} elseif ($tab === 'anketa') {
    require __DIR__ . '/anketa_body.php';
} elseif ($tab === 'admin') {
    require __DIR__ . '/admin_body.php';
}
} catch (Throwable $e) {
    echo '<div style="background:#450a0a;color:#fecaca;padding:12px;border-radius:8px;margin:12px 0">Ошибка: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars(basename($e->getFile()) . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</div>';
}
?>
</div>
</body>
</html>

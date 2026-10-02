<?php
declare(strict_types=1);
/**
 * Анкета игрока ФСБР — новый игрок / обновление данных.
 * Пишет в fsbr_copy.aux_questionaries (БД из config.php).
 *
 * CREATE TABLE IF NOT EXISTS aux_questionaries (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   player_id SMALLINT UNSIGNED NULL,
 *   type CHAR(1) NULL,
 *   timestamp DATETIME NULL,
 *   firstname VARCHAR(45) NULL,
 *   lastname VARCHAR(45) NULL,
 *   surname VARCHAR(45) NULL,
 *   birthdate DATE NULL,
 *   sex TINYINT(1) NULL,
 *   city VARCHAR(45) NULL,
 *   region VARCHAR(45) NULL,
 *   phone VARCHAR(80) NULL,
 *   mail VARCHAR(80) NULL,
 *   bbo VARCHAR(45) NULL,
 *   gambler VARCHAR(45) NULL,
 *   WBF VARCHAR(45) NULL,
 *   acbl VARCHAR(45) NULL,
 *   is_sputnik TINYINT NULL,
 *   is_sirius TINYINT NULL,
 *   first_tourn DATE NULL,
 *   club_id SMALLINT UNSIGNED NULL
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Anketa.php';
app_session_start();
csrf_enforce(); // публичная форма, но POST только с токеном


function db(): ?mysqli {
    return db_questionnaire(); // отдельная учётка: INSERT только в aux_questionaries
}

// --- AJAX: подтверждение e-mail кодом (без БД) ---
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['send_code', 'check_code'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function (bool $ok, string $msg) {
        echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $reply(false, 'Неверный запрос');
    }
    $mailIn = mb_strtolower(trim((string)($_POST['mail'] ?? '')));
    if (!mail_address_ok($mailIn)) {
        $reply(false, 'Укажите корректный e-mail');
    }
    if ($_GET['ajax'] === 'send_code') {
        $last = (int)($_SESSION['mail_sent'][$mailIn] ?? 0);
        if (time() - $last < 60) {
            $reply(false, 'Повторная отправка возможна через минуту');
        }
        if (throttle_hit('mailcode_ip_' . client_ip(), 10, 3600) || throttle_hit('mailcode_to_' . $mailIn, 3, 3600)) {
            $reply(false, 'Слишком много запросов. Попробуйте позже');
        }
        $code = (string)random_int(100000, 999999);
        $_SESSION['mail_sent'][$mailIn] = time();
        $_SESSION['mail_code'] = [
            'mail' => $mailIn,
            'hash' => hash_hmac('sha256', $code, session_id()),
            'exp' => time() + 900,
            'tries' => 0,
        ];
        $okSend = app_send_mail($mailIn, 'Код подтверждения анкеты ФСБР',
            "Ваш код подтверждения e-mail: {$code}\nКод действует 15 минут.\nЕсли вы не заполняли анкету ФСБР — просто проигнорируйте письмо.\n");
        if (!$okSend) {
            $_SESSION['mail_sent'][$mailIn] = 0; // неудачная отправка не должна включать паузу
        }
        $reply($okSend, $okSend ? 'Код отправлен на ' . $mailIn : 'Не удалось отправить письмо. Сообщите организаторам');
    }
    // check_code
    $mc = $_SESSION['mail_code'] ?? null;
    if (!$mc || $mc['mail'] !== $mailIn || $mc['exp'] < time()) {
        $reply(false, 'Код не запрашивался или устарел — запросите новый');
    }
    if ((int)$mc['tries'] >= 5) {
        $reply(false, 'Слишком много попыток — запросите новый код');
    }
    $_SESSION['mail_code']['tries'] = (int)$mc['tries'] + 1;
    $given = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
    if (hash_equals((string)$mc['hash'], hash_hmac('sha256', (string)$given, session_id()))) {
        $_SESSION['mail_verified'] = array_values(array_unique(array_merge((array)($_SESSION['mail_verified'] ?? []), [$mailIn])));
        unset($_SESSION['mail_code']);
        $reply(true, 'E-mail подтверждён');
    }
    $reply(false, 'Неверный код');
}

// --- AJAX: поиск игроков ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 1 || (mb_strlen($q) < 2 && !preg_match('/^\d+$/', $q))) {
        echo json_encode(['players' => []]);
        exit;
    }
    $mysqli = db();
    if (!$mysqli) {
        echo json_encode(['error' => 'Нет подключения к БД', 'players' => []]);
        exit;
    }
    $like = '%' . $mysqli->real_escape_string($q) . '%';
    // firstname = фамилия, lastname = имя; цифры → поиск по player_id
    $idCond = '';
    if (preg_match('/^\d+$/', $q)) {
        $idCond = ' OR p.player_id = ' . (int)$q;
    } elseif (preg_match('/\d+/', $q)) {
        // смешанный ввод: вытащим чистое число как возможный ID
        if (preg_match('/\b(\d{1,6})\b/', $q, $m)) {
            $idCond = ' OR p.player_id = ' . (int)$m[1];
        }
    }
    $sql = "SELECT p.player_id, p.firstname AS family_name, p.lastname AS given_name, p.surname AS patronymic,
                   p.sex, p.city_id, c.city_name, p.club_id,
                   cl.name AS club_name, p.razr, p.state
            FROM players p
            LEFT JOIN cities c ON c.city_id = p.city_id
            LEFT JOIN clubs cl ON cl.club_id = p.club_id
            WHERE p.state NOT IN (3, 7)
              AND (p.firstname LIKE '{$like}' OR p.lastname LIKE '{$like}'
                   OR CONCAT(p.firstname,' ',p.lastname) LIKE '{$like}'
                   OR CONCAT(p.firstname,' ',p.lastname,' ',IFNULL(p.surname,'')) LIKE '{$like}'
                   {$idCond})
            ORDER BY (p.player_id = " . (preg_match('/^\d+$/', $q) ? (int)$q : 0) . ") DESC, p.firstname, p.lastname
            LIMIT 30";
    $res = $mysqli->query($sql);
    $players = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $pid = (int)$row['player_id'];
        $ext = ['bbo' => '', 'gambler' => '', 'wbf' => '', 'acbl' => ''];
        $er = $mysqli->query("SELECT bbo, gambler, wbf, acbl FROM external_ids WHERE player_id = {$pid} LIMIT 1");
        if ($er && ($ex = $er->fetch_assoc())) {
            $ext['bbo'] = (string)($ex['bbo'] ?? '');
            $ext['gambler'] = (string)($ex['gambler'] ?? '');
            $ext['wbf'] = $ex['wbf'] !== null ? (string)$ex['wbf'] : '';
            $ext['acbl'] = $ex['acbl'] !== null ? (string)$ex['acbl'] : '';
        }
        $st = ['sputnik' => 0, 'sirius' => 0, 'first' => null];
        $sr = $mysqli->query("SELECT sputnik, sirius, first FROM students WHERE player_id = {$pid} LIMIT 1");
        if ($sr && ($su = $sr->fetch_assoc())) {
            $st['sputnik'] = (int)$su['sputnik'];
            $st['sirius'] = (int)$su['sirius'];
            $st['first'] = $su['first'];
        }
        $players[] = [
            'player_id' => $pid,
            'family_name' => $row['family_name'],
            'given_name' => $row['given_name'],
            'patronymic' => $row['patronymic'],
            'sex' => $row['sex'] !== null ? (int)$row['sex'] : null,
            'birthdate' => null,
            'city_id' => $row['city_id'] !== null ? (int)$row['city_id'] : null,
            'city_name' => $row['city_name'],
            'club_id' => $row['club_id'] !== null ? (int)$row['club_id'] : null,
            'club_name' => $row['club_name'],
            'razr' => $row['razr'],
            'bbo' => $ext['bbo'],
            'gambler' => $ext['gambler'],
            'wbf' => $ext['wbf'],
            'acbl' => $ext['acbl'],
            'is_sputnik' => $st['sputnik'],
            'is_sirius' => $st['sirius'],
            'first_tourn' => $st['first'],
            'label' => trim($row['family_name'] . ' ' . $row['given_name'] . ' ' . ($row['patronymic'] ?? ''))
                . ' (ID ' . $pid . ($row['city_name'] ? ', ' . $row['city_name'] : '') . ')',
        ];
    }
    $mysqli->close();
    echo json_encode(['players' => $players], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- cities & clubs for selects ---
$cities = [];
$clubs = [];
$mysqli = db();
if ($mysqli) {
    if ($r = $mysqli->query("SELECT city_id, city_name FROM cities ORDER BY city_name")) {
        while ($row = $r->fetch_assoc()) {
            $cities[] = $row;
        }
    }
    if ($r = $mysqli->query("SELECT club_id, name, shortname FROM clubs ORDER BY name")) {
        while ($row = $r->fetch_assoc()) {
            $clubs[] = $row;
        }
    }
}

$error = null;
$success = null;
$mode = $_POST['mode'] ?? $_GET['mode'] ?? 'new';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_anketa'])) {
    try {
        if (!$mysqli) {
            throw new RuntimeException('Нет подключения к базе данных');
        }
        $mode = $_POST['mode'] ?? 'new';
        $consent = !empty($_POST['consent']);
        if (!$consent) {
            throw new RuntimeException('Необходимо согласие на обработку персональных данных');
        }

        $family = trim((string)($_POST['family_name'] ?? ''));
        $given = trim((string)($_POST['given_name'] ?? ''));
        $patronymic = trim((string)($_POST['patronymic'] ?? ''));
        $birthdate = trim((string)($_POST['birthdate'] ?? ''));
        $sex = $_POST['sex'] ?? '';
        $city = trim((string)($_POST['city'] ?? ''));
        $region = trim((string)($_POST['region'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $mail = trim((string)($_POST['mail'] ?? ''));

        if ($mode === 'new') {
            if ($family === '' || $given === '' || $patronymic === '') {
                throw new RuntimeException('Укажите фамилию, имя и отчество');
            }
            if ($birthdate === '' || $sex === '' || $city === '' || $region === '' || $phone === '' || $mail === '') {
                throw new RuntimeException('Заполните все обязательные поля');
            }
        } else {
            $playerId = (int)($_POST['player_id'] ?? 0);
            if ($playerId < 1) {
                throw new RuntimeException('Выберите игрока для обновления');
            }
            // при обновлении ФИО/дата из карточки могут быть только для отображения;
            // обязательны только выбор игрока и согласие; остальные поля — опциональные дополнения
            if ($family === '' && $given === '') {
                throw new RuntimeException('Не удалось определить игрока');
            }
        }

        $playerId = null;
        if ($mode === 'update') {
            $playerId = (int)$_POST['player_id'];
        }

        if (throttle_hit('anketa_ip_' . client_ip(), 10, 3600)) {
            throw new RuntimeException('Слишком много анкет с вашего адреса. Попробуйте позже');
        }

        // e-mail должен быть подтверждён кодом (обязательно для нового игрока, для обновления — если указан)
        $mailList = anketa_split_mails($mail);
        if (!$mailList) {
            throw new RuntimeException('Укажите e-mail и подтвердите его кодом из письма');
        }
        foreach ($mailList as $m1) {
            if (!mail_address_ok($m1)) {
                throw new RuntimeException('Некорректный e-mail: ' . $m1);
            }
        }
        $mail = implode(', ', $mailList);
        $mailMax = 80;
        if ($cr = $mysqli->query("SHOW COLUMNS FROM aux_questionaries LIKE 'mail'")) {
            if (($cc = $cr->fetch_assoc()) && preg_match('/\((\d+)\)/', (string)$cc['Type'], $mm)) {
                $mailMax = (int)$mm[1];
            }
        }
        if (mb_strlen($mail) > $mailMax) {
            throw new RuntimeException("Слишком длинный список e-mail (не более {$mailMax} символов)");
        }
        $notVerified = array_diff($mailList, (array)($_SESSION['mail_verified'] ?? []));
        if ($notVerified) {
            throw new RuntimeException('Подтвердите кодом из письма каждый адрес. Не подтверждено: ' . implode(', ', $notVerified));
        }

        // обновление: сверка введённых «для проверки» данных с предыдущей анкетой (результат пользователю не показываем)
        $verifyStatus = null;
        if ($mode === 'update') {
            $prev = ['birthdate' => null, 'phone' => null, 'mail' => null];
            // последняя заполненная анкета игрока (questionaries), иначе последняя из очереди
            foreach (['questionaries', 'aux_questionaries'] as $qt) {
                $qr = $mysqli->query("SELECT birthdate, phone, mail FROM {$qt} WHERE player_id = {$playerId}"
                    . ($qt === 'aux_questionaries' ? " AND (status IS NULL OR status <> 'rejected')" : '') . ' ORDER BY id DESC LIMIT 1');
                if ($qr && ($pr = $qr->fetch_assoc())) {
                    $prev = $pr;
                    break;
                }
            }
            if (!$prev['phone'] || !$prev['mail'] || !$prev['birthdate']) {
                $bcol = '';
                if ($cr = $mysqli->query('SHOW COLUMNS FROM players')) {
                    while ($c = $cr->fetch_assoc()) {
                        if ($bcol === '' && in_array($c['Field'], ['birthdate', 'birth_date', 'birthday', 'dob', 'bdate'], true)) {
                            $bcol = $c['Field'];
                        }
                    }
                }
                $pq = $mysqli->query('SELECT phone, mail' . ($bcol ? ", {$bcol} AS bd" : '') . " FROM players WHERE player_id = {$playerId} LIMIT 1");
                if ($pq && ($pp = $pq->fetch_assoc())) {
                    $prev['phone'] = $prev['phone'] ?: $pp['phone'];
                    $prev['mail'] = $prev['mail'] ?: $pp['mail'];
                    $prev['birthdate'] = $prev['birthdate'] ?: ($pp['bd'] ?? null);
                }
            }
            $verifyStatus = anketa_verify_compare($prev, [
                'birthdate' => trim((string)($_POST['v_birthdate'] ?? '')),
                'phone4' => trim((string)($_POST['v_phone4'] ?? '')),
                'mail' => trim((string)($_POST['v_mail'] ?? '')),
            ]);
        }
        $emailVerified = 1; // все адреса подтверждены (проверено выше)

        // новые колонки есть не во всех базах — пишем в них только если они созданы (db_setup.sql, п. 4)
        $hasExtra = false;
        if ($cr = $mysqli->query("SHOW COLUMNS FROM aux_questionaries LIKE 'verify_status'")) {
            $hasExtra = $cr->num_rows > 0;
        }

        $bbo = trim((string)($_POST['bbo'] ?? ''));
        $gambler = trim((string)($_POST['gambler'] ?? ''));
        $wbf = trim((string)($_POST['wbf'] ?? ''));
        $acbl = trim((string)($_POST['acbl'] ?? ''));
        $isSputnik = !empty($_POST['is_sputnik']) ? 1 : 0;
        $isSirius = !empty($_POST['is_sirius']) ? 1 : 0;
        $firstTourn = trim((string)($_POST['first_tourn'] ?? ''));
        $clubId = ($_POST['club_id'] ?? '') !== '' ? (int)$_POST['club_id'] : null;
        $type = $mode === 'update' ? 'e' : 'a';
        // В aux_questionaries: firstname=фамилия, lastname=имя
        $birthSql = $birthdate !== '' ? $birthdate : null;
        $sexVal = ($sex === '' || $sex === null) ? null : (int)$sex;
        $firstSql = $firstTourn !== '' ? $firstTourn : null;

        $esc = fn($v) => $v === null || $v === '' ? 'NULL' : ("'" . $mysqli->real_escape_string((string)$v) . "'");
        $escInt = fn($v) => ($v === null || $v === '') ? 'NULL' : (string)(int)$v;

        $extraCols = $extraVals = '';
        if ($hasExtra) {
            $extraCols = ', email_verified, verify_status';
            $extraVals = ', ' . $emailVerified . ', ' . ($verifyStatus === null ? 'NULL' : "'" . $mysqli->real_escape_string($verifyStatus) . "'");
        }
        // fsbr_copy.aux_questionaries (текущая БД из config)
        $sql = "INSERT INTO aux_questionaries
            (player_id, type, timestamp, firstname, lastname, surname, birthdate, sex, city, region,
             phone, mail, bbo, gambler, WBF, acbl, is_sputnik, is_sirius, first_tourn, club_id{$extraCols})
            VALUES (
              {$escInt($playerId)},
              '{$mysqli->real_escape_string($type)}',
              NOW(),
              {$esc($family)},
              {$esc($given)},
              {$esc($patronymic)},
              {$esc($birthSql)},
              {$escInt($sexVal)},
              {$esc($city)},
              {$esc($region)},
              {$esc($phone)},
              {$esc($mail)},
              {$esc($bbo)},
              {$esc($gambler)},
              {$esc($wbf)},
              {$esc($acbl)},
              {$isSputnik},
              {$isSirius},
              {$esc($firstSql)},
              {$escInt($clubId)}{$extraVals}
            )";
        if (!$mysqli->query($sql)) {
            throw new RuntimeException('Не удалось сохранить анкету: ' . $mysqli->error);
        }
        unset($_SESSION['mail_verified']);
        $success = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($mysqli) {
    $mysqli->close();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Анкета игрока ФСБР</title>
  <style>
    :root { --bg:#f0ebf8; --card:#ffffff; --text:#202124; --muted:#5f6368; --accent:#673ab7; --line:#dadce0; --ok:#188038; --err:#d93025; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: Roboto, "Segoe UI", Arial, sans-serif; background: var(--bg); color: var(--text); line-height:1.5; font-size:15px; }
    .wrap { max-width: 640px; margin: 0 auto; padding: 12px 12px 48px; }
    h1 { font-size: 1.9rem; font-weight:400; margin: 0 0 10px; }
    .sub { color: var(--text); margin: 0; font-size: .95rem; }
    .card { background: var(--card); border:1px solid var(--line); border-radius: 8px; padding: 22px 24px; margin-bottom: 12px; }
    .card.title-card { border-top: 10px solid var(--accent); padding-top: 18px; }
    form.gform { background:none; border:0; padding:0; margin:0; }
    label { display:block; margin: 18px 0 4px; font-size: .95rem; color: var(--text); }
    label .req, .req { color: var(--err); }
    input[type=text], input[type=email], input[type=tel], input[type=date], input[type=number], select, textarea {
      width: 100%; padding: 10px 12px; border: 1px solid #8a8f98; border-radius: 6px;
      background: #f1f3f9; color: var(--text); font-size: 1rem; font-family: inherit;
      box-shadow: inset 0 1px 2px rgba(0,0,0,.08);
    }
    input:hover, select:hover, textarea:hover { border-color: #5f6368; }
    input:focus, select:focus, textarea:focus { outline: 0; border-color: var(--accent); background: #fff; box-shadow: 0 0 0 2px rgba(103,58,183,.25); }
    input[type=checkbox], input[type=radio] { accent-color: var(--accent); width:18px; height:18px; }
    .modes { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
    .modes label { display:flex; align-items:center; gap:10px; margin:0; padding:6px 0; cursor:pointer; color:var(--text); }
    .btn { display:inline-block; width:auto; margin-top:0; background:var(--accent); color:#fff; border:0; padding:10px 24px; border-radius:4px; font-size:.95rem; font-weight:500; cursor:pointer; font-family:inherit; text-decoration:none; }
    .btn:hover { filter: brightness(1.08); box-shadow:0 1px 3px rgba(0,0,0,.3); }
    .btn.secondary, #btn-send-code, #btn-check-code { background:#fff; color:var(--accent); border:1px solid var(--line); }
    .flash { background: #fce8e6; color:#a50e0e; border:1px solid #f4c7c3; padding:12px 16px; border-radius:8px; margin-bottom:12px; }
    .ok { background: #e6f4ea; color:#137333; padding:12px; border-radius:8px; margin-bottom:14px; }
    .note { font-size:.8rem; color:var(--muted); margin:4px 0 0; }
    .warn-box { background: #fff; border:1px solid var(--line); border-left:4px solid #f9ab00; color:var(--text); padding:12px 16px; border-radius:8px; margin-bottom:12px; font-size:.9rem; }
    #search-results { list-style:none; margin:8px 0 0; padding:0; max-height:220px; overflow:auto; border:1px solid var(--line); border-radius:8px; display:none; background:#fff; }
    #search-results li { padding:10px 12px; cursor:pointer; border-bottom:1px solid var(--line); }
    #search-results li:hover { background:#f3effa; }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 20px; }
    @media (max-width:560px) { .grid2 { grid-template-columns:1fr; } .card { padding:18px 16px; } }
    .consent { font-size:.85rem; color:var(--text); border:1px solid var(--line); padding:12px; border-radius:8px; margin-top:8px; background:#fafafa; }
    .section-title { font-size:1.15rem; margin:0 0 4px; color:var(--text); font-weight:500; }
    .footer-note { color:var(--muted); font-size:.75rem; text-align:center; margin-top:16px; }
  </style>
</head>
<body>
<div class="wrap">
  <?php
    // шапка: banner.png (логотип ФСБР на сине-красном фоне) рядом с questionnaire.php; нет файла — картинка из Google-формы
    $logoSrc = 'https://lh6.googleusercontent.com/zPAalcF_88Vlp02_agJ2gjQtOnDL7Z8qhwiFOLHnn57OfXsF2I2lsWHyqLsqBmjmMcPV4ZlyXcB2sks=w1200-h630-p';
    foreach (['banner.png', 'banner.jpg', 'banner.svg'] as $lf) {
        if (is_file(__DIR__ . '/' . $lf)) {
            $logoSrc = $lf;
            break;
        }
    }
  ?>
  <div class="card title-card" style="padding:0;overflow:hidden">
    <img src="<?= h($logoSrc) ?>" alt="ФСБР" referrerpolicy="no-referrer" style="display:block;width:100%;height:auto">
    <div style="padding:18px 24px 22px">
    <h1>Анкета игрока ФСБР</h1>
    <p class="sub">Федерация спортивного бриджа России. Информация из анкеты появится на сайте в начале следующего месяца.</p>
    <p class="note" style="margin-top:12px"><span class="req">*</span> Обязательные поля</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="card" style="text-align:center;padding:36px 24px">
    <div style="font-size:2.5rem;margin-bottom:12px">✓</div>
    <h2 style="margin:0 0 12px;font-size:1.35rem;font-weight:500;color:var(--ok)">Данные успешно внесены</h2>
    <p style="color:var(--muted);margin:0 0 8px;line-height:1.5">
      Информация на сайте обновится в течение месяца.
    </p>
    <p style="font-size:1.1rem;margin:16px 0 24px">Спасибо!</p>
    <a class="btn" href="questionnaire.php">Отправить ещё одну анкету</a>
  </div>
  <?php else: ?>

  <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>

  <div class="warn-box">
    <?php $photoMail = (string)(app_config()['photo_mail'] ?? ''); ?>
    Фото на сайт: отправьте<?= $photoMail !== '' ? ' на ' . h($photoMail) : ' организаторам' ?> с указанием ID игрока.
  </div>

  <form method="post" id="anketa-form" class="gform">
  <div class="card"><?= csrf_field() ?>
    <input type="hidden" name="submit_anketa" value="1">
    <input type="hidden" name="player_id" id="player_id" value="">

    <div class="modes">
      <label><input type="radio" name="mode" value="new" <?= $mode !== 'update' ? 'checked' : '' ?> onchange="setMode('new')"> Новый игрок</label>
      <label><input type="radio" name="mode" value="update" <?= $mode === 'update' ? 'checked' : '' ?> onchange="setMode('update')"> Обновление данных</label>
    </div>

    <div id="block-search" style="<?= $mode === 'update' ? '' : 'display:none' ?>">
      <label>Поиск игрока по ФИО или ID</label>
      <input type="text" id="search-q" placeholder="Фамилия, имя или ID…" autocomplete="off">
      <ul id="search-results"></ul>
      <p class="note" id="selected-label"></p>
      <div id="block-verify" style="display:none">
        <p class="section-title">Подтверждение личности</p>
        <p class="note">Введите данные из вашей прошлой анкеты — так мы убедимся, что вы обновляете свою карточку.</p>
        <div class="grid2">
          <div>
            <label>Дата рождения</label>
            <input type="date" name="v_birthdate" id="v_birthdate">
          </div>
          <div>
            <label>Телефон: последние 4 цифры</label>
            <input type="text" name="v_phone4" id="v_phone4" inputmode="numeric" maxlength="4" autocomplete="off">
          </div>
        </div>
        <label>E-mail из прошлой анкеты</label>
        <input type="text" name="v_mail" id="v_mail" autocomplete="off">
      </div>
    </div>

  </div>
  <div class="card">
    <p class="section-title">Основные данные</p>

    <div class="grid2">
      <div>
        <label>Фамилия <span class="req" data-req="new">*</span></label>
        <input type="text" name="family_name" id="family_name" <?= $mode === 'new' ? 'required' : '' ?>>
      </div>
      <div>
        <label>Имя <span class="req" data-req="new">*</span></label>
        <input type="text" name="given_name" id="given_name" <?= $mode === 'new' ? 'required' : '' ?>>
      </div>
    </div>
    <label>Отчество <span class="req" data-req="new">*</span></label>
    <input type="text" name="patronymic" id="patronymic" <?= $mode === 'new' ? 'required' : '' ?>>

    <div class="grid2">
      <div>
        <label>Дата рождения <span class="req" data-req="new">*</span></label>
        <input type="date" name="birthdate" id="birthdate" <?= $mode === 'new' ? 'required' : '' ?>>
        <p class="note" data-req="update-note" style="display:none">Не подставляется из базы (непубличные данные). Укажите, только если нужно обновить.</p>
      </div>
      <div>
        <label>Пол <span class="req" data-req="new">*</span></label>
        <select name="sex" id="sex" <?= $mode === 'new' ? 'required' : '' ?>>
          <option value="">—</option>
          <option value="1">Мужской</option>
          <option value="0">Женский</option>
        </select>
      </div>
    </div>

    <div class="grid2">
      <div>
        <label>Город <span class="req" data-req="new">*</span></label>
        <input type="text" name="city" id="city" list="city-list" <?= $mode === 'new' ? 'required' : '' ?> placeholder="как в анкете / карточке">
        <datalist id="city-list">
          <?php foreach ($cities as $c): ?>
          <option value="<?= h($c['city_name']) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label>Регион <span class="req" data-req="new">*</span></label>
        <input type="text" name="region" id="region" <?= $mode === 'new' ? 'required' : '' ?> placeholder="область / край / республика">
      </div>
    </div>

    <label>Телефон <span class="req" data-req="new">*</span></label>
    <input type="tel" name="phone" id="phone" placeholder="7XXXXXXXXXX" <?= $mode === 'new' ? 'required' : '' ?>>
    <p class="note">Формат 7XXXXXXXXXX (без +), можно несколько через запятую</p>

    <label>E-mail <span class="req">*</span></label>
    <input type="text" name="mail" id="mail" placeholder="email@example.com" <?= $mode === 'new' ? 'required' : '' ?>>
    <div id="mail-verify" style="margin-top:8px">
      <button type="button" id="btn-send-code" class="btn" style="padding:6px 14px">Отправить код на e-mail</button>
      <span id="code-box" style="display:none">
        <input type="text" id="mail-code" inputmode="numeric" maxlength="6" placeholder="код из письма" style="width:160px;margin:8px 8px 0 0;display:inline-block" autocomplete="off">
        <button type="button" id="btn-check-code" class="btn" style="padding:6px 14px">Подтвердить</button>
      </span>
      <p class="note" id="mail-status"></p>
    <p class="note">Можно указать несколько адресов через запятую. Каждый подтверждается своим кодом: нажмите «Отправить код», введите его, затем повторите для следующего адреса.</p>
    </div>

  </div>
  <div class="card">
    <p class="section-title">Дополнительно <?= $mode === 'update' ? '(новые данные — по желанию)' : '' ?></p>

    <div class="grid2">
      <div>
        <label>Ник на BBO</label>
        <input type="text" name="bbo" id="bbo">
      </div>
      <div>
        <label>Ник на Gambler</label>
        <input type="text" name="gambler" id="gambler">
      </div>
    </div>
    <div class="grid2">
      <div>
        <label>ID WBF</label>
        <input type="text" name="wbf" id="wbf">
      </div>
      <div>
        <label>ID ACBL</label>
        <input type="text" name="acbl" id="acbl">
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:10px;margin-top:14px">
      <input type="checkbox" name="is_sputnik" id="is_sputnik" value="1"> Выпускник школы «Спутник»
    </label>
    <label style="display:flex;align-items:center;gap:10px;margin-top:6px">
      <input type="checkbox" name="is_sirius" id="is_sirius" value="1"> Сириус
    </label>

    <label>Дата первого турнира (если менее трёх лет)</label>
    <input type="date" name="first_tourn" id="first_tourn">

    <label>Клуб</label>
    <select name="club_id" id="club_id">
      <option value="">— не указан —</option>
      <?php foreach ($clubs as $cl): ?>
      <option value="<?= (int)$cl['club_id'] ?>"><?= h($cl['shortname'] ?: $cl['name']) ?></option>
      <?php endforeach; ?>
    </select>

  </div>
  <div class="card">
    <p class="section-title">Согласие на обработку персональных данных</p>
    <div class="consent">
      <p style="margin:0 0 8px">Настоящим я даю согласие Общероссийской общественной организации «Федерации спортивного бриджа России» (далее – «ФСБР»), осуществлять с использованием средств автоматизации и/или без таковых обработку всех моих персональных данных, не ограничиваясь, но включая: фамилия, имя, отчество, регион проживания, адрес электронной почты, номер телефона, дата рождения, информация о моем участии в соревнованиях ФСБР, фотография; включая сбор, запись, систематизацию, накопление, хранение, уточнение (обновление, изменение), извлечение, использование, передачу, обезличивание, блокирование, удаление, уничтожение в целях моего участия в мероприятиях ФСБР.</p>
      <p style="margin:0 0 8px">Я подтверждаю, что предоставленные мною персональные данные являются полными, актуальными и достоверными, и согласен(-на) с тем, что настоящее согласие является конкретным, информированным и сознательным. Дополнительно подтверждаю, что являюсь лицом старше 18 лет и на момент дачи согласия являюсь дееспособным.</p>
      <p style="margin:0 0 8px">Я уведомлен(-а) о том, что ФСБР не осуществляет дополнительной идентификации и контроля за дееспособностью, и исходит из того, что я предоставляю достоверную и достаточную персональную информацию. Я обязуюсь своевременно извещать ФСБР об изменении предоставленных персональных данных.</p>
      <p style="margin:0 0 8px">Настоящее согласие дается до истечения сроков хранения соответствующей информации или документов, содержащих вышеуказанную информацию, определяемых в соответствии с законодательством Российской Федерации. Отзыв настоящего согласия может быть произведен в письменной форме путем направления мною соответствующего письменного уведомления ФСБР не менее чем за 3 (три) месяца до момента отзыва согласия.</p>
      <p style="margin:0 0 8px">Настоящим согласием я разрешаю размещение на сайте ФСБР моих персональных данных в части Фамилия, имя отчество, фотография, данные об участии в соревнованиях ФСБР и показанных результатах на соревнованиях.</p>
    </div>
    <label style="display:flex;align-items:flex-start;gap:10px;margin-top:14px">
      <input type="checkbox" name="consent" value="1" required style="margin-top:4px">
      <span>Согласен(на) на обработку персональных данных <span class="req">*</span></span>
    </label>

  </div>
    <button type="submit" class="btn">Отправить анкету</button>
  </form>
</div>
<script>
var verifiedMails = [];
function postJson(url, data) {
  var fd = new FormData();
  var tok = document.querySelector('#anketa-form input[name=csrf]');
  if (tok) fd.append('csrf', tok.value);
  Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
  return fetch(url, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r) { return r.json(); });
}
function mailList() {
  var out = [];
  document.getElementById('mail').value.split(/[,;\s]+/).forEach(function(x) {
    x = x.trim().toLowerCase();
    if (x && out.indexOf(x) < 0) out.push(x);
  });
  return out;
}
function pendingMails() { return mailList().filter(function(m) { return verifiedMails.indexOf(m) < 0; }); }
function mailStatusText() {
  var p = pendingMails(), l = mailList();
  if (!l.length) return '';
  if (!p.length) return 'Подтверждены все адреса';
  return 'Ожидает подтверждения: ' + p.join(', ');
}
var curMail = '';
document.getElementById('btn-send-code').addEventListener('click', function() {
  var st = document.getElementById('mail-status');
  var p = pendingMails();
  if (!p.length) { st.textContent = mailList().length ? 'Все адреса уже подтверждены' : 'Укажите e-mail'; return; }
  curMail = p[0];
  st.textContent = 'Отправляем код на ' + curMail + '…';
  postJson('?ajax=send_code', {mail: curMail}).then(function(d) {
    st.textContent = d.msg;
    if (d.ok) document.getElementById('code-box').style.display = 'inline';
  }).catch(function() { st.textContent = 'Ошибка сети'; });
});
document.getElementById('btn-check-code').addEventListener('click', function() {
  var st = document.getElementById('mail-status');
  postJson('?ajax=check_code', {mail: curMail, code: document.getElementById('mail-code').value}).then(function(d) {
    if (d.ok) {
      verifiedMails.push(curMail);
      document.getElementById('code-box').style.display = 'none';
      document.getElementById('mail-code').value = '';
      st.textContent = d.msg + '. ' + mailStatusText();
    } else {
      st.textContent = d.msg;
    }
  }).catch(function() { st.textContent = 'Ошибка сети'; });
});
document.getElementById('mail').addEventListener('input', function() {
  document.getElementById('mail-status').textContent = mailStatusText();
});
// исходные значения выбранного игрока: при отправке «не изменено» не передаём (в анкете остаются только явные правки)
var origValues = {};
document.getElementById('anketa-form').addEventListener('submit', function(e) {
  var mode = document.querySelector('input[name=mode]:checked').value;
  if (!mailList().length || pendingMails().length) {
    e.preventDefault();
    document.getElementById('mail-status').textContent = mailList().length
      ? 'Подтвердите кодом каждый адрес. ' + mailStatusText() : 'Укажите e-mail';
    return;
  }
  if (mode === 'update') {
    ['sex','city','region','bbo','gambler','wbf','acbl','first_tourn','club_id'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el && origValues[id] !== undefined && el.value === origValues[id]) el.value = '';
    });
    ['is_sputnik','is_sirius'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el && origValues[id] !== undefined && el.checked === origValues[id]) el.checked = false;
    });
  }
});
function setMode(mode) {
  document.getElementById('block-search').style.display = mode === 'update' ? '' : 'none';
  document.getElementById('block-verify').style.display = mode === 'update' ? '' : 'none';
  document.querySelectorAll('[data-req="new"]').forEach(function(el) {
    el.style.display = mode === 'new' ? '' : 'none';
  });
  document.querySelectorAll('[data-req="update-note"]').forEach(function(el) {
    el.style.display = mode === 'update' ? '' : 'none';
  });
  ['family_name','given_name','patronymic','birthdate','sex','city','region','phone','mail'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (mode === 'new' || id === 'mail') el.setAttribute('required', 'required');
    else el.removeAttribute('required');
  });
  if (mode === 'new') {
    document.getElementById('player_id').value = '';
    document.getElementById('selected-label').textContent = '';
  }
}
setMode(document.querySelector('input[name=mode]:checked').value);

var searchTimer = null;
document.getElementById('search-q').addEventListener('input', function() {
  var q = this.value.trim();
  clearTimeout(searchTimer);
  if (q.length < 1 || (q.length < 2 && !/^\d+$/.test(q))) {
    document.getElementById('search-results').style.display = 'none';
    return;
  }
  searchTimer = setTimeout(function() {
    fetch('?ajax=search&q=' + encodeURIComponent(q))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var ul = document.getElementById('search-results');
        ul.innerHTML = '';
        (data.players || []).forEach(function(p) {
          var li = document.createElement('li');
          li.textContent = p.label;
          li.addEventListener('click', function() { fillPlayer(p); });
          ul.appendChild(li);
        });
        ul.style.display = (data.players && data.players.length) ? 'block' : 'none';
      });
  }, 250);
});

function fillPlayer(p) {
  document.getElementById('player_id').value = p.player_id;
  document.getElementById('selected-label').textContent = 'Выбран: ' + p.label;
  document.getElementById('family_name').value = p.family_name || '';
  document.getElementById('given_name').value = p.given_name || '';
  document.getElementById('patronymic').value = p.patronymic || '';
  document.getElementById('birthdate').value = ''; // дата рождения не публичная
  document.getElementById('sex').value = (p.sex === 0 || p.sex === 1) ? String(p.sex) : '';
  document.getElementById('city').value = p.city_name || '';
  // телефон и e-mail из базы не отдаём; пустое поле = «не менять»
  document.getElementById('phone').value = '';
  document.getElementById('mail').value = '';
  document.getElementById('bbo').value = p.bbo || '';
  document.getElementById('gambler').value = p.gambler || '';
  document.getElementById('wbf').value = p.wbf || '';
  document.getElementById('acbl').value = p.acbl || '';
  document.getElementById('is_sputnik').checked = !!p.is_sputnik;
  document.getElementById('is_sirius').checked = !!p.is_sirius;
  document.getElementById('first_tourn').value = p.first_tourn || '';
  document.getElementById('club_id').value = p.club_id || '';
  document.getElementById('region').value = ''; // в карточке нет региона — оставляем пустым для ввода
  ['sex','city','region','bbo','gambler','wbf','acbl','first_tourn','club_id'].forEach(function(id) {
    origValues[id] = document.getElementById(id).value;
  });
  origValues.is_sputnik = document.getElementById('is_sputnik').checked;
  origValues.is_sirius = document.getElementById('is_sirius').checked;
  document.getElementById('search-results').style.display = 'none';
  document.getElementById('search-q').value = p.label;
}
</script>
  <?php endif; ?>
</div>
</body>
</html>

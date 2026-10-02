<?php
declare(strict_types=1);
/**
 * Вкладка «Анкеты»: обработка очереди aux_questionaries.
 * Подключается из index.php (вход, CSRF и сессия уже проверены).
 * Запись в БД — только SQL, сформированный здесь же, одноразовый ключ, подтверждение, транзакция с откатом.
 */
require_once __DIR__ . '/Anketa.php';

/** @return array<int,array<string,mixed>> */
function ab_rows(mysqli $db, string $sql): array
{
    $out = [];
    $r = $db->query($sql);
    while ($r && ($row = $r->fetch_assoc())) {
        $out[] = $row;
    }
    return $out;
}

function ab_esc_fn(mysqli $db): callable
{
    return fn($v) => ($v === null || $v === '') ? 'NULL' : ("'" . $db->real_escape_string((string)$v) . "'");
}

/** Колонки таблицы: имя => ['null'=>bool,'default'=>?string,'extra'=>string] */
function ab_columns(mysqli $db, string $table): array
{
    $out = [];
    foreach (ab_rows($db, 'SHOW COLUMNS FROM ' . $table) as $c) {
        $out[$c['Field']] = ['null' => $c['Null'] === 'YES', 'default' => $c['Default'], 'extra' => (string)$c['Extra']];
    }
    return $out;
}

/** Текущие данные игрока из БД в формате анкеты. */
function ab_player_as_anketa(mysqli $db, int $pid, ?string $birthCol, ?string &$src = null): array
{
    // Актуальные данные игрока собираются из источников:
    //  players — ФИО, пол, дата рождения, город, клуб (БЕЗ phone/mail);
    //  последняя анкета в questionaries — телефон, e-mail (и регион, которого нет в players);
    //  external_ids — ники и ID; students — Спутник/Сириус/первый турнир.
    $a = array_fill_keys(ANKETA_FIELDS, null);
    $sel = 'p.firstname, p.lastname, p.surname, p.sex, p.club_id, c.city_name'
        . ($birthCol ? ', p.' . $birthCol . ' AS bd' : '');
    $r = ab_rows($db, "SELECT {$sel} FROM players p LEFT JOIN cities c ON c.city_id = p.city_id WHERE p.player_id = {$pid} LIMIT 1");
    if ($r) {
        $p = $r[0];
        foreach (['firstname', 'lastname', 'surname', 'sex', 'club_id'] as $f) {
            $a[$f] = $p[$f];
        }
        $a['city'] = $p['city_name'];
        $a['birthdate'] = $p['bd'] ?? null;
    }
    $qSrc = 'анкет игрока нет';
    if ($q = ab_rows($db, "SELECT id, timestamp, phone, mail, region FROM questionaries WHERE player_id = {$pid} ORDER BY id DESC LIMIT 1")) {
        $a['phone'] = $q[0]['phone'];
        $a['mail'] = $q[0]['mail'];
        $a['region'] = $q[0]['region'];
        $qSrc = 'анкета #' . $q[0]['id'] . ' от ' . $q[0]['timestamp'];
    }
    if ($e = ab_rows($db, "SELECT bbo, gambler, wbf, acbl FROM external_ids WHERE player_id = {$pid} LIMIT 1")) {
        $a['bbo'] = $e[0]['bbo'];
        $a['gambler'] = $e[0]['gambler'];
        $a['WBF'] = $e[0]['wbf'];
        $a['acbl'] = $e[0]['acbl'];
    }
    if ($s = ab_rows($db, "SELECT sputnik, sirius, first FROM students WHERE player_id = {$pid} LIMIT 1")) {
        $a['is_sputnik'] = $s[0]['sputnik'];
        $a['is_sirius'] = $s[0]['sirius'];
        $a['first_tourn'] = $s[0]['first'];
    }
    $src = 'Актуальные данные: players + external_ids + students; телефон, e-mail, регион — ' . $qSrc;
    return $a;
}

function ab_fmt(string $f, $v, array $cities, array $clubs): string
{
    if ($v === null || $v === '') {
        return '';
    }
    if ($f === 'sex') {
        return (string)$v === '1' ? 'М' : ((string)$v === '0' ? 'Ж' : (string)$v);
    }
    if ($f === 'club_id') {
        return $clubs[(int)$v] ?? ('#' . (int)$v);
    }
    if ($f === 'is_sputnik' || $f === 'is_sirius') {
        return (string)$v === '1' ? 'да' : '';
    }
    return (string)$v;
}

$mailTestMsg = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'anketa' && ($_POST['act'] ?? '') === 'mailtest') {
    $to = trim((string)($_POST['test_to'] ?? ''));
    if (!mail_address_ok($to)) {
        $mailTestMsg = ['bad', 'Укажите корректный адрес для теста'];
    } elseif (app_send_mail($to, 'Проверка отправки FSBR Toolkit', "Это тестовое письмо. Если вы его видите — отправка настроена.\n")) {
        $mailTestMsg = ['ok', 'Письмо отправлено на ' . $to . ' (проверьте и папку «Спам»)'];
    } else {
        $e = data_read_json(app_data_dir() . '/mail_error.json.php');
        $mailTestMsg = ['bad', 'Не отправлено: ' . ($e['msg'] ?? 'причина неизвестна')];
    }
}
$mailErr = data_read_json(app_data_dir() . '/mail_error.json.php');
if (!empty($mailErr['msg'])) {
    echo '<div class="card" style="color:var(--warn)">Последний сбой отправки писем (' . h($mailErr['t'] ?? '') . '): ' . h($mailErr['msg']) . '</div>';
}
$aux_error = null;
$aux_info = null;
$aux_sqlPreview = null;
$aux_warn = [];
$aid = (int)($_GET['aid'] ?? $_POST['aid'] ?? 0);

try {
    $db = db_rw();
} catch (Throwable $e) {
    echo '<div class="card"><p style="color:var(--err)">Нет подключения к БД: ' . h($e->getMessage()) . '</p></div>';
    return;
}
$auxCols = ab_columns($db, 'aux_questionaries');
if (!isset($auxCols['status'])) {
    echo '<div class="card"><p style="color:var(--warn)">В таблице aux_questionaries нет колонок обработки. '
        . 'Выполните раздел 4 из db_setup.sql (ALTER TABLE + права), затем обновите страницу.</p></div>';
    return;
}
$plCols = ab_columns($db, 'players');
$extColsDb = ab_columns($db, 'external_ids');
$birthCol = null;
foreach (['birthdate', 'birth_date', 'birthday', 'dob', 'bdate'] as $bc) {
    if (isset($plCols[$bc])) {
        $birthCol = $bc;
        break;
    }
}
$esc = ab_esc_fn($db);
$cityRows = ab_rows($db, 'SELECT city_id, city_name FROM cities ORDER BY city_name');
$clubRows = ab_rows($db, 'SELECT club_id, name, shortname FROM clubs ORDER BY name');
$clubNames = [];
foreach ($clubRows as $c) {
    $clubNames[(int)$c['club_id']] = $c['shortname'] ?: $c['name'];
}
$clubKeyFn = function ($n) {
    $n = str_replace('ё', 'е', mb_strtolower((string)$n));
    return (preg_match('/^[^\p{L}]*[а-я]/u', $n) ? '0' : '1') . $n;   // по первой букве: кириллица первой, латиница — в конец
};
uasort($clubNames, fn($a, $b) => strcmp($clubKeyFn($a), $clubKeyFn($b)));
$cityByName = [];
foreach ($cityRows as $c) {
    $cityByName[mb_strtolower(trim((string)$c['city_name']))] = (int)$c['city_id'];
}

// ---------- действия ----------
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'anketa';
$act = $post ? (string)($_POST['act'] ?? '') : '';

$A = $aid > 0 ? (ab_rows($db, "SELECT * FROM aux_questionaries WHERE id = {$aid} LIMIT 1")[0] ?? null) : null;

// собрать «новую анкету» из полей формы n_*
$readNew = function () use ($A): array {
    $n = [];
    foreach (ANKETA_FIELDS as $f) {
        $n[$f] = trim((string)($_POST['n_' . $f] ?? ''));
        if ($n[$f] === '') {
            $n[$f] = null;
        }
    }
    foreach (['is_sputnik', 'is_sirius'] as $f) {
        $n[$f] = !empty($_POST['n_' . $f]) ? 1 : 0;
    }
    return $n;
};

if ($post && $A && $act === 'reject') {
    if ($A['status'] !== null) {
        $aux_error = 'Анкета уже обработана';
    } else {
        $note = mb_substr(trim((string)($_POST['op_note'] ?? '')), 0, 250);
        $db->query("UPDATE aux_questionaries SET status = 'rejected', processed_at = NOW(), processed_by = "
            . $esc((string)(app_config()['auth_user'] ?? '')) . ', op_note = ' . $esc($note) . " WHERE id = {$aid} AND status IS NULL");
        $aux_info = 'Анкета #' . $aid . ' отклонена';
        $A = ab_rows($db, "SELECT * FROM aux_questionaries WHERE id = {$aid} LIMIT 1")[0] ?? null;
    }
}

// база для сравнения («предыдущая анкета» или текущие данные)
$prevSrc = '';
$prev = array_fill_keys(ANKETA_FIELDS, null);
$targetPid = null;       // ID игрока, которого обновляем (null — новый)
$candidates = [];
if ($A) {
    $isEdit = $A['type'] === 'e' && (int)$A['player_id'] > 0;
    if ($isEdit) {
        $targetPid = (int)$A['player_id'];
    } elseif ($post && isset($_POST['cand']) && ctype_digit((string)$_POST['cand']) && (int)$_POST['cand'] > 0) {
        $targetPid = (int)$_POST['cand']; // оператор выбрал существующего игрока
    } elseif (!$post && ctype_digit((string)($_GET['cand'] ?? '')) && (int)$_GET['cand'] > 0) {
        $targetPid = (int)$_GET['cand'];
    }
    if ($targetPid !== null) {
        $prev = ab_player_as_anketa($db, $targetPid, $birthCol, $prevSrc);
    } elseif ($A['type'] === 'a') {
        $fam = $db->real_escape_string((string)$A['firstname']);
        $mailCond = '';
        foreach (anketa_split_mails((string)$A['mail']) as $m1) {
            $mailCond .= " OR LOWER(mail) LIKE '%" . $db->real_escape_string(addcslashes($m1, '%_')) . "%'";
        }
        $cond = "firstname = '{$fam}'" . $mailCond;
        if ($birthCol && $A['birthdate']) {
            $cond .= " OR {$birthCol} = '" . $db->real_escape_string((string)$A['birthdate']) . "'";
        }
        $pls = ab_rows($db, 'SELECT player_id, firstname, lastname, surname, mail'
            . ($birthCol ? ", {$birthCol} AS birthdate" : '') . " FROM players WHERE {$cond} LIMIT 60");
        $cond2 = "firstname = '{$fam}'" . $mailCond
            . ($A['birthdate'] ? " OR birthdate = '" . $db->real_escape_string((string)$A['birthdate']) . "'" : '');
        $pend = ab_rows($db, "SELECT id, result_player_id, firstname, lastname, surname, birthdate, mail, status
            FROM aux_questionaries WHERE id <> {$aid} AND (status IS NULL OR status = 'accepted') AND type = 'a' AND ({$cond2}) LIMIT 60");
        $candidates = anketa_candidates($A, $pls, $pend);
    }
}

$newForm = null;   // итоговая анкета (после merge или из формы)
if ($A) {
    if ($post && $act === 'build') {
        $newForm = $readNew();
    } else {
        $newForm = anketa_merge($prev, $A);
    }
}

// --- формирование SQL ---
if ($post && $A && $act === 'build') {
    $choice = (string)($_POST['cand'] ?? '');
    $wantNew = !empty($_POST['new_id']);
    if ($A['status'] !== null) {
        $aux_error = 'Анкета уже обработана';
    } elseif ($A['type'] === 'a' && $targetPid === null && !$wantNew) {
        $aux_error = 'Выберите существующего игрока из списка или отметьте «Выдать новый ID»';
    } elseif ($A['type'] === 'a' && $targetPid !== null && $wantNew) {
        $aux_error = 'Выберите что-то одно: существующего игрока или новый ID';
    } else {
        $changed = $targetPid === null ? ANKETA_FIELDS : anketa_diff($prev, $newForm);
        $cityId = ($_POST['n_city_id'] ?? '') !== '' ? (int)$_POST['n_city_id'] : null;
        if ($targetPid === null) {
            foreach (['firstname' => 'фамилия', 'lastname' => 'имя', 'sex' => 'пол'] as $f => $lbl) {
                if ($newForm[$f] === null) {
                    $aux_error = 'Не заполнено: ' . $lbl;
                }
            }
            if ($cityId === null) {
                $aux_error = 'Выберите город из справочника';
            }
            if (!$birthCol) {
                $aux_warn[] = 'В таблице players не найдена колонка даты рождения — дата не будет записана.';
            }
            foreach ($plCols as $cn => $ci) {
                if (!$ci['null'] && $ci['default'] === null && stripos($ci['extra'], 'auto_increment') === false
                    && !in_array($cn, ['player_id', 'firstname', 'lastname', 'surname', 'sex', 'club_id', 'city_id', 'state', 'razr', 'lifetime', 'lastupdated', (string)$birthCol], true)) {
                    $aux_warn[] = "Колонка players.{$cn} обязательна и не имеет значения по умолчанию — вставка нового игрока может не пройти (откат безопасен).";
                }
            }
        } elseif (in_array('city', $changed, true) && $cityId === null) {
            $aux_error = 'Город изменён — выберите его из справочника';
        }
        foreach (['firstname' => 'Фамилия', 'lastname' => 'Имя', 'surname' => 'Отчество'] as $nf => $nl) {
            if ($newForm[$nf] !== null && ($targetPid === null || in_array($nf, $changed, true)) && anketa_not_cp1251((string)$newForm[$nf])) {
                $aux_error = "{$nl}: есть символы, которых нет в кодировке cp1251 (таблица players) — исправьте в колонке «Новая»";
            }
        }
        foreach (['WBF', 'acbl'] as $nf) {
            if ($newForm[$nf] !== null && !ctype_digit((string)$newForm[$nf])) {
                $aux_error = "Поле {$nf} должно быть числом (в базе числовая колонка external_ids)";
            }
        }
        if (!$aux_error) {
            $sql = anketa_build_sql($newForm, $changed, $esc, $targetPid, [
                'aux_id' => $aid, 'type' => $A['type'], 'user' => (string)(app_config()['auth_user'] ?? ''),
                'city_id' => $cityId, 'birth_col' => $birthCol,
                'submitted_at' => (string)$A['timestamp'], // время заполнения анкеты
                'players_lu' => isset($plCols['lastupdated']),
                'ext_lu' => isset($extColsDb['lastupdated']),
            ]);
            $_SESSION['aux_sql'] = ['aid' => $aid, 'sql' => $sql];
            $_SESSION['aux_nonce'] = bin2hex(random_bytes(16));
            $aux_sqlPreview = $sql;
        }
    }
}

// --- выполнение ---
if ($post && $A && $act === 'run') {
    $have = (string)($_SESSION['aux_nonce'] ?? '');
    $okNonce = $have !== '' && hash_equals($have, (string)($_POST['aux_nonce'] ?? ''));
    unset($_SESSION['aux_nonce']);
    $st = $_SESSION['aux_sql'] ?? null;
    $posted = (string)($_POST['sql_script'] ?? '');
    if (empty($_POST['confirm_run'])) {
        $aux_error = 'Отметьте подтверждение';
    } elseif (!$okNonce) {
        $aux_error = 'Запрос устарел или уже выполнялся. Сформируйте SQL заново.';
    } elseif (!is_array($st) || (int)$st['aid'] !== $aid
        || hash('sha256', sql_normalize($posted)) !== hash('sha256', sql_normalize((string)$st['sql']))) {
        $aux_error = 'SQL не совпадает с сформированным приложением. Сформируйте заново.';
    } elseif ($A['status'] !== null) {
        $aux_error = 'Анкета уже обработана';
    } else {
        $run = null;
        try {
            $run = db_rw();
            if (!$run->multi_query((string)$st['sql'])) {
                throw new RuntimeException($run->error);
            }
            do {
                if ($res = $run->store_result()) {
                    $res->free();
                }
                if ($run->errno) {
                    throw new RuntimeException($run->error);
                }
            } while ($run->more_results() && $run->next_result());
            if ($run->errno) {
                throw new RuntimeException($run->error);
            }
            $run->close();
            unset($_SESSION['aux_sql']);
            $aux_info = 'Анкета #' . $aid . ' принята';
        } catch (Throwable $e) {
            if ($run instanceof mysqli) {
                try { @$run->rollback(); @$run->close(); } catch (Throwable $e2) { /* ignore */ }
            }
            $aux_error = 'Ошибка выполнения (изменения откатаны): ' . $e->getMessage();
        }
        $A = ab_rows($db, "SELECT * FROM aux_questionaries WHERE id = {$aid} LIMIT 1")[0] ?? null;
    }
}

// ---------- вывод ----------
?>
<style>
.ab-card{background:var(--card);border-radius:10px;padding:16px;margin-bottom:14px}
.ab-table{width:100%;border-collapse:collapse;font-size:.9rem}
.ab-table th,.ab-table td{padding:6px 8px;border-bottom:1px solid #2a3548;text-align:left;vertical-align:top}
.ab-table input[type=text],.ab-table input[type=date],.ab-table select{width:100%;padding:5px 7px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:var(--text)}
.ab-chg{background:rgba(245,158,11,.15)}
.ab-ok{color:var(--ok)}.ab-bad{color:var(--err)}.ab-warn{color:var(--warn)}
.ab-btn{background:var(--accent);color:#0f172a;border:0;padding:8px 14px;border-radius:6px;font-weight:600;cursor:pointer}
.ab-btn.sec{background:#334155;color:var(--text)}
pre.ab-sql{background:#0b1220;padding:12px;border-radius:8px;overflow:auto;font-size:.85rem}
</style>
<?php if ($aux_info): ?><div class="ab-card ab-ok"><?= h($aux_info) ?></div><?php endif; ?>
<?php if ($aux_error): ?><div class="ab-card ab-bad"><?= h($aux_error) ?></div><?php endif; ?>

<?php if (!$A): ?>
<?php
    $mc = app_config();
    $mailSummary = 'transport=' . ($mc['mail_transport'] ?? 'mail') . ', from=' . ($mc['mail_from'] ?? '')
        . (($mc['mail_transport'] ?? 'mail') === 'smtp'
            ? ', host=' . ($mc['smtp_host'] ?? '') . ', port=' . ($mc['smtp_port'] ?? '') . ', secure=' . ($mc['smtp_secure'] ?? '') . ', user=' . ($mc['smtp_user'] ?? '')
            : '');
?>
<div class="ab-card">
  <b>Проверка отправки писем</b> <span style="color:var(--muted)">(<?= h($mailSummary) ?>)</span>
  <?php if ($mailTestMsg): ?><p class="ab-<?= $mailTestMsg[0] ?>"><?= h($mailTestMsg[1]) ?></p><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="anketa"><input type="hidden" name="act" value="mailtest">
    <input type="text" name="test_to" placeholder="ваш e-mail для теста" style="padding:6px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:var(--text)">
    <button class="ab-btn sec" type="submit">Отправить тест</button>
  </form>
</div>
<?php
    $flt = (string)($_GET['st'] ?? 'pending');
    $where = $flt === 'accepted' ? "status = 'accepted'" : ($flt === 'rejected' ? "status = 'rejected'" : 'status IS NULL');
    $list = ab_rows($db, "SELECT id, player_id, type, timestamp, firstname, lastname, surname, city, mail, status, email_verified, verify_status, result_player_id
        FROM aux_questionaries WHERE {$where} ORDER BY id DESC LIMIT 200");
?>
<div class="ab-card">
  <p>
    <?php foreach (['pending' => 'Ожидают', 'accepted' => 'Приняты', 'rejected' => 'Отклонены'] as $k => $lbl): ?>
      <a class="tab<?= $flt === $k ? ' active' : '' ?>" href="?tab=anketa&st=<?= h($k) ?>"><?= h($lbl) ?></a>
    <?php endforeach; ?>
  </p>
  <table class="ab-table">
    <tr><th>#</th><th>Тип</th><th>Дата</th><th>ФИО</th><th>Город</th><th>ID</th><th>E-mail</th><th>Личность</th><th></th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= $r['type'] === 'e' ? 'обновление' : 'новый' ?></td>
      <td><?= h($r['timestamp']) ?></td>
      <td><?= h(trim($r['firstname'] . ' ' . $r['lastname'] . ' ' . $r['surname'])) ?></td>
      <td><?= h($r['city']) ?></td>
      <td><?= h($r['result_player_id'] ?: $r['player_id']) ?></td>
      <td><?= $r['email_verified'] ? '<span class="ab-ok">подтверждён</span>' : '<span class="ab-warn">нет</span>' ?></td>
      <td><?php
          $vs = $r['verify_status'];
          echo $vs === 'ok' ? '<span class="ab-ok">совпало</span>' : ($vs === 'weak' ? '<span class="ab-warn">частично</span>'
              : ($vs === 'failed' ? '<span class="ab-bad">не совпало</span>' : ($vs === 'none' ? 'нет данных' : '—')));
      ?></td>
      <td><a href="?tab=anketa&aid=<?= (int)$r['id'] ?>">Открыть</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="9">Анкет нет</td></tr><?php endif; ?>
  </table>
</div>
<?php else: ?>
<?php
    $done = $A['status'] !== null;
    $selCity = $newForm['city'] ? ($cityByName[mb_strtolower(trim((string)$newForm['city']))] ?? null) : null;
    if (isset($_POST['n_city_id']) && $_POST['n_city_id'] !== '') {
        $selCity = (int)$_POST['n_city_id'];
    }
    $changedNow = $targetPid === null ? [] : anketa_diff($prev, $newForm);
?>
<p><a href="?tab=anketa">← к списку</a></p>
<div class="ab-card">
  <b>Анкета #<?= (int)$A['id'] ?></b> — <?= $A['type'] === 'e' ? 'обновление данных (ID ' . (int)$A['player_id'] . ')' : 'новый игрок' ?>,
  <?= h($A['timestamp']) ?>.
  E-mail: <?= $A['email_verified'] ? '<span class="ab-ok">подтверждён кодом</span>' : '<span class="ab-warn">не подтверждён</span>' ?>.
  <?php if ($A['type'] === 'e'): ?>
    Сверка личности: <?php $vs = $A['verify_status'];
      echo $vs === 'ok' ? '<span class="ab-ok">совпало</span>' : ($vs === 'weak' ? '<span class="ab-warn">частично</span>'
          : ($vs === 'failed' ? '<span class="ab-bad">НЕ совпало</span>' : 'нет данных для сверки')); ?>.
  <?php endif; ?>
  <?php if ($done): ?><br>Статус: <b><?= h($A['status']) ?></b>, <?= h($A['processed_at']) ?>, <?= h($A['processed_by']) ?>
    <?= $A['result_player_id'] ? ', ID ' . (int)$A['result_player_id'] : '' ?> <?= h($A['op_note'] ?? '') ?><?php endif; ?>
</div>

<?php if ($A['type'] === 'e'): ?>
<?php
    $vd = isset($A['verify_data']) && $A['verify_data'] !== null ? json_decode((string)$A['verify_data'], true) : null;
    $stored = is_array($vd) ? ($vd['detail'] ?? []) : [];
    $entered = is_array($vd) ? ($vd['entered'] ?? []) : [];
    // что есть в базе сейчас: дата рождения — players; телефон, e-mail — последняя анкета (questionaries)
    $onFile = ['birthdate' => (string)($prev['birthdate'] ?? ''), 'phone4' => (string)($prev['phone'] ?? ''), 'mail' => (string)($prev['mail'] ?? '')];
    $resLbl = ['match' => ['совпало', 'ab-ok'], 'mismatch' => ['НЕ совпало', 'ab-bad'], 'nodata' => ['в базе нет данных', 'ab-warn'], 'empty' => ['не введено', 'ab-warn']];
    $byField = [];
    foreach ($stored as $d) {
        $byField[$d['field']] = $d;
    }
?>
<div class="ab-card">
  <b>Подтверждение личности (данные, введённые при обновлении)</b>
  <table class="ab-table" style="margin-top:8px">
    <tr><th>Поле</th><th>Ввёл человек</th><th>В базе сейчас</th><th>Результат</th></tr>
    <?php foreach (['birthdate' => 'Дата рождения', 'phone4' => 'Телефон (последние 4 цифры)', 'mail' => 'E-mail из прошлой анкеты'] as $fk => $fl): ?>
    <?php $d = $byField[$fk] ?? null; ?>
    <tr>
      <td><?= h($fl) ?></td>
      <td><?= $d ? h((string)$d['entered']) : '<span style="color:var(--muted)">не сохранено</span>' ?></td>
      <td><?= h($onFile[$fk]) ?></td>
      <td><?php if ($d): $rl = $resLbl[$d['result']] ?? [$d['result'], '']; ?><span class="<?= $rl[1] ?>"><?= h($rl[0]) ?></span><?php else: ?>—<?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if (!$stored): ?><p class="note" style="color:var(--muted)">Подробности этой анкеты не сохранены (нужна колонка verify_data — раздел 7 в db_setup.sql; для новых анкет она заполняется).</p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$done && $A['type'] === 'a'): ?>
<div class="ab-card">
  <b>Кто это? Возможные совпадения</b>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="anketa"><input type="hidden" name="aid" value="<?= $aid ?>">
  <input type="hidden" name="act" value="recalc">
  <?php if ($candidates): ?>
  <table class="ab-table">
    <?php foreach ($candidates as $c): ?>
    <tr>
      <td>
        <?php if ($c['id'] !== null): ?>
          <label><input type="radio" name="cand" value="<?= (int)$c['id'] ?>" <?= $targetPid === $c['id'] ? 'checked' : '' ?>>
          ID <?= (int)$c['id'] ?></label>
        <?php else: ?>без ID<?php endif; ?>
      </td>
      <td><?= h($c['label']) ?></td>
      <td><?= $c['kind'] === 'player' ? 'в базе' : 'анкета #' . (int)$c['aux_id'] . ($c['status'] === null ? ' — ждёт обработки' : ' — принята, ждёт добавления') ?>
        <?php if ($c['kind'] === 'pending' && $c['status'] === null): ?>
          <a href="?tab=anketa&aid=<?= (int)$c['aux_id'] ?>">открыть</a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><p>Похожих игроков не найдено.</p><?php endif; ?>
  <label><input type="checkbox" name="new_id" value="1" <?= !empty($_POST['new_id']) ? 'checked' : '' ?>> Это новый игрок — выдать новый ID</label>
  <button class="ab-btn sec" type="submit">Показать данные выбранного</button>
  </form>
</div>
<?php endif; ?>

<form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="anketa"><input type="hidden" name="aid" value="<?= $aid ?>">
<input type="hidden" name="cand" value="<?= $targetPid !== null && $A['type'] === 'a' ? (int)$targetPid : '' ?>">
<?php if ($A['type'] === 'a' && !empty($_POST['new_id'])): ?><input type="hidden" name="new_id" value="1"><?php endif; ?>
<div class="ab-card">
<table class="ab-table">
  <tr><th>Поле</th><th>Заполненная анкета</th><th>Актуальные данные</th><th>Новая (в базу)</th></tr>
  <?php foreach (ANKETA_FIELDS as $f): ?>
  <?php $chg = $targetPid !== null && in_array($f, $changedNow, true); ?>
  <tr>
    <td><?= h(ANKETA_LABELS[$f]) ?></td>
    <td><?= h(ab_fmt($f, $A[$f], [], $clubNames)) ?></td>
    <td><?= h(ab_fmt($f, $prev[$f], [], $clubNames)) ?></td>
    <td class="<?= $chg ? 'ab-chg' : '' ?>">
      <?php if ($f === 'sex'): ?>
        <select name="n_sex" <?= $done ? 'disabled' : '' ?>><option value="">—</option>
          <option value="1" <?= (string)$newForm['sex'] === '1' ? 'selected' : '' ?>>М</option>
          <option value="0" <?= (string)$newForm['sex'] === '0' ? 'selected' : '' ?>>Ж</option></select>
      <?php elseif ($f === 'city'): ?>
        <input type="text" name="n_city" value="<?= h((string)$newForm['city']) ?>" <?= $done ? 'disabled' : '' ?>>
        <select name="n_city_id" <?= $done ? 'disabled' : '' ?>><option value="">— город из справочника —</option>
          <?php foreach ($cityRows as $c): ?>
          <option value="<?= (int)$c['city_id'] ?>" <?= $selCity === (int)$c['city_id'] ? 'selected' : '' ?>><?= h($c['city_name']) ?></option>
          <?php endforeach; ?></select>
      <?php elseif ($f === 'club_id'): ?>
        <select name="n_club_id" <?= $done ? 'disabled' : '' ?>><option value="">—</option>
          <?php foreach ($clubNames as $cid => $cn): ?>
          <option value="<?= $cid ?>" <?= (string)$newForm['club_id'] === (string)$cid ? 'selected' : '' ?>><?= h($cn) ?></option>
          <?php endforeach; ?></select>
      <?php elseif ($f === 'is_sputnik' || $f === 'is_sirius'): ?>
        <input type="checkbox" name="n_<?= $f ?>" value="1" <?= (string)$newForm[$f] === '1' ? 'checked' : '' ?> <?= $done ? 'disabled' : '' ?>>
      <?php else: ?>
        <input type="<?= in_array($f, ['birthdate', 'first_tourn'], true) ? 'date' : 'text' ?>" name="n_<?= h($f) ?>"
          value="<?= h(substr((string)($newForm[$f] ?? ''), 0, in_array($f, ['birthdate', 'first_tourn'], true) ? 10 : 200)) ?>" <?= $done ? 'disabled' : '' ?>>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!$done): ?>
<p style="margin-top:12px">
  <button class="ab-btn" type="submit" name="act" value="build">Сформировать SQL</button>
</p>
<?php endif; ?>
</div>
</form>

<?php if (!$done): ?>
<div class="ab-card">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="anketa"><input type="hidden" name="aid" value="<?= $aid ?>">
    <input type="hidden" name="act" value="reject">
    <input type="text" name="op_note" placeholder="причина отклонения" style="width:60%;padding:6px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:var(--text)">
    <button class="ab-btn sec" type="submit" onclick="return confirm('Отклонить анкету?')">Отклонить</button>
  </form>
</div>
<?php endif; ?>

<?php if ($aux_sqlPreview): ?>
<div class="ab-card">
  <b>SQL к выполнению</b>
  <?php foreach ($aux_warn as $w): ?><p class="ab-warn">⚠ <?= h($w) ?></p><?php endforeach; ?>
  <pre class="ab-sql"><?= h($aux_sqlPreview) ?></pre>
  <form method="post" onsubmit="return confirm('Выполнить SQL в базе?');"><?= csrf_field() ?>
    <input type="hidden" name="tab" value="anketa"><input type="hidden" name="aid" value="<?= $aid ?>">
    <input type="hidden" name="act" value="run">
    <input type="hidden" name="aux_nonce" value="<?= h((string)($_SESSION['aux_nonce'] ?? '')) ?>">
    <input type="hidden" name="sql_script" value="<?= h($aux_sqlPreview) ?>">
    <label><input type="checkbox" name="confirm_run" value="1" required> Проверил(а) SQL, выполнить</label>
    <button class="ab-btn" type="submit">Выполнить</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

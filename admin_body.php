<?php
declare(strict_types=1);
/**
 * Вкладка «Справочники»: редактирование городов, клубов, игроков и шапок турниров.
 * Подключается из index.php (вход, CSRF и сессия уже проверены).
 * Изменения: форма → «Сформировать SQL» (показ различий) → выполнение с подтверждением и одноразовым ключом.
 * UPDATE содержит проверку старых значений: если строку успели изменить, правка не применится.
 * Удаление строк не предусмотрено (внешние ключи, история турниров).
 */
require_once __DIR__ . '/Admin.php';

if (!function_exists('adm_rows')) {
    /** @return array<int,array<string,mixed>> */
    function adm_rows(mysqli $db, string $sql): array
    {
        $out = [];
        $r = $db->query($sql);
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = $row;
        }
        return $out;
    }
}

if ((string)($_GET['t'] ?? '') === 'log') {
    $log = adm_log_all(300);
    $tt = array_column(ADM_TABLES, 'title');
    $titles = array_combine(array_keys(ADM_TABLES), $tt);
    ?>
<style>
.ad-card{background:var(--card);border-radius:10px;padding:16px;margin-bottom:14px}
.ad-table{width:100%;border-collapse:collapse;font-size:.9rem}
.ad-table th,.ad-table td{padding:6px 8px;border-bottom:1px solid #2a3548;text-align:left;vertical-align:top}
.ad-ok{color:var(--ok)}.ad-bad{color:var(--err)}.ad-mut{color:var(--muted)}
</style>
<div class="ad-card">
  <?php foreach (ADM_TABLES as $k => $d): ?><a class="tab" href="?tab=admin&t=<?= h($k) ?>"><?= h($d['title']) ?></a><?php endforeach; ?>
  <a class="tab active" href="?tab=admin&t=log">Журнал правок</a>
</div>
<div class="ad-card">
  <b>Журнал правок справочников</b>
  <p class="ad-mut">Отдельно от «Истории» загрузок турниров. Хранится в <code>data/admin_log.json.php</code>, последние 1000 записей.</p>
  <?php if (!$log): ?><p class="ad-mut">Пока правок не было.</p><?php else: ?>
  <table class="ad-table">
    <tr><th>Время</th><th>Кто</th><th>Таблица</th><th>ID</th><th>Действие</th><th>Что изменено (было → стало)</th><th>Итог</th></tr>
    <?php foreach ($log as $r): ?>
    <tr>
      <td><?= h(date('Y-m-d H:i', strtotime((string)($r['ts'] ?? '')) ?: time())) ?></td>
      <td><?= h((string)($r['user'] ?? '')) ?></td>
      <td><?= h($titles[$r['table'] ?? ''] ?? (string)($r['table'] ?? '')) ?></td>
      <td><?= (int)($r['id'] ?? 0) ?></td>
      <td><?= ($r['action'] ?? '') === 'insert' ? 'добавление' : 'правка' ?></td>
      <td><?php foreach (($r['diff'] ?? []) as $d): ?><div><?= h((string)$d[0]) ?>: <span class="ad-mut"><?= h((string)$d[1]) ?></span> → <?= h((string)$d[2]) ?></div><?php endforeach; ?>
        <details><summary class="ad-mut">SQL</summary><code><?= h((string)($r['sql'] ?? '')) ?></code></details></td>
      <td><?= !empty($r['ok']) ? '<span class="ad-ok">OK</span>' : '<span class="ad-bad">ошибка</span> ' . h((string)($r['error'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php
    return;
}

try {
    $db = db_rw();
} catch (Throwable $e) {
    echo '<div class="card"><p style="color:var(--err)">Нет подключения к БД: ' . h($e->getMessage()) . '</p></div>';
    return;
}

$t = (string)($_GET['t'] ?? $_POST['t'] ?? 'cities');
if (!isset(ADM_TABLES[$t])) {
    $t = 'cities';
}
$def = ADM_TABLES[$t];
$pk = $def['pk'];
$cols = adm_parse_columns(adm_rows($db, 'SHOW COLUMNS FROM `' . $t . '`'));
if (!isset($cols[$pk])) {
    echo '<div class="card"><p style="color:var(--err)">Не удалось прочитать структуру таблицы ' . h($t) . ' (нет права SELECT или таблицы). Выполните раздел 8 из db_setup.sql.</p></div>';
    return;
}
$esc = fn($v) => "'" . $db->real_escape_string((string)$v) . "'";

// справочники для подсказок и списков выбора
$cityMap = [];
foreach (adm_rows($db, 'SELECT city_id, city_name FROM cities') as $r) {
    $cityMap[(int)$r['city_id']] = (string)$r['city_name'];
}
asort($cityMap);
$clubMap = [];
foreach (adm_rows($db, 'SELECT club_id, name, shortname FROM clubs') as $r) {
    $clubMap[(int)$r['club_id']] = (string)($r['shortname'] ?: $r['name']);
}
uasort($clubMap, fn($a, $b) => strcmp(adm_name_key($a), adm_name_key($b)));

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'admin';
$act = $post ? (string)($_POST['act'] ?? '') : '';
$isNew = !empty($_GET['new']) || ($post && !empty($_POST['is_new']));
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$row = null;
if (!$isNew && $id > 0) {
    $row = adm_rows($db, "SELECT * FROM `{$t}` WHERE `{$pk}` = {$id} LIMIT 1")[0] ?? null;
}
$info = null;
$err = null;
$sqlPreview = null;
$diffRows = [];

// поля, доступные для редактирования
$editable = [];
foreach ($cols as $c => $m) {
    if ($c === $pk || in_array($c, $def['auto'], true) || !$m['editable']) {
        continue;
    }
    $editable[] = $c;
}

// ---------- формирование SQL ----------
if ($post && $act === 'build' && ($row || ($isNew && $def['insert']))) {
    $new = [];
    $errs = [];
    foreach ($editable as $c) {
        [$ok, $v, $e] = adm_value((string)($_POST['f_' . $c] ?? ''), $cols[$c]);
        if (!$ok) {
            $errs[] = (ADM_LABELS[$c] ?? $c) . ': ' . $e;
            continue;
        }
        $new[$c] = $v;
        if ($v !== null && $def['cp1251'] && $cols[$c]['kind'] === 'text' && adm_not_cp1251($v)) {
            $errs[] = (ADM_LABELS[$c] ?? $c) . ': есть символы, которых нет в кодировке cp1251 (таблица ' . $t . ')';
        }
        // внешние ключи на города и клубы
        if ($v !== null && $c === 'city_id' && !isset($cityMap[(int)$v])) {
            $errs[] = 'Город с ID ' . (int)$v . ' не найден';
        }
        if ($v !== null && $c === 'club_id' && !isset($clubMap[(int)$v])) {
            $errs[] = 'Клуб с ID ' . (int)$v . ' не найден';
        }
    }
    if ($isNew && $def['search'] && ($new[$def['search'][0]] ?? '') === '') {
        $errs[] = (ADM_LABELS[$def['search'][0]] ?? $def['search'][0]) . ': обязательное поле';
    }
    if ($errs) {
        $err = implode('; ', $errs);
    } elseif ($isNew) {
        $values = [];
        if (!$cols[$pk]['auto']) {
            $max = adm_rows($db, "SELECT IFNULL(MAX(`{$pk}`),0)+1 AS n FROM `{$t}`")[0]['n'] ?? 1;
            $values[$pk] = (string)(int)$max;
        }
        foreach ($new as $c => $v) {
            if ($v !== null) {
                $values[$c] = $v;
            }
        }
        $sqlPreview = adm_build_insert($t, $values, $cols, $def['auto'], $esc);
        foreach ($values as $c => $v) {
            $diffRows[] = [ADM_LABELS[$c] ?? $c, '', (string)$v];
        }
    } else {
        $changed = [];
        foreach ($new as $c => $v) {
            $old = adm_db_norm($row[$c] ?? null, $cols[$c]);
            if (!adm_equal($old, $v, $cols[$c])) {
                $changed[$c] = [$old, $v];
                $diffRows[] = [ADM_LABELS[$c] ?? $c, (string)$old, $v === null ? '(пусто)' : (string)$v];
            }
        }
        if (!$changed) {
            $info = 'Изменений нет';
        } else {
            $sqlPreview = adm_build_update($t, $pk, $id, $changed, $cols, $def['auto'], $esc);
        }
    }
    if ($sqlPreview) {
        $_SESSION['adm_sql'] = ['t' => $t, 'id' => $id, 'new' => $isNew, 'sql' => $sqlPreview, 'diff' => $diffRows];
        $_SESSION['adm_nonce'] = bin2hex(random_bytes(16));
    }
}

// ---------- выполнение ----------
if ($post && $act === 'run') {
    $have = (string)($_SESSION['adm_nonce'] ?? '');
    $okNonce = $have !== '' && hash_equals($have, (string)($_POST['adm_nonce'] ?? ''));
    unset($_SESSION['adm_nonce']);
    $st = $_SESSION['adm_sql'] ?? null;
    $posted = (string)($_POST['sql_script'] ?? '');
    if (empty($_POST['confirm_run'])) {
        $err = 'Отметьте подтверждение';
    } elseif (!$okNonce) {
        $err = 'Запрос устарел или уже выполнялся. Сформируйте SQL заново.';
    } elseif (!is_array($st) || $st['t'] !== $t || (int)$st['id'] !== $id
        || hash('sha256', sql_normalize($posted)) !== hash('sha256', sql_normalize((string)$st['sql']))) {
        $err = 'SQL не совпадает с сформированным приложением. Сформируйте заново.';
    } else {
        try {
            $run = db_rw();
            if (!$run->query((string)$st['sql'])) {
                throw new RuntimeException($run->error);
            }
            $aff = (int)$run->affected_rows;
            $newId = (int)$run->insert_id;
            $run->close();
            unset($_SESSION['adm_sql']);
            adm_log_add([
                'table' => $t, 'id' => $st['new'] ? $newId : (int)$st['id'], 'action' => $st['new'] ? 'insert' : 'update',
                'user' => (string)(app_config()['auth_user'] ?? ''), 'ok' => $st['new'] || $aff >= 1,
                'diff' => $st['diff'] ?? [], 'sql' => (string)$st['sql'],
            ]);
            if ($st['new']) {
                $info = 'Добавлено' . ($newId > 0 ? ' (ID ' . $newId . ')' : '');
                $id = $newId > 0 ? $newId : (int)($st['id'] ?? 0);
                $isNew = false;
            } elseif ($aff < 1) {
                $err = 'Строка не изменена: за время правки её успели изменить другие (или значения совпали). Откройте её заново.';
            } else {
                $info = 'Сохранено';
            }
            if (!$err && $id > 0) {
                $row = adm_rows($db, "SELECT * FROM `{$t}` WHERE `{$pk}` = {$id} LIMIT 1")[0] ?? null;
            }
        } catch (Throwable $e) {
            $err = 'Ошибка выполнения: ' . $e->getMessage();
            adm_log_add([
                'table' => $t, 'id' => (int)($st['id'] ?? 0), 'action' => !empty($st['new']) ? 'insert' : 'update',
                'user' => (string)(app_config()['auth_user'] ?? ''), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200),
                'diff' => $st['diff'] ?? [], 'sql' => (string)($st['sql'] ?? ''),
            ]);
        }
    }
}

// ---------- вывод ----------
?>
<style>
.ad-card{background:var(--card);border-radius:10px;padding:16px;margin-bottom:14px}
.ad-table{width:100%;border-collapse:collapse;font-size:.9rem}
.ad-table th,.ad-table td{padding:6px 8px;border-bottom:1px solid #2a3548;text-align:left;vertical-align:top}
.ad-in,.ad-table input[type=text],.ad-table input[type=date],.ad-table select,.ad-table textarea{width:100%;padding:5px 7px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:var(--text)}
.ad-ok{color:var(--ok)}.ad-bad{color:var(--err)}.ad-warn{color:var(--warn)}.ad-mut{color:var(--muted)}
.ad-btn{background:var(--accent);color:#0f172a;border:0;padding:8px 14px;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.ad-btn.sec{background:#334155;color:var(--text)}
pre.ad-sql{background:#0b1220;padding:12px;border-radius:8px;overflow:auto;font-size:.85rem}
</style>

<div class="ad-card">
  <?php foreach (ADM_TABLES as $k => $d): ?>
    <a class="tab<?= $k === $t ? ' active' : '' ?>" href="?tab=admin&t=<?= h($k) ?>"><?= h($d['title']) ?></a>
  <?php endforeach; ?>
  <a class="tab" href="?tab=admin&t=log">Журнал правок</a>
</div>
<?php if ($info): ?><div class="ad-card ad-ok"><?= h($info) ?></div><?php endif; ?>
<?php if ($err): ?><div class="ad-card ad-bad"><?= h($err) ?></div><?php endif; ?>

<?php if ($row || $isNew): ?>
<?php
    $building = $post && $act === 'build';
    $valOf = function (string $c) use ($building, $row) {
        if ($building) {
            return (string)($_POST['f_' . $c] ?? '');
        }
        $v = $row[$c] ?? null;
        return $v === null ? '' : (string)(adm_db_norm($v, ['kind' => 'text']) ?? '');
    };
?>
<p><a href="?tab=admin&t=<?= h($t) ?>">← к списку</a></p>
<form method="post" class="ad-card"><?= csrf_field() ?>
  <input type="hidden" name="tab" value="admin"><input type="hidden" name="t" value="<?= h($t) ?>">
  <input type="hidden" name="id" value="<?= $isNew ? 0 : $id ?>"><?php if ($isNew): ?><input type="hidden" name="is_new" value="1"><?php endif; ?>
  <b><?= h($def['title']) ?>: <?= $isNew ? 'новая запись' : h($pk) . ' = ' . $id ?></b>
  <table class="ad-table" style="margin-top:8px">
    <?php foreach ($cols as $c => $m): ?>
    <tr>
      <td style="width:30%"><?= h(ADM_LABELS[$c] ?? $c) ?> <span class="ad-mut">(<?= h($c) ?>)</span></td>
      <td>
      <?php if ($c === $pk || in_array($c, $def['auto'], true) || !$m['editable']): ?>
        <span class="ad-mut"><?= $isNew ? '(автоматически)' : h((string)($row[$c] ?? '')) ?></span>
      <?php elseif ($c === 'city_id'): ?>
        <select name="f_city_id" class="ad-in"><option value="">—</option>
          <?php foreach ($cityMap as $cid => $cn): ?><option value="<?= $cid ?>" <?= $valOf($c) === (string)$cid ? 'selected' : '' ?>><?= h($cn) ?> (<?= $cid ?>)</option><?php endforeach; ?>
        </select>
      <?php elseif ($c === 'club_id'): ?>
        <select name="f_club_id" class="ad-in"><option value="">—</option>
          <?php foreach ($clubMap as $cid => $cn): ?><option value="<?= $cid ?>" <?= $valOf($c) === (string)$cid ? 'selected' : '' ?>><?= h($cn) ?> (<?= $cid ?>)</option><?php endforeach; ?>
        </select>
      <?php elseif ($c === 'sex'): ?>
        <select name="f_sex" class="ad-in">
          <option value="1" <?= $valOf($c) === '1' ? 'selected' : '' ?>>Мужской</option>
          <option value="0" <?= $valOf($c) === '0' ? 'selected' : '' ?>>Женский</option>
        </select>
      <?php elseif ($m['kind'] === 'date'): ?>
        <input type="date" class="ad-in" name="f_<?= h($c) ?>" value="<?= h($valOf($c)) ?>">
      <?php elseif ($m['kind'] === 'text' && ($m['len'] ?? 0) > 255): ?>
        <textarea class="ad-in" rows="3" name="f_<?= h($c) ?>"><?= h($valOf($c)) ?></textarea>
      <?php else: ?>
        <input type="text" class="ad-in" name="f_<?= h($c) ?>" value="<?= h($valOf($c)) ?>"
          <?= $m['kind'] === 'text' && $m['len'] ? 'maxlength="' . (int)$m['len'] . '"' : '' ?>
          <?= $m['kind'] === 'datetime' ? 'placeholder="ГГГГ-ММ-ДД ЧЧ:ММ:СС"' : '' ?>>
      <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:12px"><button class="ad-btn" type="submit" name="act" value="build">Сформировать SQL</button></p>
</form>

<?php if ($sqlPreview): ?>
<div class="ad-card">
  <b>Что изменится</b>
  <table class="ad-table" style="margin-top:6px">
    <tr><th>Поле</th><th>Было</th><th>Станет</th></tr>
    <?php foreach ($diffRows as [$fl, $o, $n]): ?><tr><td><?= h($fl) ?></td><td><?= h($o) ?></td><td><?= h($n) ?></td></tr><?php endforeach; ?>
  </table>
  <pre class="ad-sql"><?= h($sqlPreview) ?></pre>
  <form method="post" onsubmit="return confirm('Выполнить SQL в базе?');"><?= csrf_field() ?>
    <input type="hidden" name="tab" value="admin"><input type="hidden" name="t" value="<?= h($t) ?>">
    <input type="hidden" name="id" value="<?= $isNew ? 0 : $id ?>"><?php if ($isNew): ?><input type="hidden" name="is_new" value="1"><?php endif; ?>
    <input type="hidden" name="act" value="run">
    <input type="hidden" name="adm_nonce" value="<?= h((string)($_SESSION['adm_nonce'] ?? '')) ?>">
    <input type="hidden" name="sql_script" value="<?= h($sqlPreview) ?>">
    <label><input type="checkbox" name="confirm_run" value="1" required> Проверил(а) изменения, выполнить</label>
    <button class="ad-btn" type="submit">Выполнить</button>
  </form>
</div>
<?php endif; ?>

<?php else: ?>
<?php
    $q = trim((string)($_GET['q'] ?? ''));
    $perPage = 50;
    $page = max(1, (int)($_GET['p'] ?? 1));
    $where = '1=1';
    foreach (preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        $lk = "'%" . $db->real_escape_string(addcslashes($w, '%_\\')) . "%'";
        $parts = [];
        foreach ($def['search'] as $sc) {
            $parts[] = "`{$sc}` LIKE {$lk}";
        }
        if (ctype_digit($w)) {
            $parts[] = "`{$pk}` = " . (int)$w;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }
    $total = (int)(adm_rows($db, "SELECT COUNT(*) AS n FROM `{$t}` WHERE {$where}")[0]['n'] ?? 0);
    $listCols = array_values(array_filter($def['list'], fn($c) => isset($cols[$c])));
    $list = adm_rows($db, 'SELECT `' . implode('`, `', $listCols) . "` FROM `{$t}` WHERE {$where} ORDER BY {$def['order']} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
    $pages = max(1, (int)ceil($total / $perPage));
?>
<div class="ad-card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="tab" value="admin"><input type="hidden" name="t" value="<?= h($t) ?>">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="поиск: <?= h(implode(', ', $def['search'])) ?> или ID" style="padding:6px;border-radius:6px;border:1px solid #334155;background:#0f172a;color:var(--text);min-width:260px">
    <button class="ad-btn sec" type="submit">Найти</button>
    <?php if ($def['insert']): ?><a class="ad-btn" href="?tab=admin&t=<?= h($t) ?>&new=1">+ Добавить</a><?php endif; ?>
    <span class="ad-mut">Найдено: <?= $total ?></span>
  </form>
</div>
<div class="ad-card">
  <table class="ad-table">
    <tr><?php foreach ($listCols as $c): ?><th><?= h(ADM_LABELS[$c] ?? $c) ?></th><?php endforeach; ?><th></th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <?php foreach ($listCols as $c): ?>
      <td><?php
          $v = $r[$c];
          echo h((string)$v);
          if ($c === 'city_id' && $v !== null && isset($cityMap[(int)$v])) { echo ' <span class="ad-mut">' . h($cityMap[(int)$v]) . '</span>'; }
          if ($c === 'club_id' && $v !== null && isset($clubMap[(int)$v])) { echo ' <span class="ad-mut">' . h($clubMap[(int)$v]) . '</span>'; }
      ?></td>
      <?php endforeach; ?>
      <td><a href="?tab=admin&t=<?= h($t) ?>&id=<?= (int)$r[$pk] ?>">Изменить</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="<?= count($listCols) + 1 ?>">Ничего не найдено</td></tr><?php endif; ?>
  </table>
  <?php if ($pages > 1): ?>
  <p class="ad-mut" style="margin-top:10px">
    Страница <?= $page ?> из <?= $pages ?>:
    <?php if ($page > 1): ?><a href="?tab=admin&t=<?= h($t) ?>&q=<?= h(urlencode($q)) ?>&p=<?= $page - 1 ?>">← назад</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="?tab=admin&t=<?= h($t) ?>&q=<?= h(urlencode($q)) ?>&p=<?= $page + 1 ?>">вперёд →</a><?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

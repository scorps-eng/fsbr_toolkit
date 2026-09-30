<?php
declare(strict_types=1);
/**
 * Вкладка «Клубные МБ» — устаревшая: тот же разбор через проверку отчётов.
 * Оставлена для совместимости; логика в ClubMb.php.
 */
require_once __DIR__ . '/ClubMb.php';
if (file_exists(__DIR__ . '/ImportHistory.php')) {
    require_once __DIR__ . '/ImportHistory.php';
}

// --- request handling ---
$error = null;
$reports = [];
$cmbExecOk = null;
$cmbExecMsg = null;
$cmbExecCard = null;

// Выполнить SQL клубных МБ
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'clubmb' && !empty($_POST['run_club_sql'])) {
    $sqlRun = (string)($_POST['sql_script'] ?? '');
    if ($sqlRun === '') {
        $error = 'Нет SQL для выполнения';
    } elseif (empty($_POST['confirm_run'])) {
        $error = 'Отметьте подтверждение перед выполнением SQL';
    } else {
        try {
            $mysqli = cmb_db();
            if (!$mysqli) {
                throw new RuntimeException('Нет подключения к БД');
            }
            if (!$mysqli->multi_query($sqlRun)) {
                throw new RuntimeException($mysqli->error);
            }
            $statements = 0;
            $affected = 0;
            do {
                $statements++;
                if ($res = $mysqli->store_result()) {
                    $res->free();
                }
                if ($mysqli->affected_rows > 0) {
                    $affected += (int)$mysqli->affected_rows;
                }
                if ($mysqli->errno) {
                    throw new RuntimeException($mysqli->error);
                }
            } while ($mysqli->more_results() && $mysqli->next_result());
            if ($mysqli->errno) {
                throw new RuntimeException($mysqli->error);
            }
            $tournIdResolved = ImportHistory::resolveTournId($mysqli, $sqlRun);
            $card = $tournIdResolved ? ImportHistory::fetchTournCard($mysqli, $tournIdResolved) : null;
            $mysqli->close();
            $cmbExecOk = true;
            $cmbExecMsg = "SQL выполнен: команд ≈ {$statements}, затронуто строк ≈ {$affected}";
            if ($tournIdResolved) {
                $cmbExecMsg .= "; tourn_id = {$tournIdResolved}";
            }
            $cmbExecCard = $card;
            ImportHistory::add([
                'kind' => 'club_mb',
                'tourn_id' => $tournIdResolved,
                'name' => $card['name'] ?? null,
                'type' => 5,
                'type_label' => 'клубный',
                'date' => $card['tour_date'] ?? null,
                'city' => $card['city_name'] ?? null,
                'statements' => $statements,
                'affected' => $affected,
                'ok' => true,
            ]);
            $reports[] = [
                'file' => '(выполненный SQL)',
                'meta' => ['region' => '', 'club' => '', 'date_from' => null, 'date_to' => null, 'total_declared' => null],
                'players' => [],
                'sql' => $sqlRun,
                'stats' => ['ok' => 0, 'warn' => 0, 'bad' => 0, 'n' => 0, 'sum_mb' => 0],
            ];
        } catch (Throwable $e) {
            $cmbExecOk = false;
            $error = 'Ошибка выполнения: ' . $e->getMessage();
            ImportHistory::add([
                'kind' => 'club_mb',
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
            $reports[] = [
                'file' => '(SQL с ошибкой)',
                'meta' => ['region' => '', 'club' => '', 'date_from' => null, 'date_to' => null, 'total_declared' => null],
                'players' => [],
                'sql' => $sqlRun,
                'stats' => ['ok' => 0, 'warn' => 0, 'bad' => 0, 'n' => 0, 'sum_mb' => 0],
            ];
        }
    }
}

// Загрузка и проверка файлов
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'clubmb' && empty($_POST['run_club_sql'])) {
    try {
        $files = [];
        if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $i => $name) {
                if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $files[] = [
                        'name' => $name,
                        'tmp' => $_FILES['files']['tmp_name'][$i],
                    ];
                }
            }
        } elseif (!empty($_FILES['file']['tmp_name'])) {
            $files[] = ['name' => $_FILES['file']['name'], 'tmp' => $_FILES['file']['tmp_name']];
        }
        if (!$files) {
            throw new RuntimeException('Загрузите один или несколько файлов .xls / .xlsx');
        }
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $tmp = sys_get_temp_dir() . '/cmb_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp'], $tmp)) {
                throw new RuntimeException('Не удалось сохранить ' . $f['name']);
            }
            try {
                $parsed = cmb_load_file($tmp, $f['name']);
                $validated = cmb_validate($parsed['players']);
                $db = cmb_db();
                $forcedCity = (!empty($_POST['city_id']) && ctype_digit((string)$_POST['city_id']))
                    ? (int)$_POST['city_id'] : null;
                $cityInfo = cmb_resolve_city($db, $parsed['meta'], $forcedCity);
                $citiesList = cmb_load_cities($db);
                if ($db) { $db->close(); }
                $cityId = $cityInfo['city_id'];
                $tournIdOpt = (!empty($_POST['tourn_id']) && ctype_digit((string)$_POST['tourn_id']))
                    ? (int)$_POST['tourn_id'] : null;
                $sql = null;
                $cityError = null;
                if ($cityId === null) {
                    $cityError = 'Не удалось определить город по региону «'
                        . ($parsed['meta']['region'] ?? '') . '». Выберите город в списке и нажмите «Проверить» снова.';
                } else {
                    $sql = cmb_build_sql($parsed['meta'], $validated, $cityId, $tournIdOpt);
                }
                $ok = 0; $warn = 0; $bad = 0; $sumMb = 0.0;
                foreach ($validated as $v) {
                    $sumMb += (float)($v['mb'] ?? 0);
                    if (($v['status'] ?? '') === 'ok') $ok++;
                    elseif (in_array($v['status'] ?? '', ['name_mismatch'], true)) $warn++;
                    else $bad++;
                }
                $reports[] = [
                    'file' => $f['name'],
                    'meta' => $parsed['meta'],
                    'players' => $validated,
                    'sql' => $sql,
                    'city' => $cityInfo,
                    'cities' => $citiesList,
                    'city_error' => $cityError,
                    'stats' => ['ok' => $ok, 'warn' => $warn, 'bad' => $bad, 'n' => count($validated), 'sum_mb' => $sumMb],
                ];
            } finally {
                @unlink($tmp);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<style>
.card{background:var(--card);border-radius:14px;padding:22px;margin-bottom:18px}
label{display:block;margin:10px 0 4px;color:var(--muted);font-size:.9rem}
.btn{display:inline-block;background:var(--accent);color:#fff;border:0;padding:12px 18px;border-radius:8px;font-size:1rem;cursor:pointer;margin-top:12px}
.flash{background:rgba(239,68,68,.15);color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:14px}
.note{font-size:.85rem;color:var(--muted);line-height:1.45}
table{width:100%;border-collapse:collapse;font-size:.9rem;margin:10px 0}
th,td{padding:6px 8px;border-bottom:1px solid #2a3548;text-align:left}
th{color:var(--muted);font-weight:600}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.75rem}
.badge-ok{background:rgba(34,197,94,.15);color:#86efac}
.badge-warn{background:rgba(245,158,11,.15);color:#fde68a}
.badge-err{background:rgba(239,68,68,.15);color:#fca5a5}
textarea.sql{width:100%;min-height:180px;font-family:ui-monospace,monospace;font-size:.8rem;background:#0f172a;color:var(--text);border:1px solid #334155;border-radius:8px;padding:10px}
.statrow{display:flex;gap:12px;flex-wrap:wrap;margin:8px 0}
.statrow span{background:#0f172a;padding:6px 10px;border-radius:8px;font-size:.85rem}
</style>

<div class="card">
  <h2 style="margin:0 0 8px;font-size:1.2rem">Клубные МБ (локальные турниры)</h2>
  <p class="note">Формат: «Отчет по МБ, набранным в локальных турнирах» — регион, период, колонки id / Игрок / МБ (две колонки игроков поддерживаются).</p>
  <?php if ($error): ?><div class="flash"><?= cmb_h($error) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="tab" value="clubmb">
    <label>Файлы .xls / .xlsx (можно несколько)</label>
    <input type="file" name="files[]" accept=".xls,.xlsx,application/vnd.ms-excel" multiple required
           style="color:var(--text)">
    <label>Город <span style="color:#fca5a5">*</span> (обязательно; по региону подбирается автоматически)</label>
    <?php
      $formCities = [];
      $dbC = cmb_db();
      if ($dbC) {
          $formCities = cmb_load_cities($dbC);
          $dbC->close();
      }
      $selCity = $_POST['city_id'] ?? '';
    ?>
    <select name="city_id" id="city_id" style="width:100%;max-width:420px;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">
      <option value="">— определить по региону из файла —</option>
      <?php foreach ($formCities as $c): ?>
      <option value="<?= (int)$c['city_id'] ?>" <?= ((string)$selCity === (string)$c['city_id']) ? 'selected' : '' ?>>
        <?= cmb_h($c['city_name']) ?> (id <?= (int)$c['city_id'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <p class="note">Пример: «Ростовская область» → Ростов-На-Дону. Если автоподбор не сработал — выберите город вручную.</p>

    <label>tourn_id (опционально — перезаписать существующий клубный отчёт)</label>
    <input type="number" name="tourn_id" min="1" value="<?= cmb_h((string)($_POST['tourn_id'] ?? '')) ?>"
           placeholder="пусто = новый ID" style="width:100%;max-width:280px;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">
    <p class="note">Если указать tourn_id: удалятся старые tourn_header + tourn_ind этого ID, затем вставятся заново (type=5). results не трогаем.</p>
    <button class="btn" type="submit">Проверить</button>
  </form>
</div>

<?php if (!empty($cmbExecOk)): ?>
<div class="card" style="border:1px solid #22c55e">
  <p style="margin:0;color:#86efac"><?= cmb_h($cmbExecMsg ?? 'SQL выполнен') ?></p>
  <?php if (!empty($cmbExecCard)): ?>
  <hr style="border:0;border-top:1px solid #334155;margin:12px 0">
  <p style="margin:4px 0;font-size:1.05rem"><b>#<?= (int)$cmbExecCard['tourn_id'] ?></b> — <?= cmb_h($cmbExecCard['name'] ?? '') ?></p>
  <p class="note" style="margin:4px 0">
    <?= cmb_h($cmbExecCard['type_label'] ?? 'клубный') ?>
    · <?= cmb_h((string)($cmbExecCard['tour_date_start'] ?? '')) ?> — <?= cmb_h((string)($cmbExecCard['tour_date'] ?? '')) ?>
    <?php if (!empty($cmbExecCard['city_name'])): ?> · <?= cmb_h($cmbExecCard['city_name']) ?><?php endif; ?>
    · ind: <?= (int)($cmbExecCard['counts']['tourn_ind'] ?? 0) ?>
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($reports as $rep): ?>
<div class="card">
  <h3 style="margin:0 0 6px"><?= cmb_h($rep['file']) ?></h3>
  <p class="note">
    Регион: <b><?= cmb_h($rep['meta']['region'] ?? '—') ?></b>
    <?php if (!empty($rep['meta']['club'])): ?> · Клуб: <b><?= cmb_h($rep['meta']['club']) ?></b><?php endif; ?>
    · Период: <b><?= cmb_h(($rep['meta']['date_from'] ?? '?') . ' — ' . ($rep['meta']['date_to'] ?? '?')) ?></b>
    <?php if (!empty($rep['city']['city_id'])): ?>
      · Город: <b><?= cmb_h($rep['city']['city_name'] ?? '') ?></b> (id <?= (int)$rep['city']['city_id'] ?>)
    <?php elseif (!empty($rep['city_error'])): ?>
      · Город: <b style="color:#fca5a5">не определён</b>
    <?php endif; ?>
    <?php if ($rep['meta']['total_declared'] !== null): ?>
      · В отчёте заявлено игроков: <?= (int)$rep['meta']['total_declared'] ?>
    <?php endif; ?>
    <?php if (!empty($_POST['tourn_id'])): ?> · перезапись <b>#<?= (int)$_POST['tourn_id'] ?></b><?php endif; ?>
  </p>
  <div class="statrow">
    <span>Игроков: <?= (int)$rep['stats']['n'] ?></span>
    <span>OK: <?= (int)$rep['stats']['ok'] ?></span>
    <span>Имя≠база: <?= (int)$rep['stats']['warn'] ?></span>
    <span>Проблемы: <?= (int)$rep['stats']['bad'] ?></span>
    <span>Сумма МБ: <?= cmb_h((string)$rep['stats']['sum_mb']) ?></span>
  </div>
  <table>
    <tr><th>#</th><th>ID</th><th>В отчёте</th><th>МБ</th><th>В базе</th><th>Город</th><th>Статус</th></tr>
    <?php foreach ($rep['players'] as $i => $p): ?>
    <tr>
      <td><?= (int)($p['n'] ?? $i + 1) ?></td>
      <td><?= cmb_h((string)($p['player_id'] ?? '—')) ?></td>
      <td><?= cmb_h($p['name'] ?? '') ?></td>
      <td><?= cmb_h((string)($p['mb'] ?? '')) ?></td>
      <td><?= cmb_h($p['db_fio'] ?? '—') ?></td>
      <td><?= cmb_h($p['city'] ?? '—') ?></td>
      <td><?php
        $st = $p['status'] ?? '';
        if ($st === 'ok') echo '<span class="badge badge-ok">OK</span>';
        elseif ($st === 'name_mismatch') echo '<span class="badge badge-warn">имя ≠ база</span>';
        elseif ($st === 'unknown_id') echo '<span class="badge badge-err">ID нет в базе</span>';
        elseif ($st === 'no_id') echo '<span class="badge badge-warn">нет ID</span>';
        else echo cmb_h($st);
        if (!empty($p['status_warn'])) echo ' <span class="badge badge-warn">' . cmb_h($p['status_warn']) . '</span>';
      ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if (!empty($rep['city_error'])): ?>
  <div class="flash" style="margin-top:12px"><?= cmb_h($rep['city_error']) ?></div>
  <p class="note">Выберите город в форме выше (выпадающий список) и снова нажмите «Проверить».</p>
  <?php elseif (!empty($rep['sql'])): ?>
  <label>SQL — tourn_header type=5 + tourn_ind (team_id=player_id). results не трогаем.</label>
  <form method="post" onsubmit="return confirm('Выполнить SQL клубных МБ в базе?');">
    <input type="hidden" name="tab" value="clubmb">
    <input type="hidden" name="run_club_sql" value="1">
    <textarea class="sql" name="sql_script"><?= cmb_h($rep['sql']) ?></textarea>
    <label style="display:flex;align-items:flex-start;gap:8px;margin-top:10px">
      <input type="checkbox" name="confirm_run" value="1" required style="margin-top:3px">
      Подтверждаю выполнение SQL
    </label>
    <button class="btn" type="submit" style="background:#ef4444">Выполнить SQL</button>
  </form>
  <?php else: ?>
  <p class="note" style="color:#fca5a5">SQL не сформирован: нужен город.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>

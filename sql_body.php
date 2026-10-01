<?php
declare(strict_types=1);

function sql_check_overwrite(?int $tournId, int $expectedType): ?string
{
    if ($tournId === null || $tournId < 1) {
        return null;
    }
    if (!defined('DB_HOST')) {
        return "Перезапись tourn_id={$tournId}: проверка типа в БД недоступна (нет config.php). SQL защищён условием type={$expectedType}.";
    }
    $mysqli = db_ro(); // чтение
    if ($mysqli === null) {
        return 'Не удалось проверить tourn_id (нет связи с БД)';
    }
    try {
        require_once __DIR__ . '/SqlExporter.php';
        // чужой тип → исключение (перезапись запрещена)
        $info = SqlExporter::checkOverwrite($mysqli, $tournId, $expectedType, true);
        return $info['preview'];
    } finally {
        $mysqli->close();
    }
}

require_once __DIR__ . '/SqlExporter.php';
require_once __DIR__ . '/TournamentSuggest.php';
require_once __DIR__ . '/ImportHistory.php';
require_once __DIR__ . '/ClubMb.php';
require_once __DIR__ . '/bootstrap.php';

/**
 * Проверка prev_id перед формированием SQL.
 * Ошибка — если prev не существует, это сессия, совпадает с tourn_id; требует подтверждения (confirm_prev) —
 * если у предыдущего турнира уже есть другой next_id или он не раньше нового по дате.
 */
function sql_check_prev(?int $prev, ?int $self, string $newFrom, bool $confirmed): void
{
    if ($prev === null) {
        return;
    }
    if ($self !== null && $prev === $self) {
        throw new InvalidArgumentException('prev_id не может совпадать с tourn_id');
    }
    if (!defined('DB_HOST')) {
        return;
    }
    $db = db_ro();
    if ($db === null) {
        return; // проверить нельзя; ошибка проявится при выполнении
    }
    try {
        $h = TournamentSuggest::fetchHeader($db, $prev);
    } finally {
        $db->close();
    }
    if (!$h) {
        throw new InvalidArgumentException("prev_id={$prev}: турнир не найден в базе");
    }
    if ((int)($h['type'] ?? 0) === 4 || !empty($h['parent'])) {
        throw new InvalidArgumentException("prev_id={$prev} — это сессия, а не турнир");
    }
    $warn = [];
    $next = isset($h['next_id']) && $h['next_id'] !== '' && $h['next_id'] !== null ? (int)$h['next_id'] : null;
    if ($next !== null && $next !== (int)$self) {
        $warn[] = "у турнира #{$prev} уже указан следующий #{$next} — эта связь будет заменена";
    }
    $pd = substr((string)($h['tour_date'] ?? ''), 0, 10);
    if ($pd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newFrom) && $pd >= $newFrom) {
        $warn[] = "предыдущий турнир #{$prev} датирован {$pd} — не раньше нового ({$newFrom})";
    }
    if ($warn && !$confirmed) {
        $GLOBALS['need_relink'] = true;
        throw new InvalidArgumentException(implode('; ', $warn) . '. Отметьте подтверждение под полем prev_id и сформируйте SQL ещё раз.');
    }
}

/** Положительное целое из POST; пусто → null; мусор / 0 → исключение (а не молчаливый 0). */
function post_pos_int(string $key, string $label): ?int
{
    $v = trim((string)($_POST[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    if (!ctype_digit($v) || (int)$v < 1) {
        throw new InvalidArgumentException("{$label}: ожидается целое число ≥ 1");
    }
    return (int)$v;
}

/** Целое (возможно 0 или отрицательное) из POST; пусто → null; мусор → исключение. */
function post_int(string $key, string $label): ?int
{
    $v = trim((string)($_POST[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $v)) {
        throw new InvalidArgumentException("{$label}: ожидается целое число");
    }
    return (int)$v;
}


$error = null;
$sqlText = null;
$execOk = null;
$execMsg = null;
$execCard = null;
$ratingOut = $_SESSION['rating_out'] ?? null;
$clubMbReport = $_SESSION['club_mb_report'] ?? null;
$clubMbSql = $_SESSION['club_mb_sql'] ?? null;
// Клубный режим только явно или если нет данных обычного расчёта
$isClubMb = false;
if (!empty($_POST['from_club_mb']) || !empty($_POST['build_club_sql'])) {
    $isClubMb = true;
} elseif (empty($ratingOut['results'] ?? null)
    && is_array($clubMbReport)
    && (($clubMbReport['format'] ?? '') === 'club_mb')
    && empty($_POST['tourn_name']) // не форма обычного SQL
) {
    $isClubMb = true;
}
// если есть результат расчёта турнира — всегда обычный SQL
if (!empty($ratingOut['results'] ?? null) && empty($_POST['from_club_mb']) && empty($_POST['build_club_sql'])) {
    $isClubMb = false;
}

// Пересборка SQL клубных МБ с выбранным городом / tourn_id
if ($isClubMb && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'sql'
    && !empty($_POST['build_club_sql']) && empty($_POST['run_sql'])) {
    try {
        $players = $clubMbReport['players'] ?? [];
        $meta = $clubMbReport['meta'] ?? [];
        $cityId = post_pos_int('city_id', 'city_id');
        $tournId = post_pos_int('tourn_id', 'tourn_id');
        if ($cityId === null) {
            throw new RuntimeException('Выберите город — для клубных МБ city_id обязателен');
        }
        $db = cmb_db();
        $cityName = null;
        if ($db) {
            $res = $db->query('SELECT city_name FROM cities WHERE city_id = ' . (int)$cityId . ' LIMIT 1');
            if ($res && ($row = $res->fetch_assoc())) {
                $cityName = (string)$row['city_name'];
            }
            $db->close();
        }
        if (!$cityName) {
            throw new RuntimeException('Город id=' . $cityId . ' не найден в cities');
        }
        $overwriteNote = sql_check_overwrite($tournId, 5);
        if ($overwriteNote) {
            // показываем в форме
            $GLOBALS['overwrite_preview'] = $overwriteNote;
        }
        $includeIds = array_values(array_filter(array_map('intval', (array)($_POST['include_ids'] ?? [])), fn($v) => $v > 0));
        $sqlText = cmb_build_sql($meta, $players, $cityId, $tournId, $cityName, $includeIds);
        $_SESSION['club_mb_sql'] = $sqlText;
        $clubMbSql = $sqlText;
        $_SESSION['club_mb_report'] = array_merge($clubMbReport, [
            'city' => ['city_id' => $cityId, 'city_name' => $cityName, 'matched_by' => 'manual'],
            'city_error' => null,
            'sql' => $sqlText,
        ]);
        $clubMbReport = $_SESSION['club_mb_report'];
        $_SESSION['last_sql'] = $sqlText;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($sqlText === null && $isClubMb && !empty($clubMbSql)) {
    $sqlText = $clubMbSql;
}

$refreshOnly = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'sql' && !empty($_POST['refresh_suggest']));

if (!$isClubMb && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'sql' && !$refreshOnly && empty($_POST['run_sql']) && empty($_POST['build_club_sql'])) {
    try {
        if (empty($ratingOut) || empty($ratingOut['results'])) {
            throw new RuntimeException('Нет данных расчёта. Сначала выполните шаг «Расчёт РО / ПБ / МБ».');
        }
        $owTid = post_pos_int('tourn_id', 'tourn_id');
        $cityIn = post_pos_int('city_id', 'city_id');
        $prevIn = post_pos_int('prev_id', 'prev_id');
        $champIn = post_int('champ_t', 'champ_t');
        $statusIn = post_int('status_db', 'status');
        $streamIn = post_int('stream', 'stream');
        $dFrom = trim((string)($_POST['date_from'] ?? ''));
        $dTo = trim((string)($_POST['date_to'] ?? ''));
        foreach (['Дата начала' => $dFrom, 'Дата окончания' => $dTo] as $lbl => $dv) {
            if ($dv !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv)) {
                throw new InvalidArgumentException("{$lbl}: формат YYYY-MM-DD");
            }
        }
        sql_check_prev($prevIn, $owTid,
            $dFrom !== '' ? $dFrom : (string)($ratingOut['meta']['date_from'] ?? ''),
            !empty($_POST['confirm_prev']));
        $owFmt = $ratingOut['params']['format'] ?? 'pair';
        $owType = SqlExporter::formatToType($owFmt);
        $overwriteNote = sql_check_overwrite($owTid, $owType);
        if ($overwriteNote) {
            $GLOBALS['overwrite_preview'] = $overwriteNote;
        }
        $sqlText = SqlExporter::build($ratingOut, [
            'scores' => $ratingOut['scores_opts'] ?? [],
            'tourn_id' => $owTid,
            'city_id' => $cityIn ?? 'NULL',
            'champ_t' => $champIn,
            'status_db' => $statusIn ?? 'NULL',
            'stream' => $streamIn ?? 'NULL',
            'prev_id' => $prevIn,
            'name' => trim((string)($_POST['tourn_name'] ?? '')),
            'date_from' => $dFrom,
            'date_to' => $dTo,
        ]);
        $_SESSION['last_sql'] = $sqlText;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
if ($sqlText === null && !empty($_SESSION['last_sql']) && empty($error) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // don't auto-show old sql without form
}

// Выполнить SQL из формы
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['tab'] ?? '') === 'sql' && !empty($_POST['run_sql'])) {
    $sqlRun = (string)($_POST['sql_script'] ?? '');
    if ($sqlRun === '' && !empty($_SESSION['last_sql'])) {
        $sqlRun = (string)$_SESSION['last_sql'];
    }
    $sqlText = $sqlRun !== '' ? $sqlRun : $sqlText;

    // одноразовый ключ выполнения: повтор POST (F5, двойной клик) не создаст дубль турнира
    $nonceSent = (string)($_POST['run_nonce'] ?? '');
    $nonceHave = (string)($_SESSION['run_nonce'] ?? '');
    $nonceOk = $nonceHave !== '' && hash_equals($nonceHave, $nonceSent);
    unset($_SESSION['run_nonce']); // сгорает в любом случае

    // выполняем только SQL, сформированный самим приложением в этой сессии
    $allowed = [];
    foreach ([$_SESSION['last_sql'] ?? null, $_SESSION['club_mb_sql'] ?? null,
              is_array($_SESSION['club_mb_report'] ?? null) ? ($_SESSION['club_mb_report']['sql'] ?? null) : null] as $cand) {
        if (is_string($cand) && $cand !== '') {
            $allowed[] = hash('sha256', sql_normalize($cand));
        }
    }
    $matches = in_array(hash('sha256', sql_normalize($sqlRun)), $allowed, true);
    $allowEdit = !empty(app_config()['allow_edit_sql']);

    if ($sqlRun === '') {
        $error = 'Нет SQL для выполнения';
    } elseif (empty($_POST['confirm_run'])) {
        $error = 'Отметьте подтверждение перед выполнением SQL';
    } elseif (!$nonceOk) {
        $error = 'Этот запрос уже выполнялся или устарел. Сформируйте SQL заново и повторите.';
    } elseif (!$matches && !$allowEdit) {
        $error = 'SQL не совпадает с сформированным приложением (правка вручную запрещена). Сформируйте SQL заново.';
    } else {
        $mysqli = null;
        try {
            $mysqli = db_rw(); // отдельная учётка: права только на нужные таблицы
            // multi_query: START TRANSACTION … COMMIT
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
            $execOk = true;
            $execMsg = "SQL выполнен: команд ≈ {$statements}, затронуто строк ≈ {$affected}";
            if ($tournIdResolved) {
                $execMsg .= "; tourn_id = {$tournIdResolved}";
            }
            $execCard = $card;
            ImportHistory::add([
                'kind' => 'tournament',
                'tourn_id' => $tournIdResolved,
                'name' => $card['name'] ?? null,
                'type' => $card['type'] ?? null,
                'type_label' => $card['type_label'] ?? null,
                'date' => $card['tour_date'] ?? null,
                'city' => $card['city_name'] ?? null,
                'statements' => $statements,
                'affected' => $affected,
                'ok' => true,
            ]);
        } catch (Throwable $e) {
            // откат: иначе при ошибке посреди скрипта транзакция остаётся открытой
            if ($mysqli instanceof mysqli) {
                try { @$mysqli->rollback(); @$mysqli->close(); } catch (Throwable $e2) { /* ignore */ }
            }
            $execOk = false;
            $error = 'Ошибка выполнения: ' . $e->getMessage();
            ImportHistory::add([
                'kind' => 'tournament',
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

// новый одноразовый ключ для формы выполнения (если на странице есть SQL)
if (!empty($sqlText)) {
    $_SESSION['run_nonce'] = bin2hex(random_bytes(16));
}
?>
<style>
.card{background:var(--card);border-radius:14px;padding:24px;max-width:640px;margin:0 auto 24px}
label{display:block;margin:12px 0 4px;font-size:.9rem;color:var(--muted)}
input[type=number],textarea{width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)}
.btn{display:block;width:100%;margin-top:16px;background:var(--accent);color:#fff;border:0;padding:12px;border-radius:8px;font-size:1rem;cursor:pointer}
.flash{background:rgba(239,68,68,.15);color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:16px}
.note{font-size:.85rem;color:var(--muted);margin-top:12px;line-height:1.45}
.okbox{background:rgba(34,197,94,.12);color:#86efac;padding:12px;border-radius:8px;margin-bottom:16px}
h1{font-size:1.35rem;margin:0 0 8px}
.sub{color:var(--muted);margin-bottom:20px;line-height:1.4}
</style>

<div class="card">
  <h1>Подготовка SQL</h1>
  <p class="sub">Подготовка SQL для записи турнира или клубных МБ в базу.</p>

  <?php if ($isClubMb): ?>
    <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>
    <?php if ($execOk): ?>
    <div class="okbox"><?= h($execMsg ?? 'SQL выполнен') ?></div>
    <?php if (!empty($execCard)): ?>
    <div style="margin-top:12px;padding:12px;border:1px solid #22c55e;border-radius:10px">
      <b>#<?= (int)$execCard['tourn_id'] ?></b> — <?= h($execCard['name'] ?? '') ?><br>
      <span class="note"><?= h($execCard['type_label'] ?? '') ?>
        · <?= h((string)($execCard['tour_date_start'] ?? '')) ?> — <?= h((string)($execCard['tour_date'] ?? '')) ?>
        <?php if (!empty($execCard['city_name'])): ?> · <?= h($execCard['city_name']) ?><?php endif; ?>
        · ind: <?= (int)($execCard['counts']['tourn_ind'] ?? 0) ?>
      </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <p class="note">
      Клубные МБ · регион: <b><?= h($clubMbReport['meta']['region'] ?? '—') ?></b>
      · игроков: <?= (int)count($clubMbReport['players'] ?? []) ?>
      · период: <?= h(($clubMbReport['meta']['date_from'] ?? '?') . ' — ' . ($clubMbReport['meta']['date_to'] ?? '?')) ?>
    </p>

    <?php
      $dbC = cmb_db();
      $cities = $dbC ? cmb_load_cities($dbC) : [];
      if ($dbC) $dbC->close();
      $selCity = $_POST['city_id'] ?? ($clubMbReport['city']['city_id'] ?? '');
      $selTourn = $_POST['tourn_id'] ?? '';
      $cityNamePreview = '';
      if ($selCity !== '' && $selCity !== null) {
          foreach ($cities as $c) {
              if ((int)$c['city_id'] === (int)$selCity) {
                  $cityNamePreview = $c['city_name'] . ' клубный';
                  break;
              }
          }
      }
    ?>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="tab" value="sql">
      <input type="hidden" name="build_club_sql" value="1">

      <label>Город <span style="color:#fca5a5">*</span></label>
      <select name="city_id" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">
        <option value="">— выберите город —</option>
        <?php foreach ($cities as $c): ?>
        <option value="<?= (int)$c['city_id'] ?>" <?= ((string)$selCity === (string)$c['city_id']) ? 'selected' : '' ?>>
          <?= h($c['city_name']) ?> (id <?= (int)$c['city_id'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <?php if ($cityNamePreview !== ''): ?>
      <p class="note">Название турнира: <b><?= h($cityNamePreview) ?></b></p>
      <?php else: ?>
      <p class="note">Название будет: <b>{Город} клубный</b></p>
      <?php endif; ?>

      <label>tourn_id (опционально — перезаписать существующий type=5)</label>
      <input type="number" name="tourn_id" min="1" value="<?= h((string)$selTourn) ?>" placeholder="пусто = новый ID">
      <?php if (!empty($GLOBALS['overwrite_preview'])): ?>
      <p class="note" style="color:var(--warn)"><?= h($GLOBALS['overwrite_preview']) ?></p>
      <?php endif; ?>

<?php $cmbQ = cmb_questionable($clubMbReport['players'] ?? []); $cmbInc = array_map('intval', (array)($_POST['include_ids'] ?? [])); ?>
      <?php if ($cmbQ): ?>
      <div style="margin-top:14px;padding:12px;border:1px solid var(--warn);border-radius:10px">
        <b style="color:var(--warn)">Требуют подтверждения (по умолчанию в SQL не попадают): <?= count($cmbQ) ?></b>
        <p class="note" style="margin:6px 0">Имя в отчёте не совпало с базой по ID, либо игрок «умер» / «не активен». Отметьте тех, кого нужно включить, и сформируйте SQL заново.</p>
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
          <tr style="color:var(--muted);text-align:left"><th></th><th>ID</th><th>В отчёте</th><th>В базе</th><th>МБ</th><th>Причина</th></tr>
          <?php foreach ($cmbQ as $qp): ?>
          <tr>
            <td><input type="checkbox" name="include_ids[]" value="<?= (int)$qp['player_id'] ?>" <?= in_array((int)$qp['player_id'], $cmbInc, true) ? 'checked' : '' ?>></td>
            <td><?= (int)$qp['player_id'] ?></td>
            <td><?= h((string)($qp['name'] ?? '')) ?></td>
            <td><?= h((string)($qp['db_fio'] ?? '')) ?></td>
            <td><?= h((string)($qp['mb'] ?? '')) ?></td>
            <td><?= h(trim((($qp['status'] ?? '') === 'name_mismatch' ? 'имя не совпало ' : '') . (string)($qp['status_warn'] ?? ''))) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endif; ?>
            <button class="btn" type="submit">Сформировать SQL</button>
    </form>

    <?php if ($sqlText): ?>
    <h2 style="margin-top:24px;font-size:1.05rem">SQL</h2>
    <form method="post" id="form-run-sql" onsubmit="return confirm('Выполнить SQL клубных МБ?');"><?= csrf_field() ?><input type="hidden" name="run_nonce" value="<?= h((string)($_SESSION['run_nonce'] ?? '')) ?>">
      <input type="hidden" name="tab" value="sql">
      <input type="hidden" name="run_sql" value="1">
      <textarea id="sql-out" name="sql_script" style="min-height:320px;font-family:ui-monospace,monospace;font-size:.8rem;width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)"><?= h($sqlText) ?></textarea>
      <label style="display:flex;align-items:flex-start;gap:8px;margin-top:12px;color:var(--muted);font-size:.9rem">
        <input type="checkbox" name="confirm_run" value="1" required style="margin-top:3px">
        Подтверждаю выполнение SQL
      </label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
        <button type="submit" class="btn" style="background:#ef4444;flex:1">Выполнить SQL</button>
        <button type="button" class="btn" id="btn-sql" style="background:#22c55e;flex:1">Скачать .sql</button>
      </div>
    </form>
    <script>
    document.getElementById('btn-sql').onclick = function() {
      var blob = new Blob([document.getElementById('sql-out').value], {type:'application/sql;charset=utf-8'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'club_mb_insert.sql';
      a.click();
    };
    </script>
    <?php endif; ?>

  <?php elseif (empty($ratingOut) || empty($ratingOut['results'])): ?>
    <div class="flash">Нет данных расчёта. Сначала вкладка «Расчёт РО / ПБ / МБ» (для обычных турниров) или загрузите клубные МБ на шаге проверки.</div>
    <p><a href="?tab=check" style="color:var(--accent)">← К проверке</a></p>
  <?php else: ?>
    <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>
    <?php if ($sqlText): ?><div class="okbox">SQL сформирован (<?= count($ratingOut['results']) ?> участников)</div><?php endif; ?>

    <p class="note">
      Турнир: <b><?= h($ratingOut['meta']['title'] ?? '—') ?></b><br>
      Участников: <?= (int)count($ratingOut['results']) ?>,
      d=<?= h((string)($ratingOut['params']['d'] ?? '—')) ?>,
      RC=<?= h((string)($ratingOut['params']['RC'] ?? '—')) ?>
    </p>

    <?php
      $meta = $ratingOut['meta'] ?? [];
      $defName = $_POST['tourn_name'] ?? ($meta['title'] ?? '');
      $defFrom = $_POST['date_from'] ?? ($meta['date_from'] ?? '');
      $defTo = $_POST['date_to'] ?? ($meta['date_to'] ?? '');

      $selfTid = (ctype_digit((string)($_POST['tourn_id'] ?? '')) && (int)$_POST['tourn_id'] >= 1) ? (int)$_POST['tourn_id'] : null;
      $newFromDate = substr(trim((string)$defFrom), 0, 10);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newFromDate)) {
          $newFromDate = '';
      }
      $prevSuggestions = [];
      $citySuggestions = [];
      $citiesList = [];
      $suggestTitle = trim((string)$defName);
      $placeStr = (string)($_POST['place'] ?? ($meta['place'] ?? ''));
      $defaultPrev = $_POST['prev_id'] ?? '';
      $defaultStream = $_POST['stream'] ?? '';
      $defaultCity = $_POST['city_id'] ?? '';
      $defaultChamp = $_POST['champ_t'] ?? '';
      $streamFromPrev = null;
      $champFromPrev = null;
      $cityFromPlace = null;
      // После смены названия вручную — заново ищем prev_id, stream, champ_t
      $lastName = (string)($_SESSION['sql_suggest_title'] ?? '');
      $nameChanged = ($suggestTitle !== '' && $lastName !== '' && $suggestTitle !== $lastName);
      if ($nameChanged || !empty($_POST['refresh_suggest'])) {
          $defaultPrev = '';
          $defaultStream = '';
          $defaultChamp = '';
          $streamFromPrev = null;
          $champFromPrev = null;
      }
      if ($suggestTitle !== '') {
          $_SESSION['sql_suggest_title'] = $suggestTitle;
      }
      try {
          if (defined('DB_HOST')) {
              $mysqli = db_ro();
              if ($mysqli !== null) {
                  $citiesList = TournamentSuggest::allCities($mysqli);
                  if ($placeStr !== '') {
                      $citySuggestions = TournamentSuggest::suggestCity($mysqli, $placeStr, 12);
                      if ($defaultCity === '' && $citySuggestions) {
                          $cityFromPlace = (int)$citySuggestions[0]['city_id'];
                          $defaultCity = (string)$cityFromPlace;
                      }
                  }
                  // stream и champ_t из выбранного prev_id
                  if ($defaultPrev !== '' && ctype_digit((string)$defaultPrev)) {
                      $hdr = TournamentSuggest::fetchHeader($mysqli, (int)$defaultPrev);
                      if ($hdr) {
                          if (isset($hdr['stream']) && $hdr['stream'] !== null && $hdr['stream'] !== '') {
                              $streamFromPrev = (int)$hdr['stream'];
                          }
                          if (isset($hdr['champ_t']) && $hdr['champ_t'] !== null && $hdr['champ_t'] !== '') {
                              $champFromPrev = (int)$hdr['champ_t'];
                          }
                      }
                  }
                  $preferStream = $streamFromPrev;
                  if ($preferStream === null && $defaultStream !== '' && ctype_digit((string)$defaultStream)) {
                      $preferStream = (int)$defaultStream;
                  }
                  if ($suggestTitle !== '') {
                      $prevSuggestions = TournamentSuggest::suggestPrev($mysqli, $suggestTitle, 8, $preferStream, $selfTid);
                  }
                  if ($defaultPrev === '' && $prevSuggestions) {
                      // автоподстановка — только безопасного кандидата: без чужого next_id и не позже нового по дате
                      $pick = null;
                      foreach ($prevSuggestions as $cand) {
                          if (!empty($cand['next_id']) && (int)$cand['next_id'] !== (int)$selfTid) continue;
                          if ($newFromDate !== '' && !empty($cand['tour_date'])
                              && substr((string)$cand['tour_date'], 0, 10) >= $newFromDate) continue;
                          $pick = $cand;
                          break;
                      }
                      if ($pick !== null) {
                          $defaultPrev = (string)$pick['tourn_id'];
                          if ($streamFromPrev === null && !empty($pick['stream'])) {
                              $streamFromPrev = (int)$pick['stream'];
                          }
                          // подтянуть champ_t с кандидата
                          if ($champFromPrev === null && ctype_digit($defaultPrev)) {
                              $hdr2 = TournamentSuggest::fetchHeader($mysqli, (int)$defaultPrev);
                              if ($hdr2 && isset($hdr2['champ_t']) && $hdr2['champ_t'] !== null && $hdr2['champ_t'] !== '') {
                                  $champFromPrev = (int)$hdr2['champ_t'];
                              }
                          }
                      }
                  }
                  // подписи stream / prev
                  $streamLabel = null;
                  $prevLabel = null;
                  $streamsList = TournamentSuggest::allStreams($mysqli, 300);
                  $sidLook = null;
                  if ($defaultStream !== '' && ctype_digit((string)$defaultStream)) {
                      $sidLook = (int)$defaultStream;
                  } elseif ($streamFromPrev !== null) {
                      $sidLook = (int)$streamFromPrev;
                  }
                  if ($sidLook) {
                      $streamLabel = TournamentSuggest::fetchStreamName($mysqli, $sidLook);
                  }
                  if ($defaultPrev !== '' && ctype_digit((string)$defaultPrev)) {
                      $ph = TournamentSuggest::fetchHeader($mysqli, (int)$defaultPrev);
                      if ($ph) {
                          $prevLabel = (string)($ph['name'] ?? '');
                      }
                  }
                  $mysqli->close();
              }
          }
      } catch (Throwable $e) {
          $prevSuggestions = [];
          $citySuggestions = [];
      }
      if (!isset($streamLabel)) $streamLabel = null;
      if (!isset($prevLabel)) $prevLabel = null;
      if (!isset($streamsList)) $streamsList = [];
      if ($defaultStream === '' && $streamFromPrev !== null) {
          $defaultStream = (string)$streamFromPrev;
      }
      if ($defaultChamp === '' && $champFromPrev !== null) {
          $defaultChamp = (string)$champFromPrev;
      }
    ?>

    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="tab" value="sql">

      <label>Название турнира</label>
      <input type="text" name="tourn_name" id="field-name" value="<?= h((string)$defName) ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">
      <button type="submit" name="refresh_suggest" value="1" class="btn" style="margin-top:8px;background:#475569;font-size:.9rem">
        Найти prev_id и stream по названию
      </button>
      <p class="note" style="margin:6px 0 0">После правки названия нажмите кнопку или «Сформировать SQL» — подсказки prev/stream пересчитаются.</p>

      <label>Дата начала (YYYY-MM-DD)</label>
      <input type="text" name="date_from" value="<?= h((string)$defFrom) ?>" placeholder="2026-09-01" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">

      <label>Дата окончания (YYYY-MM-DD)</label>
      <input type="text" name="date_to" value="<?= h((string)$defTo) ?>" placeholder="2026-09-02" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">

      <label>champ_t (опционально; подставляется с prev_id)</label>
      <input type="number" name="champ_t" id="field-champ" value="<?= h((string)($defaultChamp ?? '')) ?>" placeholder="NULL">
      <?php if ($champFromPrev !== null): ?>
      <p class="note">champ_t=<?= (int)$champFromPrev ?> взят с предыдущего турнира</p>
      <?php endif; ?>

      <label>tourn_id (перезапись только того же type; results не трогаем)</label>
      <input type="number" name="tourn_id" min="1" value="<?= h($_POST['tourn_id'] ?? '') ?>" placeholder="новый ID автоматически">
      <?php if (!empty($GLOBALS['overwrite_preview'])): ?>
      <p class="note" style="color:var(--warn)"><?= h($GLOBALS['overwrite_preview']) ?></p>
      <?php endif; ?>

      <label>Город (city_id) — по месту проведения из отчёта</label>
      <?php if ($placeStr !== ''): ?>
      <p class="note">Место проведения: <b><?= h($placeStr) ?></b>
        <?php if ($cityFromPlace !== null): ?> → предложен city_id=<?= (int)$cityFromPlace ?><?php endif; ?>
      </p>
      <?php endif; ?>
      <select name="city_id" id="field-city" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">
        <option value="">— не указан (NULL) —</option>
        <?php foreach ($citiesList as $c): ?>
        <option value="<?= (int)$c['city_id'] ?>" <?= ((string)$defaultCity === (string)$c['city_id']) ? 'selected' : '' ?>>
          <?= h($c['city_name']) ?> (<?= (int)$c['city_id'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($citySuggestions)): ?>
      <p class="note"><b>Подсказки по месту:</b>
        <?php foreach ($citySuggestions as $cs): ?>
          <a href="#" class="city-pick" data-id="<?= (int)$cs['city_id'] ?>" style="color:var(--accent);margin-right:8px">
            <?= h($cs['city_name']) ?>
          </a>
        <?php endforeach; ?>
      </p>
      <script>
      document.querySelectorAll('.city-pick').forEach(function(a){
        a.addEventListener('click', function(e){
          e.preventDefault();
          var sel = document.getElementById('field-city');
          if (sel) sel.value = this.getAttribute('data-id') || '';
        });
      });
      </script>
      <?php elseif ($placeStr !== '' && empty($citiesList)): ?>
      <p class="note">Не удалось загрузить справочник cities (проверьте доступ к БД).</p>
      <?php endif; ?>

      <label>status в БД (опционально)</label>
      <input type="number" name="status_db" value="<?= h($_POST['status_db'] ?? '') ?>" placeholder="NULL">

      <label>stream (опционально; у сессий всегда NULL)</label>
      <input type="number" name="stream" id="field-stream" value="<?= h((string)($defaultStream ?? '')) ?>" placeholder="NULL" list="streams-datalist">
      <datalist id="streams-datalist">
        <?php foreach ($streamsList as $st): ?>
        <option value="<?= (int)$st['stream_id'] ?>"><?= h($st['name']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <p class="note" id="stream-hint">
        <?php if ($defaultStream !== '' || $streamFromPrev !== null): ?>
          код <b><?= h((string)($defaultStream !== '' ? $defaultStream : $streamFromPrev)) ?></b>
          <?php if ($streamLabel): ?> — <?= h($streamLabel) ?><?php endif; ?>
          <?php if ($streamFromPrev !== null): ?> (с prev_id)<?php endif; ?>
        <?php else: ?>
          введите код stream; название подставится после «Найти prev_id и stream» или формирования SQL
        <?php endif; ?>
      </p>

      <label>prev_id — предыдущий турнир серии (опционально)</label>
      <input type="number" name="prev_id" id="field-prev" min="1" value="<?= h((string)($defaultPrev ?? '')) ?>" placeholder="NULL">
      <p class="note" id="prev-hint">
        <?php if ($defaultPrev !== ''): ?>
          код <b>#<?= h((string)$defaultPrev) ?></b><?php if ($prevLabel): ?> — <?= h($prevLabel) ?><?php endif; ?>
        <?php else: ?>
          если указан: prev_id у нового + next_id у предыдущего; stream/champ_t можно взять с него
        <?php endif; ?>
      </p>
      <?php if (!empty($GLOBALS['need_relink'])): ?>
      <label style="display:flex;align-items:flex-start;gap:8px;margin-top:8px;color:#fcd34d;font-size:.9rem">
        <input type="checkbox" name="confirm_prev" value="1" style="margin-top:3px">
        Подтверждаю prev_id=<?= h((string)$defaultPrev) ?> несмотря на предупреждение выше
      </label>
      <?php endif; ?>
      <?php if (!empty($prevSuggestions)): ?>
      <p class="note"><b>Похожие турниры в базе</b> (клик подставит prev_id):</p>
      <ul style="font-size:.9rem;line-height:1.5;margin:0 0 12px 1.2em">
        <?php foreach ($prevSuggestions as $ps): ?>
        <li>
          <a href="#" class="prev-pick" data-id="<?= (int)$ps['tourn_id'] ?>"
             data-stream="<?= $ps['stream'] !== null ? (int)$ps['stream'] : '' ?>"
             data-name="<?= h($ps['name']) ?>"
             style="color:var(--accent)">
            #<?= (int)$ps['tourn_id'] ?> · <?= h($ps['name']) ?>
            <?php if (!empty($ps['tour_date'])): ?> (<?= h($ps['tour_date']) ?>)<?php endif; ?>
            <?php if ($ps['stream'] !== null): ?> · stream=<?= (int)$ps['stream'] ?><?php endif; ?>
          </a>
          <?php if (!empty($ps['next_id']) && (int)$ps['next_id'] !== (int)$selfTid): ?>
            <span style="color:var(--warn)">⚠ уже есть next_id=#<?= (int)$ps['next_id'] ?></span>
          <?php endif; ?>
          <?php if ($newFromDate !== '' && !empty($ps['tour_date']) && substr((string)$ps['tour_date'], 0, 10) >= $newFromDate): ?>
            <span style="color:var(--warn)">⚠ не раньше нового по дате</span>
          <?php endif; ?>
          <span style="color:var(--muted)"> — <?= h($ps['reason']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <script>
      document.querySelectorAll('.prev-pick').forEach(function(a){
        a.addEventListener('click', function(e){
          e.preventDefault();
          var prev = document.getElementById('field-prev');
          var stream = document.getElementById('field-stream');
          var prevHint = document.getElementById('prev-hint');
          var streamHint = document.getElementById('stream-hint');
          var id = this.getAttribute('data-id') || '';
          var st = this.getAttribute('data-stream') || '';
          var name = this.getAttribute('data-name') || this.textContent.trim();
          if (prev) prev.value = id;
          if (stream && st !== '') stream.value = st;
          if (prevHint) prevHint.innerHTML = 'код <b>#' + id + '</b> — ' + name.replace(/</g,'');
          if (streamHint && st !== '') streamHint.innerHTML = 'код <b>' + st + '</b> (с prev_id)';
        });
      });
      </script>
      <?php elseif ($suggestTitle !== ''): ?>
      <p class="note">Похожих турниров в базе не найдено (или нет доступа к БД).</p>
      <?php endif; ?>

      <button class="btn" type="submit">Сформировать SQL</button>
    </form>

    <?php if ($execOk): ?>
    <div class="okbox"><?= h($execMsg ?? 'SQL выполнен') ?></div>
    <?php if (!empty($execCard)): ?>
    <div class="card" style="margin-top:12px;border:1px solid #22c55e">
      <h3 style="margin:0 0 8px;font-size:1.1rem">Карточка турнира</h3>
      <p style="margin:4px 0;font-size:1.05rem"><b>#<?= (int)$execCard['tourn_id'] ?></b> — <?= h($execCard['name'] ?? '') ?></p>
      <p class="note" style="margin:4px 0">
        Тип: <b><?= h($execCard['type_label'] ?? '') ?></b>
        · Дата: <?= h((string)($execCard['tour_date_start'] ?? '')) ?>
        <?php if (!empty($execCard['tour_date']) && ($execCard['tour_date'] ?? '') !== ($execCard['tour_date_start'] ?? '')): ?>
          — <?= h((string)$execCard['tour_date']) ?>
        <?php endif; ?>
        <?php if (!empty($execCard['city_name'])): ?> · Город: <?= h($execCard['city_name']) ?><?php endif; ?>
      </p>
      <p class="note" style="margin:4px 0">
        Записей: pair <?= (int)($execCard['counts']['tourn_pair'] ?? 0) ?>,
        team <?= (int)($execCard['counts']['tourn_team'] ?? 0) ?>,
        ind <?= (int)($execCard['counts']['tourn_ind'] ?? 0) ?>,
        ses <?= (int)($execCard['counts']['tourn_ses'] ?? 0) ?>
        <?php if ($execCard['stream'] !== null): ?> · stream=<?= (int)$execCard['stream'] ?><?php endif; ?>
        <?php if ($execCard['prev_id'] !== null): ?> · prev_id=<?= (int)$execCard['prev_id'] ?><?php endif; ?>
      </p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($sqlText): ?>
    <h2 style="margin-top:24px;font-size:1.05rem">SQL</h2>
    <form method="post" id="form-run-sql" onsubmit="return confirm('Выполнить SQL в базе <?= h(defined('DB_NAME') ? DB_NAME : '') ?>?');"><?= csrf_field() ?><input type="hidden" name="run_nonce" value="<?= h((string)($_SESSION['run_nonce'] ?? '')) ?>">
      <input type="hidden" name="tab" value="sql">
      <input type="hidden" name="run_sql" value="1">
      <textarea id="sql-out" name="sql_script" style="min-height:320px;font-family:ui-monospace,monospace;font-size:.8rem;width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)"><?= h($sqlText) ?></textarea>
      <label style="display:flex;align-items:flex-start;gap:8px;margin-top:12px;color:var(--muted);font-size:.9rem">
        <input type="checkbox" name="confirm_run" value="1" required style="margin-top:3px">
        Подтверждаю выполнение SQL в базе <b><?= h(defined('DB_NAME') ? (string)DB_NAME : '') ?></b>
      </label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
        <button type="submit" class="btn" style="background:#ef4444;flex:1">Выполнить SQL</button>
        <button type="button" class="btn" id="btn-sql" style="background:#22c55e;flex:1">Скачать .sql</button>
      </div>
    </form>
    <script>
    document.getElementById('btn-sql').onclick = function() {
      var blob = new Blob([document.getElementById('sql-out').value], {type:'application/sql;charset=utf-8'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'tournament_insert.sql';
      a.click();
    };
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>

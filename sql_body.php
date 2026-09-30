<?php
declare(strict_types=1);
require_once __DIR__ . '/SqlExporter.php';
require_once __DIR__ . '/TournamentSuggest.php';
require_once __DIR__ . '/ImportHistory.php';
require_once __DIR__ . '/ClubMb.php';
if (file_exists(__DIR__ . '/config.php')) {
    $CONFIG = require __DIR__ . '/config.php';
    if (!defined('DB_HOST')) {
        define('DB_HOST', $CONFIG['db_host']);
        define('DB_USER', $CONFIG['db_user']);
        define('DB_PASS', $CONFIG['db_pass']);
        define('DB_NAME', $CONFIG['db_name']);
    }
}

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
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
        $cityId = (!empty($_POST['city_id']) && ctype_digit((string)$_POST['city_id']))
            ? (int)$_POST['city_id'] : null;
        $tournId = (!empty($_POST['tourn_id']) && ctype_digit((string)$_POST['tourn_id']))
            ? (int)$_POST['tourn_id'] : null;
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
        $sqlText = cmb_build_sql($meta, $players, $cityId, $tournId, $cityName);
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
        $sqlText = SqlExporter::build($ratingOut, [
            'scores' => $ratingOut['scores_opts'] ?? [],
            'tourn_id' => ($_POST['tourn_id'] ?? '') !== '' ? (int)$_POST['tourn_id'] : null,
            'city_id' => ($_POST['city_id'] ?? '') !== '' ? (int)$_POST['city_id'] : 'NULL',
            'champ_t' => ($_POST['champ_t'] ?? '') !== '' ? (int)$_POST['champ_t'] : null,
            'status_db' => ($_POST['status_db'] ?? '') !== '' ? (int)$_POST['status_db'] : 'NULL',
            'stream' => ($_POST['stream'] ?? '') !== '' ? (int)$_POST['stream'] : 'NULL',
            'prev_id' => ($_POST['prev_id'] ?? '') !== '' ? (int)$_POST['prev_id'] : null,
            'name' => trim((string)($_POST['tourn_name'] ?? '')),
            'date_from' => trim((string)($_POST['date_from'] ?? '')),
            'date_to' => trim((string)($_POST['date_to'] ?? '')),
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
    if ($sqlRun === '') {
        $error = 'Нет SQL для выполнения';
    } elseif (empty($_POST['confirm_run'])) {
        $error = 'Отметьте подтверждение перед выполнением SQL';
    } else {
        try {
            if (!defined('DB_HOST')) {
                throw new RuntimeException('Нет настроек БД (config.php)');
            }
            $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($mysqli->connect_errno) {
                throw new RuntimeException('Подключение: ' . $mysqli->connect_error);
            }
            $mysqli->set_charset('utf8mb4');
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
  <p class="sub">Турнир: <code>tourn_header</code> + pair/team/ind/ses. Клубные МБ: type=5, <code>tourn_ind</code> (team_id = player_id). Таблица <code>results</code> не трогаем — её обновляют месячные скрипты.</p>

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
      Клубные МБ (type=5) · регион: <b><?= h($clubMbReport['meta']['region'] ?? '—') ?></b>
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
    <form method="post">
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

      <label>tourn_id (опционально — перезаписать существующий)</label>
      <input type="number" name="tourn_id" min="1" value="<?= h((string)$selTourn) ?>" placeholder="пусто = новый ID">

      <button class="btn" type="submit">Сформировать SQL</button>
    </form>

    <?php if ($sqlText): ?>
    <h2 style="margin-top:24px;font-size:1.05rem">SQL</h2>
    <form method="post" id="form-run-sql" onsubmit="return confirm('Выполнить SQL клубных МБ?');">
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
              $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
              if ($mysqli && !$mysqli->connect_errno) {
                  $mysqli->set_charset('utf8mb4');
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
                      $prevSuggestions = TournamentSuggest::suggestPrev($mysqli, $suggestTitle, 8, $preferStream);
                  }
                  if ($defaultPrev === '' && $prevSuggestions) {
                      $defaultPrev = (string)$prevSuggestions[0]['tourn_id'];
                      if ($streamFromPrev === null && !empty($prevSuggestions[0]['stream'])) {
                          $streamFromPrev = (int)$prevSuggestions[0]['stream'];
                      }
                      // подтянуть champ_t с лучшего кандидата
                      if ($champFromPrev === null && ctype_digit($defaultPrev)) {
                          $hdr2 = TournamentSuggest::fetchHeader($mysqli, (int)$defaultPrev);
                          if ($hdr2 && isset($hdr2['champ_t']) && $hdr2['champ_t'] !== null && $hdr2['champ_t'] !== '') {
                              $champFromPrev = (int)$hdr2['champ_t'];
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

    <form method="post">
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

      <label>tourn_id (если указать — удалятся старые данные этого турнира и вставится заново)</label>
      <input type="number" name="tourn_id" min="1" value="<?= h($_POST['tourn_id'] ?? '') ?>" placeholder="новый ID автоматически">

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
    <form method="post" id="form-run-sql" onsubmit="return confirm('Выполнить SQL в базе <?= h(defined('DB_NAME') ? DB_NAME : '') ?>?');">
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

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
app_session_start();
csrf_enforce(); // публичная форма, но POST только с токеном


function db(): ?mysqli {
    return db_questionnaire(); // отдельная учётка: INSERT только в aux_questionaries
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
                   p.sex, p.phone, p.mail, p.city_id, c.city_name, p.club_id,
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
            'phone' => $row['phone'],
            'mail' => $row['mail'],
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

        // fsbr_copy.aux_questionaries (текущая БД из config)
        $sql = "INSERT INTO aux_questionaries
            (player_id, type, timestamp, firstname, lastname, surname, birthdate, sex, city, region,
             phone, mail, bbo, gambler, WBF, acbl, is_sputnik, is_sirius, first_tourn, club_id)
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
              {$escInt($clubId)}
            )";
        if (!$mysqli->query($sql)) {
            throw new RuntimeException('Не удалось сохранить анкету: ' . $mysqli->error);
        }
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
    :root { --bg:#0f172a; --card:#1e293b; --text:#e2e8f0; --muted:#94a3b8; --accent:#38bdf8; --ok:#22c55e; --err:#ef4444; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); line-height:1.45; }
    .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 48px; }
    h1 { font-size: 1.4rem; margin: 0 0 8px; }
    .sub { color: var(--muted); margin-bottom: 20px; font-size: .95rem; }
    .card { background: var(--card); border-radius: 14px; padding: 22px; margin-bottom: 16px; }
    label { display:block; margin: 12px 0 4px; font-size: .9rem; color: var(--muted); }
    label .req { color: #f87171; }
    input[type=text], input[type=email], input[type=tel], input[type=date], input[type=number], select, textarea {
      width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #334155;
      background: #0f172a; color: var(--text); font-size: 1rem;
    }
    .modes { display: flex; gap: 10px; margin-bottom: 16px; }
    .modes label { display:flex; align-items:center; gap:8px; margin:0; padding:10px 14px; background:#0f172a; border-radius:8px; cursor:pointer; color:var(--text); border:1px solid #334155; }
    .modes input { accent-color: var(--accent); }
    .btn { display:block; width:100%; margin-top:18px; background:var(--accent); color:#0f172a; border:0; padding:12px; border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer; }
    .btn:hover { filter: brightness(1.08); }
    .flash { background: rgba(239,68,68,.15); color:#fca5a5; padding:12px; border-radius:8px; margin-bottom:14px; }
    .ok { background: rgba(34,197,94,.15); color:#86efac; padding:12px; border-radius:8px; margin-bottom:14px; }
    .note { font-size:.85rem; color:var(--muted); margin-top:8px; }
    .warn-box { background: rgba(251,191,36,.12); color:#fde68a; padding:12px; border-radius:8px; margin-bottom:14px; font-size:.9rem; }
    #search-results { list-style:none; margin:8px 0 0; padding:0; max-height:220px; overflow:auto; border:1px solid #334155; border-radius:8px; display:none; }
    #search-results li { padding:10px 12px; cursor:pointer; border-bottom:1px solid #334155; }
    #search-results li:hover { background:#334155; }
    .readonly-block { opacity: .95; }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; }
    @media (max-width:560px) { .grid2 { grid-template-columns:1fr; } }
    .consent { font-size:.8rem; color:var(--muted); max-height:120px; overflow:auto; border:1px solid #334155; padding:10px; border-radius:8px; margin-top:8px; }
    .section-title { font-size:1.05rem; margin:20px 0 0; color:var(--accent); }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Анкета игрока ФСБР</h1>
  <p class="sub">Федерация спортивного бриджа России. Информация из анкеты появится на сайте в начале следующего месяца.</p>

  <?php if ($success): ?>
  <div class="card" style="text-align:center;padding:36px 24px">
    <div style="font-size:2.5rem;margin-bottom:12px">✓</div>
    <h2 style="margin:0 0 12px;font-size:1.35rem;color:#86efac">Данные успешно внесены</h2>
    <p style="color:var(--muted);margin:0 0 8px;line-height:1.5">
      Информация на сайте обновится в течение месяца.
    </p>
    <p style="color:var(--text);font-size:1.1rem;margin:16px 0 24px">Спасибо!</p>
    <a class="btn" href="questionnaire.php" style="text-decoration:none;display:inline-block;width:auto;padding:12px 28px">Отправить ещё одну анкету</a>
  </div>
  <?php else: ?>

  <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>

  <div class="warn-box">
    Фото на сайт: отправьте на dihnova@ya.ru с указанием ID игрока.
  </div>

  <form method="post" id="anketa-form" class="card"><?= csrf_field() ?>
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
    </div>

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

    <label>E-mail <span class="req" data-req="new">*</span></label>
    <input type="text" name="mail" id="mail" placeholder="email@example.com" <?= $mode === 'new' ? 'required' : '' ?>>

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

    <label style="display:flex;align-items:center;gap:8px;color:var(--text);margin-top:12px">
      <input type="checkbox" name="is_sputnik" id="is_sputnik" value="1"> Выпускник школы «Спутник»
    </label>
    <label style="display:flex;align-items:center;gap:8px;color:var(--text)">
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

    <p class="section-title">Согласие</p>
    <div class="consent">
      Настоящим я даю согласие Общероссийской общественной организации «Федерации спортивного бриджа России» (ФСБР)
      на обработку моих персональных данных (ФИО, регион, email, телефон, дата рождения, участие в соревнованиях, фотография),
      включая сбор, хранение, уточнение и размещение на сайте ФСБР ФИО, фотографии и результатов соревнований.
      Подтверждаю, что мне исполнилось 18 лет (либо действует законный представитель), данные достоверны.
      Отзыв согласия — письменно в ФСБР не менее чем за 3 месяца.
    </div>
    <label style="display:flex;align-items:flex-start;gap:8px;color:var(--text);margin-top:10px">
      <input type="checkbox" name="consent" value="1" required style="margin-top:4px">
      <span>Согласен(на) на обработку персональных данных <span class="req">*</span></span>
    </label>

    <button type="submit" class="btn">Отправить анкету</button>
  </form>
</div>
<script>
function setMode(mode) {
  document.getElementById('block-search').style.display = mode === 'update' ? '' : 'none';
  document.querySelectorAll('[data-req="new"]').forEach(function(el) {
    el.style.display = mode === 'new' ? '' : 'none';
  });
  document.querySelectorAll('[data-req="update-note"]').forEach(function(el) {
    el.style.display = mode === 'update' ? '' : 'none';
  });
  ['family_name','given_name','patronymic','birthdate','sex','city','region','phone','mail'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (mode === 'new') el.setAttribute('required', 'required');
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
  document.getElementById('phone').value = p.phone || '';
  document.getElementById('mail').value = p.mail || '';
  document.getElementById('bbo').value = p.bbo || '';
  document.getElementById('gambler').value = p.gambler || '';
  document.getElementById('wbf').value = p.wbf || '';
  document.getElementById('acbl').value = p.acbl || '';
  document.getElementById('is_sputnik').checked = !!p.is_sputnik;
  document.getElementById('is_sirius').checked = !!p.is_sirius;
  document.getElementById('first_tourn').value = p.first_tourn || '';
  document.getElementById('club_id').value = p.club_id || '';
  document.getElementById('region').value = ''; // в карточке нет региона — оставляем пустым для ввода
  document.getElementById('search-results').style.display = 'none';
  document.getElementById('search-q').value = p.label;
}
</script>
  <?php endif; ?>
</div>
</body>
</html>

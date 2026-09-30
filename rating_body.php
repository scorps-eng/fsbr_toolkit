<?php
declare(strict_types=1);
require_once __DIR__ . '/RatingCalculator.php';
require_once __DIR__ . '/TournamentSuggest.php';
require_once __DIR__ . '/SqlExporter.php';


function normalize_team(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return $s;
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$out = null;
$meta = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $format = $_POST['format'] ?? 'pair';
        $status = $_POST['status'] ?? 'rating';
        if (!in_array($format, ['pair', 'team', 'individual'], true)) {
            throw new RuntimeException('Неверный формат турнира');
        }
        if (!in_array($status, ['express', 'rating', 'regional', 'russian', 'main_russian'], true)) {
            throw new RuntimeException('Неверный статус турнира');
        }
        $guaranteed = $_POST['guaranteed'] ?? 'none';
        if (!array_key_exists($guaranteed, RatingCalculator::GUARANTEED_PB)) {
            $guaranteed = 'none';
        }

        $jsonText = '';
        if (!empty($_FILES['json']['tmp_name'])) {
            $jsonText = file_get_contents($_FILES['json']['tmp_name']);
        } elseif (!empty($_POST['json_text'])) {
            $jsonText = $_POST['json_text'];
        }
        if ($jsonText === '' || $jsonText === false) {
            throw new RuntimeException('Загрузите JSON-отчёт');
        }
        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            throw new RuntimeException('Некорректный JSON');
        }

        $meta = $data['meta'] ?? [];
        $d = null;
        if (isset($_POST['length']) && $_POST['length'] !== '') {
            $d = (float)$_POST['length'];
        } elseif (isset($meta['length_actual']) && $meta['length_actual'] !== '' && $meta['length_actual'] !== null) {
            $d = (float)$meta['length_actual'];
        } elseif (isset($meta['length']) && $meta['length'] !== '' && $meta['length'] !== null) {
            $d = (float)$meta['length'];
        }
        if ($d === null || $d <= 0) {
            throw new RuntimeException('Не задана длина турнира (d). Укажите вручную или в meta.length_actual');
        }

        // Формат всегда из типа отчёта (JSON), не из ручного выбора
        if (!empty($data['format']) && in_array($data['format'], ['pair', 'team', 'individual'], true)) {
            $format = $data['format'];
        } elseif (!empty($data['teams']) && empty($data['sum']['pairs'] ?? null)) {
            $format = 'team';
        } elseif (!empty($data['sum']['pairs'])) {
            $format = 'pair';
        }

        $calc = new RatingCalculator($format, $status, $d, $guaranteed);

        if ($format === 'team') {
            $calc = new RatingCalculator('team', $status, $d, $guaranteed);
            foreach ($data['teams'] ?? [] as $tm) {
                $rank = (int)($tm['rank'] ?? 0);
                if ($rank < 1) continue;
                $players = $tm['players'] ?? [];
                // only counting players for q (not non_counting)
                $qs = [];
                $plist = [];
                foreach ($players as $pl) {
                    $q = RatingCalculator::qFromRazr($pl['razr'] ?? null);
                    $qs[] = $q;
                    $plist[] = [
                        'name' => $pl['name'] ?? '',
                        'player_id' => $pl['player_id'] ?? null,
                        'razr' => $pl['razr'] ?? null,
                        'q' => $q,
                        'db_fio' => $pl['db_fio'] ?? null,
                    ];
                }
                if (!$qs) continue;
                $qAvg = array_sum($qs) / count($qs);
                $label = $tm['team'] ?? ('Команда ' . $rank);
                $calc->addEntry($rank, $qAvg, $label, $plist);
            }
        } elseif ($format === 'individual') {
            $pairs = $data['sum']['pairs'] ?? [];
            foreach ($pairs as $pair) {
                $rank = (int)($pair['rank'] ?? 0);
                if ($rank < 1) continue;
                $p1 = $pair['player1'] ?? [];
                if (!$p1 && !empty($pair['name'])) {
                    $p1 = $pair;
                }
                $q1 = RatingCalculator::qFromRazr($p1['razr'] ?? null);
                $label = trim((string)($p1['name'] ?? ''));
                $calc->addEntry($rank, $q1, $label, [
                    ['name' => $p1['name'] ?? '', 'player_id' => $p1['player_id'] ?? null, 'razr' => $p1['razr'] ?? null, 'q' => $q1, 'db_fio' => $p1['db_fio'] ?? null],
                ]);
            }
        } else {
            // pair: from sum.pairs
            $pairs = $data['sum']['pairs'] ?? [];
            foreach ($pairs as $pair) {
                $rank = (int)($pair['rank'] ?? 0);
                if ($rank < 1) continue;
                $p1 = $pair['player1'] ?? [];
                $p2 = $pair['player2'] ?? [];
                $q1 = RatingCalculator::qFromRazr($p1['razr'] ?? null);
                $q2 = RatingCalculator::qFromRazr($p2['razr'] ?? null);
                $qAvg = ($q1 + $q2) / 2.0;
                $label = trim(($p1['name'] ?? '') . ' — ' . ($p2['name'] ?? ''));
                $calc->addEntry($rank, $qAvg, $label, [
                    ['name' => $p1['name'] ?? '', 'player_id' => $p1['player_id'] ?? null, 'razr' => $p1['razr'] ?? null, 'q' => $q1, 'db_fio' => $p1['db_fio'] ?? null],
                    ['name' => $p2['name'] ?? '', 'player_id' => $p2['player_id'] ?? null, 'razr' => $p2['razr'] ?? null, 'q' => $q2, 'db_fio' => $p2['db_fio'] ?? null],
                ]);
            }
        }

        $out = $calc->compute();
        $out['meta'] = $meta;
        // подставить Result (VP/IMP) из исходного JSON по порядку
        $scores = [];
        if (($out['params']['format'] ?? '') === 'team') {
            foreach ($data['teams'] ?? [] as $tm) {
                if (!isset($tm['rank']) || $tm['rank'] === null) continue;
                $scores[] = isset($tm['result']) && is_numeric($tm['result']) ? (0 + $tm['result']) : null;
            }
            // non_counting attach to results by team name
            $ncByTeam = [];
            foreach ($data['teams'] ?? [] as $tm) {
                $ncByTeam[normalize_team($tm['team'] ?? '')] = $tm['non_counting'] ?? [];
            }
            foreach ($out['results'] as &$rr) {
                $key = normalize_team($rr['label'] ?? '');
                if (isset($ncByTeam[$key])) {
                    $rr['non_counting'] = $ncByTeam[$key];
                }
            }
            unset($rr);
        } else {
            foreach ($data['sum']['pairs'] ?? [] as $pair) {
                if (!isset($pair['rank']) || $pair['rank'] === null) continue;
                $scores[] = isset($pair['result']) && is_numeric($pair['result']) ? (0 + $pair['result']) : null;
            }
        }
        $scoreOpts = [];
        foreach ($out['results'] as $i => $r) {
            if (isset($scores[$i]) && is_numeric($scores[$i])) {
                $scoreOpts[$i] = 0 + $scores[$i];
            } else {
                $scoreOpts[$i] = null;
            }
        }
        $out['scores_opts'] = $scoreOpts;

        // МБ за сессии / этапы (Сессия, Финал, Отбор…)
        $sessionResults = [];
        $sessList = $data['sessions'] ?? [];
        $GLOBALS['had_sessions_in'] = !empty($sessList);
        $metaD = null;
        if (isset($meta['length_actual']) && is_numeric($meta['length_actual'])) {
            $metaD = (float)$meta['length_actual'];
        } elseif (isset($meta['length']) && is_numeric($meta['length'])) {
            $metaD = (float)$meta['length'];
        }
        $nSess = max(1, count($sessList));
        foreach ($sessList as $sess) {
            $sessName = $sess['name'] ?? 'Сессия';
            $sessD = null;
            if (isset($sess['boards']) && is_numeric($sess['boards']) && (float)$sess['boards'] > 0) {
                $sessD = (float)$sess['boards'];
            } elseif ($metaD !== null && $metaD > 0) {
                // оценка: делим длину турнира на число этапов
                $sessD = max(1.0, round($metaD / $nSess, 1));
            }
            if ($sessD === null || $sessD <= 0) {
                $sessionResults[] = [
                    'name' => $sessName,
                    'boards' => null,
                    'error' => 'Не указана длина сессии (сдачи). Укажите boards в JSON или length турнира.',
                    'results' => [],
                ];
                continue;
            }
            $sc = new RatingCalculator($format, $status, $sessD, 'none');
            $pairs = $sess['pairs'] ?? [];
            foreach ($pairs as $pair) {
                $rank = (int)($pair['rank'] ?? 0);
                if ($rank < 1) continue;
                $p1 = $pair['player1'] ?? [];
                $p2 = $pair['player2'] ?? [];
                // fallback flat structure from check JSON
                if (!$p1 && isset($pair['name1'])) {
                    $p1 = ['name' => $pair['name1'] ?? '', 'player_id' => $pair['id1'] ?? null, 'razr' => $pair['razr1'] ?? null];
                    $p2 = ['name' => $pair['name2'] ?? '', 'player_id' => $pair['id2'] ?? null, 'razr' => $pair['razr2'] ?? null];
                }
                $q1 = RatingCalculator::qFromRazr($p1['razr'] ?? null);
                if ($format === 'individual') {
                    $label = trim((string)($p1['name'] ?? ''));
                    $sc->addEntry($rank, $q1, $label, [
                        ['name' => $p1['name'] ?? '', 'player_id' => $p1['player_id'] ?? ($p1['id'] ?? null), 'razr' => $p1['razr'] ?? null, 'q' => $q1],
                    ]);
                } else {
                    $q2 = RatingCalculator::qFromRazr($p2['razr'] ?? null);
                    $qAvg = ($q1 + $q2) / 2.0;
                    $label = trim(($p1['name'] ?? '') . ' — ' . ($p2['name'] ?? ''));
                    $sc->addEntry($rank, $qAvg, $label, [
                        ['name' => $p1['name'] ?? '', 'player_id' => $p1['player_id'] ?? ($p1['id'] ?? null), 'razr' => $p1['razr'] ?? null, 'q' => $q1],
                        ['name' => $p2['name'] ?? '', 'player_id' => $p2['player_id'] ?? ($p2['id'] ?? null), 'razr' => $p2['razr'] ?? null, 'q' => $q2],
                    ]);
                }
            }
            if (count($sc->entries) < 1) {
                continue;
            }
            try {
                $sessOut = $sc->computeSessionMb();
                // подставить Result из протокола
                $scoreByRank = [];
                foreach ($pairs as $pair) {
                    $rk = (int)($pair['rank'] ?? 0);
                    if ($rk && isset($pair['result']) && is_numeric($pair['result'])) {
                        $scoreByRank[$rk] = 0 + $pair['result'];
                    }
                }
                foreach ($sessOut['results'] as &$sr0) {
                    $rk = (int)$sr0['rank'];
                    if (isset($scoreByRank[$rk])) {
                        $sr0['result'] = $scoreByRank[$rk];
                    }
                }
                unset($sr0);
                $sessionResults[] = [
                    'name' => $sessName,
                    'boards' => $sessD,
                    'params' => $sessOut['params'],
                    'results' => $sessOut['results'],
                ];
            } catch (Throwable $ex) {
                $sessionResults[] = [
                    'name' => $sessName,
                    'boards' => $sessD,
                    'error' => $ex->getMessage(),
                    'results' => [],
                ];
            }
        }
        $out['sessions'] = $sessionResults;

        // Для каждого игрока: max(МБ турнира, сумма МБ сессий) — пометка
        $playerTournMb = [];
        $playerSessMb = [];
        foreach ($out['results'] as $r) {
            foreach ($r['players'] as $pl) {
                $pid = $pl['player_id'] ?? null;
                if ($pid === null) continue;
                $playerTournMb[(int)$pid] = (int)($r['MB'] ?? 0);
            }
        }
        foreach ($sessionResults as $sr) {
            foreach ($sr['results'] ?? [] as $r) {
                foreach ($r['players'] as $pl) {
                    $pid = $pl['player_id'] ?? null;
                    if ($pid === null) continue;
                    $pid = (int)$pid;
                    $playerSessMb[$pid] = ($playerSessMb[$pid] ?? 0) + (int)($r['MB'] ?? 0);
                }
            }
        }
        $out['mb_choice'] = [];
        $allPids = array_unique(array_merge(array_keys($playerTournMb), array_keys($playerSessMb)));
        foreach ($allPids as $pid) {
            $tmb = $playerTournMb[$pid] ?? 0;
            $smb = $playerSessMb[$pid] ?? 0;
            $out['mb_choice'][] = [
                'player_id' => $pid,
                'mb_tournament' => $tmb,
                'mb_sessions_sum' => $smb,
                'mb_best' => max($tmb, $smb),
                'prefer' => $smb > $tmb ? 'sessions' : 'tournament',
            ];
        }

        unset($_SESSION['club_mb_report'], $_SESSION['club_mb_sql']);
        $_SESSION['rating_out'] = $out;
        $_SESSION['rating_format'] = $format;
        $_SESSION['rating_status'] = $status;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$statusLabels = [
    'express' => 'Экспресс',
    'rating' => 'Рейтинговый',
    'regional' => 'Региональный чемпионат',
    'russian' => 'Чемпионат России',
    'main_russian' => 'Основной чемпионат России',
];
$formatLabels = [
    'pair' => 'Парный',
    'team' => 'Командный',
    'individual' => 'Индивидуальный',
];
?>

<style>
.card{background:var(--card);border-radius:14px;padding:24px;max-width:640px;margin:0 auto 24px}
label{display:block;margin:12px 0 4px;font-size:.9rem;color:var(--muted)}
select,input[type=number],textarea,input[type=file]{width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)}
textarea{min-height:120px;font-family:ui-monospace,monospace;font-size:.85rem}
.btn{display:block;width:100%;margin-top:16px;background:var(--accent);color:#fff;border:0;padding:12px;border-radius:8px;font-size:1rem;cursor:pointer}
.flash{background:rgba(239,68,68,.15);color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:16px}
.params{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:16px 0}
.param{background:var(--card);border-radius:10px;padding:12px;text-align:center}
.param .v{font-size:1.2rem;font-weight:700}
.param .l{font-size:.72rem;color:var(--muted);margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:.9rem;background:var(--card);border-radius:12px;overflow:hidden}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #2a3548}
th{color:var(--muted);font-size:.72rem;text-transform:uppercase}
a{color:var(--accent)}
.note{font-size:.85rem;color:var(--muted);margin-top:12px;line-height:1.45}
h1{font-size:1.35rem;margin:0 0 8px}
.sub{color:var(--muted);margin-bottom:20px;line-height:1.4}
.wrap{max-width:1100px;margin:0 auto}
</style>

<div class="wrap">
<?php if ($out === null): ?>
  <div class="card">
    <h1>Расчёт РО, ПБ и МБ</h1>
    <p class="sub">Загрузите JSON из проверки отчёта турнира. Формулы — по <a href="https://www.bridgesport.ru/materials/sports-classification/" target="_blank" rel="noopener">спортивной классификации ФСБР</a>.</p>
    <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="tab" value="rating">
      <label>JSON-отчёт</label>
      <input type="file" name="json" accept=".json,application/json">
      <label>или вставьте JSON</label>
      <textarea name="json_text" placeholder='{"format":"pair","meta":{...},"sum":{"pairs":[...]}}'><?= h($GLOBALS["prefill_json"] ?? ($_POST["json_text"] ?? "")) ?></textarea>

      <?php
        $suggestTitle = '';
        $fmtFromReport = 'pair';
        $tmp = null;
        if (!empty($_POST['json_text'])) {
          $tmp = json_decode((string)$_POST['json_text'], true);
        } elseif (!empty($GLOBALS['prefill_json'])) {
          $tmp = json_decode((string)$GLOBALS['prefill_json'], true);
        }
        if (is_array($tmp)) {
          $suggestTitle = (string)($tmp['meta']['title'] ?? '');
          if (!empty($tmp['format']) && in_array($tmp['format'], ['pair','team','individual'], true)) {
            $fmtFromReport = $tmp['format'];
          } elseif (!empty($tmp['teams'])) {
            $fmtFromReport = 'team';
          }
        }
        $fmtGuess = $fmtFromReport;
      ?>
      <label>Формат турнира</label>
      <select name="format">
        <?php foreach ($formatLabels as $k=>$v): ?>
        <option value="<?= h($k) ?>" <?= $k===$fmtFromReport?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="note">Формат берётся из типа отчёта (<?= h($fmtFromReport) ?>); при расчёте приоритет у JSON.</p>

      <?php
        $stGuess = $_POST['status'] ?? 'rating';
        $gSug = TournamentSuggest::suggestGuaranteed($suggestTitle, $fmtGuess, $stGuess);
        $sSug = TournamentSuggest::suggestStatus($suggestTitle, $stGuess);
        $defaultG = $_POST['guaranteed'] ?? $gSug['key'];
        $defaultS = $_POST['status'] ?? $sSug['status'];
      ?>
      <label>Статус</label>
      <select name="status">
        <?php foreach ($statusLabels as $k=>$v): ?>
        <option value="<?= h($k) ?>" <?= $k===$defaultS?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($suggestTitle !== ''): ?>
      <p class="note">Подсказка статуса: <b><?= h($sSug['status']) ?></b> — <?= h($sSug['reason']) ?></p>
      <?php endif; ?>

      <label>Гарантированные ПБ (Прил. 1)</label>
      <select name="guaranteed">
        <?php foreach (RatingCalculator::GUARANTEED_PB_LABELS as $k=>$v): ?>
        <option value="<?= h($k) ?>" <?= ($k===$defaultG)?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($suggestTitle !== ''): ?>
      <p class="note">Подсказка ПБ: <b><?= h($gSug['label']) ?></b> (<?= h($gSug['confidence']) ?>) — <?= h($gSug['reason']) ?></p>
      <?php endif; ?>

      <?php
        $defaultD = $_POST['length'] ?? '';
        if ($defaultD === '' && is_array($tmp ?? null)) {
          if (isset($tmp['meta']['length_actual']) && $tmp['meta']['length_actual'] !== '' && $tmp['meta']['length_actual'] !== null) {
            $defaultD = (string)(0 + $tmp['meta']['length_actual']);
          } elseif (isset($tmp['meta']['length']) && $tmp['meta']['length'] !== '' && $tmp['meta']['length'] !== null) {
            $defaultD = (string)(0 + $tmp['meta']['length']);
          }
        }
      ?>
      <label>Фактическая длина d (сдач)</label>
      <input type="number" name="length" step="1" min="1" value="<?= h((string)$defaultD) ?>" placeholder="из отчёта: meta.length_actual">
      <?php if ($defaultD !== ''): ?>
      <p class="note">Подставлено из отчёта: <b><?= h((string)$defaultD) ?></b> (фактическая длина / длина турнира)</p>
      <?php endif; ?>

      <button class="btn" type="submit">Рассчитать</button>
    </form>
    <p class="note">
      q = razr/2; если razr ≥ 95 то (razr − 100)/2 (нет в базе → 5). Разряд пары/команды — среднее q игроков.<br>
      N0 — число участников с q ≤ 0. К RC: +1 (ЧР / основной ЧР), +0.5 (РЧ).<br>
      После расчёта — вкладка «SQL» (champ_t, tourn_id). Гарантированные ПБ (Прил. 1) пока не учитываются.
    </p>
  </div>
<?php else: ?>
  <p><a href="?tab=rating">← Новый расчёт</a>
    · <button type="button" id="btn-json" style="background:var(--accent);color:#fff;border:0;padding:6px 12px;border-radius:6px;cursor:pointer">Скачать JSON</button>
   
  </p>
  <h1>Результат расчёта</h1>
  <p class="sub">
    <?= h($meta['title'] ?? '') ?>
    <?php if (!empty($meta['date_from'])): ?> · <?= h($meta['date_from']) ?><?php if (!empty($meta['date_to'])): ?> — <?= h($meta['date_to']) ?><?php endif; ?><?php endif; ?>
  </p>

  <?php $p = $out['params']; ?>
  <div class="params">
    <div class="param"><div class="v"><?= h((string)$p['N']) ?></div><div class="l">N участников</div></div>
    <div class="param"><div class="v"><?= h((string)$p['d']) ?></div><div class="l">d сдач</div></div>
    <div class="param"><div class="v"><?= h((string)$p['kq']) ?></div><div class="l">kq</div></div>
    <div class="param"><div class="v"><?= h((string)$p['N0']) ?></div><div class="l">N0 (q≤0)</div></div>
    <div class="param"><div class="v"><?= h((string)$p['RC']) ?></div><div class="l">RC</div></div>
    <div class="param"><div class="v"><?= h((string)$p['RCM']) ?></div><div class="l">RCM</div></div>
    <div class="param"><div class="v"><?= h((string)$p['pR']) ?></div><div class="l">pR</div></div>
    <div class="param"><div class="v"><?= h((string)$p['PB1']) ?></div><div class="l">ПБ за 1 место</div></div>
    <?php if (!empty($p['guaranteed']) && $p['guaranteed'] !== 'none'): ?>
    <div class="param"><div class="v"><?= h((string)($p['guaranteed_pb1'] ?? '')) ?></div><div class="l">гарант. ПБ1</div></div>
    <?php endif; ?>
  </div>

  <?php if (empty($out['sessions'])): ?>
  <p class="note" style="margin:12px 0">В JSON нет сессий/этапов — МБ по сессиям не считались. На шаге проверки должны быть листы «Сессия», «Финал», «Отбор» и т.п.</p>
  <?php endif; ?>

  <table>
    <tr>
      <th>Место</th>
      <th>Участник</th>
      <th>q</th>
      <th>q2</th>
      <th>РО</th>
      <th>ПБ</th>
      <th>МБ</th>
      <th>Игроки (q)</th>
    </tr>
    <?php foreach ($out['results'] as $r): ?>
    <tr>
      <td><?= (int)$r['rank'] ?></td>
      <td><?= h($r['label']) ?></td>
      <td><?= h((string)$r['q']) ?></td>
      <td><?= h((string)($r['q2'] ?? '')) ?></td>
      <td><b><?= (int)$r['RO'] ?></b></td>
      <td><b><?= (int)$r['PB'] ?></b></td>
      <td><?= (int)$r['MB'] ?></td>
      <td><?php
        $bits = [];
        foreach ($r['players'] as $pl) {
          $bits[] = h($pl['name'] ?? '') . ' (' . h((string)($pl['q'] ?? '')) . ')';
        }
        echo implode(', ', $bits);
      ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if (!empty($out['sessions'])): ?>
  <h2 style="margin-top:32px;font-size:1.15rem">Результаты сессий / этапов</h2>
  <?php foreach ($out['sessions'] as $sr): ?>
  <section style="background:var(--card);border-radius:12px;padding:18px;margin:16px 0">
    <h3 style="margin:0 0 10px;font-size:1.05rem"><?= h($sr['name'] ?? 'Этап') ?>
      <?php if (!empty($sr['boards'])): ?> · d=<?= h((string)$sr['boards']) ?><?php endif; ?>
    </h3>
    <?php if (!empty($sr['error'])): ?>
      <p class="flash"><?= h($sr['error']) ?></p>
    <?php else: ?>
      <?php $sp = $sr['params'] ?? []; ?>
      <div class="params">
        <div class="param"><div class="v"><?= h((string)($sp['N'] ?? '')) ?></div><div class="l">N</div></div>
        <div class="param"><div class="v"><?= h((string)($sp['kq'] ?? '')) ?></div><div class="l">kq</div></div>
        <div class="param"><div class="v"><?= h((string)($sp['kd'] ?? '')) ?></div><div class="l">kd (сессия)</div></div>
        <div class="param"><div class="v"><?= h((string)($sp['kqn'] ?? '')) ?></div><div class="l">kqn</div></div>
      </div>
      <table>
        <tr><th>Место</th><th>Пара</th><th>Результат</th><th>МБ</th><th>Игроки</th></tr>
        <?php foreach ($sr['results'] ?? [] as $r): ?>
        <tr>
          <td><?= (int)$r['rank'] ?></td>
          <td><?= h($r['label'] ?? '') ?></td>
          <td><?= isset($r['result']) ? h((string)$r['result']) : '—' ?></td>
          <td><b><?= (int)($r['MB'] ?? 0) ?></b></td>
          <td><?php
            $bits = [];
            foreach ($r['players'] ?? [] as $pl) {
              $bits[] = h($pl['name'] ?? '') . (isset($pl['player_id']) ? ' ('.$pl['player_id'].')' : '');
            }
            echo implode(', ', $bits);
          ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <p class="note">МБ за сессии и за турнир не суммируются — по классификации берётся более выгодный вариант.</p>
  <?php endif; ?>

  <?php if (!empty($out['mb_choice'])): ?>
  <h2 style="margin-top:28px;font-size:1.1rem">МБ: турнир vs сумма сессий</h2>
  <table>
    <tr><th>ID</th><th>МБ турнир</th><th>Σ МБ сессий</th><th>К начислению</th><th>Вариант</th></tr>
    <?php foreach ($out['mb_choice'] as $mc): ?>
    <tr>
      <td><?= (int)$mc['player_id'] ?></td>
      <td><?= (int)$mc['mb_tournament'] ?></td>
      <td><?= (int)$mc['mb_sessions_sum'] ?></td>
      <td><b><?= (int)$mc['mb_best'] ?></b></td>
      <td><?= ($mc['prefer'] ?? '') === 'sessions' ? 'сессии' : 'турнир' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <section style="background:var(--card);border-radius:12px;padding:18px;margin-top:20px">
    <h2 style="margin:0 0 10px;font-size:1.05rem">Дальше: SQL</h2>
    <p class="sub">На следующем шаге укажите champ_t и при необходимости tourn_id, city_id.</p>
    <a class="btn" style="display:inline-block;width:auto;padding:10px 18px;text-decoration:none;background:#22c55e" href="?tab=sql">Перейти к подготовке SQL →</a>
  </section>

  <script>
  document.getElementById('btn-json').onclick = function() {
    var data = <?= json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;
    var blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json;charset=utf-8'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'rating_result.json';
    a.click();
  };
  </script>
<?php endif; ?>
</div>

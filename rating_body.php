<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** q игрока: предпочитает готовый q, иначе razr+razr_coeff */
function q_from_player_fields(array $pl): float
{
    if (isset($pl['q']) && $pl['q'] !== null && $pl['q'] !== '' && is_numeric($pl['q'])) {
        return (float)$pl['q'];
    }
    return RatingCalculator::qFromRazr($pl['razr'] ?? null, $pl['razr_coeff'] ?? null);
}

require_once __DIR__ . '/RatingCalculator.php';
require_once __DIR__ . '/TournamentSuggest.php';
require_once __DIR__ . '/SqlExporter.php';

/** Строка игрока для таблицы результатов (единый вид для основного расчёта и сессий). */
function rb_player_row(array $pl, float $q): array
{
    return [
        'name' => $pl['name'] ?? '',
        'player_id' => $pl['player_id'] ?? ($pl['id'] ?? null),
        'razr' => $pl['razr'] ?? null,
        'q' => $q,
        'db_fio' => $pl['db_fio'] ?? null,
    ];
}

/**
 * Добавить в калькулятор одну запись пары/индивидуала (формат check-JSON: player1/player2 или плоский name1/id1/razr1).
 * Используется и для основного расчёта, и для расчёта сессий.
 */
function rb_add_pair_entry(RatingCalculator $calc, array $pair, string $format): void
{
    $rank = (int)($pair['rank'] ?? 0);
    if ($rank < 1) {
        return;
    }
    $p1 = $pair['player1'] ?? [];
    $p2 = $pair['player2'] ?? [];
    if (!$p1 && isset($pair['name1'])) {
        $p1 = ['name' => $pair['name1'] ?? '', 'player_id' => $pair['id1'] ?? null, 'razr' => $pair['razr1'] ?? null];
        $p2 = ['name' => $pair['name2'] ?? '', 'player_id' => $pair['id2'] ?? null, 'razr' => $pair['razr2'] ?? null];
    } elseif (!$p1 && $format === 'individual' && !empty($pair['name'])) {
        $p1 = $pair;
    }
    $res = (isset($pair['result']) && is_numeric($pair['result'])) ? (0 + $pair['result']) : null;
    $q1 = q_from_player_fields($p1);
    if ($format === 'individual') {
        $calc->addEntry($rank, $q1, trim((string)($p1['name'] ?? '')), [rb_player_row($p1, $q1)], $res);
        return;
    }
    $q2 = q_from_player_fields($p2);
    $label = trim(($p1['name'] ?? '') . ' — ' . ($p2['name'] ?? ''));
    $calc->addEntry($rank, ($q1 + $q2) / 2.0, $label, [rb_player_row($p1, $q1), rb_player_row($p2, $q2)], $res);
}


function normalize_team(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return $s;
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
        if (!in_array($status, ['non_rating', 'express', 'rating', 'regional', 'russian', 'main_russian'], true)) {
            throw new RuntimeException('Неверный статус турнира');
        }
        $guaranteed = $_POST['guaranteed'] ?? 'none';
        if (!array_key_exists($guaranteed, RatingCalculator::GUARANTEED_PB)) {
            $guaranteed = 'none';
        }

        $jsonText = '';
        if (($upErr = upload_error_message($_FILES['json'] ?? null)) !== null) {
            throw new RuntimeException($upErr);
        }
        if (!empty($_FILES['json']['tmp_name'])) {
            if (filesize($_FILES['json']['tmp_name']) > 10 * 1024 * 1024) {
                throw new RuntimeException('JSON-файл больше 10 МБ');
            }
            $jsonText = file_get_contents($_FILES['json']['tmp_name']);
        } elseif (!empty($_POST['json_text'])) {
            $jsonText = $_POST['json_text'];
        } elseif (!empty($_SESSION['report_json'])) {
            $jsonText = (string)$_SESSION['report_json'];
        }
        if ($jsonText === '' || $jsonText === false) {
            throw new RuntimeException('Загрузите JSON-отчёт или сначала выполните проверку');
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
        }
        // meta.length (плановая) в d не используем
        if ($d === null || $d <= 0) {
            throw new RuntimeException('Не задана фактическая длина турнира (d). Укажите вручную или meta.length_actual');
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
            foreach ($data['teams'] ?? [] as $tm) {
                $rank = (int)($tm['rank'] ?? 0);
                if ($rank < 1) continue;
                $players = $tm['players'] ?? [];
                // only counting players for q (not non_counting)
                $qs = [];
                $plist = [];
                foreach ($players as $pl) {
                    $q = q_from_player_fields($pl);
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
                $res = (isset($tm['result']) && is_numeric($tm['result'])) ? (0 + $tm['result']) : null;
                $calc->addEntry($rank, $qAvg, $label, $plist, $res);
            }
        } else {
            // pair / individual: from sum.pairs
            foreach ($data['sum']['pairs'] ?? [] as $pair) {
                rb_add_pair_entry($calc, $pair, $format);
            }
        }

        $out = $calc->compute();
        $out['meta'] = $meta;
        // Result (VP/IMP) уже в каждой записи results[] через addEntry
        if (($out['params']['format'] ?? '') === 'team') {
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
        }
        $scoreOpts = [];
        foreach ($out['results'] as $i => $r) {
            $scoreOpts[$i] = isset($r['result']) && is_numeric($r['result']) ? (0 + $r['result']) : null;
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
            $sessEstimated = false;
            if (isset($sess['boards']) && is_numeric($sess['boards']) && (float)$sess['boards'] > 0) {
                $sessD = (float)$sess['boards'];
            } elseif ($metaD !== null && $metaD > 0) {
                // оценка: делим длину турнира на число этапов
                $sessD = max(1.0, round($metaD / $nSess, 1));
                $sessEstimated = true;
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
            if ($sessD < 14) {
                $sessionResults[] = [
                    'name' => $sessName,
                    'boards' => $sessD,
                    'skipped' => true,
                    'error' => 'МБ сессии не считаются: длина ' . $sessD . ' < 14 сдач',
                    'results' => [],
                ];
                continue;
            }
            $sc = new RatingCalculator($format, $status, $sessD, 'none');
            $pairs = $sess['pairs'] ?? [];
            foreach ($pairs as $pair) {
                rb_add_pair_entry($sc, $pair, $format);
            }
            if (count($sc->entries) < 1) {
                continue;
            }
            try {
                $sessOut = $sc->computeSessionMb();
                // Result уже в results[] из addEntry
                $sessionResults[] = [
                    'name' => $sessName,
                    'boards' => $sessD,
                    'estimated' => $sessEstimated,
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
    'non_rating' => 'Не рейтинговый (только МБ)',
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
.okbox{background:rgba(34,197,94,.12);color:#86efac;padding:12px;border-radius:8px}
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
    <?php
      $prefillJson = $GLOBALS['prefill_json'] ?? '';
      if ($prefillJson === '' && !empty($_SESSION['report_json'])) {
          $prefillJson = (string)$_SESSION['report_json'];
      }
      if ($prefillJson === '' && !empty($_POST['json_text'])) {
          $prefillJson = (string)$_POST['json_text'];
      }
      $hasPrefill = is_string($prefillJson) && trim($prefillJson) !== '';
    ?>
    <p class="sub"><?php if ($hasPrefill): ?>
      Отчёт уже передан с шага проверки. Укажите параметры и нажмите «Рассчитать».
    <?php else: ?>
      Загрузите JSON из проверки отчёта турнира.
    <?php endif; ?>
      Формулы — по <a href="https://www.bridgesport.ru/materials/sports-classification/" target="_blank" rel="noopener">спортивной классификации ФСБР</a>.</p>
    <?php if ($error): ?><div class="flash"><?= h($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="tab" value="rating">
      <?php if ($hasPrefill): ?>
      <input type="hidden" name="from_session" value="1">
      <?php
        $preTmp = json_decode($prefillJson, true);
        $preTitle = is_array($preTmp) ? (string)($preTmp['meta']['title'] ?? '') : '';
        $preFmt = is_array($preTmp) ? (string)($preTmp['format'] ?? '') : '';
        $preN = 0;
        if (is_array($preTmp)) {
          if (!empty($preTmp['sum']['pairs'])) $preN = count($preTmp['sum']['pairs']);
          elseif (!empty($preTmp['teams'])) $preN = count($preTmp['teams']);
          elseif (!empty($preTmp['results'])) $preN = count($preTmp['results']);
        }
      ?>
      <div class="okbox" style="margin-bottom:14px">
        Отчёт загружен с проверки
        <?php if ($preTitle !== ''): ?> — <b><?= h($preTitle) ?></b><?php endif; ?>
        <?php if ($preFmt !== ''): ?> · <?= h($preFmt) ?><?php endif; ?>
        <?php if ($preN): ?> · участников: <?= (int)$preN ?><?php endif; ?>
        
      </div>
      <?php else: ?>
      <label>JSON-отчёт</label>
      <input type="file" name="json" accept=".json,application/json">
      <label>или вставьте JSON</label>
      <textarea name="json_text" placeholder='{"format":"pair","meta":{...},"sum":{"pairs":[...]}}'></textarea>
      <?php endif; ?>

      <?php
        $suggestTitle = '';
        $fmtFromReport = 'pair';
        $tmp = null;
        if (!empty($GLOBALS['prefill_json'])) {
          $tmp = json_decode((string)$GLOBALS['prefill_json'], true);
        } elseif (!empty($_SESSION['report_json'])) {
          $tmp = json_decode((string)$_SESSION['report_json'], true);
        } elseif (!empty($_POST['json_text'])) {
          $tmp = json_decode((string)$_POST['json_text'], true);
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
      <?php
        // фактическая длина — для подсказки статуса и расчёта
        $defaultD = $_POST['length'] ?? '';
        if ($defaultD === '' && is_array($tmp ?? null)) {
          if (isset($tmp['meta']['length_actual']) && $tmp['meta']['length_actual'] !== '' && $tmp['meta']['length_actual'] !== null) {
            $defaultD = (string)(0 + $tmp['meta']['length_actual']);
          }
          // плановую length в d не подставляем автоматически
        }
        $dForStatus = ($defaultD !== '' && is_numeric($defaultD)) ? (float)$defaultD : null;
        $stGuess = $_POST['status'] ?? 'rating';
        $sSug = TournamentSuggest::suggestStatus($suggestTitle, 'rating', $dForStatus, $fmtGuess);
        $gSug = TournamentSuggest::suggestGuaranteed($suggestTitle, $fmtGuess, $sSug['status']);
        $defaultG = $_POST['guaranteed'] ?? $gSug['key'];
        $defaultS = $_POST['status'] ?? $sSug['status'];
      ?>
      <label>Фактическая длина d (сдач)</label>
      <input type="number" name="length" step="1" min="1" value="<?= h((string)$defaultD) ?>" placeholder="meta.length_actual">

      <label>Статус</label>
      <select name="status">
        <?php foreach ($statusLabels as $k=>$v): ?>
        <option value="<?= h($k) ?>" <?= $k===$defaultS?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Гарантированные ПБ</label>
      <select name="guaranteed">
        <?php foreach (RatingCalculator::GUARANTEED_PB_LABELS as $k=>$v): ?>
        <option value="<?= h($k) ?>" <?= ($k===$defaultG)?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>

      <button class="btn" type="submit">Рассчитать</button>
    </form>
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

  <?php $p = $out['params']; foreach (($p['warnings'] ?? []) as $w): ?>
  <p class="flash"><?= h($w) ?></p>
  <?php endforeach; ?>
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
    <?php if (!empty($sr['estimated'])): ?>
      <p class="flash">Длина сессии не указана в файле — взята оценка: длина турнира ÷ число сессий = <?= h((string)$sr['boards']) ?> сдач.
        Если сессии разной длины, МБ будут посчитаны неверно: укажите <code>boards</code> для каждой сессии.</p>
    <?php endif; ?>
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
              $bits[] = h($pl['name'] ?? '') . (isset($pl['player_id']) ? ' ('.h($pl['player_id']).')' : '');
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
        <a class="btn" style="display:inline-block;width:auto;padding:10px 18px;text-decoration:none;background:#22c55e" href="?tab=sql">Перейти к подготовке SQL →</a>
  </section>

  <script>
  document.getElementById('btn-json').onclick = function() {
    var data = <?= json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json;charset=utf-8'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'rating_result.json';
    a.click();
  };
  </script>
<?php endif; ?>
</div>

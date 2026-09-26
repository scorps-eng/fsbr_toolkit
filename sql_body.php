<?php
declare(strict_types=1);
require_once __DIR__ . '/SqlExporter.php';

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$error = null;
$sqlText = null;
$ratingOut = $_SESSION['rating_out'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'sql') {
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
  <p class="sub">Вставка в tourn_header + tourn_pair/tourn_team. Таблица results не трогаем (обновляется скриптами).</p>

  <?php if (empty($ratingOut) || empty($ratingOut['results'])): ?>
    <div class="flash">Нет данных расчёта. Сначала вкладка «Расчёт РО / ПБ / МБ».</div>
    <p><a href="?tab=rating" style="color:var(--accent)">← К расчёту</a></p>
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
    ?>
    <form method="post">
      <input type="hidden" name="tab" value="sql">

      <label>Название турнира</label>
      <input type="text" name="tourn_name" value="<?= h((string)$defName) ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">

      <label>Дата начала (YYYY-MM-DD)</label>
      <input type="text" name="date_from" value="<?= h((string)$defFrom) ?>" placeholder="2026-09-01" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">

      <label>Дата окончания (YYYY-MM-DD)</label>
      <input type="text" name="date_to" value="<?= h((string)$defTo) ?>" placeholder="2026-09-02" style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:var(--text)">

      <label>champ_t (опционально, часто NULL)</label>
      <input type="number" name="champ_t" value="<?= h($_POST['champ_t'] ?? '') ?>" placeholder="NULL">

      <label>tourn_id (если указать — удалятся старые данные этого турнира и вставится заново)</label>
      <input type="number" name="tourn_id" min="1" value="<?= h($_POST['tourn_id'] ?? '') ?>" placeholder="новый ID автоматически">

      <label>city_id (опционально)</label>
      <input type="number" name="city_id" value="<?= h($_POST['city_id'] ?? '') ?>" placeholder="NULL">

      <label>status в БД (опционально)</label>
      <input type="number" name="status_db" value="<?= h($_POST['status_db'] ?? '') ?>" placeholder="NULL">

      <label>stream (опционально)</label>
      <input type="number" name="stream" value="<?= h($_POST['stream'] ?? '') ?>" placeholder="NULL">

      <button class="btn" type="submit">Сформировать SQL</button>
    </form>

    <?php if ($sqlText): ?>
    <h2 style="margin-top:24px;font-size:1.05rem">SQL</h2>
    <textarea id="sql-out" readonly style="min-height:320px;font-family:ui-monospace,monospace;font-size:.8rem"><?= h($sqlText) ?></textarea>
    <button type="button" class="btn" id="btn-sql" style="background:#22c55e">Скачать .sql</button>
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

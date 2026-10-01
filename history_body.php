<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ImportHistory.php';
$log = ImportHistory::all(300);
?>
<style>
.card{background:var(--card);border-radius:14px;padding:22px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{padding:8px 10px;border-bottom:1px solid #2a3548;text-align:left}
th{color:var(--muted);font-weight:600}
.badge-ok{color:#86efac}
.badge-err{color:#fca5a5}
.note{color:var(--muted);font-size:.85rem}
</style>
<div class="card">
  <h2 style="margin:0 0 8px;font-size:1.15rem">История загрузок</h2>
  <p class="note">Записи о выполнении SQL: обычные турниры и клубные МБ. Хранится локально в <code>data/import_log.json.php</code>.</p>
  <?php if (!$log): ?>
  <p class="note">Пока пусто — выполните SQL на вкладке «SQL» или «Клубные МБ».</p>
  <?php else: ?>
  <table>
    <tr>
      <th>Время</th>
      <th>Тип</th>
      <th>tourn_id</th>
      <th>Название</th>
      <th>Дата</th>
      <th>Город</th>
      <th>Статус</th>
    </tr>
    <?php foreach ($log as $row): ?>
    <tr>
      <td><?php
        $ts = $row['ts'] ?? '';
        echo h($ts !== '' ? date('Y-m-d H:i', strtotime($ts) ?: time()) : '—');
      ?></td>
      <td><?= h($row['type_label'] ?? $row['kind'] ?? '') ?></td>
      <td><?= isset($row['tourn_id']) && $row['tourn_id'] ? ('#' . (int)$row['tourn_id']) : '—' ?></td>
      <td><?= h($row['name'] ?? '—') ?></td>
      <td><?= h((string)($row['date'] ?? '—')) ?></td>
      <td><?= h($row['city'] ?? '—') ?></td>
      <td><?php
        if (!empty($row['ok'])) echo '<span class="badge-ok">OK</span>';
        else echo '<span class="badge-err">ошибка</span>' . (!empty($row['error']) ? ' <span class="note">' . h(mb_substr((string)$row['error'], 0, 80)) . '</span>' : '');
      ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

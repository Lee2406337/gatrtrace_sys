<?php /** @var array $rows */ ?>
<h1>例行事項規則</h1>
<p><a class="btn" href="index.php?r=events&action=new">＋ 新增規則</a></p>
<table>
  <thead><tr><th>類別</th><th>工作事項</th><th>頻率</th><th>基準值</th><th>單位</th><th>備註</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['category']) ?></td>
      <td><?= htmlspecialchars($row['task_name']) ?></td>
      <td><?= htmlspecialchars(frequency_label($row['frequency'])) ?></td>
      <td><?= htmlspecialchars(baseline_value_label($row['frequency'], $row['baseline_value'])) ?></td>
      <td><?= htmlspecialchars($row['department']) ?></td>
      <td><?= htmlspecialchars((string)$row['note']) ?></td>
      <td>
        <a href="index.php?r=events&action=edit&id=<?= (int)$row['id'] ?>">編輯</a>
        <form method="post" action="index.php?r=events&action=delete" class="inline" data-confirm="確定刪除？">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="btn btn-secondary" type="submit">刪除</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

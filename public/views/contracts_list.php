<?php /** @var array $rows */ ?>
<h1>合約清單</h1>
<p><a class="btn" href="index.php?r=contracts&action=new">＋ 新增合約</a></p>
<table>
  <thead><tr><th>合約名稱</th><th>頻率</th><th>起始日</th><th>到期日(原始)</th><th>最後修改日期</th><th>單位</th><th>備註</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['contract_name']) ?></td>
      <td><?= htmlspecialchars(frequency_label((string)$row['frequency'])) ?></td>
      <td><?= htmlspecialchars((string)$row['start_date']) ?></td>
      <td><?= htmlspecialchars((string)$row['end_date_raw']) ?></td>
      <td><?= htmlspecialchars((string)$row['updated_at']) ?></td>
      <td><?= htmlspecialchars((string)$row['department']) ?></td>
      <td><?= htmlspecialchars((string)$row['note']) ?></td>
      <td>
        <a href="index.php?r=contracts&action=edit&id=<?= (int)$row['id'] ?>">編輯</a>
        <form method="post" action="index.php?r=contracts&action=delete" class="inline" data-confirm="確定刪除？">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="btn btn-secondary" type="submit">刪除</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

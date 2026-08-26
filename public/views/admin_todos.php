<?php /** @var array $rows */ /** @var array $months */ /** @var string $ym */
$statuses = \App\TodoValidator::STATUSES;
?>
<h1>本月待辦後台</h1>
<p class="text-danger">⚠ 這裡可以直接覆寫任何一筆待辦的狀態，不受一般簽核鎖定限制（已有簽核紀錄的也能改），僅供處理卡住或誤植的資料，會記錄在修改紀錄。</p>

<form method="get" action="admin.php" class="my-1">
  <input type="hidden" name="r" value="todos">
  <label>月份</label>
  <select name="ym" class="auto-submit-select">
    <option value="" <?= $ym === '' ? 'selected' : '' ?>>全部月份</option>
    <?php foreach ($months as $m): ?>
      <option value="<?= htmlspecialchars($m) ?>" <?= $ym === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if (empty($rows)): ?>
  <p class="text-muted">沒有待辦資料。</p>
<?php else: ?>
<table>
  <thead><tr><th>年月</th><th>類別</th><th>工作事項</th><th>頻率</th><th>單位</th><th>應完成日</th><th>狀態</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['year_month']) ?></td>
      <td><?= htmlspecialchars($row['category']) ?></td>
      <td><?= htmlspecialchars($row['task_name']) ?></td>
      <td><?= htmlspecialchars(frequency_label($row['frequency'])) ?></td>
      <td><?= htmlspecialchars((string) $row['department']) ?></td>
      <td><?= htmlspecialchars((string) $row['due_date']) ?></td>
      <td>
        <form method="post" action="admin.php?r=todos&action=override-status" class="flex gap-3" data-confirm="直接覆寫這筆待辦的狀態？（不受簽核鎖定限制）">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="ym" value="<?= htmlspecialchars($ym) ?>">
          <select name="status">
            <?php foreach ($statuses as $s): ?>
              <option value="<?= $s ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-secondary" type="submit">覆寫</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

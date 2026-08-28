<?php
/** @var string $type */
/** @var array $results */
/** @var string $csvContent */
$labels = ['ok' => '將匯入', 'error' => '格式錯誤，跳過', 'duplicate' => '重複，跳過'];
$classes = ['ok' => 'cell-green', 'error' => 'cell-red', 'duplicate' => 'cell-yellow'];
$okCount = count(array_filter($results, function ($r) {
    return $r['status'] === 'ok';
}));
$skipCount = count($results) - $okCount;
?>
<h1>匯入預覽（<?= $type === 'contracts' ? '合約' : '本月待辦' ?>）</h1>
<p>共讀到 <?= count($results) ?> 列（不含標題列）：將匯入 <strong><?= $okCount ?></strong> 筆，跳過 <strong><?= $skipCount ?></strong> 筆。</p>

<?php if (count($results) === 0): ?>
  <p style="color:#666;">沒有讀到任何資料列，請確認 CSV 內容或重新下載範本。</p>
<?php else: ?>
<table>
  <thead><tr><th>列號</th><th>狀態</th><th>內容 / 原因</th></tr></thead>
  <tbody>
  <?php foreach ($results as $row): ?>
    <tr>
      <td><?= (int) $row['line'] ?></td>
      <td class="<?= $classes[$row['status']] ?>"><?= $labels[$row['status']] ?></td>
      <td>
        <?= htmlspecialchars(implode('、', $row['cols'])) ?>
        <?php if ($row['message']): ?><br><span class="cell-red"><?= htmlspecialchars($row['message']) ?></span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<p>
<?php if ($okCount > 0): ?>
  <form method="post" action="admin.php?r=import&action=commit" class="inline">
    <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <textarea name="csv_content" style="display:none"><?= htmlspecialchars($csvContent) ?></textarea>
    <button class="btn" type="submit">確認匯入 <?= $okCount ?> 筆</button>
  </form>
<?php endif; ?>
  <a class="btn btn-secondary" href="admin.php?r=import">重新上傳</a>
</p>

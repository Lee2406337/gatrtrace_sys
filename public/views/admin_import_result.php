<?php
/** @var string $type */
/** @var int $inserted */
/** @var int $skipped */
$dest = match ($type) {
    'contracts' => ['index.php?r=contracts', '合約清單'],
    default     => ['index.php?r=events', '事件總攬'],
};
?>
<h1>匯入結果</h1>
<p>成功匯入 <strong><?= $inserted ?></strong> 筆，跳過 <strong><?= $skipped ?></strong> 筆。</p>
<p>
  <a class="btn" href="<?= htmlspecialchars($dest[0]) ?>">查看<?= htmlspecialchars($dest[1]) ?></a>
  <a class="btn btn-secondary" href="admin.php?r=import">回匯入頁面</a>
</p>

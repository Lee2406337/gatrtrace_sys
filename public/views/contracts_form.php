<?php /** @var ?array $row */
$freqOptions = ['每月', '每季', '半年', '每年', '2年', '3年', '一次性', '其他'];
if (!empty($row['frequency']) && !in_array($row['frequency'], $freqOptions, true)) {
    $freqOptions[] = $row['frequency']; // 保留既有不在清單內的舊值，避免編輯存檔時被靜默改掉
}
?>
<h1><?= $row ? '編輯' : '新增' ?>合約</h1>
<form method="post" action="index.php?r=contracts&action=save">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
  <label>合約名稱 *</label>
  <input name="contract_name" required value="<?= htmlspecialchars($row['contract_name'] ?? '') ?>">
  <label>頻率</label>
  <select name="frequency">
    <option value="">—</option>
    <?php foreach ($freqOptions as $f): ?>
      <option value="<?= htmlspecialchars($f) ?>" <?= (($row['frequency'] ?? '') === $f) ? 'selected' : '' ?>><?= htmlspecialchars(frequency_label($f)) ?></option>
    <?php endforeach; ?>
  </select>
  <label>起始日</label>
  <input name="start_date" type="date" value="<?= htmlspecialchars((string)($row['start_date'] ?? '')) ?>">
  <label>到期日（可留空）</label>
  <input name="end_date_raw" type="date" value="<?= htmlspecialchars((string)($row['end_date_raw'] ?? '')) ?>">
  <label>單位</label>
  <select name="department">
    <option value="">—</option>
    <?php foreach (\App\Departments::ALL as $d): ?>
      <option value="<?= $d ?>" <?= (($row['department'] ?? '') === $d) ? 'selected' : '' ?>><?= $d ?></option>
    <?php endforeach; ?>
  </select>
  <label>備註</label>
  <textarea name="note"><?= htmlspecialchars((string)($row['note'] ?? '')) ?></textarea>
  <p><button class="btn" type="submit">儲存</button>
     <a class="btn btn-secondary" href="index.php?r=contracts">取消</a></p>
</form>

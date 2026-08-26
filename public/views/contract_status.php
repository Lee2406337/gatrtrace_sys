<?php /** @var array $items */ /** @var array $summary */ ?>
<h1>合約年限整理
  <span class="legend">
    <span class="legend-dot cell-red"></span> 已到期／30天內到期
    <span class="legend-dot cell-yellow"></span> 31–90天內到期
  </span>
</h1>
<div class="overview">
  <div class="box">合約總數：<?= (int)$summary['total'] ?></div>
  <div class="box">30天內：<?= (int)$summary['30'] ?></div>
  <div class="box">已到期：<?= (int)$summary['expired'] ?></div>
  <div class="box">31–90天：<?= (int)$summary['31_90'] ?></div>
  <div class="box">&gt;90天：<?= (int)$summary['over90'] ?></div>
</div>
<table>
  <thead><tr><th>合約名稱</th><th>頻率</th><th>起始日</th><th>到期日</th><th>狀態</th><th>剩餘天數</th><th>提醒建議</th></tr></thead>
  <tbody>
  <?php $prevNoRemain = false; foreach ($items as $it):
      $cls = contract_cell_class($it['status']);
      $isNoRemain = $it['remaining'] === null;
      if ($isNoRemain && !$prevNoRemain): $prevNoRemain = true; ?>
        <tr class="row-divider"><td colspan="7"></td></tr>
  <?php endif; ?>
    <tr class="<?= $cls ?>">
      <td><?= htmlspecialchars($it['contract_name']) ?></td>
      <td><?= htmlspecialchars(frequency_label((string)$it['frequency'])) ?></td>
      <td><?= htmlspecialchars((string)$it['start_date']) ?></td>
      <td><?= htmlspecialchars((string)($it['end_date'] ?? $it['end_date_raw'])) ?></td>
      <td><?= htmlspecialchars($it['status']) ?></td>
      <td><?= $it['remaining'] === null ? '' : (int)$it['remaining'] ?></td>
      <td><?= htmlspecialchars($it['suggestion']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

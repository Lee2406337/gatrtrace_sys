<?php /** @var array $rows */ /** @var int $total */ /** @var int $curPage */ /** @var int $perPage */
/** @var array $filters */ /** @var array $pageOptions */ /** @var array $actionOptions */
$totalPages = max(1, (int) ceil($total / $perPage));
$qs = function (int $pn) use ($filters): string {
    $params = [
        'r' => 'change-logs', 'pn' => $pn,
        'flt_from' => $filters['date_from'], 'flt_to' => $filters['date_to'],
        'flt_page' => $filters['page'], 'flt_action' => $filters['action'], 'flt_actor' => $filters['actor'],
    ];
    return 'admin.php?' . http_build_query(array_filter($params, function ($v) {
        return $v !== '';
    }));
};
?>
<h1>修改紀錄</h1>

<form method="get" action="admin.php" class="flex flex-wrap flex-end gap-6 my-1">
  <input type="hidden" name="r" value="change-logs">
  <div>
    <label>從</label>
    <input type="date" name="flt_from" value="<?= htmlspecialchars($filters['date_from']) ?>" class="w-filter">
  </div>
  <div>
    <label>到</label>
    <input type="date" name="flt_to" value="<?= htmlspecialchars($filters['date_to']) ?>" class="w-filter">
  </div>
  <div>
    <label>分頁</label>
    <select name="flt_page" class="w-filter">
      <option value="">全部</option>
      <?php foreach ($pageOptions as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>" <?= $filters['page'] === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>動作</label>
    <select name="flt_action" class="w-filter">
      <option value="">全部</option>
      <?php foreach ($actionOptions as $a): ?>
        <option value="<?= htmlspecialchars($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>異動人員</label>
    <input type="text" name="flt_actor" value="<?= htmlspecialchars($filters['actor']) ?>" placeholder="姓名或工號" class="w-filter">
  </div>
  <div>
    <button class="btn" type="submit">篩選</button>
    <a class="btn btn-secondary" href="admin.php?r=change-logs">清除篩選</a>
  </div>
</form>

<p class="text-muted">共 <?= $total ?> 筆，第 <?= $curPage ?>／<?= $totalPages ?> 頁</p>

<?php if (empty($rows)): ?>
  <p class="text-muted">沒有符合條件的紀錄。</p>
<?php else: ?>
<table>
  <thead><tr><th>時間</th><th>分頁</th><th>動作</th><th>摘要</th><th>異動人員</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
      <td><?= htmlspecialchars($r['page']) ?></td>
      <td><?= htmlspecialchars($r['action']) ?></td>
      <td><?= htmlspecialchars($r['summary']) ?></td>
      <td><?= htmlspecialchars($r['actor']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="flex gap-5 my-1">
  <?php if ($curPage > 1): ?>
    <a class="btn btn-secondary" href="<?= htmlspecialchars($qs(1)) ?>">« 第一頁</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars($qs($curPage - 1)) ?>">‹ 上一頁</a>
  <?php endif; ?>
  <span>第 <?= $curPage ?>／<?= $totalPages ?> 頁</span>
  <?php if ($curPage < $totalPages): ?>
    <a class="btn btn-secondary" href="<?= htmlspecialchars($qs($curPage + 1)) ?>">下一頁 ›</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars($qs($totalPages)) ?>">最後一頁 »</a>
  <?php endif; ?>
</div>

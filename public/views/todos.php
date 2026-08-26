<?php /** @var string $ym */ /** @var array $dated */ /** @var array $undated */ /** @var array $approval */ /** @var bool $allCompleted */ /** @var array $fullySigned */
$today = new DateTimeImmutable('today');
$statuses = \App\TodoValidator::STATUSES;
$curYm = date('Y-m');
$ymDt = new DateTimeImmutable($ym . '-01');
$prevYm = $ymDt->modify('-1 month')->format('Y-m');
$nextYm = $ymDt->modify('+1 month')->format('Y-m');

// 「本月待辦」跟「本月提醒／未指定日期事項」兩個表格只差「整列變色（逾期）」「類別欄顏色」
// 「應完成日欄內容」三處，其餘欄位（頻率/單位/狀態表單/後續辦理/備註/簽核/操作）完全一樣，
// 抽成這個 local function 給兩個迴圈共用，避免同一段標記維護兩份。
function todos_render_row(array $row, string $ym, array $approval, array $statuses, string $rowClass, string $categoryClass, string $dueDateCell): string
{
    ob_start(); ?>
    <tr class="<?= $rowClass ?>">
      <td class="<?= $categoryClass ?>"><?= htmlspecialchars($row['category']) ?></td>
      <td><?= htmlspecialchars($row['task_name']) ?></td>
      <td><?= htmlspecialchars(frequency_label($row['frequency'])) ?></td>
      <td><?= htmlspecialchars((string)$row['department']) ?></td>
      <td><?= $dueDateCell ?></td>
      <td>
        <?php $hasSignature = isset($approval[(int)$row['id']]) && $approval[(int)$row['id']]['has_signature']; ?>
        <?php if ($hasSignature): ?>
          <span class="text-hint">已有簽核紀錄，無法編輯</span>
        <?php else: ?>
        <form method="post" action="index.php?r=todos&action=update-status&ym=<?= urlencode($ym) ?>" class="status-form flex gap-3 flex-wrap">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <select name="status" class="status-select">
            <?php foreach ($statuses as $s): ?>
              <option value="<?= $s ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <input name="follow_up" placeholder="後續辦理(已完成必填)" value="<?= htmlspecialchars((string)$row['follow_up']) ?>">
          <input name="note" placeholder="備註(異常須含原因)" value="<?= htmlspecialchars((string)$row['note']) ?>">
          <?php if (in_array($row['frequency'], [\App\EventFrequency::Other->value, \App\EventFrequency::Irregular->value], true)): ?>
            <input name="next_due_date" type="date" class="next-due-input hidden" title="下一次需要處理的時間（完成時必填，會自動建立下一筆待辦）">
          <?php endif; ?>
          <button class="btn" type="submit">更新</button>
        </form>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars((string)$row['follow_up']) ?></td>
      <td><?= htmlspecialchars((string)$row['note']) ?></td>
      <td>
        <?php if (in_array($row['status'], ['已完成', '簽核中'], true) && isset($approval[(int)$row['id']])):
          $ap = $approval[(int)$row['id']]; ?>
          <span><?= htmlspecialchars($ap['status']) ?></span>
          <?php if ($ap['status'] !== '已簽核'): ?>
            <span title="待簽核" class="text-danger-strong">●</span>
          <?php endif; ?>
          <?php if ($ap['can_sign']): ?>
            <a href="index.php?r=todos&action=sign&id=<?= (int)$row['id'] ?>&ym=<?= urlencode($ym) ?>">簽核</a>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td>
        <form method="post" action="index.php?r=todos&action=delete&ym=<?= urlencode($ym) ?>" class="inline" data-confirm="確定刪除？">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="btn btn-secondary" type="submit">刪除</button>
        </form>
      </td>
    </tr>
    <?php
    return ob_get_clean();
}
?>
<h1>本月待辦事項（<?= htmlspecialchars($ym) ?>）
  <span class="legend">
    <span class="legend-dot cell-red"></span> 逾期／即將到期(≤3天)
    <span class="legend-dot cell-yellow"></span> 即將到期(4-7天)
    <span class="legend-dot cell-green"></span> 已完成／簽核中
    <span class="legend-dot cell-darkgray"></span> 異常
  </span>
</h1>
<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>
<div class="my-1">
  <a class="btn btn-secondary" href="index.php?r=todos&ym=<?= urlencode($prevYm) ?>">◀ 上個月</a>
  <?php if ($ym !== $curYm): ?>
    <a class="btn btn-secondary" href="index.php?r=todos&ym=<?= urlencode($curYm) ?>">回到本月</a>
  <?php endif; ?>
  <a class="btn btn-secondary" href="index.php?r=todos&ym=<?= urlencode($nextYm) ?>">下個月 ▶</a>
</div>
<div class="my-1">
  <form method="post" action="index.php?r=todos&action=expand&ym=<?= urlencode($ym) ?>" class="inline" data-confirm="重新整理？會產生本月新待辦與合約提醒、清掉已完成合約的殘留提醒，重複項目會自動略過。">
    <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
    <button class="btn" type="submit">重新整理</button>
  </form>
  <a class="btn btn-secondary" href="index.php?r=todos&action=new&ym=<?= urlencode($ym) ?>">＋ 新增待辦</a>
</div>
<?php if ($allCompleted): ?>
  <p class="notice-success">全部已完成</p>
<?php elseif (empty($dated)): ?>
  <p class="text-muted">本月尚無待辦事項，請按「重新整理」展開。</p>
<?php else: ?>
<table>
  <thead><tr><th>類別</th><th>工作事項</th><th>頻率</th><th>單位</th><th>應完成日</th>
    <th>目前狀態</th><th>後續辦理事項</th><th>備註</th><th>簽核</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($dated as $row):
      $remain = (int)$today->diff(new DateTimeImmutable($row['due_date']))->format('%r%a');
      $cls = todo_cell_class($row['status'], $remain);
      // 逾期（今天 > 應完成日）且尚未處理完：整列標紅，比只標類別欄更醒目
      $isOverdue = $remain < 0 && in_array($row['status'], ['未開始', '進行中'], true);
      $rowCls = $isOverdue ? 'cell-red' : '';
      $cellCls = $isOverdue ? '' : $cls;
      echo todos_render_row($row, $ym, $approval, $statuses, $rowCls, $cellCls, htmlspecialchars((string)$row['due_date']));
  endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>本月提醒／未指定日期事項</h2>
<?php if (empty($undated)): ?>
  <p class="text-muted">本月無未指定日期的事項。</p>
<?php else: ?>
<table>
  <thead><tr><th>類別</th><th>工作事項</th><th>頻率</th><th>單位</th><th>應完成日</th>
    <th>目前狀態</th><th>後續辦理事項</th><th>備註</th><th>簽核</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($undated as $row):
      $cls = todo_cell_class($row['status'], null);
      echo todos_render_row($row, $ym, $approval, $statuses, '', $cls, '—');
  endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>已完成／已簽核</h2>
<?php if (empty($fullySigned)): ?>
  <p class="text-muted">本月尚無已完成且已簽核完成的項目。</p>
<?php else: ?>
<table>
  <thead><tr><th>類別</th><th>工作事項</th><th>後續辦理事項</th><th>備註</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($fullySigned as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['category']) ?></td>
      <td><?= htmlspecialchars($row['task_name']) ?></td>
      <td><?= htmlspecialchars((string)$row['follow_up']) ?></td>
      <td><?= htmlspecialchars((string)$row['note']) ?></td>
      <td><a class="btn btn-secondary" href="index.php?r=todos&action=sign&id=<?= (int)$row['id'] ?>&ym=<?= urlencode($ym) ?>">進入</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php /** @var array $todo */ /** @var array $build */ /** @var int $uid */ /** @var string $ym */ /** @var array $opinions */ /** @var array $history */ ?>
<h1>簽核</h1>
<table>
  <tr><th>類別</th><td><?= htmlspecialchars($todo['category']) ?></td></tr>
  <tr><th>工作事項</th><td><?= htmlspecialchars($todo['task_name']) ?></td></tr>
  <tr><th>頻率</th><td><?= htmlspecialchars(frequency_label($todo['frequency'])) ?></td></tr>
  <tr><th>應完成日</th><td><?= htmlspecialchars((string)$todo['due_date']) ?></td></tr>
  <tr><th>本次完成日</th><td><?= htmlspecialchars((string)$todo['completed_at']) ?></td></tr>
  <tr><th>後續辦理事項</th><td><?= htmlspecialchars((string)$todo['follow_up']) ?></td></tr>
  <tr><th>備註</th><td><?= htmlspecialchars((string)$todo['note']) ?></td></tr>
  <tr><th>簽核狀態</th><td><?= htmlspecialchars($build['status']) ?></td></tr>
</table>

<h2>簽核進度</h2>
<table>
  <thead><tr><th>關卡</th><th>狀態</th><th>簽核人</th><th>意見</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($build['steps'] as $st): ?>
    <tr>
      <td><?= (int)$st['step_order'] ?>（<?= htmlspecialchars($st['label']) ?>）</td>
      <td><?= $st['signed'] ? '已簽' : ($st['skipped'] ? '跳過（無在職簽核人）' : '待簽') ?></td>
      <td>
        <?php foreach ($opinions as $op) { if ((int)$op['step_order']===(int)$st['step_order']) echo htmlspecialchars($op['signer_name']); } ?>
      </td>
      <td>
        <?php foreach ($opinions as $op) { if ((int)$op['step_order']===(int)$st['step_order']) echo htmlspecialchars((string)$op['opinion']); } ?>
      </td>
      <td>
        <?php if (\App\ApprovalWorkflow::canRecall($build, $uid, (int)$st['step_order'])): ?>
          <form method="post" action="index.php?r=todos&action=recall&ym=<?= urlencode($ym) ?>" class="inline" data-confirm="取回此關簽核？">
            <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
            <input type="hidden" name="id" value="<?= (int)$todo['id'] ?>">
            <input type="hidden" name="step" value="<?= (int)$st['step_order'] ?>">
            <button class="btn btn-secondary" type="submit">取回</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>簽核歷程</h2>
<?php if (empty($history)): ?>
  <p class="text-muted">尚無簽核活動。</p>
<?php else: ?>
<table>
  <thead><tr><th>活動名稱</th><th>寄件者</th><th>狀態</th><th>意見建議</th><th>時間</th></tr></thead>
  <tbody>
  <?php foreach ($history as $h): ?>
    <tr>
      <td><?= htmlspecialchars((string)($h['step_label'] ?? '（管理覆寫，非特定關卡）')) ?></td>
      <td><?= htmlspecialchars($h['actor_name']) ?></td>
      <td><?= htmlspecialchars($h['action']) ?></td>
      <td><?= htmlspecialchars((string)$h['opinion']) ?></td>
      <td><?= htmlspecialchars((string)$h['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($build['current_step'] && \App\ApprovalWorkflow::canSign($build, $uid, (int)$build['current_step'])): ?>
  <h2>簽核（第 <?= (int)$build['current_step'] ?> 關）</h2>
  <form method="post" action="index.php?r=todos&action=do-sign&ym=<?= urlencode($ym) ?>">
    <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
    <input type="hidden" name="id" value="<?= (int)$todo['id'] ?>">
    <label>簽核意見（可留空）</label>
    <textarea name="opinion"></textarea>
    <p><button class="btn" type="submit">送出簽核</button></p>
  </form>
<?php else: ?>
  <p>目前無需你簽核（非目前關卡簽核人，或已完成）。</p>
<?php endif; ?>
<p><a class="btn btn-secondary" href="index.php?r=todos&ym=<?= urlencode($ym) ?>">返回本月待辦</a></p>

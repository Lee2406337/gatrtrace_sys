<?php /** @var array $steps */ /** @var array $users */
$activeUsers = array_filter($users, function ($u) {
    return ($u['employment_status'] ?? '在職') === '在職';
});
$userOptions = array_values(array_map(function ($u) {
    return ['id' => (int) $u['id'], 'label' => $u['name']];
}, $activeUsers));
$userOptionsJson = htmlspecialchars(json_encode($userOptions, JSON_UNESCAPED_UNICODE));
?>
<h1>簽核關卡設定</h1>

<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>

<p class="notice-warn">
  ⚠ 請勿在有進行中簽核時調整關卡順序或刪除關卡，可能導致既有簽名錯置。
</p>

<?php if (empty($steps)): ?>
  <p class="cell-red pad-sm">目前無簽核關卡，所有已完成待辦將直接視為已簽核。</p>
<?php endif; ?>

<h2>新增關卡</h2>
<form method="post" action="admin.php?r=approval-steps&action=save">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <label>順序</label><input name="step_order" type="number" min="1" required>
  <label>關卡種類</label>
  <select name="step_kind">
    <option value="approve">需簽核</option>
    <option value="notify">僅通知</option>
  </select>
  <label>簽核人類型</label>
  <select name="signer_kind" class="signer-kind-select">
    <option value="role">角色</option>
    <option value="user">指定人員</option>
  </select>
  <span data-signer="role">
    <label>角色</label>
    <select name="signer_value_role">
      <option value="部門主管">部門主管</option>
      <option value="管理部主管">管理部主管</option>
    </select>
  </span>
  <span data-signer="user" class="hidden">
    <label>指定人員</label>
    <div class="person-picker" data-options="<?= $userOptionsJson ?>">
      <input type="text" class="person-picker-input" autocomplete="off" placeholder="輸入姓名搜尋">
      <input type="hidden" name="signer_value_user" class="person-picker-value">
      <div class="person-picker-menu hidden"></div>
    </div>
  </span>
  <label>顯示名稱</label><input name="label" required>
  <p><button class="btn" type="submit">新增</button></p>
</form>

<h2>現有關卡</h2>
<table>
  <thead><tr><th>順序／關卡種類／簽核人類型／簽核人／名稱</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($steps as $s): ?>
    <tr>
      <td>
        <form method="post" action="admin.php?r=approval-steps&action=save" class="flex flex-wrap gap-3">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input name="step_order" type="number" min="1" value="<?= (int)$s['step_order'] ?>" class="w-narrow">
          <select name="step_kind">
            <option value="approve" <?= ($s['step_kind']??'approve')==='approve'?'selected':'' ?>>需簽核</option>
            <option value="notify" <?= ($s['step_kind']??'approve')==='notify'?'selected':'' ?>>僅通知</option>
          </select>
          <select name="signer_kind" class="signer-kind-select">
            <option value="role" <?= $s['signer_kind']==='role'?'selected':'' ?>>角色</option>
            <option value="user" <?= $s['signer_kind']==='user'?'selected':'' ?>>指定人員</option>
          </select>
          <span data-signer="role" class="<?= $s['signer_kind']==='user'?'hidden':'' ?>">
            <select name="signer_value_role">
              <option value="部門主管" <?= $s['signer_value']==='部門主管'?'selected':'' ?>>部門主管</option>
              <option value="管理部主管" <?= $s['signer_value']==='管理部主管'?'selected':'' ?>>管理部主管</option>
            </select>
          </span>
          <span data-signer="user" class="<?= $s['signer_kind']==='user'?'':'hidden' ?>">
            <?php
              $rowUserOptions = $userOptions;
              $curSignerId = $s['signer_kind'] === 'user' ? (int) $s['signer_value'] : 0;
              $curSignerLabel = '';
              foreach ($users as $u) {
                  if ((int) $u['id'] === $curSignerId) {
                      $curSignerLabel = $u['name'];
                      if (($u['employment_status'] ?? '在職') !== '在職') {
                          $curSignerLabel .= '（已停用）';
                          $rowUserOptions[] = ['id' => $curSignerId, 'label' => $curSignerLabel];
                      }
                      break;
                  }
              }
            ?>
            <div class="person-picker" data-options="<?= htmlspecialchars(json_encode($rowUserOptions, JSON_UNESCAPED_UNICODE)) ?>">
              <input type="text" class="person-picker-input" autocomplete="off" placeholder="輸入姓名搜尋" value="<?= htmlspecialchars($curSignerLabel) ?>">
              <input type="hidden" name="signer_value_user" class="person-picker-value" value="<?= $curSignerId ?: '' ?>">
              <div class="person-picker-menu hidden"></div>
            </div>
          </span>
          <input name="label" value="<?= htmlspecialchars($s['label']) ?>">
          <button class="btn" type="submit">儲存</button>
        </form>
      </td>
      <td>
        <form method="post" action="admin.php?r=approval-steps&action=delete" class="inline" data-confirm="刪除此關卡？請確認無進行中簽核。">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-secondary" type="submit">刪除</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="text-hint">角色可選「部門主管」「管理部主管」；指定人員請在輸入框打姓名關鍵字，從搜尋建議中選取在職使用者。關卡種類選「僅通知」時，這關不需要簽核、不會卡住流程，解出的人只會收到「已完成通知」信。</p>

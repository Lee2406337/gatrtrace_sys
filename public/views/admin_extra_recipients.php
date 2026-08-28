<?php /** @var array $rules */ /** @var array $users */
$activeUsers = array_filter($users, function ($u) {
    return ($u['employment_status'] ?? '在職') === '在職';
});
$userOptions = array_values(array_map(function ($u) {
    return ['id' => (int) $u['id'], 'label' => $u['name']];
}, $activeUsers));
$userOptionsJson = htmlspecialchars(json_encode($userOptions, JSON_UNESCAPED_UNICODE));
?>
<h1>提醒信副本設定</h1>

<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>

<p class="text-hint">設定某部門的「本月待辦提醒信」除了廣播給該部門所有在職人員之外，還要額外副本給誰（例如該部門主管）。副本內容跟部門廣播信完全一樣；如果指定的人本來就是該部門在職人員、已經收過廣播信，這裡不會重複寄第二封。</p>

<h2>新增規則</h2>
<form method="post" action="admin.php?r=extra-recipients&action=save">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <label>部門</label>
  <select name="department" required>
    <?php foreach (\App\Departments::ALL as $d): ?>
      <option value="<?= $d ?>"><?= $d ?></option>
    <?php endforeach; ?>
  </select>
  <label>收件人類型</label>
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

<h2>現有規則</h2>
<table>
  <thead><tr><th>部門／收件人類型／收件人／名稱</th><th>操作</th></tr></thead>
  <tbody>
  <?php foreach ($rules as $s): ?>
    <tr>
      <td>
        <form method="post" action="admin.php?r=extra-recipients&action=save" class="flex flex-wrap gap-3">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <select name="department">
            <?php foreach (\App\Departments::ALL as $d): ?>
              <option value="<?= $d ?>" <?= $s['department']===$d?'selected':'' ?>><?= $d ?></option>
            <?php endforeach; ?>
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
        <form method="post" action="admin.php?r=extra-recipients&action=delete" class="inline" data-confirm="刪除此規則？">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-secondary" type="submit">刪除</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="text-hint">角色可選「部門主管」「管理部主管」；指定人員請在輸入框打姓名關鍵字，從搜尋建議中選取在職使用者。</p>

<?php /** @var array $users */ /** @var array $depts */
$deptOptions = \App\Departments::ALL; ?>
<h1>使用者後台</h1>
<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>

<h2>新增使用者</h2>
<form method="post" action="admin.php?r=users&action=save">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <label>姓名</label><input name="name" required>
  <label>工號（登入帳號）</label><input name="staff_id" required>
  <label>Email（供提醒信）</label><input name="email" type="email" required>
  <label>任職狀態</label>
  <select name="employment_status"><option value="在職">啟用</option><option value="停用">停用</option></select>
  <div class="flex gap-3 my-05">
    <label class="checkbox-label"><input type="checkbox" name="is_admin" value="1"> 系統管理員（可進 admin 後台）</label>
  </div>

  <label>部門（可新增多個，身兼多職）</label>
  <div id="new-user-depts"></div>
  <div class="flex gap-4 my-03">
    <select id="new-user-dept-pick" class="w-auto">
      <option value="">選擇部門</option>
      <?php foreach ($deptOptions as $d): ?>
        <option value="<?= $d ?>"><?= $d ?></option>
      <?php endforeach; ?>
    </select>
    <label class="checkbox-label"><input type="checkbox" id="new-user-dept-pick-manager" value="1"> 主管</label>
    <button type="button" class="btn btn-secondary" id="new-user-dept-add">加部門</button>
  </div>

  <label>密碼</label><input name="password" type="password" required>
  <p><button class="btn" type="submit">新增</button></p>
</form>

<h2>使用者列表</h2>
<p>
  <input type="text" id="user-search" placeholder="搜尋姓名／工號／Email" class="w-search">
</p>
<table class="admin-users">
  <thead><tr><th>基本資料</th><th>工號</th><th>Email</th><th>狀態</th><th>登入狀態</th><th>部門（身兼多職）</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td>
        <form method="post" action="admin.php?r=users&action=save" class="flex gap-3 flex-wrap">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input name="name" value="<?= htmlspecialchars($u['name']) ?>" required>
          <input name="staff_id" placeholder="工號" value="<?= htmlspecialchars((string)($u['staff_id'] ?? '')) ?>">
          <input name="email" type="email" value="<?= htmlspecialchars((string)$u['email']) ?>">
          <div class="field-break"></div>
          <select name="employment_status">
            <option value="在職" <?= $u['employment_status']==='在職'?'selected':'' ?>>啟用</option>
            <option value="停用" <?= $u['employment_status']==='停用'?'selected':'' ?>>停用</option>
          </select>
          <label class="checkbox-label"><input type="checkbox" name="is_admin" value="1" <?= (int)($u['is_admin'] ?? 0)===1?'checked':'' ?>> 系統管理員</label>
          <input name="password" type="password" placeholder="改密碼(留空不改)">
          <button class="btn" type="submit">儲存</button>
        </form>
      </td>
      <td><?= htmlspecialchars((string)($u['staff_id'] ?? '')) ?></td>
      <td><?= htmlspecialchars((string)$u['email']) ?></td>
      <td>
        <?= $u['employment_status'] === '在職' ? '啟用' : '停用' ?>
        <?php if ((int)($u['is_admin'] ?? 0) === 1): ?><br><strong>系統管理員</strong><?php endif; ?>
      </td>
      <td>
        <?php
          $failedAttempts = (int) ($u['failed_attempts'] ?? 0);
          $remaining = max(0, \App\Auth::MAX_FAILED_ATTEMPTS - $failedAttempts);
          $lockedUntil = $u['locked_until'] ?? null;
          $isLocked = $lockedUntil !== null && $lockedUntil > date('Y-m-d H:i:s');
        ?>
        <div>剩餘嘗試次數：<?= $remaining ?>/<?= \App\Auth::MAX_FAILED_ATTEMPTS ?></div>
        <?php if ($isLocked): ?>
          <div class="cell-red">鎖定中，將於 <?= htmlspecialchars($lockedUntil) ?> 自動解鎖</div>
        <?php endif; ?>
        <form method="post" action="admin.php?r=users&action=unlock" class="inline" data-confirm="解封並重設登入嘗試次數？">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <button class="btn btn-secondary" type="submit">解封</button>
        </form>
      </td>
      <td>
        <?php foreach ($depts[$u['id']] as $ud): ?>
          <div>
            <?= htmlspecialchars($ud['department']) ?>
            <?php if ((int)$ud['is_manager']===1): ?><strong>（主管）</strong>
              <form method="post" action="admin.php?r=users&action=toggle-manager" class="inline">
                <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
                <input type="hidden" name="ud" value="<?= (int)$ud['id'] ?>">
                <input type="hidden" name="on" value="0">
                <button class="btn-link" type="submit">取消主管</button>
              </form>
            <?php else: ?>
              <form method="post" action="admin.php?r=users&action=toggle-manager" class="inline">
                <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
                <input type="hidden" name="ud" value="<?= (int)$ud['id'] ?>">
                <input type="hidden" name="on" value="1">
                <button class="btn-link" type="submit">設為主管</button>
              </form>
            <?php endif; ?>
            <form method="post" action="admin.php?r=users&action=remove-dept" class="inline" data-confirm="移除此部門？">
              <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
              <input type="hidden" name="ud" value="<?= (int)$ud['id'] ?>">
              <button class="btn-link" type="submit">移除</button>
            </form>
          </div>
        <?php endforeach; ?>
        <form method="post" action="admin.php?r=users&action=add-dept" class="flex gap-3">
          <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <select name="department"><?php foreach ($deptOptions as $d): ?><option value="<?= $d ?>"><?= $d ?></option><?php endforeach; ?></select>
          <label class="checkbox-label"><input type="checkbox" name="is_manager" value="1"> 主管</label>
          <button class="btn btn-secondary" type="submit">加部門</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

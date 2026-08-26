<?php /** @var string $err */ /** @var bool $ok */ ?>
<h1>修改密碼</h1>

<?php if ($ok): ?>
  <p class="notice-success">密碼已修改成功。</p>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($err) ?></p>
<?php endif; ?>

<form method="post" action="index.php?r=change-password&action=save" class="w-form-narrow">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <label>目前密碼</label>
  <input name="current_password" type="password" required>
  <label>新密碼</label>
  <input name="new_password" type="password" required>
  <label>確認新密碼</label>
  <input name="confirm_password" type="password" required>
  <p><button class="btn" type="submit">修改密碼</button></p>
</form>

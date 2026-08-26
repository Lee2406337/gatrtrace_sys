<?php /** @var string $err */ ?>
<div class="auth-wrap">
  <div class="auth-box">
    <h1>登入</h1>
    <?php if ($err !== ''): ?><p class="cell-red pad-sm"><?= htmlspecialchars($err) ?></p><?php endif; ?>
    <form method="post" action="index.php?r=login&action=authenticate">
      <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
      <label>工號</label>
      <input name="staff_id" required>
      <label>密碼</label>
      <input name="password" type="password" required id="login-password">
      <p id="caps-lock-hint" class="caps-hint hidden">⚠️ Caps Lock 已開啟</p>
      <button class="btn" type="submit">登入</button>
    </form>
  </div>
</div>

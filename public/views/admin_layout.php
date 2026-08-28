<?php /** @var string $title */ /** @var string $body */ ?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> — 後台 — 總務環安待辦系統</title>
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
  <?php if (\App\Auth::check()): ?>
  <nav>
      <a href="admin.php?r=users">使用者後台</a>
      <a href="admin.php?r=todos">本月待辦後台</a>
      <a href="admin.php?r=approval-steps">簽核關卡設定</a>
      <a href="admin.php?r=extra-recipients">提醒信副本設定</a>
      <a href="admin.php?r=change-logs">修改紀錄</a>
      <a href="admin.php?r=import">資料匯入</a>
      <a href="index.php?r=todos">返回前台</a>
      <form method="post" action="index.php?r=logout" class="inline">
        <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
        <button class="btn-link" type="submit">登出（<?= htmlspecialchars(\App\Auth::user()['name']) ?>）</button>
      </form>
  </nav>
  <?php endif; ?>
  <?= $body ?>
  <p class="mt-2"><a class="btn btn-secondary" href="index.php?r=todos">« 返回前台</a></p>
  <script src="../assets/app.js" defer></script>
</body>
</html>

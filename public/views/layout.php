<?php /** @var string $title */ /** @var string $body */ ?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> — 總務環安待辦系統</title>
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
  <?php if (\App\Auth::check()): ?>
  <nav>
      <a href="index.php?r=todos">本月待辦</a>
      <a href="index.php?r=contract-status">合約年限整理</a>
      <a href="index.php?r=calendar">月份行事曆</a>
      <a href="index.php?r=events">例行事項</a>
      <a href="index.php?r=contracts">合約清單</a>
      <a href="index.php?r=change-password">修改密碼</a>
    <?php if (\App\Auth::isAdmin()): ?>
      <a href="admin.php">後台</a>
    <?php endif; ?>
      <form method="post" action="index.php?r=logout" class="inline">
        <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
        <button class="btn-link" type="submit">登出（<?= htmlspecialchars(\App\Auth::user()['name']) ?>）</button>
      </form>
  </nav>
  <?php endif; ?>
  <?= $body ?>
  <script src="../assets/app.js" defer></script>
</body>
</html>

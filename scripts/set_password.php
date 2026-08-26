<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/hidden_input.php';
use App\Db;

// email 不算敏感資訊，維持 argv 傳入；密碼改為互動式遮罩輸入。
$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "用法：php scripts/set_password.php <email>\n");
    exit(1);
}
if (function_exists('stream_isatty') && !stream_isatty(STDIN)) {
    fwrite(STDERR, "需要在真正的終端機（PowerShell／cmd 視窗）中直接執行本腳本，才能互動輸入密碼。\n");
    exit(1);
}
$plain = read_hidden_password();
if ($plain === '') {
    fwrite(STDERR, "密碼不可為空。\n");
    exit(1);
}
$pdo = Db::connect(require __DIR__ . '/../config/db.php');
$st = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$st->execute([password_hash($plain, PASSWORD_DEFAULT), $email]);
echo $st->rowCount() > 0 ? "已更新 $email 密碼\n" : "查無此 email：$email\n";

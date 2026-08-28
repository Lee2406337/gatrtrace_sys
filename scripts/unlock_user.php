<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Polyfills.php';
require_once __DIR__ . '/lib/hidden_input.php';
use App\Db;

// 解鎖被登入防爆破鎖定的帳號。用於唯一管理者自我鎖定、後台無法解封時的回復路徑。
// 這是最後一道防線（只要能在這台機器上跑 CLI 就能執行），所以額外要求輸入
// administrator 帳號目前的密碼做二次驗證，不是誰能連上這台機器就能任意解鎖任何帳號。
// 畫面全程空白：不印任何提示文字，密碼輸入不回顯，按 Enter 送出；驗證錯誤畫面
// 一樣不變、直接結束，不透露任何訊息（不重試、不提示錯誤原因）。

$staffId = $argv[1] ?? '';
if ($staffId === '') {
    fwrite(STDERR, "用法：php scripts/unlock_user.php <staff_id>\n");
    exit(1);
}

// 遮罩輸入需要一個真正的終端機（PowerShell／cmd 視窗）才能互動；不是的話
// （例如被其他腳本呼叫、輸出被導向檔案）遮罩輸入會卡住等不到輸入，
// 這裡先擋掉、直接失敗，而不是無限卡住。
if (function_exists('stream_isatty') && !stream_isatty(STDIN)) {
    fwrite(STDERR, "需要在真正的終端機（PowerShell／cmd 視窗）中直接執行本腳本，才能互動輸入密碼。\n");
    exit(1);
}

$pdo = Db::connect(require __DIR__ . '/../config/db.php');

$st = $pdo->prepare("SELECT password_hash FROM users WHERE staff_id = 'administrator'");
$st->execute();
$adminHash = $st->fetchColumn();

$password = read_hidden_password();

$verifyOk = ($adminHash !== false && $adminHash !== null && $adminHash !== '') && password_verify($password, $adminHash);

if (!$verifyOk) {
    exit(1); // 密碼錯誤（或查無 administrator 帳號）：畫面不變，靜默結束
}

$st = $pdo->prepare("UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE staff_id = ?");
$st->execute([$staffId]);
echo $st->rowCount() > 0 ? "已解鎖工號 $staffId\n" : "查無此工號（或原本即未鎖定）：$staffId\n";

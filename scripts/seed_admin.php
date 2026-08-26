<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/hidden_input.php';

use App\Db;
use App\Repository\UsersRepository;

// 建立/更新系統管理者帳號（工號 administrator）。密碼改為互動式遮罩輸入，不再透過 CLI
// 參數帶入，避免明碼留在 shell 歷史紀錄／process listing。
if (function_exists('stream_isatty') && !stream_isatty(STDIN)) {
    fwrite(STDERR, "需要在真正的終端機（PowerShell／cmd 視窗）中直接執行本腳本，才能互動輸入密碼。\n");
    exit(1);
}
$password = read_hidden_password();
if ($password === '') {
    fwrite(STDERR, "密碼不可為空。\n");
    exit(1);
}

$pdo = Db::connect(require __DIR__ . '/../config/db.php');
$repo = new UsersRepository($pdo);

$st = $pdo->prepare("SELECT id FROM users WHERE staff_id = ?");
$st->execute(['administrator']);
$id = $st->fetchColumn();

if ($id) {
    $repo->update((int) $id, [
        'name' => '系統管理員', 'email' => null, 'staff_id' => 'administrator',
        'employment_status' => '在職', 'password' => $password, 'is_admin' => true,
    ]);
    echo "已更新管理者 administrator 密碼（在職，系統管理員身份）\n";
} else {
    $newId = $repo->create([
        'name' => '系統管理員', 'email' => '', 'staff_id' => 'administrator',
        'employment_status' => '在職', 'password' => $password, 'is_admin' => true,
    ]);
    $repo->addDepartment((int) $newId, '管理部', true);
    echo "已建立管理者 administrator（管理部／主管，系統管理員身份）\n";
}

<?php
// 前台（public/index.php）與後台（public/admin.php）共用的啟動流程：
// autoload、session、安全標頭、閒置逾時、CSRF 守門、DB 連線、render() 版型輔助。
// 兩個入口都必須各自 require 這支檔案，確保安全前置邏輯只有一份、不會兩邊各自維護一份而日後漂移。

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Polyfills.php';
require_once __DIR__ . '/color_rules.php';

// php.ini 的 date.timezone 未必是 Asia/Taipei（本機曾是 Europe/Berlin），會讓「今天」
// 判定整段位移 6-7 小時，影響本月待辦預設月份／completed_at／提醒信去重／合約剩餘天數。
date_default_timezone_set('Asia/Taipei');

use App\Db;
use App\Auth;
use App\Csrf;

// 僅在 HTTPS 下開啟 secure cookie（HTTP 部署維持關閉，否則 cookie 送不回、登入失效）
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $isHttps]);
session_start();

// 安全標頭
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// 樣板已全面改用外部 assets/app.js + assets/app.css（不再有 inline style／inline script／onXXX 屬性），
// 所以 script-src／style-src 都不需要 'unsafe-inline' 了。
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");

// 閒置逾時（60 分鐘）
if (Auth::check()) {
    $now = time();
    if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > 3600) {
        Auth::logout();
        header('Location: index.php?r=login'); exit;
    }
    $_SESSION['last_activity'] = $now;
}

// CSRF：所有 POST 一律驗 token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    http_response_code(400);
    exit('CSRF 驗證失敗，請重新整理後再試');
}

$cfg = require __DIR__ . '/db.php';
try {
    $pdo = Db::connect($cfg);
} catch (\PDOException $e) {
    http_response_code(503);
    exit('資料庫連線逾時或失敗，請稍後重新整理再試。若持續發生，請檢查網路連線是否穩定。');
}

// 每次請求重驗權限快照：後台把某人降權/停用後，對方既有登入立即失效，不用等 session 過期
Auth::revalidate($pdo);

/** $layout 可傳 'layout'（前台）或 'admin_layout'（後台），各自的版型檔放在 public/views/ */
function render(string $view, array $data, string $layout = 'layout'): void
{
    extract($data);
    ob_start();
    require __DIR__ . '/../public/views/' . $view . '.php';
    $body = ob_get_clean();
    $title = $data['title'] ?? '';
    require __DIR__ . '/../public/views/' . $layout . '.php';
}

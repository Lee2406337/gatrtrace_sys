<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Polyfills.php';

// php.ini 的 date.timezone 未必是 Asia/Taipei，會讓「今天」判定位移，進而影響提醒信去重鍵
date_default_timezone_set('Asia/Taipei');

use App\Db;
use App\SmtpMailer;
use App\ReminderService;

// 排程（schtasks /ru SYSTEM）跑起來沒有人盯著畫面，失敗（連線逾時、SMTP 帳密過期等）
// 原本會直接讓例外冒出、整支腳本安靜地非0結束，沒有人會發現。這裡把成功/失敗都追加寫進
// 一份獨立的 log 檔，方便事後排查「排程到底有沒有跑、跑了結果如何」。
$logFile = __DIR__ . '/../storage/logs/daily_reminder.log';

function log_line(string $file, string $line): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

try {
    $pdo = Db::connect(require __DIR__ . '/../config/db.php');
    $mailer = new SmtpMailer(require __DIR__ . '/../config/mail.php');
    $today = new DateTimeImmutable('today');

    $r = ReminderService::run($pdo, $today, $mailer);
    $summary = sprintf('每日提醒 %s：寄出 %d 封、略過 %d（重複/無 email）', $today->format('Y-m-d'), $r['sent'], $r['skipped']);
    echo $summary . "\n";
    log_line($logFile, 'OK ' . $summary);
} catch (\Throwable $e) {
    $msg = 'FAIL ' . get_class($e) . '：' . $e->getMessage();
    fwrite(STDERR, $msg . "\n");
    log_line($logFile, $msg);
    exit(1);
}

<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// php.ini 的 date.timezone 未必是 Asia/Taipei，會讓 date('Y-m') 預設值判定的「本月」位移
date_default_timezone_set('Asia/Taipei');

use App\Db;
use App\ExpandMonthService;

$cfg = require __DIR__ . '/../config/db.php';
$pdo = Db::connect($cfg);

$arg = $argv[1] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $arg)) {
    fwrite(STDERR, "錯誤：年月格式須為 YYYY-MM（例：2026-08），收到「{$arg}」\n");
    exit(1);
}
[$year, $month] = array_map('intval', explode('-', $arg));

$r = ExpandMonthService::run($pdo, $year, $month);
\App\ChangeLog::record($pdo, '本月待辦', '展開', sprintf('展開 %04d-%02d（系統）', $year, $month));
echo sprintf("展開 %04d-%02d 完成：新增 %d 筆、略過 %d 筆（重複）\n", $year, $month, $r['inserted'], $r['skipped']);

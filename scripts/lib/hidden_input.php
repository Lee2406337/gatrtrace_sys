<?php
// 共用：讀取一行密碼、終端機不回顯任何字元，按 Enter 送出。
// 給 unlock_user.php／seed_admin.php／set_password.php 三支 CLI 腳本共用（純 top-level CLI
// script，不是 PSR-4 自動載入的 App\ 類別，用 require_once 引入函式定義，跟現有慣例一致）。

/** 遮罩輸入需要一個真正的終端機才能互動；呼叫端應先自行檢查 stream_isatty(STDIN)。 */
function read_hidden_password(): string
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'nsu_');
        if ($tmpFile === false) {
            fwrite(STDERR, "無法建立暫存檔。\n");
            exit(1);
        }
        // 保證清理：不論後面正常回傳、proc_open 失敗、或任何例外中途中斷，都不會留下
        // 含明文密碼的暫存檔。@unlink 對已刪除路徑靜默失敗，跟下面「正常路徑」的
        // unlink 重複呼叫是安全、冪等的。
        register_shutdown_function(static function () use ($tmpFile): void {
            @unlink($tmpFile);
        });

        $psPathLiteral = str_replace("'", "''", $tmpFile);
        $setContentLine = "Set-Content -NoNewline -Path '" . $psPathLiteral . "' -Value \$p -Encoding UTF8";
        $script = '$p = \'\'; '
            . 'while ($true) { '
            . '$k = [Console]::ReadKey($true); '
            . 'if ($k.Key -eq [ConsoleKey]::Enter) { break }; '
            . 'if ($k.Key -eq [ConsoleKey]::Backspace) { if ($p.Length -gt 0) { $p = $p.Substring(0, $p.Length - 1) } } '
            . 'else { $p += $k.KeyChar } '
            . '}; '
            . $setContentLine;
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));

        $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $proc = proc_open('powershell -NoProfile -EncodedCommand ' . $encoded, $descriptors, $pipes);
        if (is_resource($proc)) {
            proc_close($proc);
        }

        $password = (string) @file_get_contents($tmpFile);
        @unlink($tmpFile); // 正常路徑立即清理；shutdown function 是保底，不是取代
        if (str_starts_with($password, "\xEF\xBB\xBF")) {
            $password = substr($password, 3);
        }
        return $password;
    }

    system('stty -echo');
    $out = fgets(STDIN);
    system('stty echo');
    echo "\n";
    return rtrim((string) $out, "\r\n");
}

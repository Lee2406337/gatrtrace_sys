<?php
// 用法：先啟動內建伺服器，再跑本檔驗證輸出。
// 1) 另開終端：D:\xampp\php\php.exe -S 127.0.0.1:8899 -t public
// 2) 本檔對該埠發出請求並印出檢查結果。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$base = 'http://127.0.0.1:8899/index.php';

function req(string $url, ?array $post = null, ?array &$hdr = null): string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $res = curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $hdr = ['raw' => substr($res, 0, $size), 'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE)];
    curl_close($ch);
    return substr($res, $size);
}

$ok = true;
// A. 安全標頭
req($base . '?r=login', null, $h);
foreach (['X-Frame-Options: SAMEORIGIN', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: same-origin', 'Content-Security-Policy:'] as $needle) {
    $has = stripos($h['raw'], $needle) !== false;
    echo ($has ? '[PASS]' : '[FAIL]') . " 標頭 $needle\n"; $ok = $ok && $has;
}
// B. 無 token 的 POST 應 400
req($base . '?r=login&action=authenticate', ['staff_id' => 'nobody', 'password' => 'x'], $h);
$is400 = $h['code'] === 400;
echo ($is400 ? '[PASS]' : '[FAIL]') . " 無 CSRF token 的 POST 回 400（實得 {$h['code']}）\n"; $ok = $ok && $is400;

echo $ok ? "\nSMOKE OK\n" : "\nSMOKE FAIL\n";
exit($ok ? 0 : 1);

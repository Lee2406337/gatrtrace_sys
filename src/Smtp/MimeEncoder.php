<?php
namespace App\Smtp;

final class MimeEncoder
{
    // RFC 2047 encoded-word：=?UTF-8?B?<base64>?=。純 ASCII 內容直接原樣回傳，
    // 避免對英文主旨做不必要的編碼。
    public static function encodeHeader(string $text): string
    {
        // 表頭注入防護：header 值不該含 CR/LF，純 ASCII 直通路徑不會像 base64 分支
        // 那樣自動把控制字元編碼掉，這裡先行剔除，兩條路徑都受益。
        $text = str_replace(["\r", "\n"], '', $text);
        if ($text === '' || mb_check_encoding($text, 'ASCII')) {
            return $text;
        }
        // 每段 base64 前的原文最多取 45 bytes（base64 後 60 字元），
        // 加上 "=?UTF-8?B?" 與 "?=" 前後綴，單段仍在 RFC 建議的 75 字元內。
        $maxBytesPerChunk = 45;
        $words = [];
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $chunkLen = min($maxBytesPerChunk, $length - $offset);
            // 避免切斷多位元組 UTF-8 字元：往回收縮直到落在字元邊界（UTF-8 延續 byte 的高兩位是 10）
            while ($chunkLen > 0 && $offset + $chunkLen < $length && (ord($text[$offset + $chunkLen]) & 0xC0) === 0x80) {
                $chunkLen--;
            }
            if ($chunkLen === 0) {
                // 收縮到 0 代表輸入不是合法 UTF-8（一長串延續 byte 找不到字元邊界）。
                // 這種輸入本來就無法做出「正確」的切法，但至少要讓 $offset 前進、避免無窮迴圈。
                $chunkLen = min($maxBytesPerChunk, $length - $offset);
            }
            $words[] = '=?UTF-8?B?' . base64_encode(substr($text, $offset, $chunkLen)) . '?=';
            $offset += $chunkLen;
        }
        return implode("\r\n ", $words);
    }

    public static function encodeBody(string $text): string
    {
        return chunk_split(base64_encode($text), 76, "\r\n");
    }
}

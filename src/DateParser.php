<?php
namespace App;

final class DateParser
{
    /** 可解析回 Y-m-d，否則 null（對齊附錄 10.1 的 ISNUMBER 語意） */
    public static function parse(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        // Excel 中文地區設定常把打的日期自動格式化成「2026年8月11日」，先轉回 YYYY-M-D 再走下面的一般解析
        $raw = preg_replace('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u', '$1-$2-$3', $raw);
        $normalized = str_replace(['/', '.'], '-', $raw);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $dt->format('Y-m-d');
    }
}

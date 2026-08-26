<?php
namespace App;

/**
 * 例行事項基準值（baseline_value）的格式解析單一事實來源。
 * 每個 parseX() 只負責「這段字串格式合不合法、合法的話拆出哪些數字」，
 * 格式不合一律回 null；不負責日期是否落在某年某月（那是 EventExpander 的事），
 * 也不負責組錯誤訊息（那是 BaselineValidator 的事）——EventExpander 與
 * BaselineValidator 都只依賴這裡，格式規則只有一份，不會兩邊各自維護到走鐘。
 */
final class BaselineFormat
{
    /** 「每週」：1–7（1=週一…7=週日） */
    public static function parseWeekday(string $b): ?int
    {
        $b = trim($b);
        if (!ctype_digit($b)) {
            return null;
        }
        $n = (int) $b;
        return ($n >= 1 && $n <= 7) ? $n : null;
    }

    /** 「每月」：EOM 或 1–31 的號數 */
    public static function parseMonthlyDay(string $b): ?array
    {
        $b = trim($b);
        if ($b === 'EOM') {
            return ['eom' => true, 'day' => null];
        }
        if (!ctype_digit($b)) {
            return null;
        }
        $day = (int) $b;
        return ($day >= 1 && $day <= 31) ? ['eom' => false, 'day' => $day] : null;
    }

    /** 單組 "MM-DD"（每年直接用；半年的每一組也用這個） */
    public static function parseMonthDay(string $s): ?array
    {
        if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($s), $m)) {
            return null;
        }
        $mm = (int) $m[1];
        $dd = (int) $m[2];
        return ($mm >= 1 && $mm <= 12 && $dd >= 1 && $dd <= 31) ? ['mm' => $mm, 'dd' => $dd] : null;
    }

    /** 「半年」：恰好兩組 "MM-DD"，以逗號分隔，任一組格式不合則整體視為不合法 */
    public static function parseHalfYear(string $b): ?array
    {
        $parts = explode(',', trim($b));
        if (count($parts) !== 2) {
            return null;
        }
        $out = [];
        foreach ($parts as $p) {
            $pair = self::parseMonthDay($p);
            if ($pair === null) {
                return null;
            }
            $out[] = $pair;
        }
        return $out;
    }

    /** 「2年」／「3年」：完整日期 "YYYY-MM-DD"，且須為真實存在的日期 */
    public static function parseFullDate(string $s): ?array
    {
        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim($s), $m)) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        return checkdate($mo, $d, $y) ? ['y' => $y, 'm' => $mo, 'd' => $d] : null;
    }
}

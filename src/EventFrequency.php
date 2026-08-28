<?php
namespace App;

/**
 * 例行事項／待辦的「頻率」合法值（events_master.frequency 底層存的 ENUM 值）。
 * 注意：合約（contracts_master.frequency）是完全獨立、自由選項的描述欄位（含「每季」
 * 「一次性」這種這裡沒有的值），不受 EventExpander／BaselineValidator 約束，兩者互不相干，
 * 不要把合約表單的頻率清單也改成這個 enum。
 *
 * PHP 7.3 相容：原本是 `enum EventFrequency: string`（PHP 8.1+ 限定語法），7.3 沒有原生
 * enum，改用純靜態工具類 + class 常數（常數本身就是底層字串）。呼叫端原本用
 * `EventFrequency::Weekly->value` 取底層字串，改成常數直接是字串後，所有呼叫端一律拿掉
 * `->value`。tryFrom()/isBaselineOptional()/label() 原本是 enum 的靜態/實例方法；因為呼叫端
 * 會把 tryFrom() 的回傳值當物件呼叫實例方法（例如 `$freq?->isBaselineOptional()`），這裡全部
 * 改成純靜態方法，直接吃/回傳 string（tryFrom() 回傳 ?string 而非 ?self，也一併解決了原本
 * 兩處 `?->` nullsafe 呼叫的相容性問題）。
 */
final class EventFrequency
{
    public const Weekly = '每週';
    public const Monthly = '每月';
    public const OddMonth = '單數月';
    public const EvenMonth = '雙數月';
    public const HalfYear = '半年';
    public const Yearly = '每年';
    public const TwoYear = '2年';
    public const ThreeYear = '3年';
    public const ByContract = '依合約';
    public const Other = '其他';
    public const Irregular = '不定期'; // 已停用（併入「其他」），新增/匯入不再提供此選項，僅為相容既有舊資料保留

    /** 宣告順序（含已停用的 Irregular，放最後）。selectableValues() 用 array_diff 排除它。 */
    private const ALL = [
        self::Weekly, self::Monthly, self::OddMonth, self::EvenMonth, self::HalfYear, self::Yearly,
        self::TwoYear, self::ThreeYear, self::ByContract, self::Other,
        self::Irregular,
    ];

    /** 純靜態工具類，不允許實例化 */
    private function __construct()
    {
    }

    /** @return string[] 新增/匯入可選的頻率值，依宣告順序（排除已停用的 Irregular） */
    public static function selectableValues(): array
    {
        return array_values(array_diff(self::ALL, [self::Irregular]));
    }

    /** $value 是否為合法頻率值：是則原樣回傳該字串，否則回傳 null（取代原本的 tryFrom(): ?self） */
    public static function tryFrom(?string $value): ?string
    {
        return in_array($value, self::ALL, true) ? $value : null;
    }

    /** 這個頻率是否不需要基準值（依合約／其他／不定期）。未知/null 值一律回 false。 */
    public static function isBaselineOptional(?string $value): bool
    {
        return in_array($value, [self::ByContract, self::Other, self::Irregular], true);
    }

    /** 畫面顯示文字（半年/2年/3年顯示成更好讀的每半年/每兩年/每三年，其餘原樣） */
    public static function label(?string $value): string
    {
        switch ($value) {
            case self::HalfYear:
                return '每半年';
            case self::TwoYear:
                return '每兩年';
            case self::ThreeYear:
                return '每三年';
            default:
                return (string) $value;
        }
    }
}

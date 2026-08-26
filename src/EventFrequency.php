<?php
namespace App;

/**
 * 例行事項／待辦的「頻率」合法值（events_master.frequency 底層存的 ENUM 值）。
 * 注意：合約（contracts_master.frequency）是完全獨立、自由選項的描述欄位（含「每季」
 * 「一次性」這種這裡沒有的值），不受 EventExpander／BaselineValidator 約束，兩者互不相干，
 * 不要把合約表單的頻率清單也改成這個 enum。
 */
enum EventFrequency: string
{
    case Weekly = '每週';
    case Monthly = '每月';
    case HalfYear = '半年';
    case Yearly = '每年';
    case TwoYear = '2年';
    case ThreeYear = '3年';
    case ByContract = '依合約';
    case Other = '其他';
    case Irregular = '不定期'; // 已停用（併入「其他」），新增/匯入不再提供此選項，僅為相容既有舊資料保留

    /** @return string[] 新增/匯入可選的頻率值，依宣告順序（排除已停用的 Irregular） */
    public static function selectableValues(): array
    {
        return array_map(
            fn (self $c) => $c->value,
            array_values(array_filter(self::cases(), fn (self $c) => $c !== self::Irregular))
        );
    }

    /** 這個頻率是否不需要基準值（依合約／其他／不定期） */
    public function isBaselineOptional(): bool
    {
        return in_array($this, [self::ByContract, self::Other, self::Irregular], true);
    }

    /** 畫面顯示文字（半年/2年/3年顯示成更好讀的每半年/每兩年/每三年，其餘原樣） */
    public function label(): string
    {
        return match ($this) {
            self::HalfYear => '每半年',
            self::TwoYear => '每兩年',
            self::ThreeYear => '每三年',
            default => $this->value,
        };
    }
}

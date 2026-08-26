<?php
namespace App;

/**
 * 例行事項基準值格式驗證（對齊 events_form 標準格式）。
 * 合格回 null；不合格回繁體中文錯誤訊息。格式規則本身在 BaselineFormat，
 * 這裡只負責「呼叫對應的 parse 方法、失敗時回哪句錯誤訊息」。
 */
final class BaselineValidator
{
    public static function validate(string $frequency, ?string $baseline): ?string
    {
        $b = trim((string) $baseline);
        $freq = EventFrequency::tryFrom($frequency);

        // 這些頻率不需要基準值（可空）；未知頻率字串（$freq 為 null）視為「不是可空頻率」，落到下面繼續驗證
        if ($freq?->isBaselineOptional() ?? false) {
            return null;
        }

        if ($b === '') {
            return '此頻率需填寫基準值。';
        }

        return match ($freq) {
            EventFrequency::Weekly => BaselineFormat::parseWeekday($b) !== null
                ? null : '「每週」基準值須為 1–7（1=週一…7=週日）。',
            EventFrequency::Monthly => BaselineFormat::parseMonthlyDay($b) !== null
                ? null : '「每月」基準值須為 1–31 的號數或 EOM。',
            EventFrequency::Yearly => BaselineFormat::parseMonthDay($b) !== null
                ? null : '「每年」基準值須為 MM-DD 格式（例：07-31）。',
            EventFrequency::HalfYear => BaselineFormat::parseHalfYear($b) !== null
                ? null : '「半年」基準值須為兩組 MM-DD，以逗號分隔（例：03-31,09-30）。',
            EventFrequency::TwoYear, EventFrequency::ThreeYear => BaselineFormat::parseFullDate($b) !== null
                ? null : '「' . $frequency . '」基準值須為 YYYY-MM-DD 格式（例：2025-06-30）。',
            default => null, // 未知頻率（含不合法字串）不阻擋
        };
    }
}

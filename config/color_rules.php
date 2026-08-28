<?php
// 顏色規則單一來源（規格書第 5 節）。回傳 assets/app.css 定義的 CSS class 名稱。

function todo_cell_class(string $status, ?int $remainingDays, string $frequency = ''): string
{
    // 已完成、簽核中都算工作本身已完成（簽核中只是在跑正式簽核流程），一律綠色
    if ($status === '已完成' || $status === '簽核中') {
        return 'cell-green';
    }
    if ($status === '異常') {
        return 'cell-darkgray';
    }
    if ($status === '未開始' || $status === '進行中') {
        if ($remainingDays === null) {
            return '';
        }
        // 「每週」不提前預警，到當天才變色/提醒；含逾期（負數 <= 0）
        if ($frequency === \App\EventFrequency::Weekly) {
            return $remainingDays <= 0 ? 'cell-red' : '';
        }
        if ($remainingDays <= 3) {
            return 'cell-red';   // 含逾期（負數 ≤ 3）
        }
        if ($remainingDays <= 7) {
            return 'cell-yellow';
        }
        return '';
    }
    return '';
}

// 本月待辦清單排序分組（數字越小越前面）。
// 0=使用者本人待簽核（最優先，避免卡關）／1=逾期或3天內到期／2=4-7天內到期／
// 3=一般未到期（>7天，未開始／進行中）／4=已完成或簽核中（非本人待簽）／5=異常
function todo_sort_group(string $status, ?int $remainingDays, bool $canSign): int
{
    if ($canSign) {
        return 0;
    }
    if ($status === '已完成' || $status === '簽核中') {
        return 4;
    }
    if ($status === '異常') {
        return 5;
    }
    if ($remainingDays !== null && $remainingDays <= 3) {
        return 1;
    }
    if ($remainingDays !== null && $remainingDays <= 7) {
        return 2;
    }
    return 3;
}

// 同分組內的頻率排序：合約（類別=合約，不論其自身頻率文字）最前，
// 其餘依 合約>每週>每月>單數月/雙數月>每半年>每年>每兩年>每三年>不定期 排序，未列出的頻率值併入「不定期」（最後）。
function todo_frequency_priority(string $category, string $frequency): int
{
    if ($category === '合約') {
        return 0;
    }
    switch (\App\EventFrequency::tryFrom($frequency)) {
        case \App\EventFrequency::Weekly:
            return 1;
        case \App\EventFrequency::Monthly:
            return 2;
        case \App\EventFrequency::OddMonth:
        case \App\EventFrequency::EvenMonth:
            return 3;
        case \App\EventFrequency::HalfYear:
            return 4;
        case \App\EventFrequency::Yearly:
            return 5;
        case \App\EventFrequency::TwoYear:
            return 6;
        case \App\EventFrequency::ThreeYear:
            return 7;
        default:
            return 8;
    }
}

function contract_cell_class(string $status): string
{
    switch ($status) {
        case '已到期':
        case '30天內':
            return 'cell-red';
        case '31–90天':
            return 'cell-yellow';
        default:
            return '';
    }
}

// 頻率顯示文字統一（底層存值不變：events_master ENUM／表單 value 仍是 半年/2年/3年，
// 只有畫面顯示換成更好讀的「每半年／每兩年／每三年」）。
function frequency_label(string $freq): string
{
    // 合約的頻率是獨立的自由選項欄位（例如「每季」「一次性」），不在 EventFrequency 之列，
    // tryFrom() 找不到就回 null，交由下面的 ?? 原樣顯示——這正是既有行為，不是漏判。
    $resolved = \App\EventFrequency::tryFrom($freq);
    return $resolved !== null ? \App\EventFrequency::label($resolved) : $freq;
}

function calendar_cell_class(bool $hasMonthly, bool $hasYearly, bool $hasContract): string
{
    if ($hasMonthly) {
        return 'cell-lightblue';
    }
    if ($hasYearly) {
        return 'cell-lightgreen';
    }
    if ($hasContract) {
        return 'cell-lightred';
    }
    return '';
}

// 例行事項基準值的友善顯示格式（events_form.php 下拉選單與 events_list.php 清單共用同一套；
// 純顯示用，不影響 baseline_value 實際存值與 BaselineValidator 的驗證邏輯）。

function baseline_pad2(int $n): string
{
    return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
}

function baseline_month_day_label(string $md): string
{
    $bits = explode('-', trim($md));
    if (count($bits) === 2 && ctype_digit($bits[0]) && ctype_digit($bits[1])) {
        return baseline_pad2((int) $bits[0]) . '月' . baseline_pad2((int) $bits[1]) . '號';
    }
    return $md;
}

function baseline_multi_year_label(string $raw): string
{
    $bits = explode('-', $raw);
    if (count($bits) === 3 && ctype_digit($bits[1]) && ctype_digit($bits[2])) {
        return $bits[0] . '年' . baseline_pad2((int) $bits[1]) . '月' . baseline_pad2((int) $bits[2]) . '號';
    }
    return $raw;
}

function baseline_value_label(string $frequency, ?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    switch (\App\EventFrequency::tryFrom($frequency)) {
        case \App\EventFrequency::Weekly:
            return ctype_digit($raw) ? '週' . baseline_pad2((int) $raw) : $raw;
        case \App\EventFrequency::Monthly:
        case \App\EventFrequency::OddMonth:
        case \App\EventFrequency::EvenMonth:
            return $raw === 'EOM' ? '月底（EOM）' : (ctype_digit($raw) ? baseline_pad2((int) $raw) . '號' : $raw);
        case \App\EventFrequency::Yearly:
            return baseline_month_day_label($raw);
        case \App\EventFrequency::HalfYear:
            return implode('、', array_map('baseline_month_day_label', explode(',', $raw)));
        case \App\EventFrequency::TwoYear:
        case \App\EventFrequency::ThreeYear:
            return baseline_multi_year_label($raw);
        default:
            // 不定期／依合約／其他，或格式不明的既有資料：原樣顯示，不隱藏異常值
            return $raw;
    }
}

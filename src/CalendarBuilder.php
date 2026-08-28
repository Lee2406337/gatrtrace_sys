<?php
namespace App;

require_once __DIR__ . '/../config/color_rules.php';

final class CalendarBuilder
{
    private const MONTHLY = [EventFrequency::Weekly, EventFrequency::Monthly, EventFrequency::OddMonth, EventFrequency::EvenMonth];
    private const YEARLY  = [EventFrequency::Yearly, EventFrequency::HalfYear, EventFrequency::TwoYear, EventFrequency::ThreeYear];
    private const UNDATED = [EventFrequency::Irregular, EventFrequency::ByContract, EventFrequency::Other];

    public function build(int $year, int $month, array $events, array $contracts): array
    {
        $expander = new EventExpander();
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        // date('N') 是 ISO 星期一=1..星期日=7；畫面比照 Windows 行事曆表頭「日一二三四五六」
        // （星期日排最前面），轉成 1=日..7=六，跟表頭欄位順序對齊
        $isoWeekday = (int) date('N', mktime(0, 0, 0, $month, 1, $year));
        $firstWeekday = ($isoWeekday % 7) + 1;

        $flags = [];
        $cells = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $cells[$d] = ['items' => [], 'class' => ''];
            $flags[$d] = ['monthly' => false, 'yearly' => false, 'contract' => false];
        }

        $undated = [];
        foreach ($events as $e) {
            $freq = $e['frequency'];
            if (in_array($freq, self::UNDATED, true)) {
                $undated[] = [
                    'type' => $freq,
                    'name' => $e['task_name'],
                    'note' => (string) ($e['note'] ?? ''),
                ];
                continue;
            }
            foreach ($expander->expand($freq, $e['baseline_value'], $year, $month) as $ymd) {
                $day = (int) substr($ymd, 8, 2);
                if (!isset($cells[$day])) {
                    continue;
                }
                $cells[$day]['items'][] = "【{$e['category']}】{$e['task_name']}";
                if (in_array($freq, self::MONTHLY, true)) {
                    $flags[$day]['monthly'] = true;
                } elseif (in_array($freq, self::YEARLY, true)) {
                    $flags[$day]['yearly'] = true;
                }
            }
        }

        foreach ($contracts as $c) {
            $ed = $c['end_date'] ?? null;
            if ($ed === null || $ed === '') {
                continue;
            }
            if ((int) substr($ed, 0, 4) === $year && (int) substr($ed, 5, 2) === $month) {
                $day = (int) substr($ed, 8, 2);
                if (isset($cells[$day])) {
                    $cells[$day]['items'][] = "【合約】{$c['contract_name']}";
                    $flags[$day]['contract'] = true;
                }
            }
        }

        foreach ($cells as $d => $_) {
            $cells[$d]['class'] = calendar_cell_class(
                $flags[$d]['monthly'],
                $flags[$d]['yearly'],
                $flags[$d]['contract']
            );
        }

        return [
            'year' => $year,
            'month' => $month,
            'daysInMonth' => $daysInMonth,
            'firstWeekday' => $firstWeekday,
            'cells' => $cells,
            'undated' => $undated,
        ];
    }
}

<?php
namespace App;

use App\Repository\MonthlyTodosRepository;
use App\Repository\EventsRepository;
use App\Repository\ContractsRepository;

final class ExpandMonthService
{
    /**
     * 合約提醒視窗的起始月份（到期前一個月）。純日期運算，抽成獨立方法方便單元測試。
     * 不能直接對帶日期的字串 modify('-1 month')——到期日是 29/30/31 號、上個月天數
     * 不足時（例如 3/31、5/31、10/31、12/31）PHP 會把日期溢位回到同一個月而非真的
     * 往前一個月。改成先取到期月份的月初、再往前一天，不受天數溢位影響。
     */
    public static function reminderWindowStartYm(string $endDate): string
    {
        return (new \DateTimeImmutable($endDate . ' 00:00:00'))
            ->modify('first day of this month')->modify('-1 day')->format('Y-m');
    }

    /**
     * 每月批次展開的協調者，依序做三件各自獨立的事：
     * 1) 例行事項展開成當月待辦（expandRoutineEvents）
     * 2) 合約到期提醒的自我修復（healStaleContractReminders）
     * 3) 合約到期提醒展開（expandContractReminders）
     * @return array{inserted:int, skipped:int}
     */
    public static function run(\PDO $pdo, int $year, int $month): array
    {
        $todos = new MonthlyTodosRepository($pdo);
        $ym = sprintf('%04d-%02d', $year, $month);

        [$insertedEvents, $skippedEvents] = self::expandRoutineEvents($pdo, $todos, $ym, $year, $month);
        self::healStaleContractReminders($pdo);
        self::healStaleEventReminders($pdo);
        [$insertedContracts, $skippedContracts] = self::expandContractReminders($pdo, $todos, $ym, $year, $month);

        return [
            'inserted' => $insertedEvents + $insertedContracts,
            'skipped'  => $skippedEvents + $skippedContracts,
        ];
    }

    /**
     * 職責一：把 events_master 依頻率展開成當月的 monthly_todos。頻率「其他」（含舊資料
     * 「不定期」）沒有固定日期可展開，改成「若還沒有未結案的紀錄，就建立一筆未指定日期的
     * 提醒」，只在使用者按重新整理時觸發，跟其他頻率一致；其餘頻率維持既有的多日期展開邏輯。
     */
    private static function expandRoutineEvents(\PDO $pdo, MonthlyTodosRepository $todos, string $ym, int $year, int $month): array
    {
        $expander = new EventExpander();
        $inserted = 0;
        $skipped = 0;

        $events = (new EventsRepository($pdo))->all();
        foreach ($events as $e) {
            if (in_array($e['frequency'], [EventFrequency::Other, EventFrequency::Irregular], true)) {
                if ($todos->hasOpenInstanceForEvent((int) $e['id'])) {
                    $skipped++;
                    continue;
                }
                $todos->create([
                    'source_event_id' => (int) $e['id'],
                    'year_month'      => $ym,
                    'category'        => $e['category'],
                    'task_name'       => $e['task_name'],
                    'frequency'       => $e['frequency'],
                    'department'      => $e['department'],
                    'due_date'        => null,
                ]);
                $inserted++;
                continue;
            }
            $dates = $expander->expand($e['frequency'], $e['baseline_value'], $year, $month);
            foreach ($dates as $due) {
                $ok = $todos->upsertExpanded([
                    'source_event_id' => (int) $e['id'],
                    'year_month'      => $ym,
                    'category'        => $e['category'],
                    'task_name'       => $e['task_name'],
                    'frequency'       => $e['frequency'],
                    'department'      => $e['department'],
                    'due_date'        => $due,
                ]);
                $ok ? $inserted++ : $skipped++;
            }
        }

        return [$inserted, $skipped];
    }

    /**
     * 職責二（自我修復）：合約若「目前到期日對應的那一筆」提醒已完成或已進入簽核流程，清掉
     * 該合約其他仍未完成的提醒待辦（不論落在哪個月份）。用 due_date = 合約目前 end_date 精準
     * 比對這一筆是否為「真正到期日」的紀錄，而不是任何一筆——避免只是提早把「到期前預警」
     * 那筆完成，就永久誤判整份合約已處理完畢（見 expandContractReminders 的 doneCheck 說明）。
     * status 同時涵蓋「簽核中」：一旦使用者已提交簽核，該筆本身會由簽核提醒信持續追蹤進度，
     * 不該再讓下個月的展開又生出第二張一模一樣到期日的待辦卡片。
     */
    private static function healStaleContractReminders(\PDO $pdo): void
    {
        (new MonthlyTodosRepository($pdo))->deleteStaleContractReminders();
    }

    /** 職責二之二（自我修復）：事件頻率若已不再是「其他／不定期」，清掉當初為它建立、還沒結案的未指定日期待辦。 */
    private static function healStaleEventReminders(\PDO $pdo): void
    {
        (new MonthlyTodosRepository($pdo))->deleteStaleEventReminders();
    }

    /**
     * 職責三：合約到期提醒。從到期前一個月起，只要「目前到期日對應的那一筆」還沒完成也還沒
     * 進入簽核流程，每次展開當月都會（重新）產生一筆提醒待辦，直到有人處理為止（不設上限月數）。
     * 判斷「已處理」用 due_date = 合約目前 end_date 精準比對（到期當月/逾期時 due_date 就是
     * end_date 本身），而非合約史上任何一筆——否則完成提早的「到期前預警」那筆，或日後續約把
     * end_date 往後延，都會被誤判成整份合約已處理完畢、永遠不再提醒。狀態同時涵蓋「簽核中」：
     * 已送出簽核就不該再生出第二張到期日相同的重複待辦卡片（見 healStaleContractReminders 說明）。
     */
    private static function expandContractReminders(\PDO $pdo, MonthlyTodosRepository $todos, string $ym, int $year, int $month): array
    {
        $inserted = 0;
        $skipped = 0;
        $daysInThisMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $contractsRepo = new ContractsRepository($pdo);

        // 合約只記部門（不記特定負責人），直接沿用 contracts_master.department
        $contracts = $contractsRepo->withEndDate();
        foreach ($contracts as $c) {
            $windowStartYm = self::reminderWindowStartYm($c['end_date']);
            if ($ym < $windowStartYm) {
                continue; // 還沒進入提醒視窗（到期前一個月）
            }
            if ($todos->hasCompletedContractReminder((int) $c['id'], $c['end_date'])) {
                continue; // 已有一筆完成紀錄，不再產生新的提醒
            }
            $endYm = substr($c['end_date'], 0, 7);
            if ($ym > $endYm) {
                // 已逾期（超過到期月份）：應完成日固定為真正的到期日，準確反映逾期天數，不隨展開月份往後飄移
                $due = $c['end_date'];
            } else {
                // 到期前一個月／到期當月：應完成日 = 到期日的「日」數落在本次展開的月份
                $day = min((int) substr($c['end_date'], 8, 2), $daysInThisMonth);
                $due = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            $ok = $todos->upsertContractReminder([
                'source_contract_id' => (int) $c['id'],
                'year_month'         => $ym,
                'category'           => '合約',
                'task_name'          => $c['contract_name'],
                'frequency'          => mb_substr((string) ($c['frequency'] ?? ''), 0, 20),
                'department'         => $c['department'],
                'due_date'           => $due,
            ]);
            $ok ? $inserted++ : $skipped++;
        }

        return [$inserted, $skipped];
    }
}

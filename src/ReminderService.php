<?php
namespace App;

use App\Repository\ReminderLogRepository;
use App\Repository\ApprovalStepsRepository;
use App\Repository\ApprovalsRepository;
use App\Repository\MonthlyTodosRepository;
use App\Repository\ContractsRepository;
use App\Repository\UsersRepository;

final class ReminderService
{
    /** @return array{sent:int, skipped:int} */
    public static function run(\PDO $pdo, \DateTimeImmutable $today, Mailer $mailer): array
    {
        $ym = $today->format('Y-m');
        $log = new ReminderLogRepository($pdo);
        $todosRepo = new MonthlyTodosRepository($pdo);
        $contractsRepo = new ContractsRepository($pdo);
        $usersRepo = new UsersRepository($pdo);

        // 本月待辦（部門制：廣播給該部門所有在職人員，一筆待辦對多個人各產生一列，
        // 交由下面的 ReminderCollector 依 email 分組聚合，同一人收到的多筆會合併成一封信）
        $todos = $todosRepo->broadcastRecipients($ym);

        // 合約（部門制：廣播給該部門所有在職人員，同一份邏輯跟本月待辦一致）+ 即時算狀態
        $contractsRaw = $contractsRepo->broadcastRecipients();
        $cs = new ContractStatus();
        $contracts = [];
        foreach ($contractsRaw as $c) {
            $ev = $cs->evaluate($c['contract_name'], $c['end_date_raw'], $c['end_date'], $today);
            $contracts[] = $c + ['status' => $ev['status'], 'remaining' => $ev['remaining']];
        }

        // 待簽核：提醒對象是「目前關卡的簽核人」本人（非整個部門），全部合成一封簽核信，
        // 不分部門；department 只用來在信裡標明每筆項目本身屬於哪個單位
        $stepsRepo = new ApprovalStepsRepository($pdo);
        $apRepo = new ApprovalsRepository($pdo);
        $steps = $stepsRepo->all();
        $signings = [];
        foreach ($todosRepo->pendingSigning($ym) as $pt) {
            $tid = (int) $pt['id'];
            $build = $apRepo->buildFor($tid, $steps);
            $currentStep = $build['current_step'];
            if (!$currentStep) {
                continue;
            }
            $stepEntry = ApprovalWorkflow::stepEntry($build, (int) $currentStep);
            $signerId = $stepEntry['resolved_user_id'] ?? null;
            if (!$signerId) {
                continue;
            }
            $signer = $usersRepo->findActiveContact($signerId);
            if (!$signer || !$signer['email']) {
                continue;
            }
            $signings[] = [
                'category' => $pt['category'], 'task_name' => $pt['task_name'], 'due_date' => $pt['due_date'],
                'step_label' => $stepEntry['label'] ?? '', 'email' => $signer['email'], 'department' => $pt['department'],
                'signer_label' => $signer['name'],
            ];
        }

        // 未指定日期事項（頻率「其他」等）：不分月份，跟「本月待辦」頁面共用同一份查詢
        $undated = $todosRepo->openUndated();

        $collected = (new ReminderCollector())->collect($todos, $contracts, $signings, $undated, $today);
        $formatter = new ReminderFormatter();
        $dateStr = $today->format('Y-m-d');
        $sent = 0;
        $skipped = $collected['skipped'];

        foreach ($collected['bundles'] as $bundle) {
            $email = $bundle['email'];
            $department = $bundle['department'];
            if ($log->alreadySent($dateStr, $email, $department, 'todo')) {
                $skipped++;
                continue;
            }
            $msg = $formatter->format($bundle);
            if ($mailer->send($email, $msg['subject'], $msg['body'])) {
                $log->record($dateStr, $email, $department, count($bundle['todos']) + count($bundle['contracts']), 'todo');
                $sent++;
            }
        }

        // 簽核提醒獨立成一封信，跟上面的待辦/合約提醒分開寄送與去重；
        // 不分部門合成一封，去重鍵的 department 固定傳 null
        foreach ($collected['signing_bundles'] as $bundle) {
            $email = $bundle['email'];
            if ($log->alreadySent($dateStr, $email, null, 'signing')) {
                $skipped++;
                continue;
            }
            $msg = $formatter->formatSigning($bundle);
            if ($mailer->send($email, $msg['subject'], $msg['body'])) {
                $log->record($dateStr, $email, null, count($bundle['signings']), 'signing');
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}

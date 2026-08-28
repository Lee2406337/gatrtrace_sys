<?php
namespace App;

use App\Repository\ReminderLogRepository;
use App\Repository\ApprovalStepsRepository;
use App\Repository\ApprovalsRepository;
use App\Repository\MonthlyTodosRepository;
use App\Repository\ContractsRepository;
use App\Repository\UsersRepository;
use App\Repository\ExtraRecipientRepository;

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

        // 已完成通知（「通知」關卡）：不用等前面 approve 關卡簽完，狀態「已完成」或「簽核中」
        // 的待辦都要檢查；同一筆待辦可能有多個通知關卡，逐一檢查是否已經寄過（見 alreadyNotified()）
        $notifications = [];
        foreach ($todosRepo->pendingNotificationCandidates($ym) as $pt) {
            $tid = (int) $pt['id'];
            $build = $apRepo->buildFor($tid, $steps);
            foreach ($build['steps'] as $st) {
                if (($st['step_kind'] ?? 'approve') !== 'notify') {
                    continue;
                }
                $targetId = $st['notify_target_user_id'];
                if ($targetId === null || $apRepo->alreadyNotified($tid, $st['step_order'])) {
                    continue;
                }
                $recipient = $usersRepo->findActiveContact($targetId);
                if (!$recipient || !$recipient['email']) {
                    continue;
                }
                $notifications[] = [
                    'todo_id' => $tid, 'step_order' => $st['step_order'], 'step_label' => $st['label'],
                    'category' => $pt['category'], 'task_name' => $pt['task_name'], 'due_date' => $pt['due_date'],
                    'completed_at' => $pt['completed_at'], 'department' => $pt['department'],
                    'email' => $recipient['email'], 'recipient_label' => $recipient['name'],
                ];
            }
        }

        $collected = (new ReminderCollector())->collect($todos, $contracts, $signings, $undated, $today, $notifications);
        $formatter = new ReminderFormatter();
        $dateStr = $today->format('Y-m-d');
        $sent = 0;
        $skipped = $collected['skipped'];

        $extraRepo = new ExtraRecipientRepository($pdo);
        foreach ($collected['bundles'] as $bundle) {
            $email = $bundle['email'];
            $department = $bundle['department'];
            $itemCount = count($bundle['todos']) + count($bundle['contracts']);
            if (!$log->alreadySent($dateStr, $email, $department, 'todo')) {
                $msg = $formatter->format($bundle);
                if ($mailer->send($email, $msg['subject'], $msg['body'])) {
                    $log->record($dateStr, $email, $department, $itemCount, 'todo');
                    $sent++;
                }
            } else {
                $skipped++;
            }

            // 額外收件人：同一部門提醒信也要副本給誰（後台設定），內容跟上面完全一樣，
            // 靠 reminder_log 同一套 (date,email,department,'todo') 去重——如果額外收件人
            // 本來就是該部門在職人員、已經收過自己那份廣播信，這裡會被自然擋掉不重複寄
            foreach ($extraRepo->forDepartment($department) as $rule) {
                $extraUserId = $extraRepo->resolveRecipient($rule, $usersRepo);
                if ($extraUserId === null) {
                    continue;
                }
                $extra = $usersRepo->findActiveContact($extraUserId);
                if (!$extra || !$extra['email']) {
                    continue;
                }
                if ($log->alreadySent($dateStr, $extra['email'], $department, 'todo')) {
                    continue;
                }
                $msg = $formatter->format($bundle);
                if ($mailer->send($extra['email'], $msg['subject'], $msg['body'])) {
                    $log->record($dateStr, $extra['email'], $department, $itemCount, 'todo');
                    $sent++;
                }
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

        // 已完成通知獨立成一封信，跟簽核提醒同一種模式；去重不靠 reminder_log（那是「今天寄過」），
        // 而是靠 approval_log 的永久紀錄（「這輩子寄過」），已經在組 $notifications 時濾過一輪，
        // 這裡寄出成功後才補寫 approval_log，避免寄送失敗卻誤記成已通知、明天就漏寄
        foreach ($collected['notify_bundles'] as $bundle) {
            $email = $bundle['email'];
            $msg = $formatter->formatNotify($bundle);
            if ($mailer->send($email, $msg['subject'], $msg['body'])) {
                foreach ($bundle['notifications'] as $n) {
                    $apRepo->recordNotified((int) $n['todo_id'], (int) $n['step_order'], (string) $n['step_label']);
                }
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}

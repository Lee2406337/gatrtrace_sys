<?php
namespace App;

require_once __DIR__ . '/../config/color_rules.php';

final class ReminderCollector
{
    /** @return array{bundles: array<string,array>, signing_bundles: array<string,array>, skipped: int} */
    public function collect(array $todos, array $contracts, array $signings, array $undated, \DateTimeImmutable $today): array
    {
        $bundles = [];
        $signingBundles = [];
        $skipped = 0;

        foreach ($todos as $t) {
            $email = (string) ($t['responsible_email'] ?? '');
            $department = (string) ($t['department'] ?? '');
            if ($email === '' || $t['due_date'] === null) {
                if ($email === '') { $skipped++; }
                continue;
            }
            $remaining = (int) $today->diff(new \DateTimeImmutable($t['due_date']))->format('%r%a');
            $cls = todo_cell_class($t['status'], $remaining);
            if (!in_array($t['status'], ['未開始', '進行中', '簽核中'], true)) {
                continue;
            }
            if ($cls === 'cell-red') {
                $urgency = 'immediate';
            } elseif ($cls === 'cell-yellow') {
                $urgency = 'soon';
            } else {
                continue; // 未變色，不提醒
            }
            $key = $this->ensureBundle($bundles, $email, $department, (string) $t['responsible_label']);
            // 同一部門若有多個帳號共用同一 email（例如職務代理），廣播查詢會對同一筆
            // 待辦產生多列；同一封信裡同一筆待辦只列一次，依 todo_id 去重
            $todoId = $t['todo_id'] ?? null;
            if ($todoId !== null && isset($bundles[$key]['seen_todo_ids'][$todoId])) {
                continue;
            }
            if ($todoId !== null) {
                $bundles[$key]['seen_todo_ids'][$todoId] = true;
            }
            $bundles[$key]['todos'][] = $t + ['urgency' => $urgency, 'remaining' => $remaining];
        }

        foreach ($contracts as $c) {
            if (($c['status'] ?? '') !== '30天內') {
                continue;
            }
            $email = (string) ($c['responsible_email'] ?? '');
            $department = (string) ($c['department'] ?? '');
            if ($email === '' || $department === '') { $skipped++; continue; }
            $key = $this->ensureBundle($bundles, $email, $department, (string) $c['responsible_label']);
            // 合約也是部門廣播，同部門多帳號共用 email 時會對同一份合約產生多列；
            // 同一封信裡同一份合約只列一次，依 contract_id 去重（跟待辦的 todo_id 去重同理）
            $contractId = $c['contract_id'] ?? null;
            if ($contractId !== null && isset($bundles[$key]['seen_contract_ids'][$contractId])) {
                continue;
            }
            if ($contractId !== null) {
                $bundles[$key]['seen_contract_ids'][$contractId] = true;
            }
            $bundles[$key]['contracts'][] = $c;
        }

        // 待簽核：另外獨立成一封「簽核提醒」信，不跟待辦/合約的提醒信合併，也不依部門分開——
        // 同一人不管總務還環安的簽核都合成同一封，提醒對象是目前關卡的簽核人本人，
        // 不受到期日遠近限制（卡在簽核本身就該提醒）
        foreach ($signings as $sg) {
            $email = (string) ($sg['email'] ?? '');
            if ($email === '') { $skipped++; continue; }
            if (!isset($signingBundles[$email])) {
                $signingBundles[$email] = ['name' => (string) $sg['signer_label'], 'email' => $email, 'signings' => []];
            }
            $signingBundles[$email]['signings'][] = $sg;
        }

        // 未指定日期事項（頻率「其他」等）：只附加在「本來就會寄出」的部門信件最下面，
        // 不為了單純有未指定日期事項而新增收件人（不會在這裡呼叫 ensureBundle 新建 bundle）。
        $undatedByDept = [];
        foreach ($undated as $u) {
            $undatedByDept[(string) ($u['department'] ?? '')][] = $u;
        }
        foreach ($bundles as $key => &$bundle) {
            $dept = $bundle['department'];
            if (!empty($undatedByDept[$dept])) {
                $bundle['undated'] = $undatedByDept[$dept];
            }
        }
        unset($bundle);

        return ['bundles' => $bundles, 'signing_bundles' => $signingBundles, 'skipped' => $skipped];
    }

    // 分開寄：同一人身兼多部門會收到多封信，各自只含該部門的項目——
    // 分組鍵是 email+department（而非單純 email），回傳這個鍵供呼叫端接續操作
    private function ensureBundle(array &$bundles, string $email, string $department, string $label): string
    {
        $key = $email . '|' . $department;
        if (!isset($bundles[$key])) {
            $bundles[$key] = [
                'name' => $label, 'email' => $email, 'department' => $department,
                'todos' => [], 'contracts' => [], 'signings' => [], 'undated' => [],
                'seen_todo_ids' => [], 'seen_contract_ids' => [],
            ];
        }
        return $key;
    }
}

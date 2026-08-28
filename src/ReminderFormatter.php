<?php
namespace App;

final class ReminderFormatter
{
    /** @return array{subject: string, body: string} */
    public function format(array $bundle): array
    {
        $count = count($bundle['todos']) + count($bundle['contracts']);
        $subject = sprintf('【總務環安待辦系統】【%s待辦提醒】今日待辦 %d 筆', $bundle['department'], $count);

        $lines = [];
        $lines[] = "以下為【{$bundle['department']}】今日提醒事項：";
        $lines[] = '';

        foreach (['進行中', '未開始', '簽核中'] as $status) {
            $inStatus = array_values(array_filter($bundle['todos'], function ($t) use ($status) {
                return $t['status'] === $status;
            }));
            if (!$inStatus) {
                continue;
            }
            $lines[] = "■ {$status}";
            foreach (['immediate' => '需立即處理（剩餘 ≤3 天）', 'soon' => '需盡快處理（剩餘 4–7 天）'] as $urg => $title) {
                $inUrg = array_values(array_filter($inStatus, function ($t) use ($urg) {
                    return $t['urgency'] === $urg;
                }));
                if (!$inUrg) {
                    continue;
                }
                $lines[] = "  ‧ {$title}";
                foreach ($inUrg as $t) {
                    $lines[] = sprintf('     - 【%s】%s（頻率：%s，單位：%s，應完成日：%s）', $t['category'], $t['task_name'], $t['frequency'], $t['department'], $t['due_date']);
                }
            }
            $lines[] = '';
        }

        if (!empty($bundle['contracts'])) {
            $lines[] = '■ 合約到期提醒（30天內）';
            foreach ($bundle['contracts'] as $c) {
                $lines[] = sprintf('     - %s（到期日：%s，剩餘 %d 天）', $c['contract_name'], $c['end_date'], (int) $c['remaining']);
            }
            $lines[] = '';
        }

        if (!empty($bundle['undated'])) {
            $lines[] = '■ 未指定日期事項';
            foreach ($bundle['undated'] as $u) {
                $lines[] = sprintf('     - 【%s】%s（頻率：%s，單位：%s）', $u['category'], $u['task_name'], $u['frequency'], $u['department']);
            }
            $lines[] = '';
        }

        return ['subject' => $subject, 'body' => implode("\n", $lines)];
    }

    /** @return array{subject: string, body: string} */
    public function formatSigning(array $bundle): array
    {
        $count = count($bundle['signings']);
        $subject = sprintf('【總務環安待辦系統】【簽核提醒】待簽核 %d 筆', $count);

        $lines = [];
        $lines[] = '以下為待你簽核的項目：';
        $lines[] = '';
        // 不分部門，一封信合併總務／環安／管理部所有待你簽核的項目，每行標明所屬單位
        foreach ($bundle['signings'] as $sg) {
            $lines[] = sprintf('     - 【%s】%s（單位：%s，%s，應完成日：%s）', $sg['category'], $sg['task_name'], $sg['department'], $sg['step_label'], (string) ($sg['due_date'] ?? '未指定'));
        }
        $lines[] = '';

        return ['subject' => $subject, 'body' => implode("\n", $lines)];
    }

    /** @return array{subject: string, body: string} */
    public function formatNotify(array $bundle): array
    {
        $count = count($bundle['notifications']);
        $subject = sprintf('【總務環安待辦系統】【已完成通知】%d 筆', $count);

        $lines = [];
        $lines[] = '以下項目已完成，通知您知悉：';
        $lines[] = '';
        foreach ($bundle['notifications'] as $n) {
            $lines[] = sprintf('     - 【%s】%s（單位：%s，%s，應完成日：%s，實際完成日：%s）', $n['category'], $n['task_name'], $n['department'], $n['step_label'], (string) ($n['due_date'] ?? '未指定'), (string) ($n['completed_at'] ?? '未指定'));
        }
        $lines[] = '';

        return ['subject' => $subject, 'body' => implode("\n", $lines)];
    }
}

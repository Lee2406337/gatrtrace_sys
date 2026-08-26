<?php
namespace App;

final class ContractStatus
{
    /**
     * @return array{remaining: ?int, status: string, suggestion: string}
     */
    public function evaluate(?string $name, ?string $endRaw, ?string $endDate, \DateTimeImmutable $today): array
    {
        if (trim((string) $name) === '') {
            return ['remaining' => null, 'status' => '', 'suggestion' => ''];
        }

        $remaining = null;
        if (trim((string) $endRaw) === '') {
            $status = '未填到期日';
        } elseif ($endDate === null) {
            $status = '需確認';
        } else {
            $end = new \DateTimeImmutable($endDate);
            $remaining = (int) $today->diff($end)->format('%r%a');
            if ($remaining < 0) {
                $status = '已到期';
            } elseif ($remaining <= 30) {
                $status = '30天內';
            } elseif ($remaining <= 90) {
                $status = '31–90天';
            } else {
                $status = '>90天';
            }
        }

        $suggestion = match ($status) {
            '需確認'   => '請確認到期日格式',
            '未填到期日' => '請補上合約迄日',
            '已到期'   => '請確認是否已續約或結案',
            '30天內'   => '立即啟動續約／請款／採購流程',
            '31–90天'  => '安排續約詢價與內部確認',
            '>90天'    => '持續追蹤',
            default    => '',
        };

        return ['remaining' => $remaining, 'status' => $status, 'suggestion' => $suggestion];
    }
}

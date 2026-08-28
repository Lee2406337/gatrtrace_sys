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

        return ['remaining' => $remaining, 'status' => $status, 'suggestion' => self::suggestionFor($status)];
    }

    /** 拆成獨立方法、每個 case 直接 return，跟其餘 match()→switch 改寫維持同一種寫法，
     *  避免 break-then-fallthrough-if-forgotten 這種日後加 case 容易漏掉的風險。 */
    private static function suggestionFor(string $status): string
    {
        switch ($status) {
            case '需確認':
                return '請確認到期日格式';
            case '未填到期日':
                return '請補上合約迄日';
            case '已到期':
                return '請確認是否已續約或結案';
            case '30天內':
                return '立即啟動續約／請款／採購流程';
            case '31–90天':
                return '安排續約詢價與內部確認';
            case '>90天':
                return '持續追蹤';
            default:
                return '';
        }
    }
}

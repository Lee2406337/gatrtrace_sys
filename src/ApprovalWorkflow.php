<?php
namespace App;

final class ApprovalWorkflow
{
    /** @return array{steps:array,current_step:?int,status:string} */
    public function build(array $steps, ?int $deptManagerId, ?int $adminManagerId, array $userActive, array $signed, ?int $responsibleUserId = null): array
    {
        usort($steps, fn($a, $b) => (int) $a['step_order'] <=> (int) $b['step_order']);
        $out = [];
        foreach ($steps as $s) {
            $order = (int) $s['step_order'];
            $resolved = null;
            if ($s['signer_kind'] === 'role') {
                if ($s['signer_value'] === '部門主管') {
                    $resolved = $deptManagerId;
                } elseif ($s['signer_value'] === '管理部主管') {
                    $resolved = $adminManagerId;
                }
            } elseif ($s['signer_kind'] === 'user') {
                $uid = (int) $s['signer_value'];
                $resolved = !empty($userActive[$uid]) ? $uid : null;
            }
            // 負責人本人不可簽核自己的待辦：該關視為無在職簽核人而跳過
            if ($responsibleUserId !== null && $resolved === $responsibleUserId) {
                $resolved = null;
            }
            $isSigned = array_key_exists($order, $signed);
            $out[] = [
                'step_order' => $order,
                'label' => $s['label'],
                'resolved_user_id' => $resolved,
                'skipped' => $resolved === null,
                'signed' => $isSigned,
                'signer_user_id' => $isSigned ? (int) $signed[$order] : null,
            ];
        }

        $current = null;
        foreach ($out as $st) {
            if (!$st['skipped'] && !$st['signed']) {
                $current = $st['step_order'];
                break;
            }
        }

        $nonSkipped = array_filter($out, fn($st) => !$st['skipped']);
        $signedCount = count(array_filter($nonSkipped, fn($st) => $st['signed']));
        if (count($nonSkipped) === 0 || $signedCount === count($nonSkipped)) {
            $status = '已簽核';
        } elseif ($signedCount > 0) {
            $status = '簽核中';
        } else {
            $status = '未簽核';
        }

        return ['steps' => $out, 'current_step' => $current, 'status' => $status];
    }

    public static function canSign(array $build, int $userId, int $stepOrder): bool
    {
        if ($build['current_step'] !== $stepOrder) {
            return false;
        }
        foreach ($build['steps'] as $st) {
            if ($st['step_order'] === $stepOrder) {
                return !$st['skipped'] && !$st['signed'] && $st['resolved_user_id'] === $userId;
            }
        }
        return false;
    }

    /** 從 build() 的結果找出某關卡的完整資訊（label/resolved_user_id/signed/skipped...），找不到回 null */
    public static function stepEntry(array $build, int $stepOrder): ?array
    {
        foreach ($build['steps'] as $st) {
            if ((int) $st['step_order'] === $stepOrder) {
                return $st;
            }
        }
        return null;
    }

    /** 從 build() 的結果找出某關卡的顯示名稱，找不到回空字串 */
    public static function labelForStep(array $build, int $stepOrder): string
    {
        return (string) (self::stepEntry($build, $stepOrder)['label'] ?? '');
    }

    public static function canRecall(array $build, int $userId, int $stepOrder): bool
    {
        $maxSigned = null;
        $signerOfStep = null;
        foreach ($build['steps'] as $st) {
            if ($st['signed']) {
                if ($maxSigned === null || $st['step_order'] > $maxSigned) {
                    $maxSigned = $st['step_order'];
                }
                if ($st['step_order'] === $stepOrder) {
                    $signerOfStep = $st['signer_user_id'];
                }
            }
        }
        return $signerOfStep === $userId && $maxSigned === $stepOrder;
    }
}

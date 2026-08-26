<?php
namespace App\Repository;

final class ApprovalsRepository
{
    public function __construct(private \PDO $pdo) {}

    public function forTodo(int $todoId): array
    {
        $st = $this->pdo->prepare("SELECT step_order, signer_user_id FROM approvals WHERE monthly_todo_id = ?");
        $st->execute([$todoId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['step_order']] = (int) $r['signer_user_id'];
        }
        return $out;
    }

    public function sign(int $todoId, int $stepOrder, int $signerId, ?string $opinion, string $stepLabel, string $actorName): void
    {
        // INSERT IGNORE：併發時撞 UNIQUE(monthly_todo_id, step_order) 靜默略過，避免 500
        $st = $this->pdo->prepare(
            "INSERT IGNORE INTO approvals (monthly_todo_id, step_order, signer_user_id, opinion) VALUES (?,?,?,?)"
        );
        $opinion = ($opinion ?? '') === '' ? null : $opinion;
        $st->execute([$todoId, $stepOrder, $signerId, $opinion]);
        // 只有真的簽進去（沒被 INSERT IGNORE 擋掉）才留歷程，避免併發重複簽多記一筆
        if ($st->rowCount() > 0) {
            $this->logEvent($todoId, $stepOrder, $stepLabel, '簽核', $actorName, $opinion);
        }
    }

    public function recall(int $todoId, int $stepOrder, string $stepLabel, string $actorName): void
    {
        $this->pdo->prepare("DELETE FROM approvals WHERE monthly_todo_id = ? AND step_order = ?")
                  ->execute([$todoId, $stepOrder]);
        $this->logEvent($todoId, $stepOrder, $stepLabel, '取回', $actorName, null);
    }

    /** 簽核歷程（含取回），append-only，供 approval_sign.php 顯示完整活動軌跡 */
    public function history(int $todoId): array
    {
        $st = $this->pdo->prepare(
            "SELECT step_order, step_label, action, actor_name, opinion, created_at
             FROM approval_log WHERE monthly_todo_id = ? ORDER BY id"
        );
        $st->execute([$todoId]);
        return $st->fetchAll();
    }

    /** 後台強制覆寫狀態時也留一筆歷程，避免簽核歷程有缺口看不出狀態怎麼變的 */
    public function logOverride(int $todoId, string $actorName, string $fromStatus, string $toStatus): void
    {
        $this->logEvent($todoId, null, null, '管理覆寫', $actorName, "{$fromStatus}→{$toStatus}");
    }

    private function logEvent(int $todoId, ?int $stepOrder, ?string $stepLabel, string $action, string $actorName, ?string $opinion): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO approval_log (monthly_todo_id, step_order, step_label, action, actor_name, opinion) VALUES (?,?,?,?,?,?)"
        );
        $st->execute([$todoId, $stepOrder, $stepLabel, $action, $actorName, $opinion]);
    }

    public function clearForTodo(int $todoId): void
    {
        $this->pdo->prepare("DELETE FROM approvals WHERE monthly_todo_id = ?")->execute([$todoId]);
    }

    public function opinionsFor(int $todoId): array
    {
        $st = $this->pdo->prepare(
            "SELECT a.step_order, a.signer_user_id, u.name AS signer_name, a.opinion, a.signed_at
             FROM approvals a JOIN users u ON u.id = a.signer_user_id
             WHERE a.monthly_todo_id = ? ORDER BY a.step_order"
        );
        $st->execute([$todoId]);
        return $st->fetchAll();
    }

    /**
     * resolveContext() + ApprovalWorkflow::build() 這組固定搭配，原本在多個呼叫端各自重複組裝，
     * 抽成這個 helper。$signedMap 已經另外查過（例如呼叫端還要算 has_signature）時可以傳入避免重查。
     * @return array{steps:array,current_step:?int,status:string}
     */
    public function buildFor(int $todoId, array $steps, ?array $signedMap = null): array
    {
        $ctx = $this->resolveContext($todoId, $steps);
        $signedMap ??= $this->forTodo($todoId);
        return (new \App\ApprovalWorkflow())->build(
            $steps, $ctx['dept_manager_id'], $ctx['admin_manager_id'], $ctx['user_active'], $signedMap, $ctx['responsible_user_id']
        );
    }

    /** @return array{dept_manager_id:?int,admin_manager_id:?int,user_active:array<int,bool>,responsible_user_id:?int} */
    public function resolveContext(int $todoId, array $steps): array
    {
        // 待辦本身只記部門（不記特定負責人），故 responsible_user_id 恆為 null——
        // 「負責人本人不可簽自己」這條規則在部門制下沒有對象可排除。
        $st = $this->pdo->prepare("SELECT department FROM monthly_todos WHERE id = ?");
        $st->execute([$todoId]);
        $row = $st->fetch();
        $dept = $row['department'] ?? null;
        $responsibleUserId = null;

        $deptManagerId = $dept ? $this->activeManagerOf((string) $dept) : null;
        $adminManagerId = $this->activeManagerOf('管理部');

        $userActive = [];
        foreach ($steps as $s) {
            if ($s['signer_kind'] === 'user') {
                $uid = (int) $s['signer_value'];
                $userActive[$uid] = $this->isActiveUser($uid);
            }
        }
        return [
            'dept_manager_id' => $deptManagerId,
            'admin_manager_id' => $adminManagerId,
            'user_active' => $userActive,
            'responsible_user_id' => $responsibleUserId,
        ];
    }

    private function activeManagerOf(string $department): ?int
    {
        $st = $this->pdo->prepare(
            "SELECT u.id FROM user_departments ud JOIN users u ON u.id = ud.user_id
             WHERE ud.department = ? AND ud.is_manager = 1 AND u.employment_status = '在職'
             ORDER BY u.id LIMIT 1"
        );
        $st->execute([$department]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function isActiveUser(int $uid): bool
    {
        return (new UsersRepository($this->pdo))->isActive($uid);
    }
}

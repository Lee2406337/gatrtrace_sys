<?php
namespace App\Repository;

final class UsersRepository
{
    public function __construct(private \PDO $pdo) {}

    /** 僅接受 在職/停用，非法值正規化為 在職（避免 STRICT 模式非法 ENUM 500） */
    private function normStatus($v): string
    {
        return in_array($v, ['在職', '停用'], true) ? $v : '在職';
    }

    public function all(): array
    {
        return $this->pdo->query("SELECT id, name, email, staff_id, employment_status, locked_until, failed_attempts, is_admin FROM users ORDER BY id")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** 登入用：帶 password_hash／failed_attempts／locked_until，查無此工號回 null */
    public function findByStaffId(string $staffId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT id, name, password_hash, employment_status, failed_attempts, locked_until, is_admin FROM users WHERE staff_id = ?"
        );
        $st->execute([$staffId]);
        return $st->fetch() ?: null;
    }

    /** 是否在職（查無此人也回 false）——簽核關卡指定人／簽核授權判斷共用同一份「在職」定義 */
    public function isActive(int $id): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND employment_status = '在職'");
        $st->execute([$id]);
        return (int) $st->fetchColumn() > 0;
    }

    /** 提醒信簽核通知用：在職才回 email/name，查無此人或已停用一律回 null */
    public function findActiveContact(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT email, name FROM users WHERE id = ? AND employment_status = '在職'");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** 每請求權限重驗（Auth::revalidate）專用：只取 is_admin／employment_status，查無此人回 null */
    public function authSnapshot(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT is_admin, employment_status FROM users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * 原子遞增登入失敗次數並視門檻鎖定（單一 UPDATE 由 DB 端判斷，避免並行請求稀釋門檻）。
     * @return bool 這次呼叫是否觸發了鎖定（供呼叫端決定要不要多寫一筆「鎖定」稽核）
     */
    public function recordFailedLogin(int $id, int $maxAttempts, string $lockedUntil): bool
    {
        $this->pdo->prepare(
            "UPDATE users SET
                locked_until = IF(failed_attempts + 1 >= ?, ?, locked_until),
                failed_attempts = IF(failed_attempts + 1 >= ?, 0, failed_attempts + 1)
             WHERE id = ?"
        )->execute([$maxAttempts, $lockedUntil, $maxAttempts, $id]);

        $check = $this->pdo->prepare("SELECT locked_until FROM users WHERE id = ?");
        $check->execute([$id]);
        $now = $check->fetchColumn();
        return $now !== null && $now !== false && $now > date('Y-m-d H:i:s');
    }

    /** 登入成功：歸零失敗計數、解除鎖定 */
    public function recordSuccessfulLogin(int $id): void
    {
        $this->pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$id]);
    }

    /** password_needs_rehash() 為真時更新雜湊值用；$newHash 已經是 password_hash() 過的值 */
    public function rehashPassword(int $id, string $newHash): void
    {
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $id]);
    }

    public function create(array $d): int
    {
        // 後端防線：新增使用者一律需密碼（控制器已先擋，此為 defense-in-depth）
        if (($d['password'] ?? '') === '') {
            throw new \InvalidArgumentException('新增使用者必須設定密碼');
        }
        $st = $this->pdo->prepare(
            "INSERT INTO users (name, email, staff_id, employment_status, password_hash, is_admin)
             VALUES (:name,:email,:staff_id,:employment_status,:password_hash,:is_admin)"
        );
        $st->execute([
            'name' => $d['name'],
            'email' => ($d['email'] ?? '') === '' ? null : $d['email'],
            'staff_id' => ($d['staff_id'] ?? '') === '' ? null : $d['staff_id'],
            'employment_status' => $this->normStatus($d['employment_status'] ?? '在職'),
            'password_hash' => ($d['password'] ?? '') === '' ? null : password_hash($d['password'], PASSWORD_DEFAULT),
            'is_admin' => !empty($d['is_admin']) ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $staffId = ($d['staff_id'] ?? '') === '' ? null : $d['staff_id'];
        $isAdmin = !empty($d['is_admin']) ? 1 : 0;
        if (($d['password'] ?? '') !== '') {
            $st = $this->pdo->prepare(
                "UPDATE users SET name=:name, email=:email, staff_id=:staff_id, employment_status=:employment_status, password_hash=:ph, is_admin=:is_admin WHERE id=:id"
            );
            $st->execute([
                'name' => $d['name'], 'email' => ($d['email'] ?? '') === '' ? null : $d['email'], 'staff_id' => $staffId,
                'employment_status' => $this->normStatus($d['employment_status'] ?? '在職'), 'ph' => password_hash($d['password'], PASSWORD_DEFAULT),
                'is_admin' => $isAdmin, 'id' => $id,
            ]);
            return;
        }
        $st = $this->pdo->prepare(
            "UPDATE users SET name=:name, email=:email, staff_id=:staff_id, employment_status=:employment_status, is_admin=:is_admin WHERE id=:id"
        );
        $st->execute([
            'name' => $d['name'], 'email' => ($d['email'] ?? '') === '' ? null : $d['email'], 'staff_id' => $staffId,
            'employment_status' => $this->normStatus($d['employment_status'] ?? '在職'), 'is_admin' => $isAdmin, 'id' => $id,
        ]);
    }

    /** 使用者前台自行改密碼專用：只動 password_hash，不動姓名/工號/部門等其他欄位 */
    public function updatePassword(int $id, string $newPassword): void
    {
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                  ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
    }

    public function unlock(int $id): void
    {
        $this->pdo->prepare("UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE id = ?")->execute([$id]);
    }

    public function departmentsOf(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT id, department, is_manager FROM user_departments WHERE user_id = ? ORDER BY id");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public function addDepartment(int $userId, string $department, bool $isManager): int
    {
        $st = $this->pdo->prepare("INSERT INTO user_departments (user_id, department, is_manager) VALUES (?,?,?)");
        $st->execute([$userId, $department, $isManager ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function removeDepartment(int $udId): void
    {
        $this->pdo->prepare("DELETE FROM user_departments WHERE id = ?")->execute([$udId]);
    }

    public function setManager(int $udId, bool $isManager): void
    {
        $this->pdo->prepare("UPDATE user_departments SET is_manager = ? WHERE id = ?")->execute([$isManager ? 1 : 0, $udId]);
    }
}

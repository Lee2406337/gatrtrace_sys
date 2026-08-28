<?php
namespace App\Repository;

/**
 * 「部門待辦提醒信也要副本給誰」的規則設定（extra_recipient_rules）。跟簽核關卡、逾期升級
 * 通知都是獨立機制；這裡沒有自己的去重紀錄表，副本寄送直接沿用 reminder_log 現有的
 * (date,email,department,'todo') 去重機制，當作同一份部門提醒信多寄一份給另一個 email。
 */
final class ExtraRecipientRepository
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM extra_recipient_rules ORDER BY department, id")->fetchAll();
    }

    /** 某部門設定的所有額外收件人規則 */
    public function forDepartment(string $department): array
    {
        $st = $this->pdo->prepare("SELECT * FROM extra_recipient_rules WHERE department = ? ORDER BY id");
        $st->execute([$department]);
        return $st->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM extra_recipient_rules WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            "INSERT INTO extra_recipient_rules (department, signer_kind, signer_value, label) VALUES (:d,:k,:v,:l)"
        );
        $st->execute($this->bind($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            "UPDATE extra_recipient_rules SET department=:d, signer_kind=:k, signer_value=:v, label=:l WHERE id=:id"
        );
        $st->execute($this->bind($d) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM extra_recipient_rules WHERE id = ?")->execute([$id]);
    }

    private function bind(array $d): array
    {
        return [
            'd' => (string) $d['department'],
            'k' => $d['signer_kind'] === 'user' ? 'user' : 'role',
            'v' => (string) $d['signer_value'],
            'l' => (string) $d['label'],
        ];
    }

    /** 依規則跟部門解出實際收件人 user id；role 依部門動態解析，user 規則要在職才算數 */
    public function resolveRecipient(array $rule, UsersRepository $usersRepo): ?int
    {
        if ($rule['signer_kind'] === 'role') {
            if ($rule['signer_value'] === '部門主管') {
                return $usersRepo->activeManagerOf($rule['department']);
            }
            if ($rule['signer_value'] === '管理部主管') {
                return $usersRepo->activeManagerOf('管理部');
            }
            return null;
        }
        $uid = (int) $rule['signer_value'];
        return $usersRepo->isActive($uid) ? $uid : null;
    }
}

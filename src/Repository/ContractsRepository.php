<?php
namespace App\Repository;

final class ContractsRepository
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM contracts_master ORDER BY id DESC")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM contracts_master WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            "INSERT INTO contracts_master (contract_name, frequency, start_date, end_date_raw, end_date, note, department)
             VALUES (:contract_name,:frequency,:start_date,:end_date_raw,:end_date,:note,:department)"
        );
        $st->execute($this->bind($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            "UPDATE contracts_master SET contract_name=:contract_name, frequency=:frequency,
             start_date=:start_date, end_date_raw=:end_date_raw, end_date=:end_date, note=:note,
             department=:department WHERE id=:id"
        );
        $st->execute($this->bind($d) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM contracts_master WHERE id = ?")->execute([$id]);
    }

    /** 匯入查重複用：合約名稱相同視為重複 */
    public function existsByName(string $name): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM contracts_master WHERE contract_name = ?");
        $st->execute([$name]);
        return (int) $st->fetchColumn() > 0;
    }

    /** 每日提醒／到期展開用：只列有到期日的合約 */
    public function withEndDate(): array
    {
        return $this->pdo->query("SELECT * FROM contracts_master WHERE end_date IS NOT NULL")->fetchAll();
    }

    /**
     * 每日提醒信用：廣播給合約所屬部門的所有在職人員（部門制，不記特定負責人）。
     * @return array 每列含 contract_id／contract_name／end_date／end_date_raw／department／
     *               responsible_email／responsible_label
     */
    public function broadcastRecipients(): array
    {
        return $this->pdo->query(
            "SELECT c.id AS contract_id, c.contract_name, c.end_date, c.end_date_raw, c.department,
                    u.email AS responsible_email, u.name AS responsible_label
             FROM contracts_master c
             JOIN user_departments ud ON ud.department = c.department
             JOIN users u ON u.id = ud.user_id AND u.employment_status = '在職'
             WHERE c.department IS NOT NULL"
        )->fetchAll();
    }

    private function bind(array $d): array
    {
        return [
            'contract_name' => $d['contract_name'],
            'frequency'     => $d['frequency'] ?: null,
            'start_date'    => $d['start_date'] ?: null,
            'end_date_raw'  => $d['end_date_raw'] ?: null,
            'end_date'      => $d['end_date'] ?: null,
            'note'          => ($d['note'] ?? '') === '' ? null : $d['note'],
            'department'    => ($d['department'] ?? '') === '' ? null : $d['department'],
        ];
    }
}

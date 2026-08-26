<?php
namespace App\Repository;

final class EventsRepository
{
    public function __construct(private \PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM events_master ORDER BY id DESC")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM events_master WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            "INSERT INTO events_master (category, task_name, frequency, baseline_value, department, note)
             VALUES (:category,:task_name,:frequency,:baseline_value,:department,:note)"
        );
        $st->execute($this->bind($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            "UPDATE events_master SET category=:category, task_name=:task_name, frequency=:frequency,
             baseline_value=:baseline_value, department=:department, note=:note WHERE id=:id"
        );
        $st->execute($this->bind($d) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM events_master WHERE id = ?")->execute([$id]);
    }

    /** 匯入查重複用：類別+工作事項+單位完全相同視為重複 */
    public function existsByCategoryTaskDepartment(string $category, string $taskName, string $department): bool
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM events_master WHERE category = ? AND task_name = ? AND department = ?");
        $st->execute([$category, $taskName, $department]);
        return (int) $st->fetchColumn() > 0;
    }

    private function bind(array $d): array
    {
        return [
            'category'       => $d['category'],
            'task_name'      => $d['task_name'],
            'frequency'      => $d['frequency'],
            'baseline_value' => ($d['baseline_value'] ?? '') === '' ? null : $d['baseline_value'],
            'department'     => $d['department'],
            'note'           => ($d['note'] ?? '') === '' ? null : $d['note'],
        ];
    }
}

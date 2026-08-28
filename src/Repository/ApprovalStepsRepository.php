<?php
namespace App\Repository;

final class ApprovalStepsRepository
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM approval_steps ORDER BY step_order, id")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM approval_steps WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            "INSERT INTO approval_steps (step_order, signer_kind, signer_value, step_kind, label) VALUES (:o,:k,:v,:sk,:l)"
        );
        $st->execute($this->bind($d));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            "UPDATE approval_steps SET step_order=:o, signer_kind=:k, signer_value=:v, step_kind=:sk, label=:l WHERE id=:id"
        );
        $st->execute($this->bind($d) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM approval_steps WHERE id = ?")->execute([$id]);
    }

    private function bind(array $d): array
    {
        return [
            'o' => (int) $d['step_order'],
            'k' => $d['signer_kind'] === 'user' ? 'user' : 'role',
            'v' => (string) $d['signer_value'],
            'sk' => ($d['step_kind'] ?? 'approve') === 'notify' ? 'notify' : 'approve',
            'l' => (string) $d['label'],
        ];
    }
}

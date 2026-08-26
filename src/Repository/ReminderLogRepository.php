<?php
namespace App\Repository;

final class ReminderLogRepository
{
    public function __construct(private \PDO $pdo) {}

    public function alreadySent(string $date, string $email, ?string $department, string $kind = 'todo'): bool
    {
        // department 用 <=> 做 NULL-safe 比對：簽核提醒不分部門合成一封，
        // department 固定傳 null，一般的 "=" 比對 NULL 永遠不成立會讓去重失效
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM reminder_log WHERE send_date = ? AND email = ? AND department <=> ? AND kind = ?");
        $st->execute([$date, $email, $department, $kind]);
        return (int) $st->fetchColumn() > 0;
    }

    public function record(string $date, string $email, ?string $department, int $count, string $kind = 'todo'): void
    {
        // INSERT IGNORE：併發時第二個 run 撞 UNIQUE(send_date,email,department,kind) 不拋例外中斷整批
        $this->pdo->prepare("INSERT IGNORE INTO reminder_log (send_date, email, department, kind, item_count) VALUES (?,?,?,?,?)")
                  ->execute([$date, $email, $department, $kind, $count]);
    }
}

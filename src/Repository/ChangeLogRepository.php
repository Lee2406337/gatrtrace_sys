<?php
namespace App\Repository;

final class ChangeLogRepository
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(string $page, string $action, string $summary, string $actor, ?int $actorId): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO change_logs (page, action, summary, actor, actor_user_id) VALUES (?,?,?,?,?)"
        );
        $st->execute([$page, $action, mb_substr($summary, 0, 255), mb_substr($actor, 0, 50), $actorId]);
    }

    /**
     * 依篩選條件分頁查詢。$filters 可含 date_from／date_to（YYYY-MM-DD）、
     * page／action（精確比對）、actor（文字輸入，模糊比對姓名或工號；空字串視為不篩選）。
     * @return array{rows: array, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $join = 'LEFT JOIN users u ON u.id = cl.actor_user_id';
        $where = [];
        $params = [];
        if (!empty($filters['date_from'])) {
            $where[] = 'cl.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'cl.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['page'])) {
            $where[] = 'cl.page = ?';
            $params[] = $filters['page'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'cl.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['actor'])) {
            // 姓名（cl.actor 快照文字）或工號（JOIN users 查 staff_id）任一符合即可，
            // 因為 actor 存的是稽核當下的姓名快照，工號要透過 actor_user_id 反查現在的使用者
            $where[] = '(cl.actor LIKE ? OR u.staff_id LIKE ?)';
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['actor']) . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM change_logs cl {$join} {$whereSql}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $st = $this->pdo->prepare("SELECT cl.* FROM change_logs cl {$join} {$whereSql} ORDER BY cl.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($params);

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** 篩選下拉選單用：各欄位目前實際出現過的相異值 */
    public function distinctValues(string $column): array
    {
        $allowed = ['page', 'action', 'actor'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('不支援的欄位');
        }
        return $this->pdo->query("SELECT DISTINCT {$column} FROM change_logs ORDER BY {$column}")
                          ->fetchAll(\PDO::FETCH_COLUMN);
    }
}

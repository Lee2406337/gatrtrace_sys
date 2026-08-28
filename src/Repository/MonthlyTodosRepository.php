<?php
namespace App\Repository;

final class MonthlyTodosRepository
{
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function forMonth(string $ym): array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM monthly_todos
             WHERE `year_month` = ? ORDER BY (due_date IS NULL), due_date, id"
        );
        $st->execute([$ym]);
        return $st->fetchAll();
    }

    /**
     * 所有還沒結案、未指定日期的待辦，不限月份、不限部門。「本月待辦」頁面的「未指定日期
     * 事項」跨月顯示，跟每日提醒信附帶清單，共用同一份查詢（見 index.php / ReminderService）。
     */
    public function openUndated(): array
    {
        return $this->pdo->query(
            "SELECT * FROM monthly_todos WHERE due_date IS NULL AND status NOT IN ('已完成', '簽核中') ORDER BY id"
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM monthly_todos WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** 後台總覽用：$ym 為 null 時列出所有月份，否則只列該月份 */
    public function allFiltered(?string $ym): array
    {
        if ($ym === null || $ym === '') {
            return $this->pdo->query(
                "SELECT * FROM monthly_todos ORDER BY `year_month` DESC, (due_date IS NULL), due_date, id"
            )->fetchAll();
        }
        $st = $this->pdo->prepare(
            "SELECT * FROM monthly_todos WHERE `year_month` = ? ORDER BY (due_date IS NULL), due_date, id"
        );
        $st->execute([$ym]);
        return $st->fetchAll();
    }

    /** 後台總覽的月份篩選下拉選單用 */
    public function distinctMonths(): array
    {
        return $this->pdo->query("SELECT DISTINCT `year_month` FROM monthly_todos ORDER BY `year_month` DESC")
                          ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * 依 (year_month, source_event_id, due_date) 去重；插入回 true，已存在回 false。
     * 交由 DB 唯一鍵 uq_mt_expanded + INSERT IGNORE 保證併發安全（消除 SELECT-then-INSERT 競態）。
     */
    public function upsertExpanded(array $row): bool
    {
        $st = $this->pdo->prepare(
            "INSERT IGNORE INTO monthly_todos
               (source_event_id, `year_month`, category, task_name, frequency, department, due_date)
             VALUES
               (:source_event_id,:year_month,:category,:task_name,:frequency,:department,:due_date)"
        );
        $st->execute([
            'source_event_id' => $row['source_event_id'] ?? null,
            'year_month'      => $row['year_month'],
            'category'        => $row['category'],
            'task_name'       => $row['task_name'],
            'frequency'       => $row['frequency'],
            'department'      => $row['department'] ?? null,
            'due_date'        => $row['due_date'] ?? null,
        ]);
        return $st->rowCount() > 0;
    }

    /**
     * 合約到期提醒去重：依 (year_month, source_contract_id, due_date)；插入回 true，已存在回 false。
     * 用獨立的 uq_mt_contract_reminder 唯一鍵（不動既有 uq_mt_expanded），source_contract_id
     * 在例行事項展開/手動待辦一律為 NULL，MySQL 對含 NULL 的唯一鍵不強制比對，故互不影響。
     */
    public function upsertContractReminder(array $row): bool
    {
        $st = $this->pdo->prepare(
            "INSERT IGNORE INTO monthly_todos
               (source_contract_id, `year_month`, category, task_name, frequency, department, due_date)
             VALUES
               (:source_contract_id,:year_month,:category,:task_name,:frequency,:department,:due_date)"
        );
        $st->execute([
            'source_contract_id' => $row['source_contract_id'],
            'year_month'         => $row['year_month'],
            'category'           => $row['category'],
            'task_name'          => $row['task_name'],
            'frequency'          => $row['frequency'],
            'department'         => $row['department'] ?? null,
            'due_date'           => $row['due_date'],
        ]);
        return $st->rowCount() > 0;
    }

    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            "INSERT INTO monthly_todos
               (source_event_id, source_contract_id, `year_month`, category, task_name, frequency, department, due_date, status, follow_up, completed_at, note)
             VALUES
               (:source_event_id,:source_contract_id,:year_month,:category,:task_name,:frequency,:department,:due_date,:status,:follow_up,:completed_at,:note)"
        );
        $st->execute([
            'source_event_id'    => $d['source_event_id'] ?? null,
            'source_contract_id' => $d['source_contract_id'] ?? null,
            'year_month'         => $d['year_month'],
            'category'           => $d['category'],
            'task_name'          => $d['task_name'],
            'frequency'          => $d['frequency'],
            'department'         => $d['department'] ?? null,
            'due_date'           => $d['due_date'] ?? null,
            'status'             => $d['status'] ?? '未開始',
            'follow_up'          => $d['follow_up'] ?? null,
            'completed_at'       => $d['completed_at'] ?? null,
            'note'               => $d['note'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $st = $this->pdo->prepare(
            "UPDATE monthly_todos SET status=:status, follow_up=:follow_up,
             completed_at=:completed_at, note=:note WHERE id=:id"
        );
        $st->execute([
            'status'       => $d['status'],
            'follow_up'    => ($d['follow_up'] ?? '') === '' ? null : $d['follow_up'],
            'completed_at' => ($d['completed_at'] ?? '') === '' ? null : $d['completed_at'],
            'note'         => ($d['note'] ?? '') === '' ? null : $d['note'],
            'id'           => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM monthly_todos WHERE id = ?")->execute([$id]);
    }

    /**
     * 合約提醒自我修復：合約若「目前到期日對應的那一筆」提醒已完成或已進入簽核流程，清掉
     * 該合約其他仍未完成的提醒待辦（不論落在哪個月份）。詳細理由見 ExpandMonthService 呼叫端註解。
     */
    public function deleteStaleContractReminders(): void
    {
        $this->pdo->exec(
            "DELETE mt FROM monthly_todos mt
             INNER JOIN monthly_todos done
               ON done.source_contract_id = mt.source_contract_id AND done.status IN ('已完成', '簽核中')
             INNER JOIN contracts_master c
               ON c.id = mt.source_contract_id AND c.end_date = done.due_date
             WHERE mt.source_contract_id IS NOT NULL
               AND mt.status NOT IN ('已完成', '簽核中')"
        );
    }

    /**
     * 未指定日期提醒的自我修復：事件若已經改成非「其他／不定期」的頻率，清掉當初為它建立、
     * 還沒結案的未指定日期待辦（不然舊的未指定日期提醒會跟新頻率展開出來的有日期待辦並存，
     * 永遠不會消失）。
     *
     * 已知限制：只能處理「事件還存在、但頻率已改變」的情況。事件被刪除時，
     * fk_mt_event 是 ON DELETE SET NULL，source_event_id 會變成 NULL，屆時這筆待辦
     * 跟手動建立、本來就沒有 source_event_id 的未指定日期待辦無法區分，無法自動清理
     * （要解決需要改 schema，不在這次範圍內）。
     */
    public function deleteStaleEventReminders(): void
    {
        $this->pdo->exec(
            "DELETE mt FROM monthly_todos mt
             INNER JOIN events_master e ON e.id = mt.source_event_id
             WHERE mt.due_date IS NULL
               AND mt.status NOT IN ('已完成', '簽核中')
               AND e.frequency NOT IN ('其他', '不定期')"
        );
    }

    /** 合約到期提醒展開用：「目前到期日對應的那一筆」是否已完成或已進入簽核流程 */
    public function hasCompletedContractReminder(int $contractId, string $dueDate): bool
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM monthly_todos WHERE source_contract_id = ? AND status IN ('已完成', '簽核中') AND due_date = ?"
        );
        $st->execute([$contractId, $dueDate]);
        return (int) $st->fetchColumn() > 0;
    }

    /**
     * 頻率「其他」（含舊資料「不定期」）用：這個事件是否已有未結案的紀錄（不限 year_month、
     * 不限是否有 due_date）。判斷「未結案」的標準跟上面 hasCompletedContractReminder() 對
     * 合約提醒用的標準一致（已完成／簽核中都算已經有人在處理了，不用再產生一筆）。
     */
    public function hasOpenInstanceForEvent(int $sourceEventId): bool
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM monthly_todos WHERE source_event_id = ? AND status NOT IN ('已完成', '簽核中')"
        );
        $st->execute([$sourceEventId]);
        return (int) $st->fetchColumn() > 0;
    }

    /**
     * 每日提醒信用：廣播給待辦所屬部門的所有在職人員（部門制，一筆待辦對多個人各產生一列，
     * 交由 ReminderCollector 依 email 分組聚合）。
     */
    public function broadcastRecipients(string $ym): array
    {
        $st = $this->pdo->prepare(
            "SELECT t.id AS todo_id, t.status, t.due_date, t.category, t.task_name, t.frequency, t.department,
                    u.email AS responsible_email, u.name AS responsible_label
             FROM monthly_todos t
             JOIN user_departments ud ON ud.department = t.department
             JOIN users u ON u.id = ud.user_id AND u.employment_status = '在職'
             WHERE t.`year_month` = ?"
        );
        $st->execute([$ym]);
        return $st->fetchAll();
    }

    /** 每日提醒信用：該月所有「簽核中」的待辦（決定要提醒哪些人簽核） */
    public function pendingSigning(string $ym): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, category, task_name, department, due_date FROM monthly_todos
             WHERE `year_month` = ? AND status = '簽核中'"
        );
        $st->execute([$ym]);
        return $st->fetchAll();
    }

    /**
     * 每日提醒信用：該月「已完成」或「簽核中」的待辦，供篩出尚未寄過通知的「通知」關卡。
     * 兩種狀態都要涵蓋：整段簽核流程只有「通知」關卡（沒有 approve 關卡）時，狀態會直接是
     * 「已完成」；混有 approve 關卡、approve 還沒簽完時，狀態停在「簽核中」，但通知關卡
     * 不等 approve 關卡簽完，一樣要照常通知，所以兩種狀態的候選都要撈出來交給呼叫端判斷。
     */
    public function pendingNotificationCandidates(string $ym): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, category, task_name, department, due_date, completed_at FROM monthly_todos
             WHERE `year_month` = ? AND status IN ('已完成', '簽核中')"
        );
        $st->execute([$ym]);
        return $st->fetchAll();
    }
}

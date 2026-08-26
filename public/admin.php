<?php
// public/admin.php 是「後台」入口，跟前台 public/index.php 是各自獨立的入口檔，
// 不走 index.php?r=xxx 那條路由；共用 config/bootstrap.php 的安全前置。
// 整支檔案都要求 Auth::isAdmin()（系統管理員身份），不是任一部門主管就能進。
require_once __DIR__ . '/../config/bootstrap.php';

use App\Auth;
use App\ChangeLog;
use App\ImportService;
use App\TodoValidator;
use App\Repository\ContractsRepository;
use App\Repository\EventsRepository;
use App\Repository\UsersRepository;
use App\Repository\ApprovalStepsRepository;
use App\Repository\ApprovalsRepository;
use App\Repository\ChangeLogRepository;
use App\Repository\MonthlyTodosRepository;

Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php?r=events'); exit; }

// 整段路由處理包一層防護：連線建立後若在處理過程中中途斷線，也給乾淨訊息
// 而非把檔案路徑堆疊噴給使用者看（跟 index.php 的做法一致）。
try {

$r = $_GET['r'] ?? 'users';
$action = $_GET['action'] ?? 'list';

if ($r === 'users') {
    $repo = new UsersRepository($pdo);
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=users'); exit; }
        $d = $_POST;
        $isCreate = empty($d['id']);
        // 新增使用者必須設密碼（表單雖有 required，直接 POST 可繞過）
        if ($isCreate && ($d['password'] ?? '') === '') {
            header('Location: admin.php?r=users&err=' . urlencode('新增使用者必須設定密碼')); exit;
        }
        $before = !$isCreate ? $repo->find((int)$d['id']) : null;
        try {
            if (!$isCreate) { $repo->update((int)$d['id'], $d); }
            else {
                $newUserId = $repo->create($d);
                // 新增時可順便指派多個部門（身兼多職），不用再多跑「加部門」——
                // department[]／is_dept_manager[] 由前端 JS 成對產生（hidden input，非 checkbox），
                // 不會有勾選陣列跟部門陣列對不齊的問題
                foreach ((array) ($d['department'] ?? []) as $i => $dept) {
                    if ($dept === '') { continue; }
                    $isDeptManager = ($d['is_dept_manager'][$i] ?? '0') === '1';
                    $repo->addDepartment($newUserId, $dept, $isDeptManager);
                }
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                header('Location: admin.php?r=users&err=' . urlencode('工號已被使用，請改用其他值')); exit;
            }
            throw $e;
        }
        // 啟用/停用是在編輯表單裡跟其他欄位一起存，狀態有變才另外記一筆，方便在修改紀錄追蹤
        $newStatus = in_array($d['employment_status'] ?? '', ['在職', '停用'], true) ? $d['employment_status'] : '在職';
        if ($before && $before['employment_status'] !== $newStatus) {
            ChangeLog::record($pdo, '使用者', $newStatus === '停用' ? '停用' : '啟用', ($before['staff_id'] ?? $before['name']) . " → {$newStatus}");
        }
        header('Location: admin.php?r=users'); exit;
    }
    if ($action === 'add-dept') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=users'); exit; }
        try {
            $repo->addDepartment((int)$_POST['user_id'], $_POST['department'], !empty($_POST['is_manager']));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                header('Location: admin.php?r=users&err=' . urlencode('此使用者已經在該部門，無法重複新增')); exit;
            }
            throw $e;
        }
        header('Location: admin.php?r=users'); exit;
    }
    if ($action === 'remove-dept') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=users'); exit; }
        try {
            $repo->removeDepartment((int)$_POST['ud']);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                header('Location: admin.php?r=users&err=' . urlencode('此部門仍被指派，無法移除')); exit;
            }
            throw $e;
        }
        header('Location: admin.php?r=users'); exit;
    }
    if ($action === 'toggle-manager') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=users'); exit; }
        $repo->setManager((int)$_POST['ud'], ($_POST['on'] ?? '') === '1');
        header('Location: admin.php?r=users'); exit;
    }
    if ($action === 'unlock') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=users'); exit; }
        $repo->unlock((int)$_POST['id']);
        $target = $repo->find((int)$_POST['id']);
        ChangeLog::record($pdo, '登入', '解封', '解封工號 ' . ($target['staff_id'] ?? ('#' . (int)$_POST['id'])));
        header('Location: admin.php?r=users'); exit;
    }
    $users = $repo->all();
    $depts = [];
    foreach ($users as $u) { $depts[$u['id']] = $repo->departmentsOf((int)$u['id']); }
    render('admin_users', ['title' => '使用者後台', 'users' => $users, 'depts' => $depts], 'admin_layout');
    exit;
}

if ($r === 'approval-steps') {
    $repo = new ApprovalStepsRepository($pdo);
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=approval-steps'); exit; }
        $d = $_POST;
        // F5：若前端提供分欄位（角色/使用者下拉），依 signer_kind 取用對應值
        if (($d['signer_kind'] ?? '') === 'role' && isset($d['signer_value_role'])) {
            $d['signer_value'] = $d['signer_value_role'];
        } elseif (($d['signer_kind'] ?? '') === 'user' && isset($d['signer_value_user'])) {
            $d['signer_value'] = $d['signer_value_user'];
        }
        // F3：驗證
        $err = null;
        $order = (string)($d['step_order'] ?? '');
        if (!ctype_digit($order) || (int)$order < 1) {
            $err = 'step_order 須為 >=1 的整數';
        } elseif (!in_array($d['signer_kind'] ?? '', ['role', 'user'], true)) {
            $err = 'signer_kind 不合法';
        } elseif (($d['signer_kind'] === 'role') && !in_array($d['signer_value'] ?? '', ['部門主管', '管理部主管'], true)) {
            $err = '角色須為「部門主管」或「管理部主管」';
        } elseif ($d['signer_kind'] === 'user') {
            $uid = (int)($d['signer_value'] ?? 0);
            if ($uid < 1 || !(new UsersRepository($pdo))->isActive($uid)) {
                $err = '指定人員須為存在且在職的使用者';
            }
        }
        if (($d['label'] ?? '') === '') { $err = $err ?? '顯示名稱不可空白'; }
        if ($err !== null) {
            header('Location: admin.php?r=approval-steps&err=' . urlencode($err)); exit;
        }
        try {
            if (!empty($d['id'])) { $repo->update((int)$d['id'], $d); }
            else { $repo->create($d); }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                header('Location: admin.php?r=approval-steps&err=' . urlencode('關卡順序重複')); exit;
            }
            throw $e;
        }
        header('Location: admin.php?r=approval-steps'); exit;
    }
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=approval-steps'); exit; }
        $repo->delete((int)$_POST['id']); header('Location: admin.php?r=approval-steps'); exit;
    }
    $users = (new UsersRepository($pdo))->all();
    render('admin_approval_steps', ['title' => '簽核關卡設定', 'steps' => $repo->all(), 'users' => $users], 'admin_layout');
    exit;
}

if ($r === 'change-logs') {
    $logRepo = new ChangeLogRepository($pdo);
    $filters = [
        'date_from' => trim((string) ($_GET['flt_from'] ?? '')),
        'date_to'   => trim((string) ($_GET['flt_to'] ?? '')),
        'page'      => trim((string) ($_GET['flt_page'] ?? '')),
        'action'    => trim((string) ($_GET['flt_action'] ?? '')),
        'actor'     => trim((string) ($_GET['flt_actor'] ?? '')),
    ];
    $perPage = 50;
    $curPage = max(1, (int) ($_GET['pn'] ?? 1));
    $result = $logRepo->search($filters, $curPage, $perPage);
    render('admin_change_logs', [
        'title' => '修改紀錄', 'rows' => $result['rows'], 'total' => $result['total'],
        'curPage' => $curPage, 'perPage' => $perPage, 'filters' => $filters,
        'pageOptions' => $logRepo->distinctValues('page'),
        'actionOptions' => $logRepo->distinctValues('action'),
    ], 'admin_layout');
    exit;
}

if ($r === 'todos') {
    $repo = new MonthlyTodosRepository($pdo);
    if ($action === 'override-status') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=todos'); exit; }
        $id = (int) $_POST['id'];
        $current = $repo->find($id);
        $newStatus = (string) ($_POST['status'] ?? '');
        $validStatuses = TodoValidator::STATUSES;
        if ($current && in_array($newStatus, $validStatuses, true)) {
            $completedAt = in_array($newStatus, ['已完成', '簽核中'], true)
                ? (in_array($current['status'], ['已完成', '簽核中'], true) ? $current['completed_at'] : date('Y-m-d'))
                : null;
            $repo->update($id, [
                'status' => $newStatus, 'follow_up' => $current['follow_up'],
                'note' => $current['note'], 'completed_at' => $completedAt,
            ]);
            // 離開已完成/簽核中時一併清掉簽核紀錄，避免下次再轉已完成時，
            // resolveCompletionStatus() 誤判「舊簽核紀錄還在＝已簽完」而跳過真正該走的簽核流程
            $apRepo = new ApprovalsRepository($pdo);
            if (!in_array($newStatus, ['已完成', '簽核中'], true)) {
                $apRepo->clearForTodo($id);
            }
            // 也留一筆簽核歷程（action=管理覆寫），避免 approval_sign.php 的歷程表格
            // 對這次狀態轉折完全看不出來，出現稽核缺口
            $apRepo->logOverride($id, (string) Auth::user()['name'], $current['status'], $newStatus);
            ChangeLog::record($pdo, '本月待辦', '後台覆寫',
                "{$current['task_name']}（{$current['year_month']}）狀態 {$current['status']}→{$newStatus}（管理後台覆寫，不受簽核鎖定限制）");
        }
        header('Location: admin.php?r=todos&ym=' . urlencode((string) ($_POST['ym'] ?? ''))); exit;
    }
    $ym = trim((string) ($_GET['ym'] ?? ''));
    $rows = $repo->allFiltered($ym === '' ? null : $ym);
    $months = $repo->distinctMonths();
    render('admin_todos', ['title' => '本月待辦後台', 'rows' => $rows, 'months' => $months, 'ym' => $ym], 'admin_layout');
    exit;
}

if ($r === 'import') {
    // 兩種匯入類型共用的小工具：型別正規化、分類函式、Repository、範本檔名、ChangeLog 頁面標籤，
    // 都用同一個 match() 集中對應，避免三個 action 各自重複一套 if/else 判斷
    $normalizeImportType = fn(string $t): string => $t === 'contracts' ? 'contracts' : 'events';
    $classifyRows = fn(string $type, array $parsed) => match ($type) {
        'contracts' => ImportService::classifyContractRows($pdo, $parsed),
        default     => ImportService::classifyEventRows($pdo, $parsed),
    };
    $repoFor = fn(string $type) => match ($type) {
        'contracts' => new ContractsRepository($pdo),
        default     => new EventsRepository($pdo),
    };
    $labelFor = fn(string $type) => match ($type) {
        'contracts' => '合約',
        default     => '例行事項',
    };

    if ($action === 'template') {
        $type = $normalizeImportType((string) ($_GET['type'] ?? ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $type . '_import_template.csv"');
        echo ImportService::template($type);
        exit;
    }
    if ($action === 'preview') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=import'); exit; }
        $type = $normalizeImportType((string) ($_POST['type'] ?? ''));
        if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
            header('Location: admin.php?r=import&err=' . urlencode('請選擇要上傳的 CSV 檔案')); exit;
        }
        $utf8 = ImportService::decode((string) file_get_contents($_FILES['file']['tmp_name']));
        $parsed = ImportService::parseCsv($utf8);
        $results = $classifyRows($type, $parsed);
        render('admin_import_preview', [
            'title' => '匯入預覽', 'type' => $type, 'results' => $results, 'csvContent' => $utf8,
        ], 'admin_layout');
        exit;
    }
    if ($action === 'commit') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?r=import'); exit; }
        $type = $normalizeImportType((string) ($_POST['type'] ?? ''));
        // 不信任前端送回的結構化資料，用同一份內容重新解析＋驗證＋查重複再寫入
        $utf8 = (string) ($_POST['csv_content'] ?? '');
        $parsed = ImportService::parseCsv($utf8);
        $results = $classifyRows($type, $parsed);
        $inserted = 0;
        $repo = $repoFor($type);
        foreach ($results as $row) {
            if ($row['status'] === 'ok') {
                $repo->create($row['data']);
                $inserted++;
            }
        }
        $skipped = count($results) - $inserted;
        // 至少真的匯入一筆才留紀錄；整批都被跳過（沒有任何資料真正寫入）沒必要留一筆稽核紀錄
        if ($inserted > 0) {
            ChangeLog::record($pdo, $labelFor($type), '匯入', "CSV 匯入：成功 {$inserted} 筆，跳過 {$skipped} 筆");
        }
        render('admin_import_result', ['title' => '匯入結果', 'type' => $type, 'inserted' => $inserted, 'skipped' => $skipped], 'admin_layout');
        exit;
    }
    render('admin_import', ['title' => '資料匯入'], 'admin_layout');
    exit;
}

http_response_code(404);
echo '找不到頁面';

} catch (\PDOException $e) {
    http_response_code(503);
    exit('資料庫連線逾時或中斷，請重新整理再試一次。若持續發生，請檢查網路連線是否穩定。');
} catch (\Throwable $e) {
    // 兜底：避免任何未預期例外把完整 stack trace（含絕對路徑）直接印給瀏覽器
    error_log('[newsys/admin] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    exit('系統發生錯誤，請重新整理後再試。若持續發生，請聯絡系統管理員。');
}

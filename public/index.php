<?php
// public/index.php 是「前台」入口（本月待辦／例行事項／合約／行事曆／登入）。
// 後台（使用者／本月待辦覆寫／簽核關卡設定／修改紀錄）是獨立入口 public/admin.php，
// 兩者共用 config/bootstrap.php 的安全前置（session／安全標頭／CSRF／閒置逾時／DB 連線）。
require_once __DIR__ . '/../config/bootstrap.php';

use App\Auth;
use App\Repository\EventsRepository;
use App\Repository\ContractsRepository;
use App\Repository\MonthlyTodosRepository;
use App\Repository\ApprovalStepsRepository;
use App\Repository\ApprovalsRepository;
use App\Repository\UsersRepository;
use App\ApprovalWorkflow;
use App\ChangeLog;
use App\ExpandMonthService;
use App\TodoValidator;
use App\DateParser;
use App\ContractStatus;
use App\BaselineValidator;
use App\EventFrequency;
use App\Departments;

// 整段路由處理包一層防護：連線建立後若在處理過程中中途斷線（例如不穩定網路造成
// 「MySQL server has gone away」），也給乾淨訊息而非把檔案路徑堆疊噴給使用者看。
try {

$r = $_GET['r'] ?? 'todos';
$action = $_GET['action'] ?? 'list';

/**
 * 依目前簽核進度，回傳待辦「應該呈現」的狀態：全部簽完（或本來就不需要簽）→已完成；
 * 還在跑簽核流程（含尚未有人簽）→簽核中。標記完成、簽核、取回都靠這個統一判斷收斂狀態。
 */
function resolveCompletionStatus(\PDO $pdo, int $todoId): string
{
    $steps = (new ApprovalStepsRepository($pdo))->all();
    $build = (new ApprovalsRepository($pdo))->buildFor($todoId, $steps);
    return $build['status'] === '已簽核' ? '已完成' : '簽核中';
}

if ($r === 'events') {
    Auth::requireLogin();
    $repo = new EventsRepository($pdo);
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?r=events'); exit; }
        $d = $_POST;
        $err = BaselineValidator::validate($d['frequency'] ?? '', $d['baseline_value'] ?? null);
        if ($err !== null) {
            $back = !empty($d['id'])
                ? 'index.php?r=events&action=edit&id=' . (int)$d['id'] . '&err=' . urlencode($err)
                : 'index.php?r=events&action=new&err=' . urlencode($err);
            header('Location: ' . $back); exit;
        }
        if (!empty($d['id'])) { $repo->update((int)$d['id'], $d); }
        else { $repo->create($d); }
        header('Location: index.php?r=events'); exit;
    }
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?r=events'); exit; }
        $repo->delete((int)$_POST['id']);
        header('Location: index.php?r=events'); exit;
    }
    if ($action === 'new' || $action === 'edit') {
        $row = $action === 'edit' ? $repo->find((int)$_GET['id']) : null;
        render('events_form', ['title' => '例行事項', 'row' => $row]);
        exit;
    }
    render('events_list', ['title' => '例行事項', 'rows' => $repo->all()]);
    exit;
}

if ($r === 'contracts') {
    Auth::requireLogin();
    $repo = new ContractsRepository($pdo);
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?r=contracts'); exit; }
        $d = $_POST;
        $d['start_date'] = DateParser::parse($d['start_date'] ?? '');
        $d['end_date'] = DateParser::parse($d['end_date_raw'] ?? '');
        if (!empty($d['id'])) { $repo->update((int)$d['id'], $d); }
        else { $repo->create($d); }
        ChangeLog::record($pdo, '合約', !empty($d['id']) ? '編輯' : '新增', (string)($d['contract_name'] ?? ''));
        header('Location: index.php?r=contracts'); exit;
    }
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?r=contracts'); exit; }
        $victim = $repo->find((int)$_POST['id']);
        $repo->delete((int)$_POST['id']);
        ChangeLog::record($pdo, '合約', '刪除', '刪除：' . ($victim['contract_name'] ?? ('合約 #' . (int)$_POST['id'])));
        header('Location: index.php?r=contracts'); exit;
    }
    if ($action === 'new' || $action === 'edit') {
        $row = $action === 'edit' ? $repo->find((int)$_GET['id']) : null;
        render('contracts_form', ['title' => '合約清單', 'row' => $row]);
        exit;
    }
    render('contracts_list', ['title' => '合約清單', 'rows' => $repo->all()]);
    exit;
}

if ($r === 'todos') {
    Auth::requireLogin();
    $repo = new MonthlyTodosRepository($pdo);
    $ym = $_GET['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { $ym = date('Y-m'); }
    if ($action === 'expand') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php?r=todos&ym=$ym"); exit; }
        [$y, $m] = array_map('intval', explode('-', $ym));
        ExpandMonthService::run($pdo, $y, $m);
        ChangeLog::record($pdo, '本月待辦', '重新整理', "重新整理 {$ym}");
        header("Location: index.php?r=todos&ym=$ym"); exit;
    }
    if ($action === 'update-status') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php?r=todos&ym=$ym"); exit; }
        $id = (int)$_POST['id'];
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, TodoValidator::STATUSES, true)) {
            header('Location: index.php?r=todos&ym=' . urlencode($ym) . '&err=' . urlencode('狀態值不合法')); exit;
        }
        $followUp = $_POST['follow_up'] ?? null;
        $note = $_POST['note'] ?? null;
        $nextDueDateRaw = $_POST['next_due_date'] ?? '';
        // completed_at 只在「轉為已完成」時記錄一次；離開已完成清空；維持已完成則保留原值
        $current = $repo->find($id);
        // 鎖定編輯的判斷依據：是否已有任一關被簽過（不是單純看 status 是不是簽核中——
        // 剛轉入簽核流程、還沒人簽第一關時仍可編輯）
        if (count((new ApprovalsRepository($pdo))->forTodo($id)) > 0) {
            header('Location: index.php?r=todos&ym=' . urlencode($ym) . '&err=' . urlencode('已有簽核紀錄，無法編輯，請至簽核頁處理')); exit;
        }
        $err = TodoValidator::validate($status, $followUp, $note);
        if ($err !== null) {
            header('Location: index.php?r=todos&ym=' . urlencode($ym) . '&err=' . urlencode($err)); exit;
        }
        $prevStatus = $current['status'] ?? null;
        $prevCompleted = $current['completed_at'] ?? null;
        $frequency = (string) ($current['frequency'] ?? '');
        $becomingComplete = $status === '已完成' && $prevStatus !== '已完成';
        // 頻率「其他」（含舊資料「不定期」）轉為已完成時，須填下一次需要處理的時間，系統會自動建立下一筆待辦
        $nextDueDate = in_array($frequency, [EventFrequency::Other, EventFrequency::Irregular], true) ? DateParser::parse($nextDueDateRaw) : null;
        if ($becomingComplete && in_array($frequency, [EventFrequency::Other, EventFrequency::Irregular], true) && $nextDueDate === null) {
            header('Location: index.php?r=todos&ym=' . urlencode($ym) . '&err=' . urlencode('頻率為「其他」完成時，需填寫下一次需要處理的時間。')); exit;
        }
        // 選「已完成」時：若需要簽核（有生效中的簽核關卡），自動轉為「簽核中」，不會直接卡在已完成
        $finalStatus = $status;
        if ($status === '已完成') {
            $finalStatus = resolveCompletionStatus($pdo, $id);
        }
        if (in_array($finalStatus, ['已完成', '簽核中'], true)) {
            $completedAt = (in_array($prevStatus, ['已完成', '簽核中'], true)) ? $prevCompleted : date('Y-m-d');
        } else {
            $completedAt = null;
        }
        $repo->update($id, ['status' => $finalStatus, 'follow_up' => $followUp, 'note' => $note,
            'completed_at' => $completedAt]);
        if (!in_array($finalStatus, ['已完成', '簽核中'], true)) {
            (new ApprovalsRepository($pdo))->clearForTodo($id);
        }
        if ($becomingComplete && $nextDueDate !== null) {
            $repo->create([
                'source_event_id' => $current['source_event_id'] ?? null,
                'year_month'      => substr($nextDueDate, 0, 7),
                'category'        => $current['category'],
                'task_name'       => $current['task_name'],
                'frequency'       => $frequency,
                'department'      => $current['department'],
                'due_date'        => $nextDueDate,
                'status'          => '未開始',
            ]);
            ChangeLog::record($pdo, '本月待辦', '自動建立', "{$current['task_name']} 下一次待辦（{$nextDueDate}）");
        }
        ChangeLog::record($pdo, '本月待辦', '編輯', ($current['task_name'] ?? '待辦') . " 狀態→{$finalStatus}");
        header("Location: index.php?r=todos&ym=$ym"); exit;
    }
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php?r=todos&ym=$ym"); exit; }
        $victim = $repo->find((int)$_POST['id']);
        $repo->delete((int)$_POST['id']);
        ChangeLog::record($pdo, '本月待辦', '刪除', '刪除：' . ($victim['task_name'] ?? ('待辦 #' . (int)$_POST['id'])));
        header("Location: index.php?r=todos&ym=$ym"); exit;
    }
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php?r=todos&ym=$ym"); exit; }
        $d = $_POST; $d['year_month'] = $ym;
        $err = TodoValidator::validate($d['status'] ?? '未開始', $d['follow_up'] ?? null, $d['note'] ?? null);
        if ($err !== null) {
            header('Location: index.php?r=todos&action=new&ym=' . urlencode($ym) . '&err=' . urlencode($err)); exit;
        }
        $freq = (string) ($d['frequency'] ?? '');
        $isOtherFreq = in_array($freq, [EventFrequency::Other, EventFrequency::Irregular], true);
        $nextDueDate = $isOtherFreq ? DateParser::parse($d['next_due_date'] ?? '') : null;
        if ($isOtherFreq && ($d['status'] ?? '') === '已完成' && $nextDueDate === null) {
            header('Location: index.php?r=todos&action=new&ym=' . urlencode($ym) . '&err=' . urlencode('頻率為「其他」完成時，需填寫下一次需要處理的時間。')); exit;
        }
        // 空字串正規化為 null，避免 '' 進 DATE 欄
        $d['department']     = ($d['department'] ?? '') === '' ? null : $d['department'];
        $d['due_date']       = ($d['due_date'] ?? '') === '' ? null : $d['due_date'];
        $d['completed_at']   = (($d['status'] ?? '') === '已完成') ? date('Y-m-d') : null;
        $newTodoId = $repo->create($d);
        // 新建立就直接選「已完成」時，若需要簽核，同樣自動轉為「簽核中」
        if (($d['status'] ?? '') === '已完成') {
            $resolved = resolveCompletionStatus($pdo, $newTodoId);
            if ($resolved === '簽核中') {
                $repo->update($newTodoId, ['status' => '簽核中', 'follow_up' => $d['follow_up'] ?? null, 'note' => $d['note'] ?? null, 'completed_at' => $d['completed_at']]);
            }
        }
        ChangeLog::record($pdo, '本月待辦', '新增', (string)($d['task_name'] ?? ''));
        if ($nextDueDate !== null && ($d['status'] ?? '') === '已完成') {
            $repo->create([
                'source_event_id' => null,
                'year_month'      => substr($nextDueDate, 0, 7),
                'category'        => $d['category'],
                'task_name'       => $d['task_name'],
                'frequency'       => $freq,
                'department'      => $d['department'],
                'due_date'        => $nextDueDate,
                'status'          => '未開始',
            ]);
            ChangeLog::record($pdo, '本月待辦', '自動建立', "{$d['task_name']} 下一次待辦（{$nextDueDate}）");
        }
        header("Location: index.php?r=todos&ym=" . urlencode($ym)); exit;
    }
    if ($action === 'sign' || $action === 'do-sign' || $action === 'recall') {
        Auth::requireLogin();
        $tid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $todo = $repo->find($tid);
        if (!$todo || !in_array($todo['status'], ['已完成', '簽核中'], true)) { header("Location: index.php?r=todos&ym=$ym"); exit; }
        $stepsRepo = new ApprovalStepsRepository($pdo);
        $apRepo = new ApprovalsRepository($pdo);
        $steps = $stepsRepo->all();
        $build = $apRepo->buildFor($tid, $steps);
        $uid = (int) Auth::user()['id'];

        if ($action === 'do-sign') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php?r=todos&ym=$ym"); exit; }
            $stepOrder = (int) $build['current_step'];
            if ($stepOrder && ApprovalWorkflow::canSign($build, $uid, $stepOrder)) {
                $stepLabel = ApprovalWorkflow::labelForStep($build, $stepOrder);
                $apRepo->sign($tid, $stepOrder, $uid, $_POST['opinion'] ?? null, $stepLabel, (string) Auth::user()['name']);
                ChangeLog::record($pdo, '簽核', '簽核', "{$todo['task_name']} 第{$stepOrder}關簽核");
                // 全部簽完（或不再需要簽）收斂回已完成；否則維持/轉為簽核中
                $newStatus = resolveCompletionStatus($pdo, $tid);
                if ($newStatus !== $todo['status']) {
                    $repo->update($tid, ['status' => $newStatus, 'follow_up' => $todo['follow_up'], 'note' => $todo['note'], 'completed_at' => $todo['completed_at']]);
                }
                header("Location: index.php?r=todos&ym=$ym"); exit;
            }
            header('Location: index.php?r=todos&ym=' . urlencode($ym) . '&err=' . urlencode('無法簽核或取回（非目前關卡簽核人）')); exit;
        }
        if ($action === 'recall') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php?r=todos&ym=$ym"); exit; }
            $stepOrder = (int)($_POST['step'] ?? $_GET['step'] ?? 0);
            if ($stepOrder && ApprovalWorkflow::canRecall($build, $uid, $stepOrder)) {
                $stepLabel = ApprovalWorkflow::labelForStep($build, $stepOrder);
                $apRepo->recall($tid, $stepOrder, $stepLabel, (string) Auth::user()['name']);
                ChangeLog::record($pdo, '簽核', '取回', "{$todo['task_name']} 第{$stepOrder}關取回");
                // 取回後可能不再是「全部簽完」，重新收斂狀態（已完成→簽核中）
                $newStatus = resolveCompletionStatus($pdo, $tid);
                if ($newStatus !== $todo['status']) {
                    $repo->update($tid, ['status' => $newStatus, 'follow_up' => $todo['follow_up'], 'note' => $todo['note'], 'completed_at' => $todo['completed_at']]);
                }
                header("Location: index.php?r=todos&ym=$ym"); exit;
            }
            header('Location: index.php?r=todos&ym=' . urlencode($ym) . '&err=' . urlencode('無法簽核或取回（非目前關卡簽核人）')); exit;
        }
        // action === 'sign'：顯示簽核視窗
        render('approval_sign', ['title' => '簽核', 'todo' => $todo, 'build' => $build, 'uid' => $uid, 'ym' => $ym,
            'opinions' => $apRepo->opinionsFor($tid), 'history' => $apRepo->history($tid)]);
        exit;
    }
    if ($action === 'new') {
        $events = new EventsRepository($pdo);
        render('todos_form', ['title' => '本月待辦', 'ym' => $ym, 'events' => $events->all()]);
        exit;
    }
    // 依 id 去重合併：forMonth($ym) 撈當月全部（不限狀態，已完成的交給後面 $visibleRows
    // 判斷要不要隱藏），openUndated() 撈所有還沒結案、未指定日期的紀錄（不限月份）。
    // 未指定日期事項因此不再受限於「建立當下那個月」，不管瀏覽哪個月都能看到，直到結案為止。
    $byId = [];
    foreach (array_merge($repo->forMonth($ym), $repo->openUndated()) as $row) {
        $byId[$row['id']] = $row;
    }
    $rows = array_values($byId);
    // 單位篩選：array_intersect 順便擋掉被竄改的非法值；空陣列＝不篩、顯示全部
    $deptFilter = array_values(array_intersect((array) ($_GET['flt_department'] ?? []), Departments::ALL));
    if ($deptFilter) {
        $rows = array_values(array_filter($rows, function ($row) use ($deptFilter) {
            return in_array($row['department'], $deptFilter, true);
        }));
    }
    // 切換月份／重新整理／新增待辦時要保留目前的單位篩選，附加在對應連結後面
    $filterQs = $deptFilter ? ('&' . http_build_query(['flt_department' => $deptFilter])) : '';
    $stepsRepo = new ApprovalStepsRepository($pdo);
    $apRepo = new ApprovalsRepository($pdo);
    $steps = $stepsRepo->all();
    $curUid = Auth::check() ? (int) Auth::user()['id'] : 0;
    $approval = [];
    foreach ($rows as $row) {
        if (in_array($row['status'], ['已完成', '簽核中'], true)) {
            $tid = (int) $row['id'];
            $signedMap = $apRepo->forTodo($tid);
            $b = $apRepo->buildFor($tid, $steps, $signedMap);
            $canSign = $b['current_step'] && $curUid && ApprovalWorkflow::canSign($b, $curUid, (int)$b['current_step']);
            // 鎖定編輯的判斷依據：是否已經有任一關被簽過（不是單純看 status 是不是簽核中——
            // 剛轉入簽核流程、還沒人簽第一關時仍應可編輯）
            $approval[$tid] = ['status' => $b['status'], 'can_sign' => $canSign, 'has_signature' => count($signedMap) > 0];
        }
    }
    // 已完成且已簽核完成的不再顯示（工作徹底結束）；已完成但簽核尚未跑完的仍要顯示，否則會找不到簽核入口
    $visibleRows = array_filter($rows, function ($row) use ($approval) {
        if ($row['status'] !== '已完成') {
            return true;
        }
        $tid = (int) $row['id'];
        return !isset($approval[$tid]) || $approval[$tid]['status'] !== '已簽核';
    });
    $dated = array_values(array_filter($visibleRows, function ($x) {
        return $x['due_date'] !== null;
    }));
    $undated = array_filter($visibleRows, function ($x) {
        return $x['due_date'] === null;
    });
    // 已完成且已簽核完成、被上面 $visibleRows 篩掉的項目：另開一區可查閱簽核意見
    $fullySigned = array_values(array_filter($rows, function ($row) use ($approval) {
        if ($row['status'] !== '已完成') {
            return false;
        }
        $tid = (int) $row['id'];
        return isset($approval[$tid]) && $approval[$tid]['status'] === '已簽核';
    }));
    // 排序：使用者本人待簽核 > 逾期／≤3天 > 4-7天 > 一般(>7天) > 已完成／簽核中 > 異常；
    // 同分組內再依 合約>每週>每月>單數月/雙數月>每半年>每年>每兩年>每三年>不定期 排序，最後依到期日/id 穩定排序
    $todayForSort = new DateTimeImmutable('today');
    usort($dated, function ($a, $b) use ($todayForSort, $approval) {
        $remainA = (int) $todayForSort->diff(new DateTimeImmutable($a['due_date']))->format('%r%a');
        $remainB = (int) $todayForSort->diff(new DateTimeImmutable($b['due_date']))->format('%r%a');
        $canSignA = !empty($approval[(int) $a['id']]['can_sign']);
        $canSignB = !empty($approval[(int) $b['id']]['can_sign']);
        $groupA = todo_sort_group($a['status'], $remainA, $canSignA);
        $groupB = todo_sort_group($b['status'], $remainB, $canSignB);
        if ($groupA !== $groupB) {
            return $groupA <=> $groupB;
        }
        $freqA = todo_frequency_priority((string) $a['category'], (string) $a['frequency']);
        $freqB = todo_frequency_priority((string) $b['category'], (string) $b['frequency']);
        if ($freqA !== $freqB) {
            return $freqA <=> $freqB;
        }
        if ($a['due_date'] !== $b['due_date']) {
            return $a['due_date'] <=> $b['due_date'];
        }
        return $a['id'] <=> $b['id'];
    });
    // 本來有資料、但全部被「已完成且已簽核完成」篩掉才算「全部已完成」；本來就沒資料是另一種狀態
    $allCompleted = count($rows) > 0 && count($visibleRows) === 0;
    render('todos', ['title' => '本月待辦', 'ym' => $ym, 'dated' => $dated, 'undated' => $undated, 'approval' => $approval, 'allCompleted' => $allCompleted, 'fullySigned' => $fullySigned, 'deptFilter' => $deptFilter, 'filterQs' => $filterQs]);
    exit;
}

if ($r === 'contract-status') {
    Auth::requireLogin();
    $repo = new ContractsRepository($pdo);
    $cs = new ContractStatus();
    $today = new DateTimeImmutable('today');
    $items = [];
    foreach ($repo->all() as $c) {
        $ev = $cs->evaluate($c['contract_name'], $c['end_date_raw'], $c['end_date'], $today);
        $items[] = $c + $ev;
    }
    // 有剩餘天數者依剩餘天數升冪；無者（需確認/未填）墊底，依 created_at
    usort($items, function ($a, $b) {
        $an = $a['remaining'] === null; $bn = $b['remaining'] === null;
        if ($an !== $bn) return $an <=> $bn;          // 有天數者在前
        if (!$an) return $a['remaining'] <=> $b['remaining'];
        return strcmp((string)$a['created_at'], (string)$b['created_at']);
    });
    $summary = ['total' => count($items), '30' => 0, 'expired' => 0, '31_90' => 0, 'over90' => 0];
    foreach ($items as $it) {
        if ($it['status'] === '30天內') $summary['30']++;
        elseif ($it['status'] === '已到期') $summary['expired']++;
        elseif ($it['status'] === '31–90天') $summary['31_90']++;
        elseif ($it['status'] === '>90天') $summary['over90']++;
    }
    render('contract_status', ['title' => '合約年限整理', 'items' => $items, 'summary' => $summary]);
    exit;
}

if ($r === 'calendar') {
    Auth::requireLogin();
    $events = (new EventsRepository($pdo))->all();
    $contracts = (new ContractsRepository($pdo))->all();
    $builder = new App\CalendarBuilder();
    $year = (int) date('Y');
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[$m] = $builder->build($year, $m, $events, $contracts);
    }
    render('calendar', ['title' => '月份行事曆', 'year' => $year, 'months' => $months, 'current' => (int) date('n')]);
    exit;
}

if ($r === 'login') {
    $err = '';
    if ($action === 'authenticate') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?r=login'); exit; }
        if (Auth::attempt($pdo, $_POST['staff_id'] ?? '', $_POST['password'] ?? '')) {
            header('Location: index.php?r=todos'); exit;
        }
        // 統一訊息：不論查無此工號／帳號被鎖／密碼錯誤，一律顯示同一段文字，避免透露帳號是否
        // 存在、是否鎖定、確切解鎖時間等可被攻擊者利用的區分訊號。
        $err = '工號或密碼錯誤，若因多次嘗試失敗被暫時鎖定，請稍後再試或洽管理員解封';
    }
    render('login', ['title' => '登入', 'err' => $err]);
    exit;
}

if ($r === 'logout') {
    // 僅接受 POST（全站 CSRF 守門已擋掉無 token 的 POST），避免 <img> 之類被動 GET 強制登出
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Auth::logout();
    }
    header('Location: index.php?r=login'); exit;
}

if ($r === 'change-password') {
    Auth::requireLogin();
    $repo = new UsersRepository($pdo);
    $err = '';
    $ok = false;
    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?r=change-password'); exit; }
        $uid = (int) Auth::user()['id'];
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $me = $repo->find($uid);
        if (!$me || empty($me['password_hash']) || !password_verify($current, $me['password_hash'])) {
            $err = '目前密碼不正確';
        } elseif ($new !== $confirm) {
            $err = '兩次輸入的新密碼不一致';
        } elseif ($new === '') {
            $err = '新密碼不可為空';
        } else {
            $repo->updatePassword($uid, $new);
            ChangeLog::record($pdo, '使用者', '修改密碼', ($me['staff_id'] ?? $me['name']) . ' 自行修改密碼');
            $ok = true;
        }
    }
    render('change_password', ['title' => '修改密碼', 'err' => $err, 'ok' => $ok]);
    exit;
}


http_response_code(404);
echo '找不到頁面';

} catch (\PDOException $e) {
    http_response_code(503);
    exit('資料庫連線逾時或中斷，請重新整理再試一次。若持續發生，請檢查網路連線是否穩定。');
} catch (\Throwable $e) {
    // 兜底：避免任何未預期例外把完整 stack trace（含絕對路徑）直接印給瀏覽器。
    // log 只記類別/訊息/檔案位置，刻意不用 getTraceAsString()——PHP 7.3 沒有
    // zend.exception_ignore_args 這個 ini（7.4+ 才有），trace 裡每個 frame 的參數
    // 一定會被印出來，萬一某次例外剛好發生在密碼相關呼叫（登入、改密碼）中途，
    // 密碼明碼就會被完整寫進這份 log。
    error_log('[newsys/index] ' . get_class($e) . '：' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    exit('系統發生錯誤，請重新整理後再試。若持續發生，請聯絡系統管理員。');
}

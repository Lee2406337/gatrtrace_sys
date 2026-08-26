<?php /** @var string $ym */ /** @var array $events */
// 「不定期」已併入「其他」，新建待辦不再提供此選項（既有事件總攬帶入的舊資料若為「不定期」，
// 挑選後由下方 JS 自動改標為「其他」）。
$freqs = \App\EventFrequency::selectableValues();
$statuses = \App\TodoValidator::STATUSES; ?>
<h1>新增本月待辦（<?= htmlspecialchars($ym) ?>）</h1>
<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>
<form method="post" action="index.php?r=todos&action=save&ym=<?= urlencode($ym) ?>">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <label>從既有事件總攬帶入（選填）</label>
  <select id="event_picker">
    <option value="">— 手動輸入 —</option>
    <?php foreach ($events as $e): ?>
      <option value="<?= htmlspecialchars(json_encode(['category'=>$e['category'],'task_name'=>$e['task_name'],'frequency'=>$e['frequency'],'department'=>$e['department'],'note'=>$e['note']], JSON_UNESCAPED_UNICODE)) ?>">
        <?= htmlspecialchars($e['task_name']) ?>（<?= htmlspecialchars(frequency_label($e['frequency'])) ?>）
      </option>
    <?php endforeach; ?>
  </select>
  <label>類別 *</label><input name="category" id="f_category" required>
  <label>工作事項 *</label><input name="task_name" id="f_task" required>
  <label>頻率 *</label>
  <select name="frequency" id="f_freq" required>
    <?php foreach ($freqs as $f): ?><option value="<?= $f ?>"><?= frequency_label($f) ?></option><?php endforeach; ?>
  </select>
  <label>應完成日 *（YYYY-MM-DD；無指定日期可留空歸入下方提醒區）</label>
  <input name="due_date" type="date">
  <label>單位</label>
  <select name="department" id="f_department">
    <option value="">—</option>
    <?php foreach (\App\Departments::ALL as $d): ?><option value="<?= $d ?>"><?= $d ?></option><?php endforeach; ?>
  </select>
  <label>目前狀態 *</label>
  <select name="status" id="f_status" required><?php foreach ($statuses as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select>
  <div id="next-due-wrap" class="hidden">
    <label>下一次需要處理的時間 *（頻率為「其他」且狀態為已完成時必填，系統會自動建立下一筆待辦）</label>
    <input name="next_due_date" type="date" id="f_next_due">
  </div>
  <label>備註</label><textarea name="note" id="f_note"></textarea>
  <p><button class="btn" type="submit">儲存</button>
     <a class="btn btn-secondary" href="index.php?r=todos&ym=<?= urlencode($ym) ?>">取消</a></p>
</form>

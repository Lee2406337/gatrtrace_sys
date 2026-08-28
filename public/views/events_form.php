<?php /** @var ?array $row */
// 「不定期」已併入「其他」，新增/一般編輯不再提供此選項；
// 若編輯的既有資料 frequency 剛好是「不定期」，仍保留在清單中，避免存檔時被靜默改成其他頻率。
$freqs = [
    \App\EventFrequency::Weekly, \App\EventFrequency::Monthly,
    \App\EventFrequency::OddMonth, \App\EventFrequency::EvenMonth, \App\EventFrequency::HalfYear,
    \App\EventFrequency::Yearly, \App\EventFrequency::TwoYear, \App\EventFrequency::ThreeYear,
];
if (($row['frequency'] ?? '') === \App\EventFrequency::Irregular) { $freqs[] = \App\EventFrequency::Irregular; }
$freqs[] = \App\EventFrequency::ByContract;
$freqs[] = \App\EventFrequency::Other;
$weekdays = [];
for ($w = 1; $w <= 7; $w++) { $weekdays[(string) $w] = '週' . baseline_pad2($w); }
$curYear = (int) date('Y');
?>
<h1><?= $row ? '編輯' : '新增' ?>例行事項</h1>
<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>
<form method="post" action="index.php?r=events&action=save">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
  <label>類別 *</label>
  <input name="category" required value="<?= htmlspecialchars($row['category'] ?? '') ?>">
  <label>工作事項 *</label>
  <input name="task_name" required value="<?= htmlspecialchars($row['task_name'] ?? '') ?>">
  <label>頻率 *</label>
  <select name="frequency" id="frequency" required
    data-existing-freq="<?= htmlspecialchars($row['frequency'] ?? '') ?>"
    data-existing-baseline="<?= htmlspecialchars((string)($row['baseline_value'] ?? '')) ?>">
    <?php foreach ($freqs as $f): ?>
      <option value="<?= $f ?>" <?= (($row['frequency'] ?? '') === $f) ? 'selected' : '' ?>><?= frequency_label($f) ?></option>
    <?php endforeach; ?>
  </select>

  <label>基準值</label>
  <div id="baseline-groups">
    <div class="baseline-group hidden" data-freq="每週">
      <select id="bl-week">
        <option value="">請選擇星期</option>
        <?php foreach ($weekdays as $v => $label): ?>
          <option value="<?= $v ?>"><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="baseline-group hidden" data-freq="每月,單數月,雙數月">
      <select id="bl-month-day">
        <option value="">請選擇號數</option>
        <?php for ($d = 1; $d <= 31; $d++): ?>
          <option value="<?= $d ?>"><?= baseline_pad2($d) ?>號</option>
        <?php endfor; ?>
        <option value="EOM">月底（EOM）</option>
      </select>
    </div>
    <div class="baseline-group hidden" data-freq="每年">
      <select id="bl-yearly-m"><option value="">月</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= baseline_pad2($m) ?>月</option><?php endfor; ?></select>
      <select id="bl-yearly-d"><option value="">日</option><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= baseline_pad2($d) ?>號</option><?php endfor; ?></select>
    </div>
    <div class="baseline-group hidden" data-freq="半年">
      <div>第一組：
        <select id="bl-half1-m"><option value="">月</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= baseline_pad2($m) ?>月</option><?php endfor; ?></select>
        <select id="bl-half1-d"><option value="">日</option><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= baseline_pad2($d) ?>號</option><?php endfor; ?></select>
      </div>
      <div>第二組：
        <select id="bl-half2-m"><option value="">月</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= baseline_pad2($m) ?>月</option><?php endfor; ?></select>
        <select id="bl-half2-d"><option value="">日</option><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= baseline_pad2($d) ?>號</option><?php endfor; ?></select>
      </div>
    </div>
    <div class="baseline-group hidden" data-freq="2年,3年">
      <select id="bl-multi-y"><option value="">年</option><?php for ($y = $curYear - 5; $y <= $curYear + 10; $y++): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?></select>
      <select id="bl-multi-m"><option value="">月</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= baseline_pad2($m) ?>月</option><?php endfor; ?></select>
      <select id="bl-multi-d"><option value="">日</option><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= baseline_pad2($d) ?>號</option><?php endfor; ?></select>
    </div>
    <p id="baseline-none" class="text-hint">此頻率不需基準值</p>
  </div>
  <input type="hidden" name="baseline_value" id="baseline_value">

  <label>單位 *</label>
  <select name="department" required>
    <?php foreach (\App\Departments::ALL as $d): ?>
      <option value="<?= $d ?>" <?= (($row['department'] ?? '') === $d) ? 'selected' : '' ?>><?= $d ?></option>
    <?php endforeach; ?>
  </select>
  <label>備註</label>
  <textarea name="note"><?= htmlspecialchars((string)($row['note'] ?? '')) ?></textarea>
  <p><button class="btn" type="submit">儲存</button>
     <a class="btn btn-secondary" href="index.php?r=events">取消</a></p>
</form>

<?php /** @var int $year */ /** @var array $months */ /** @var int $current */
$weekdayNames = ['日','一','二','三','四','五','六']; ?>
<h1>月份行事曆（<?= (int)$year ?> 年）
  <span class="legend">
    <span class="legend-dot cell-lightblue"></span> 每週／每月事項
    <span class="legend-dot cell-lightgreen"></span> 半年／每年／2年／3年事項
    <span class="legend-dot cell-lightred"></span> 合約到期日
  </span>
</h1>
<div class="cal-tabs">
  <?php for ($m = 1; $m <= 12; $m++): ?>
    <button type="button" class="btn btn-secondary cal-tab<?= $m === $current ? ' active' : '' ?>" data-month="<?= $m ?>"><?= $m ?> 月</button>
  <?php endfor; ?>
</div>

<?php foreach ($months as $m => $cal): ?>
  <section class="cal-month<?= $m === $current ? '' : ' hidden' ?>" data-month="<?= (int)$m ?>">
    <h2><?= (int)$m ?> 月</h2>
    <table class="cal-grid">
      <thead><tr><?php foreach ($weekdayNames as $w): ?><th><?= $w ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <tr>
        <?php
          // 前置空格：firstWeekday 已轉成 1=日..7=六，對齊上面表頭順序
          for ($i = 1; $i < $cal['firstWeekday']; $i++) { echo '<td class="cal-empty"></td>'; }
          $col = $cal['firstWeekday'];
          for ($d = 1; $d <= $cal['daysInMonth']; $d++):
            $cell = $cal['cells'][$d];
        ?>
          <td class="cal-day <?= $cell['class'] ?>">
            <div class="cal-daynum"><?= $d ?></div>
            <?php foreach ($cell['items'] as $it): ?>
              <div class="cal-item"><?= htmlspecialchars($it) ?></div>
            <?php endforeach; ?>
          </td>
        <?php
            if ($col % 7 === 0 && $d < $cal['daysInMonth']) { echo '</tr><tr>'; }
            $col++;
          endfor;
          while (($col - 1) % 7 !== 0) { echo '<td class="cal-empty"></td>'; $col++; }
        ?>
        </tr>
      </tbody>
    </table>

    <h3>本月提醒／未指定日期事項</h3>
    <table>
      <thead><tr><th>類型</th><th>項目名稱</th><th>說明／備註</th></tr></thead>
      <tbody>
      <?php if (empty($cal['undated'])): ?>
        <tr><td colspan="3">（無）</td></tr>
      <?php endif; ?>
      <?php foreach ($cal['undated'] as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['type']) ?></td>
          <td><?= htmlspecialchars($u['name']) ?></td>
          <td><?= htmlspecialchars($u['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach; ?>

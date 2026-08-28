// 全站共用行為，統一集中在這裡（CSP 收緊：script-src/style-src 不再有
// 'unsafe-inline'，所以不能用 onXXX="..." 屬性、內嵌 <script>、或 element.style
// 直接賦值；一律用 addEventListener + classList 切換 .hidden 這個 CSS class）。
// 每段都用 IIFE 包起來，且開頭一定先確認目標元素存在才動作，因為同一份
// app.js 會在前台/後台每一頁載入，不是每頁都有對應的元素。

// 送出前確認（取代 onsubmit="return confirm('...')"，改用 <form data-confirm="訊息">）
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) { e.preventDefault(); }
});

// 選單改變即送出表單（取代 onchange="this.form.submit()"，改用 class="auto-submit-select"）
document.addEventListener('change', function (e) {
  if (e.target.matches && e.target.matches('.auto-submit-select')) { e.target.form.submit(); }
});

// 簽核關卡設定：切換「角色」／「指定人員」欄位（取代 onchange="toggleSigner(this)"，改用 class="signer-kind-select"）
document.addEventListener('change', function (e) {
  if (!e.target.matches || !e.target.matches('.signer-kind-select')) { return; }
  var form = e.target.closest('form');
  var role = form && form.querySelector('[data-signer="role"]');
  var user = form && form.querySelector('[data-signer="user"]');
  if (!role || !user) { return; }
  var isUser = e.target.value === 'user';
  role.classList.toggle('hidden', isUser);
  user.classList.toggle('hidden', !isUser);
});

// 登入頁：Caps Lock 提示
(function () {
  var pwd = document.getElementById('login-password');
  var hint = document.getElementById('caps-lock-hint');
  if (!pwd || !hint) { return; }
  function check(e) {
    var on = typeof e.getModifierState === 'function' && e.getModifierState('CapsLock');
    hint.classList.toggle('hidden', !on);
  }
  pwd.addEventListener('keydown', check);
  pwd.addEventListener('keyup', check);
  pwd.addEventListener('blur', function () { hint.classList.add('hidden'); });
})();

// 使用者後台：新增使用者時動態加部門列
(function () {
  var list = document.getElementById('new-user-depts');
  var pick = document.getElementById('new-user-dept-pick');
  var pickManager = document.getElementById('new-user-dept-pick-manager');
  var addBtn = document.getElementById('new-user-dept-add');
  if (!list || !pick || !addBtn) { return; }
  addBtn.addEventListener('click', function () {
    var dept = pick.value;
    if (!dept) { return; }
    var isManager = pickManager.checked;
    var row = document.createElement('div');
    row.className = 'dept-row';
    var label = document.createElement('span');
    label.textContent = dept + (isManager ? '（主管）' : '');
    var hDept = document.createElement('input');
    hDept.type = 'hidden'; hDept.name = 'department[]'; hDept.value = dept;
    var hManager = document.createElement('input');
    hManager.type = 'hidden'; hManager.name = 'is_dept_manager[]'; hManager.value = isManager ? '1' : '0';
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button'; removeBtn.className = 'btn-link'; removeBtn.textContent = '移除';
    removeBtn.addEventListener('click', function () { row.remove(); });
    row.appendChild(label); row.appendChild(hDept); row.appendChild(hManager); row.appendChild(removeBtn);
    list.appendChild(row);
    pick.value = ''; pickManager.checked = false;
  });
})();

// 使用者後台：姓名／工號／Email 篩選
(function () {
  var searchInput = document.getElementById('user-search');
  if (!searchInput) { return; }
  searchInput.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('table.admin-users tbody tr').forEach(function (tr) {
      var name = tr.querySelector('input[name="name"]');
      var staffId = tr.querySelector('input[name="staff_id"]');
      var email = tr.querySelector('input[name="email"]');
      var haystack = [
        name ? name.value : '',
        staffId ? staffId.value : '',
        email ? email.value : ''
      ].join(' ').toLowerCase();
      tr.classList.toggle('hidden', !(q === '' || haystack.indexOf(q) !== -1));
    });
  });
})();

// 月份行事曆：切換月份分頁
(function () {
  var tabs = document.querySelectorAll('.cal-tab');
  if (!tabs.length) { return; }
  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = this.getAttribute('data-month');
      document.querySelectorAll('.cal-month').forEach(function (s) {
        s.classList.toggle('hidden', s.getAttribute('data-month') !== m);
      });
      document.querySelectorAll('.cal-tab').forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
    });
  });
})();

// 本月待辦：狀態選「已完成」才顯示「下一次需要處理的時間」欄位
(function () {
  var forms = document.querySelectorAll('.status-form');
  if (!forms.length) { return; }
  forms.forEach(function (form) {
    var sel = form.querySelector('.status-select');
    var nd = form.querySelector('.next-due-input');
    if (!sel || !nd) { return; }
    function sync() { nd.classList.toggle('hidden', sel.value !== '已完成'); }
    sel.addEventListener('change', sync);
    sync();
  });
})();

// 新增本月待辦：同上邏輯 + 從既有事項總攬帶入
(function () {
  var freqSel = document.getElementById('f_freq');
  var statusSel = document.getElementById('f_status');
  var nextDueWrap = document.getElementById('next-due-wrap');
  if (!freqSel || !statusSel || !nextDueWrap) { return; }

  function syncNextDue() {
    var isOther = freqSel.value === '其他' || freqSel.value === '不定期';
    nextDueWrap.classList.toggle('hidden', !(isOther && statusSel.value === '已完成'));
  }
  freqSel.addEventListener('change', syncNextDue);
  statusSel.addEventListener('change', syncNextDue);
  syncNextDue();

  var eventPicker = document.getElementById('event_picker');
  if (eventPicker) {
    eventPicker.addEventListener('change', function () {
      if (!this.value) { return; }
      var d = JSON.parse(this.value);
      document.getElementById('f_category').value = d.category || '';
      document.getElementById('f_task').value = d.task_name || '';
      freqSel.value = d.frequency || '每月';
      if (freqSel.value !== (d.frequency || '每月')) { freqSel.value = '其他'; } // 舊資料「不定期」等清單已無此選項時回退
      document.getElementById('f_department').value = d.department || '';
      document.getElementById('f_note').value = d.note || '';
      syncNextDue();
    });
  }
})();

// 例行事項表單：依頻率切換基準值輸入群組
(function () {
  var freqSel = document.getElementById('frequency');
  if (!freqSel) { return; }
  var groups = Array.prototype.slice.call(document.querySelectorAll('.baseline-group'));
  var noneMsg = document.getElementById('baseline-none');
  var hiddenInput = document.getElementById('baseline_value');

  function pad2(n) { n = String(n); return n.length < 2 ? '0' + n : n; }
  function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }

  function groupFor(freq) {
    for (var i = 0; i < groups.length; i++) {
      if (groups[i].getAttribute('data-freq').split(',').indexOf(freq) !== -1) return groups[i];
    }
    return null;
  }

  function composeValue() {
    var freq = freqSel.value, v = '';
    if (freq === '每週') {
      v = val('bl-week');
    } else if (freq === '每月' || freq === '單數月' || freq === '雙數月') {
      v = val('bl-month-day');
    } else if (freq === '每年') {
      var m = val('bl-yearly-m'), d = val('bl-yearly-d');
      v = (m && d) ? (pad2(m) + '-' + pad2(d)) : '';
    } else if (freq === '半年') {
      var m1 = val('bl-half1-m'), d1 = val('bl-half1-d'), m2 = val('bl-half2-m'), d2 = val('bl-half2-d');
      var p1 = (m1 && d1) ? (pad2(m1) + '-' + pad2(d1)) : '';
      var p2 = (m2 && d2) ? (pad2(m2) + '-' + pad2(d2)) : '';
      v = (p1 && p2) ? (p1 + ',' + p2) : '';
    } else if (freq === '2年' || freq === '3年') {
      var y = val('bl-multi-y'), mm = val('bl-multi-m'), dd = val('bl-multi-d');
      v = (y && mm && dd) ? (y + '-' + pad2(mm) + '-' + pad2(dd)) : '';
    }
    hiddenInput.value = v;
  }

  function refreshVisibility() {
    var active = groupFor(freqSel.value);
    groups.forEach(function (g) { g.classList.toggle('hidden', g !== active); });
    if (noneMsg) { noneMsg.classList.toggle('hidden', !!active); }
    composeValue();
  }

  groups.forEach(function (g) {
    Array.prototype.forEach.call(g.querySelectorAll('select'), function (sel) {
      sel.addEventListener('change', composeValue);
    });
  });
  freqSel.addEventListener('change', refreshVisibility);

  // 編輯模式：把既有 baseline_value 依頻率拆回對應下拉選單（資料來自 #frequency 的 data-* 屬性）
  var existingFreq = freqSel.getAttribute('data-existing-freq');
  var existingBaseline = freqSel.getAttribute('data-existing-baseline') || '';
  if (existingFreq) {
    if (existingFreq === '每週') {
      document.getElementById('bl-week').value = existingBaseline;
    } else if (existingFreq === '每月' || existingFreq === '單數月' || existingFreq === '雙數月') {
      document.getElementById('bl-month-day').value = existingBaseline;
    } else if (existingFreq === '每年') {
      var md = existingBaseline.split('-');
      if (md[0]) document.getElementById('bl-yearly-m').value = String(parseInt(md[0], 10));
      if (md[1]) document.getElementById('bl-yearly-d').value = String(parseInt(md[1], 10));
    } else if (existingFreq === '半年') {
      var parts = existingBaseline.split(',');
      if (parts[0]) {
        var p1 = parts[0].split('-');
        if (p1[0]) document.getElementById('bl-half1-m').value = String(parseInt(p1[0], 10));
        if (p1[1]) document.getElementById('bl-half1-d').value = String(parseInt(p1[1], 10));
      }
      if (parts[1]) {
        var p2 = parts[1].split('-');
        if (p2[0]) document.getElementById('bl-half2-m').value = String(parseInt(p2[0], 10));
        if (p2[1]) document.getElementById('bl-half2-d').value = String(parseInt(p2[1], 10));
      }
    } else if (existingFreq === '2年' || existingFreq === '3年') {
      var ymd = existingBaseline.split('-');
      if (ymd.length === 3) {
        document.getElementById('bl-multi-y').value = ymd[0];
        document.getElementById('bl-multi-m').value = String(parseInt(ymd[1], 10));
        document.getElementById('bl-multi-d').value = String(parseInt(ymd[2], 10));
      }
    }
  }

  refreshVisibility();
})();

// 人員搜尋選擇器。
// 用法：包一層 <div class="person-picker" data-options='[{"id":1,"label":"王小明"}]'>，
// 裡面放 .person-picker-input（文字框，顯示用）、.person-picker-value（hidden，實際送出的 id）、
// .person-picker-menu.hidden（建議清單容器，空 div 即可，JS 會自己填內容）。
(function () {
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function highlight(label, q) {
    if (!q) { return escapeHtml(label); }
    var idx = label.toLowerCase().indexOf(q.toLowerCase());
    if (idx === -1) { return escapeHtml(label); }
    return escapeHtml(label.slice(0, idx)) + '<strong>' + escapeHtml(label.slice(idx, idx + q.length)) + '</strong>' + escapeHtml(label.slice(idx + q.length));
  }

  function initPersonPicker(root) {
    var input = root.querySelector('.person-picker-input');
    var hidden = root.querySelector('.person-picker-value');
    var menu = root.querySelector('.person-picker-menu');
    if (!input || !hidden || !menu) { return; }
    var options = [];
    try { options = JSON.parse(root.getAttribute('data-options') || '[]'); } catch (e) { options = []; }
    var activeIndex = -1;
    var currentList = []; // 目前選單裡實際顯示的項目（篩選後的子集），Enter 鍵要對這份索引，不是對整份 options

    function updateActive(items) {
      items.forEach(function (it, i) { it.classList.toggle('active', i === activeIndex); });
      if (activeIndex >= 0) { items[activeIndex].scrollIntoView({ block: 'nearest' }); }
    }

    function select(opt) {
      input.value = opt.label;
      hidden.value = opt.id;
      menu.classList.add('hidden');
    }

    function render(list) {
      menu.innerHTML = '';
      activeIndex = -1;
      currentList = list.slice(0, 20);
      if (!currentList.length) { menu.classList.add('hidden'); return; }
      var q = input.value.trim();
      currentList.forEach(function (opt) {
        var row = document.createElement('div');
        row.className = 'person-picker-item';
        row.innerHTML = highlight(opt.label, q);
        row.addEventListener('mousedown', function (e) { e.preventDefault(); select(opt); });
        menu.appendChild(row);
      });
      menu.classList.remove('hidden');
    }

    function filter() {
      var q = input.value.trim().toLowerCase();
      hidden.value = ''; // 打字中先清空，真的選到才算數，避免打一半就送出舊的 id
      if (!q) { menu.classList.add('hidden'); return; }
      render(options.filter(function (o) { return o.label.toLowerCase().indexOf(q) !== -1; }));
    }

    input.addEventListener('input', filter);
    input.addEventListener('focus', function () { if (input.value.trim()) { filter(); } });
    input.addEventListener('blur', function () {
      setTimeout(function () { menu.classList.add('hidden'); }, 150);
      // 文字剛好精準對到某個選項（例如貼上完整姓名）就直接視為選定，不用一定要點清單
      if (!hidden.value) {
        var exact = options.filter(function (o) { return o.label === input.value.trim(); })[0];
        if (exact) { hidden.value = exact.id; }
      }
    });
    input.addEventListener('keydown', function (e) {
      var items = menu.querySelectorAll('.person-picker-item');
      if (!items.length) { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1); updateActive(items); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); updateActive(items); }
      else if (e.key === 'Enter') { if (activeIndex >= 0 && currentList[activeIndex]) { e.preventDefault(); select(currentList[activeIndex]); } }
      else if (e.key === 'Escape') { menu.classList.add('hidden'); }
    });
  }

  document.querySelectorAll('.person-picker').forEach(initPersonPicker);
})();

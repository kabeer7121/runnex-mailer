/* Runnex Email Marketing – front-end helpers (no framework) */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('_csrf', csrf);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  // show / hide password (eye icon) on every password field
  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.9 8.3 2 12 2 12s3.5 7 10 7c1.9 0 3.6-.6 5-1.4"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  $$('input[type=password]').forEach(function (inp) {
    if (inp.closest('.pw-wrap')) return;
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.setAttribute('aria-label', 'Show password');
    btn.title = 'Show password';
    btn.innerHTML = EYE;
    btn.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.title = show ? 'Hide password' : 'Show password';
      btn.setAttribute('aria-label', btn.title);
      inp.focus();
    });
    wrap.appendChild(btn);
  });

  // filter/search forms: keep the address bar clean (no internal "p=" and no empty fields)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if ((f.method || '').toLowerCase() !== 'get' || /index\.php/.test(location.pathname)) return;
    $$('input[name=p]', f).forEach(function (i) { i.disabled = true; });
    $$('input, select', f).forEach(function (i) { if (i.name && i.value === '' && i.type !== 'checkbox') i.disabled = true; });
  });

  window.addEventListener('pageshow', function () { $$('form[method=get] [disabled]').forEach(function (i) { i.disabled = false; }); });

  // close user menu on outside click
  document.addEventListener('click', function (e) {
    $$('.user-menu.open').forEach(function (m) { if (!m.contains(e.target)) m.classList.remove('open'); });
  });

  // select-all checkboxes
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-checkall]')) {
      var scope = t.closest('table') || t.closest('form');
      $$('input[type=checkbox]', scope).forEach(function (c) { if (c !== t && !c.disabled) c.checked = t.checked; });
    }
    if (t.matches('[data-checkall-scope]')) {
      $$('input[type=checkbox]', $(t.getAttribute('data-checkall-scope'))).forEach(function (c) { if (c !== t) c.checked = t.checked; });
    }
    if (t.matches('[data-bulk-action]')) bulkShow(t);
  });
  function bulkShow(sel) {
    $$('[data-show-for]', sel.form).forEach(function (el) {
      el.style.display = el.getAttribute('data-show-for').split(' ').indexOf(sel.value) >= 0 ? '' : 'none';
    });
  }
  $$('[data-bulk-action]').forEach(bulkShow);

  // copy code blocks
  $$('[data-copy]').forEach(function (el) {
    el.title = 'Click to copy';
    el.addEventListener('click', function () {
      if (navigator.clipboard) navigator.clipboard.writeText(el.textContent.trim()).then(function () { el.style.outline = '2px solid #16a34a'; setTimeout(function () { el.style.outline = ''; }, 800); });
    });
  });

  // provider presets on account form
  var prov = $('[data-provider]');
  if (prov) prov.addEventListener('change', function () {
    var p = JSON.parse(prov.selectedOptions[0].getAttribute('data-preset'));
    ['smtp_host', 'smtp_port', 'smtp_secure', 'imap_host', 'imap_port'].forEach(function (k) {
      var f = prov.form.elements[k];
      if (f && p[k] !== undefined && p[k] !== '') f.value = p[k];
    });
    if (prov.value === 'custom') $('details.adv').open = true;
  });

  // account form: name suggestion + live connection test
  var accEmail = $('[data-acc-email]'), accName = $('[data-acc-name]');
  if (accEmail && accName) accEmail.addEventListener('change', function () {
    if (accName.value) return;
    var local = accEmail.value.split('@')[0].replace(/\d+/g, '').replace(/[._-]+/g, ' ').trim();
    accName.value = local.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  });
  var testBtn = $('[data-acc-test]');
  if (testBtn) testBtn.addEventListener('click', function () {
    var f = testBtn.form, out = $('[data-acc-result]');
    var data = {};
    ['email', 'password', 'provider', 'smtp_host', 'smtp_port', 'smtp_secure', 'imap_host', 'imap_port', 'username'].forEach(function (k) { if (f.elements[k]) data[k] = f.elements[k].value; });
    var m = location.search.match(/[?&]id=(\d+)/);
    if (m) data.id = m[1];
    testBtn.disabled = true;
    out.className = 'small';
    out.textContent = 'Testing… (up to 20 seconds)';
    post('index.php?p=api&a=test_account', data).then(function (r) {
      out.className = 'small ' + (r.ok ? 'c-ok' : 'c-bad');
      out.textContent = (r.ok ? '✓ ' : '✕ ') + r.message;
    }).catch(function () { out.className = 'small c-bad'; out.textContent = 'Test failed – check your internet connection.'; })
      .then(function () { testBtn.disabled = false; });
  });

  // verification provider fields
  var vprov = $('[data-vprov]');
  function vShow() { $$('[data-vshow]').forEach(function (el) { el.style.display = el.getAttribute('data-vshow').split(' ').indexOf(vprov.value) >= 0 ? '' : 'none'; }); }
  if (vprov) { vprov.addEventListener('change', vShow); vShow(); }

  // custom fields
  var kvAdd = $('[data-kv-add]');
  if (kvAdd) kvAdd.addEventListener('click', function () {
    var box = $('[data-kv]'), row = box.lastElementChild.cloneNode(true);
    $$('input', row).forEach(function (i) { i.value = ''; });
    box.appendChild(row);
  });

  // rich text editor
  function initEditor(ed) {
    if (ed._init) return;
    ed._init = true;
    var area = $('.editor-area', ed), src = $('.editor-src', ed), srcBtn = $('[data-source]', ed);
    area.innerHTML = src.value;
    var sync = function () { src.value = area.innerHTML; src.dispatchEvent(new Event('input', { bubbles: true })); };
    area.addEventListener('input', sync);
    area.addEventListener('blur', sync);
    src.addEventListener('input', function (e) { if (!src.hidden && e.isTrusted) area.innerHTML = src.value; });
    area.addEventListener('paste', function (e) {
      // paste as plain text – pasted Word/Docs formatting hurts deliverability
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });
    $$('[data-cmd]', ed).forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var cmd = b.getAttribute('data-cmd');
        if (cmd === 'createLink') {
          var u = prompt('Link URL (https://…)');
          if (!u) return;
          document.execCommand('createLink', false, u);
        } else document.execCommand(cmd, false, null);
        sync();
      });
    });
    var ins = $('[data-insert]', ed);
    ins.addEventListener('change', function () {
      if (!ins.value) return;
      if (src.hidden) {
        area.focus();
        document.execCommand('insertText', false, ins.value);
        sync();
      } else {
        var p = src.selectionStart || 0;
        src.value = src.value.slice(0, p) + ins.value + src.value.slice(p);
        area.innerHTML = src.value;
      }
      ins.value = '';
    });
    srcBtn.addEventListener('click', function () {
      if (src.hidden) { src.value = area.innerHTML; src.hidden = false; area.style.display = 'none'; srcBtn.classList.add('on'); }
      else { area.innerHTML = src.value; src.hidden = true; area.style.display = ''; srcBtn.classList.remove('on'); sync(); }
    });
    ed.setContent = function (html) { area.innerHTML = html; src.value = html; sync(); };
  }
  $$('[data-editor]').forEach(function (ed) { if (!ed.closest('template')) initEditor(ed); });
  document.addEventListener('submit', function (e) {
    $$('[data-editor]', e.target).forEach(function (ed) {
      var src = $('.editor-src', ed), area = $('.editor-area', ed);
      if (src.hidden) src.value = area.innerHTML;
    });
  }, true);

  // campaign steps
  var stepsBox = $('[data-steps]');
  function renumber() {
    $$('[data-step]', stepsBox).forEach(function (s, i) {
      $('[data-step-title]', s).textContent = i === 0 ? 'Email 1' : 'Follow-up ' + i;
      $('.step-delay', s).hidden = i === 0;
      $('[data-step-remove]', s).hidden = i === 0;
    });
  }
  if (stepsBox) {
    var counter = 1000;
    var addStep = function (delay, subject, body) {
      var html = $('#step-tpl').innerHTML.replace(/__i__/g, String(counter++));
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var step = wrap.firstElementChild;
      stepsBox.appendChild(step);
      var ed = $('[data-editor]', step);
      initEditor(ed);
      if (delay !== undefined) {
        $('.step-delay input', step).value = Math.max(1, delay);
        $('[data-sc-subject]', step).value = subject || '';
        ed.setContent(body || '');
      }
      renumber();
      return step;
    };
    $('[data-step-add]').addEventListener('click', function () { addStep(); });
    var seqSel = $('[data-load-sequence]');
    if (seqSel) seqSel.addEventListener('change', function () {
      var key = seqSel.value;
      if (!key) return;
      var seq = JSON.parse(seqSel.getAttribute('data-library'))[key];
      seqSel.value = '';
      if (!confirm('Replace all emails in this campaign with "' + seq.name + '"?')) return;
      var steps = $$('[data-step]', stepsBox);
      steps.slice(1).forEach(function (s) { s.remove(); });
      var first = steps[0];
      $('[data-sc-subject]', first).value = seq.steps[0].subject;
      $('[data-editor]', first).setContent(seq.steps[0].body_html);
      seq.steps.slice(1).forEach(function (s) { addStep(s.delay_days, s.subject, s.body_html); });
      var name = $('input[name=name]', seqSel.form);
      if (name && !name.value) name.value = seq.name;
      first.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    stepsBox.addEventListener('click', function (e) {
      var b = e.target.closest('[data-step-remove]');
      if (b && confirm('Remove this follow-up?')) { b.closest('[data-step]').remove(); renumber(); }
    });
    var tplSel = $('[data-load-template]');
    if (tplSel) tplSel.addEventListener('change', function () {
      var o = tplSel.selectedOptions[0];
      if (!tplSel.value) return;
      var first = $('[data-step]', stepsBox);
      $('[data-sc-subject]', first).value = o.getAttribute('data-subject');
      $('[data-editor]', first).setContent(o.getAttribute('data-body'));
      tplSel.value = '';
    });
  }

  // live spam check
  $$('[data-spamcheck]').forEach(function (box) {
    var out = $('[data-sc-result]', box) || $('[data-sc-result]');
    var timer = null, current = null;
    function scope(el) { return el.closest('[data-step]') || box; }
    function run() {
      var s = current || box;
      var subj = $('[data-sc-subject]', s), body = $('.editor-src', s) || $('[data-sc-body]', s);
      if (!body) return;
      var ed = body.closest('[data-editor]');
      var html = ed && body.hidden ? $('.editor-area', ed).innerHTML : body.value;
      post('index.php?p=api&a=spamcheck', { subject: subj ? subj.value : '', body: html }).then(function (r) {
        var color = r.score >= 85 ? '#16a34a' : r.score >= 70 ? '#65a30d' : r.score >= 50 ? '#d97706' : '#dc2626';
        var label = current && current.matches('[data-step]') ? $('[data-step-title]', current).textContent : '';
        var h = '<div class="score"><b style="color:' + color + '">' + r.score + '</b><div><b>' + esc(r.grade) + '</b>' + (label ? '<div class="small muted">' + esc(label) + '</div>' : '') +
          '<div class="small muted">' + r.words + ' words · ' + r.links + ' links</div></div></div><ul>';
        r.issues.forEach(function (i) { h += '<li class="' + i.level + '">' + esc(i.msg) + '</li>'; });
        out.innerHTML = h + '</ul>';
      }).catch(function () {});
    }
    function schedule(e) {
      if (!e.target.closest('[data-step], [data-sc-subject], [data-sc-body], [data-editor]')) return;
      current = scope(e.target);
      clearTimeout(timer);
      timer = setTimeout(run, 500);
    }
    box.addEventListener('input', schedule);
    box.addEventListener('focusin', schedule);
    if ($('.editor-src', box) || ($('[data-sc-body]', box) && $('[data-sc-body]', box).value)) { current = $('[data-step]', box) || box; setTimeout(run, 300); }
  });

  // charts
  function charts() {
    if (!window.Chart) return;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#71717a';
    $$('canvas[data-chart]').forEach(function (cv) {
      var c = JSON.parse(cv.getAttribute('data-chart'));
      var stacked = c.opts && c.opts.stacked;
      var ds = c.datasets.map(function (d) {
        var o = { label: d.label, data: d.data, borderWidth: 2, tension: 0.3, pointRadius: 0, pointHitRadius: 8 };
        if (c.type === 'doughnut') { o.backgroundColor = d.colors; o.borderWidth = 1; }
        else if (c.type === 'bar') { o.backgroundColor = d.color; o.borderRadius = 3; if (d.stack) o.stack = d.stack; }
        else { o.borderColor = d.color; o.backgroundColor = d.color + '22'; o.fill = false; }
        return o;
      });
      new Chart(cv, {
        type: c.type, data: { labels: c.labels, datasets: ds },
        options: {
          responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } } },
          scales: c.type === 'doughnut' ? {} : {
            x: { stacked: !!stacked, grid: { display: false }, ticks: { maxTicksLimit: 10 } },
            y: { stacked: !!stacked, beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f1f3' } }
          }
        }
      });
    });
  }
  if (window.Chart) charts(); else window.addEventListener('load', charts);
})();

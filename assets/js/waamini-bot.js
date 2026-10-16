/* WAAMINI Bot client helpers. Exposes window.WBC. Needs an element with data-wbc, data-api and data-token. */
(function () {
  var root = document.querySelector('[data-wbc]');
  if (!root) { return; }
  var API = root.getAttribute('data-api');
  var TOKEN = root.getAttribute('data-token');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; });
  }
  function linkify(escaped) {
    return escaped.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
  }
  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function api(action, data, formData) {
    var opts = {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': TOKEN, 'Accept': 'application/json'}};
    if (formData) {
      formData.append('action', action);
      formData.append('_token', TOKEN);
      opts.body = formData;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(Object.assign({action: action, _token: TOKEN}, data || {}));
    }
    return fetch(API, opts).then(function (r) {
      return r.json().catch(function () { return {ok: false, error: 'Unexpected server response.'}; });
    }).catch(function () { return {ok: false, error: 'Network problem. Please try again.'}; });
  }

  var toastEl = document.getElementById('wbcToast');
  var toastTimer = null;
  function toast(msg, ok) {
    if (!toastEl) { return; }
    toastEl.className = 'wbc-toast is-show ' + (ok === false ? 'is-err' : 'is-ok');
    toastEl.textContent = msg;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.className = 'wbc-toast'; }, 6000);
  }

  var STATUS = {
    queued: ['is-off', 'Queued'], training: ['is-info', '<span class="wbc-spin"></span> Training'],
    success: ['is-ok', 'Success'], failed: ['is-bad', 'Failed']
  };
  var ICON_REFRESH = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>';
  var ICON_TRASH = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>';

  /** Render the sources table body. type: 'url' | 'file' */
  function renderSources(tbody, sources, type, handlers) {
    var rows = (sources || []).filter(function (s) { return s.type === type; });
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="4"><div class="wbc-empty">' + (type === 'url' ? 'No pages yet. Add your website above.' : 'No documents yet.') + '</div></td></tr>';
      return rows;
    }
    tbody.innerHTML = rows.map(function (s) {
      var st = STATUS[s.status] || STATUS.queued;
      var label = type === 'url' ? esc(s.title || s.uri) : esc(s.title || s.uri);
      var sub = type === 'url' ? '<small>' + esc(s.uri) + '</small>' : '';
      return '<tr data-id="' + s.id + '"><td><span class="wbc-url">' + label + sub + '</span>' + (s.error ? '<span class="wbc-err">' + esc(s.error) + '</span>' : '') + '</td>'
        + '<td><span class="db-pill ' + st[0] + '">' + st[1] + '</span></td>'
        + '<td>' + (s.status === 'success' ? fmtBytes(s.bytes) : '—') + '</td>'
        + '<td style="white-space:nowrap">' + (type === 'url' ? '<button type="button" class="wbc-ic-btn" data-retrain="' + s.id + '" title="Retrain">' + ICON_REFRESH + '</button> ' : '')
        + '<button type="button" class="wbc-ic-btn is-bad" data-del="' + s.id + '" title="Delete">' + ICON_TRASH + '</button></td></tr>';
    }).join('');
    return rows;
  }

  /** Poll "tick" until nothing is queued or training. cb(resp) is called after every response. */
  var ticking = false;
  function tickLoop(cb) {
    if (ticking) { return; }
    ticking = true;
    (function next() {
      api('tick').then(function (r) {
        if (cb) { cb(r); }
        if (r && r.ok && r.remaining > 0) { setTimeout(next, 800); } else { ticking = false; }
      });
    })();
  }

  /** The test chat, styled like the WhatsApp app. Runs the real engine, sends nothing to WhatsApp. */
  var TICK1 = '<svg viewBox="0 0 16 11" width="16" height="11" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="2 6 5.5 9.5 13 2"/></svg>';
  var TICK2 = '<svg viewBox="0 0 16 11" width="16" height="11" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 6 4 9 10 2"/><polyline points="6 8 7.5 9 14 2"/></svg>';
  function clock() { return new Date().toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true}); }

  function playground(cfg) {
    var msgs = cfg.msgs, input = cfg.input, sendBtn = cfg.send, history = [];
    var busy = false;

    function scroll() { msgs.scrollTop = msgs.scrollHeight; }
    function dropHint() { var h = msgs.querySelector('[data-hint]'); if (h) { h.remove(); } }
    function dayChip() {
      if (msgs.querySelector('.wa-day')) { return; }
      var d = document.createElement('div'); d.className = 'wa-day'; d.innerHTML = '<span>Today</span>'; msgs.appendChild(d);
    }
    function addUser(text) {
      dropHint(); dayChip();
      var d = document.createElement('div');
      d.className = 'wbc-m is-user';
      d.innerHTML = '<div class="wbc-bubble">' + esc(text) + '<span class="wa-meta">' + clock() + '<i class="wa-tick">' + TICK1 + '</i></span></div>';
      msgs.appendChild(d); scroll();
      return d;
    }
    function markRead(node) { var t = node && node.querySelector('.wa-tick'); if (t) { t.innerHTML = TICK2; t.classList.add('is-read'); } }
    function addTyping() {
      var d = document.createElement('div');
      d.className = 'wbc-m is-bot'; d.setAttribute('data-typing', '1');
      d.innerHTML = '<div class="wbc-bubble"><span class="wbc-typing"><i></i><i></i><i></i></span></div>';
      msgs.appendChild(d); scroll(); return d;
    }
    function sysLine(r) {
      var parts = [];
      (r.steps || []).forEach(function (s) {
        if (s.type === 'search') { parts.push('Searched knowledge (' + (s.found || 0) + ' found)'); }
        else if (s.type === 'qa') { parts.push('Used an approved answer'); }
        else if (s.type === 'handoff') { parts.push(s.label); }
        else if (s.type === 'contact') { parts.push('Saved customer details'); }
      });
      if (!parts.length) { return ''; }
      return '<div class="wa-sys"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>' + esc(parts.join(' · ')) + '</div>';
    }
    function sources(r) {
      if (!r.pages || !r.pages.length) { return ''; }
      return '<div class="wa-src">' + r.pages.slice(0, 4).map(function (p) {
        var t = p.title || p.url;
        return '<a href="' + esc(p.url) + '" target="_blank" rel="noopener noreferrer" title="' + esc(p.url) + '"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg><span>' + esc(t) + '</span></a>';
      }).join('') + '</div>';
    }
    function addBot(r, question) {
      var d = document.createElement('div');
      d.className = 'wbc-m is-bot';
      var text = r.text || r.error || 'No answer.';
      var sys = sysLine(r);
      if (sys) { var s = document.createElement('div'); s.innerHTML = sys; msgs.appendChild(s.firstChild); }
      d.innerHTML = '<div class="wbc-bubble">' + linkify(esc(text)) + '<span class="wa-meta">' + clock() + '</span></div>' + sources(r)
        + '<div class="wbc-m__acts"><button type="button" class="wbc-link" data-retest>Retest</button>'
        + (r.text && !r.error ? '<button type="button" class="wbc-link" data-fix>Fix this answer</button>' : '') + '</div>';
      d.setAttribute('data-q', question);
      d.setAttribute('data-a', r.text || '');
      msgs.appendChild(d); scroll();
    }

    function ask(text, replaceNode) {
      if (busy || !text.trim()) { return; }
      busy = true; sendBtn.disabled = true;
      var mine = null;
      if (replaceNode) { replaceNode.remove(); } else { mine = addUser(text); history.push({role: 'user', content: text}); }
      var typing = addTyping();
      api('test_chat', {messages: history.slice(-20)}).then(function (r) {
        typing.remove();
        if (mine) { markRead(mine); }
        if (!r.ok) {
          if (r.unavailable) { toast(r.error, false); }
          addBot({text: '', error: r.error || 'The test could not run.'}, text);
        } else {
          addBot(r, text);
          if (r.text) { history.push({role: 'assistant', content: r.text}); }
          if (cfg.onResult) { cfg.onResult(r); }
        }
      }).then(function () { busy = false; sendBtn.disabled = false; input.focus(); });
    }

    function send() {
      var t = input.value.trim();
      if (!t) { return; }
      input.value = ''; input.style.height = '';
      ask(t);
    }
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(120, input.scrollHeight) + 'px'; });

    msgs.addEventListener('click', function (e) {
      var node = e.target.closest('.wbc-m.is-bot');
      if (!node) { return; }
      if (e.target.closest('[data-retest]')) {
        var q = node.getAttribute('data-q');
        // drop the old answer (and its status line) from the chat and the history, then ask again
        if (history.length && history[history.length - 1].role === 'assistant') { history.pop(); }
        if (history.length && history[history.length - 1].role === 'user') { history.pop(); }
        var prev = node.previousElementSibling;
        if (prev && prev.classList.contains('wa-sys')) { var p2 = prev.previousElementSibling; prev.remove(); prev = p2; }
        if (prev && prev.classList.contains('is-user')) { prev.remove(); }
        node.remove();
        ask(q);
      } else if (e.target.closest('[data-fix]')) {
        if (node.querySelector('.wbc-fix')) { return; }
        var f = document.createElement('div');
        f.className = 'wbc-fix';
        f.innerHTML = '<label class="wbc-lab">Customer question</label><input class="wbc-input" data-fq value="' + esc(node.getAttribute('data-q')) + '">'
          + '<label class="wbc-lab" style="margin-top:8px">Correct answer</label><textarea class="wbc-area" data-fa>' + esc(node.getAttribute('data-a')) + '</textarea>'
          + '<div class="wbc-btn-row"><button type="button" class="db-btn db-btn--primary wbc-btn-sm" data-fsave>Save as Q&amp;A</button><button type="button" class="db-btn wbc-btn-sm" data-fcancel>Cancel</button></div>';
        node.appendChild(f); scroll();
      } else if (e.target.closest('[data-fcancel]')) {
        node.querySelector('.wbc-fix').remove();
      } else if (e.target.closest('[data-fsave]')) {
        var q2 = node.querySelector('[data-fq]').value, a2 = node.querySelector('[data-fa]').value;
        api('fix_answer', {question: q2, answer: a2}).then(function (r) {
          if (r.ok) { toast('Saved. Click Retest to see the corrected answer.', true); node.querySelector('.wbc-fix').remove(); }
          else { toast(r.error || 'Could not save.', false); }
        });
      }
    });

    if (cfg.reset) { cfg.reset.addEventListener('click', function () { history = []; msgs.innerHTML = cfg.hint ? cfg.hint() : ''; if (cfg.onReset) { cfg.onReset(); } }); }
    return {ask: ask};
  }

  window.WBC = {api: api, toast: toast, esc: esc, fmtBytes: fmtBytes, renderSources: renderSources, tickLoop: tickLoop, playground: playground, linkify: linkify};
})();

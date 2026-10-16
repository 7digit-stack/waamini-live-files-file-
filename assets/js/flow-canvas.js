/* WAAMINI Flow Builder canvas. Plain JS, no dependencies.
 * The graph is saved as nodes[] (id, type, position, config) and edges[] (id, source_node, source_port, target_node)
 * and is the only thing the execution engine follows. */
(function () {
  'use strict';
  var CFG = window.FLOW_CFG || {};
  var NODE_W = 290;

  /* ── tiny helpers ─────────────────────────────────────────────────────── */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function uid(p) { return (p || 'n') + '_' + Math.random().toString(36).slice(2, 10); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function h(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }

  var SV = function (p) { return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>'; };
  var ICON = {
    trigger: SV('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>'),
    send: SV('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'),
    question: SV('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'),
    condition: SV('<path d="M6 3v12"/><path d="M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M15 6a9 9 0 0 0-9 9"/><path d="M18 15v6"/><path d="M21 18h-6"/>'),
    delay: SV('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
    action: SV('<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'),
    end: SV('<circle cx="12" cy="12" r="10"/><rect x="9" y="9" width="6" height="6" rx="1"/>'),
    undo: SV('<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>'),
    redo: SV('<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13"/>'),
    plus: SV('<path d="M5 12h14"/><path d="M12 5v14"/>'),
    trash: SV('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>'),
    x: SV('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
    chev: SV('<path d="m9 18 6-6-6-6"/>')
  };
  var TYPES = {
    trigger:   { label: 'Trigger',  color: '#128C7E', desc: 'What starts the flow' },
    send:      { label: 'Message',  color: '#0284C7', desc: 'Text, media, buttons, list, template' },
    question:  { label: 'Question', color: '#7C3AED', desc: 'Ask and save the answer' },
    condition: { label: 'Condition', color: '#D97706', desc: 'Yes / No branch' },
    delay:     { label: 'Delay',    color: '#64748B', desc: 'Wait, then continue' },
    action:    { label: 'Action',   color: '#EA580C', desc: 'Tags, fields, agent, webhook' },
    end:       { label: 'End',      color: '#DC2626', desc: 'Finish the conversation' }
  };
  var KINDS = [['text', 'Text'], ['image', 'Image'], ['video', 'Video'], ['document', 'File'], ['buttons', 'Buttons'], ['list', 'List'], ['template', 'Template']];
  var ACTIONS = [['add_tag', 'Add tag'], ['remove_tag', 'Remove tag'], ['update_field', 'Update contact field'], ['assign_agent', 'Assign to agent'], ['webhook', 'Call webhook'], ['handover_ai', 'Hand over to AI bot'], ['handover_human', 'Hand over to human agent']];
  var LIM = { buttons: 3, button_title: 20, list_rows: 10, row_title: 24, body: 1024, text: 4096 };

  /* ── state ────────────────────────────────────────────────────────────── */
  var S = {
    nodes: {}, edges: {}, sel: {}, selEdge: null, view: { x: 60, y: 60, z: 1 }, meta: { templates: [], agents: [], tags: [], contact_fields: [], limits: LIM },
    flow: {}, issues: [], dirty: false, saving: false, again: false, version: 0, hist: [], hi: -1, histLock: false, link: null, drag: null, pan: null, box: null, menuCtx: null, settings: null
  };
  var root = $('#fb'), view = $('#fbView'), world = $('#fbWorld'), svg = $('#fbEdges'), insp = $('#fbInsp'), toastEl = $('#fbToast');

  /* ── api ──────────────────────────────────────────────────────────────── */
  function api(action, data, method) {
    var opt = { method: method || (data ? 'POST' : 'GET'), headers: { 'X-CSRF-Token': CFG.csrf, 'Accept': 'application/json' }, credentials: 'same-origin' };
    var url = CFG.api;
    if (opt.method === 'POST') { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(Object.assign({ action: action, flow_id: CFG.flowId }, data || {})); }
    else { url += '?action=' + encodeURIComponent(action) + '&flow_id=' + CFG.flowId + (data ? '&' + data : ''); }
    return fetch(url, opt).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Bad response' }; }).then(function (j) { j._status = r.status; return j; }); });
  }
  function toast(msg, ms) { toastEl.textContent = msg; toastEl.classList.add('is-on'); clearTimeout(toast.t); toast.t = setTimeout(function () { toastEl.classList.remove('is-on'); }, ms || 2600); }

  /* ── ports (same rules as includes/flow/nodes.php) ────────────────────── */
  function portsOf(n) {
    var c = n.config || {};
    switch (n.type) {
      case 'trigger': return [{ id: 'out', label: 'Start', kind: 'main' }];
      case 'question': return [{ id: 'next', label: 'Answer', kind: 'main' }, { id: 'fallback', label: 'No valid answer', kind: 'fallback' }].concat(+c.timeout_minutes > 0 ? [{ id: 'timeout', label: 'No reply after ' + minLabel(+c.timeout_minutes), kind: 'timeout' }] : []);
      case 'condition': return [{ id: 'yes', label: 'Yes', kind: 'branch' }, { id: 'no', label: 'No', kind: 'branch' }];
      case 'delay': return [{ id: 'next', label: 'After wait', kind: 'main' }];
      case 'end': return [];
      case 'action': return (c.action === 'handover_ai' || c.action === 'handover_human') ? [] : [{ id: 'next', label: 'Next', kind: 'main' }];
      case 'send':
        if (c.kind === 'buttons') return (c.buttons || []).map(function (b) { return { id: b.id, label: b.title || 'Button', kind: 'branch' }; }).concat([{ id: 'fallback', label: 'No match', kind: 'fallback' }]).concat(+c.timeout_minutes > 0 ? [{ id: 'timeout', label: 'No reply after ' + minLabel(+c.timeout_minutes), kind: 'timeout' }] : []);
        if (c.kind === 'list') return (c.rows || []).map(function (r) { return { id: r.id, label: r.title || 'Row', kind: 'branch' }; }).concat([{ id: 'fallback', label: 'No match', kind: 'fallback' }]).concat(+c.timeout_minutes > 0 ? [{ id: 'timeout', label: 'No reply after ' + minLabel(+c.timeout_minutes), kind: 'timeout' }] : []);
        return [{ id: 'next', label: 'Next', kind: 'main' }];
    }
    return [];
  }
  function minLabel(m) { return (m >= 60 && m % 60 === 0) ? (m / 60) + (m === 60 ? ' hour' : ' hours') : m + ' min'; }
  var TIMEOUTS = [[0, 'Never (wait for the customer)'], [15, 'After 15 minutes'], [30, 'After 30 minutes'], [60, 'After 1 hour'], [120, 'After 2 hours'], [360, 'After 6 hours'], [720, 'After 12 hours'], [1440, 'After 24 hours']];
  function timeoutField(c) {
    var cur = +c.timeout_minutes || 0, opts = TIMEOUTS.slice(); if (!opts.some(function (o) { return o[0] === cur; })) opts.push([cur, 'After ' + minLabel(cur)]);
    return fld('If the customer does not reply', sel('timeout_minutes', opts, cur), cur > 0 ? 'A "No reply after ' + minLabel(cur) + '" output appears on the step. Connect it to the follow-up message. It runs once, in the background, only if the customer has not answered.' : 'Optional follow-up when the customer goes quiet.');
  }
  function waits(n) { return n.type === 'question' || (n.type === 'send' && (n.config.kind === 'buttons' || n.config.kind === 'list')); }

  function defaults(type) {
    switch (type) {
      case 'trigger': return { mode: 'keyword', rules: [{ match: 'contains', value: '' }], reply_ids: [], cooldown_hours: 12 };
      case 'send': return { kind: 'text', text: '' };
      case 'question': return { text: '', save_as: 'answer', field: '', validate: 'none', retry_text: 'Sorry, that does not look right. Please try again.', max_retries: 1 };
      case 'condition': return { source: 'tag', name: '', operator: 'equals', value: '', from: '09:00', to: '18:00' };
      case 'delay': return { amount: 10, unit: 'minutes' };
      case 'action': return { action: 'add_tag', tag: '', field: 'name', value: '', agent_id: 0, url: '', method: 'POST', body: '', message: '' };
      case 'end': return { message: '' };
    }
    return {};
  }

  /* ── view transform ───────────────────────────────────────────────────── */
  function applyView() {
    world.style.transform = 'translate(' + S.view.x + 'px,' + S.view.y + 'px) scale(' + S.view.z + ')';
    view.style.backgroundPosition = S.view.x + 'px ' + S.view.y + 'px';
    view.style.backgroundSize = (24 * S.view.z) + 'px ' + (24 * S.view.z) + 'px';
    drawMini();
  }
  function toWorld(cx, cy) { var r = view.getBoundingClientRect(); return { x: (cx - r.left - S.view.x) / S.view.z, y: (cy - r.top - S.view.y) / S.view.z }; }
  function zoomAt(z, cx, cy) {
    z = clamp(z, 0.3, 1.6);
    var r = view.getBoundingClientRect(), px = cx - r.left, py = cy - r.top;
    S.view.x = px - (px - S.view.x) * (z / S.view.z);
    S.view.y = py - (py - S.view.y) * (z / S.view.z);
    S.view.z = z; applyView();
  }
  function bbox(ids) {
    var list = (ids || Object.keys(S.nodes)).map(function (i) { return S.nodes[i]; }).filter(Boolean);
    if (!list.length) return null;
    var x1 = 1e9, y1 = 1e9, x2 = -1e9, y2 = -1e9;
    list.forEach(function (n) { var hgt = n._el ? n._el.offsetHeight : 120; x1 = Math.min(x1, n.x); y1 = Math.min(y1, n.y); x2 = Math.max(x2, n.x + NODE_W); y2 = Math.max(y2, n.y + hgt); });
    return { x1: x1, y1: y1, x2: x2, y2: y2 };
  }
  function fit() {
    var b = bbox(); var r = view.getBoundingClientRect();
    if (!b) { S.view = { x: 260, y: 120, z: 1 }; applyView(); return; }
    var padL = 250, padR = insp.classList.contains('is-open') ? 390 : 60, pad = 70;
    var w = b.x2 - b.x1, hh = b.y2 - b.y1;
    var z = clamp(Math.min((r.width - padL - padR) / w, (r.height - pad * 2) / hh), 0.35, 1);
    S.view.z = z;
    S.view.x = padL + ((r.width - padL - padR) - w * z) / 2 - b.x1 * z;
    S.view.y = (r.height - hh * z) / 2 - b.y1 * z;
    applyView();
  }
  function centerOn(n) {
    var r = view.getBoundingClientRect();
    S.view.x = r.width / 2 - (n.x + NODE_W / 2) * S.view.z - (insp.classList.contains('is-open') ? 180 : 0) + 100;
    S.view.y = r.height / 2 - (n.y + 60) * S.view.z; applyView();
  }

  /* ── Bot Reply button catalog (synced from Bot Replies) ───────────────── */
  function botById(id) { return (S.meta.bot_replies || []).filter(function (b) { return b.id === +id; })[0] || null; }
  function btnLabel(id) {
    var list = S.meta.bot_replies || [];
    for (var i = 0; i < list.length; i++) for (var j = 0; j < list[i].buttons.length; j++) if (list[i].buttons[j].id === id) return list[i].buttons[j].title;
    return id;
  }
  /* make a Send > Buttons node mirror its Bot Reply: text, footer and labels follow, ids (= canvas ports) are kept */
  function linkBot(n, bot) {
    editNode(n, function (c) {
      c.kind = 'buttons'; c.bot_reply_id = bot.id; c.text = String(bot.text || '').slice(0, LIM.body); c.footer = bot.footer || '';
      c.buttons = bot.buttons.map(function (b) { return { id: b.id, title: b.title }; });
    }, true);
  }
  function syncLinked(silent) {
    return api('botreplies').then(function (r) {
      if (!r.ok) return 0;
      S.meta.bot_replies = r.bot_replies; var changed = 0, gone = 0;
      Object.keys(S.nodes).forEach(function (id) {
        var n = S.nodes[id]; if (n.type !== 'send' || !n.config.bot_reply_id) return;
        var bot = botById(n.config.bot_reply_id); if (!bot) { gone++; return; }
        var before = JSON.stringify(n.config); var probe = clone(n.config);
        probe.text = String(bot.text || '').slice(0, LIM.body); probe.footer = bot.footer || ''; probe.buttons = bot.buttons.map(function (b) { return { id: b.id, title: b.title }; });
        if (JSON.stringify(probe) !== before) { linkBot(n, bot); changed++; }
      });
      Object.keys(S.nodes).forEach(function (id) { renderNode(S.nodes[id]); }); renderEdges(); refreshInspector();
      if (!silent) toast(changed ? changed + ' step' + (changed > 1 ? 's' : '') + ' updated from Bot Replies' : (gone ? 'A linked Bot Reply was deleted or switched off. The last copy is kept.' : 'Already in sync with Bot Replies'), 3200);
      else if (changed) toast(changed + ' button label' + (changed > 1 ? 's' : '') + ' synced from Bot Replies', 3200);
      return changed;
    });
  }

  /* ── node summary text ────────────────────────────────────────────────── */
  function trim(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
  function summary(n) {
    var c = n.config || {};
    switch (n.type) {
      case 'trigger':
        if (c.mode === 'keyword') { var v = (c.rules || []).filter(function (r) { return r.value; }).map(function (r) { return r.value; }); return { t: v.length ? 'Keyword: ' + v.join(', ') : '', e: 'Add a keyword rule', tags: (c.rules || []).slice(0, 1).map(function (r) { return { exact: 'Exact', contains: 'Contains', starts: 'Starts with', regex: 'Regex' }[r.match]; }) }; }
        if (c.mode === 'from_ad') return { t: c.ad_match === 'ad_id' && c.ad_value ? 'From ad: ' + c.ad_value : (c.ad_match === 'headline' && c.ad_value ? 'From an ad about "' + c.ad_value + '"' : 'Lead came from any Click-to-WhatsApp ad'), tags: ['Ad lead'] };
        return { t: { new_contact: 'First message from a new contact', any_message: 'Any message outside a flow', button_reply: (c.reply_ids || []).length ? 'Button reply: ' + c.reply_ids.map(btnLabel).join(', ') : 'Any button or template reply', fallback: 'Default fallback: when nothing else answers' }[c.mode] || '' };
      case 'send':
        if (c.kind === 'template') { var tp = S.meta.templates.filter(function (t) { return t.id === c.template_id; })[0]; return { t: tp ? 'Template: ' + tp.name : '', e: 'Choose a template', tags: ['Template'] }; }
        if (c.kind === 'image' || c.kind === 'video' || c.kind === 'document') return { t: c.text || (c.media_url ? (c.filename || (c.kind === 'image' ? 'Image attached' : (c.kind === 'video' ? 'Video attached' : 'File attached'))) : ''), e: 'Upload or link a file', tags: [c.kind[0].toUpperCase() + c.kind.slice(1)] };
        return { t: c.text, e: 'Write the message', tags: c.kind === 'buttons' ? ['Reply buttons'].concat(c.bot_reply_id ? ['Linked: ' + (botById(c.bot_reply_id) ? botById(c.bot_reply_id).name : 'Bot Reply')] : []) : c.kind === 'list' ? ['List'] : [] };
      case 'question': return { t: c.text, e: 'Write the question', tags: ['Saves to {{' + (c.save_as || 'answer') + '}}'].concat(c.validate && c.validate !== 'none' ? ['Check: ' + c.validate] : []) };
      case 'condition':
        if (c.source === 'time') return { t: 'Time is between ' + c.from + ' and ' + c.to };
        if (c.source === 'tag') return { t: c.name ? 'Contact has tag "' + c.name + '"' : '', e: 'Choose a tag' };
        return { t: c.name ? (c.source === 'field' ? 'Contact ' : 'Variable ') + c.name + ' ' + String(c.operator || '').replace('_', ' ') + (/empty/.test(c.operator) ? '' : ' "' + (c.value || '') + '"') : '', e: 'Set the check' };
      case 'delay': return { t: 'Wait ' + c.amount + ' ' + c.unit };
      case 'action': {
        var a = c.action;
        var m = { add_tag: 'Add tag "' + (c.tag || '') + '"', remove_tag: 'Remove tag "' + (c.tag || '') + '"', update_field: 'Set ' + c.field + ' = "' + trim(c.value, 24) + '"', assign_agent: 'Assign to agent', webhook: c.method + ' ' + trim(c.url, 34), handover_ai: 'Hand over to the AI bot', handover_human: 'Hand over to a human agent' };
        return { t: m[a] || '' };
      }
      case 'end': return { t: c.message || 'Ends the conversation', plain: !c.message };
    }
    return { t: '' };
  }

  /* ── render nodes ─────────────────────────────────────────────────────── */
  function renderNode(n) {
    var T = TYPES[n.type], sm = summary(n), ports = portsOf(n);
    var el = n._el;
    if (!el) { el = document.createElement('div'); n._el = el; world.appendChild(el); }
    el.className = 'fb-node' + (S.sel[n.id] ? ' is-sel' : '') + (n._issues ? ' has-err' : '');
    el.dataset.id = n.id;
    el.style.left = n.x + 'px'; el.style.top = n.y + 'px'; el.style.setProperty('--c', T.color);
    var title = n.type === 'send' ? ({ text: 'Send text', image: 'Send image', video: 'Send video', document: 'Send file', buttons: 'Send buttons', list: 'Send list', template: 'Send template' }[n.config.kind] || 'Message') : T.label;
    var html = '';
    if (n.type !== 'trigger') html += '<div class="fb-port in" data-node="' + n.id + '" data-port="in"></div>';
    html += '<div class="fb-nh"><div class="fb-ic" style="--c:' + T.color + '">' + ICON[n.type] + '</div><div class="fb-nt"><b>' + esc(title) + '</b><small>' + esc(T.label) + '</small></div></div>';
    html += '<div class="fb-nb">';
    html += '<div class="fb-prev' + (sm.t ? '' : ' is-empty') + '">' + esc(sm.t ? trim(sm.t, 140) : (sm.e || '—')) + '</div>';
    if (n.type === 'send' && n.config.kind === 'image' && /^https?:/i.test(n.config.media_url || '')) html += '<img class="fb-thumb" alt="" src="' + esc(n.config.media_url) + '">';
    if (sm.tags && sm.tags.filter(Boolean).length) html += '<div class="fb-meta">' + sm.tags.filter(Boolean).map(function (t) { return '<span class="fb-tag">' + esc(t) + '</span>'; }).join('') + '</div>';
    html += '</div>';
    var branches = ports.filter(function (p) { return p.kind !== 'main'; });
    if (ports.length === 1 && ports[0].kind === 'main') {
      html += '<div class="fb-port out main" data-node="' + n.id + '" data-port="' + ports[0].id + '"></div>';
    } else if (branches.length) {
      html += '<div class="fb-outs">' + ports.map(function (p) {
        var cls = p.kind === 'fallback' ? ' is-fb' : (p.kind === 'timeout' ? ' is-to' : (p.id === 'yes' ? ' is-yes' : (p.id === 'no' ? ' is-no' : '')));
        var lab = p.kind === 'fallback' ? p.label + ' <em>optional</em>' : esc(trim(p.label, 26));
        return '<div class="fb-out' + cls + '"><span>' + (p.kind === 'fallback' ? lab : lab) + '</span><div class="fb-port out' + (p.kind === 'fallback' ? ' is-fb' : (p.kind === 'timeout' ? ' is-to' : '')) + '" data-node="' + n.id + '" data-port="' + p.id + '"></div></div>';
      }).join('') + '</div>';
    }
    if (n._issues) html += '<div class="fb-warn" title="' + esc(n._issuesText || '') + '">' + n._issues + '</div>';
    el.innerHTML = html;
    measure(n);
    var th = el.querySelector('.fb-thumb'); if (th) th.addEventListener('load', function () { measure(n); renderEdges(); });
  }
  function measure(n) {
    var el = n._el; if (!el) return;
    var nr = el.getBoundingClientRect(), z = S.view.z || 1;
    n._ports = {};
    $$('.fb-port.out', el).forEach(function (p) {
      var r = p.getBoundingClientRect();
      n._ports[p.dataset.port] = { x: (r.left + r.width / 2 - nr.left) / z, y: (r.top + r.height / 2 - nr.top) / z };
      p.classList.toggle('is-on', hasEdgeFrom(n.id, p.dataset.port));
    });
    var ip = $('.fb-port.in', el);
    if (ip) { var r2 = ip.getBoundingClientRect(); n._in = { x: (r2.left + r2.width / 2 - nr.left) / z, y: (r2.top + r2.height / 2 - nr.top) / z }; }
  }
  function hasEdgeFrom(id, port) { return Object.keys(S.edges).some(function (k) { var e = S.edges[k]; return e.source_node === id && e.source_port === port; }); }
  function edgeFrom(id, port) { var k = Object.keys(S.edges).filter(function (k) { var e = S.edges[k]; return e.source_node === id && e.source_port === port; })[0]; return k ? S.edges[k] : null; }

  /* ── render edges ─────────────────────────────────────────────────────── */
  function curve(x1, y1, x2, y2) { var dx = Math.max(70, Math.abs(x2 - x1) * 0.5); return 'M' + x1 + ',' + y1 + ' C' + (x1 + dx) + ',' + y1 + ' ' + (x2 - dx) + ',' + y2 + ' ' + x2 + ',' + y2; }
  function renderEdges() {
    var out = '<defs><marker id="fbArrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M1,1 L9,5 L1,9 z" fill="#9FB2AB"/></marker></defs>';
    Object.keys(S.edges).forEach(function (id) {
      var e = S.edges[id], a = S.nodes[e.source_node], b = S.nodes[e.target_node];
      if (!a || !b || !a._ports || !a._ports[e.source_port] || !b._in) return;
      var p = a._ports[e.source_port];
      var x1 = a.x + p.x, y1 = a.y + p.y, x2 = b.x + b._in.x - 2, y2 = b.y + b._in.y;
      var d = curve(x1, y1, x2, y2), mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
      var bad = (a._issues || b._issues) && false;
      out += '<g class="fb-edge-g' + (S.selEdge === id ? ' is-sel' : '') + (bad ? ' is-err' : '') + '" data-edge="' + id + '"><path class="fb-edge" d="' + d + '" marker-end="url(#fbArrow)"/><path class="fb-edge-hit" d="' + d + '"/>' +
        '<g class="fb-edge-x" data-del="' + id + '" transform="translate(' + mx + ',' + my + ')"><circle r="11" fill="#fff" stroke="#E5B4B4" stroke-width="1.5"/><path d="M-4,-4 L4,4 M4,-4 L-4,4" stroke="#DC2626" stroke-width="2" stroke-linecap="round"/></g></g>';
    });
    if (S.link) {
      var s = S.nodes[S.link.src]; var pp = s && s._ports && s._ports[S.link.port];
      if (pp) out += '<path class="fb-edge-temp" d="' + curve(s.x + pp.x, s.y + pp.y, S.link.x, S.link.y) + '"/>';
    }
    svg.innerHTML = out;
    $$('.fb-edge-g', svg).forEach(function (g) { g.querySelector('.fb-edge-hit').style.pointerEvents = 'stroke'; });
    Object.keys(S.nodes).forEach(function (id) { var n = S.nodes[id]; if (n._el) $$('.fb-port.out', n._el).forEach(function (p) { p.classList.toggle('is-on', hasEdgeFrom(id, p.dataset.port)); }); });
    drawMini();
  }
  function renderAll() {
    Object.keys(S.nodes).forEach(function (id) { renderNode(S.nodes[id]); });
    renderEdges(); applyView();
    $('#fbEmpty').style.display = Object.keys(S.nodes).length ? 'none' : 'block';
  }

  /* ── minimap ──────────────────────────────────────────────────────────── */
  function drawMini() {
    var cv = $('#fbMiniCv'); if (!cv) return;
    var w = cv.clientWidth, hgt = cv.clientHeight, dpr = window.devicePixelRatio || 1;
    if (cv.width !== w * dpr) { cv.width = w * dpr; cv.height = hgt * dpr; }
    var ctx = cv.getContext('2d'); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, w, hgt);
    var b = bbox(); var vr = view.getBoundingClientRect();
    var vx1 = -S.view.x / S.view.z, vy1 = -S.view.y / S.view.z, vx2 = vx1 + vr.width / S.view.z, vy2 = vy1 + vr.height / S.view.z;
    var x1 = Math.min(b ? b.x1 : vx1, vx1), y1 = Math.min(b ? b.y1 : vy1, vy1), x2 = Math.max(b ? b.x2 : vx2, vx2), y2 = Math.max(b ? b.y2 : vy2, vy2);
    var sc = Math.min((w - 16) / (x2 - x1), (hgt - 16) / (y2 - y1)); var ox = 8 - x1 * sc + ((w - 16) - (x2 - x1) * sc) / 2, oy = 8 - y1 * sc + ((hgt - 16) - (y2 - y1) * sc) / 2;
    drawMini.m = { sc: sc, ox: ox, oy: oy };
    Object.keys(S.nodes).forEach(function (id) {
      var n = S.nodes[id]; ctx.fillStyle = n._issues ? '#DC2626' : TYPES[n.type].color; ctx.globalAlpha = .85;
      var rh = n._el ? n._el.offsetHeight : 100; rr(ctx, n.x * sc + ox, n.y * sc + oy, NODE_W * sc, rh * sc, 3); ctx.fill();
    });
    ctx.globalAlpha = 1; ctx.strokeStyle = '#128C7E'; ctx.lineWidth = 1.5; ctx.fillStyle = 'rgba(18,140,126,.08)';
    rr(ctx, vx1 * sc + ox, vy1 * sc + oy, (vx2 - vx1) * sc, (vy2 - vy1) * sc, 4); ctx.fill(); ctx.stroke();
  }
  function rr(ctx, x, y, w, hh, r) { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + hh, r); ctx.arcTo(x + w, y + hh, x, y + hh, r); ctx.arcTo(x, y + hh, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }

  /* ── history, dirty, autosave ─────────────────────────────────────────── */
  function snapshot() {
    return JSON.stringify({
      n: Object.keys(S.nodes).map(function (id) { var n = S.nodes[id]; return { id: n.id, type: n.type, x: Math.round(n.x), y: Math.round(n.y), config: n.config }; }),
      e: Object.keys(S.edges).map(function (id) { return S.edges[id]; })
    });
  }
  function commit() {
    if (S.histLock) return;
    var s = snapshot();
    if (S.hist[S.hi] === s) return;
    S.hist = S.hist.slice(0, S.hi + 1); S.hist.push(s); if (S.hist.length > 80) S.hist.shift(); S.hi = S.hist.length - 1;
    markDirty(); updateUndo();
  }
  function restore(s) {
    var o = JSON.parse(s); S.histLock = true;
    $$('.fb-node', world).forEach(function (e) { e.remove(); });
    S.nodes = {}; S.edges = {};
    o.n.forEach(function (n) { S.nodes[n.id] = { id: n.id, type: n.type, x: n.x, y: n.y, config: clone(n.config) }; });
    o.e.forEach(function (e) { S.edges[e.id] = clone(e); });
    Object.keys(S.sel).forEach(function (id) { if (!S.nodes[id]) delete S.sel[id]; });
    S.selEdge = null; applyIssues(S.issues, true); renderAll(); refreshInspector(); S.histLock = false;
  }
  function undo() { if (S.hi > 0) { S.hi--; restore(S.hist[S.hi]); markDirty(); updateUndo(); } }
  function redo() { if (S.hi < S.hist.length - 1) { S.hi++; restore(S.hist[S.hi]); markDirty(); updateUndo(); } }
  function updateUndo() { $('#fbUndo').disabled = S.hi <= 0; $('#fbRedo').disabled = S.hi >= S.hist.length - 1; }
  function setSave(txt, err) { var e = $('#fbSave'); e.textContent = txt; e.classList.toggle('is-err', !!err); }
  function markDirty() { S.dirty = true; setSave('Unsaved changes…'); clearTimeout(markDirty.t); markDirty.t = setTimeout(save, 1200); }

  function graphPayload() {
    return {
      nodes: Object.keys(S.nodes).map(function (id) { var n = S.nodes[id]; return { id: n.id, type: n.type, position: { x: Math.round(n.x), y: Math.round(n.y) }, config: n.config }; }),
      edges: Object.keys(S.edges).map(function (id) { var e = S.edges[id]; return { id: e.id, source_node: e.source_node, source_port: e.source_port, target_node: e.target_node }; })
    };
  }
  function save() {
    clearTimeout(markDirty.t);
    if (S.saving) { S.again = true; return Promise.resolve(); }
    if (!S.dirty) return Promise.resolve();
    S.saving = true; S.dirty = false; setSave('Saving…');
    var st = S.settings || {};
    return api('save', {
      graph: graphPayload(), base_version: S.version, view: [Math.round(S.view.x), Math.round(S.view.y), S.view.z.toFixed(2)].join(','),
      settings: { name: $('#fbName').value, priority: st.priority, idle_minutes: st.idle_minutes, max_steps: st.max_steps }
    }).then(function (r) {
      S.saving = false;
      if (r.conflict) { setSave('Changed in another tab', true); toast('This flow was changed in another tab. Reload the page.', 5000); S.dirty = false; return; }
      if (!r.ok) { S.dirty = true; setSave(r.error || 'Could not save', true); return; }
      S.version = r.version; S.flow.status = r.status; setStatus();
      applyIssues(r.issues || []);
      setSave('Saved ' + (r.saved_at || ''));
      if (r.dropped) toast(r.dropped + ' invalid connection(s) were removed.');
      if (S.again || S.dirty) { S.again = false; save(); }
    }).catch(function () { S.saving = false; S.dirty = true; setSave('Offline — will retry', true); setTimeout(save, 4000); });
  }
  window.addEventListener('beforeunload', function (e) { if (S.dirty || S.saving) { e.preventDefault(); e.returnValue = ''; } });

  /* ── validation highlight ─────────────────────────────────────────────── */
  function applyIssues(issues, quiet) {
    S.issues = issues || [];
    var per = {};
    S.issues.forEach(function (i) { if (i.node_id && i.level === 'error') { per[i.node_id] = per[i.node_id] || []; per[i.node_id].push(i.message); } });
    Object.keys(S.nodes).forEach(function (id) {
      var n = S.nodes[id], list = per[id] || [];
      var changed = (n._issues || 0) !== list.length;
      n._issues = list.length; n._issuesText = list.join('\n');
      if (changed && !quiet) renderNode(n); else if (n._el) { n._el.classList.toggle('has-err', !!n._issues); }
    });
    if (!quiet) renderEdges();
    var errs = S.issues.filter(function (i) { return i.level === 'error'; }).length, warns = S.issues.length - errs;
    var b = $('#fbProbBtn');
    b.className = 'fb-pb ' + (errs ? 'has-err' : 'is-ok');
    b.innerHTML = errs ? (errs + ' problem' + (errs > 1 ? 's' : '') + ' to fix') : (warns ? warns + ' note' + (warns > 1 ? 's' : '') : 'No problems');
    var list = $('#fbProbList');
    list.innerHTML = S.issues.length ? S.issues.map(function (i, k) { return '<button class="fb-prob" data-k="' + k + '"><i class="' + (i.level === 'error' ? '' : 'w') + '"></i><span>' + esc(i.message) + '</span></button>'; }).join('') : '<div class="fb-hint" style="padding:8px 10px">Nothing to fix. This flow is ready to publish.</div>';
    drawMini();
  }

  /* ── selection ────────────────────────────────────────────────────────── */
  function selectOnly(id) { S.sel = {}; if (id) S.sel[id] = true; S.selEdge = null; refreshSel(); }
  function refreshSel() {
    Object.keys(S.nodes).forEach(function (id) { var n = S.nodes[id]; if (n._el) n._el.classList.toggle('is-sel', !!S.sel[id]); });
    var ids = Object.keys(S.sel);
    if (ids.length === 1) openInspector(S.nodes[ids[0]]); else closeInspector();
    renderEdges();
  }

  /* ── create / delete / duplicate / connect ────────────────────────────── */
  function addNode(type, x, y, cfg) {
    if (type === 'trigger' && Object.keys(S.nodes).some(function (i) { return S.nodes[i].type === 'trigger'; })) { toast('A flow can have only one Trigger.'); return null; }
    var n = { id: uid('n'), type: type, x: Math.round(x), y: Math.round(y), config: cfg || defaults(type) };
    S.nodes[n.id] = n; renderNode(n); commit(); return n;
  }
  function viewCenterWorld(off) { var r = view.getBoundingClientRect(); var w = toWorld(r.left + r.width / 2 - 60, r.top + r.height / 2 - 60); var k = off || 0; return { x: w.x - NODE_W / 2 + k * 28, y: w.y + k * 28 }; }
  /* next free place for a new step: right of the selected step, else the middle of the view; never on top of another step */
  function nextSpot() {
    var ids = Object.keys(S.sel), base;
    if (ids.length === 1 && S.nodes[ids[0]]) base = { x: S.nodes[ids[0]].x + NODE_W + 90, y: S.nodes[ids[0]].y };
    else base = viewCenterWorld(0);
    return freeSpot(base.x, base.y);
  }
  function freeSpot(x, y) {
    function hit(px, py) { return Object.keys(S.nodes).some(function (id) { var n = S.nodes[id], hh = n._el ? n._el.offsetHeight : 130; return px < n.x + NODE_W + 30 && px + NODE_W + 30 > n.x && py < n.y + hh + 30 && py + 150 > n.y; }); }
    var tries = 0; while (hit(x, y) && tries < 40) { y += 190; if (++tries % 5 === 0) { x += NODE_W + 90; y -= 190 * 5; } }
    return { x: x, y: y };
  }
  function connect(src, port, target) {
    if (src === target || !S.nodes[src] || !S.nodes[target] || S.nodes[target].type === 'trigger') return false;
    var old = edgeFrom(src, port); if (old) delete S.edges[old.id];
    var e = { id: uid('e'), source_node: src, source_port: port, target_node: target };
    S.edges[e.id] = e; renderEdges(); commit(); return true;
  }
  function removeEdgesOfNode(id) { Object.keys(S.edges).forEach(function (k) { var e = S.edges[k]; if (e.source_node === id || e.target_node === id) delete S.edges[k]; }); }
  function deleteSelection() {
    if (S.selEdge) { delete S.edges[S.selEdge]; S.selEdge = null; renderEdges(); commit(); return; }
    var ids = Object.keys(S.sel); if (!ids.length) return;
    ids.forEach(function (id) { var n = S.nodes[id]; if (n._el) n._el.remove(); removeEdgesOfNode(id); delete S.nodes[id]; });
    S.sel = {}; closeInspector(); renderAll(); commit();
  }
  function duplicateSelection() {
    var ids = Object.keys(S.sel).filter(function (i) { return S.nodes[i] && S.nodes[i].type !== 'trigger'; }); if (!ids.length) return;
    var map = {}; S.sel = {};
    ids.forEach(function (id) { var o = S.nodes[id]; var n = { id: uid('n'), type: o.type, x: o.x + 40, y: o.y + 40, config: clone(o.config) }; map[id] = n.id; S.nodes[n.id] = n; renderNode(n); S.sel[n.id] = true; });
    Object.keys(S.edges).forEach(function (k) { var e = S.edges[k]; if (map[e.source_node] && map[e.target_node]) { var ne = { id: uid('e'), source_node: map[e.source_node], source_port: e.source_port, target_node: map[e.target_node] }; S.edges[ne.id] = ne; } });
    commit(); refreshSel();
  }
  /* edit helper used by the inspector */
  var histTimer;
  function editNode(n, fn, structural) {
    fn(n.config);
    if (structural) { var valid = {}; portsOf(n).forEach(function (p) { valid[p.id] = 1; }); Object.keys(S.edges).forEach(function (k) { var e = S.edges[k]; if (e.source_node === n.id && !valid[e.source_port]) delete S.edges[k]; }); }
    renderNode(n); renderEdges();
    clearTimeout(histTimer);
    if (structural) { commit(); } else { histTimer = setTimeout(commit, 500); }
    if (!structural) markDirty();
  }

  /* ── pointer interaction ──────────────────────────────────────────────── */
  var downPos = null;
  view.addEventListener('pointerdown', function (ev) {
    if (ev.button !== 0 && ev.button !== 1) return;
    hideMenu();
    var t = ev.target;
    var port = t.closest && t.closest('.fb-port.out');
    var delb = t.closest && t.closest('[data-del]');
    var edgeG = t.closest && t.closest('.fb-edge-g');
    var nodeEl = t.closest && t.closest('.fb-node');
    if (delb) { delete S.edges[delb.getAttribute('data-del')]; S.selEdge = null; renderEdges(); commit(); ev.preventDefault(); return; }
    if (port) {
      var n = S.nodes[port.dataset.node]; view.setPointerCapture(ev.pointerId);
      var w = toWorld(ev.clientX, ev.clientY);
      S.link = { src: n.id, port: port.dataset.port, x: w.x, y: w.y }; view.classList.add('is-linking'); n._el.classList.add('is-src'); ev.preventDefault(); return;
    }
    if (edgeG && !nodeEl) { S.sel = {}; S.selEdge = edgeG.getAttribute('data-edge'); refreshSel(); return; }
    if (nodeEl) {
      var id = nodeEl.dataset.id;
      if (ev.target.closest('input,textarea,select,button')) return;
      if (ev.shiftKey) { if (S.sel[id]) delete S.sel[id]; else S.sel[id] = true; S.selEdge = null; refreshSel(); }
      else if (!S.sel[id]) selectOnly(id);
      var ids = Object.keys(S.sel);
      S.drag = { sx: ev.clientX, sy: ev.clientY, moved: false, items: ids.map(function (i) { return { id: i, x: S.nodes[i].x, y: S.nodes[i].y }; }), clickId: id };
      view.setPointerCapture(ev.pointerId); return;
    }
    // background
    if (ev.shiftKey) { var r = view.getBoundingClientRect(); S.box = { x: ev.clientX - r.left, y: ev.clientY - r.top, x2: ev.clientX - r.left, y2: ev.clientY - r.top }; view.setPointerCapture(ev.pointerId); return; }
    S.pan = { sx: ev.clientX, sy: ev.clientY, vx: S.view.x, vy: S.view.y, moved: false };
    view.classList.add('is-pan'); view.setPointerCapture(ev.pointerId);
  });
  view.addEventListener('pointermove', function (ev) {
    if (S.link) { var w = toWorld(ev.clientX, ev.clientY); S.link.x = w.x; S.link.y = w.y; renderEdges(); return; }
    if (S.drag) {
      var dx = (ev.clientX - S.drag.sx) / S.view.z, dy = (ev.clientY - S.drag.sy) / S.view.z;
      if (!S.drag.moved && Math.abs(dx) + Math.abs(dy) < 3 / S.view.z) return;
      S.drag.moved = true;
      S.drag.items.forEach(function (it) { var n = S.nodes[it.id]; n.x = it.x + dx; n.y = it.y + dy; n._el.style.left = n.x + 'px'; n._el.style.top = n.y + 'px'; });
      renderEdges(); return;
    }
    if (S.pan) { S.pan.moved = true; S.view.x = S.pan.vx + ev.clientX - S.pan.sx; S.view.y = S.pan.vy + ev.clientY - S.pan.sy; applyView(); return; }
    if (S.box) {
      var r = view.getBoundingClientRect(); S.box.x2 = ev.clientX - r.left; S.box.y2 = ev.clientY - r.top;
      var b = $('#fbSelBox'); b.style.display = 'block';
      b.style.left = Math.min(S.box.x, S.box.x2) + 'px'; b.style.top = Math.min(S.box.y, S.box.y2) + 'px';
      b.style.width = Math.abs(S.box.x2 - S.box.x) + 'px'; b.style.height = Math.abs(S.box.y2 - S.box.y) + 'px';
    }
  });
  function endPointer(ev) {
    if (S.link) {
      var lk = S.link; S.link = null; view.classList.remove('is-linking'); var sn = S.nodes[lk.src]; if (sn && sn._el) sn._el.classList.remove('is-src');
      var el = document.elementFromPoint(ev.clientX, ev.clientY), nodeEl = el && el.closest && el.closest('.fb-node');
      if (nodeEl && nodeEl.dataset.id !== lk.src) { if (!connect(lk.src, lk.port, nodeEl.dataset.id)) renderEdges(); else toast('Connected', 900); }
      else if (!nodeEl && el && view.contains(el) && !(el.closest && el.closest('.fb-pal,.fb-insp,.fb-zoom,.fb-mini,.fb-pb,.fb-probs,.fb-menu'))) {
        var wp = toWorld(ev.clientX, ev.clientY); showMenu(ev.clientX, ev.clientY, { src: lk.src, port: lk.port, x: wp.x, y: wp.y }); renderEdges();
      } else renderEdges();
    }
    if (S.drag) {
      var d = S.drag; S.drag = null;
      if (d.moved) { commit(); } else if (!ev.shiftKey && Object.keys(S.sel).length > 1) { selectOnly(d.clickId); }
    }
    if (S.pan) {
      var p = S.pan; S.pan = null; view.classList.remove('is-pan');
      if (!p.moved) { S.sel = {}; S.selEdge = null; refreshSel(); } else { markViewDirty(); }
    }
    if (S.box) {
      var bx = S.box; S.box = null; $('#fbSelBox').style.display = 'none';
      var a = toWorld(bx.x + view.getBoundingClientRect().left, bx.y + view.getBoundingClientRect().top), c = toWorld(bx.x2 + view.getBoundingClientRect().left, bx.y2 + view.getBoundingClientRect().top);
      var x1 = Math.min(a.x, c.x), x2 = Math.max(a.x, c.x), y1 = Math.min(a.y, c.y), y2 = Math.max(a.y, c.y);
      S.sel = {}; Object.keys(S.nodes).forEach(function (id) { var n = S.nodes[id], hh = n._el ? n._el.offsetHeight : 100; if (n.x < x2 && n.x + NODE_W > x1 && n.y < y2 && n.y + hh > y1) S.sel[id] = true; }); S.selEdge = null; refreshSel();
    }
  }
  view.addEventListener('pointerup', endPointer);
  view.addEventListener('pointercancel', endPointer);
  function markViewDirty() { clearTimeout(markViewDirty.t); markViewDirty.t = setTimeout(function () { if (!S.dirty) { S.dirty = true; save(); } }, 1500); }
  view.addEventListener('wheel', function (ev) {
    ev.preventDefault();
    if (ev.ctrlKey || ev.metaKey) zoomAt(S.view.z * (ev.deltaY < 0 ? 1.08 : 0.92), ev.clientX, ev.clientY);
    else { S.view.x -= ev.deltaX; S.view.y -= ev.deltaY; applyView(); }
  }, { passive: false });
  view.addEventListener('dblclick', function (ev) { if (ev.target === view || ev.target === world || ev.target === svg) { var w = toWorld(ev.clientX, ev.clientY); showMenu(ev.clientX, ev.clientY, { x: w.x, y: w.y }); } });

  /* palette + quick add menu */
  function palItems(forMenu) {
    return Object.keys(TYPES).map(function (t) {
      var disabled = t === 'trigger' && Object.keys(S.nodes).some(function (i) { return S.nodes[i].type === 'trigger'; });
      return '<button class="fb-pitem" data-add="' + t + '" ' + (forMenu ? '' : 'draggable="true"') + (disabled ? ' disabled' : '') + '><span class="fb-ic" style="--c:' + TYPES[t].color + '">' + ICON[t] + '</span><div><b>' + TYPES[t].label + '</b><small>' + TYPES[t].desc + '</small></div></button>';
    }).join('');
  }
  function renderPalette() { $('#fbPalList').innerHTML = palItems(false); }
  $('#fbPalList').addEventListener('click', function (ev) { var b = ev.target.closest('[data-add]'); if (!b || b.disabled) return; var p = nextSpot(); var from = Object.keys(S.sel).length === 1 ? Object.keys(S.sel)[0] : null; var n = addNode(b.dataset.add, p.x, p.y); if (n) { selectOnly(n.id); renderPalette(); var r = view.getBoundingClientRect(), sx = n.x * S.view.z + S.view.x; if (sx + NODE_W * S.view.z > r.width - 380 || sx < 240) centerOn(n); } });
  $('#fbPalList').addEventListener('dragstart', function (ev) { var b = ev.target.closest('[data-add]'); if (!b) return; ev.dataTransfer.setData('text/flow-node', b.dataset.add); ev.dataTransfer.effectAllowed = 'copy'; });
  view.addEventListener('dragover', function (ev) { if (ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types, 'text/flow-node') >= 0) { ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy'; } });
  view.addEventListener('drop', function (ev) {
    var t = ev.dataTransfer.getData('text/flow-node'); if (!t) return; ev.preventDefault();
    var w = toWorld(ev.clientX, ev.clientY); var n = addNode(t, w.x - NODE_W / 2, w.y - 30); if (n) { selectOnly(n.id); renderPalette(); }
  });
  function showMenu(cx, cy, ctx) {
    var m = $('#fbMenu'); S.menuCtx = ctx; m.innerHTML = '<h4 style="margin:4px 8px 6px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#6B7C76;font-weight:800">Add next step</h4>' + palItems(true);
    var r = view.getBoundingClientRect(); m.style.left = clamp(cx - r.left, 8, r.width - 240) + 'px'; m.style.top = clamp(cy - r.top, 8, r.height - 360) + 'px'; m.classList.add('is-open');
  }
  function hideMenu() { $('#fbMenu').classList.remove('is-open'); S.menuCtx = null; }
  $('#fbMenu').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-add]'); if (!b || b.disabled) return; var c = S.menuCtx; hideMenu(); if (!c) return;
    var n = addNode(b.dataset.add, c.x, c.y - 30); if (!n) return;
    if (c.src) connect(c.src, c.port, n.id);
    selectOnly(n.id); renderPalette();
  });
  document.addEventListener('pointerdown', function (ev) { if (!ev.target.closest('#fbMenu')) hideMenu(); }, true);

  /* ── starter templates ───────────────────────────────────────────────── */
  function templateGraph(kind) {
    var trigger = { mode: 'keyword', rules: [{ match: 'contains', value: 'hi' }], reply_ids: [], cooldown_hours: 12 };
    var nodes = [], edges = [];
    function node(id, type, x, y, config) { nodes.push({ id: id, type: type, x: x, y: y, config: config }); }
    function edge(id, source, port, target) { edges.push({ id: id, source_node: source, source_port: port, target_node: target }); }
    node('trigger', 'trigger', 80, 240, trigger);
    if (kind === 'welcome') {
      node('welcome', 'send', 460, 240, { kind: 'text', text: 'Hi {{contact.name}}! Welcome. How can we help you today?' });
      node('end', 'end', 840, 240, { message: '' });
      edge('e_trigger_welcome', 'trigger', 'out', 'welcome'); edge('e_welcome_end', 'welcome', 'next', 'end');
    } else if (kind === 'menu') {
      node('menu', 'send', 430, 210, { kind: 'buttons', text: 'Hi {{contact.name}}! What can we help you with?', footer: '', buttons: [{ id: 'sales', title: 'Sales' }, { id: 'support', title: 'Support' }, { id: 'pricing', title: 'Pricing' }] });
      node('sales', 'send', 850, 50, { kind: 'text', text: 'Our sales team will help you choose the right plan.' });
      node('support', 'send', 850, 245, { kind: 'text', text: 'Please describe the issue and our support team will help.' });
      node('pricing', 'send', 850, 440, { kind: 'text', text: 'I can share our latest pricing and plans.' });
      node('sales_end', 'end', 1230, 50, { message: '' }); node('support_end', 'end', 1230, 245, { message: '' }); node('pricing_end', 'end', 1230, 440, { message: '' });
      edge('e_trigger_menu', 'trigger', 'out', 'menu'); edge('e_menu_sales', 'menu', 'sales', 'sales'); edge('e_menu_support', 'menu', 'support', 'support'); edge('e_menu_pricing', 'menu', 'pricing', 'pricing');
      edge('e_sales_end', 'sales', 'next', 'sales_end'); edge('e_support_end', 'support', 'next', 'support_end'); edge('e_pricing_end', 'pricing', 'next', 'pricing_end');
    } else if (kind === 'lead') {
      node('ask_email', 'question', 460, 240, { text: 'Thanks for contacting us. What is your email address?', save_as: 'email', field: 'email', validate: 'email', retry_text: 'Please enter a valid email address.', max_retries: 1 });
      node('thanks', 'send', 840, 240, { kind: 'text', text: 'Thanks! We saved your email and will be in touch shortly.' });
      node('end', 'end', 1220, 240, { message: '' });
      edge('e_trigger_question', 'trigger', 'out', 'ask_email'); edge('e_question_thanks', 'ask_email', 'next', 'thanks'); edge('e_thanks_end', 'thanks', 'next', 'end');
    } else {
      node('intro', 'send', 460, 240, { kind: 'text', text: 'Thanks for contacting us. I am connecting you with our team now.' });
      node('handover', 'action', 840, 240, { action: 'handover_human', tag: '', field: 'name', value: '', agent_id: 0, url: '', method: 'POST', body: '', message: '' });
      edge('e_trigger_intro', 'trigger', 'out', 'intro'); edge('e_intro_handover', 'intro', 'next', 'handover');
    }
    return { nodes: nodes, edges: edges };
  }
  function applyTemplate(kind) {
    if (Object.keys(S.nodes).length && !window.confirm('This will replace all nodes and connections in this flow. Continue?')) return;
    var graph = templateGraph(kind);
    Object.keys(S.nodes).forEach(function (id) { if (S.nodes[id]._el) S.nodes[id]._el.remove(); });
    S.nodes = {}; S.edges = {}; S.sel = {}; S.selEdge = null;
    graph.nodes.forEach(function (n) { S.nodes[n.id] = n; }); graph.edges.forEach(function (e) { S.edges[e.id] = e; });
    $('#fbTemplates').classList.remove('is-open'); renderPalette(); renderAll(); fit();
    S.hist = [snapshot()]; S.hi = 0; updateUndo(); S.dirty = true; save();
    selectOnly(kind === 'menu' ? 'menu' : (kind === 'lead' ? 'ask_email' : (kind === 'handoff' ? 'intro' : 'welcome')));
    toast('Template added. Edit the highlighted message, then publish.', 3600);
  }
  $('#fbTemplatesBtn').onclick = function () { $('#fbTemplates').classList.add('is-open'); };
  $('#fbTemplatesClose').onclick = function () { $('#fbTemplates').classList.remove('is-open'); };
  $('#fbTemplates').addEventListener('click', function (ev) { if (ev.target === this) this.classList.remove('is-open'); var b = ev.target.closest('[data-template]'); if (b) applyTemplate(b.dataset.template); });

  /* zoom buttons */
  $('#fbZin').onclick = function () { var r = view.getBoundingClientRect(); zoomAt(S.view.z * 1.2, r.left + r.width / 2, r.top + r.height / 2); };
  $('#fbZout').onclick = function () { var r = view.getBoundingClientRect(); zoomAt(S.view.z / 1.2, r.left + r.width / 2, r.top + r.height / 2); };
  $('#fbFit').onclick = fit;
  $('#fbMiniCv').addEventListener('pointerdown', function (ev) {
    var m = drawMini.m; if (!m) return; var r = ev.currentTarget.getBoundingClientRect(); var wx = (ev.clientX - r.left - m.ox) / m.sc, wy = (ev.clientY - r.top - m.oy) / m.sc;
    var vr = view.getBoundingClientRect(); S.view.x = vr.width / 2 - wx * S.view.z; S.view.y = vr.height / 2 - wy * S.view.z; applyView();
  });

  /* keyboard */
  document.addEventListener('keydown', function (ev) {
    var t = ev.target, typing = t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable);
    var mod = ev.ctrlKey || ev.metaKey;
    if (mod && ev.key.toLowerCase() === 's') { ev.preventDefault(); S.dirty = true; save(); return; }
    if (typing) return;
    if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); deleteSelection(); }
    else if (mod && ev.key.toLowerCase() === 'z') { ev.preventDefault(); ev.shiftKey ? redo() : undo(); }
    else if (mod && ev.key.toLowerCase() === 'y') { ev.preventDefault(); redo(); }
    else if (mod && ev.key.toLowerCase() === 'd') { ev.preventDefault(); duplicateSelection(); }
    else if (mod && ev.key.toLowerCase() === 'a') { ev.preventDefault(); S.sel = {}; Object.keys(S.nodes).forEach(function (i) { S.sel[i] = true; }); refreshSel(); }
    else if (ev.key === 'Escape') { selectOnly(null); hideMenu(); }
    else if (ev.key === '+' || ev.key === '=') $('#fbZin').click();
    else if (ev.key === '-') $('#fbZout').click();
    else if (ev.key === '0') fit();
  });

  /* ── inspector ────────────────────────────────────────────────────────── */
  function closeInspector() { insp.classList.remove('is-open'); $('#fbMini').classList.remove('is-shift'); }
  function refreshInspector() { var ids = Object.keys(S.sel); if (ids.length === 1 && S.nodes[ids[0]]) openInspector(S.nodes[ids[0]]); else closeInspector(); }
  function savedVars() { var v = []; Object.keys(S.nodes).forEach(function (i) { var n = S.nodes[i]; if (n.type === 'question' && n.config.save_as) v.push(n.config.save_as); }); return v; }

  function fld(label, inner, hint, extra) { return '<div class="fb-f"><label>' + label + (extra ? '<em>' + extra + '</em>' : '') + '</label>' + inner + (hint ? '<div class="fb-hint">' + hint + '</div>' : '') + '</div>'; }
  function varChips(target) {
    var chips = ['contact.first_name', 'contact.name', 'contact.phone'].concat(savedVars()).filter(function (v, i, a) { return a.indexOf(v) === i; });
    return '<div class="fb-chips" data-chips="' + target + '">' + chips.map(function (c) { return '<button type="button" data-ins="{{' + c + '}}">{{' + esc(c) + '}}</button>'; }).join('') + '</div>';
  }
  function cnt(v, max) { var n = (v || '').length; return '<span class="fb-cnt' + (n > max ? ' is-over' : '') + '">' + n + '/' + max + '</span>'; }
  function sel(name, opts, val) { return '<select class="fb-sel" data-k="' + name + '">' + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'; }

  function openInspector(n) {
    if (!n) return;
    var T = TYPES[n.type], c = n.config, html = '';
    $('#fbInspTitle').textContent = n.type === 'send' ? 'Message' : T.label;
    $('#fbInspIc').style.setProperty('--c', T.color); $('#fbInspIc').innerHTML = ICON[n.type];
    $('#fbInspSub').textContent = T.desc;
    $('#fbInspDel').style.display = '';
    if (n._issuesText) html += '<div class="fb-box" style="background:#FEF2F2;color:#B42318">' + esc(n._issuesText).replace(/\n/g, '<br>') + '</div>';

    if (n.type === 'trigger') {
      html += fld('Starts when', sel('mode', [['keyword', 'A customer sends a keyword'], ['new_contact', 'First message from a new contact'], ['any_message', 'Any message outside an active flow'], ['button_reply', 'Button or template reply'], ['from_ad', 'Lead came from a Click-to-WhatsApp ad'], ['fallback', 'Default fallback (nothing else answered)']], c.mode));
      if (c.mode === 'from_ad') {
        html += fld('Which ads', sel('ad_match', [['any', 'Any ad or post'], ['ad_id', 'Specific ad IDs'], ['headline', 'Ad headline or text contains']], c.ad_match || 'any'));
        if (c.ad_match === 'ad_id') html += fld('Ad IDs', '<input class="fb-in" data-k="ad_value" value="' + esc(c.ad_value || '') + '" placeholder="e.g. 120211234567890, 120219876543210">', 'Find the ad ID on the Ad Leads page or in Meta Ads Manager. Separate several with commas.');
        if (c.ad_match === 'headline') html += fld('Headline or ad text contains', '<input class="fb-in" data-k="ad_value" value="' + esc(c.ad_value || '') + '" placeholder="e.g. saree">', 'Not case sensitive.');
        html += '<div class="fb-box">Runs on the first message a customer sends after tapping your ad. Example: send the saree catalogue only to leads from saree ads.</div>';
      }
      if (c.mode === 'keyword') {
        html += '<div class="fb-lbl">Keyword rules <em>case-insensitive</em></div>' + (c.rules || []).map(function (r, i) {
          return '<div class="fb-rule"><div class="fb-row">' + sel('rule-match-' + i, [['exact', 'Is exactly'], ['contains', 'Contains word'], ['starts', 'Starts with'], ['regex', 'Regex']], r.match) + '<button class="fb-sm" data-rule-del="' + i + '" title="Remove">' + ICON.x + '</button></div><input class="fb-in" data-k="rule-value-' + i + '" value="' + esc(r.value) + '" placeholder="' + (r.match === 'regex' ? '^order\\s*#?\\d+$' : 'e.g. hi, menu, price') + '"></div>';
        }).join('') + '<button class="fb-add" data-rule-add>' + ICON.plus + ' Add rule</button><div class="fb-hint">The first rule that matches starts the flow. Matching is not case sensitive.</div>';
      }
      if (c.mode === 'button_reply') html += triggerPicker(c);
      if (c.mode === 'button_reply') html += fld('Other reply IDs (one per line)', '<textarea class="fb-ta" data-k="reply_ids" placeholder="Leave empty for any button or template reply">' + esc((c.reply_ids || []).join('\n')) + '</textarea>', 'Matches the button id or the button text of a reply.');
      if (c.mode === 'any_message') html += fld('Do not restart for the same customer within (hours)', '<input class="fb-in" type="number" min="0" max="720" data-k="cooldown_hours" value="' + (c.cooldown_hours == null ? 12 : c.cooldown_hours) + '">', 'Stops the flow from replaying on every message. 0 = no pause.');
      if (c.mode === 'fallback') html += '<div class="fb-box">Runs only when no bot reply and no other flow handled the message.</div>';
      html += '<div class="fb-hint">When several flows match, the one with the lowest priority number runs first (Settings).</div>';
    }

    if (n.type === 'send') {
      html += '<div class="fb-lbl">Message type</div><div class="fb-seg" style="margin-bottom:14px">' + KINDS.map(function (k) { return '<button data-kind="' + k[0] + '" class="' + (c.kind === k[0] ? 'is-on' : '') + '">' + k[1] + '</button>'; }).join('') + '</div>';
      if (c.kind === 'template') {
        html += fld('Approved template', '<select class="fb-sel" data-k="template_id"><option value="0">Choose a template…</option>' + S.meta.templates.map(function (t) { return '<option value="' + t.id + '"' + (t.id === c.template_id ? ' selected' : '') + (t.approved ? '' : ' disabled') + '>' + esc(t.name) + (t.approved ? '' : ' (not approved)') + '</option>'; }).join('') + '</select>', 'Templates can be sent at any time, even after the 24-hour window.');
        var tp = S.meta.templates.filter(function (t) { return t.id === c.template_id; })[0];
        if (tp) {
          var mx = 0; (tp.body.match(/\{\{(\d+)\}\}/g) || []).forEach(function (m) { mx = Math.max(mx, parseInt(m.replace(/\D/g, ''), 10)); });
          html += '<div class="fb-prev" style="margin-bottom:14px">' + esc(tp.body) + '</div>';
          for (var i = 0; i < mx; i++) html += fld('Variable {{' + (i + 1) + '}}', '<input class="fb-in" data-k="var-' + i + '" value="' + esc((c.variables || [])[i] || '') + '" placeholder="e.g. {{contact.first_name}}">');
        }
      } else {
        var linked = c.kind === 'buttons' && +c.bot_reply_id > 0, ro = linked ? ' readonly' : '';
        if (c.kind === 'buttons') html += botSource(c, linked);
        if (c.kind === 'image' || c.kind === 'video' || c.kind === 'document') html += mediaBlock(c);
        var max = c.kind === 'text' ? LIM.text : LIM.body;
        html += fld(c.kind === 'text' ? 'Message' : (c.kind === 'buttons' || c.kind === 'list' ? 'Message text' : 'Caption'), '<textarea class="fb-ta" data-k="text" data-max="' + max + '" data-var="1" rows="5"' + ro + '>' + esc(c.text || '') + '</textarea>' + (linked ? '' : varChips('text')), '', '<span data-cnt="text">' + cnt(c.text, max) + '</span>');
        if (c.kind === 'buttons') {
          html += '<div class="fb-lbl">Reply buttons <em>' + (c.buttons || []).length + '/' + LIM.buttons + '</em></div>' + (c.buttons || []).map(function (b, i) {
            return '<div class="fb-row"><input class="fb-in" data-k="btn-' + i + '" maxlength="' + LIM.button_title + '" value="' + esc(b.title) + '" placeholder="Button text (max ' + LIM.button_title + ')"' + ro + '>' + (linked ? '' : '<button class="fb-sm" data-btn-del="' + i + '" title="Remove">' + ICON.x + '</button>') + '</div>';
          }).join('') + (linked ? '' : '<button class="fb-add" data-btn-add ' + ((c.buttons || []).length >= LIM.buttons ? 'disabled' : '') + '>' + ICON.plus + ' Add button</button>') + '<div class="fb-hint">WhatsApp allows 3 reply buttons, 20 characters each. Each button has its own output on the canvas and is matched by its ID, so renaming a label never breaks a connection.</div>' + fld('Footer (optional)', '<input class="fb-in" data-k="footer" maxlength="60" value="' + esc(c.footer || '') + '"' + ro + '>') + timeoutField(c);
        }
        if (c.kind === 'list') {
          html += fld('List button label', '<input class="fb-in" data-k="button" maxlength="20" value="' + esc(c.button || 'Choose') + '">');
          html += '<div class="fb-lbl">List rows <em>' + (c.rows || []).length + '/' + LIM.list_rows + '</em></div>' + (c.rows || []).map(function (r, i) {
            return '<div class="fb-rule"><div class="fb-row"><input class="fb-in" data-k="row-' + i + '" maxlength="' + LIM.row_title + '" value="' + esc(r.title) + '" placeholder="Row title (max ' + LIM.row_title + ')"><button class="fb-sm" data-row-del="' + i + '" title="Remove">' + ICON.x + '</button></div><input class="fb-in" data-k="rowd-' + i + '" maxlength="72" value="' + esc(r.description || '') + '" placeholder="Description (optional)"></div>';
          }).join('') + '<button class="fb-add" data-row-add ' + ((c.rows || []).length >= LIM.list_rows ? 'disabled' : '') + '>' + ICON.plus + ' Add row</button><div class="fb-hint">WhatsApp allows up to 10 rows, 24 characters each.</div>' + timeoutField(c);
        }
        html += '<div class="fb-box">Free-form messages can only be sent within 24 hours of the customer\'s last message. After a long delay use a Template.</div>';
      }
    }

    if (n.type === 'question') {
      html += fld('Question', '<textarea class="fb-ta" data-k="text" data-max="' + LIM.body + '" data-var="1" rows="4">' + esc(c.text || '') + '</textarea>' + varChips('text'), '', '<span data-cnt="text">' + cnt(c.text, LIM.body) + '</span>');
      html += fld('Save the answer as variable', '<input class="fb-in" data-k="save_as" value="' + esc(c.save_as || 'answer') + '">', 'Use it later as {{' + esc(c.save_as || 'answer') + '}}.');
      html += fld('Also save to contact field', sel('field', [['', 'Do not update the contact']].concat(S.meta.contact_fields.map(function (f) { return [f, f]; })), c.field));
      html += fld('Check the answer', sel('validate', [['none', 'Accept anything'], ['number', 'Must be a number'], ['email', 'Must be an email'], ['phone', 'Must be a phone number']], c.validate));
      html += timeoutField(c);
      if (c.validate !== 'none') html += fld('Retry message', '<textarea class="fb-ta" data-k="retry_text" rows="2">' + esc(c.retry_text || '') + '</textarea>') + fld('Retries before the "No valid answer" output', '<input class="fb-in" type="number" min="0" max="3" data-k="max_retries" value="' + (c.max_retries == null ? 1 : c.max_retries) + '">');
    }

    if (n.type === 'condition') {
      html += fld('Check', sel('source', [['tag', 'Contact has a tag'], ['field', 'Contact field'], ['variable', 'Saved variable'], ['time', 'Time of day']], c.source));
      if (c.source === 'tag') html += fld('Tag', '<input class="fb-in" data-k="name" list="fbTags" value="' + esc(c.name || '') + '" placeholder="e.g. vip"><datalist id="fbTags">' + S.meta.tags.map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist>');
      if (c.source === 'field') html += fld('Field', sel('name', S.meta.contact_fields.map(function (f) { return [f, f]; }), c.name));
      if (c.source === 'variable') html += fld('Variable', '<input class="fb-in" data-k="name" list="fbVars" value="' + esc(c.name || '') + '"><datalist id="fbVars">' + savedVars().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist>');
      if (c.source === 'field' || c.source === 'variable') {
        html += fld('Operator', sel('operator', [['equals', 'is'], ['not_equals', 'is not'], ['contains', 'contains'], ['gt', 'is greater than'], ['lt', 'is less than'], ['empty', 'is empty'], ['not_empty', 'is not empty']], c.operator));
        if (!/empty/.test(c.operator)) html += fld('Value', '<input class="fb-in" data-k="value" value="' + esc(c.value || '') + '">');
      }
      if (c.source === 'time') html += '<div class="fb-2">' + fld('From', '<input class="fb-in" type="time" data-k="from" value="' + esc(c.from) + '">') + fld('To', '<input class="fb-in" type="time" data-k="to" value="' + esc(c.to) + '">') + '</div><div class="fb-hint">Uses the server time zone of your workspace.</div>';
      html += '<div class="fb-hint">The Yes output runs when the check is true, No when it is false.</div>';
    }

    if (n.type === 'delay') {
      html += '<div class="fb-2">' + fld('Wait', '<input class="fb-in" type="number" min="1" max="720" data-k="amount" value="' + c.amount + '">') + fld('Unit', sel('unit', [['seconds', 'seconds'], ['minutes', 'minutes'], ['hours', 'hours']], c.unit)) + '</div>';
      html += '<div class="fb-hint">The wait runs in the background queue. The conversation continues on its own after the time is up.</div>';
      if ((c.unit === 'hours' && c.amount >= 24)) html += '<div class="fb-box">After 24 hours only an approved Template can be sent.</div>';
    }

    if (n.type === 'action') {
      html += fld('Action', sel('action', ACTIONS, c.action));
      if (c.action === 'add_tag' || c.action === 'remove_tag') html += fld('Tag', '<input class="fb-in" data-k="tag" list="fbTags" value="' + esc(c.tag || '') + '"><datalist id="fbTags">' + S.meta.tags.map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist>');
      if (c.action === 'update_field') html += fld('Field', sel('field', S.meta.contact_fields.map(function (f) { return [f, f]; }), c.field)) + fld('Value', '<input class="fb-in" data-k="value" value="' + esc(c.value || '') + '" placeholder="Text or {{variable}}">');
      if (c.action === 'assign_agent' || c.action === 'handover_human') html += fld('Agent', sel('agent_id', [[0, c.action === 'handover_human' ? 'Leave unassigned' : 'Choose an agent…']].concat(S.meta.agents.map(function (a) { return [a.id, a.name]; })), c.agent_id));
      if (c.action === 'handover_human') html += fld('Message to the customer (optional)', '<textarea class="fb-ta" data-k="message" rows="3">' + esc(c.message || '') + '</textarea>') + '<div class="fb-hint">The flow ends and the bots stay quiet for this customer so your team can reply.</div>';
      if (c.action === 'handover_ai') html += '<div class="fb-hint">The flow ends and the AI bot answers from the next message.</div>';
      if (c.action === 'webhook') html += '<div class="fb-2">' + fld('Method', sel('method', [['POST', 'POST'], ['GET', 'GET']], c.method)) + '</div>' + fld('URL', '<input class="fb-in" data-k="url" value="' + esc(c.url || '') + '" placeholder="https://example.com/hook">') + fld('Extra text sent as "body" (optional)', '<textarea class="fb-ta" data-k="body" rows="3">' + esc(c.body || '') + '</textarea>') + '<div class="fb-hint">We send the customer, the flow variables and your text. The status is saved as {{webhook_status}}. Internal addresses are blocked.</div>';
    }

    if (n.type === 'end') html += fld('Closing message (optional)', '<textarea class="fb-ta" data-k="message" rows="3" data-var="1">' + esc(c.message || '') + '</textarea>' + varChips('message'), 'Sent once, then the conversation ends.');

    var ports = portsOf(n);
    if (ports.length) html += '<div class="fb-hint" style="margin-top:6px">Outputs: ' + ports.map(function (p) { return esc(p.label); }).join(' · ') + '. Drag from an output dot to the next step.</div>';
    $('#fbInspBody').innerHTML = html;
    insp.classList.add('is-open'); $('#fbMini').classList.add('is-shift');
    insp.dataset.node = n.id;
  }

  function botSource(c, linked) {
    var list = S.meta.bot_replies || [];
    var opts = '<option value="0">Write my own buttons</option>' + list.map(function (b) { return '<option value="' + b.id + '"' + (+c.bot_reply_id === b.id ? ' selected' : '') + '>Bot Reply: ' + esc(b.name) + ' (' + b.buttons.length + ' button' + (b.buttons.length > 1 ? 's' : '') + ')</option>'; }).join('');
    var h2 = fld('Button source', '<select class="fb-sel" data-k="bot_reply_id">' + opts + '</select>', list.length ? 'Pick a Bot Reply to reuse its message and buttons. The labels stay in sync when you edit the Bot Reply.' : 'No Bot Reply with buttons yet. Create one in Bot Replies to reuse it here.');
    if (linked) {
      var bot = botById(c.bot_reply_id);
      h2 += '<div class="fb-box" style="background:#E6FCF2;color:#0B6B5E">' + (bot ? 'Linked to Bot Reply <b>' + esc(bot.name) + '</b>. Text, footer and button labels follow it. Edit them in <a href="bot_replies.php" target="_blank" style="color:#0B6B5E;font-weight:700">Bot Replies</a>.' : 'The linked Bot Reply was deleted or switched off. The last synced copy is kept.') + '<div style="display:flex;gap:8px;margin-top:10px"><button class="fb-add" style="background:#fff" data-botsync>Sync now</button><button class="fb-add" style="background:#fff;color:#B42318" data-unlink>Unlink</button></div></div>';
    }
    return h2;
  }
  function triggerPicker(c) {
    var list = S.meta.bot_replies || [], ids = c.reply_ids || [];
    if (!list.length) return '';
    return '<div class="fb-lbl">Pick buttons from Bot Replies</div>' + list.map(function (b) {
      return '<div class="fb-rule"><div style="font-size:12.5px;font-weight:800;margin-bottom:6px">' + esc(b.name) + '</div>' + b.buttons.map(function (x) {
        return '<label class="fb-row" style="font-size:13px;font-weight:600;cursor:pointer;margin-bottom:4px"><input type="checkbox" data-pickbtn="' + esc(x.id) + '"' + (ids.indexOf(x.id) >= 0 ? ' checked' : '') + '> ' + esc(x.title) + '</label>';
      }).join('') + '</div>';
    }).join('') + '<div class="fb-hint">The flow starts when a customer taps a ticked button. Matched by button ID, so a renamed label still works.</div>';
  }

  var UP = {
    image: { label: 'image', accept: '.jpg,.jpeg,.png,image/jpeg,image/png', hint: 'JPG or PNG, up to 5 MB' },
    video: { label: 'video', accept: '.mp4,.3gp,video/mp4,video/3gpp', hint: 'MP4 or 3GP, up to 16 MB' },
    document: { label: 'file', accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv', hint: 'PDF, Word, Excel, PowerPoint, TXT or CSV, up to 20 MB' }
  };
  function mediaBlock(c) {
    var u = UP[c.kind], has = /^https?:/i.test(c.media_url || ''), out = '';
    if (has) {
      var nm = c.filename || String(c.media_url).split('/').pop();
      out += '<div class="fb-lbl">' + u.label[0].toUpperCase() + u.label.slice(1) + '</div><div class="fb-file">' +
        (c.kind === 'image' ? '<img alt="" src="' + esc(c.media_url) + '">' : '<span class="fb-ic" style="--c:' + (c.kind === 'video' ? '#7C3AED' : '#EA580C') + '">' + ICON.send + '</span>') +
        '<div><b>' + esc(nm) + '</b><small>' + (c.kind === 'video' ? 'Video' : (c.kind === 'image' ? 'Image' : 'File')) + ' ready to send</small></div>' +
        '<label class="fb-add" style="width:auto;padding:0 12px;cursor:pointer">Replace<input type="file" data-upload hidden accept="' + u.accept + '"></label>' +
        '<button class="fb-sm" data-media-del title="Remove">' + ICON.x + '</button></div>';
    } else {
      out += '<div class="fb-lbl">Upload ' + u.label + '</div><label class="fb-up" data-drop><span class="fb-ic" style="--c:#128C7E">' +
        SV('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>') +
        '</span><b>Click to upload or drop a ' + u.label + ' here</b><small>' + u.hint + '</small><input type="file" data-upload hidden accept="' + u.accept + '"></label>';
    }
    out += '<div id="fbUpMsg" class="fb-hint"></div>';
    out += fld('Or use a public link (https)', '<input class="fb-in" data-k="media_url" value="' + esc(c.media_url || '') + '" placeholder="https://…">', 'WhatsApp downloads the file from this address, so it must be reachable from the internet.');
    if (c.kind === 'document') out += fld('File name shown to the customer', '<input class="fb-in" data-k="filename" maxlength="120" value="' + esc(c.filename || '') + '" placeholder="e.g. Price list.pdf">');
    return out;
  }
  function uploadMedia(n, file) {
    var kind = n.config.kind, msg = $('#fbUpMsg'), zone = $('#fbInspBody [data-drop]');
    if (!file) return;
    if (zone) zone.classList.add('is-busy');
    if (msg) msg.textContent = 'Uploading ' + file.name + '…';
    var fd = new FormData(); fd.append('file', file); fd.append('kind', kind);
    fetch(CFG.upload, { method: 'POST', headers: { 'X-CSRF-Token': CFG.csrf, 'Accept': 'application/json' }, body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Upload failed' }; }); })
      .then(function (r) {
        if (!r.ok) { if (zone) zone.classList.remove('is-busy'); if (msg) msg.textContent = r.error || 'Upload failed'; toast(r.error || 'Upload failed', 4200); return; }
        editNode(n, function (c) { c.media_url = r.url; if (kind === 'document') c.filename = c.filename || r.name; }, true);
        openInspector(n);
        toast(r.public ? 'Uploaded' : 'Uploaded. This address is not reachable from the internet, so WhatsApp cannot fetch it until the site is online.', r.public ? 1800 : 5200);
      }).catch(function () { if (zone) zone.classList.remove('is-busy'); if (msg) msg.textContent = 'Upload failed. Check your connection.'; });
  }
  $('#fbInspBody').addEventListener('dragover', function (ev) { var z = ev.target.closest('[data-drop]'); if (z) { ev.preventDefault(); z.classList.add('is-over'); } });
  $('#fbInspBody').addEventListener('dragleave', function (ev) { var z = ev.target.closest('[data-drop]'); if (z) z.classList.remove('is-over'); });
  $('#fbInspBody').addEventListener('drop', function (ev) { var z = ev.target.closest('[data-drop]'); if (!z) return; ev.preventDefault(); z.classList.remove('is-over'); var n = curNode(); if (n && ev.dataTransfer.files[0]) uploadMedia(n, ev.dataTransfer.files[0]); });

  /* inspector events (delegated) */
  function curNode() { return S.nodes[insp.dataset.node]; }
  $('#fbInspBody').addEventListener('input', function (ev) {
    var t = ev.target, n = curNode(); if (!n || !t.dataset.k) return;
    var k = t.dataset.k, v = t.value;
    editNode(n, function (c) {
      var m;
      if ((m = k.match(/^rule-value-(\d+)$/))) c.rules[+m[1]].value = v;
      else if ((m = k.match(/^btn-(\d+)$/))) c.buttons[+m[1]].title = v.slice(0, LIM.button_title);
      else if ((m = k.match(/^row-(\d+)$/))) c.rows[+m[1]].title = v.slice(0, LIM.row_title);
      else if ((m = k.match(/^rowd-(\d+)$/))) c.rows[+m[1]].description = v;
      else if ((m = k.match(/^var-(\d+)$/))) { c.variables = c.variables || []; c.variables[+m[1]] = v; }
      else if (k === 'reply_ids') c.reply_ids = v.split(/\n/).map(function (s) { return s.trim(); }).filter(Boolean);
      else if (k === 'amount' || k === 'max_retries' || k === 'cooldown_hours') c[k] = Math.max(0, parseInt(v, 10) || 0);
      else if (k === 'save_as') c.save_as = v.replace(/[^A-Za-z0-9_]/g, '_');
      else c[k] = v;
    }, false);
    if (t.dataset.max) { var cn = insp.querySelector('[data-cnt="' + k + '"]'); if (cn) cn.innerHTML = cnt(v, +t.dataset.max); }
  });
  $('#fbInspBody').addEventListener('change', function (ev) {
    var t = ev.target, n = curNode();
    if (n && t.hasAttribute && t.hasAttribute('data-upload')) { uploadMedia(n, t.files[0]); return; }
    if (n && t.dataset.pickbtn) { editNode(n, function (c) { c.reply_ids = c.reply_ids || []; var i = c.reply_ids.indexOf(t.dataset.pickbtn); if (t.checked && i < 0) c.reply_ids.push(t.dataset.pickbtn); if (!t.checked && i >= 0) c.reply_ids.splice(i, 1); }, false); openInspector(n); return; }
    if (!n || !t.dataset.k) return;
    var k = t.dataset.k, v = t.value, m;
    if (t.tagName !== 'SELECT') return;
    if (k === 'bot_reply_id') {
      var bid = parseInt(v, 10) || 0;
      if (bid) { var bot = botById(bid); if (bot) linkBot(n, bot); } else { editNode(n, function (c) { c.bot_reply_id = 0; }, true); }
      openInspector(n); return;
    }
    var rerender = true;
    if ((m = k.match(/^rule-match-(\d+)$/))) editNode(n, function (c) { c.rules[+m[1]].match = v; }, false);
    else if (k === 'template_id') editNode(n, function (c) { c.template_id = parseInt(v, 10) || 0; var tp = S.meta.templates.filter(function (x) { return x.id === c.template_id; })[0]; c.template_name = tp ? tp.name : ''; c.language = tp ? tp.language : 'en'; c.variables = []; }, true);
    else if (k === 'agent_id') editNode(n, function (c) { c.agent_id = parseInt(v, 10) || 0; }, false);
    else if (k === 'timeout_minutes') editNode(n, function (c) { c.timeout_minutes = parseInt(v, 10) || 0; }, true);
    else editNode(n, function (c) {
      c[k] = v;
      if (k === 'mode' && v === 'keyword' && (!c.rules || !c.rules.length)) c.rules = [{ match: 'contains', value: '' }];
      if (k === 'source' && v === 'field') { c.name = S.meta.contact_fields[0] || 'name'; }
      if (k === 'source' && v !== 'field') { c.name = ''; }
      if (k === 'action' && v === 'update_field') { c.field = c.field || 'name'; }
    }, k === 'action' || k === 'mode');
    if (rerender) openInspector(n);
  });
  $('#fbInspBody').addEventListener('click', function (ev) {
    var n = curNode(); if (!n) return; var b = ev.target.closest('button'); if (!b) return;
    if (b.dataset.kind) {
      if (b.dataset.kind === n.config.kind) return;
      editNode(n, function (c) {
        var keepText = c.text || ''; var old = c.kind; c.kind = b.dataset.kind; c.text = keepText;
        if (c.kind === 'buttons' && !(c.buttons && c.buttons.length)) c.buttons = [{ id: uid('b'), title: 'Option 1' }, { id: uid('b'), title: 'Option 2' }];
        if (c.kind === 'list' && !(c.rows && c.rows.length)) { c.rows = [{ id: uid('r'), title: 'Option 1', description: '' }, { id: uid('r'), title: 'Option 2', description: '' }]; c.button = c.button || 'Choose'; }
        if (c.kind === 'template') { c.template_id = c.template_id || 0; c.template_name = c.template_name || ''; c.language = c.language || 'en'; c.variables = c.variables || []; }
        if (/image|video|document/.test(c.kind)) { if (old !== c.kind) { c.media_url = ''; c.filename = ''; } else c.media_url = c.media_url || ''; }   // a picture is not a valid video or file: start clean when the type changes
      }, true); openInspector(n); return;
    }
    if (b.hasAttribute('data-media-del')) { editNode(n, function (c) { c.media_url = ''; if (c.kind === 'document') c.filename = ''; }, true); openInspector(n); return; }
    if (b.hasAttribute('data-unlink')) { editNode(n, function (c) { c.bot_reply_id = 0; }, true); openInspector(n); toast('Unlinked. You can now edit the buttons here.'); return; }
    if (b.hasAttribute('data-botsync')) { syncLinked(false); return; }
    if (b.hasAttribute('data-btn-add')) { if ((n.config.buttons || []).length >= LIM.buttons) return; editNode(n, function (c) { c.buttons = c.buttons || []; c.buttons.push({ id: uid('b'), title: 'Option ' + (c.buttons.length + 1) }); }, true); openInspector(n); return; }
    if (b.dataset.btnDel != null) { editNode(n, function (c) { c.buttons.splice(+b.dataset.btnDel, 1); }, true); openInspector(n); return; }
    if (b.hasAttribute('data-row-add')) { if ((n.config.rows || []).length >= LIM.list_rows) return; editNode(n, function (c) { c.rows = c.rows || []; c.rows.push({ id: uid('r'), title: 'Option ' + (c.rows.length + 1), description: '' }); }, true); openInspector(n); return; }
    if (b.dataset.rowDel != null) { editNode(n, function (c) { c.rows.splice(+b.dataset.rowDel, 1); }, true); openInspector(n); return; }
    if (b.hasAttribute('data-rule-add')) { editNode(n, function (c) { c.rules = c.rules || []; c.rules.push({ match: 'contains', value: '' }); }, true); openInspector(n); return; }
    if (b.dataset.ruleDel != null) { editNode(n, function (c) { c.rules.splice(+b.dataset.ruleDel, 1); }, true); openInspector(n); return; }
    if (b.dataset.ins) {
      var wrap = b.closest('[data-chips]'); var ta = $('#fbInspBody [data-k="' + wrap.dataset.chips + '"]'); if (!ta) return;
      var s = ta.selectionStart == null ? ta.value.length : ta.selectionStart, e = ta.selectionEnd == null ? s : ta.selectionEnd;
      ta.value = ta.value.slice(0, s) + b.dataset.ins + ta.value.slice(e); ta.focus(); ta.setSelectionRange(s + b.dataset.ins.length, s + b.dataset.ins.length);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  $('#fbInspClose').onclick = function () { selectOnly(null); };
  $('#fbInspDel').onclick = deleteSelection;

  /* ── top bar: status, publish, settings, logs ─────────────────────────── */
  function setStatus() {
    var pub = S.flow.status === 'published';
    var c = $('#fbStatus'); c.className = 'fb-chip' + (pub ? ' is-pub' : ''); c.innerHTML = '<i></i>' + (pub ? 'Published' : 'Draft');
    var b = $('#fbPublish'); b.textContent = pub ? 'Unpublish' : 'Publish'; b.className = 'fb-btn ' + (pub ? 'is-ghost' : 'is-primary');
  }
  $('#fbPublish').onclick = function () {
    var btn = this, pub = S.flow.status === 'published'; btn.disabled = true;
    S.dirty = true;
    (S.saving ? new Promise(function (r) { setTimeout(r, 600); }) : Promise.resolve()).then(save).then(function () {
      return api(pub ? 'unpublish' : 'publish', {});
    }).then(function (r) {
      btn.disabled = false;
      if (r.issues) applyIssues(r.issues);
      if (r.ok) { S.flow.status = r.status; setStatus(); toast(pub ? 'Flow unpublished. It will not run.' : 'Flow published. It is live for new messages.', 3200); }
      else { $('#fbProbs').classList.add('is-open'); toast('Fix the highlighted problems, then publish again.', 3600); var first = (r.issues || []).filter(function (i) { return i.level === 'error' && i.node_id && S.nodes[i.node_id]; })[0]; if (first) { selectOnly(first.node_id); centerOn(S.nodes[first.node_id]); } }
    }).catch(function () { btn.disabled = false; toast('Could not publish. Check your connection.'); });
  };
  $('#fbUndo').onclick = undo; $('#fbRedo').onclick = redo;
  $('#fbProbBtn').onclick = function () { $('#fbProbs').classList.add('is-open'); };
  $('#fbProbClose').onclick = function () { $('#fbProbs').classList.remove('is-open'); };
  $('#fbProbList').addEventListener('click', function (ev) { var b = ev.target.closest('[data-k]'); if (!b) return; var i = S.issues[+b.dataset.k]; if (i && i.node_id && S.nodes[i.node_id]) { selectOnly(i.node_id); centerOn(S.nodes[i.node_id]); } });
  $('#fbName').addEventListener('input', function () { markDirty(); });
  $('#fbName').addEventListener('keydown', function (e) { if (e.key === 'Enter') this.blur(); });

  $('#fbSettingsBtn').onclick = function () { var s = S.settings; $('#sPrio').value = s.priority; $('#sIdle').value = s.idle_minutes; $('#sSteps').value = s.max_steps; $('#fbSettings').classList.add('is-open'); };
  $('#sCancel').onclick = function () { $('#fbSettings').classList.remove('is-open'); };
  $('#sSave').onclick = function () { S.settings = { priority: clamp(parseInt($('#sPrio').value, 10) || 100, 1, 999), idle_minutes: clamp(parseInt($('#sIdle').value, 10) || 120, 5, 10080), max_steps: clamp(parseInt($('#sSteps').value, 10) || 25, 5, 100) }; $('#fbSettings').classList.remove('is-open'); markDirty(); };

  /* run log */
  var logSession = 0;
  function openLog() { $('#fbLog').classList.add('is-open'); loadLog(logSession); }
  function loadLog(sid) {
    logSession = sid || 0;
    api('logs', logSession ? 'session_id=' + logSession : '').then(function (r) {
      if (!r.ok) return;
      $('#fbLogSess').innerHTML = '<button class="fb-sess' + (logSession ? '' : ' is-on') + '" data-s="0"><b>All runs</b><small>Latest steps</small></button>' + r.sessions.map(function (s) {
        return '<button class="fb-sess' + (logSession === +s.id ? ' is-on' : '') + '" data-s="' + s.id + '"><b>' + esc(s.contact_name || ('Contact #' + s.contact_id)) + '</b><small>' + esc(s.contact_phone || '') + ' · ' + esc(s.status) + (s.end_reason ? ' (' + esc(s.end_reason) + ')' : '') + '<br>' + esc(s.started_at) + '</small></button>';
      }).join('');
      $('#fbLogRows').innerHTML = r.logs.length ? r.logs.map(function (l) {
        return '<div class="fb-step is-' + esc(l.result) + '" data-node="' + esc(l.node_id || '') + '"><i></i><div><b>' + esc(l.event) + '</b> ' + (l.node_type ? '<span class="fb-tag">' + esc(l.node_type) + '</span> ' : '') + esc(l.detail || '') + (logSession ? '' : '<br><small style="color:#6B7C76">' + esc(l.contact_name || ('Contact #' + l.contact_id)) + '</small>') + '</div><time>' + esc(l.created_at.slice(5, 16)) + '</time></div>';
      }).join('') : '<div class="fb-hint" style="padding:14px">No runs yet. Send a message that matches the trigger to a test number, then reload this log.</div>';
    });
  }
  $('#fbLogBtn').onclick = openLog; $('#fbLogClose').onclick = function () { $('#fbLog').classList.remove('is-open'); };
  $('#fbLogRefresh').onclick = function () { loadLog(logSession); };
  $('#fbLogSess').addEventListener('click', function (ev) { var b = ev.target.closest('[data-s]'); if (b) loadLog(+b.dataset.s); });
  $('#fbLogRows').addEventListener('click', function (ev) {
    var s = ev.target.closest('[data-node]'); if (!s || !s.dataset.node || !S.nodes[s.dataset.node]) return;
    var n = S.nodes[s.dataset.node]; centerOn(n); n._el.classList.add('is-run'); setTimeout(function () { n._el.classList.remove('is-run'); }, 1800);
  });

  /* ── boot ─────────────────────────────────────────────────────────────── */
  function boot() {
    setSave('Loading…');
    api('load').then(function (r) {
      if (!r.ok) { setSave(r.error || 'Could not load', true); toast(r.error || 'Could not load the flow', 5000); return; }
      S.flow = r.flow; S.meta = Object.assign(S.meta, r.meta); S.version = r.flow.version;
      S.settings = { priority: r.flow.priority, idle_minutes: r.flow.idle_minutes, max_steps: r.flow.max_steps };
      $('#fbName').value = r.flow.name;
      (r.graph.nodes || []).forEach(function (n) { S.nodes[n.id] = { id: n.id, type: n.type, x: n.position.x, y: n.position.y, config: (n.config && !Array.isArray(n.config)) ? n.config : {} }; });
      (r.graph.edges || []).forEach(function (e) { S.edges[e.id] = e; });
      var created = false;
      if (!Object.keys(S.nodes).length) {
        var cfg = defaults('trigger'), tt = r.flow.trigger_type, kw = r.flow.trigger_keyword;
        cfg.mode = ({ new_contact: 'new_contact', any_message: 'any_message' })[tt] || 'keyword';
        if (cfg.mode === 'keyword') cfg.rules = (kw ? String(kw).split(',') : ['']).map(function (v) { return { match: 'contains', value: v.trim() }; });
        var n0 = { id: 'trigger', type: 'trigger', x: 80, y: 160, config: cfg }; S.nodes[n0.id] = n0; created = true;
      }
      Object.keys(S.edges).forEach(function (k) { var e = S.edges[k], a = S.nodes[e.source_node]; if (!a || !S.nodes[e.target_node] || !portsOf(a).some(function (p) { return p.id === e.source_port; })) delete S.edges[k]; });
      setStatus(); renderPalette(); renderAll();
      var v = (r.flow.view || '').split(',').map(parseFloat);
      if (v.length === 3 && v.every(isFinite) && !created) { S.view = { x: v[0], y: v[1], z: clamp(v[2], .3, 1.6) }; applyView(); } else fit();
      Object.keys(S.nodes).forEach(function (id) { measure(S.nodes[id]); }); renderEdges();
      S.hist = [snapshot()]; S.hi = 0; updateUndo();
      applyIssues([], false);
      setSave('Saved');
      if (created) { S.dirty = true; save(); }
      else { api('validate', { graph: graphPayload() }).then(function (vr) { if (vr.ok) applyIssues(vr.issues); }); }
      selectOnly(Object.keys(S.nodes).length === 1 ? Object.keys(S.nodes)[0] : null);
      syncLinked(true);
    }).catch(function () { setSave('Could not load', true); });
  }
  window.addEventListener('resize', function () { drawMini(); });
  boot();
  window.__flow = S;   // handy for debugging in the console
})();

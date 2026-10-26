/*
 * WAAMINI motion layer (GSAP 3.13 + Flip).
 * Animation only: it never changes markup, classes, routes, forms or AJAX.
 * Every tween restores the element's own inline styles when it finishes.
 *
 * What runs on every client and admin page:
 *   - sidebar Flip pill when moving between pages
 *   - page title split into characters, header text and buttons slide in
 *   - every card / panel / grid tile found on the page staggers in;
 *     blocks below the fold reveal as they scroll into view
 *   - inside each block: rows stagger, numbers count up, bars grow,
 *     Chart.js charts replay with a staggered build
 *   - panels shown by tabs or buttons animate in
 *   - chat bubbles, list reorder, unread badge pop, tab indicator,
 *     modals and dropdowns, toggles, toasts
 *
 * Public API: window.WaaminiMotion.{enter, newMessage, reorderList, flipFilter}
 */
(function () {
  'use strict';
  if (typeof window.gsap === 'undefined') return;
  var gsap = window.gsap;
  var Flip = window.Flip || null;
  if (Flip) gsap.registerPlugin(Flip);

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) gsap.globalTimeline.timeScale(40);

  /* =================================================================
     helpers
     ================================================================= */
  // Inline styles a tween may touch. "transition" is switched off while a tween runs,
  // because many WAAMINI cards have CSS transitions on transform/opacity that would
  // otherwise smear every GSAP frame.
  var PROPS = ['transform', 'translate', 'rotate', 'scale', 'opacity', 'filter', 'transformOrigin', 'willChange', 'visibility', 'transition'];

  function $(sel, root) { try { return (root || document).querySelector(sel); } catch (e) { return null; } }
  function $$(sel, root) { try { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); } catch (e) { return []; } }
  function vh() { return window.innerHeight || document.documentElement.clientHeight || 800; }

  function shownBox(el) {
    if (!el || !el.getBoundingClientRect) return false;
    if (el.offsetParent === null && getComputedStyle(el).position !== 'fixed') return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }
  function inView(el, slack) {
    var r = el.getBoundingClientRect();
    return r.bottom > 0 && r.top < vh() * (slack || 1);
  }
  function visible(el) { return shownBox(el) && inView(el); }

  function keep(els) {
    return els.map(function (el) { return [el, PROPS.map(function (p) { return el.style[p]; })]; });
  }
  function restore(saved) {
    saved.forEach(function (s) { PROPS.forEach(function (p, i) { s[0].style[p] = s[1][i]; }); });
  }
  function quiet(els) { els.forEach(function (el) { el.style.transition = 'none'; }); }
  // Keep what the page's own update wrote, except the props Flip.getState itself sets.
  function merge(pre, post) {
    return post.map(function (s, i) {
      return [s[0], s[1].map(function (v, j) { return /^(translate|rotate|scale)$/.test(PROPS[j]) ? pre[i][1][j] : v; })];
    });
  }
  function idle(els) { return (els || []).filter(function (el) { return el && !gsap.isTweening(el) && !el.__wmHeld; }); }

  function from(els, vars) {
    els = idle(els);
    if (!els.length) return null;
    var saved = keep(els), done = vars.onComplete;
    quiet(els);
    vars.onComplete = function () { restore(saved); if (done) done(); };
    return gsap.from(els, vars);
  }
  function tlFrom(tl, els, vars, at) {
    els = idle(els);
    if (!els.length) return;
    var saved = keep(els), done = vars.onComplete;
    quiet(els);
    vars.onComplete = function () { restore(saved); if (done) done(); };
    tl.from(els, vars, at);
  }
  function busy(el) { return el && el.__wmBusy; }
  function alpha(c) {
    if (!c || c === 'transparent') return 0;
    var m = c.match(/rgba?\(([^)]+)\)/);
    if (!m) return 1;
    var p = m[1].split(/[\s,/]+/).filter(Boolean);
    return p.length > 3 ? parseFloat(p[3]) : 1;
  }
  function each(n, total) { return n > 1 ? Math.min(0.07, total / n) : 0; }

  /* =================================================================
     selectors (real WAAMINI classes)
     ================================================================= */
  var MAIN_SEL = 'main.main-content, main.admin-main';
  var TITLE_SEL = '.db-head h1, .page-header h1, .page-header h2, .cv-title, .wr-title, .wf-title, .drip-hero-title, h1, h2';
  var HEAD_SEL = '.page-header, .db-head, .su-head, .wf-hero, .tp-hero, .drip-hero, .la-hero, .ig-hero, .ga-hero';
  var KPI_SEL = '.db-kpi, .stat-card, .su-kpi, .cv-stat, .ct-kpi';
  var CARD_SEL = '.db-card, .form-card, .su-card, .sp-card, .sm-card, .data-table-wrapper, #chatWindow, #contactList';
  var BTN_SEL = '.btn, [class*="btn"], [class*="button"], [role="button"]';
  var ROW_SEL = 'tbody > tr, .ct-row:not(.ct-head), .wa-contact-item, .db-li, .db-meter__row';
  var COUNT_SEL = '.db-kpi__v, .stat-number, .stat-value, .cv-stat-value, .su-kpi-i, .ct-kpi-v, .sm-stat, .cp-stat, .ao-kpi b, .wf-stat b';
  var BAR_SEL = '.db-bar > i, .qbk-track > u, .wbc-track > u, .ax-track > u, .pu-track > u, .pax-track > u, .au-track > u, .api-track > u, .wf-track > u, .wf-track > i';
  var MODAL_SEL = '.su-modal, .om-overlay, .cg-modal, .fb-modal, .wfb-modal-overlay, .drip-modal-overlay, .wf-ov, .modal-overlay';
  var POP_SEL = '.wa-pop, .wa-menu, .topbar-dropdown';
  var TOAST_SEL = '.alert, .cv-toast, .wbc-toast, .zp-toast, .cp-toast, .sm-toast, .qbk-toast, #waToast';
  var HOLDS_OVERLAY = MODAL_SEL + ', [class*="modal"], [class*="overlay"], [class*="drawer"], [class*="popup"], dialog';
  var SKIP_TAGS = /^(SCRIPT|STYLE|LINK|META|SVG|CANVAS|INPUT|SELECT|TEXTAREA|BUTTON|LABEL|IMG|VIDEO|AUDIO|IFRAME|TEMPLATE|NOSCRIPT|BR|HR|THEAD|TBODY|TR|TD|TH|OPTION|I|B|STRONG|EM|SMALL|SPAN|P|H1|H2|H3|H4|H5|H6)$/;

  /* =================================================================
     1. Sidebar Flip pill
     ================================================================= */
  function initSidebar() {
    var nav = $('#sidebar .sidebar-nav');
    if (!nav) return;
    var pill = document.createElement('span');
    pill.className = 'wm-nav-pill';
    pill.setAttribute('aria-hidden', 'true');
    nav.insertBefore(pill, nav.firstChild);

    function place(item) {
      pill.style.top = item.offsetTop + 'px';
      pill.style.left = item.offsetLeft + 'px';
      pill.style.width = item.offsetWidth + 'px';
      pill.style.height = item.offsetHeight + 'px';
    }
    var active = $('.nav-item.active', nav);
    if (active) place(active);


    nav.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a.nav-item');
      if (!a || !nav.contains(a) || e.defaultPrevented) return;
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      if (a.classList.contains('locked') || a.classList.contains('active')) return;
      if (a.hasAttribute('onclick') || a.hasAttribute('download') || (a.target && a.target !== '_self')) return;
      var href = a.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return;
      var cur = $('.nav-item.active', nav);
      if (!cur) return;

      e.preventDefault();
      var cs = getComputedStyle(cur);
      pill.style.background = cs.backgroundColor;
      pill.style.borderRadius = cs.borderRadius;
      place(cur);
      nav.classList.add('wm-moving');
      pill.style.opacity = '1';
      if (Flip) {
        var state = Flip.getState(pill);
        place(a);
        Flip.from(state, { duration: 0.45, ease: 'power3.inOut' });
      } else {
        gsap.to(pill, { top: a.offsetTop, height: a.offsetHeight, duration: 0.45, ease: 'power3.inOut' });
      }
      setTimeout(function () { window.location.href = a.href; }, 250);
    });

    window.addEventListener('pageshow', function () {
      nav.classList.remove('wm-moving');
      pill.style.opacity = '';
      var act = $('.nav-item.active', nav);
      if (act) place(act);
    });
  }

  /* =================================================================
     2. Page title (split text) and header
     ================================================================= */
  function splitTitle(title) {
    var text = (title.textContent || '').replace(/\s+/g, ' ').trim();
    if (!text || text.length > 80) return null;
    var cs = getComputedStyle(title);
    if (cs.backgroundImage !== 'none' || (cs.webkitBackgroundClip || cs.backgroundClip) === 'text') return null;

    var walker = document.createTreeWalker(title, NodeFilter.SHOW_TEXT, null);
    var nodes = [], n;
    while ((n = walker.nextNode())) if (n.nodeValue.trim()) nodes.push(n);
    if (!nodes.length) return null;

    var addedLabel = !title.hasAttribute('aria-label');
    if (addedLabel) title.setAttribute('aria-label', text);
    var chars = [], swaps = [];
    nodes.forEach(function (node) {
      var wrap = document.createElement('span');
      wrap.className = 'wm-split';
      wrap.setAttribute('aria-hidden', 'true');
      node.nodeValue.split(/(\s+)/).forEach(function (part) {
        if (!part) return;
        if (/^\s+$/.test(part)) { wrap.appendChild(document.createTextNode(part)); return; }
        var w = document.createElement('span');
        w.className = 'wm-w';
        for (var i = 0; i < part.length; i++) {
          var c = document.createElement('span');
          c.className = 'wm-ch';
          c.textContent = part.charAt(i);
          w.appendChild(c);
          chars.push(c);
        }
        wrap.appendChild(w);
      });
      node.parentNode.replaceChild(wrap, node);
      swaps.push([wrap, node]);
    });
    return {
      chars: chars,
      undo: function () {
        swaps.forEach(function (s) { if (s[0].parentNode) s[0].parentNode.replaceChild(s[1], s[0]); });
        if (addedLabel) title.removeAttribute('aria-label');
      }
    };
  }

  function pageTitle(main) {
    var list = $$(TITLE_SEL, main);
    for (var i = 0; i < list.length; i++) {
      if (visible(list[i]) && !list[i].closest(MODAL_SEL)) return list[i];
    }
    return null;
  }

  // Returns the header element so block discovery can leave it alone.
  function enterTitle(main, tl) {
    var title = pageTitle(main);
    if (!title) return null;
    var split = splitTitle(title);
    if (split) {
      tl.from(split.chars, {
        yPercent: 70, opacity: 0, filter: 'blur(6px)', duration: 0.55,
        ease: 'power3.out', stagger: Math.min(0.02, 0.5 / split.chars.length), onComplete: split.undo
      }, 0);
    } else {
      tlFrom(tl, [title], { y: 10, opacity: 0, duration: 0.45, ease: 'power3.out' }, 0);
    }
    var head = title.closest(HEAD_SEL) || title.parentElement;
    if (!head || head === main) return title;
    var rest = $$(':scope > *', head).filter(function (el) { return !el.contains(title) && visible(el); }).slice(0, 8);
    // Header actions sit in a wrapper: animate the buttons inside it one by one.
    var parts = [];
    rest.forEach(function (el) {
      var kids = $$(':scope > a, :scope > button, :scope > .btn, :scope > form', el).filter(visible);
      if (kids.length > 1) parts = parts.concat(kids); else parts.push(el);
    });
    // Siblings of the title inside its own wrapper (subtitle under the h1).
    if (title.parentElement && title.parentElement !== head) {
      parts = $$(':scope > *', title.parentElement).filter(function (el) { return el !== title && visible(el); }).concat(parts);
    }
    tlFrom(tl, parts.slice(0, 10), { y: 8, opacity: 0, duration: 0.45, ease: 'power2.out', stagger: 0.05 }, 0.14);
    return head;
  }

  /* =================================================================
     3. Block discovery: cards, panels, grid tiles, tables
     ================================================================= */
  function isSurface(el, cs, r) {
    if (cs.position === 'fixed' || cs.position === 'sticky') return false;
    if (r.width < 90 || r.height < 34) return false;
    var bg = alpha(cs.backgroundColor) > 0.03 || cs.backgroundImage !== 'none';
    var shadow = cs.boxShadow && cs.boxShadow !== 'none';
    var radius = parseFloat(cs.borderTopLeftRadius) >= 6;
    var border = parseFloat(cs.borderTopWidth) > 0 || parseFloat(cs.borderLeftWidth) > 0;
    return !!(shadow || (bg && (radius || border)) || (border && radius));
  }

  function surfaces(root, skip) {
    var out = [], queue = [[root, 0]], seen = 0;
    while (queue.length && seen < 2000 && out.length < 90) {
      var item = queue.shift(), kids = item[0].children;
      for (var i = 0; i < kids.length; i++) {
        var k = kids[i];
        seen++;
        if (SKIP_TAGS.test(k.tagName.toUpperCase()) || k.id === 'sidebar' || k.hasAttribute('data-wm-skip')) continue;
        if (skip && (k === skip || k.contains(skip))) {
          // The page header itself is animated separately; look past it.
          if (k !== skip) queue.push([k, item[1] + 1]);
          continue;
        }
        if (k.matches(MODAL_SEL + ', ' + POP_SEL + ', ' + TOAST_SEL + ', ' + BTN_SEL)) continue;
        var cs = getComputedStyle(k);
        if (cs.display === 'none' || cs.visibility === 'hidden' || cs.position === 'fixed') continue;
        var r = k.getBoundingClientRect();
        if (!r.width || !r.height) continue;
        if (k.tagName === 'TABLE' || k.matches(KPI_SEL + ', ' + CARD_SEL) || isSurface(k, cs, r)) { out.push(k); continue; }
        if (item[1] < 7) queue.push([k, item[1] + 1]);
      }
    }
    // Nothing card-like (plain form pages): use the top-level blocks instead.
    if (!out.length) {
      out = $$(':scope > *', root).filter(function (el) {
        return !SKIP_TAGS.test(el.tagName.toUpperCase()) && el !== skip && !(skip && el.contains(skip)) &&
          !el.matches(MODAL_SEL + ', ' + TOAST_SEL) && shownBox(el) && el.offsetHeight > 30;
      }).slice(0, 20);
    }
    return out;
  }

  /* ---------- inside a block ---------- */
  function rowsOf(s) {
    var rows = $$(ROW_SEL, s).filter(function (el) { return shownBox(el) && el.offsetHeight >= 16; });
    if (!rows.length) {
      // Repeated children (lists, tiles, timelines) sharing the same class.
      var boxes = [s].concat($$(':scope > *, :scope > * > *', s)).slice(0, 60);
      for (var i = 0; i < boxes.length && !rows.length; i++) {
        var kids = Array.prototype.filter.call(boxes[i].children, function (el) { return !SKIP_TAGS.test(el.tagName.toUpperCase()) || el.tagName === 'A' || el.tagName === 'LI'; });
        if (kids.length < 3 || !kids[0].className) continue;
        var cls = String(kids[0].className).split(/\s+/)[0];
        var same = kids.filter(function (el) { return String(el.className).split(/\s+/)[0] === cls && shownBox(el) && el.offsetHeight >= 16; });
        if (same.length >= 3 && same.length >= kids.length * 0.6) rows = same;
      }
    }
    return rows.filter(function (el) { return inView(el, 1.15); }).slice(0, 14);
  }

  var NUM_RE = /^([^\d\s-]{0,2}\s?)(-?\d[\d,]*(?:\.\d+)?)(\s?(?:%|[A-Za-z]{1,4})?\+?)$/;
  function countTargets(s) {
    var list = $$(COUNT_SEL, s);
    var leaves = $$('b, strong, span, div, h2, h3, h4, h5, p, td', s).slice(0, 500);
    leaves.forEach(function (el) {
      if (el.children.length || list.indexOf(el) !== -1) return;
      var t = (el.textContent || '').trim();
      if (!t || t.length > 18 || !NUM_RE.test(t)) return;
      if (parseFloat(getComputedStyle(el).fontSize) < 18) return;
      list.push(el);
    });
    return list.slice(0, 14);
  }

  function countUp(el, delay) {
    if (el.children.length || busy(el) || !shownBox(el)) return;
    var original = el.textContent;
    var t = original.trim();
    var m = t.match(NUM_RE) || t.match(/^([^\d-]*)(-?\d[\d,]*(?:\.\d+)?)([\s\S]*)$/);
    if (!m) return;
    var numStr = m[2], value = parseFloat(numStr.replace(/,/g, ''));
    if (!isFinite(value) || Math.abs(value) < 2 || Math.abs(value) > 1e12) return;
    var dec = (numStr.split('.')[1] || '').length;
    var opts = { minimumFractionDigits: dec, maximumFractionDigits: dec };
    var fmt;
    if (numStr.indexOf(',') === -1) fmt = function (v) { return v.toFixed(dec); };
    else if (value.toLocaleString('en-IN', opts) === numStr) fmt = function (v) { return v.toLocaleString('en-IN', opts); };
    else if (value.toLocaleString('en-US', opts) === numStr) fmt = function (v) { return v.toLocaleString('en-US', opts); };
    else return;
    var lead = original.slice(0, original.indexOf(t));
    var tail = original.slice(original.indexOf(t) + t.length);
    var write = function (v) { return lead + m[1] + fmt(v) + m[3] + tail; };

    var obj = { v: 0 };
    el.__wmBusy = true;
    // Keep the box from jumping while digits change width.
    var minW = el.style.minWidth;
    if (getComputedStyle(el).display !== 'inline') el.style.minWidth = el.offsetWidth + 'px';
    var last = write(0);
    el.textContent = last;
    var tw = gsap.to(obj, {
      v: value, duration: 1.2, ease: 'power2.out', delay: delay || 0,
      onUpdate: function () {
        if (el.textContent !== last) { el.__wmBusy = false; el.style.minWidth = minW; tw.kill(); return; }
        last = write(obj.v);
        el.textContent = last;
      },
      onComplete: function () { el.textContent = original; el.style.minWidth = minW; el.__wmBusy = false; }
    });
  }

  function barTargets(s) {
    var bars = $$(BAR_SEL, s).map(function (el) { return [el, 'x']; });
    var known = bars.map(function (b) { return b[0]; });
    $$('[style*="width"]', s).slice(0, 200).forEach(function (el) {
      if (known.indexOf(el) !== -1 || /^(IMG|INPUT|TEXTAREA|SELECT|CANVAS|SVG|TABLE|TD|TH)$/i.test(el.tagName)) return;
      if (!/%\s*$/.test(el.style.width) || (el.textContent || '').trim()) return;
      var h = el.offsetHeight;
      if (h >= 2 && h <= 18 && el.parentElement && el.parentElement.offsetHeight <= 24) { bars.push([el, 'x']); known.push(el); }
    });
    $$('[style*="height"]', s).slice(0, 200).forEach(function (el) {
      if (known.indexOf(el) !== -1 || /^(IMG|INPUT|TEXTAREA|SELECT|CANVAS|SVG|TABLE)$/i.test(el.tagName)) return;
      if (!/%\s*$/.test(el.style.height) || (el.textContent || '').trim()) return;
      if (el.offsetWidth <= 70 && el.offsetHeight >= 4) { bars.push([el, 'y']); known.push(el); }
    });
    return bars.filter(function (b) { return shownBox(b[0]) && !b[0].style.transform; }).slice(0, 60);
  }

  function growBars(s, delay) {
    var bars = barTargets(s);
    var xs = bars.filter(function (b) { return b[1] === 'x'; }).map(function (b) { return b[0]; });
    var ys = bars.filter(function (b) { return b[1] === 'y'; }).map(function (b) { return b[0]; });
    if (xs.length) from(xs, { scaleX: 0, transformOrigin: 'left center', duration: 0.9, ease: 'power3.out', stagger: each(xs.length, 0.5), delay: delay });
    if (ys.length) from(ys, { scaleY: 0, transformOrigin: 'center bottom', duration: 0.8, ease: 'power3.out', stagger: each(ys.length, 0.5), delay: delay });
  }

  /* ---------- Chart.js: replay each chart with a staggered build ---------- */
  function chartDelay(ctx) {
    return ctx && ctx.type === 'data' && ctx.mode === 'default' ? Math.min(ctx.dataIndex * 45 + ctx.datasetIndex * 110, 750) : 0;
  }
  function chartsIn(s) {
    var C = window.Chart;
    if (!C || !C.getChart) return [];
    return $$('canvas', s).map(function (c) { try { return C.getChart(c); } catch (e) { return null; } }).filter(Boolean);
  }
  function replayChart(chart, delay) {
    if (!chart || chart.__wmPlayed) return;
    chart.__wmPlayed = true;
    try {
      var a = chart.options && chart.options.animation;
      if (a === false || !chart.canvas || !shownBox(chart.canvas)) return;
      if (a && typeof a === 'object' && !a.delay) a.delay = chartDelay;
      setTimeout(function () {
        try { chart.reset(); chart.update(); } catch (e) {}
      }, Math.max(0, (delay || 0) * 1000));
    } catch (e) {}
  }
  function initChartDefaults() {
    var C = window.Chart;
    try {
      if (C && C.defaults && C.defaults.animation && !C.defaults.animation.delay) C.defaults.animation.delay = chartDelay;
    } catch (e) {}
  }
  // Charts built after this script ran (DOMContentLoaded / load handlers).
  function lateCharts() {
    var C = window.Chart;
    if (!C || !C.instances) return;
    initChartDefaults();
    Object.keys(C.instances).forEach(function (k) {
      var ch = C.instances[k];
      if (!ch || ch.__wmPlayed || !ch.canvas) return;
      var host = ch.canvas.closest('[data-wm-wait]');
      if (host) return; // its block reveals later and replays it then
      if (visible(ch.canvas)) replayChart(ch, 0);
    });
  }

  function innerMotion(s, delay) {
    delay = delay || 0;
    var head = $('h2, h3, h4, h5, [class*="__head"], [class*="-head"], [class*="title"]', s);
    if (head && head !== s && shownBox(head) && !head.querySelector('canvas, table')) {
      from([head], { x: -8, opacity: 0, duration: 0.45, ease: 'power2.out', delay: delay + 0.05 });
    }
    var rows = rowsOf(s);
    if (rows.length) from(rows, { y: 8, opacity: 0, duration: 0.35, ease: 'power2.out', stagger: each(rows.length, 0.45), delay: delay + 0.1 });
    countTargets(s).forEach(function (el) { countUp(el, delay + 0.05); });
    growBars(s, delay + 0.15);
    chartsIn(s).forEach(function (ch) { replayChart(ch, delay + 0.1); });
  }

  /* ---------- scroll reveal for blocks below the fold ---------- */
  var io = null;
  function revealBatch(list) {
    list = list.filter(function (s) { return s.__wmHeld; });
    if (!list.length) return;
    var gap = each(list.length, 0.35);
    list.forEach(function (s, i) {
      var saved = s.__wmSaved;
      s.__wmHeld = false;
      s.removeAttribute('data-wm-wait');
      gsap.to(s, {
        opacity: 1, y: 0, duration: 0.6, ease: 'power3.out', delay: i * gap,
        onComplete: function () { restore(saved); }
      });
      innerMotion(s, i * gap + 0.08);
    });
  }
  function hold(s) {
    // Blocks holding a modal stay visible so a modal can never open inside a hidden card.
    if (s.querySelector(HOLDS_OVERLAY) || s.closest(HOLDS_OVERLAY)) {
      s.setAttribute('data-wm-wait', '');
      if (io) io.observe(s);
      s.__wmLite = true;
      return;
    }
    s.__wmSaved = keep([s]);
    quiet([s]);
    gsap.set(s, { opacity: 0, y: 26 });
    s.__wmHeld = true;
    s.setAttribute('data-wm-wait', '');
    if (io) io.observe(s);
    else revealBatch([s]);
  }
  function initObserver() {
    if (!('IntersectionObserver' in window)) return;
    io = new IntersectionObserver(function (entries) {
      var batch = [];
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var s = en.target;
        io.unobserve(s);
        if (s.__wmLite) { s.__wmLite = false; s.removeAttribute('data-wm-wait'); innerMotion(s, 0); return; }
        batch.push(s);
      });
      batch.sort(function (a, b) {
        var ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
        return (ra.top - rb.top) || (ra.left - rb.left);
      });
      revealBatch(batch);
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.01 });
    // Printing or saving the page: show everything.
    window.addEventListener('beforeprint', function () {
      $$('[data-wm-wait]').forEach(function (s) { if (s.__wmHeld) { s.__wmHeld = false; restore(s.__wmSaved); } });
    });
  }

  /* ---------- run blocks inside any root ---------- */
  function animateBlocks(root, tl, at, skip) {
    var list = surfaces(root, skip);
    var now = [], later = [];
    list.forEach(function (s) { (inView(s, 0.96) ? now : later).push(s); });
    var gap = each(now.length, 0.6);
    if (now.length) {
      tlFrom(tl, now, { y: 18, opacity: 0, duration: 0.6, ease: 'power3.out', stagger: gap }, at);
      now.forEach(function (s, i) { innerMotion(s, at + i * gap + 0.08); });
    }
    later.forEach(hold);
  }

  /* ---------- public: enter() for AJAX-loaded or newly shown content ---------- */
  function enter(container) {
    if (!container || !container.nodeType) return null;
    var tl = gsap.timeline();
    animateBlocks(container, tl, 0, null);
    return tl;
  }

  function pageEntrance() {
    var main = $(MAIN_SEL);
    if (!main || document.body.hasAttribute('data-wm-off')) return;
    var tl = gsap.timeline();
    var head = enterTitle(main, tl);
    animateBlocks(main, tl, head ? 0.2 : 0, head);
  }

  /* ---------- panels shown by tabs, "show more", steps ---------- */
  var PANEL_SEL = '[id], [class*="tab"], [class*="panel"], [class*="pane"], [class*="step"], [class*="view"], [class*="section"], [class*="body"], [class*="list"], [class*="grid"]';
  function initPanels() {
    var main = $(MAIN_SEL);
    if (!main) return;
    main.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest(MODAL_SEL + ', ' + POP_SEL)) return;
      var hidden = $$(PANEL_SEL, main).slice(0, 700).filter(function (el) { return el.offsetParent === null && !el.closest(MODAL_SEL); });
      if (!hidden.length) return;
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          var shown = hidden.filter(function (el) { return el.offsetParent !== null && el.offsetHeight > 40 && inView(el); });
          shown = shown.filter(function (el) { return !shown.some(function (o) { return o !== el && o.contains(el); }); });
          shown.slice(0, 4).forEach(function (el) {
            if (el.matches(TOAST_SEL)) return;
            var blocks = surfaces(el, null);
            if (blocks.length && !(blocks.length === 1 && blocks[0] === el)) enter(el);
            else from([el], { y: 10, opacity: 0, duration: 0.4, ease: 'power2.out' });
          });
        });
      });
    }, true);
  }

  /* =================================================================
     4. Chat
     ================================================================= */
  function newMessage(el) {
    if (!el || !el.nodeType) return null;
    var out = el.classList && el.classList.contains('outgoing');
    return from([el], { y: 10, x: out ? 8 : -8, opacity: 0, duration: 0.3, ease: 'power2.out' });
  }

  function reorderList(listEl, updateFn) {
    if (typeof updateFn !== 'function') return;
    if (!listEl || !Flip) { updateFn(); return; }
    var kids = Array.prototype.slice.call(listEl.children);
    if (kids.length > 300 || kids.some(function (el) { return gsap.isTweening(el); })) { updateFn(); return; }
    var targets = kids.filter(visible).slice(0, 60);
    var before = kids.slice();
    var pre = keep(targets); // Flip.getState writes translate/rotate/scale inline, so snapshot first
    var state = targets.length ? Flip.getState(targets) : null;
    updateFn();
    var saved = merge(pre, keep(targets));
    var after = Array.prototype.slice.call(listEl.children);
    var moved = after.length !== before.length || after.some(function (el, i) { return el !== before[i]; });
    if (!state || !moved) { restore(saved); return; }
    quiet(targets);
    // Flip tidies its own props after onComplete, so hand styles back on the next tick.
    return Flip.from(state, { duration: 0.4, ease: 'power2.inOut', onComplete: function () { setTimeout(function () { restore(saved); }, 0); } });
  }

  function popBadge(el) {
    if (!el || busy(el)) return;
    el.__wmBusy = true;
    var s = keep([el]);
    quiet([el]);
    gsap.fromTo(el, { scale: 0.4 }, { scale: 1, duration: 0.45, ease: 'back.out(2)', onComplete: function () { restore(s); el.__wmBusy = false; } });
  }

  function initChat() {
    var win = document.getElementById('chatWindow');
    if (win && window.MutationObserver) {
      new MutationObserver(function (muts) {
        var rows = [];
        muts.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (n.nodeType === 1 && n.classList.contains('wa-message-row')) rows.push(n);
          });
        });
        if (rows.length && rows.length <= 4) rows.forEach(newMessage);
      }).observe(win, { childList: true });
    }

    var list = document.getElementById('contactList');
    if (list && window.MutationObserver) {
      var seen = new WeakMap();
      $$('.wa-unread-badge', list).forEach(function (b) { seen.set(b, b.style.display === 'none' ? '' : b.textContent); });
      new MutationObserver(function () {
        $$('.wa-unread-badge', list).forEach(function (b) {
          var now = b.style.display === 'none' ? '' : b.textContent;
          var was = seen.has(b) ? seen.get(b) : now;
          seen.set(b, now);
          if (now && now !== was) popBadge(b);
        });
      }).observe(list, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['style'] });
    }
  }

  /* =================================================================
     5. Tabs / filters
     ================================================================= */
  var TAB_SEL = '.sm-chips button, .wf-tabs button, .cc-seg button, .wbc-tab, .ads-chip, .wa-chip, [role="tab"]';
  var ON = ['on', 'is-on', 'is-active', 'active', 'selected'];
  function isOn(el) { return ON.some(function (c) { return el.classList.contains(c); }) || el.getAttribute('aria-selected') === 'true'; }

  function initTabs() {
    document.addEventListener('click', function (e) {
      var tab = e.target.closest && e.target.closest(TAB_SEL);
      if (!tab || !tab.parentElement) return;
      var href = tab.tagName === 'A' ? (tab.getAttribute('href') || '') : '#';
      if (href && href.charAt(0) !== '#') return;
      var group = tab.parentElement;
      var sibs = Array.prototype.filter.call(group.children, function (el) { return el.matches(TAB_SEL); });
      var old = sibs.filter(isOn)[0];
      requestAnimationFrame(function () {
        var now = sibs.filter(isOn)[0];
        if (!now || now === old) return;
        slideIndicator(group, old, now);
      });
    }, true);
  }

  function slideIndicator(group, old, now) {
    var cs = getComputedStyle(now);
    var hasFill = alpha(cs.backgroundColor) > 0.03 || cs.backgroundImage !== 'none';
    if (!old || !hasFill || busy(group)) {
      from([now], { scale: 0.94, duration: 0.3, ease: 'back.out(2)' });
      return;
    }
    group.__wmBusy = true;
    var ghost = document.createElement('span');
    ghost.className = 'wm-tab-ghost';
    ghost.setAttribute('aria-hidden', 'true');
    ghost.style.backgroundColor = cs.backgroundColor;
    ghost.style.backgroundImage = cs.backgroundImage;
    ghost.style.borderRadius = cs.borderRadius;
    ghost.style.boxShadow = cs.boxShadow;
    var iso = group.style.isolation, nowBg = now.style.background, nowTr = now.style.transition;
    group.style.isolation = 'isolate';
    group.appendChild(ghost);
    var op = ghost.offsetParent || document.body;
    var base = op.getBoundingClientRect();
    function put(el) {
      var r = el.getBoundingClientRect();
      ghost.style.left = (r.left - base.left + op.scrollLeft - op.clientLeft) + 'px';
      ghost.style.top = (r.top - base.top + op.scrollTop - op.clientTop) + 'px';
      ghost.style.width = r.width + 'px';
      ghost.style.height = r.height + 'px';
    }
    put(old);
    now.style.transition = 'none';
    now.style.background = 'transparent';
    function finish() {
      now.style.background = nowBg;
      now.style.transition = nowTr;
      group.style.isolation = iso;
      if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
      group.__wmBusy = false;
    }
    if (Flip) {
      var st = Flip.getState(ghost);
      put(now);
      Flip.from(st, { duration: 0.38, ease: 'power3.inOut', onComplete: finish });
    } else {
      var r = now.getBoundingClientRect();
      gsap.to(ghost, { left: '+=' + (r.left - old.getBoundingClientRect().left), width: r.width, duration: 0.38, ease: 'power3.inOut', onComplete: finish });
    }
  }

  function flipFilter(container, updateFn) {
    if (typeof updateFn !== 'function') return;
    if (!container || !Flip) { updateFn(); return; }
    var kids = Array.prototype.slice.call(container.children).slice(0, 200);
    var pre = keep(kids);
    var state = Flip.getState(kids);
    updateFn();
    var saved = merge(pre, keep(kids));
    quiet(kids);
    return Flip.from(state, {
      duration: 0.35, ease: 'power2.inOut',
      onEnter: function (els) { return gsap.fromTo(els, { opacity: 0 }, { opacity: 1, duration: 0.25 }); },
      onComplete: function () { setTimeout(function () { restore(saved); }, 0); }
    });
  }

  /* =================================================================
     6. Modals and dropdowns (open)
     ================================================================= */
  function shown(el) { var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }

  function openModal(el) {
    var box = el.firstElementChild;
    from([el], { opacity: 0, duration: 0.2, ease: 'power1.out' });
    if (box) {
      from([box], { scale: 0.96, y: 8, opacity: 0, duration: 0.28, ease: 'power2.out' });
      var bits = $$(':scope > *', box).filter(shownBox).slice(0, 8);
      if (bits.length > 1) from(bits, { y: 6, opacity: 0, duration: 0.3, ease: 'power2.out', stagger: 0.035, delay: 0.08 });
    }
  }
  function openPop(el) {
    // chat composer popups (.wa-pop) fade in with plain CSS in whatsapp.php; a JS tween there could leave them invisible
    if (el.classList.contains('wa-pop')) return;
    var origin = el.classList.contains('wa-pop') ? 'bottom left' : 'top right';
    // fromTo with an explicit end, not from(): from() animates towards the *current* opacity, so
    // re-opening a menu while its last fade was still running froze it at ~1% opacity.
    gsap.killTweensOf(el);
    gsap.fromTo(el, { scale: 0.96, opacity: 0 },
      { scale: 1, opacity: 1, transformOrigin: origin, duration: 0.2, ease: 'power2.out',
        clearProps: 'opacity,transform,scale,transformOrigin' });
  }

  function initOverlays() {
    if (!window.MutationObserver) return;
    var state = new WeakMap();
    $$(MODAL_SEL + ', ' + POP_SEL).forEach(function (el) { state.set(el, shown(el)); });
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        var el = m.target;
        if (el.nodeType !== 1 || el.__wmBusy) return;
        var isModal = el.matches(MODAL_SEL), isPop = !isModal && el.matches(POP_SEL);
        if (!isModal && !isPop) return;
        var now = shown(el), was = state.has(el) ? state.get(el) : false;
        state.set(el, now);
        if (now && !was) {
          el.__wmBusy = true;
          (isModal ? openModal : openPop)(el);
          setTimeout(function () { el.__wmBusy = false; }, 350);
        }
      });
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
  }

  /* =================================================================
     7. Toggles
     ================================================================= */
  function initToggles() {
    document.addEventListener('change', function (e) {
      var input = e.target;
      if (!input || !input.matches || !input.matches('.toggle-switch input[type="checkbox"]')) return;
      var slider = input.parentElement && input.parentElement.querySelector('.toggle-slider');
      if (slider) from([slider], { scale: 0.88, duration: 0.45, ease: 'back.out(2.2)' });
    });
  }

  /* =================================================================
     8. Toasts
     ================================================================= */
  function toast(el) {
    if (!el || busy(el)) return;
    el.__wmBusy = true;
    from([el], { scale: 0.92, y: -6, opacity: 0, duration: 0.45, ease: 'back.out(1.8)', onComplete: function () { el.__wmBusy = false; } });
  }
  function initToasts() {
    $$(TOAST_SEL, $(MAIN_SEL) || document.body).filter(visible).slice(0, 4).forEach(toast);
    if (!window.MutationObserver) return;
    var wa = { shown: false };
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        if (m.type === 'childList') {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (n.nodeType === 1 && n.matches && n.matches(TOAST_SEL) && visible(n)) toast(n);
          });
        } else if (m.target.id === 'waToast') {
          var now = m.target.style.display !== 'none';
          if (now && !wa.shown) toast(m.target);
          wa.shown = now;
        }
      });
    }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['style'] });
  }

  /* =================================================================
     boot
     ================================================================= */
  function boot() {
    try { initChartDefaults(); } catch (e) {}
    try { initObserver(); } catch (e) {}
    try { initSidebar(); } catch (e) {}
    try { pageEntrance(); } catch (e) {}
    // The header holds the main area hidden until here (no flash of content before the entrance starts)
    document.documentElement.classList.remove('wm-pre');
    try { initPanels(); } catch (e) {}
    try { initChat(); } catch (e) {}
    try { initTabs(); } catch (e) {}
    try { initOverlays(); } catch (e) {}
    try { initToggles(); } catch (e) {}
    try { initToasts(); } catch (e) {}
    var late = function () { try { lateCharts(); } catch (e) {} };
    document.addEventListener('DOMContentLoaded', function () { setTimeout(late, 0); });
    window.addEventListener('load', late);
  }

  window.WaaminiMotion = {
    enter: enter,
    newMessage: newMessage,
    reorderList: reorderList,
    flipFilter: flipFilter
  };

  // Loaded at the end of <body>, so the page markup is already here: start now,
  // before first paint, so nothing flashes.
  if (document.body) boot();
  else document.addEventListener('DOMContentLoaded', boot);
})();

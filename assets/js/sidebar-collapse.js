/* Sidebar for the client and admin panels:
   - round toggle on the sidebar edge (collapse to icons / expand)
   - "Quick find" search that filters the menu
   - green hover label in icon-only mode
   State is remembered in localStorage. */
(function () {
  var KEY = 'wmn_sidebar_collapsed';
  var sb = document.getElementById('sidebar');
  if (!sb || document.querySelector('.sb-edge')) { return; }
  var nav = sb.querySelector('.sidebar-nav');

  var CHEV = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
  var MAG = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>';

  function get() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  /* icon-only mode is a desktop feature: phones and tablets always get the full menu */
  var WIDE = window.matchMedia('(min-width: 1025px)');
  function set(v) { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) { /* ignore */ } }

  /* ── edge toggle ── */
  var edge = document.createElement('button');
  edge.type = 'button'; edge.className = 'sb-edge'; edge.innerHTML = CHEV;
  document.body.appendChild(edge);

  function apply(collapsed) {
    document.body.classList.toggle('sb-collapsed', collapsed);
    edge.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    edge.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    edge.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    hideTip();
  }
  edge.addEventListener('click', function () { var n = !document.body.classList.contains('sb-collapsed'); set(n); apply(n); });

  /* ── quick find ── */
  var find = null, input = null;
  if (nav) {
    find = document.createElement('div');
    find.className = 'sb-find';
    find.innerHTML = '<label class="sb-find__box">' + MAG + '<input type="search" placeholder="Quick find" aria-label="Quick find in menu" autocomplete="off"><kbd>/</kbd></label>'
      + '<button type="button" class="sb-find__btn" aria-label="Quick find" data-sb-label="Quick find">' + MAG + '</button>';
    nav.parentNode.insertBefore(find, nav);
    input = find.querySelector('input');
    var none = document.createElement('div');
    none.className = 'sb-none'; none.textContent = 'No menu items match.';
    nav.appendChild(none);

    var items = function () { return Array.prototype.slice.call(nav.querySelectorAll('.nav-item')); };
    var titles = function () { return Array.prototype.slice.call(nav.querySelectorAll('.nav-section-title')); };
    function label(a) {
      var c = a.cloneNode(true);
      Array.prototype.forEach.call(c.querySelectorAll('.icon,.badge,svg'), function (n) { n.remove(); });
      return (c.textContent || '').replace(/\s+/g, ' ').replace(/^[^\w]+/, '').trim();
    }
    function filter() {
      var q = input.value.trim().toLowerCase(), shown = 0;
      items().forEach(function (a) {
        var ok = !q || label(a).toLowerCase().indexOf(q) !== -1;
        a.classList.toggle('sb-hide', !ok);
        if (ok) { shown++; }
      });
      // a section title stays only when something below it matches
      titles().forEach(function (t) {
        var n = t.nextElementSibling, any = !q;
        while (n && !n.classList.contains('nav-section-title')) {
          if (n.matches && (n.matches('.nav-item:not(.sb-hide)') || n.querySelector('.nav-item:not(.sb-hide)'))) { any = true; break; }
          n = n.nextElementSibling;
        }
        t.classList.toggle('sb-hide', !any);
      });
      none.style.display = (q && !shown) ? 'block' : 'none';
    }
    input.addEventListener('input', filter);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { input.value = ''; filter(); input.blur(); }
      else if (e.key === 'Enter') { var first = nav.querySelector('.nav-item:not(.sb-hide)[href]'); if (first && first.getAttribute('href') !== '#') { window.location.href = first.href; } }
    });
    find.querySelector('.sb-find__btn').addEventListener('click', function () {
      set(false); apply(false);
      setTimeout(function () { input.focus(); }, 280);
    });
    // press "/" anywhere (outside inputs) to jump to the search
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
      var t = e.target, tag = t && t.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) { return; }
      e.preventDefault();
      if (document.body.classList.contains('sb-collapsed')) { set(false); apply(false); setTimeout(function () { input.focus(); }, 280); } else { input.focus(); }
    });
  }

  /* ── hover label in icon-only mode ── */
  var tip = document.createElement('div');
  tip.className = 'sb-tip'; document.body.appendChild(tip);
  function hideTip() { tip.classList.remove('is-on'); }
  function labelFor(el) {
    if (el.hasAttribute('data-sb-label')) { return el.getAttribute('data-sb-label'); }
    var c = el.cloneNode(true);
    Array.prototype.forEach.call(c.querySelectorAll('.icon,.badge,svg'), function (n) { n.remove(); });
    return (c.textContent || '').replace(/\s+/g, ' ').replace(/^[^\w]+/, '').trim();
  }
  sb.addEventListener('mouseover', function (e) {
    if (!document.body.classList.contains('sb-collapsed')) { return; }
    var el = e.target.closest('.nav-item, .sb-find__btn, .company-badge, .sidebar-header .logo, .sidebar-brand');
    if (!el || !sb.contains(el)) { hideTip(); return; }
    var text = el.classList.contains('company-badge') ? (el.textContent || '').replace(/\s+/g, ' ').trim() : labelFor(el);
    if (!text) { hideTip(); return; }
    var r = el.getBoundingClientRect();
    tip.textContent = text;
    tip.style.left = (r.right + 12) + 'px';
    tip.style.top = (r.top + r.height / 2 - 17) + 'px';
    tip.classList.add('is-on');
  });
  sb.addEventListener('mouseleave', hideTip);
  sb.addEventListener('scroll', hideTip, {passive: true});
  window.addEventListener('resize', hideTip);

  apply(get() && WIDE.matches);
  var onWide = function () { apply(get() && WIDE.matches); };
  if (WIDE.addEventListener) { WIDE.addEventListener('change', onWide); } else if (WIDE.addListener) { WIDE.addListener(onWide); }
})();

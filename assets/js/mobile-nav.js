/* Phone menu (client + admin panels): close button, page scroll lock, close after picking a page.
 * Works alongside the existing open/close code: it only watches the sidebar's "open" class. */
(function () {
  var sb = document.getElementById('sidebar') || document.querySelector('.admin-sidebar, .sidebar');
  if (!sb) return;
  var phone = window.matchMedia ? window.matchMedia('(max-width: 900px)') : { matches: false };

  function close() {
    sb.classList.remove('open');
    document.body.classList.remove('admin-sidebar-open');
    var ov = document.getElementById('sidebarOverlay');
    if (ov) ov.classList.remove('show');
  }

  // ✕ button in the menu header
  if (!sb.querySelector('.wm-nav-close')) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'wm-nav-close';
    b.setAttribute('aria-label', 'Close menu');
    b.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    b.addEventListener('click', function (e) { e.stopPropagation(); close(); });
    sb.insertBefore(b, sb.firstChild);
  }

  // lock the page behind the open menu
  function sync() { document.documentElement.classList.toggle('wm-nav-open', phone.matches && sb.classList.contains('open')); }
  if (window.MutationObserver) new MutationObserver(sync).observe(sb, { attributes: true, attributeFilter: ['class'] });
  if (phone.addEventListener) phone.addEventListener('change', sync);
  sync();

  // picking a page closes the menu at once (feels like an app); submenu headers keep it open
  sb.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || !phone.matches) return;
    var h = a.getAttribute('href') || '';
    if (h === '#' || h.indexOf('javascript:') === 0 || a.classList.contains('has-submenu')) return;
    setTimeout(close, 60);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sb.classList.contains('open')) close(); });

  // swipe left on the menu to close it
  var x0 = null;
  sb.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  sb.addEventListener('touchend', function (e) {
    if (x0 !== null && e.changedTouches[0].clientX - x0 < -70) close();
    x0 = null;
  }, { passive: true });
})();

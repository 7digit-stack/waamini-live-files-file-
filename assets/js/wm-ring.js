/* WAAMINI ring overview: draws the SVG rings for every .wm-flow block (same as the Dashboard "Activity overview").
 * Optional attributes on .wm-flow: data-centre-name, data-centre-sub. Per step: data-sweep (20-270), data-icon. */
(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var CX = 600, CY = 410;
  var CORE_R = 90;
  var BANDS = [[98, 160], [160, 223], [223, 286]];
  var CARD_RIGHT = 280;
  var CARD_Y = [82, 222, 362];
  var CENTRE_NAME = 'WAAMINI';
  var CENTRE_SUB  = 'WHATSAPP CRM';

  var ICONS = {
    capture:  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    automate: '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    convert:  '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    rupee:    '<path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/>',
    bot:      '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    bag:      '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>'
  };

  function el(name, attrs, html) {
    var n = document.createElementNS(NS, name);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    if (html) n.innerHTML = html;
    return n;
  }
  function pt(r, deg) {
    var a = deg * Math.PI / 180;
    return [CX + r * Math.sin(a), CY - r * Math.cos(a)];
  }
  function f(n) { return Math.round(n * 100) / 100; }

  document.querySelectorAll('.wm-flow').forEach(function (root) {
    if (root.__wmRing) return; // already drawn
    root.__wmRing = true;
    var svg   = root.querySelector('.wm-flow__svg');
    var steps = Array.prototype.slice.call(root.querySelectorAll('.wm-step')).slice(0, 3);
    if (!svg || !steps.length) return;

    svg.innerHTML =
      '<defs><filter id="wmShadow" x="-20%" y="-20%" width="140%" height="140%">' +
      '<feDropShadow dx="0" dy="3" stdDeviation="7" flood-color="#0F172A" flood-opacity="0.24"/>' +
      '</filter></defs>';

    var gRings = el('g', {}), gLinks = el('g', {}), gTop = el('g', {});
    var gDots  = el('g', { 'class': 'wm-dots' });
    var calm   = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    steps.forEach(function (li, i) {
      var cs      = getComputedStyle(li);
      var color   = cs.getPropertyValue('--c').trim() || '#1E1B4B';
      var onColor = cs.getPropertyValue('--t').trim() || '#FFFFFF';
      var sweep   = Math.max(20, Math.min(270, parseFloat(li.getAttribute('data-sweep')) || 270));
      var rIn = BANDS[i][0], rOut = BANDS[i][1], r = (rIn + rOut) / 2;
      var start = pt(r, 0), end = pt(r, sweep);
      li.setAttribute('data-i', i);
      li.style.setProperty('--i', i);

      var ring = el('path', {
        'class': 'wm-ring', 'data-i': i, style: '--i:' + i, pathLength: 1,
        d: 'M' + f(start[0]) + ' ' + f(start[1]) +
           ' A' + r + ' ' + r + ' 0 ' + (sweep > 180 ? 1 : 0) + ' 1 ' + f(end[0]) + ' ' + f(end[1]),
        stroke: color, 'stroke-width': rOut - rIn, filter: 'url(#wmShadow)'
      });
      gRings.insertBefore(ring, gRings.firstChild);

      var xv = CX - r, y = CARD_Y[i], c = 18;
      var d = 'M' + CARD_RIGHT + ' ' + y + ' H' + f(xv - c) +
              ' Q' + f(xv) + ' ' + y + ' ' + f(xv) + ' ' + (y + c) + ' V' + CY;
      if (sweep < 270) d += ' A' + r + ' ' + r + ' 0 0 0 ' + f(end[0]) + ' ' + f(end[1]);
      gLinks.appendChild(el('path', { 'class': 'wm-link', 'data-i': i, style: '--i:' + i, pathLength: 1, d: d, stroke: color }));

      if (!calm) {
        var dot = el('circle', { r: 4, fill: color, 'data-i': i });
        dot.appendChild(el('animateMotion', { path: d, dur: '2.8s', begin: (i * 0.45) + 's', repeatCount: 'indefinite' }));
        dot.appendChild(el('animate', { attributeName: 'opacity', values: '0;1;1;0', keyTimes: '0;0.08;0.88;1', dur: '2.8s', begin: (i * 0.45) + 's', repeatCount: 'indefinite' }));
        gDots.appendChild(dot);
      }

      var ip = pt(r, sweep - (24 / r) * 180 / Math.PI), size = 24;
      var iconWrap = el('g', {
        'data-i': i, 'pointer-events': 'none',
        transform: 'translate(' + f(ip[0] - size / 2) + ' ' + f(ip[1] - size / 2) + ') scale(' + size / 24 + ')'
      });
      iconWrap.appendChild(el('g', { 'class': 'wm-icon', style: '--i:' + i, stroke: onColor },
        ICONS[li.getAttribute('data-icon')] || ICONS.convert));
      gTop.appendChild(iconWrap);

      var num = el('text', { 'class': 'wm-num', 'data-i': i, style: '--i:' + i, x: CX - 14, y: f(CY - r + 6), 'text-anchor': 'end' });
      num.textContent = ('0' + (i + 1)).slice(-2);
      gTop.appendChild(num);
    });

    var core = el('g', { 'class': 'wm-core' });
    core.appendChild(el('circle', { 'class': 'wm-core__disc', cx: CX, cy: CY, r: CORE_R, fill: '#FFFFFF', filter: 'url(#wmShadow)' }));
    var name = el('text', { 'class': 'wm-core__name', x: CX, y: CY + 3, 'text-anchor': 'middle' });
    name.textContent = root.getAttribute('data-centre-name') || CENTRE_NAME;
    var sub = el('text', { 'class': 'wm-core__sub', x: CX, y: CY + 24, 'text-anchor': 'middle' });
    sub.textContent = root.getAttribute('data-centre-sub') || CENTRE_SUB;
    core.appendChild(name); core.appendChild(sub); gTop.appendChild(core);

    svg.appendChild(gLinks); svg.appendChild(gDots); svg.appendChild(gRings); svg.appendChild(gTop);

    var mq = window.matchMedia('(max-width: 820px)');
    function fit() { svg.setAttribute('viewBox', mq.matches ? '296 106 608 608' : '0 0 900 720'); }
    fit();
    if (mq.addEventListener) mq.addEventListener('change', fit); else mq.addListener(fit);

    var armed = !calm && 'IntersectionObserver' in window, doneTimer;
    if (armed) {
      root.classList.add('is-ready');
      new IntersectionObserver(function (entries) {
        var e = entries[entries.length - 1];
        if (e.intersectionRatio >= 0.3 && !root.classList.contains('is-in')) {
          root.classList.add('is-in');
          doneTimer = setTimeout(function () { root.classList.add('is-done'); }, 3200);
        } else if (!e.isIntersecting) {
          clearTimeout(doneTimer);
          setActive(-1);
          root.classList.remove('is-in', 'is-done');
        }
      }, { threshold: [0, 0.3] }).observe(root);
    }

    var parts = Array.prototype.slice.call(root.querySelectorAll('[data-i]'));
    var pinned = -1, lastPointer = 'mouse';
    function setActive(i) {
      root.classList.toggle('has-active', i >= 0);
      parts.forEach(function (n) { n.classList.toggle('is-active', +n.getAttribute('data-i') === i); });
    }
    function live() { return !armed || root.classList.contains('is-done'); }
    root.addEventListener('pointerdown', function (e) { lastPointer = e.pointerType; }, true);
    root.querySelectorAll('.wm-step, .wm-ring').forEach(function (n) {
      var i = +n.getAttribute('data-i');
      n.addEventListener('pointerenter', function (e) { if (e.pointerType !== 'touch' && live()) setActive(i); });
      n.addEventListener('pointerleave', function (e) { if (e.pointerType !== 'touch') setActive(pinned); });
      n.addEventListener('click', function (e) {
        if (lastPointer !== 'touch' || !live()) return;
        e.stopPropagation();
        pinned = pinned === i ? -1 : i;
        setActive(pinned);
      });
    });
    document.addEventListener('click', function () { if (pinned >= 0) { pinned = -1; setActive(-1); } });
  });
})();

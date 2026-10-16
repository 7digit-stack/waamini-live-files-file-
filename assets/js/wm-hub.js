/* WAAMINI broadcast hub: pick a feature on the left, its live card appears under the hub on the right.
   - Auto-cycles through the features until the visitor clicks one.
   - Plays the entrance once, when the section scrolls into view.
   - Without JavaScript the first feature is shown as a normal static layout.            */
(function () {
  var NAMES = ['Priya', 'Arun', 'Meena', 'Karthik'];   // names shown in the Personalisation demo
  var AUTO_MS = 6500;

  var root = document.querySelector('.wmh');
  if (!root) return;
  var items = Array.prototype.slice.call(root.querySelectorAll('.wmh-item'));
  var cards = Array.prototype.slice.call(root.querySelectorAll('.wmh-card'));
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var current = 0, timer = null, started = false, typeTimer = null;

  /* ---- campaign report numbers count up when that card is shown ---- */
  function countUp(card) {
    Array.prototype.forEach.call(card.querySelectorAll('[data-count]'), function (el) {
      var end = parseFloat(el.getAttribute('data-count')), t0 = performance.now(), dur = 1200;
      (function tick(now) {
        var p = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - p, 3);
        el.textContent = (end * e).toFixed(1) + 'K';
        if (p < 1) requestAnimationFrame(tick);
      })(t0);
    });
  }

  /* ---- personalisation demo: {First Name} is replaced by a real name ---- */
  function personalise(card) {
    var tok = card.querySelector('.wmh-tok'); if (!tok) return;
    var token = tok.getAttribute('data-token'), i = 0;
    clearTimeout(typeTimer);
    function show() {
      var name = NAMES[i++ % NAMES.length], n = 0;
      tok.classList.add('is-real'); tok.textContent = '';
      (function typeChar() {
        tok.textContent = name.slice(0, ++n);
        if (n < name.length) typeTimer = setTimeout(typeChar, 70);
        else typeTimer = setTimeout(function () { tok.classList.remove('is-real'); tok.textContent = token; typeTimer = setTimeout(show, 1200); }, 1800);
      })();
    }
    typeTimer = setTimeout(show, 700);
  }

  function activate(i) {
    current = i;
    items.forEach(function (b, k) { b.classList.toggle('is-active', k === i); b.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
    cards.forEach(function (c, k) { c.classList.toggle('is-active', k === i); });
    var card = cards[i];
    clearTimeout(typeTimer);
    var tok = root.querySelector('.wmh-tok');
    if (tok) { tok.classList.remove('is-real'); tok.textContent = tok.getAttribute('data-token'); }
    if (card.getAttribute('data-card') === '4') countUp(card);
    if (card.getAttribute('data-card') === '1') personalise(card);
  }

  function auto() {
    clearInterval(timer);
    if (reduce) return;
    timer = setInterval(function () { activate((current + 1) % items.length); }, AUTO_MS);
  }

  items.forEach(function (b, k) {
    b.addEventListener('click', function () { activate(k); auto(); });
  });
  var viz = root.querySelector('.wmh-right');
  if (viz) {
    viz.addEventListener('mouseenter', function () { clearInterval(timer); });
    viz.addEventListener('mouseleave', auto);
  }

  function start() {
    if (started) return; started = true;
    root.classList.add('is-in');
    activate(0);
    auto();
  }

  if (!reduce) root.classList.add('wmh-js');
  if (reduce || !('IntersectionObserver' in window)) { start(); return; }
  var io = new IntersectionObserver(function (entries) {
    if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); start(); }
  }, { threshold: 0.15 });
  io.observe(root);
})();

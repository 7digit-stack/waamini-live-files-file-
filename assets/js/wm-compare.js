/* WAAMINI comparison table: section-by-section typing animation.
   Starts once, when the section scrolls into view. Without JavaScript, or with
   "reduce motion" on, the full table is simply shown (text is always in the HTML). */
(function () {
  var SPEED = 1;            // 1 = normal, 2 = twice as fast, 0.5 = half speed
  var CHAR_MS = 11;         // default typing time per character
  var START_AT = 0.2;       // start when 20% of the section is visible

  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms / SPEED); }); }

  function type(el, msPerChar) {
    return new Promise(function (resolve) {
      var original = el.innerHTML, spans = [];
      var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT), nodes = [], n;
      while ((n = walker.nextNode())) nodes.push(n);
      nodes.forEach(function (node) {
        var frag = document.createDocumentFragment();
        Array.from(node.nodeValue.replace(/\s+/g, ' ')).forEach(function (ch) {
          var s = document.createElement('span');
          s.className = 'wm-ch'; s.textContent = ch;
          frag.appendChild(s); spans.push(s);
        });
        node.parentNode.replaceChild(frag, node);
      });
      el.classList.add('is-on');

      var shown = 0, t0 = performance.now(), step = msPerChar / SPEED;
      function frame(now) {
        var target = Math.min(spans.length, Math.floor((now - t0) / step) + 1);
        while (shown < target) {
          if (shown) spans[shown - 1].classList.remove('is-caret');
          spans[shown].classList.add('is-on', 'is-caret');
          shown++;
        }
        if (shown < spans.length) requestAnimationFrame(frame);
        else setTimeout(function () { el.innerHTML = original; resolve(); }, 60 / SPEED);
      }
      requestAnimationFrame(frame);
    });
  }

  function play(root) {
    var items = Array.prototype.slice.call(root.querySelectorAll('[data-wm]'));
    var activeRow = null;
    function setRow(row) {
      if (row === activeRow) return;
      if (activeRow) activeRow.classList.remove('is-typing');
      if (row) row.classList.add('is-typing');
      activeRow = row;
    }
    return items.reduce(function (chain, el) {
      return chain.then(function () {
        var kind = el.getAttribute('data-wm');
        setRow(el.closest('tbody tr'));
        if (kind === 'type') return type(el, Number(el.getAttribute('data-wm-speed')) || CHAR_MS);
        el.classList.add('is-on');
        return sleep(kind === 'draw' ? 750 : kind === 'fade' ? 200 : 50);
      });
    }, Promise.resolve()).then(function () {
      setRow(null);
      root.classList.remove('wm-anim');
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.wm-compare'), function (root) {
    root.classList.add('wm-anim');
    if (!('IntersectionObserver' in window)) { play(root); return; }
    var io = new IntersectionObserver(function (entries) {
      if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); play(root); }
    }, { threshold: START_AT });
    io.observe(root);
  });
})();

/* WAAMINI donut chart (3D by default, { flat: true } for a classic 2D donut). Plain canvas, no dependencies.
 *   var d = Donut3D(canvas, { onHover: function (index) {} });
 *   d.set([{ label, value, color }], { total: 120, caption: 'new leads', unit: '' });
 *   d.highlight(index | -1)            // from the legend
 * The ring is drawn as a tilted ellipse with a stack of darker copies underneath (the side wall), a soft shadow,
 * a gloss on top and percentage labels. The hovered slice is pulled out with a glow, like the reference design. */
(function (global) {
  'use strict';
  var TAU = Math.PI * 2, START = -Math.PI / 2;

  function shade(hex, f) {   // f < 1 darkens, f > 1 lightens
    var n = parseInt(hex.slice(1), 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255;
    var m = function (v) { return Math.max(0, Math.min(255, Math.round(f < 1 ? v * f : v + (255 - v) * (f - 1)))); };
    return 'rgb(' + m(r) + ',' + m(g) + ',' + m(b) + ')';
  }
  function ease(t) { return 1 - Math.pow(1 - t, 3); }

  function Donut3D(canvas, opts) {
    opts = opts || {};
    var ctx = canvas.getContext('2d'), tip = null;
    var S = { center: null, data: [], total: 0, caption: '', hover: -1, pop: [], sweep: 0, raf: 0, w: 0, h: 0, dpr: 1, geo: null, reduced: global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches };

    function mkTip() {
      if (tip) return tip;
      tip = document.createElement('div');
      tip.style.cssText = 'position:absolute;z-index:5;pointer-events:none;background:#0F2622;color:#fff;padding:8px 12px;border-radius:8px;font:600 12px Inter,system-ui,sans-serif;opacity:0;transition:opacity .12s;white-space:nowrap;transform:translate(-50%,-120%)';
      canvas.parentNode.style.position = canvas.parentNode.style.position || 'relative';
      canvas.parentNode.appendChild(tip);
      return tip;
    }

    function resize() {
      var r = canvas.getBoundingClientRect();
      S.dpr = global.devicePixelRatio || 1;
      S.w = Math.max(200, r.width); S.h = Math.max(200, r.height);
      canvas.width = S.w * S.dpr; canvas.height = S.h * S.dpr;
      var depth = opts.flat ? 0 : Math.min(34, S.h * 0.11);
      var rx = opts.flat ? Math.min(S.w, S.h) * 0.45 : Math.min(S.w * 0.46, (S.h - depth - 36) * 0.5 / 0.64);
      var ry = opts.flat ? rx : rx * 0.64;
      S.geo = { cx: S.w / 2, cy: (S.h - depth) / 2 + 2, rx: rx, ry: ry, depth: depth, hole: 0.5 };
    }

    function slices() {
      var out = [], a = START, tot = S.data.reduce(function (s, d) { return s + d.value; }, 0);
      S.data.forEach(function (d, i) {
        var frac = tot > 0 ? d.value / tot : 0;
        var span = frac * TAU * S.sweep;
        out.push({ i: i, a0: a, a1: a + span, mid: a + span / 2, frac: frac, color: d.color });
        a += frac * TAU * S.sweep;
      });
      return out;
    }

    function ringPath(g, a0, a1, ox, oy) {
      ctx.beginPath();
      ctx.ellipse(g.cx + ox, g.cy + oy, g.rx, g.ry, 0, a0, a1, false);
      ctx.ellipse(g.cx + ox, g.cy + oy, g.rx * g.hole, g.ry * g.hole, 0, a1, a0, true);
      ctx.closePath();
    }

    function draw() {
      var g = S.geo; if (!g) return;
      ctx.setTransform(S.dpr, 0, 0, S.dpr, 0, 0);
      ctx.clearRect(0, 0, S.w, S.h);
      var sl = slices(), empty = S.data.length === 0 || S.total <= 0;

      // soft shadow on the "floor" (3D only)
      if (!opts.flat) {
      ctx.save();
      ctx.filter = 'blur(14px)'; ctx.fillStyle = 'rgba(15,38,34,.22)';
      ctx.beginPath(); ctx.ellipse(g.cx, g.cy + g.depth + 14, g.rx * 0.92, g.ry * 0.9, 0, 0, TAU); ctx.fill();
      ctx.restore();
      }

      if (empty) {
        for (var k = g.depth; k >= 0; k--) { ringPath(g, 0, TAU, 0, k); ctx.fillStyle = k === 0 ? '#E3EEE9' : '#C9D8D2'; ctx.fill(); }
        return;
      }

      var off = function (s) { var p = S.pop[s.i] || 0; return { x: Math.cos(s.mid) * p * 12, y: Math.sin(s.mid) * p * 12 * (g.ry / g.rx) - (opts.flat ? 0 : p * 2) }; };

      // side wall: stacked darker copies; slices that are pulled out are drawn last so they overlap their neighbours
      var order = sl.slice().sort(function (a, b) { return (S.pop[a.i] || 0) - (S.pop[b.i] || 0); });
      order.forEach(function (s) {
        if (s.a1 - s.a0 <= 0.0001) return;
        var o = off(s);
        for (var k = g.depth; k >= 1; k--) { ringPath(g, s.a0, s.a1, o.x, o.y + k); ctx.fillStyle = shade(s.color, 0.58); ctx.fill(); }
      });
      // top faces
      order.forEach(function (s) {
        if (s.a1 - s.a0 <= 0.0001) return;
        var o = off(s), p = S.pop[s.i] || 0;
        ctx.save();
        if (p > 0.02) { ctx.shadowColor = s.color; ctx.shadowBlur = (opts.flat ? 10 : 18) * p; ctx.shadowOffsetY = (opts.flat ? 0 : 4 * p); }
        ringPath(g, s.a0, s.a1, o.x, o.y);
        if (opts.flat) { ctx.fillStyle = s.color; }
        else {
          var gr = ctx.createLinearGradient(0, g.cy - g.ry, 0, g.cy + g.ry);
          gr.addColorStop(0, shade(s.color, 1.22)); gr.addColorStop(0.55, s.color); gr.addColorStop(1, shade(s.color, 0.86));
          ctx.fillStyle = gr;
        }
        ctx.fill();
        ctx.shadowColor = 'transparent'; ctx.lineWidth = 2; ctx.strokeStyle = 'rgba(255,255,255,.95)'; ctx.stroke();
        ctx.restore();
      });
      // centre text (inside the hole)
      if (S.center) {
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillStyle = '#0F2622'; ctx.font = '800 ' + Math.max(18, Math.round(g.rx * 0.2)) + 'px Inter, system-ui, sans-serif';
        ctx.fillText(S.center.value, g.cx, g.cy - g.ry * 0.07);
        ctx.fillStyle = '#586a63'; ctx.font = '600 ' + Math.max(11, Math.round(g.rx * 0.07)) + 'px Inter, system-ui, sans-serif';
        ctx.fillText(S.center.caption, g.cx, g.cy + g.ry * 0.2);
      }
      // percentage labels
      ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.font = '700 ' + Math.max(12, Math.round(g.rx * 0.085)) + 'px Inter, system-ui, sans-serif';
      ctx.shadowColor = 'rgba(0,0,0,.35)'; ctx.shadowBlur = 3;
      sl.forEach(function (s) {
        if (s.frac < 0.06 || S.sweep < 0.95) return;
        var o = off(s), rr = (1 + g.hole) / 2;
        ctx.fillText((Math.round(s.frac * 1000) / 10) + '%', g.cx + o.x + Math.cos(s.mid) * g.rx * rr, g.cy + o.y + Math.sin(s.mid) * g.ry * rr);
      });
      ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0;
    }

    function animate(done) {
      cancelAnimationFrame(S.raf);
      var t0 = null, dur = S.reduced ? 1 : 700;
      (function step(ts) {
        if (t0 === null) t0 = ts;
        var t = Math.min(1, (ts - t0) / dur); S.sweep = ease(t); draw();
        if (t < 1) S.raf = requestAnimationFrame(step); else if (done) done();
      })(performance.now());
    }

    function tween() {   // ease the pop-out of the hovered slice
      cancelAnimationFrame(S.raf);
      (function step() {
        var again = false;
        S.data.forEach(function (d, i) {
          var target = i === S.hover ? 1 : 0, cur = S.pop[i] || 0, nx = cur + (target - cur) * 0.28;
          if (Math.abs(target - nx) < 0.01) nx = target; else again = true;
          S.pop[i] = nx;
        });
        draw(); if (again) S.raf = requestAnimationFrame(step);
      })();
    }

    function pick(ev) {
      var g = S.geo, r = canvas.getBoundingClientRect(), x = ev.clientX - r.left, y = ev.clientY - r.top;
      var found = -1;
      for (var k = 0; k <= g.depth && found < 0; k += 4) {   // the face, then the wall below it
        var dx = (x - g.cx) / g.rx, dy = (y - k - g.cy) / g.ry, d = Math.sqrt(dx * dx + dy * dy);
        if (d <= 1.04 && d >= g.hole - 0.02) {
          var a = Math.atan2(dy, dx); if (a < START) a += TAU;
          slices().forEach(function (s) { if (a >= s.a0 && a < s.a1) found = s.i; });
        }
      }
      return found;
    }

    canvas.addEventListener('pointermove', function (ev) {
      var i = pick(ev); if (i !== S.hover) { S.hover = i; tween(); if (opts.onHover) opts.onHover(i); }
      var t = mkTip();
      if (i >= 0) {
        var r = canvas.getBoundingClientRect(), d = S.data[i], pr = canvas.parentNode.getBoundingClientRect();
        t.textContent = d.label + ': ' + d.value.toLocaleString('en-IN') + ' (' + Math.round(d.value / S.total * 1000) / 10 + '%)';
        t.style.left = (ev.clientX - pr.left) + 'px'; t.style.top = (ev.clientY - pr.top) + 'px'; t.style.opacity = 1;
      } else t.style.opacity = 0;
    });
    canvas.addEventListener('pointerleave', function () { if (S.hover !== -1) { S.hover = -1; tween(); if (opts.onHover) opts.onHover(-1); } if (tip) tip.style.opacity = 0; });
    global.addEventListener('resize', function () { resize(); draw(); });

    return {
      set: function (data, meta) {
        meta = meta || {};
        S.data = data.filter(function (d) { return d.value > 0; });
        S.total = S.data.reduce(function (s, d) { return s + d.value; }, 0);
        S.center = meta.center || null;
        S.pop = []; S.hover = -1; resize(); animate();
      },
      highlight: function (i) { if (i !== S.hover) { S.hover = i; tween(); } },
      redraw: function () { resize(); draw(); }
    };
  }
  global.Donut3D = Donut3D;
})(window);

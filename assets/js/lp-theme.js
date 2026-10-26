/* =========================================================================
   Hero typewriter (7digit hero pattern). Plain vanilla JS, no GSAP/library
   dependency — only runs on pages that actually have #hero-typed (index.php),
   no-ops everywhere else.
   ========================================================================= */
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('hero-typed');
    if (!el) return;
    // The words come from the Landing CMS (data-words); the list below is the fallback
    var phrases = ['WhatsApp Automation', 'WhatsApp API', 'WhatsApp CRM', 'WhatsApp Bot'];
    try {
        var cms = JSON.parse(el.getAttribute('data-words') || '[]');
        if (Array.isArray(cms) && cms.length) phrases = cms.map(String);
    } catch (e) {}
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        el.textContent = phrases[0];
        return;
    }

    var pi = 0, ci = 0, deleting = false;
    var speed = { type: 55, delete: 30, pause: 1400 };

    function tick() {
        var word = phrases[pi];
        el.textContent = deleting ? word.slice(0, ci--) : word.slice(0, ci++);

        if (!deleting && ci > word.length) {
            deleting = true;
            setTimeout(tick, speed.pause);
            return;
        }
        if (deleting && ci < 0) {
            deleting = false;
            ci = 0;
            pi = (pi + 1) % phrases.length;
        }
        setTimeout(tick, deleting ? speed.delete : speed.type);
    }
    setTimeout(tick, 800);
});

/* =========================================================================
   Full-screen numbered nav overlay (7digit nav pattern). Pure CSS
   transitions + vanilla JS, zero dependency on GSAP/Lenis — runs in its
   own listener so the menu still opens/closes even if the CDN scripts
   below fail to load or the visitor has prefers-reduced-motion set.
   ========================================================================= */
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('waNavToggle');
    var overlay = document.getElementById('waNavOverlay');
    if (!toggle || !overlay) return;

    var isOpen = false;
    var scrollY = 0;

    function openMenu() {
        isOpen = true;
        scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top = '-' + scrollY + 'px';
        document.body.style.width = '100%';
        toggle.classList.add('wa-menu-open');
        toggle.setAttribute('aria-expanded', 'true');
        overlay.classList.add('wa-menu-is-open');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeMenu() {
        isOpen = false;
        toggle.classList.remove('wa-menu-open');
        toggle.setAttribute('aria-expanded', 'false');
        overlay.classList.remove('wa-menu-is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollY);
    }

    toggle.addEventListener('click', function () { isOpen ? closeMenu() : openMenu(); });
    toggle.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); isOpen ? closeMenu() : openMenu(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) closeMenu();
    });

    // Any click inside the overlay (nav item or footer link) closes it
    // immediately rather than waiting on the close transition, so the
    // destination page isn't hidden behind a still-animating panel.
    overlay.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () { if (isOpen) closeMenu(); });
    });

    // Any other link on the page (in-page anchors, buttons outside the
    // menu) also force-closes the menu first, in case it was left open.
    document.querySelectorAll('a:not(#waNavOverlay a)').forEach(function (link) {
        link.addEventListener('click', function () { if (isOpen) closeMenu(); });
    });
});

/* =========================================================================
   7digit-system port: Lenis smooth scroll + GSAP ScrollTrigger reveal +
   pointer:fine cursor/magnetic-hover. Shared across every body.lp-neo page
   (index, features, pricing, support, blogs, the 4 industry pages, legal)
   so the scroll/animation feel is identical everywhere instead of drifting
   per-page. No WebGL/canvas — depth comes from fluid type + glass surfaces
   + CSS transforms only, matching the reference site's actual architecture.
   ========================================================================= */
document.addEventListener('DOMContentLoaded', function () {
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var hasGsapStack = !!(window.gsap && window.ScrollTrigger);
    var lenis = null;

    if (!hasGsapStack || reduceMotion) return; // CSS default state is fully visible — nothing to fix up

    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.normalizeScroll(true);
    ScrollTrigger.config({ ignoreMobileResize: true });

    // Only once GSAP+ScrollTrigger are confirmed present do we let CSS hide
    // the reveal targets (see .lp-anim-ready rules in landing-neo.css) —
    // if the CDN scripts fail to load, this line never runs and content
    // simply stays at its default visible state instead of vanishing.
    document.body.classList.add('lp-anim-ready');

    /* ── Canonical Lenis + GSAP ticker sync ── */
    function buildLenis() {
        var isMobile = window.innerWidth <= 900;
        var l = new Lenis({
            duration: isMobile ? 1 : 1.2,
            easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
            lerp: isMobile ? 0.15 : 0.1,
            wheelMultiplier: isMobile ? 1.5 : 1,
            smoothTouch: isMobile,
            touchMultiplier: 2,
            syncTouch: true,
            smoothWheel: true
        });
        l.on('scroll', ScrollTrigger.update);
        return l;
    }
    lenis = buildLenis();
    gsap.ticker.add(function (time) { lenis.raf(time * 1000); });
    gsap.ticker.lagSmoothing(0);

    // Lenis intercepts wheel/touch but not native hash-link jumps — route
    // in-page anchors (#pricing, #faq, ...) through it so its internal
    // scroll state never goes stale.
    document.querySelectorAll('a[href^="#"]:not([href="#"])').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var target = document.getElementById(a.getAttribute('href').slice(1));
            if (!target) return;
            e.preventDefault();
            lenis.scrollTo(target, { offset: -80, duration: 1.2 });
        });
    });

    // Destroy + rebuild (not mutate) on a mobile/desktop breakpoint cross,
    // matching the reference site's exact pattern.
    var wasMobile = window.innerWidth <= 900;
    function checkLenisBreakpoint() {
        var isMobile = window.innerWidth <= 900;
        if (isMobile !== wasMobile) {
            wasMobile = isMobile;
            lenis.destroy();
            lenis = buildLenis();
            ScrollTrigger.refresh();
        }
    }

    /* ── Reveal architecture: CSS sets the hidden state (.lp-anim-ready
       rules), JS only ever animates *to* visible. Forks into two full code
       paths at the 1000px breakpoint rather than trying to make one
       timeline responsive via media queries. Selector list covers every
       card-like component across all body.lp-neo pages, not just index. ── */
    var revealSelectors = [
        // index.php
        '.feat-card-modern', '.ps-col', '.cust-card', '.cert-card', '.sync-kpi',
        '.vs-card', '.hiw-item', '.trust-stat', '.ai-showcase-card',
        '.auto-visual-card', '.campaign-preview', '.ai-chat-preview', '.invoice-showcase',
        // shared across index + pricing
        '.faq-item', '.price-card', '.pricing-card',
        // features.php
        '.f-list-card', '.bb-step', '.api-table-wrapper',
        // pricing.php
        '.wa-conv-type', '.wa-mp-tip-card',
        // support.php
        '.contact-card', '.sp-way', '.sp-q', '.sp-form', '.sp-visit',
        // blogs.php
        '.blog-card',
        // ecommerce / education / healthcare / real-estate
        '.ind-feat-card', '.ind-step-card', '.ind-mockup', '.ind-cta-card',
        // legal pages
        '.legal-card'
    ];

    function initAnimations() {
        ScrollTrigger.getAll().forEach(function (st) { st.kill(); });

        if (window.innerWidth <= 1000) {
            // Mobile/tablet: cheap, one-shot reveal per element — no masked
            // headline wipe, no stagger cascade.
            document.querySelectorAll(revealSelectors.join(',') + ', .section-header, .title-wrap').forEach(function (el) {
                gsap.set(el, { opacity: 1, y: 0 });
                ScrollTrigger.create({
                    trigger: el, start: 'top 92%', once: true,
                    onEnter: function () { gsap.from(el, { opacity: 0, y: 30, duration: 0.6, ease: 'power3.out' }); }
                });
            });
            return;
        }

        // Desktop: masked headline reveal + independent label/title/item
        // ScrollTriggers per section (three separate triggers, not one
        // master timeline — each element reveals relative to its own
        // scroll position rather than a shared parent's).
        gsap.utils.toArray('.title-wrap').forEach(function (wrap) {
            var heading = wrap.querySelector('h1, h2');
            if (!heading) return;
            gsap.fromTo(heading, { y: '105%' }, {
                y: '0%', duration: 1, ease: 'power4.out',
                scrollTrigger: { trigger: wrap, start: 'top 88%' }
            });
        });

        gsap.utils.toArray('.section-header .hero-badge, .section-header .pulse-dot, .badge-label').forEach(function (el) {
            gsap.fromTo(el, { opacity: 0, y: 20 }, {
                opacity: 1, y: 0, duration: 0.6,
                scrollTrigger: { trigger: el, start: 'top 90%' }
            });
        });

        document.querySelectorAll(revealSelectors.join(',')).forEach(function (el, i) {
            gsap.fromTo(el, { opacity: 0, y: 34 }, {
                opacity: 1, y: 0, duration: 0.65, ease: 'power3.out', delay: (i % 4) * 0.1,
                scrollTrigger: { trigger: el, start: 'top 88%' }
            });
        });
    }
    initAnimations();

    /* Re-sync trigger positions once layout has actually settled. Pages
       load a web font async and some load images after first paint — both
       reflow height *after* triggers were measured, so anything below that
       point can arm against stale coordinates. */
    var refreshDebounce;
    function refreshST() {
        clearTimeout(refreshDebounce);
        refreshDebounce = setTimeout(function () { ScrollTrigger.refresh(); }, 120);
    }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(refreshST);
    window.addEventListener('load', refreshST);
    document.querySelectorAll('.sync-right img, .hero-mockup img, .ind-mockup img').forEach(function (img) {
        if (img.complete) return;
        img.addEventListener('load', refreshST, { once: true });
    });

    // Debounced resize: rebuild animations + Lenis rather than patching
    // live triggers, matching the reference site's exact pattern.
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            checkLenisBreakpoint();
            initAnimations();
            ScrollTrigger.refresh();
        }, 200);
    });

    /* ── pointer:fine-gated effects: magnetic hover on buttons. Touch devices never
       receive this pointer-only JS. (The custom circle cursor was removed: the site uses
       the normal system pointer.) ── */
    var mm = gsap.matchMedia();
    mm.add('(pointer: fine)', function () {
        // Magnetic hover on buttons — pulls toward the cursor at a small
        // fraction of the offset, elastic snap-back on release.
        var magneticSelectors = [
            '.btn-primary-glow', '.btn-buy', '.it-explore-btn', '.ai-sc-cta',
            '.btn-cta', '.plan-cta', '.submit-btn', '.ind-btn-primary', '.btn-delete-request', '.sp-send', '.sp-dir'
        ];
        document.querySelectorAll(magneticSelectors.join(',')).forEach(function (el) {
            el.addEventListener('mousemove', function (e) {
                var r = el.getBoundingClientRect();
                var dx = (e.clientX - (r.left + r.width / 2)) * 0.25;
                var dy = (e.clientY - (r.top + r.height / 2)) * 0.25;
                gsap.to(el, { x: dx, y: dy, duration: 0.4, ease: 'power2.out' });
            });
            el.addEventListener('mouseleave', function () {
                gsap.to(el, { x: 0, y: 0, duration: 0.6, ease: 'elastic.out(1, 0.4)' });
            });
        });
    });
});

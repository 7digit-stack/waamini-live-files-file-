<style>
/* Site footer — dark rounded card with green column headings (WATI-style layout, WAAMINI palette) */
.wf{padding:40px 16px 24px;background:transparent;font-family:'Inter',system-ui,sans-serif}
.wf-card{max-width:1280px;margin:0 auto;background:#08110F;border-radius:32px;padding:56px 56px 28px;color:#fff;position:relative;overflow:hidden}
.wf-card::before{content:'';position:absolute;top:-160px;right:-120px;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(37,211,102,.16),transparent 70%);pointer-events:none}
.wf-top{position:relative;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:24px;padding-bottom:36px;margin-bottom:40px;border-bottom:1px solid rgba(255,255,255,.1)}
.wf-brand{display:flex;flex-direction:column;gap:12px;max-width:420px}
.wf-logo{display:inline-flex;align-items:center;gap:10px;text-decoration:none}
.wf-logo-img{display:block;height:40px;width:auto}
.wf-logo-ic{width:40px;height:40px;border-radius:12px;background:#128C7E;display:inline-flex;align-items:center;justify-content:center}
.wf-logo-word{font-family:'Exo 2','Inter',sans-serif;font-style:italic;font-weight:800;text-transform:uppercase;font-size:26px;letter-spacing:.4px;color:#fff;line-height:1}
.wf-tag{margin:0;font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.66)}
.wf-cta{display:inline-flex;align-items:center;gap:10px;padding:15px 28px;border-radius:999px;background:#25D366;color:#06201B;font-weight:700;font-size:15px;text-decoration:none;transition:transform .2s,box-shadow .2s,background .2s}
.wf-cta:hover{background:#3be07a;color:#06201B;transform:translateY(-2px);box-shadow:0 14px 30px -12px rgba(37,211,102,.6)}
.wf-grid{position:relative;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:34px 28px}
.wf-col h4{margin:0 0 20px;font-size:16px;font-weight:600;color:#25D366;letter-spacing:.1px}
.wf-col ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:13px}
.wf-col a{color:rgba(255,255,255,.92);text-decoration:none;font-size:15px;line-height:1.35;transition:color .15s}
.wf-col a:hover{color:#25D366}
.wf-col p{margin:0 0 10px;font-size:14px;line-height:1.55;color:rgba(255,255,255,.66)}
.wf-mail{display:inline-block;margin-top:2px;color:#fff!important;font-weight:600;font-size:14.5px;word-break:break-all}
.wf-bottom{position:relative;display:flex;flex-wrap:wrap;gap:14px 24px;align-items:center;justify-content:space-between;margin-top:44px;padding-top:22px;border-top:1px solid rgba(255,255,255,.1);font-size:13.5px;color:rgba(255,255,255,.6)}
.wf-bottom b{color:#25D366;font-weight:700}
.wf-legal{display:flex;flex-wrap:wrap;gap:18px}
.wf-legal a{color:rgba(255,255,255,.7);text-decoration:none}
.wf-legal a:hover{color:#25D366}
@media (max-width:1100px){.wf-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:720px){
  .wf-card{padding:38px 24px 24px;border-radius:24px}
  .wf-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .wf-cta{width:100%;justify-content:center}
}
@media (max-width:420px){.wf-grid{grid-template-columns:1fr}}
</style>

<?php $__wfFeatures = is_file(__DIR__ . '/feature_pages_registry.php') ? (require __DIR__ . '/feature_pages_registry.php') : []; ?>
<footer class="wf">
    <div class="wf-card">
        <div class="wf-top">
            <div class="wf-brand">
                <a href="index.php" class="wf-logo" aria-label="Waamini Home">
                    <img src="assets/img/waamini-logo.png" alt="Waamini" class="wf-logo-img" width="205" height="40">
                </a>
                <p class="wf-tag">India's WhatsApp CRM and automation platform. Automate replies, run campaigns and close more deals from one dashboard.</p>
            </div>
            <a href="register.php" class="wf-cta">Start free trial
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
        </div>

        <div class="wf-grid">
            <div class="wf-col">
                <h4>Solutions</h4>
                <ul>
                    <li><a href="ecommerce.php">E-commerce</a></li>
                    <li><a href="real-estate.php">Real Estate</a></li>
                    <li><a href="healthcare.php">Healthcare</a></li>
                    <li><a href="education.php">Education</a></li>
                </ul>
            </div>
            <div class="wf-col">
                <h4>Features</h4>
                <ul>
                    <?php foreach ($__wfFeatures as $__slug => $__label): ?>
                    <li><a href="<?php echo htmlspecialchars($__slug, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($__label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="wf-col">
                <h4>Product</h4>
                <ul>
                    <li><a href="features.php">All Features</a></li>
                    <li><a href="pricing.php">Pricing Plans</a></li>
                    <li><a href="register.php">Create Account</a></li>
                    <li><a href="login.php">Login</a></li>
                </ul>
            </div>
            <div class="wf-col">
                <h4>Resources</h4>
                <ul>
                    <li><a href="blogs.php">Official Blog</a></li>
                    <li><a href="support.php">Support Center</a></li>
                    <li><a href="support.php#faq">FAQs</a></li>
                    <li><a href="support.php">Book a Demo</a></li>
                </ul>
            </div>
            <div class="wf-col">
                <h4>Legal</h4>
                <ul>
                    <li><a href="privacy-policy.php">Privacy Policy</a></li>
                    <li><a href="terms-of-service.php">Terms of Service</a></li>
                    <li><a href="data-deletion.php">Data Deletion</a></li>
                </ul>
            </div>
            <div class="wf-col">
                <h4>Contact</h4>
                <p>Questions about your account or a setup? Our team is here to help.</p>
                <?php $cmsMailF = function_exists('cms_setting') ? cms_setting('support_email', 'support@waamini.com') : 'support@waamini.com'; ?>
                <a href="mailto:<?php echo htmlspecialchars($cmsMailF, ENT_QUOTES, 'UTF-8'); ?>" class="wf-mail"><?php echo htmlspecialchars($cmsMailF, ENT_QUOTES, 'UTF-8'); ?></a>
                <?php if (function_exists('cms_setting')): foreach (['linkedin_url' => 'LinkedIn', 'instagram_url' => 'Instagram', 'twitter_handle' => 'X (Twitter)'] as $__k => $__label): $__u = cms_setting($__k); if ($__u === '') continue; if ($__k === 'twitter_handle') { $__u = 'https://x.com/' . ltrim($__u, '@'); } ?>
                <a href="<?php echo htmlspecialchars($__u, ENT_QUOTES, 'UTF-8'); ?>" class="wf-mail" target="_blank" rel="noopener noreferrer" style="display:block;margin-top:6px"><?php echo htmlspecialchars($__label); ?></a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="wf-bottom">
            <div><?php
                $__ft = function_exists('cms_setting') ? cms_setting('footer_text') : '';
                if ($__ft !== '') { echo str_replace('♥', '<span style="color:#ef4444">♥</span>', cms_inline($__ft)); }
                else { ?>© 2026 <b>WAAMINI</b> by 7digit · Made with <span style="color:#ef4444">♥</span> in India<?php } ?></div>
            <div class="wf-legal">
                <a href="privacy-policy.php">Privacy</a>
                <a href="terms-of-service.php">Terms</a>
                <a href="data-deletion.php">Data Deletion</a>
            </div>
        </div>
    </div>
</footer>

<?php include_once __DIR__ . '/offer_counter.php'; ?>

<!-- CHAT ANIMATION SCRIPT -->
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Delay non-critical animations until full load (improves LCP)
window.addEventListener('load', function() {
    // Global marker for "enable premium effects now"
    document.documentElement.classList.add('fx-ready');

    // 1. CHAT PREVIEW ANIMATION (Industry Pages)
    const chatObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const messages = entry.target.querySelectorAll('.preview-msg');
                messages.forEach((msg, index) => {
                    setTimeout(() => {
                        msg.classList.add('revealed');
                    }, index * 1000);
                });
                chatObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.chat-preview-container').forEach(container => {
        chatObserver.observe(container);
    });

    // 2. BOT TIMELINE ANIMATION (Features & Index)
    const timelineObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const steps = entry.target.querySelectorAll('.timeline-step');
                steps.forEach((step, index) => {
                    setTimeout(() => {
                        step.classList.add('visible');
                    }, index * 800);
                });
                timelineObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.bot-timeline').forEach(timeline => {
        timelineObserver.observe(timeline);
    });
});
</script>
</body>
</html>

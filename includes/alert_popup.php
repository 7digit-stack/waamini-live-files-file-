<?php
/**
 * Alert Popup: modal announcements for the landing pages, managed in the Landing CMS (Alert Popup).
 * Every active popup is shown one after another (max 3, newest first); a popup's frequency rule
 * (once / session / always) decides whether it is skipped for a returning visitor.
 * An optional secondary button ("Later", "Dismiss") closes the popup without following the main button.
 *
 * Include after <body> in header.php.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../db.php';
}

$alertPopups = [];
try {
    $hasDismiss = $conn->query("SHOW COLUMNS FROM lp_alert_popups LIKE 'dismiss_text'");
    if ($hasDismiss && $hasDismiss->num_rows === 0) {
        $conn->query("ALTER TABLE lp_alert_popups ADD COLUMN dismiss_text VARCHAR(60) NOT NULL DEFAULT ''");
    }
    $res = $conn->query("SELECT id, title, message, image_url, btn_text, btn_url, dismiss_text, bg_color, text_color, accent_color, show_delay, show_frequency, is_dismissible
                         FROM lp_alert_popups WHERE is_active = 1 ORDER BY id DESC LIMIT 12");
    $seen = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $sig = md5(trim((string)$row['title']) . '|' . trim((string)$row['message']));   // identical copies show once
        if (isset($seen[$sig])) continue;
        $seen[$sig] = true;
        $alertPopups[] = $row;
        if (count($alertPopups) >= 3) break;
    }
} catch (Throwable $e) {
    $alertPopups = [];
}

if (!$alertPopups) return;

$__h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$__queue = [];
?>
<style>
.wa-popup-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); animation: waPopFadeIn 0.3s ease; }
.wa-popup-overlay.active { display: flex; }
.wa-popup-modal { border-radius: 20px; max-width: 440px; width: 92%; max-height: 90vh; overflow-y: auto; box-shadow: 0 16px 48px rgba(0,0,0,0.25); padding: 32px 28px 28px; position: relative; text-align: center; animation: waPopSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.wa-popup-close { position: absolute; top: 12px; right: 14px; background: none; border: none; font-size: 18px; line-height: 1; cursor: pointer; opacity: 0.5; transition: opacity 0.2s; color: inherit; padding: 4px 8px; border-radius: 6px; }
.wa-popup-close:hover { opacity: 1; background: rgba(0,0,0,0.06); }
.wa-popup-image { max-width: 100%; height: auto; border-radius: 12px; margin-bottom: 16px; }
.wa-popup-title { font-size: 20px; font-weight: 700; margin: 0 0 10px; line-height: 1.3; color: inherit; }
.wa-popup-message { font-size: 14px; line-height: 1.6; opacity: 0.85; margin: 0 0 20px; }
.wa-popup-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.wa-popup-btn { display: inline-block; padding: 12px 28px; border-radius: 999px; font-weight: 700; font-size: 14px; text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; color: #fff; }
.wa-popup-btn:hover { transform: translateY(-2px); color: #fff; }
.wa-popup-btn-alt { display: inline-block; padding: 12px 24px; border-radius: 999px; font-weight: 600; font-size: 14px; border: 0; cursor: pointer; background: rgba(0,0,0,0.06); color: inherit; font-family: inherit; }
.wa-popup-btn-alt:hover { background: rgba(0,0,0,0.1); }
@keyframes waPopFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes waPopSlideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@media (max-width: 480px) { .wa-popup-modal { padding: 24px 20px; } .wa-popup-title { font-size: 18px; } }
</style>

<?php foreach ($alertPopups as $__p):
    $pid = (int)$__p['id'];
    $bg = $__h($__p['bg_color'] ?: '#ffffff');
    $fg = $__h($__p['text_color'] ?: '#0F2622');
    $accent = $__h($__p['accent_color'] ?: '#128C7E');
    $__queue[] = ['id' => 'waAlertPopup' . $pid, 'cookie' => 'wa_popup_dismissed_' . $pid, 'session' => 'wa_popup_shown_' . $pid,
                  'freq' => (string)($__p['show_frequency'] ?: 'once'), 'delay' => max(0, (int)$__p['show_delay'])];
?>
<div id="waAlertPopup<?php echo $pid; ?>" class="wa-popup-overlay" role="dialog" aria-modal="true" aria-label="<?php echo $__h($__p['title']); ?>">
    <div class="wa-popup-modal" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;">
        <?php if ((int)$__p['is_dismissible']): ?><button type="button" class="wa-popup-close" data-wa-close aria-label="Close">✕</button><?php endif; ?>
        <?php if (!empty($__p['image_url'])): ?><img src="<?php echo $__h($__p['image_url']); ?>" alt="" class="wa-popup-image" loading="lazy"><?php endif; ?>
        <?php if (!empty($__p['title'])): ?><h2 class="wa-popup-title"><?php echo $__h($__p['title']); ?></h2><?php endif; ?>
        <?php if (!empty($__p['message'])): ?><p class="wa-popup-message"><?php echo nl2br($__h($__p['message'])); ?></p><?php endif; ?>
        <div class="wa-popup-actions">
            <?php if (!empty($__p['btn_text']) && !empty($__p['btn_url'])): ?>
            <a href="<?php echo $__h($__p['btn_url']); ?>" class="wa-popup-btn" style="background:<?php echo $accent; ?>;"><?php echo $__h($__p['btn_text']); ?></a>
            <?php endif; ?>
            <?php if (!empty($__p['dismiss_text'])): ?>
            <button type="button" class="wa-popup-btn-alt" data-wa-close><?php echo $__h($__p['dismiss_text']); ?></button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
    var queue = <?php echo json_encode($__queue, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var current = null;

    function skip(p) {
        try {
            if (p.freq === 'once' && document.cookie.indexOf(p.cookie + '=1') !== -1) return true;
            if (p.freq === 'session' && sessionStorage.getItem(p.session)) return true;
        } catch (e) {}
        return false;
    }
    queue = queue.filter(function (p) { return !skip(p); });

    function next() {
        current = null;
        document.body.style.overflow = '';
        var p = queue.shift();
        if (!p) return;
        setTimeout(function () {
            var el = document.getElementById(p.id);
            if (!el) return next();
            current = { p: p, el: el };
            el.classList.add('active');
            document.body.style.overflow = 'hidden';
            try { if (p.freq === 'session') sessionStorage.setItem(p.session, '1'); } catch (e) {}
        }, p.delay * 1000);
    }

    function closeCurrent() {
        if (!current) return;
        if (current.p.cookie) document.cookie = current.p.cookie + '=1; path=/; max-age=' + (86400 * 30) + '; SameSite=Lax';
        current.el.classList.remove('active');
        next();
    }

    document.addEventListener('click', function (e) {
        if (!current) return;
        if (e.target === current.el || (e.target.closest && e.target.closest('[data-wa-close]') && current.el.contains(e.target))) closeCurrent();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeCurrent(); });
    next();
})();
</script>

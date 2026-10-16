<?php
/**
 * Offer Counter — Sticky bottom bar or floating widget
 * Renders active offer counter from lp_offer_counters table.
 * Supports countdown timers and dismiss with cookie.
 *
 * Include BEFORE </body> in header.php (or in footer.php)
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../db.php';
}

$offerCounter = null;
try {
    $st = $conn->prepare("
        SELECT id, title, subtitle, countdown_target, countdown_label,
               bg_color, text_color, accent_color,
               btn_text, btn_url, position, is_sticky, is_dismissible
        FROM lp_offer_counters
        WHERE is_active = 1
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($st) {
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
            $row['countdown_ts'] = $row['countdown_target'] ? strtotime($row['countdown_target']) : 0;
            $offerCounter = $row;
        }
        $st->close();
    }
} catch (Throwable $e) {
    $offerCounter = null;
}

if (!$offerCounter) return;

$bg = htmlspecialchars($offerCounter['bg_color'] ?? '#128C7E', ENT_QUOTES, 'UTF-8');
$fg = htmlspecialchars($offerCounter['text_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8');
$accent = htmlspecialchars($offerCounter['accent_color'] ?? '#25D366', ENT_QUOTES, 'UTF-8');
$title = htmlspecialchars($offerCounter['title'] ?? '', ENT_QUOTES, 'UTF-8');
$subtitle = htmlspecialchars($offerCounter['subtitle'] ?? '', ENT_QUOTES, 'UTF-8');
$btnText = htmlspecialchars($offerCounter['btn_text'] ?? '', ENT_QUOTES, 'UTF-8');
$btnUrl = htmlspecialchars($offerCounter['btn_url'] ?? '', ENT_QUOTES, 'UTF-8');
$cdLabel = htmlspecialchars($offerCounter['countdown_label'] ?? '', ENT_QUOTES, 'UTF-8');
$cdTarget = $offerCounter['countdown_ts'];
$position = $offerCounter['position'] ?? 'bottom';
$isSticky = (int)($offerCounter['is_sticky'] ?? 1);
$isDismissible = (int)($offerCounter['is_dismissible'] ?? 1);
$offerId = (int)$offerCounter['id'];
$dismissCookie = "wa_offer_dismissed_{$offerId}";

$posClass = $position === 'floating' ? 'wa-offer-floating' : 'wa-offer-bottom';
$stickyStyle = $isSticky ? 'position:sticky;bottom:0;z-index:99;' : '';
?>
<style>
.wa-offer-counter {
    width: 100%;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    <?php echo $stickyStyle; ?>
}
.wa-offer-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 12px 20px;
    max-width: 1200px;
    margin: 0 auto;
    flex-wrap: wrap;
    text-align: center;
}
.wa-offer-title { font-weight: 700; font-size: 15px; }
.wa-offer-subtitle { font-size: 13px; opacity: 0.85; }
.wa-offer-countdown {
    font-weight: 600;
    font-size: 13px;
    white-space: nowrap;
}
.wa-offer-cd-display { font-weight: 700; }
.wa-offer-cd-display span { display: inline-block; min-width: 20px; text-align: center; }
.wa-offer-btn {
    display: inline-block;
    padding: 7px 18px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    transition: transform 0.2s, box-shadow 0.2s;
    flex-shrink: 0;
}
.wa-offer-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
.wa-offer-close {
    background: none; border: none; cursor: pointer;
    font-size: 16px; line-height: 1; padding: 2px 6px;
    opacity: 0.7; transition: opacity 0.2s; flex-shrink: 0;
}
.wa-offer-close:hover { opacity: 1; }

/* Floating variant */
.wa-offer-floating {
    position: fixed;
    bottom: 24px;
    right: 24px;
    max-width: 360px;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    z-index: 999;
}
.wa-offer-floating .wa-offer-inner {
    flex-direction: column;
    padding: 20px;
    gap: 10px;
}
.wa-offer-floating .wa-offer-close {
    position: absolute;
    top: 8px;
    right: 10px;
}
@media (max-width: 640px) {
    .wa-offer-floating {
        right: 12px;
        bottom: 12px;
        max-width: calc(100% - 24px);
    }
    .wa-offer-inner { gap: 8px; padding: 10px 14px; font-size: 13px; }
    .wa-offer-countdown { font-size: 12px; }
}
</style>

<div id="waOfferCounter" class="wa-offer-counter <?php echo $posClass; ?>"
     data-cookie="<?php echo $dismissCookie; ?>"
     style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;">
    <div class="wa-offer-inner">
        <span class="wa-offer-title"><?php echo $title; ?></span>
        <?php if ($subtitle): ?>
        <span class="wa-offer-subtitle"><?php echo $subtitle; ?></span>
        <?php endif; ?>
        <?php if ($cdLabel && $cdTarget): ?>
        <span class="wa-offer-countdown" data-target="<?php echo $cdTarget; ?>">
            <?php echo $cdLabel; ?>:
            <span class="wa-offer-cd-display">
                <span class="wa-offer-cd-days">00</span>d
                <span class="wa-offer-cd-hours">00</span>h
                <span class="wa-offer-cd-mins">00</span>m
                <span class="wa-offer-cd-secs">00</span>s
            </span>
        </span>
        <?php endif; ?>
        <?php if ($btnText && $btnUrl): ?>
        <a href="<?php echo $btnUrl; ?>" class="wa-offer-btn" style="background:<?php echo $accent; ?>;color:<?php echo $bg; ?>;"><?php echo $btnText; ?></a>
        <?php endif; ?>
        <?php if ($isDismissible): ?>
        <button class="wa-offer-close" onclick="dismissOfferCounter()" aria-label="Dismiss" style="color:<?php echo $fg; ?>;">✕</button>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function() {
    var bar = document.getElementById('waOfferCounter');
    if (!bar) return;
    var cookieName = bar.getAttribute('data-cookie');
    if (cookieName && document.cookie.indexOf(cookieName + '=1') !== -1) {
        bar.style.display = 'none';
        return;
    }
    var cd = bar.querySelector('.wa-offer-countdown');
    if (cd) {
        var target = parseInt(cd.getAttribute('data-target')) * 1000;
        function update() {
            var diff = Math.max(0, target - Date.now());
            if (diff <= 0) {
                cd.querySelector('.wa-offer-cd-display').textContent = 'Ended';
                return;
            }
            var s = Math.floor(diff / 1000) % 60;
            var m = Math.floor(diff / 60000) % 60;
            var h = Math.floor(diff / 3600000) % 24;
            var d = Math.floor(diff / 86400000);
            cd.querySelector('.wa-offer-cd-days').textContent = String(d).padStart(2, '0');
            cd.querySelector('.wa-offer-cd-hours').textContent = String(h).padStart(2, '0');
            cd.querySelector('.wa-offer-cd-mins').textContent = String(m).padStart(2, '0');
            cd.querySelector('.wa-offer-cd-secs').textContent = String(s).padStart(2, '0');
        }
        update();
        setInterval(update, 1000);
    }
})();

function dismissOfferCounter() {
    var bar = document.getElementById('waOfferCounter');
    if (!bar) return;
    var cookieName = bar.getAttribute('data-cookie');
    if (cookieName) {
        document.cookie = cookieName + '=1; path=/; max-age=' + (86400 * 30) + '; SameSite=Lax';
    }
    bar.style.transform = 'translateY(100%)';
    bar.style.opacity = '0';
    bar.style.maxHeight = '0';
    bar.style.padding = '0';
    bar.style.margin = '0';
    bar.style.border = 'none';
    setTimeout(function() { bar.style.display = 'none'; }, 400);
}
</script>

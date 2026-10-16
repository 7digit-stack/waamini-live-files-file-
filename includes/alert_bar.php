<?php
/**
 * Alert Bar — Top announcement banner for landing pages
 * Renders the active alert from lp_alerts table.
 * Supports countdown timers and dismiss with cookie.
 *
 * Include BEFORE <nav class="main-nav"> in header.php
 */

// Ensure DB connection is available
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../db.php';
}

$alertBar = null;

try {
    $now = date('Y-m-d H:i:s');
    $st = $conn->prepare("
        SELECT id, type, message, link_text, link_url,
               btn_text, btn_url,
               countdown_label, countdown_target,
               bg_color, text_color, is_dismissible
        FROM lp_alerts
        WHERE is_active = 1
          AND (start_date IS NULL OR start_date <= ?)
          AND (end_date IS NULL OR end_date >= ?)
        ORDER BY sort_order ASC, id DESC
        LIMIT 1
    ");
    if ($st) {
        $st->bind_param('ss', $now, $now);
        $st->execute();
        $res = $st->get_result();
        if ($row = $res->fetch_assoc()) {
            $row['countdown_ts'] = $row['countdown_target'] ? strtotime($row['countdown_target']) : 0;
            $alertBar = $row;
        }
        $st->close();
    }
} catch (Throwable $e) {
    // Silently fail — no alert bar if DB or table is missing
    $alertBar = null;
}

if (!$alertBar) return;

$typeIcons = [
    'login' => '👋', 'registration' => '📝', 'offer' => '🎉',
    'info' => '💡', 'warning' => '⚠️', 'announcement' => '📢',
];
$icon = $typeIcons[$alertBar['type']] ?? '💡';

$bg = htmlspecialchars($alertBar['bg_color'] ?? '#128C7E', ENT_QUOTES, 'UTF-8');
$fg = htmlspecialchars($alertBar['text_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8');
$msg = htmlspecialchars($alertBar['message'], ENT_QUOTES, 'UTF-8');
$linkText = $alertBar['link_text'] ? htmlspecialchars($alertBar['link_text'], ENT_QUOTES, 'UTF-8') : '';
$linkUrl = $alertBar['link_url'] ? htmlspecialchars($alertBar['link_url'], ENT_QUOTES, 'UTF-8') : '';
$btnText = $alertBar['btn_text'] ? htmlspecialchars($alertBar['btn_text'], ENT_QUOTES, 'UTF-8') : '';
$btnUrl = $alertBar['btn_url'] ? htmlspecialchars($alertBar['btn_url'], ENT_QUOTES, 'UTF-8') : '';
$cdLabel = $alertBar['countdown_label'] ? htmlspecialchars($alertBar['countdown_label'], ENT_QUOTES, 'UTF-8') : '';
$cdTarget = $alertBar['countdown_ts'];
$isDismissible = (int)($alertBar['is_dismissible'] ?? 1);
$alertId = (int)$alertBar['id'];

$dismissCookie = "wa_alert_dismissed_{$alertId}";
?>
<!-- Alert Bar -->
<div id="waAlertBar" class="wa-alert-bar" data-cookie="<?php echo $dismissCookie; ?>"
     style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;">
    <div class="wa-alert-inner">
        <span class="wa-alert-icon"><?php echo $icon; ?></span>
        <span class="wa-alert-msg"><?php echo $msg; ?></span>
        <?php if ($linkText && $linkUrl): ?>
        <a href="<?php echo $linkUrl; ?>" class="wa-alert-link" style="color:<?php echo $fg; ?>;"><?php echo $linkText; ?></a>
        <?php endif; ?>
        <?php if ($btnText && $btnUrl): ?>
        <a href="<?php echo $btnUrl; ?>" class="wa-alert-btn" style="background:<?php echo $fg; ?>;color:<?php echo $bg; ?>;"><?php echo $btnText; ?></a>
        <?php endif; ?>
        <?php if ($cdLabel && $cdTarget): ?>
        <span class="wa-alert-countdown" data-target="<?php echo $cdTarget; ?>">
            <span class="wa-cd-label"><?php echo $cdLabel; ?>: </span>
            <span class="wa-cd-display">
                <span class="wa-cd-days">00</span>d
                <span class="wa-cd-hours">00</span>h
                <span class="wa-cd-mins">00</span>m
                <span class="wa-cd-secs">00</span>s
            </span>
        </span>
        <?php endif; ?>
        <?php if ($isDismissible): ?>
        <button class="wa-alert-close" onclick="dismissAlertBar()" aria-label="Dismiss" style="color:<?php echo $fg; ?>;">✕</button>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function() {
    var bar = document.getElementById('waAlertBar');
    if (!bar) return;
    var cookieName = bar.getAttribute('data-cookie');
    if (cookieName && document.cookie.indexOf(cookieName + '=1') !== -1) {
        bar.style.display = 'none';
        return;
    }
    // Countdown timer
    var cd = bar.querySelector('.wa-alert-countdown');
    if (cd) {
        var target = parseInt(cd.getAttribute('data-target')) * 1000;
        function updateCountdown() {
            var diff = Math.max(0, target - Date.now());
            if (diff <= 0) {
                cd.querySelector('.wa-cd-display').textContent = 'Ended';
                return;
            }
            var s = Math.floor(diff / 1000) % 60;
            var m = Math.floor(diff / 60000) % 60;
            var h = Math.floor(diff / 3600000) % 24;
            var d = Math.floor(diff / 86400000);
            cd.querySelector('.wa-cd-days').textContent = String(d).padStart(2, '0');
            cd.querySelector('.wa-cd-hours').textContent = String(h).padStart(2, '0');
            cd.querySelector('.wa-cd-mins').textContent = String(m).padStart(2, '0');
            cd.querySelector('.wa-cd-secs').textContent = String(s).padStart(2, '0');
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    }
})();

function dismissAlertBar() {
    var bar = document.getElementById('waAlertBar');
    if (!bar) return;
    var cookieName = bar.getAttribute('data-cookie');
    if (cookieName) {
        document.cookie = cookieName + '=1; path=/; max-age=' + (86400 * 30) + '; SameSite=Lax';
    }
    bar.style.transform = 'translateY(-100%)';
    bar.style.opacity = '0';
    bar.style.maxHeight = '0';
    bar.style.padding = '0';
    bar.style.margin = '0';
    bar.style.border = 'none';
    setTimeout(function() { bar.style.display = 'none'; }, 400);
}
</script>

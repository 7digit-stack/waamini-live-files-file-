<?php
/**
 * Bottom tab bar for phones (client + admin panels). Shown only on screens ≤ 900px (see assets/css/mobile.css).
 * The centre "More" button opens the full side menu.
 * Expects $wmTabs = [[key, label, url, svg-path, [extra active page keys]], ...] (4 items) and $currentPage.
 */
if (empty($wmTabs) || !is_array($wmTabs)) return;
$__cur = (string)($currentPage ?? '');
$__svg = static fn(string $p) => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$__tab = static function (array $t) use ($__cur, $__svg): string {
    [$key, $label, $url, $icon] = $t;
    $on = $__cur === $key || in_array($__cur, (array)($t[4] ?? []), true);
    return '<a class="wm-tab' . ($on ? ' is-on' : '') . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . ($on ? ' aria-current="page"' : '') . '>'
         . '<span class="wm-tab__ic">' . $__svg($icon) . '</span><span class="wm-tab__l">' . htmlspecialchars($label) . '</span></a>';
};
?>
<nav class="wm-tabbar" id="wmTabbar" aria-label="Quick navigation">
  <?php echo $__tab($wmTabs[0]) . $__tab($wmTabs[1]); ?>
  <button type="button" class="wm-tab wm-tab--more" id="wmTabMore" aria-label="Open menu" aria-controls="sidebar">
    <span class="wm-tab__fab"><?php echo $__svg('<rect width="7" height="7" x="3" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="14" rx="1.5"/><rect width="7" height="7" x="3" y="14" rx="1.5"/>'); ?></span>
    <span class="wm-tab__l">More</span>
  </button>
  <?php echo $__tab($wmTabs[2]) . $__tab($wmTabs[3]); ?>
</nav>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES); ?>">
(function () {
  var more = document.getElementById('wmTabMore');
  if (!more) return;
  more.addEventListener('click', function (e) {
    e.stopPropagation();
    var h = document.getElementById('hamburger') || document.querySelector('.hamburger-menu, .menu-toggle, .sidebar-toggle');
    if (h) h.click();      // the panel's own menu code opens the drawer, backdrop and all
  });
})();
</script>

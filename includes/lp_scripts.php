<?php
/** Smooth-scroll + GSAP stack and lp-theme.js (custom cursor, nav overlay, reveals) for lp-neo pages. */
$__lpNonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<script src="https://cdn.jsdelivr.net/npm/lenis@1.1.16/dist/lenis.min.js" nonce="<?php echo $__lpNonce; ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" nonce="<?php echo $__lpNonce; ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" nonce="<?php echo $__lpNonce; ?>"></script>
<script src="assets/js/lp-theme.js?v=5" nonce="<?php echo $__lpNonce; ?>"></script>

<?php
/**
 * Shared split-screen layout for login, register, forgot/reset password.
 *   auth_open($title, $navLinks)   ... page markup ...   auth_close()
 * Helpers: auth_alert(), auth_field(), auth_icon().
 */

function auth_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function auth_icon(string $name): string
{
    $p = [
        'mail'  => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'user'  => '<path d="M16 10h2"/><path d="M16 14h2"/><path d="M6.17 15a3 3 0 0 1 5.66 0"/><circle cx="9" cy="11" r="2"/><rect x="2" y="5" width="20" height="14" rx="2"/>',
        'build' => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'phone' => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
        'lock'  => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ][$name] ?? '';
    return '<svg class="field__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Message slot. $type: error | success | warn. $html lets callers pass trusted markup. */
function auth_alert(string $msg, string $type = 'error', bool $html = false): void
{
    if ($msg === '') { return; }
    echo '<div class="alert alert--' . auth_e($type) . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . ($html ? $msg : auth_e($msg)) . '</div>';
}

/** One input pill. $type 'password' adds the show/hide toggle. */
function auth_field(string $id, string $name, string $type, string $label, string $placeholder, string $icon, array $o = []): void
{
    $attrs = '';
    foreach (['autocomplete', 'minlength', 'inputmode', 'maxlength'] as $a) {
        if (isset($o[$a])) { $attrs .= ' ' . $a . '="' . auth_e($o[$a]) . '"'; }
    }
    if (!empty($o['required'])) { $attrs .= ' required'; }
    if (!empty($o['autofocus'])) { $attrs .= ' autofocus'; }
    $val = isset($o['value']) ? ' value="' . auth_e($o['value']) . '"' : '';
    $pw = $type === 'password';
    echo '<div class="field' . ($pw ? ' field--password' : '') . '"><div class="field__body">'
       . '<label for="' . auth_e($id) . '">' . auth_e($label) . '</label>'
       . '<input id="' . auth_e($id) . '" name="' . auth_e($name) . '" type="' . auth_e($type) . '" placeholder="' . auth_e($placeholder) . '"' . $attrs . $val . '>'
       . '</div>';
    if ($pw) {
        echo '<button class="field__toggle" type="button" aria-label="Show password" aria-pressed="false" data-toggle-password="' . auth_e($id) . '">'
           . '<svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>'
           . '<svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>'
           . '</button>';
    } else {
        echo auth_icon($icon);
    }
    echo '</div>';
}

/** Opens <html> through <main>. $nav = [[label, href], ...] */
function auth_open(string $title, array $nav = []): void
{
    if (!$nav) { $nav = [['Home', 'index.php'], ['Join', 'register.php']]; }
    $nonce = auth_e($_SESSION['_csp_nonce'] ?? '');
    $base = (string)($GLOBALS['AUTH_BASE'] ?? ''); // '../' when the page lives in a sub-folder (e.g. landing-admin)
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo auth_e($title); ?> | WAAMINI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap">
<link rel="stylesheet" href="<?php echo auth_e($base); ?>assets/css/auth-split.css?v=2">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
</head>
<body>
<div class="auth">
  <div class="auth__panel">
    <header class="auth__top">
      <a class="brand" href="<?php echo auth_e($base); ?>index.php" aria-label="Waamini home"><img class="brand__logo" src="<?php echo auth_e($base); ?>assets/img/waamini-logo.png" alt="Waamini" width="164" height="32"></a>
      <nav class="auth__nav" aria-label="Main">
        <?php foreach ($nav as $n): ?><a href="<?php echo auth_e($n[1]); ?>"><?php echo auth_e($n[0]); ?></a><?php endforeach; ?>
      </nav>
    </header>
    <main class="auth__main">
<?php
}

/** Closes <main>, adds the art panel and the shared password-toggle script. */
function auth_close(string $extraScript = ''): void
{
    $nonce = auth_e($_SESSION['_csp_nonce'] ?? '');
    ?>
    </main>
  </div>

  <div class="auth__art" aria-hidden="true">
    <svg class="auth__scene" viewBox="0 0 640 900" preserveAspectRatio="xMinYMid slice">
      <defs><clipPath id="sceneClip"><path d="M440 0C380 20 250 70 222 180C196 280 300 320 286 400C268 500 130 540 96 650C66 750 60 830 26 900L640 900L640 0Z"/></clipPath></defs>
      <path d="M380 -10C320 12 205 60 176 176C150 280 250 322 238 396C222 490 86 532 50 644C22 740 18 830 -16 900" fill="none" stroke="#C5D0DC" stroke-width="1.5" stroke-dasharray="6 8" stroke-linecap="round"/>
      <g clip-path="url(#sceneClip)">
        <rect width="640" height="900" fill="#DDEEF2"/>
        <ellipse cx="520" cy="120" rx="180" ry="70" fill="#F4FAFB"/>
        <ellipse cx="400" cy="196" rx="140" ry="50" fill="#F4FAFB"/>
        <ellipse cx="600" cy="256" rx="160" ry="54" fill="#F4FAFB" opacity=".8"/>
        <ellipse cx="340" cy="304" rx="96" ry="28" fill="#F4FAFB" opacity=".7"/>
        <path d="M0 640L0 500L70 455L130 490L210 410L270 450L340 372L400 430L455 395L520 440L580 400L640 430L640 640Z" fill="#A9D2D7"/>
        <path d="M340 372L372 404L352 400L340 416L326 400L312 404ZM210 410L236 436L218 432L208 446L196 432L186 434ZM455 395L478 414L462 412L454 424L442 412L432 412ZM580 400L604 420L588 418L580 430L570 418L558 420Z" fill="#EDF6F7"/>
        <path d="M0 640L0 560L60 520L120 550L190 490L250 540L300 505L360 560L430 500L500 555L560 515L640 560L640 640Z" fill="#6FB3BC"/>
        <path d="M190 490L250 540L215 640L160 640ZM430 500L500 555L470 640L410 640ZM560 515L640 560L640 640L590 640Z" fill="#5AA3AD"/>
        <path d="M90 640L240 552L330 602L380 640Z" fill="#D5E6E7"/>
        <path d="M0 640L0 626l8-10 8 10 8-14 8 14 8-9 8 9 8-13 8 13 8-11 8 11 8-15 8 15 8-10 8 10 8-14 8 14 8-12 8 12 8-16 8 16 8-11 8 11 8-15 8 15 8-13 8 13 8-18 8 18 8-12 8 12 8-17 8 17 8-14 8 14 8-20 8 20 8-13 8 13 8-19 8 19 8-16 8 16 8-22 8 22 8-15 8 15 8-24 8 24 8-18 8 18 8-26 8 26 8-17 8 17 8-28 8 28 8-20 8 20 8-30 8 30 8-19 8 19 8-32 8 32 8-22 8 22 8-34 8 34 8-24 8 24 8-36 8 36 8-26 8 26 8-38 8 38 8-28 8 28 8-40 8 40L640 640Z" fill="#17424C"/>
        <rect y="640" width="640" height="260" fill="#4E9FAB"/>
        <path d="M0 640L0 780L70 825L130 790L210 870L270 830L340 908L400 850L455 885L520 840L580 880L640 850L640 640Z" fill="#A9D2D7" opacity=".5"/>
        <path d="M0 640L0 720L60 760L120 730L190 790L250 740L300 775L360 720L430 780L500 725L560 765L640 720L640 640Z" fill="#3F8D99" opacity=".6"/>
        <path d="M0 640L640 640" stroke="#BFDDE0" stroke-width="2"/>
        <path d="M150 704h120M330 716h90M470 700h130M90 760h70M240 772h150M450 764h110M60 824h110M230 838h80M380 826h160M120 874h140M330 882h100M500 870h110" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" opacity=".28"/>
      </g>
    </svg>
    <img class="auth__mark" src="<?php echo auth_e($base); ?>assets/img/waamini-icon.png" alt="" width="72" height="72">
  </div>
</div>
<script nonce="<?php echo $nonce; ?>">
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
  var input = document.getElementById(btn.getAttribute('data-toggle-password'));
  btn.addEventListener('click', function () {
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});
</script>
<?php if ($extraScript !== ''): ?><script nonce="<?php echo $nonce; ?>"><?php echo $extraScript; ?></script><?php endif; ?>
</body>
</html>
<?php
}

/** Google button (only when OAuth is configured). */
function auth_google_button(string $label): void
{
    // Always shown; if Google is not configured yet, auth/google.php sends the user back with a clear message.
    echo '<div class="or">or</div>'
       . '<a class="btn btn--ghost btn--block" href="auth/google.php">'
       . '<svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>'
       . auth_e($label) . '</a>';
}

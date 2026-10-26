<?php
/**
 * WAAMINI - Login Page
 * Hardened against Clickjacking, XSS, and Concurrency DoS
 */

// Load global security (Headers, Sessions, Error Suppression)
require_once __DIR__ . '/config/bootstrap.php';

// Load legacy dependencies (db.php creates $conn using mysqli)
include 'includes/security.php';
include 'db.php';
$disablePublicPageCache = true;

$error = "";
$rateLimited = false;
$rateKey = 'login:' . get_client_ip();

// Google OAuth error codes
$oauthErrors = [
    'oauth_config'           => 'Google login is not configured. Please contact support.',
    'oauth_state'            => 'Security check failed. Please try again.',
    'oauth_denied'           => 'Google sign-in was cancelled.',
    'oauth_no_code'          => 'No authorization code received from Google.',
    'oauth_token_fail'       => 'Could not exchange token with Google. Please try again.',
    'oauth_userinfo_fail'    => 'Could not fetch your Google profile. Please try again.',
    'oauth_no_email'         => 'Your Google account has no verified email address.',
    'oauth_unverified_email' => 'Your Google email is not verified.',
    'oauth_db_error'         => 'A database error occurred. Please try again.',
    'oauth_register_fail'    => 'Could not create your account. Please try again.',
    'oauth_user_not_found'   => 'Account not found. Please try again.',
    'oauth_account_disabled' => 'Your account has been disabled. Contact support.',
];
if (isset($_GET['error']) && isset($oauthErrors[$_GET['error']])) {
    $error = $oauthErrors[$_GET['error']];
}

// ── Two-factor popup state (password OK, waiting for the authenticator code) ──
$show2fa = false;
$twofaError = '';
if (isset($_GET['cancel2fa'])) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_email'], $_SESSION['login_remember_me']);
    header('Location: login.php');
    exit();
}
if (isset($_GET['error2fa'])) {
    $error = 'Your sign-in step expired. Please log in again.';
}
if (isset($_GET['2fa']) && !empty($_SESSION['pending_2fa_user_id'])) {
    if (empty($_SESSION['pending_2fa_at'])) { $_SESSION['pending_2fa_at'] = time(); } // Google sign-in path
    if (time() - (int)$_SESSION['pending_2fa_at'] <= 600) {
        $show2fa = true;
        $twofaError = (string)($_SESSION['twofa_flash'] ?? '');
    } else {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_email'], $_SESSION['login_remember_me']);
        $error = 'Your sign-in step expired. Please log in again.';
    }
}
unset($_SESSION['twofa_flash']);

if (isset($_POST['email'])) {

  // CSRF Check
  csrf_verify();

  // Rate Limiting: 5 attempts per 5 minutes
  $rateCheck = rate_limit($rateKey, 5, 300);
  if (!$rateCheck['allowed']) {
    $error = "Too many failed attempts. Please wait.";
    $rateLimited = true;
  }
  else {
    $email = sanitize_email($_POST['email']);
    $password = $_POST['password'] ?? '';

    if (!validate_email($email)) {
      $error = "Invalid email format!";
      rate_limit_hit($rateKey);
    }
    else {
      // Check IP lockout before any processing
      ip_check_lockout();

      $stmt = $conn->prepare("SELECT id, name, email, password, company_id, role, status, two_factor_enabled, two_factor_secret FROM users WHERE email=?");
      if (!$stmt) {
        error_log('[WAAMINI Login] prepare failed: ' . $conn->error);
        $error = "System temporarily unavailable. Please try again.";
      } else {
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, (string)($user['password'] ?? ''))) {

          if (($user['status'] ?? 'active') === 'disabled') {
            $error = "Invalid email or password.";
            rate_limit_hit($rateKey);
            ip_log_failed_attempt();
          }
          else {
            // --- 2FA Check ---
            $has2FA = isset($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1;

            if ($has2FA) {
              // Password is right — ask for the authenticator code in a popup on this page
              session_regenerate_id(true);
              $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
              $_SESSION['pending_2fa_at'] = time();
              $_SESSION['pending_2fa_email'] = (string)$user['email'];
              $_SESSION['login_remember_me'] = !empty($_POST['remember']);
              header("Location: login.php?2fa=1");
              exit();
            }

            // Success — clear rate limit and IP lockout
            rate_limit_clear($rateKey);
            ip_clear_failed_attempts();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = (string)($user['name'] ?? '');
            $_SESSION['company_id'] = (int)($user['company_id'] ?? 0);
            $_SESSION['role'] = (string)($user['role'] ?? 'client');
            waamini_refresh_session_cookie(
                !empty($_POST['remember']) ? waamini_remember_me_lifetime_seconds() : waamini_default_session_lifetime_seconds()
            );

            // Generate persistent remember me token
            if (!empty($_POST['remember'])) {
                $token = bin2hex(random_bytes(32));
                $selector = bin2hex(random_bytes(16));
                $hashedToken = hash('sha256', $token);
                $expires = date('Y-m-d H:i:s', time() + waamini_remember_me_lifetime_seconds());
                
                $stmtToken = $conn->prepare("INSERT INTO user_tokens (user_id, selector, token, expires_at) VALUES (?, ?, ?, ?)");
                if ($stmtToken) {
                    $stmtToken->bind_param("isss", $user['id'], $selector, $hashedToken, $expires);
                    $stmtToken->execute();
                }
                
                setcookie(
                    'remember_me',
                    $selector . ':' . $token,
                    time() + waamini_remember_me_lifetime_seconds(),
                    '/',
                    '',
                    Security::isHttps(),
                    true
                );
            }

            // Save persistent email for pre-filling
            if (!empty($_POST['remember'])) {
                setcookie('remember_user', $email, time() + 2592000, '/', '', Security::isHttps(), true);
            } else {
                setcookie('remember_user', '', time() - 3600, '/', '', Security::isHttps(), true);
            }

            if ($user['role'] === 'super_admin') {
              header("Location: admin/dashboard.php");
            }
            else {
              header("Location: dashboard.php");
            }
            exit();
          }
        }
        else {
          $error = "Invalid email or password.";
          rate_limit_hit($rateKey);
          ip_log_failed_attempt();
        }
      }
      else {
        $error = "Invalid email or password.";
        rate_limit_hit($rateKey);
        ip_log_failed_attempt();
      }
      $stmt->close();
      }
      
      // SECURITY FIX: Removed sleep() to prevent thread-blocking DoS attacks.
      // Your IP lockout & rate limiter already adequately block brute force.
    }
  }
}

$rateInfo = rate_limit($rateKey, 5, 300);
require_once __DIR__ . '/includes/auth_layout.php';
auth_open('Log in', [['Home', 'index.php'], ['Join', 'register.php']]);
?>
      <div class="auth__intro">
        <p class="eyebrow">Welcome back</p>
        <h1>Log in<span>.</span></h1>
        <p class="lead">New to WAAMINI? <a class="link" href="register.php">Create an account</a></p>
      </div>

      <?php auth_alert((string)$error, 'error'); ?>

      <?php if (!$rateLimited && $rateInfo['allowed']): ?>
      <form class="form" method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <?php auth_field('email', 'email', 'email', 'Email', 'you@company.com', 'mail', ['autocomplete' => 'email', 'required' => 1, 'value' => (string)($_POST['email'] ?? $_COOKIE['remember_user'] ?? '')]); ?>
        <?php auth_field('password', 'password', 'password', 'Password', 'Your password', 'lock', ['autocomplete' => 'current-password', 'required' => 1]); ?>
        <div class="form__meta">
          <label class="check"><input type="checkbox" name="remember" value="1" <?php echo !empty($_POST['remember']) ? 'checked' : ''; ?>> Remember me</label>
          <a class="link" href="forgot-password.php">Forgot password?</a>
        </div>
        <div class="form__actions">
          <a class="btn btn--ghost" href="register.php">Create account</a>
          <button class="btn btn--primary" type="submit">Log in</button>
        </div>
        <?php auth_google_button('Continue with Google'); ?>
      </form>
      <?php else: ?>
        <div class="lock">
          <h2>Account locked</h2>
          <p style="margin:0">Too many failed attempts. Try again in <b><?php echo (int)ceil((int)($rateInfo['retry_after'] ?? 60) / 60); ?> minute(s)</b>.</p>
        </div>
      <?php endif; ?>

<?php if ($show2fa):
    $tfEmail = (string)($_SESSION['pending_2fa_email'] ?? '');
    if ($tfEmail !== '' && strpos($tfEmail, '@') !== false) {
        [$tfU, $tfD] = explode('@', $tfEmail, 2);
        $tfEmail = mb_substr($tfU, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($tfU) - 2))) . '@' . $tfD;
    }
    $tfLeft = (int)(rate_limit('verify_2fa:' . get_client_ip(), 5, 300)['remaining'] ?? 5);
?>
<style>
.tf{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(14,23,38,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);animation:tfFade .2s ease-out}
.tf__card{position:relative;width:min(440px,100%);max-height:calc(100dvh - 32px);overflow-y:auto;background:var(--white);border-radius:24px;padding:32px 28px 24px;box-shadow:0 40px 80px -24px rgba(14,23,38,.45);text-align:center;animation:tfUp .25s cubic-bezier(.2,.8,.2,1)}
.tf__icon{width:64px;height:64px;margin:0 auto 16px;border-radius:18px;display:grid;place-items:center;background:var(--accent-tint);color:var(--accent)}
.tf__icon svg{width:30px;height:30px}
.tf h2{margin:0 0 8px;font:700 22px/1.25 Outfit,system-ui,sans-serif;color:var(--ink)}
.tf__lead{margin:0 0 6px;font-size:14.5px;line-height:1.55;color:var(--slate)}
.tf__who{margin:0 0 22px;font-size:13px;color:var(--slate)}
.tf__who b{color:var(--ink);font-weight:600}
.tf__boxes{display:flex;justify-content:center;gap:clamp(6px,2vw,10px);margin:0 0 14px}
.tf__boxes input{width:clamp(40px,11vw,52px);height:clamp(50px,13vw,60px);padding:0;border:1.5px solid var(--line);border-radius:14px;background:var(--field);text-align:center;font:700 24px/1 Outfit,system-ui,sans-serif;color:var(--ink);caret-color:var(--accent);outline:none;transition:border-color .15s,box-shadow .15s,background .15s}
.tf__boxes input:focus{border-color:var(--accent);background:var(--white);box-shadow:0 0 0 4px var(--accent-tint)}
.tf__boxes input.is-filled{background:var(--white);border-color:var(--accent)}
.tf__boxes .tf__gap{width:6px}
.tf.is-error .tf__boxes input{border-color:#D64545;background:#FDF2F2}
.tf.is-shake .tf__boxes{animation:tfShake .4s}
.tf__msg{min-height:20px;margin:0 0 14px;font-size:13.5px;font-weight:600;color:#A12222}
.tf__msg.is-info{color:var(--slate);font-weight:500}
.tf__actions{display:flex;flex-direction:column;gap:10px}
.tf__actions .btn{flex:none;width:100%;height:52px}
.tf__actions .btn[disabled]{opacity:.6;cursor:wait}
.tf__cancel{display:inline-block;margin-top:2px;font-size:13.5px;color:var(--slate);text-decoration:none}
.tf__cancel:hover{color:var(--ink);text-decoration:underline}
.tf__hint{margin:16px 0 0;padding-top:14px;border-top:1px solid var(--field-hover);font-size:12.5px;line-height:1.5;color:var(--slate)}
.tf__spin{width:18px;height:18px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:tfSpin .7s linear infinite;display:none}
.is-busy .tf__spin{display:inline-block}
.tf.is-done .tf__icon{background:#E3F6EC;color:#16834F}
@keyframes tfFade{from{opacity:0}}
@keyframes tfUp{from{opacity:0;transform:translateY(16px) scale(.98)}}
@keyframes tfShake{20%,60%{transform:translateX(-7px)}40%,80%{transform:translateX(7px)}}
@keyframes tfSpin{to{transform:rotate(360deg)}}
@media (max-width:420px){.tf__card{padding:26px 18px 20px;border-radius:20px}.tf h2{font-size:20px}}
@media (prefers-reduced-motion:reduce){.tf,.tf__card,.tf.is-shake .tf__boxes{animation:none}}
</style>
<div class="tf<?php echo $twofaError !== '' ? ' is-error' : ''; ?>" id="tf" role="dialog" aria-modal="true" aria-labelledby="tfTitle" aria-describedby="tfLead">
  <div class="tf__card">
    <div class="tf__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10 11V9.5a2 2 0 0 1 4 0V11"/></svg>
    </div>
    <h2 id="tfTitle">Two-factor verification</h2>
    <p class="tf__lead" id="tfLead">Open your authenticator app and enter the 6-digit code for Waamini.</p>
    <?php if ($tfEmail !== ''): ?><p class="tf__who">Signing in as <b><?php echo auth_e($tfEmail); ?></b></p><?php else: ?><p class="tf__who">&nbsp;</p><?php endif; ?>

    <form id="tfForm" method="POST" action="verify_2fa.php" autocomplete="off" novalidate>
      <?php echo csrf_field(); ?>
      <div class="tf__boxes" id="tfBoxes">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <?php if ($i === 3): ?><span class="tf__gap" aria-hidden="true"></span><?php endif; ?>
          <input type="text" name="d[]" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit <?php echo $i + 1; ?> of 6"<?php echo $i === 0 ? ' autocomplete="one-time-code" autofocus' : ' autocomplete="off"'; ?>>
        <?php endfor; ?>
      </div>
      <p class="tf__msg<?php echo $twofaError === '' ? ' is-info' : ''; ?>" id="tfMsg" role="alert" aria-live="assertive"><?php
        echo $twofaError !== '' ? auth_e($twofaError) : ($tfLeft < 5 ? auth_e($tfLeft . ' attempt(s) left') : '');
      ?></p>
      <div class="tf__actions">
        <button class="btn btn--primary" type="submit" id="tfSubmit"><span class="tf__spin" aria-hidden="true"></span><span id="tfSubmitTxt">Verify &amp; continue</span></button>
        <a class="tf__cancel" href="login.php?cancel2fa=1">Cancel and use a different account</a>
      </div>
    </form>
    <p class="tf__hint">The code changes every 30 seconds. If it keeps failing, check that your phone's time is set automatically.</p>
  </div>
</div>
<?php endif; ?>
<?php
$tfScript = !$show2fa ? '' : <<<'JS'
(function () {
  var tf = document.getElementById('tf'), form = document.getElementById('tfForm');
  if (!tf || !form) return;
  var boxes = [].slice.call(form.querySelectorAll('#tfBoxes input'));
  var msg = document.getElementById('tfMsg'), btn = document.getElementById('tfSubmit'), btnTxt = document.getElementById('tfSubmitTxt');
  var busy = false;
  document.body.style.overflow = 'hidden';

  function code() { return boxes.map(function (b) { return b.value; }).join(''); }
  function paint() { boxes.forEach(function (b) { b.classList.toggle('is-filled', b.value !== ''); }); }
  function say(text, info) { msg.textContent = text || ''; msg.classList.toggle('is-info', !!info); }
  function fill(str, from) {
    var d = String(str).replace(/\D/g, '').split('');
    for (var i = from; i < boxes.length && d.length; i++) boxes[i].value = d.shift();
    paint();
    var next = boxes.findIndex(function (b) { return b.value === ''; });
    (next === -1 ? boxes[boxes.length - 1] : boxes[next]).focus();
    if (code().length === 6) submit();
  }
  function reset(focus) { boxes.forEach(function (b) { b.value = ''; }); paint(); if (focus) boxes[0].focus(); }
  function setBusy(on) { busy = on; btn.disabled = on; btn.classList.toggle('is-busy', on); btnTxt.textContent = on ? 'Checking…' : 'Verify & continue'; }

  boxes.forEach(function (b, i) {
    b.addEventListener('input', function () {
      tf.classList.remove('is-error');
      if (b.value.length > 1) { var v = b.value; b.value = ''; fill(v, i); return; }
      b.value = b.value.replace(/\D/g, '');
      paint();
      if (b.value && i < boxes.length - 1) boxes[i + 1].focus();
      if (code().length === 6) submit();
    });
    b.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !b.value && i > 0) { boxes[i - 1].value = ''; boxes[i - 1].focus(); paint(); e.preventDefault(); }
      else if (e.key === 'ArrowLeft' && i > 0) { boxes[i - 1].focus(); e.preventDefault(); }
      else if (e.key === 'ArrowRight' && i < boxes.length - 1) { boxes[i + 1].focus(); e.preventDefault(); }
    });
    b.addEventListener('focus', function () { b.select(); });
    b.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData).getData('text');
      if (t) { e.preventDefault(); fill(t, i); }
    });
  });

  function submit(e) {
    if (e) e.preventDefault();
    if (busy) return;
    var c = code();
    if (c.length !== 6) { tf.classList.add('is-error'); say('Enter all 6 digits.'); var f = boxes.find(function (b) { return !b.value; }); if (f) f.focus(); return; }
    setBusy(true); say('', true);
    var fd = new FormData();
    fd.append('_token', form.querySelector('[name="_token"]').value);
    fd.append('auth_code', c);
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          tf.classList.add('is-done'); btnTxt.textContent = 'Verified — opening your dashboard…';
          window.location.replace(res.redirect || 'dashboard.php');
          return;
        }
        if (res.restart) { window.location.replace('login.php?error2fa=expired'); return; }
        setBusy(false);
        tf.classList.add('is-error', 'is-shake');
        setTimeout(function () { tf.classList.remove('is-shake'); }, 450);
        var extra = (typeof res.remaining === 'number' && res.remaining < 5) ? ' (' + res.remaining + ' attempt' + (res.remaining === 1 ? '' : 's') + ' left)' : '';
        say((res.error || res.message || 'Could not verify the code.') + extra);
        if (res.locked) { boxes.forEach(function (b) { b.disabled = true; }); btn.disabled = true; btnTxt.textContent = 'Locked for a few minutes'; return; }
        reset(true);
      })
      .catch(function () { setBusy(false); say('Network problem. Check your connection and try again.'); });
  }
  form.addEventListener('submit', submit);
  // Stray Escape should not drop the user out of the sign-in step
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); boxes[0].focus(); } });
  setTimeout(function () { (boxes.find(function (b) { return !b.value; }) || boxes[0]).focus(); }, 60);
})();
JS;
auth_close($tfScript);
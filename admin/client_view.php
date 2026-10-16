<?php
include '../includes/auth_check.php';
include '../db.php';
include_once '../includes/security.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: users.php');
    exit;
}

// ── Fetch client ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT u.*, c.name AS company_name, c.status AS company_status, c.id AS cid
     FROM users u
     LEFT JOIN companies c ON u.company_id = c.id
     WHERE u.id = ? LIMIT 1"
);
if (!$stmt) { header('Location: users.php'); exit; }
$stmt->bind_param('i', $id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    header('Location: users.php?msg=not_found');
    exit;
}

// ── Handle quick actions (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_status' && $u['role'] !== 'super_admin' && $u['id'] != $_SESSION['user_id']) {
        $newStatus = ($u['status'] ?? 'active') === 'active' ? 'disabled' : 'active';
        $s = $conn->prepare("UPDATE users SET status=? WHERE id=?");
        if ($s) { $s->bind_param('si', $newStatus, $id); $s->execute(); }
        header('Location: client_view.php?id='.$id.'&msg=toggled'); exit;
    }

    if ($action === 'reset_password' && $u['role'] !== 'super_admin') {
        $np = (string)($_POST['new_password'] ?? '');
        if (strlen($np) >= 6) {
            $hashed = password_hash($np, PASSWORD_DEFAULT);
            $s = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            if ($s) { $s->bind_param('si', $hashed, $id); $s->execute(); }
            header('Location: client_view.php?id='.$id.'&msg=pw_reset'); exit;
        }
    }

    if ($action === 'delete' && $u['role'] !== 'super_admin' && $u['id'] != $_SESSION['user_id']) {
        $s = $conn->prepare("DELETE FROM users WHERE id=? AND role != 'super_admin'");
        if ($s) { $s->bind_param('i', $id); $s->execute(); }
        header('Location: users.php?msg=deleted'); exit;
    }
}

// ── Fetch audit log activity ───────────────────────────────────────────────────
$activity = [];
$aStmt = $conn->prepare(
    "SELECT action, description, ip_address, created_at
     FROM audit_log WHERE user_id = ?
     ORDER BY id DESC LIMIT 20"
);
if ($aStmt && $aStmt->execute()) {
    $aRes = $aStmt->get_result();
    while ($row = $aRes->fetch_assoc()) $activity[] = $row;
    $aStmt->close();
}

// ── Message count (this month) ─────────────────────────────────────────────────
$msgCount = 0;
$mStmt = $conn->query(
    "SELECT COUNT(*) AS c FROM messages
     WHERE company_id=".((int)$u['company_id'] ?? 0)."
       AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')"
);
if ($mStmt) $msgCount = (int)$mStmt->fetch_assoc()['c'];

// ── Subscription ───────────────────────────────────────────────────────────────
$sub = null;
try {
    $sStmt = $conn->prepare(
        "SELECT s.*, p.name AS plan_name, p.price
         FROM subscriptions s
         LEFT JOIN subscription_plans p ON s.plan_id = p.id
         WHERE s.company_id = ? ORDER BY s.id DESC LIMIT 1"
    );
    if ($sStmt) { $sStmt->bind_param('i', $u['company_id']); $sStmt->execute(); $sub = $sStmt->get_result()->fetch_assoc(); $sStmt->close(); }
} catch (Throwable $_) {}

$pageTitle   = 'Client: ' . htmlspecialchars($u['name'] ?? 'Unknown');
$currentPage = 'users';
include '../includes/admin_header.php';

$isGoogle   = !empty($u['google_id']);
$isDisabled = ($u['status'] ?? 'active') === 'disabled';
$initial    = strtoupper(substr($u['name'] ?? '?', 0, 1));
$flashMsg   = $_GET['msg'] ?? '';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    'cal'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'login'  => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
    'edit'   => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'power'  => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
    'key'    => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'pulse'  => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'x'      => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'dice'   => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M16 8h.01"/><path d="M12 12h.01"/><path d="M8 16h.01"/>',
    'user'   => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
];
$roleLabels = ['super_admin' => 'Super admin', 'client' => 'Client', 'admin' => 'Vendor owner'];
$roleShown = $roleLabels[(string)($u['role'] ?? '')] ?? str_replace('_', ' ', ucfirst((string)($u['role'] ?? '—')));
$isSuper = (($u['role'] ?? '') === 'super_admin');
$isYou = ($u['id'] == $_SESSION['user_id']);
$canManage = !$isSuper && !$isYou;
$companyActive = (($u['company_status'] ?? 'active') === 'active');
$subStatus = (string)($sub['status'] ?? '');
$subCls = ['active' => 'is-ok', 'trial' => 'is-info', 'expired' => 'is-bad', 'cancelled' => 'is-off'][$subStatus] ?? 'is-off';
$actMap = [
    'email_register'  => ['user',  ''],
    'google_register' => ['user',  'is-g'],
    'google_login'    => ['login', 'is-g'],
    'login'           => ['login', ''],
];
?>

<div class="db cv">

  <a class="cv-back" href="users.php"><?php echo $__ic($P['back'], 14); ?> Back to users</a>

  <?php if ($flashMsg === 'toggled'): ?><div class="cv-alert" role="status"><?php echo $__ic($P['check'], 18); ?> User status updated.</div>
  <?php elseif ($flashMsg === 'pw_reset'): ?><div class="cv-alert" role="status"><?php echo $__ic($P['check'], 18); ?> Password has been reset.</div><?php endif; ?>

  <!-- ===== profile header ===== -->
  <section class="db-card cv-hero">
    <span class="cv-av <?php echo $isSuper ? 'is-sa' : ($isGoogle ? 'is-g' : ''); ?>"><?php echo $esc($initial); ?></span>
    <div class="cv-id">
      <div class="cv-id__top">
        <h1><?php echo $esc($u['name']); ?><?php if ($isYou): ?> <em>you</em><?php endif; ?></h1>
        <span class="db-pill <?php echo $isGoogle ? 'is-info' : 'is-off'; ?>"><?php echo $isGoogle ? 'Google' : 'Email'; ?></span>
        <span class="db-pill <?php echo $isDisabled ? 'is-bad' : 'is-ok'; ?>"><?php echo $isDisabled ? 'Disabled' : 'Active'; ?></span>
        <span class="db-pill <?php echo $isSuper ? 'is-warn' : 'is-ok'; ?>"><?php echo $esc($roleShown); ?></span>
      </div>
      <div class="cv-meta">
        <span><?php echo $__ic($P['mail'], 15); ?><?php echo $esc($u['email']); ?></span>
        <?php if (!empty($u['mobile'])): ?><span><?php echo $__ic($P['phone'], 15); ?><?php echo $esc($u['mobile']); ?></span><?php endif; ?>
        <span><?php echo $__ic($P['building'], 15); ?><?php echo $esc($u['company_name'] ?? '—'); ?></span>
        <span><?php echo $__ic($P['cal'], 15); ?>Joined <?php echo date('d M Y', strtotime($u['created_at'])); ?></span>
      </div>
    </div>
    <div class="cv-acts">
      <?php if ($canManage): ?>
        <a href="users.php?login_as=<?php echo (int)$u['id']; ?>&_token=<?php echo urlencode(csrf_get_token()); ?>" class="db-btn db-btn--primary js-confirm"
           data-confirm="Login as <?php echo $esc($u['name']); ?>? You will be redirected to their panel."><?php echo $__ic($P['login'], 16); ?> Login as</a>
        <a href="user_edit.php?id=<?php echo (int)$u['id']; ?>" class="db-btn"><?php echo $__ic($P['edit'], 16); ?> Edit</a>
        <form method="POST" class="cv-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_status">
          <button type="submit" class="db-btn js-confirm" data-confirm="<?php echo $isDisabled ? 'Enable' : 'Disable'; ?> this account?"><?php echo $__ic($P['power'], 16); ?> <?php echo $isDisabled ? 'Enable' : 'Disable'; ?></button></form>
        <button type="button" class="db-btn" id="cvOpenReset"><?php echo $__ic($P['key'], 16); ?> Reset password</button>
        <form method="POST" class="cv-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete">
          <button type="submit" class="db-btn cv-del js-confirm" data-confirm="Permanently delete this client? This cannot be undone."><?php echo $__ic($P['trash'], 16); ?> Delete</button></form>
      <?php elseif (!$isYou): ?>
        <a href="user_edit.php?id=<?php echo (int)$u['id']; ?>" class="db-btn"><?php echo $__ic($P['edit'], 16); ?> Edit</a>
      <?php else: ?>
        <a href="profile.php" class="db-btn"><?php echo $__ic($P['user'], 16); ?> My profile</a>
      <?php endif; ?>
    </div>
  </section>

  <!-- ===== KPIs ===== -->
  <section class="cv-stats">
    <div class="cv-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['chat'], 20); ?></span><div><span>Messages this month</span><b><?php echo number_format($msgCount); ?></b></div></div>
    <div class="cv-stat"><span class="db-kpi__ic is-purple"><?php echo $__ic($P['layers'], 20); ?></span><div><span>Plan</span><b class="cv-sm"><?php echo $sub ? $esc($sub['plan_name'] ?? 'Unknown') : 'None'; ?></b></div></div>
    <div class="cv-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['building'], 20); ?></span><div><span>Company status</span><b class="cv-sm <?php echo $companyActive ? 'is-ok' : 'is-bad'; ?>"><?php echo $esc(ucfirst((string)($u['company_status'] ?? 'active'))); ?></b></div></div>
    <div class="cv-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['shield'], 20); ?></span><div><span>Role</span><b class="cv-sm"><?php echo $esc($roleShown); ?></b></div></div>
  </section>

  <div class="db-row db-row--bottom cv-layout">

    <!-- ===== activity ===== -->
    <section class="db-card">
      <div class="db-card__head"><div><h2>Activity log</h2><p>Last 20 actions for this account</p></div><a class="db-link" href="audit_log.php">Full audit &rsaquo;</a></div>
      <div class="db-list" style="max-height:560px;padding-top:6px">
        <?php if (empty($activity)): ?>
          <div class="db-empty">No activity recorded yet.</div>
        <?php else: foreach ($activity as $act):
            [$ico, $cls] = $actMap[$act['action']] ?? ['pulse', 'is-amber']; ?>
          <div class="db-li">
            <span class="db-li__ic <?php echo $cls; ?>"><?php echo $__ic($P[$ico], 18); ?></span>
            <div class="db-li__t"><b style="white-space:normal"><?php echo $esc(str_replace(['_', '-'], ' ', ucfirst((string)$act['action']))); ?></b><small style="white-space:normal"><?php echo $esc($act['description'] ?? ''); ?></small></div>
            <div class="db-li__r"><?php echo date('d M Y', strtotime($act['created_at'])); ?><div class="cv-time"><?php echo date('H:i', strtotime($act['created_at'])); ?><?php if (!empty($act['ip_address'])): ?> · <code><?php echo $esc($act['ip_address']); ?></code><?php endif; ?></div></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <!-- ===== side ===== -->
    <aside class="cv-side">
      <section class="db-card">
        <div class="db-card__head"><div><h2>Account</h2></div></div>
        <div class="db-meter">
          <div class="db-meter__row"><span>User ID</span><b>#<?php echo (int)$u['id']; ?></b></div>
          <div class="db-meter__row"><span>Role</span><b><?php echo $esc($roleShown); ?></b></div>
          <div class="db-meter__row"><span>Login method</span><b><?php echo $isGoogle ? 'Google OAuth' : 'Email &amp; password'; ?></b></div>
          <?php if ($isGoogle): ?><div class="db-meter__row"><span>Google ID</span><b><?php echo $esc(substr((string)$u['google_id'], 0, 12)); ?>…</b></div><?php endif; ?>
          <div class="db-meter__row" style="border-bottom:0"><span>Joined</span><b><?php echo date('d M Y', strtotime($u['created_at'])); ?></b></div>
        </div>
      </section>

      <section class="db-card">
        <div class="db-card__head"><div><h2>Company &amp; plan</h2></div><?php if ($sub): ?><span class="db-pill <?php echo $subCls; ?>"><?php echo $esc(ucfirst($subStatus)); ?></span><?php endif; ?></div>
        <div class="db-meter">
          <div class="db-meter__row"><span>Company</span><b class="cv-trunc"><?php echo $esc($u['company_name'] ?? '—'); ?></b></div>
          <div class="db-meter__row"><span>Company status</span><b class="<?php echo $companyActive ? 'cv-ok' : 'cv-bad'; ?>"><?php echo $esc(ucfirst((string)($u['company_status'] ?? 'active'))); ?></b></div>
          <div class="db-meter__row"><span>Plan</span><b><?php echo $sub ? $esc($sub['plan_name'] ?? 'Unknown') : '—'; ?></b></div>
          <?php if ($sub && !empty($sub['expires_at'])): ?><div class="db-meter__row"><span>Expires</span><b><?php echo date('d M Y', strtotime($sub['expires_at'])); ?></b></div><?php endif; ?>
          <?php if ($u['cid']): ?><a href="vendor_subscription.php?company_id=<?php echo (int)$u['cid']; ?>" class="db-btn" style="width:100%;justify-content:center;margin-top:14px">View subscription</a><?php endif; ?>
        </div>
      </section>
    </aside>
  </div>
</div>

<!-- ===== reset password modal ===== -->
<div class="cv-modal" id="cvResetModal" hidden>
  <div class="cv-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cvResetT">
    <div class="cv-modal__head">
      <div><h3 id="cvResetT">Reset password</h3><p>for <b><?php echo $esc($u['name']); ?></b></p></div>
      <button type="button" class="cv-ico" id="cvModalX" aria-label="Close"><?php echo $__ic($P['x'], 17); ?></button>
    </div>
    <form method="POST" autocomplete="off">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="reset_password">
      <div class="cv-modal__body">
        <label for="cvNewPass">New password</label>
        <div class="cv-row">
          <input type="password" name="new_password" id="cvNewPass" class="cv-input" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
          <button type="button" class="cv-ico" id="cvToggle" aria-label="Show or hide password"><?php echo $__ic($P['eye'], 16); ?></button>
        </div>
        <div class="cv-gen"><button type="button" class="db-btn" id="cvGen"><?php echo $__ic($P['dice'], 15); ?> Generate</button><code id="cvGenShow"></code></div>
      </div>
      <div class="cv-modal__foot">
        <button type="button" class="db-btn" id="cvCancel">Cancel</button>
        <button type="submit" class="db-btn db-btn--primary">Apply new password</button>
      </div>
    </form>
  </div>
</div>

<style>
.cv{max-width:none;margin:0}
.cv-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;font-size:14px;color:var(--tx);text-decoration:none}
.cv-back:hover{color:var(--g)}
.cv-alert{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E;font-size:14.5px;font-weight:600}
.cv-hero{display:flex;align-items:center;gap:22px;padding:24px 26px;margin-bottom:18px;flex-wrap:wrap}
.cv-av{width:76px;height:76px;border-radius:22px;flex:none;display:grid;place-items:center;font-size:30px;font-weight:800;color:#fff;background:linear-gradient(135deg,var(--g),var(--g2));box-shadow:0 14px 26px -12px rgba(18,140,126,.6)}
.cv-av.is-g{background:linear-gradient(135deg,#1d6fd6,#4a97f0)}.cv-av.is-sa{background:linear-gradient(135deg,#F59E0B,#d98300)}
.cv-id{flex:1;min-width:260px}
.cv-id__top{display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;margin-bottom:10px}
.cv-id h1{margin:0;font-size:26px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}
.cv-id h1 em{margin-left:6px;padding:2px 9px;border-radius:999px;background:#E3F7EE;color:#0B6B5E;font-size:12px;font-style:normal;font-weight:700;vertical-align:4px}
.cv-meta{display:flex;flex-wrap:wrap;gap:8px 22px;font-size:14px;color:var(--tx)}
.cv-meta span{display:inline-flex;align-items:center;gap:7px}
.cv-meta .db-ic{color:var(--mu)}
.cv-acts{display:flex;flex-wrap:wrap;gap:9px;align-items:center}
.cv-inline{display:inline;margin:0}
.cv-del{color:#c0372b;border-color:#F8C9C2}
.cv-del:hover{background:#FEF0EE;border-color:#e5484d;color:#c0372b}
.cv-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.cv-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.cv-stat .db-kpi__ic{margin:0}
.cv-stat>div{min-width:0}
.cv-stat span:not(.db-kpi__ic){display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.cv-stat b{display:block;margin-top:1px;font-size:27px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cv-stat b.cv-sm{font-size:19px;padding-top:4px}
.cv-stat b.is-ok{color:#12803f}.cv-stat b.is-bad{color:#c0372b}
.cv-layout{align-items:start}
.cv-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.cv-trunc{max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cv-ok{color:#12803f!important}.cv-bad{color:#c0372b!important}
.cv-time{margin-top:4px;font-weight:600;color:var(--mu);font-size:12px}
.cv-time code{font-size:11.5px;color:var(--mu);background:none;padding:0}
.db-li .db-li__ic.is-g{background:#E4EFFD;color:#1d6fd6}

.cv-modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,38,34,.55);backdrop-filter:blur(6px)}
.cv-modal[hidden]{display:none}
.cv-modal__dialog{width:100%;max-width:440px;background:#fff;border-radius:20px;box-shadow:0 40px 90px -20px rgba(0,0,0,.45)}
.cv-modal__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:22px 24px 14px;border-bottom:1px solid #EEF3F1}
.cv-modal__head h3{margin:0 0 3px;font-size:19px;font-weight:800;color:var(--ink)}
.cv-modal__head p{margin:0;font-size:13.5px;color:var(--tx)}.cv-modal__head p b{color:var(--g)}
.cv-modal__body{padding:18px 24px}
.cv-modal__body label{display:block;margin-bottom:8px;font-size:14px;font-weight:700;color:var(--ink)}
.cv-row{display:flex;gap:10px}
.cv-input{flex:1;min-width:0;height:44px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;font:500 15px Inter,system-ui,sans-serif;color:var(--ink)}
.cv-input:focus{outline:0;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cv-ico{width:44px;height:44px;flex:none;border-radius:11px;border:1.5px solid var(--line);background:#fff;color:var(--tx);display:grid;place-items:center;cursor:pointer}
.cv-ico:hover{border-color:var(--g);color:var(--g)}
.cv-gen{display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap}
.cv-gen code{font:600 13.5px ui-monospace,Menlo,Consolas,monospace;color:#0B6B5E;word-break:break-all}
.cv-modal__foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px 22px;border-top:1px solid #EEF3F1}

@media (max-width:1100px){.cv-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.cv-stats{grid-template-columns:1fr}.cv-hero{padding:20px}.cv-acts .db-btn{flex:1;justify-content:center}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var modal = $('cvResetModal');
    function open() { modal.hidden = false; $('cvNewPass').value = ''; $('cvNewPass').type = 'password'; $('cvGenShow').textContent = ''; $('cvNewPass').focus(); }
    function close() { modal.hidden = true; }
    var openBtn = $('cvOpenReset'); if (openBtn) openBtn.addEventListener('click', open);
    $('cvModalX').addEventListener('click', close);
    $('cvCancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    $('cvToggle').addEventListener('click', function () { var el = $('cvNewPass'); el.type = el.type === 'password' ? 'text' : 'password'; });
    $('cvGen').addEventListener('click', function () {
        var c = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#', p = '';
        for (var i = 0; i < 12; i++) p += c.charAt(Math.floor(Math.random() * c.length));
        $('cvNewPass').value = p; $('cvNewPass').type = 'text'; $('cvGenShow').textContent = p;
    });
    // confirm dialogs (CSP-safe, no inline onclick)
    document.addEventListener('click', function (e) {
        var el = e.target.closest('.js-confirm[data-confirm]');
        if (el && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>

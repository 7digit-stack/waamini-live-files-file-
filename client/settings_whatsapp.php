<?php
include '../includes/client_auth_check.php';
if (!has_feature('set_whatsapp')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client Settings menu

$pageTitle = 'WhatsApp Setup';
$currentPage = 'settings_whatsapp';

require_once __DIR__ . '/../services/EncryptionService.php';

// App Secret of the client's own Meta app. Meta signs every webhook with it, and webhook.php checks that
// signature, so without it incoming messages from the client's app are rejected. Stored encrypted.
try {
    $__col = $conn->query("SHOW COLUMNS FROM whatsapp_config LIKE 'app_secret'");
    if ($__col && $__col->num_rows === 0) $conn->query('ALTER TABLE whatsapp_config ADD COLUMN app_secret TEXT NULL');
} catch (\Throwable $e) { error_log('[settings_whatsapp] app_secret column: ' . $e->getMessage()); }

/**
 * Verify token webhook.php accepts for any company: Admin > WhatsApp "webhook verify token" (system_settings),
 * else WHATSAPP_VERIFY_TOKEN / WA_WEBHOOK_VERIFY_TOKEN from .env. If none is set, one is generated and saved
 * as the platform token, so a new client always sees a working token before their first save.
 */
if (!function_exists('waamini_platform_verify_token')) {
    function waamini_platform_verify_token(mysqli $conn): string
    {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'wa_webhook_verify_token' LIMIT 1");
        if ($st) {
            $st->execute();
            $v = trim((string)($st->get_result()->fetch_assoc()['setting_value'] ?? ''));
            $st->close();
            if ($v !== '') return $v;
        }
        $v = trim((string)(getenv('WHATSAPP_VERIFY_TOKEN') ?: getenv('WA_WEBHOOK_VERIFY_TOKEN') ?: ''));
        if ($v !== '') return $v;
        $v = 'waamini_' . bin2hex(random_bytes(12));
        $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('wa_webhook_verify_token', ?, 'whatsapp') ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '' OR setting_value IS NULL, VALUES(setting_value), setting_value)");
        if ($st) { $st->bind_param('s', $v); $st->execute(); $st->close(); }
        return $v;
    }
}

$allowedApiVersions = ['v24.0', 'v23.0', 'v22.0', 'v21.0', 'v20.0', 'v19.0', 'v18.0'];
$defaultApiVersion = 'v24.0';

/** Same scheme used when inserting new rows (must match Meta webhook expectations behind TLS). */
function waamini_whatsapp_public_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return $https ? 'https' : 'http';
}

/**
 * Load whatsapp_config row for this company (access_token left as stored in DB).
 *
 * @return array<string, mixed>|null
 */
function waamini_load_whatsapp_config_row(mysqli $conn, int $companyId): ?array {
    $stmt = $conn->prepare('SELECT * FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $companyId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

// --- POST: Re-sync (POST + CSRF — never GET, avoids CSRF on state-changing action) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resync_wa'])) {
    csrf_verify();
    $cfg = waamini_load_whatsapp_config_row($conn, $companyId);

    if ($cfg && !empty($cfg['access_token']) && !empty($cfg['phone_number_id'])) {
        $token = decrypt_secret((string)$cfg['access_token']);
        if ($token === null || $token === '') {
            header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode('The saved access token cannot be decrypted. Paste a current Meta access token and save the configuration.')));
            exit();
        }
        $apiVer = $cfg['api_version'] ?? $defaultApiVersion;
        if (!in_array($apiVer, $allowedApiVersions, true)) {
            $apiVer = $defaultApiVersion;
        }
        $pnid = preg_replace('/[^\d]/', '', (string)$cfg['phone_number_id']);
        $url = 'https://graph.facebook.com/' . rawurlencode($apiVer) . '/' . rawurlencode($pnid)
            . '?fields=verified_name,display_phone_number,quality_rating,code_verification_status,platform_type,name_status';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = is_string($response) ? json_decode($response, true) : null;

        if (is_array($data) && isset($data['verified_name'])) {
            $stmt = $conn->prepare(
                "UPDATE whatsapp_config SET verified_name=?, display_phone=?, quality_rating=?, name_status=?, status='active' WHERE company_id=?"
            );
            if ($stmt) {
                $vn = (string)($data['verified_name'] ?? '');
                $dp = (string)($data['display_phone_number'] ?? '');
                $qr = (string)($data['quality_rating'] ?? '');
                $ns = (string)($data['name_status'] ?? '');
                $stmt->bind_param('ssssi', $vn, $dp, $qr, $ns, $companyId);
                $stmt->execute();
                $stmt->close();
            }
            header('Location: ' . app_url('client/settings_whatsapp.php?msg=synced'));
            exit();
        }

        $err = is_array($data) && isset($data['error']['message'])
            ? (string)$data['error']['message']
            : 'API error';
        header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode($err)));
        exit();
    }

    header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode('Save your API credentials first.')));
    exit();
}

// Fetch raw row (keep ciphertext for “leave token blank = keep existing”)
$waRow = waamini_load_whatsapp_config_row($conn, $companyId);
$cipherBackup = ($waRow && !empty($waRow['access_token'])) ? (string)$waRow['access_token'] : '';
$protocol = waamini_whatsapp_public_base_url();
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$callbackUrl = $protocol . '://' . $host . app_url('webhook.php')
    . '?company_id=' . rawurlencode((string)$companyId);

// --- POST: Save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_wa'])) {
    csrf_verify();

    $fbAppId = preg_replace('/[^\d]/', '', (string)($_POST['fb_app_id'] ?? ''));
    $phoneNumberId = preg_replace('/[^\d]/', '', (string)($_POST['phone_number_id'] ?? ''));
    $wabaId = preg_replace('/[^\d]/', '', (string)($_POST['waba_id'] ?? ''));

    $apiVerIn = trim((string)($_POST['api_version'] ?? $defaultApiVersion));
    $apiVersion = in_array($apiVerIn, $allowedApiVersions, true) ? $apiVerIn : $defaultApiVersion;

    $accessTokenPlain = trim((string)($_POST['access_token'] ?? ''));

    // Draft saves allowed: Meta IDs / token can be filled incrementally before going live.

    // Empty token field = keep existing ciphertext (same pattern as payment settings)
    $encryptedToken = null;
    if ($accessTokenPlain !== '') {
        $encryptedToken = encrypt_secret($accessTokenPlain);
    } elseif ($cipherBackup !== '') {
        $encryptedToken = $cipherBackup;
    }

    if ($waRow) {
        $stmt = $conn->prepare(
            'UPDATE whatsapp_config SET fb_app_id=?, phone_number_id=?, waba_id=?, access_token=?, api_version=?, callback_url=?, updated_at=NOW() WHERE company_id=?'
        );
        if (!$stmt) {
            header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode('Could not save configuration.')));
            exit();
        }
        $stmt->bind_param('ssssssi', $fbAppId, $phoneNumberId, $wabaId, $encryptedToken, $apiVersion, $callbackUrl, $companyId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode('Could not save configuration.')));
            exit();
        }
    } else {
        // Same token the page showed before the first save, so the one already pasted into Meta keeps working
        $verifyToken = waamini_platform_verify_token($conn);
        $status = 'pending';
        $stmt = $conn->prepare(
            'INSERT INTO whatsapp_config (company_id, fb_app_id, phone_number_id, waba_id, access_token, callback_url, verify_token, api_version, status) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        if (!$stmt) {
            header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode('Could not save configuration.')));
            exit();
        }
        $stmt->bind_param(
            'issssssss',
            $companyId,
            $fbAppId,
            $phoneNumberId,
            $wabaId,
            $encryptedToken,
            $callbackUrl,
            $verifyToken,
            $apiVersion,
            $status
        );
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            header('Location: settings_whatsapp.php?error=' . rawurlencode('Could not save configuration.'));
            exit();
        }
    }

    $appSecretPlain = trim((string)($_POST['app_secret'] ?? ''));
    if ($appSecretPlain !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/i', $appSecretPlain)) {
            header('Location: ' . app_url('client/settings_whatsapp.php?error=' . rawurlencode('The App Secret should be the 32-character code from Meta: your app > App settings > Basic > App secret. Your other settings were saved.')));
            exit();
        }
        $encSecret = encrypt_secret(strtolower($appSecretPlain));
        $stSec = $conn->prepare('UPDATE whatsapp_config SET app_secret=? WHERE company_id=?');
        if ($stSec) { $stSec->bind_param('si', $encSecret, $companyId); $stSec->execute(); $stSec->close(); }
    }

    header('Location: ' . app_url('client/settings_whatsapp.php?msg=saved'));
    exit();
}

// Reload after any redirect-generating handlers above
$waRow = waamini_load_whatsapp_config_row($conn, $companyId);
$cipherBackup = ($waRow && !empty($waRow['access_token'])) ? (string)$waRow['access_token'] : '';

// Display copy with the token masked in the textarea
$waConfig = is_array($waRow) ? $waRow : [];
$hasStoredToken = $cipherBackup !== '';
$decryptedToken = $hasStoredToken ? decrypt_secret($cipherBackup) : null;
$storedTokenUnreadable = $hasStoredToken
    && $decryptedToken === null
    && preg_match('/^(?:oa|na|plain):/', $cipherBackup) === 1;

$protocol = waamini_whatsapp_public_base_url();
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$callbackUrl = $protocol . '://' . $host . app_url('webhook.php')
    . '?company_id=' . rawurlencode((string)$companyId);
$verifyToken = is_array($waRow) ? (string)($waRow['verify_token'] ?? '') : '';
// Always show a verify token that webhook.php accepts, also before the first save (new clients):
// a saved company row without a token gets one; otherwise the platform token is shown.
if ($verifyToken === '') {
    if (is_array($waRow)) {
        $verifyToken = 'waamini_' . bin2hex(random_bytes(8));
        $stVt = $conn->prepare("UPDATE whatsapp_config SET verify_token = ? WHERE company_id = ? AND (verify_token IS NULL OR verify_token = '')");
        if ($stVt) { $stVt->bind_param('si', $verifyToken, $companyId); $stVt->execute(); $stVt->close(); }
    } else {
        $verifyToken = waamini_platform_verify_token($conn);
    }
}

$hasWaRow = is_array($waRow);

// Connected = credentials present; business card = Meta returned verified_name (synced at least once)
$isConnected = !$storedTokenUnreadable && !empty($decryptedToken) && !empty($waConfig['phone_number_id']);
$hasBusinessInfo = !empty($waConfig['verified_name']);

include '../includes/client_header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo $_GET['msg'] === 'synced' ? 'WhatsApp Business Info synced successfully!' : 'WhatsApp configuration saved!'; ?></div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> WhatsApp Setup</h2>
    <?php if ($storedTokenUnreadable): ?>
        <span class="badge badge-suspended">Credentials Need Update</span>
    <?php elseif ($hasWaRow && ($waConfig['status'] ?? '') === 'active' && $isConnected): ?>
        <span class="badge badge-active"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Connected</span>
    <?php elseif ($hasWaRow): ?>
        <span class="badge badge-draft"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending Verification</span>
    <?php else: ?>
        <span class="badge badge-suspended"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Not Connected</span>
    <?php endif; ?>
</div>

<?php if ($hasBusinessInfo): ?>
<!-- ====== WhatsApp Business Info ====== -->
<div class="form-card" style="max-width:none;margin-bottom:24px;border:1px solid rgba(0,184,148,0.2);background:linear-gradient(135deg,rgba(0,184,148,0.04),rgba(0,206,201,0.02));">
    <h3 style="font-size:15px;font-weight:700;color:var(--text-primary);margin-bottom:20px;display:inline-block;background:var(--bg-card);padding:6px 16px;border-radius:8px;border:1px solid var(--border);">WhatsApp Business Info</h3>

    <div style="padding:0 4px;">
        <h4 style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:16px;display:inline-block;background:var(--bg-card);padding:4px 12px;border-radius:6px;border:1px solid var(--border);">Phone Numbers</h4>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div>
                <div style="font-size:12px;font-weight:600;color:#00b894;margin-bottom:4px;">Phone Number ID</div>
                <div style="font-size:15px;font-weight:500;color:var(--text-primary);"><?php echo htmlspecialchars((string)$waConfig['phone_number_id'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <div style="font-size:12px;font-weight:600;color:#00b894;margin-bottom:4px;">Verified Name</div>
                <div style="font-size:15px;font-weight:500;color:var(--text-primary);"><?php echo htmlspecialchars((string)$waConfig['verified_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <div style="font-size:12px;font-weight:600;color:#00b894;margin-bottom:4px;">Display Phone Number</div>
                <div style="font-size:15px;font-weight:500;color:var(--text-primary);"><?php echo htmlspecialchars((string)($waConfig['display_phone'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <div style="font-size:12px;font-weight:600;color:#00b894;margin-bottom:4px;">Quality Rating ↗</div>
                <?php
                $qr = $waConfig['quality_rating'] ?? 'UNKNOWN';
                $qrMap = [
                    'GREEN' => ['Green - High quality', '#00c853'],
                    'YELLOW' => ['Yellow - Medium quality', '#ffa726'],
                    'RED' => ['Red - Low quality', '#ff4757'],
                ];
                $qrInfo = $qrMap[strtoupper((string)$qr)] ?? ['Unknown', 'var(--text-muted)'];
                ?>
                <div style="font-size:15px;font-weight:600;color:<?php echo htmlspecialchars($qrInfo[1], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo htmlspecialchars($qrInfo[0], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <!-- Action buttons -->
        <?php $wabaQuery = !empty($waConfig['waba_id']) ? '?waba_id=' . $waConfig['waba_id'] : ''; ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
            <a href="https://business.facebook.com/wa/manage/phone-numbers/<?php echo $wabaQuery; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-edit" style="border-radius:20px;font-size:12px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Update Display Name
            </a>
            <a href="https://business.facebook.com/wa/manage/phone-numbers/<?php echo $wabaQuery; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-edit" style="border-radius:20px;font-size:12px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg> Update Business Profile
            </a>
            <a href="https://business.facebook.com/wa/manage/phone-numbers/<?php echo $wabaQuery; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-edit" style="border-radius:20px;font-size:12px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Update Two-Step Verification Code
            </a>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <form method="post" action="<?php echo app_url('client/settings_whatsapp.php'); ?>" style="display:inline;margin:0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="resync_wa" value="1">
                <button type="submit" class="btn btn-sm" style="border-radius:20px;background:linear-gradient(135deg,#00b894,#00cec9);color:#fff;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Re-sync Phone Numbers
                </button>
            </form>
            <a href="https://business.facebook.com/wa/manage/phone-numbers/<?php echo $wabaQuery; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-edit" style="border-radius:20px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> Manage Phone Numbers ↗
            </a>
            <a href="https://business.facebook.com/wa/manage/<?php echo $wabaQuery; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="border-radius:20px;background:var(--bg-sidebar);color:var(--text-primary);border:1px solid var(--border);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> WhatsApp Manager ↗
            </a>
            <a href="https://business.facebook.com/wa/manage/payments/<?php echo $wabaQuery; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="border-radius:20px;background:var(--bg-sidebar);color:var(--text-primary);border:1px solid var(--border);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg> Manage Payments ↗
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Admin > WhatsApp Onboarding can switch this guide off for every client
$waGuideOn = true;
$gRes = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'wa_onboarding_guide' LIMIT 1");
if ($gRes && ($gRow = $gRes->fetch_assoc())) $waGuideOn = (string)$gRow['setting_value'] !== '0';
?>
<?php if (!$hasBusinessInfo && $waGuideOn): ?>
<!-- Step-by-step guide (show only when not connected, and when the admin has the guide switched on) -->
<div class="form-card" style="max-width:none;margin-bottom:24px;border:1px solid rgba(0,184,148,0.15);background:rgba(0,184,148,0.03);">
    <h3 style="font-size:15px;font-weight:700;color:#00b894;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Setup Guide — Follow These Steps</h3>
    <div class="setup-steps">
        <div class="setup-step">
            <div class="step-number">1</div>
            <div class="step-content">
                <strong>Create a Meta Business App</strong>
                <p>Go to <a href="https://developers.facebook.com" target="_blank" rel="noopener noreferrer" style="color:#00b894;">developers.facebook.com</a> → Create App → Business → Add WhatsApp Product</p>
            </div>
        </div>
        <div class="setup-step">
            <div class="step-number">2</div>
            <div class="step-content">
                <strong>Copy your App ID</strong>
                <p>From the App Dashboard, copy your <strong>App ID</strong> and paste it below</p>
            </div>
        </div>
        <div class="setup-step">
            <div class="step-number">3</div>
            <div class="step-content">
                <strong>Copy Phone Number ID</strong>
                <p>Go to WhatsApp → API Setup → Copy the <strong>Phone Number ID</strong></p>
            </div>
        </div>
        <div class="setup-step">
            <div class="step-number">4</div>
            <div class="step-content">
                <strong>Set up Webhook</strong>
                <p>In Facebook → WhatsApp → Configuration → Callback URL, paste:</p>
                <div class="callback-url-box">
                    <code id="callbackUrl"><?php echo htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8'); ?></code>
                    <button type="button" class="copy-btn" onclick="copyToClipboard('callbackUrl')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy</button>
                </div>
                <p>Keep the <code>company_id</code> query parameter in this URL; it routes messages to this WAAMINI company.</p>
                <?php
                $urlHost = parse_url($callbackUrl, PHP_URL_HOST);
                $isLocal = in_array($urlHost, ['localhost', '127.0.0.1', '::1'], true)
                        || ($urlHost !== false && $urlHost !== null && is_string($urlHost) && str_ends_with($urlHost, '.local'))
                        || ($urlHost !== false && $urlHost !== null && is_string($urlHost) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $urlHost));
                if ($isLocal):
                ?>
                <div style="margin-top:10px;padding:10px 14px;background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.3);border-radius:8px;font-size:12px;color:#e74c3c;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> <strong>Webhook URL is on localhost</strong> — Meta cannot reach it from the internet.<br>
                    To receive messages in the panel, expose this app publicly using a tunnel:<br>
                    <code style="background:rgba(0,0,0,0.15);padding:2px 6px;border-radius:4px;font-size:11px;">ngrok http 8080</code>
                    &nbsp;then update the Callback URL with your ngrok https address.
                </div>
                <?php endif; ?>
                <?php if ($verifyToken): ?>
                <p style="margin-top:8px;">Verify Token:</p>
                <div class="callback-url-box">
                    <code id="verifyTokenText"><?php echo htmlspecialchars($verifyToken, ENT_QUOTES, 'UTF-8'); ?></code>
                    <button type="button" class="copy-btn" onclick="copyToClipboard('verifyTokenText')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="setup-step">
            <div class="step-number">5</div>
            <div class="step-content">
                <strong>Generate Permanent Access Token</strong>
                <p>In Facebook → WhatsApp → API Setup → Generate a <strong>Permanent Token</strong> and paste below</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Config Form (submit button below uses form="wa-save-form" so Re-sync can sit beside it without nesting forms) -->
<form method="POST" id="wa-save-form">
    <?php echo csrf_field(); ?>
    <div class="settings-grid">
        <div class="form-card" style="max-width:none;">
            <h3 class="settings-section-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg> API Credentials</h3>

            <div class="form-group">
                <label>Facebook App ID</label>
                <input type="text" name="fb_app_id" class="form-control" maxlength="32" inputmode="numeric" autocomplete="off"
                       value="<?php echo htmlspecialchars((string)($waConfig['fb_app_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="e.g. 1234567890123456">
            </div>

            <?php
            $__hasSecret = !empty($waConfig['app_secret']);
            $__platformAppId = defined('META_APP_ID') ? (string)META_APP_ID : (string)(getenv('META_APP_ID') ?: '');
            $__ownApp = (string)($waConfig['fb_app_id'] ?? '') !== '' && (string)($waConfig['fb_app_id'] ?? '') !== $__platformAppId;
            ?>
            <div class="form-group">
                <label for="waAppSecret">App Secret <?php if ($__hasSecret): ?><span style="font-size:11px;font-weight:700;color:#059669;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:99px;padding:1px 8px;margin-left:4px;">Saved</span><?php endif; ?></label>
                <input type="password" id="waAppSecret" name="app_secret" class="form-control" maxlength="64" autocomplete="new-password" spellcheck="false"
                       placeholder="<?php echo $__hasSecret ? 'Saved (leave empty to keep it)' : '32-character code from your Meta app'; ?>">
                <small style="color:var(--text-muted);">Meta for Developers &rarr; your app &rarr; App settings &rarr; Basic &rarr; App secret (click Show). WAAMINI uses it to check that incoming messages really come from your app. Stored encrypted, never shown again.</small>
                <?php if (!$__hasSecret && $__ownApp): ?>
                <div style="margin-top:8px;padding:9px 12px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12.5px;line-height:1.5;"><strong>Add your App Secret.</strong> Without it, customer replies and delivery updates sent by your Meta app are rejected.</div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Phone Number ID</label>
                <input type="text" name="phone_number_id" class="form-control" maxlength="32" inputmode="numeric" autocomplete="off"
                       value="<?php echo htmlspecialchars((string)($waConfig['phone_number_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="e.g. 109876543210">
            </div>

            <div class="form-group">
                <label>WhatsApp Business Account ID (WABA ID)</label>
                <input type="text" name="waba_id" class="form-control" maxlength="32" inputmode="numeric" autocomplete="off"
                       value="<?php echo htmlspecialchars((string)($waConfig['waba_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="e.g. 112233445566">
            </div>

            <div class="form-group">
                <label>API Version</label>
                <select name="api_version" class="form-control">
                    <?php foreach ($allowedApiVersions as $v): ?>
                        <option value="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($waConfig['api_version'] ?? $defaultApiVersion) === $v ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-card" style="max-width:none;">
            <h3 class="settings-section-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Access Token</h3>

            <div class="form-group">
                <label>Permanent Access Token</label>
                <textarea name="access_token" class="form-control" rows="5"
                          placeholder="<?php echo $hasStoredToken ? 'Token saved — paste a new token to replace it' : 'Paste your permanent access token from Facebook Developer portal...'; ?>"></textarea>
                <small style="color:var(--text-muted);">Generate this from Facebook → App Dashboard → API Setup. Leave blank to keep your current encrypted token.</small>
            </div>

            <?php if ($hasWaRow): ?>
            <div class="info-box" style="margin-top:12px;">
                <div class="info-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#016df8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg></div>
                <div>
                    <strong>Connection Status:</strong>
                    <?php if ($storedTokenUnreadable): ?>
                        <span style="color:#ef4444;">Saved token cannot be decrypted. Paste a current Meta token above and save.</span><br>
                    <?php else: ?>
                        <?php echo ($waConfig['status'] ?? '') === 'active' && $isConnected ? '<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#10b981"/></svg> Active & Connected' : '<svg width="10" height="10" viewBox="0 0 10 10" style="display:inline;vertical-align:middle"><circle cx="5" cy="5" r="5" fill="#f59e0b"/></svg> Pending Verification'; ?><br>
                    <?php endif; ?>
                    <small>Last updated: <?php
                    $upd = $waConfig['updated_at'] ?? '';
                    echo $upd !== '' ? htmlspecialchars(date('d M Y H:i', strtotime((string)$upd)), ENT_QUOTES, 'UTF-8') : '—';
                    ?></small>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isConnected && !$hasBusinessInfo): ?>
            <div class="info-box" style="margin-top:12px;background:rgba(255,167,38,0.06);border-color:rgba(255,167,38,0.15);">
                <div class="info-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg></div>
                <div style="font-size:13px;color:var(--text-secondary);">
                    After saving, use <strong><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Re-sync Business Info</strong> below to fetch your WhatsApp Business Info.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<div style="margin-top:24px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <button type="submit" form="wa-save-form" name="save_wa" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Configuration</button>
    <?php if ($isConnected): ?>
    <form method="post" action="<?php echo app_url('client/settings_whatsapp.php'); ?>" style="display:inline;margin:0;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="resync_wa" value="1">
        <button type="submit" class="btn btn-edit" style="border-radius:10px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Re-sync Business Info</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($hasBusinessInfo && $verifyToken): ?>
<!-- Webhook Info (collapsed for connected users) -->
<div class="form-card" style="max-width:none;margin-top:24px;">
    <h3 style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Webhook Details</h3>
    <div class="callback-url-box" style="margin-bottom:8px;">
        <code id="callbackUrlFooter"><?php echo htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8'); ?></code>
        <button type="button" class="copy-btn" onclick="copyToClipboard('callbackUrlFooter')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy</button>
    </div>
    <div class="callback-url-box">
        <code id="verifyTokenFooter"><?php echo htmlspecialchars($verifyToken, ENT_QUOTES, 'UTF-8'); ?></code>
        <button type="button" class="copy-btn" onclick="copyToClipboard('verifyTokenFooter')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy</button>
    </div>
</div>
<?php endif; ?>

<style>
.settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.settings-section-title { font-size: 15px; font-weight: 600; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); color: var(--text-primary); }
.info-box { display: flex; gap: 10px; padding: 14px; background: rgba(0,184,148,0.06); border: 1px solid rgba(0,184,148,0.12); border-radius: 10px; font-size: 13px; color: var(--text-secondary); line-height: 1.5; }
.info-box .info-icon { font-size: 18px; flex-shrink: 0; }
.info-box strong { color: var(--text-primary); }

.setup-steps { display: flex; flex-direction: column; gap: 16px; }
.setup-step { display: flex; gap: 14px; align-items: flex-start; }
.step-number { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #00b894, #00cec9); color: #fff; font-weight: 800; font-size: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.step-content { flex: 1; }
.step-content strong { color: var(--text-primary); font-size: 14px; display: block; margin-bottom: 4px; }
.step-content p { font-size: 13px; color: var(--text-secondary); margin: 0; line-height: 1.6; }

.callback-url-box { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding: 8px 12px; background: var(--bg-dark); border-radius: 8px; border: 1px solid var(--border); }
.callback-url-box code { flex: 1; min-width: 0; font-size: 12px; color: #00b894; overflow-wrap: anywhere; word-break: normal; }
.copy-btn { background: rgba(0,184,148,0.1); border: 1px solid rgba(0,184,148,0.2); color: #00b894; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; white-space: nowrap; }
.copy-btn:hover { background: rgba(0,184,148,0.2); }
.copy-btn { flex: none; display: inline-flex; align-items: center; gap: 5px; }
.copy-btn svg { flex: none; }
.copy-btn.is-copied { background: #00b894; border-color: #00b894; color: #fff; }

@media (max-width: 900px) { .settings-grid { grid-template-columns: 1fr; } }
</style>

<script>
function copyToClipboard(elementId) {
    // Copies the text of #elementId; the button right after it shows "Copied" for 2 s, then gets its own icon + label back.
    const el = document.getElementById(elementId);
    if (!el) return;
    const text = el.textContent.trim();
    const btn = el.nextElementSibling;
    const CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    function done() {
        if (!btn) return;
        if (!btn.dataset.label) btn.dataset.label = btn.innerHTML;
        btn.innerHTML = CHECK + ' Copied';
        btn.classList.add('is-copied');
        clearTimeout(btn._copyTimer);
        btn._copyTimer = setTimeout(function () { btn.innerHTML = btn.dataset.label; btn.classList.remove('is-copied'); }, 2000);
    }
    function fallback() {   // older browsers / non-https: copy through a hidden textarea
        const ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { if (document.execCommand('copy')) done(); } catch (e) {}
        document.body.removeChild(ta);
    }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, fallback);
    else fallback();
}
</script>

<?php include '../includes/client_footer.php'; ?>

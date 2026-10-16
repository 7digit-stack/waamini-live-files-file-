<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';

$pageTitle   = 'API & Webhook';
$currentPage = 'settings_api';

$success = '';
$error   = '';

/** Public base URL scheme. */
function waamini_settings_api_public_scheme(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return $https ? 'https' : 'http';
}

/** Ensure legacy databases have webhook_url on api_keys. */
function waamini_ensure_api_keys_webhook_column(mysqli $conn): void {
    static $done = false;
    if ($done || !($conn instanceof mysqli)) return;
    $check = $conn->query("SHOW COLUMNS FROM `api_keys` LIKE 'webhook_url'");
    if ($check && $check->num_rows === 0) {
        $conn->query('ALTER TABLE `api_keys` ADD COLUMN `webhook_url` VARCHAR(512) NULL DEFAULT NULL');
    }
    $done = true;
}

waamini_ensure_api_keys_webhook_column($conn);

function waamini_load_active_api_key(mysqli $conn, int $companyId): ?array {
    $stmt = $conn->prepare('SELECT * FROM api_keys WHERE company_id=? AND is_active=1 LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

$apiKey       = waamini_load_active_api_key($conn, $companyId);
$hasKey       = is_array($apiKey);
$webhookValue = $hasKey ? (string)($apiKey['webhook_url'] ?? '') : '';

// Generate new key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_key'])) {
    csrf_verify();
    $disableStmt = $conn->prepare('UPDATE api_keys SET is_active=0 WHERE company_id=?');
    if ($disableStmt) {
        $disableStmt->bind_param('i', $companyId);
        $disableStmt->execute();
    }
    $key  = 'waamini_' . bin2hex(random_bytes(24));
    $stmt = $conn->prepare('INSERT INTO api_keys (company_id, api_key, is_active) VALUES (?,?,1)');
    $stmt->bind_param('is', $companyId, $key);
    if ($stmt->execute()) {
        $success = 'New API key generated!';
        $apiKey  = waamini_load_active_api_key($conn, $companyId);
        $hasKey  = true;
    } else {
        $error = 'Could not generate API key.';
    }
    $stmt->close();
}

// Save webhook URL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_webhook'])) {
    csrf_verify();
    $webhookUrl = filter_var(trim((string)$_POST['webhook_url']), FILTER_VALIDATE_URL);
    if ($webhookUrl === false && !empty($_POST['webhook_url'])) {
        $error = 'Invalid Webhook URL format.';
    } else {
        if ($hasKey) {
            $stmt = $conn->prepare('UPDATE api_keys SET webhook_url=? WHERE id=?');
            $stmt->bind_param('si', $webhookUrl, $apiKey['id']);
        } else {
            $key  = 'waamini_' . bin2hex(random_bytes(24));
            $stmt = $conn->prepare('INSERT INTO api_keys (company_id, api_key, webhook_url, is_active) VALUES (?,?,?,1)');
            $stmt->bind_param('iss', $companyId, $key, $webhookUrl);
        }
        if ($stmt->execute()) {
            $success      = 'Webhook URL updated!';
            $apiKey       = waamini_load_active_api_key($conn, $companyId);
            $hasKey       = true;
            $webhookValue = $webhookUrl;
        } else {
            $error = 'Failed to save webhook.';
        }
        $stmt->close();
    }
}

// Test Webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_webhook'])) {
    csrf_verify();
    if (empty($webhookValue)) {
        $error = 'Please save a Webhook URL first.';
    } else {
        $samplePayload = [
            'event'      => 'test_webhook',
            'company_id' => $companyId,
            'timestamp'  => time(),
            'message'    => 'This is a test notification from WAAMINI.'
        ];
        $ch = curl_init($webhookValue);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($samplePayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            $success = "Test successful! Webhook returned HTTP $httpCode.";
        } else {
            $error = "Test failed. Webhook returned HTTP $httpCode (Response: " . substr(strip_tags((string)$response), 0, 50) . ")";
        }
    }
}

$scheme     = waamini_settings_api_public_scheme();
$host       = preg_replace('/[^a-zA-Z0-9.:\[\]_-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$baseApiUrl = $scheme . '://' . $host . app_url('api');

/* â”€â”€ Connection status â”€â”€ */
$co = [];
try {
    $st = $conn->prepare("SELECT im_crm_key, fb_page_id, fb_page_access_token, google_sheet_webhook, zapier_webhook_url FROM companies WHERE id=? LIMIT 1");
    if (!$st) {
        $st = $conn->prepare("SELECT im_crm_key, fb_page_id, fb_page_access_token, google_sheet_webhook FROM companies WHERE id=? LIMIT 1");
    }
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $co = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
    }
} catch (\Throwable $e) {}

include '../includes/client_header.php';
?>
<style>
/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   API & Webhook â€” CRM Connection Hub
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.ah-page {
  --ah-green:  #008767;
  --ah-blue:   #2563eb;
  --ah-purple: #7c3aed;
  --ah-amber:  #d97706;
  --ah-red:    #dc2626;
  --ah-border: #e2e8f0;
  --ah-surface:#f8fafc;
  font-family: Inter, "Segoe UI", system-ui, sans-serif;
  color: #0f172a;
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding-bottom: 32px;
}
.ah-page *, .ah-page *::before, .ah-page *::after { box-sizing: border-box; }

/* â”€â”€ Card base â”€â”€ */
.ah-card {
  background: #fff;
  border: 1px solid var(--ah-border);
  border-radius: 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
}

/* â”€â”€ Hero header â”€â”€ */
.ah-hero {
  padding: 24px 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
}
.ah-hero-l { display: flex; align-items: center; gap: 16px; }
.ah-hero-ico {
  width: 52px; height: 52px; border-radius: 14px;
  background: #eff6ff; border: 1px solid #bfdbfe;
  color: var(--ah-blue); display: grid; place-items: center; flex-shrink: 0;
}
.ah-hero-ico svg { width: 24px; height: 24px; }
.ah-hero h1 { margin: 0 0 3px; font-size: 20px; font-weight: 800; letter-spacing: -.02em; }
.ah-hero p  { margin: 0; font-size: 13.5px; color: #64748b; }
.ah-key-pill {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 7px 14px; border-radius: 10px;
  background: #f8fafc; border: 1px solid var(--ah-border);
  font-size: 12.5px; font-weight: 600; color: #475569;
  max-width: 340px; overflow: hidden;
}
.ah-key-pill code {
  font-family: ui-monospace, monospace; font-size: 12px;
  color: var(--ah-blue); white-space: nowrap; overflow: hidden;
  text-overflow: ellipsis; max-width: 220px; display: block;
}

/* â”€â”€ Flow diagram â”€â”€ */
.ah-flow {
  padding: 22px 28px;
  display: flex;
  align-items: center;
  gap: 0;
  overflow-x: auto;
}
.ah-flow-node {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  flex-shrink: 0; min-width: 90px;
}
.ah-flow-ico {
  width: 52px; height: 52px; border-radius: 14px;
  display: grid; place-items: center;
  box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.ah-flow-ico svg { width: 22px; height: 22px; }
.ah-flow-ico.crm   { background: #eff6ff; color: var(--ah-blue);   border: 1.5px solid #bfdbfe; }
.ah-flow-ico.wh    { background: #ecfdf5; color: var(--ah-green);  border: 1.5px solid #a7f3d0; }
.ah-flow-ico.wa    { background: #fff7ed; color: var(--ah-amber);  border: 1.5px solid #fed7aa; }
.ah-flow-ico.sheet { background: #f0fdf4; color: #16a34a;          border: 1.5px solid #bbf7d0; }
.ah-flow-lbl { font-size: 11.5px; font-weight: 700; color: #475569; text-align: center; }
.ah-flow-arrow {
  flex: 1; min-width: 32px;
  display: flex; align-items: center; justify-content: center;
  color: #cbd5e1; font-size: 20px; padding: 0 4px;
  margin-bottom: 20px;
}
.ah-flow-arrow svg { width: 28px; height: 28px; }

/* â”€â”€ Alerts â”€â”€ */
.ah-alert {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 12px;
  font-size: 13.5px; font-weight: 600;
}
.ah-alert svg { width: 16px; height: 16px; flex-shrink: 0; }
.ah-alert.ok  { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.ah-alert.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* â”€â”€ Two column â”€â”€ */
.ah-split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 18px;
}

/* â”€â”€ Section heading â”€â”€ */
.ah-section-head {
  display: flex; align-items: center; gap: 10px;
  padding: 18px 22px 0; margin-bottom: 16px;
}
.ah-section-head h2 {
  margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;
}
.ah-section-head p { margin: 0; font-size: 12.5px; color: #64748b; }
.ah-sh-ico {
  width: 34px; height: 34px; border-radius: 9px;
  display: grid; place-items: center; flex-shrink: 0;
}
.ah-sh-ico svg { width: 16px; height: 16px; }

/* â”€â”€ API Key block â”€â”€ */
.ah-key-block { padding: 0 22px 20px; }
.ah-code-row {
  display: flex; align-items: center; gap: 8px;
  background: var(--ah-surface); border: 1px solid var(--ah-border);
  border-radius: 10px; padding: 10px 12px; margin-bottom: 10px;
}
.ah-code-row code {
  flex: 1; font-family: ui-monospace, monospace; font-size: 12.5px;
  color: var(--ah-blue); word-break: break-all; min-width: 0;
}
.ah-no-key {
  text-align: center; padding: 18px; border: 1.5px dashed var(--ah-border);
  border-radius: 10px; font-size: 13px; color: #94a3b8;
  background: var(--ah-surface); margin-bottom: 10px;
}
.ah-copy-btn {
  padding: 4px 12px; border-radius: 7px; font-size: 11.5px; font-weight: 700;
  border: 1px solid var(--ah-border); background: #fff; color: #475569;
  cursor: pointer; flex-shrink: 0; transition: all .15s;
  font-family: inherit;
}
.ah-copy-btn:hover { background: #eff6ff; color: var(--ah-blue); border-color: #bfdbfe; }
.ah-copy-btn.copied { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }

/* â”€â”€ Buttons â”€â”€ */
.ah-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px; border-radius: 10px; font-size: 13px; font-weight: 700;
  border: none; cursor: pointer; text-decoration: none; font-family: inherit;
  transition: all .2s; width: 100%; justify-content: center;
}
.ah-btn svg { width: 15px; height: 15px; flex-shrink: 0; }
.ah-btn-primary { background: var(--ah-blue); color: #fff; }
.ah-btn-primary:hover { background: #1d4ed8; }
.ah-btn-success { background: var(--ah-green); color: #fff; }
.ah-btn-success:hover { background: #065f46; }
.ah-btn-outline {
  background: #fff; color: #475569;
  border: 1px solid var(--ah-border);
}
.ah-btn-outline:hover { background: var(--ah-surface); border-color: #94a3b8; }
.ah-btn-amber { background: var(--ah-amber); color: #fff; }
.ah-btn-amber:hover { background: #b45309; }
.ah-btn + .ah-btn { margin-top: 8px; }

/* â”€â”€ Webhook form â”€â”€ */
.ah-wh-block { padding: 0 22px 20px; display: flex; flex-direction: column; gap: 10px; }
.ah-input {
  width: 100%; background: var(--ah-surface);
  border: 1px solid var(--ah-border); border-radius: 10px;
  padding: 10px 13px; font-size: 13.5px; color: #0f172a;
  font-family: inherit; outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.ah-input:focus { background: #fff; border-color: var(--ah-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.ah-input::placeholder { color: #94a3b8; }

/* â”€â”€ CRM integration cards â”€â”€ */
.ah-crm-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 14px;
  padding: 0 22px 22px;
}
.ah-crm-card {
  border: 1px solid var(--ah-border); border-radius: 14px;
  padding: 16px; display: flex; flex-direction: column; gap: 10px;
  transition: box-shadow .2s, transform .2s; background: #fff;
}
.ah-crm-card:hover { box-shadow: 0 4px 16px -4px rgba(0,0,0,.1); transform: translateY(-1px); }
.ah-crm-card-top { display: flex; align-items: center; justify-content: space-between; }
.ah-crm-ico {
  width: 40px; height: 40px; border-radius: 11px;
  display: grid; place-items: center; flex-shrink: 0;
}
.ah-crm-ico svg { width: 20px; height: 20px; }
.ah-crm-ico.zapier   { background: #fff0eb; color: #ff4a00; border: 1.5px solid #ffd0bf; }
.ah-crm-ico.pabbly   { background: #f0f4ff; color: #4f46e5; border: 1.5px solid #c7d2fe; }
.ah-crm-ico.n8n      { background: #fff8e1; color: #f59e0b; border: 1.5px solid #fde68a; }
.ah-crm-ico.fb       { background: #eff6ff; color: #1877f2; border: 1.5px solid #bfdbfe; }
.ah-crm-ico.sheets   { background: #f0fdf4; color: #16a34a; border: 1.5px solid #bbf7d0; }
.ah-crm-ico.custom   { background: #f5f3ff; color: var(--ah-purple); border: 1.5px solid #ddd6fe; }
.ah-status-dot {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 9px; border-radius: 99px;
  font-size: 11px; font-weight: 700;
}
.ah-status-dot::before {
  content: ''; width: 7px; height: 7px; border-radius: 50%;
}
.ah-status-dot.connected    { background: #ecfdf5; color: #065f46; }
.ah-status-dot.connected::before { background: #10b981; }
.ah-status-dot.disconnected { background: #f1f5f9; color: #64748b; }
.ah-status-dot.disconnected::before { background: #94a3b8; }
.ah-crm-name { font-size: 14px; font-weight: 800; color: #0f172a; margin: 0; }
.ah-crm-desc { font-size: 12.5px; color: #64748b; line-height: 1.5; margin: 0; }
.ah-crm-url-box {
  background: var(--ah-surface); border: 1px solid var(--ah-border);
  border-radius: 8px; padding: 8px 10px;
  font-family: ui-monospace, monospace; font-size: 11px;
  color: #475569; word-break: break-all; line-height: 1.5;
}
.ah-crm-url-box strong { color: var(--ah-blue); }

/* â”€â”€ Docs / endpoint list â”€â”€ */
.ah-endpoint {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--ah-border);
}
.ah-endpoint:last-child { border-bottom: 0; }
.ah-ep-badge {
  padding: 2px 8px; border-radius: 5px;
  font-size: 10px; font-weight: 800; letter-spacing: .04em;
  text-transform: uppercase; flex-shrink: 0; margin-top: 2px;
}
.ah-ep-badge.post { background: #eff6ff; color: var(--ah-blue); border: 1px solid #bfdbfe; }
.ah-ep-badge.get  { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.ah-ep-name { font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 3px; }
.ah-ep-url  {
  font-family: ui-monospace, monospace; font-size: 11.5px;
  color: var(--ah-blue); word-break: break-all;
  background: var(--ah-surface); border: 1px solid var(--ah-border);
  border-radius: 6px; padding: 4px 8px; display: block; margin-bottom: 5px;
}
.ah-ep-payload {
  font-size: 11.5px; color: #64748b; font-family: ui-monospace, monospace;
  background: var(--ah-surface); border-radius: 5px; padding: 4px 8px;
  display: block;
}

/* â”€â”€ Step guide â”€â”€ */
.ah-steps { display: flex; flex-direction: column; gap: 0; padding: 0 22px 20px; }
.ah-step {
  display: flex; gap: 14px; padding: 12px 0;
  border-bottom: 1px solid var(--ah-border);
}
.ah-step:last-child { border-bottom: 0; }
.ah-step-num {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--ah-blue); color: #fff;
  font-size: 11.5px; font-weight: 800;
  display: grid; place-items: center; flex-shrink: 0; margin-top: 1px;
}
.ah-step h4 { margin: 0 0 2px; font-size: 13px; font-weight: 700; color: #0f172a; }
.ah-step p  { margin: 0; font-size: 12.5px; color: #64748b; line-height: 1.5; }
.ah-step code {
  font-family: ui-monospace, monospace; font-size: 11.5px;
  background: #eff6ff; color: var(--ah-blue);
  padding: 1px 5px; border-radius: 4px;
}

/* â”€â”€ Responsive â”€â”€ */
@media (max-width: 860px) {
  .ah-split { grid-template-columns: 1fr; }
  .ah-crm-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
@media (max-width: 520px) {
  .ah-crm-grid { grid-template-columns: 1fr; }
  .ah-hero { padding: 18px 20px; }
  .ah-flow { padding: 16px 20px; }
}
</style>

<?php
$_svgi = static function (string $p, int $s = 20): string {
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

// derive connection statuses
$fbConn     = !empty($co['fb_page_id']) && !empty($co['fb_page_access_token']);
$sheetsConn = !empty($co['google_sheet_webhook']);
$indiaConn  = !empty($co['im_crm_key']);
$zapierConn = !empty($webhookValue);
?>

<div class="ah-page">

  <!-- â”€â”€ Hero â”€â”€ -->
  <div class="ah-card ah-hero">
    <div class="ah-hero-l">
      <div class="ah-hero-ico">
        <?php echo $_svgi('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'); ?>
      </div>
      <div>
        <h1>API &amp; Webhook Hub</h1>
        <p>Connect your CRMs, automation tools and custom apps to your WhatsApp gateway.</p>
      </div>
    </div>
    <?php if ($hasKey): ?>
    <div class="ah-key-pill">
      <?php echo $_svgi('<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>', 15); ?>
      <code><?php echo htmlspecialchars($apiKey['api_key']); ?></code>
      <button class="ah-copy-btn" onclick="ahCopy(this, <?php echo htmlspecialchars(json_encode($apiKey['api_key'])); ?>)">Copy</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- â”€â”€ Alerts â”€â”€ -->
  <?php if ($success): ?>
  <div class="ah-alert ok"><?php echo $_svgi('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>'); ?> <?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="ah-alert err"><?php echo $_svgi('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>'); ?> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <!-- â”€â”€ Data flow diagram â”€â”€ -->
  <div class="ah-card">
    <div class="ah-section-head">
      <div class="ah-sh-ico" style="background:#eff6ff;color:#2563eb;"><?php echo $_svgi('<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>'); ?></div>
      <div>
        <h2>How your data flows</h2>
        <p>Every lead, message and event moves through WAAMINI and lands in WhatsApp + your CRM.</p>
      </div>
    </div>
    <div class="ah-flow">
      <div class="ah-flow-node">
        <div class="ah-flow-ico crm"><?php echo $_svgi('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></div>
        <span class="ah-flow-lbl">Your CRM /<br>Zapier / App</span>
      </div>
      <div class="ah-flow-arrow"><?php echo $_svgi('<path d="m9 18 6-6-6-6"/>', 28); ?></div>
      <div class="ah-flow-node">
        <div class="ah-flow-ico wh"><?php echo $_svgi('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'); ?></div>
        <span class="ah-flow-lbl">WAAMINI<br>Webhook API</span>
      </div>
      <div class="ah-flow-arrow"><?php echo $_svgi('<path d="m9 18 6-6-6-6"/>', 28); ?></div>
      <div class="ah-flow-node">
        <div class="ah-flow-ico wa">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>
        </div>
        <span class="ah-flow-lbl">WhatsApp<br>Message sent</span>
      </div>
      <div class="ah-flow-arrow"><?php echo $_svgi('<path d="m9 18 6-6-6-6"/>', 28); ?></div>
      <div class="ah-flow-node">
        <div class="ah-flow-ico sheet"><?php echo $_svgi('<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>'); ?></div>
        <span class="ah-flow-lbl">Contact in<br>CRM + Sheets</span>
      </div>
    </div>
  </div>

  <!-- â”€â”€ Key + Webhook â”€â”€ -->
  <div class="ah-split">

    <!-- API Key -->
    <div class="ah-card">
      <div class="ah-section-head">
        <div class="ah-sh-ico" style="background:#fff7ed;color:#d97706;"><?php echo $_svgi('<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>'); ?></div>
        <div>
          <h2>API Key</h2>
          <p>Send securely in the API key header over HTTPS</p>
        </div>
      </div>
      <div class="ah-key-block">
        <?php if ($hasKey): ?>
        <div class="ah-code-row">
          <code id="ahApiKey"><?php echo htmlspecialchars($apiKey['api_key']); ?></code>
          <button class="ah-copy-btn" onclick="ahCopy(this, <?php echo htmlspecialchars(json_encode($apiKey['api_key'])); ?>)">Copy</button>
        </div>
        <p style="font-size:11.5px;color:#94a3b8;margin:0 0 14px;">
          Company ID: <strong style="color:#475569;"><?php echo (int)$companyId; ?></strong>
          &nbsp;Â·&nbsp; Generated <?php echo date('d M Y', strtotime($apiKey['created_at'])); ?>
        </p>
        <?php else: ?>
        <div class="ah-no-key"><?php echo $_svgi('<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>'); ?> No API key yet</div>
        <?php endif; ?>
        <form method="POST">
          <?php echo csrf_field(); ?>
          <button type="submit" name="generate_key" class="ah-btn ah-btn-amber"
                  onclick="return confirm('<?php echo $hasKey ? 'Regenerating will invalidate your current key immediately. Proceed?' : 'Generate a new API key?'; ?>')">
            <?php echo $_svgi('<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>'); ?>
            <?php echo $hasKey ? 'Regenerate Key' : 'Generate API Key'; ?>
          </button>
        </form>
      </div>
    </div>

    <!-- Outbound Webhook -->
    <div class="ah-card">
      <div class="ah-section-head">
        <div class="ah-sh-ico" style="background:#ecfdf5;color:#008767;"><?php echo $_svgi('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'); ?></div>
        <div>
          <h2>Outbound Webhook</h2>
          <p>Save a destination and send a test payload</p>
        </div>
      </div>
      <div class="ah-wh-block">
        <form method="POST" id="ahWebhookForm">
          <?php echo csrf_field(); ?>
          <input type="url" name="webhook_url" class="ah-input"
                 placeholder="https://yourdomain.com/waamini-events"
                 value="<?php echo htmlspecialchars($webhookValue); ?>">
          <button type="submit" name="save_webhook" class="ah-btn ah-btn-success">
            <?php echo $_svgi('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'); ?>
            Save Webhook URL
          </button>
        </form>
        <form method="POST">
          <?php echo csrf_field(); ?>
          <button type="submit" name="test_webhook" class="ah-btn ah-btn-outline"
                  <?php echo empty($webhookValue) ? 'disabled title="Save a webhook URL first"' : ''; ?>>
            <?php echo $_svgi('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>'); ?>
            Send Test Payload
          </button>
        </form>
        <?php if ($webhookValue): ?>
        <div style="font-size:12px;color:#64748b;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
          <?php echo $_svgi('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 14); ?>
          <strong style="color:#008767;">Destination saved</strong> â€” only the test action sends a payload; automatic event delivery is not available yet:
          <code style="font-family:monospace;font-size:11px;color:#2563eb;word-break:break-all;"><?php echo htmlspecialchars($webhookValue); ?></code>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- â”€â”€ CRM Connectors â”€â”€ -->
  <div class="ah-card">
    <div class="ah-section-head">
      <div class="ah-sh-ico" style="background:#f5f3ff;color:#7c3aed;"><?php echo $_svgi('<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>'); ?></div>
      <div>
        <h2>CRM &amp; Automation Connectors</h2>
        <p>Import CRM leads with the universal endpoint, or use the separate message endpoint when you explicitly want to send WhatsApp messages.</p>
      </div>
    </div>

    <div class="ah-crm-grid">

      <!-- Zapier -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico zapier">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm0 19.2c-3.976 0-7.2-3.224-7.2-7.2S8.024 4.8 12 4.8 19.2 8.024 19.2 12 15.976 19.2 12 19.2z"/></svg>
          </div>
          <span class="ah-status-dot <?php echo $zapierConn ? 'connected' : 'disconnected'; ?>"><?php echo $zapierConn ? 'Active' : 'Not set'; ?></span>
        </div>
        <p class="ah-crm-name">Zapier / Pabbly / Make</p>
        <p class="ah-crm-desc">This route queues a WhatsApp message; it is not a lead-only import. For CRM leads without sending a message, use the universal lead-sync endpoint below.</p>
        <div class="ah-crm-url-box">
          <strong>POST</strong> <?php echo htmlspecialchars($baseApiUrl); ?>/zapier_catch.php<br>
          ?company_id=<strong><?php echo (int)$companyId; ?></strong><br>
          Header: <code>X-WAAMINI-API-Key: YOUR_API_KEY</code>
        </div>
        <div style="font-size:11.5px;color:#64748b;">
          Body: <code style="font-family:monospace;background:#f8fafc;padding:1px 5px;border-radius:4px;">{"phone":"91XXXXXXXXXX","message":"Hi!"}</code><br>
          Requires <code>X-WAAMINI-Signature</code>: hex HMAC-SHA256 of the exact JSON body, keyed with your API key. The legacy <code>api_key</code> URL parameter remains supported.
        </div>
      </div>

      <!-- Universal CRM lead sync -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico custom">
            <?php echo $_svgi('<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/>'); ?>
          </div>
          <span class="ah-status-dot <?php echo $hasKey ? 'connected' : 'disconnected'; ?>"><?php echo $hasKey ? 'API ready' : 'Needs API key'; ?></span>
        </div>
        <p class="ah-crm-name">Any CRM / Custom Lead Sync</p>
        <p class="ah-crm-desc">Import leads from HubSpot, Zoho, Salesforce, Pipedrive, Freshsales, Monday.com, or any custom CRM through Zapier, Make, n8n, or a direct HTTPS request. This is an API connector, not a native OAuth app.</p>
        <div class="ah-crm-url-box">
          <strong>POST</strong> <?php echo htmlspecialchars($baseApiUrl); ?>/crm_lead_sync.php<br>
          Header: <code>Authorization: Bearer YOUR_API_KEY</code> (or <code>X-WAAMINI-API-Key: YOUR_API_KEY</code>)<br>
          JSON: <code>{"phone":"+919876543210","name":"New lead","email":"lead@example.com","source":"HubSpot"}</code>
        </div>
        <div style="font-size:11.5px;color:#64748b;">Upserts by phone within your company; accepts up to 100 contacts per request. Lead import does not send a WhatsApp message.</div>
      </div>

      <!-- Facebook Lead Ads -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico fb">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
          </div>
          <span class="ah-status-dot <?php echo $fbConn ? 'connected' : 'disconnected'; ?>"><?php echo $fbConn ? 'Connected' : 'Not set'; ?></span>
        </div>
        <p class="ah-crm-name">Facebook Lead Ads</p>
        <p class="ah-crm-desc">Subscribe your Facebook Page to import Lead Ads into contacts. WhatsApp auto-replies are optional and must be configured separately.</p>
        <div class="ah-crm-url-box">
          <strong>Webhook URL:</strong><br>
          <?php echo htmlspecialchars($baseApiUrl); ?>/fb_leads_webhook.php?cid=<strong><?php echo (int)$companyId; ?></strong>
        </div>
        <a href="settings_fb_leads.php" class="ah-btn ah-btn-primary" style="font-size:12.5px;padding:7px 14px;">
          <?php echo $fbConn ? 'Manage connection' : 'Set up now'; ?> â†’
        </a>
      </div>

      <!-- IndiaMART -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico n8n">
            <?php echo $_svgi('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?>
          </div>
          <span class="ah-status-dot <?php echo $indiaConn ? 'connected' : 'disconnected'; ?>"><?php echo $indiaConn ? 'Connected' : 'Not set'; ?></span>
        </div>
        <p class="ah-crm-name">IndiaMART</p>
        <p class="ah-crm-desc">Pull buyer enquiries from IndiaMART; you can configure a WhatsApp auto-reply for newly imported leads.</p>
        <div class="ah-crm-url-box">
          Syncs via CRM API key every 15 minutes.<br>
          Leads added to contacts automatically.
        </div>
        <a href="settings_indiamart.php" class="ah-btn ah-btn-primary" style="font-size:12.5px;padding:7px 14px;">
          <?php echo $indiaConn ? 'Manage connection' : 'Connect IndiaMART'; ?> â†’
        </a>
      </div>

      <!-- Google Sheets -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico sheets">
            <?php echo $_svgi('<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>'); ?>
          </div>
          <span class="ah-status-dot <?php echo $sheetsConn ? 'connected' : 'disconnected'; ?>"><?php echo $sheetsConn ? 'Connected' : 'Not set'; ?></span>
        </div>
        <p class="ah-crm-name">Google Sheets</p>
        <p class="ah-crm-desc">Push contacts and sales data to Google Sheets via Apps Script webhook automatically.</p>
        <div class="ah-crm-url-box">
          Apps Script â†’ Deploy as Web App â†’<br>
          Paste URL in Settings â†’ Google Sheets.
        </div>
        <a href="settings_gsheets.php" class="ah-btn ah-btn-primary" style="font-size:12.5px;padding:7px 14px;">
          <?php echo $sheetsConn ? 'Manage connection' : 'Connect Sheets'; ?> â†’
        </a>
      </div>

      <!-- eCommerce -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico pabbly">
            <?php echo $_svgi('<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>'); ?>
          </div>
          <span class="ah-status-dot disconnected">Inbound</span>
        </div>
        <p class="ah-crm-name">eCommerce / WooCommerce</p>
        <p class="ah-crm-desc">Import an order's customer as a contact using the universal CRM endpoint. There is no separate eCommerce order-trigger endpoint.</p>
        <div class="ah-crm-url-box">
          <strong>POST</strong> <?php echo htmlspecialchars($baseApiUrl); ?>/crm_lead_sync.php<br>
          Map the customer's phone, name, email, and order source.
        </div>
      </div>

      <!-- Custom / n8n -->
      <div class="ah-crm-card">
        <div class="ah-crm-card-top">
          <div class="ah-crm-ico custom">
            <?php echo $_svgi('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'); ?>
          </div>
          <span class="ah-status-dot disconnected">Custom</span>
        </div>
        <p class="ah-crm-name">n8n / Custom App</p>
        <p class="ah-crm-desc">Use a custom HTTP Request node to import or update contacts. This route does not message the lead.</p>
        <div class="ah-crm-url-box">
          POST <?php echo htmlspecialchars($baseApiUrl); ?>/crm_lead_sync.php<br>
          Headers: <code style="font-size:10.5px;">Content-Type: application/json</code><br>
          <code style="font-size:10.5px;">Authorization: Bearer YOUR_API_KEY</code>
        </div>
      </div>

    </div>
  </div>

  <!-- â”€â”€ Quick-start steps + Active endpoints â”€â”€ -->
  <div class="ah-split">

    <!-- Steps -->
    <div class="ah-card">
      <div class="ah-section-head">
        <div class="ah-sh-ico" style="background:#ecfdf5;color:#008767;"><?php echo $_svgi('<path d="M21 10.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.5"/><path d="m9 11 3 3L22 4"/>'); ?></div>
        <div><h2>Quick-start</h2><p>Connect your first tool in 3 minutes</p></div>
      </div>
      <div class="ah-steps">
        <div class="ah-step">
          <div class="ah-step-num">1</div>
          <div>
            <h4>Generate your API key</h4>
            <p>Click <strong>Generate API Key</strong> above. Copy it and keep it private. Use HTTPS for every request.</p>
          </div>
        </div>
        <div class="ah-step">
          <div class="ah-step-num">2</div>
          <div>
            <h4>Pick your endpoint</h4>
            <p>For CRM lead imports, use <code>/api/crm_lead_sync.php</code>. For an explicit WhatsApp send, use <code>/api/zapier_catch.php</code> with its required signature.</p>
          </div>
        </div>
        <div class="ah-step">
          <div class="ah-step-num">3</div>
          <div>
            <h4>Set the endpoint's authentication header</h4>
            <p>For lead sync, send <code>Authorization: Bearer YOUR_KEY</code>. The message route uses <code>company_id</code> in the URL and your API key in a header.</p>
          </div>
        </div>
        <div class="ah-step">
          <div class="ah-step-num">4</div>
          <div>
            <h4>POST a lead or a batch of leads</h4>
            <p>Send <code>{"phone":"+919876543210","name":"New lead","source":"HubSpot"}</code>, or use <code>{"contacts":[...]}</code> for up to 100 leads. Phone is required.</p>
          </div>
        </div>
        <div class="ah-step">
          <div class="ah-step-num">5</div>
          <div>
            <h4>Set outbound webhook (optional)</h4>
            <p>Use the test button to check your destination. Automatic event delivery is not currently enabled.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Active endpoints -->
    <div class="ah-card">
      <div class="ah-section-head">
        <div class="ah-sh-ico" style="background:#eff6ff;color:#2563eb;"><?php echo $_svgi('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>'); ?></div>
        <div><h2>Integration Endpoints</h2><p>Authentication is shown for each endpoint</p></div>
      </div>

      <?php
      $eps = [
        ['POST', 'Any CRM lead import', 'crm_lead_sync.php', '{"phone":"+919876543210","name":"New lead","source":"HubSpot"}', 'Authorization: Bearer YOUR_API_KEY'],
        ['POST', 'Zapier / Pabbly / Make message send', 'zapier_catch.php?company_id=' . (int)$companyId, '{"phone":"91XX","message":"Hi!"}', 'X-WAAMINI-API-Key and X-WAAMINI-Signature headers'],
        ['POST', 'Facebook Lead Ads webhook', 'fb_leads_webhook.php?cid=' . (int)$companyId, '', 'Configure in Facebook Lead Ads settings'],
        ['GET', 'API health check', 'health.php', '', 'No API key required'],
      ];
      foreach ($eps as [$method, $name, $path, $body, $auth]):
        $fullUrl = htmlspecialchars($baseApiUrl . '/' . $path);
      ?>
      <div class="ah-endpoint">
        <span class="ah-ep-badge <?php echo strtolower($method); ?>"><?php echo $method; ?></span>
        <div style="min-width:0;flex:1;">
          <div class="ah-ep-name"><?php echo htmlspecialchars($name); ?></div>
          <span class="ah-ep-url" id="ahEp<?php echo md5($path); ?>"><?php echo $fullUrl; ?></span>
          <?php if ($body): ?>
          <span class="ah-ep-payload"><?php echo htmlspecialchars($body); ?></span>
          <?php endif; ?>
          <span class="ah-ep-payload"><?php echo htmlspecialchars($auth); ?></span>
        </div>
        <button class="ah-copy-btn" style="flex-shrink:0;margin-top:2px;"
                onclick="ahCopy(this, '<?php echo addslashes($baseApiUrl . '/' . $path); ?>')">Copy</button>
      </div>
      <?php endforeach; ?>
    </div>

  </div>

</div>

<script>
function ahCopy(btn, text) {
  function onDone() {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    btn.classList.add('copied');
    setTimeout(function() { btn.textContent = orig; btn.classList.remove('copied'); }, 2000);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(onDone).catch(fallback);
  } else { fallback(); }
  function fallback() {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;left:-9999px';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); onDone(); } catch(e) {}
    document.body.removeChild(ta);
  }
}
</script>

<?php include '../includes/client_footer.php'; ?>

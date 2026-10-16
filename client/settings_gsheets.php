<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
if (!has_feature('google_sheets')) {
    header('Location: dashboard.php');
    exit();
}

$success = '';
$error = '';

// PRG success flag — set after save redirect
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = 'Webhook saved successfully!';
}

/** Ensures column exists on legacy databases. */
function waamini_ensure_google_sheet_webhook_column(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn instanceof mysqli) {
        return;
    }
    $check = $conn->query("SHOW COLUMNS FROM `companies` LIKE 'google_sheet_webhook'");
    if ($check && $check->num_rows === 0) {
        $conn->query('ALTER TABLE `companies` ADD COLUMN `google_sheet_webhook` VARCHAR(512) NULL DEFAULT NULL');
    }
    $done = true;
}

waamini_ensure_google_sheet_webhook_column($conn);

// Fetch company settings
$companyData = [];
$stSgs1 = $conn->prepare('SELECT google_sheet_webhook, last_gsheet_sync, last_gsheet_status FROM companies WHERE id=? LIMIT 1');
if ($stSgs1) {
    $stSgs1->bind_param('i', $companyId);
    if ($stSgs1->execute()) {
        $row = $stSgs1->get_result()->fetch_assoc();
        if (is_array($row)) {
            $companyData = $row;
        }
    }
    $stSgs1->close();
}

// Handle Manual Sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trigger_manual_sync'])) {
    csrf_verify();
    if (empty($companyData['google_sheet_webhook'])) {
        $error = 'Save a valid Webhook URL first.';
    } else {
        $webhookUrl = $companyData['google_sheet_webhook'];

        $stC = $conn->prepare("SELECT name, phone, status, chat_labels as labels, last_message_at FROM contacts WHERE company_id = ? ORDER BY last_message_at DESC");
        $stC->bind_param("i", $companyId);
        $stC->execute();
        $contactsRes = $stC->get_result();

        $payload = [];
        while ($contact = $contactsRes->fetch_assoc()) {
            $payload[] = [
                'name'            => $contact['name'],
                'phone'           => $contact['phone'],
                'status'          => $contact['status'] ?: 'unassigned',
                'labels'          => $contact['labels'],
                'last_message_at' => $contact['last_message_at']
            ];
        }

        if (count($payload) === 0) {
            $error = 'No contacts found to sync.';
        } else {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $responseStr = trim((string)$response);
            $isSuccess = ($httpCode == 200 || $httpCode == 302 || strpos($responseStr, 'Success') !== false);

            if ($isSuccess) {
                $success = 'Sync successful! ' . count($payload) . ' contacts exported. Google says: "' . htmlspecialchars($responseStr) . '"';
                $conn->query("UPDATE companies SET last_gsheet_sync = NOW(), last_gsheet_status = 'Success' WHERE id = $companyId");
                $companyData['last_gsheet_sync']   = date('Y-m-d H:i:s');
                $companyData['last_gsheet_status'] = 'Success';
            } else {
                $error = "Sync failed (HTTP $httpCode). Google response: " . htmlspecialchars($responseStr);
                if ($httpCode == 401 || $httpCode == 403) {
                    $error .= " Access Denied: Ensure you selected 'Anyone' during deployment.";
                }
                $conn->query("UPDATE companies SET last_gsheet_sync = NOW(), last_gsheet_status = 'Failed' WHERE id = $companyId");
                $companyData['last_gsheet_sync']   = date('Y-m-d H:i:s');
                $companyData['last_gsheet_status'] = 'Failed';
            }
        }
    }
}

// Save Webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gsheets_webhook'])) {
    csrf_verify();
    $raw = trim((string)($_POST['google_sheet_webhook'] ?? ''));
    if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_URL)) {
        $stmt = $conn->prepare('UPDATE companies SET google_sheet_webhook=? WHERE id=?');
        $stmt->bind_param('si', $raw, $companyId);
        if ($stmt->execute()) {
            $stmt->close();
            // PRG: redirect to prevent re-submission on browser refresh
            header('Location: settings_gsheets.php?saved=1');
            exit();
        }
        $stmt->close();
        $error = 'Could not save webhook. Please try again.';
    } else {
        $error = 'Invalid URL. Please enter a valid Google Apps Script web app URL.';
    }
}

$pageTitle = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/></svg> Google Sheets Integration';
$currentPage = 'settings_gsheets';
include '../includes/client_header.php';
?>
<style>
.gs-wrap { max-width: 980px; margin: 0 auto; padding: 24px 20px 40px; }

/* Header */
.gs-hdr {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 28px 32px; margin-bottom: 24px;
    box-shadow: var(--shadow);
}
.gs-hdr-title { font-size: 22px; font-weight: 800; color: var(--text-primary); margin: 0 0 4px; letter-spacing: -.3px; }
.gs-hdr-sub   { font-size: 14px; color: var(--text-muted); margin: 0; }

/* Grid */
.gs-grid { display: grid; grid-template-columns: minmax(0, 1fr) 290px; gap: 20px; align-items: start; }
.gs-grid > * { min-width: 0; }
@media (max-width: 860px) { .gs-grid { grid-template-columns: 1fr; } }

/* Card */
.gs-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 20px; }
.gs-card:last-child { margin-bottom: 0; }
.gs-card-hdr { padding: 16px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 9px; }
.gs-card-title { font-size: 14px; font-weight: 700; color: var(--text-primary); margin: 0; }
.gs-card-sub   { font-size: 12px; color: var(--text-muted); margin: 3px 0 0; }
.gs-card-body  { padding: 22px; }

/* Alerts */
.gs-alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: var(--radius); font-size: 13px; font-weight: 600; margin-bottom: 20px; }
.gs-alert svg { flex-shrink: 0; margin-top: 1px; }
.gs-alert-ok  { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
.gs-alert-err { background: var(--danger-bg);  border: 1px solid var(--danger-border);  color: var(--danger); }

/* Step label */
.gs-step { display: inline-flex; align-items: center; gap: 7px; margin-bottom: 12px; }
.gs-step-num {
    width: 22px; height: 22px; border-radius: 50%; background: var(--accent); color: #fff;
    font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.gs-step-txt { font-size: 13px; font-weight: 700; color: var(--text-primary); }

/* Code block */
.gs-code-wrap { position: relative; border-radius: var(--radius); overflow: hidden; margin-bottom: 6px; }
.gs-code-bar  {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; background: #1a1f2e; border-bottom: 1px solid rgba(255,255,255,.07);
}
.gs-code-lang { font-size: 11px; font-weight: 600; color: #6b7892; text-transform: uppercase; letter-spacing: .5px; }
.gs-copy-btn  {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border: none; border-radius: 6px;
    background: var(--success); color: #fff; font-size: 11px; font-weight: 700;
    cursor: pointer; transition: var(--transition);
}
.gs-copy-btn:hover { opacity: .85; }
.gs-copy-btn svg { width: 12px; height: 12px; flex-shrink: 0; }
.gs-code-pre {
    margin: 0; padding: 18px 20px; background: #0f1117;
    font-size: 12px; line-height: 1.7; color: #a6e3a1;
    font-family: ui-monospace, 'Cascadia Code', 'Fira Code', monospace;
    overflow-x: auto; max-height: 340px; overflow-y: auto;
}

/* Divider */
.gs-divider { border: none; border-top: 1px solid var(--border); margin: 20px 0; }

/* Form */
.gs-field { margin-bottom: 16px; }
.gs-label {
    display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .5px; color: var(--text-muted); margin-bottom: 7px;
}
.gs-input {
    width: 100%; background: var(--bg-input); border: 1.5px solid var(--border);
    color: var(--text-primary); border-radius: var(--radius); padding: 10px 14px;
    font-size: 14px; font-family: inherit; outline: none; transition: var(--transition);
    box-sizing: border-box;
}
.gs-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,124,227,.12); }
.gs-input::placeholder { color: var(--text-muted); }

/* Buttons */
.gs-btn-row { display: flex; gap: 10px; margin-top: 18px; }
.gs-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px;
    padding: 11px 18px; border: none; border-radius: var(--radius);
    font-size: 13px; font-weight: 700; cursor: pointer; transition: var(--transition);
}
.gs-btn svg { flex-shrink: 0; width: 14px; height: 14px; }
.gs-btn-save  { background: var(--accent);   color: #fff; }
.gs-btn-save:hover  { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(45,124,227,.25); }
.gs-btn-sync  { background: var(--success);  color: #fff; }
.gs-btn-sync:hover  { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(16,185,129,.25); }

/* Sync status */
.gs-status {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px; background: var(--bg-input); border: 1px solid var(--border);
    border-radius: var(--radius);
}
.gs-status-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gs-status-icon-ok  { background: var(--success-bg); color: var(--success); }
.gs-status-icon-err { background: var(--danger-bg);  color: var(--danger); }
.gs-status-icon-nil { background: var(--bg-card); color: var(--text-muted); border: 1px solid var(--border); }
.gs-status-lbl { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; }
.gs-status-val { font-size: 14px; font-weight: 800; margin-top: 2px; }
.gs-status-ok  { color: var(--success); }
.gs-status-err { color: var(--danger); }
.gs-status-nil { color: var(--text-muted); }
.gs-status-time { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Sales tracker commands */
.gs-tracker-badge {
    display: inline-flex; align-items: center; gap: 6px; margin-bottom: 10px;
    padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .4px;
    background: rgba(245,158,11,.1); color: var(--warning); border: 1px solid rgba(245,158,11,.28);
}
.gs-commands-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.gs-cmd-box {
    padding: 12px 14px; background: var(--bg-input); border: 1px solid var(--border);
    border-radius: var(--radius);
}
.gs-cmd-box-title { font-size: 11px; font-weight: 700; color: var(--text-primary); margin-bottom: 7px; display: flex; align-items: center; gap: 5px; }
.gs-cmd-box-title svg { width: 12px; height: 12px; color: var(--accent); flex-shrink: 0; }
.gs-cmd-item { font-size: 12px; color: var(--text-secondary); line-height: 1.8; }
.gs-cmd-item code { font-family: ui-monospace, monospace; background: var(--accent-light); color: var(--accent); padding: 1px 5px; border-radius: 4px; font-size: 11px; }

/* How-it-works list */
.gs-how { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.gs-how li { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; color: var(--text-secondary); }
.gs-how li svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; color: var(--accent); }
</style>

<div class="gs-wrap">

    <div class="gs-hdr">
        <h1 class="gs-hdr-title">Google Sheets Integration</h1>
        <p class="gs-hdr-sub">Automatically export contacts and log sales directly to Google Sheets via Apps Script webhook.</p>
    </div>

    <?php if ($success): ?>
    <div class="gs-alert gs-alert-ok">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
        <?php echo $success; ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="gs-alert gs-alert-err">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <div class="gs-grid">

        <!-- Left: Setup Steps -->
        <div>

            <!-- Step 1 -->
            <div class="gs-card">
                <div class="gs-card-hdr">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    <div>
                        <div class="gs-card-title">Step 1 &mdash; Add Script to Google Sheets</div>
                        <div class="gs-card-sub">Open your Google Sheet &rarr; Extensions &rarr; Apps Script &rarr; paste this code</div>
                    </div>
                </div>
                <div class="gs-card-body">
                    <div class="gs-code-wrap">
                        <div class="gs-code-bar">
                            <span class="gs-code-lang">Google Apps Script</span>
                            <button type="button" class="gs-copy-btn" onclick="gsCopyScript()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                                Copy Code
                            </button>
                        </div>
                        <pre class="gs-code-pre" id="gsScriptBlock">function doPost(e) {
  if (!e || !e.postData) return ContentService.createTextOutput("Ready").setMimeType(ContentService.MimeType.TEXT);
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var data = JSON.parse(e.postData.contents);
  var payload = Array.isArray(data) ? data[0] : data;

  if (payload.type === 'instant_sale') {
    var sheet = ss.getSheetByName("Sales Log") || ss.insertSheet("Sales Log");
    if (sheet.getLastRow() === 0) sheet.appendRow(["Timestamp", "Item", "Qty", "Details", "Phone"]);
    var rows = Array.isArray(data) ? data : [data];
    rows.forEach(r => sheet.appendRow([r.timestamp, r.item, r.quantity, r.details, r.phone]));
    return ContentService.createTextOutput("Success").setMimeType(ContentService.MimeType.TEXT);
  }

  var sheet = ss.getActiveSheet();
  if (sheet.getLastRow() === 0) sheet.appendRow(["Date", "Contact", "Phone", "Status", "Labels", "Time"]);
  if (Array.isArray(data)) {
    data.forEach(c => sheet.appendRow([new Date().toLocaleDateString(), c.name, c.phone, c.status, c.labels, c.last_message_at]));
  }
  return ContentService.createTextOutput("Success").setMimeType(ContentService.MimeType.TEXT);
}</pre>
                    </div>
                    <p style="font-size:12px; color:var(--text-muted); margin:10px 0 0;">After pasting: click <strong style="color:var(--text-primary);">Deploy &rarr; New deployment &rarr; Web app</strong>. Set <em>Execute as: Me</em> and <em>Who has access: Anyone</em>. Copy the generated URL.</p>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="gs-card">
                <div class="gs-card-hdr">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    <div>
                        <div class="gs-card-title">Step 2 &mdash; Save Your Webhook URL</div>
                        <div class="gs-card-sub">Paste the web app URL from the deployment dialog</div>
                    </div>
                </div>
                <div class="gs-card-body">
                    <!-- Form 1: Save webhook URL -->
                    <form method="POST" id="gsFormSave">
                        <?php echo csrf_field(); ?>

                        <div class="gs-field">
                            <label class="gs-label" for="gs_webhook">Web App URL</label>
                            <input
                                type="url"
                                name="google_sheet_webhook"
                                id="gs_webhook"
                                class="gs-input"
                                value="<?php echo htmlspecialchars($companyData['google_sheet_webhook'] ?? ''); ?>"
                                placeholder="https://script.google.com/macros/s/..."
                                required
                                autocomplete="off"
                            >
                        </div>

                        <div class="gs-btn-row">
                            <button type="submit" name="save_gsheets_webhook" class="gs-btn gs-btn-save">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                                Save Settings
                            </button>
                        </div>
                    </form>

                    <!-- Form 2: Manual sync (separate form so CSRF and required don't interfere) -->
                    <form method="POST" id="gsFormSync" style="margin-top:10px;">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="trigger_manual_sync" class="gs-btn gs-btn-sync" style="width:100%;"
                            <?php echo empty($companyData['google_sheet_webhook']) ? 'disabled title="Save a webhook URL first"' : ''; ?>>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                            Sync Now
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <!-- Right: Info + Status -->
        <div>

            <!-- Sync Status -->
            <div class="gs-card">
                <div class="gs-card-hdr">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                    <span class="gs-card-title">Last Sync Status</span>
                </div>
                <div class="gs-card-body">
                    <?php
                    $lastStatus = $companyData['last_gsheet_status'] ?? '';
                    $lastSync   = $companyData['last_gsheet_sync']   ?? '';
                    $isOk  = (strtolower($lastStatus) === 'success');
                    $isErr = (strtolower($lastStatus) === 'failed');
                    $iconClass = $isOk ? 'gs-status-icon-ok' : ($isErr ? 'gs-status-icon-err' : 'gs-status-icon-nil');
                    $valClass  = $isOk ? 'gs-status-ok'      : ($isErr ? 'gs-status-err'      : 'gs-status-nil');
                    ?>
                    <div class="gs-status">
                        <div class="gs-status-icon <?php echo $iconClass; ?>">
                            <?php if ($isOk): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
                            <?php elseif ($isErr): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                            <?php else: ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="gs-status-lbl">Status</div>
                            <div class="gs-status-val <?php echo $valClass; ?>"><?php echo $lastStatus ? strtoupper(htmlspecialchars($lastStatus)) : 'NEVER SYNCED'; ?></div>
                            <div class="gs-status-time"><?php echo $lastSync ? 'Last sync: ' . htmlspecialchars($lastSync) : 'No sync history'; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales Tracker -->
            <div class="gs-card">
                <div class="gs-card-hdr">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                    <div>
                        <div class="gs-card-title">Instant Sales Tracker</div>
                        <div class="gs-card-sub">Log sales by messaging your WhatsApp number</div>
                    </div>
                </div>
                <div class="gs-card-body">
                    <div class="gs-commands-grid" style="margin-bottom:16px;">
                        <div class="gs-cmd-box">
                            <div class="gs-cmd-box-title">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                                Log a Sale
                            </div>
                            <div class="gs-cmd-item">
                                <code>Tshirt 4</code><br>
                                <code>Saree 2, Red color</code>
                            </div>
                        </div>
                        <div class="gs-cmd-box">
                            <div class="gs-cmd-box-title">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                                View Reports
                            </div>
                            <div class="gs-cmd-item">
                                <code>Sales Report</code><br>
                                <code>Report</code>
                            </div>
                        </div>
                    </div>
                    <p style="font-size:12px; color:var(--text-muted); margin:0;">Messages are parsed by the chatbot and appended to the <strong style="color:var(--text-primary);">Sales Log</strong> sheet automatically.</p>
                </div>
            </div>

            <!-- How it Works -->
            <div class="gs-card">
                <div class="gs-card-hdr">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                    <span class="gs-card-title">How It Works</span>
                </div>
                <div class="gs-card-body">
                    <ul class="gs-how">
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            Paste the Apps Script code into your Google Sheet
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                            Deploy as Web App with <em>Anyone</em> access
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                            Save the webhook URL here and click Sync Now
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/></svg>
                            All contacts export to your Sheet instantly
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function gsCopyScript() {
    var code = document.getElementById('gsScriptBlock').innerText;
    navigator.clipboard.writeText(code).then(function() {
        var btn = document.querySelector('.gs-copy-btn');
        var orig = btn.innerHTML;
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Copied!';
        setTimeout(function() { btn.innerHTML = orig; }, 2000);
    });
}
</script>

<?php include '../includes/client_footer.php'; ?>

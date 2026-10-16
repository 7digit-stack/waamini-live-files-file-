<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';

$pageTitle = 'General Settings';
$currentPage = 'settings_general';

$success = '';
$error = '';

/**
 * Ensure optional columns exist (legacy installs may predate migrations).
 *
 * @param mysqli $conn
 */
function waamini_ensure_company_profile_columns($conn): void {
    static $didRun = false;
    if ($didRun || !($conn instanceof mysqli)) {
        return;
    }
    $cols = [
        'phone' => 'VARCHAR(20) DEFAULT NULL',
        'email' => 'VARCHAR(255) DEFAULT NULL',
        'website' => 'VARCHAR(200) DEFAULT NULL',
        'address' => 'TEXT DEFAULT NULL',
    ];
    foreach ($cols as $col => $ddl) {
        $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
        if ($safeCol !== $col || $safeCol === '') {
            continue;
        }
        $pat = $conn->real_escape_string($col);
        $check = $conn->query("SHOW COLUMNS FROM `companies` LIKE '" . $pat . "'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE `companies` ADD COLUMN `" . $safeCol . "` " . $ddl);
        }
    }
    $didRun = true;
}

waamini_ensure_company_profile_columns($conn);

function waamini_load_company(mysqli $conn, int $companyId): array {
    $stmt = $conn->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $companyId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : [];
}

$company = waamini_load_company($conn, $companyId);

$companyMissing = ($companyId > 0 && $company === []);

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();

    $companyName = trim((string)($_POST['company_name'] ?? ''));
    $phone = sanitize_phone($_POST['phone'] ?? '');
    $emailRaw = trim((string)($_POST['email'] ?? ''));
    $email = $emailRaw === '' ? '' : sanitize_email($emailRaw);
    $websiteRaw = trim((string)($_POST['website'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($companyName === '') {
        $error = 'Company name is required.';
    } elseif ($emailRaw !== '' && !validate_email($emailRaw)) {
        $error = 'Please enter a valid email address.';
    } elseif ($websiteRaw !== '') {
        $websiteCheck = filter_var($websiteRaw, FILTER_VALIDATE_URL);
        if ($websiteCheck === false) {
            $error = 'Please enter a valid website URL (including http:// or https://).';
        }
    }

    $website = '';
    if ($error === '' && isset($websiteCheck) && $websiteCheck !== false) {
        $website = $websiteCheck;
    } elseif ($error === '' && $websiteRaw === '') {
        $website = '';
    }

    if ($error === '') {
        $stmt = $conn->prepare('UPDATE companies SET name=?, phone=?, email=?, website=?, address=? WHERE id=?');
        if (!$stmt) {
            $error = 'Could not save settings. Please try again.';
        } else {
            $stmt->bind_param('sssssi', $companyName, $phone, $email, $website, $address, $companyId);
            if (!$stmt->execute()) {
                $error = 'Could not save settings. Please try again.';
            } else {
                $company = waamini_load_company($conn, $companyId);
                if ($company === []) {
                    $error = 'Settings could not be saved: your company record was not found.';
                } else {
                    $success = 'Settings saved!';
                }
            }
            $stmt->close();
        }
    }
}

$lI = static function (string $p, int $sz = 20): string {
    return '<svg class="lI" width="' . $sz . '" height="' . $sz . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
// How complete is the business profile?
$profileFields = ['name' => 'Company name', 'phone' => 'Phone', 'email' => 'Email', 'website' => 'Website', 'address' => 'Address'];
$filled = 0; $missing = [];
foreach ($profileFields as $k => $label) {
    if (trim((string)($company[$k] ?? '')) !== '') { $filled++; } else { $missing[] = $label; }
}
$pct = (int)round($filled / count($profileFields) * 100);
$ringC = 2 * M_PI * 34;

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=2'); ?>">
<style>
.gs-ring { position:relative; width:96px; height:96px; flex:none; }
.gs-ring svg { width:96px; height:96px; transform:rotate(-90deg); }
.gs-ring circle { fill:none; stroke-width:8; stroke-linecap:round; }
.gs-ring .bg { stroke:#E3F7EE; } .gs-ring .fg { stroke:#128C7E; }
.gs-ring div { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.gs-ring b { font-size:20px; font-weight:800; color:var(--ink); line-height:1; }
.gs-ring span { font-size:11px; font-weight:800; color:var(--mu); margin-top:3px; }
.gs-todo { display:flex; flex-wrap:wrap; gap:8px; }
.su-f textarea { min-height:96px; }
.su-f .iw { position:relative; }
.su-f .iw .lI { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--mu); width:18px; height:18px; pointer-events:none; }
.su-f .iw input { padding-left:42px; }
</style>

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>', 25); ?></div>
        <div>
            <h1>General Settings</h1>
            <p>Your business details. They appear on invoices, quotes and messages to your customers.</p>
        </div>
    </div>
</div>

<?php if ($companyMissing): ?>
<div class="su-alert err" style="margin-bottom:18px"><?php echo $lI('<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 18); ?> Your company profile could not be loaded. If this persists, contact support.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="su-alert err" style="margin-bottom:18px"><?php echo $lI('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>', 18); ?> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="su-alert ok" style="margin-bottom:18px"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="su-grid">

    <form method="POST" class="su-col">
        <?php echo csrf_field(); ?>

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>'); ?></span> Business details</h2>
            <p class="sub">The name customers see when you message them.</p>
            <div class="su-f">
                <label class="l" for="gsName">Company name *</label>
                <input type="text" id="gsName" name="company_name" maxlength="255" value="<?php echo htmlspecialchars($company['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="su-f">
                <label class="l" for="gsAddr">Address</label>
                <textarea id="gsAddr" name="address" rows="3" placeholder="Street, city, state, PIN code"><?php echo htmlspecialchars($company['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <span class="hint">Shown on invoices and quotes.</span>
            </div>
        </section>

        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>'); ?></span> Contact &amp; online presence</h2>
            <p class="sub">How customers can reach you outside WhatsApp.</p>
            <div class="su-fg">
                <div class="su-f">
                    <label class="l" for="gsPhone">Phone</label>
                    <div class="iw"><?php echo $lI('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>', 18); ?><input type="text" id="gsPhone" name="phone" maxlength="40" placeholder="+91 98765 43210" value="<?php echo htmlspecialchars($company['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>
                <div class="su-f">
                    <label class="l" for="gsEmail">Email</label>
                    <div class="iw"><?php echo $lI('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>', 18); ?><input type="email" id="gsEmail" name="email" maxlength="255" placeholder="hello@yourbusiness.com" value="<?php echo htmlspecialchars($company['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>
                <div class="su-f full">
                    <label class="l" for="gsWeb">Website</label>
                    <div class="iw"><?php echo $lI('<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>', 18); ?><input type="url" id="gsWeb" name="website" maxlength="500" placeholder="https://example.com" value="<?php echo htmlspecialchars($company['website'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                    <span class="hint">Include http:// or https://</span>
                </div>
            </div>
        </section>

        <div><button type="submit" name="save" class="su-btn"><?php echo $lI('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'); ?> Save settings</button></div>
    </form>

    <div class="su-col">
        <section class="su-card">
            <h2><span class="ch"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>'); ?></span> Profile completeness</h2>
            <div style="display:flex;align-items:center;gap:18px;">
                <div class="gs-ring" role="img" aria-label="Profile <?php echo $pct; ?> percent complete">
                    <svg viewBox="0 0 80 80" aria-hidden="true"><circle cx="40" cy="40" r="34" class="bg"/><circle cx="40" cy="40" r="34" class="fg" stroke-dasharray="<?php echo round($ringC * $pct / 100, 1) . ' ' . round($ringC, 1); ?>"/></svg>
                    <div><b><?php echo $pct; ?>%</b><span>COMPLETE</span></div>
                </div>
                <p class="sub" style="margin:0"><?php echo $pct === 100 ? 'Your profile is complete. Customers have everything they need.' : 'A complete profile builds trust with your customers.'; ?></p>
            </div>
            <?php if ($missing): ?>
            <div>
                <p class="sub" style="margin:0 0 8px">Still missing:</p>
                <div class="gs-todo"><?php foreach ($missing as $mItem): ?><span class="su-tag amber"><?php echo htmlspecialchars($mItem); ?></span><?php endforeach; ?></div>
            </div>
            <?php endif; ?>
        </section>

        <div class="su-note">
            <?php echo $lI('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>', 22); ?>
            <div>
                <h4>Where is this used?</h4>
                <p>Your company name, phone, email and address are used on PDF quotes and invoices and in the messages sent to customers. WhatsApp connection and payment settings are in their own pages under Settings.</p>
            </div>
        </div>
    </div>

</div>
</div>

<?php include '../includes/client_footer.php'; ?>

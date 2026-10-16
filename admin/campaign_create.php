<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Create Campaign';
$currentPage = 'campaigns';

$error = '';

// Fetch companies (admin picks which company's WhatsApp to send from)
$companies = $conn->query("SELECT c.id, c.name FROM companies c JOIN whatsapp_config wc ON wc.company_id = c.id WHERE c.status='active' ORDER BY c.name");

// Fetch templates (APPROVED ones — Meta requires approved templates for campaigns)
$templates = $conn->query("SELECT id, name, category, body_text, language FROM message_templates WHERE wa_status='APPROVED' OR status='approved' ORDER BY name");
if (!$templates || $templates->num_rows === 0) {
    // fallback: show all templates so admin can still create
    $templates = $conn->query("SELECT id, name, category, body_text, language FROM message_templates ORDER BY name");
}

// Contact counts PER company (for dynamic "All Contacts" label in form)
$contactCountsByCompany = [];
$ccResult = $conn->query("SELECT company_id, COUNT(*) as cnt FROM contacts WHERE phone IS NOT NULL AND phone != '' AND status='active' GROUP BY company_id");
if ($ccResult) {
    while ($ccRow = $ccResult->fetch_assoc()) {
        $contactCountsByCompany[(int)$ccRow['company_id']] = (int)$ccRow['cnt'];
    }
}
// Total across all companies (fallback display)
$totalContacts = array_sum($contactCountsByCompany);

// Fetch groups with member counts via contact_group_members junction table
$groups = $conn->query("
    SELECT g.*, COUNT(c.id) as member_count
    FROM contact_groups g
    LEFT JOIN contact_group_members gm ON gm.group_id = g.id
    LEFT JOIN contacts c ON c.id = gm.contact_id AND c.phone IS NOT NULL AND c.phone != '' AND c.status='active'
    GROUP BY g.id ORDER BY g.name
");

// Group counts per company (for dynamic JS updates) — via junction table
$groupCountsByCompany = [];
$gcResult = $conn->query("
    SELECT c.company_id, gm.group_id, COUNT(c.id) as cnt
    FROM contact_group_members gm
    JOIN contacts c ON c.id = gm.contact_id AND c.phone IS NOT NULL AND c.phone != '' AND c.status='active'
    GROUP BY c.company_id, gm.group_id
");
if ($gcResult) {
    while ($gcRow = $gcResult->fetch_assoc()) {
        $compId = (int)$gcRow['company_id'];
        $grpId  = (int)$gcRow['group_id'];
        if (!isset($groupCountsByCompany[$compId])) $groupCountsByCompany[$compId] = [];
        $groupCountsByCompany[$compId][$grpId] = (int)$gcRow['cnt'];
    }
}

// Fetch tags
$allTags = [];
$tagResult = $conn->query("SELECT DISTINCT tags FROM contacts WHERE tags IS NOT NULL AND tags != ''");
while ($row = $tagResult->fetch_assoc()) {
    foreach (explode(',', $row['tags']) as $tag) {
        $t = trim($tag);
        if ($t && !in_array($t, $allTags))
            $allTags[] = $t;
    }
}
sort($allTags);

// Handle create
if (isset($_POST['create_campaign'])) {
    csrf_verify();
    $name        = sanitize($_POST['name']);
    $templateId  = intval($_POST['template_id']);
    $companyId   = intval($_POST['company_id'] ?? 0);
    $targetType  = in_array($_POST['target_type'], ['all', 'group', 'tag']) ? $_POST['target_type'] : 'all';
    $targetValue = sanitize($_POST['target_value'] ?? '');
    $scheduledAt = !empty($_POST['scheduled_at']) ? $_POST['scheduled_at'] : null;

    if (empty($name)) {
        $error = "Campaign name is required!";
    } elseif (empty($templateId)) {
        $error = "Please select a template!";
    } elseif (empty($companyId)) {
        $error = "Please select which company account to send from!";
    } else {
        // Fetch template details (need name + language for WhatsApp API payload)
        $tmpl = $conn->prepare("SELECT * FROM message_templates WHERE id=?");
        if (!$tmpl) {
            error_log("[WAAMINI Admin Campaign Create] template prepare failed: " . $conn->error);
            $error = "System error loading template.";
            goto form_done;
        }
        $tmpl->bind_param("i", $templateId);
        $tmpl->execute();
        $templateRow = $tmpl->get_result()->fetch_assoc();
        if (!$templateRow) {
            $error = "Template not found.";
            goto form_done;
        }
        $templateName = $templateRow['name'];
        $templateLang = $templateRow['language'] ?? 'en';
        $bodyText     = $templateRow['body'] ?? $templateRow['body_text'] ?? '';

        // Fetch contacts for the selected company based on target
        switch ($targetType) {
            case 'group':
                $gid = intval($targetValue);
                $cStmt = $conn->prepare("
                    SELECT c.* FROM contacts c
                    JOIN contact_group_members gm ON gm.contact_id = c.id AND gm.group_id = ?
                    WHERE c.company_id = ? AND c.phone IS NOT NULL AND c.phone != '' AND c.status='active'
                ");
                if (!$cStmt) {
                    error_log("[WAAMINI Admin Campaign Create] contacts(group) prepare failed: " . $conn->error);
                    $error = "System error loading contacts.";
                    goto form_done;
                }
                $cStmt->bind_param("ii", $gid, $companyId);
                break;
            case 'tag':
                $tagSearch = "%$targetValue%";
                $cStmt = $conn->prepare("SELECT * FROM contacts WHERE company_id=? AND tags LIKE ? AND phone IS NOT NULL AND phone != '' AND status='active'");
                if (!$cStmt) {
                    error_log("[WAAMINI Admin Campaign Create] contacts(tag) prepare failed: " . $conn->error);
                    $error = "System error loading contacts.";
                    goto form_done;
                }
                $cStmt->bind_param("is", $companyId, $tagSearch);
                break;
            default: // all
                $cStmt = $conn->prepare("SELECT * FROM contacts WHERE company_id=? AND phone IS NOT NULL AND phone != '' AND status='active'");
                if (!$cStmt) {
                    error_log("[WAAMINI Admin Campaign Create] contacts(all) prepare failed: " . $conn->error);
                    $error = "System error loading contacts.";
                    goto form_done;
                }
                $cStmt->bind_param("i", $companyId);
                break;
        }
        $cStmt->execute();
        $allContacts      = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $totalRecipients  = count($allContacts);

        if ($totalRecipients === 0) {
            $error = "No active contacts with phone numbers found for the selected company and audience.";
            goto form_done;
        }

        // Determine campaign status and scheduled time
        $camStatus   = !empty($scheduledAt) ? 'scheduled' : 'running';
        // IMPORTANT: For immediate campaigns (not scheduled), set baseTime to NOW so messages send immediately
        // For scheduled campaigns, use the provided scheduled time
        $isImmediate = empty($scheduledAt);
        $baseTime    = $isImmediate ? time() : strtotime($scheduledAt);
        $userId      = $_SESSION['user_id'] ?? 1;

        // Insert campaign record
        $camStmt = $conn->prepare("INSERT INTO campaigns (company_id, name, template_id, target_type, target_value, total_contacts, total_recipients, sent_count, status, scheduled_at, created_by) VALUES (?,?,?,?,?,?,?,0,?,?,?)");
        if (!$camStmt) {
            error_log("[WAAMINI Admin Campaign Create] campaigns insert prepare failed: " . $conn->error);
            $error = "Error creating campaign record.";
            goto form_done;
        }
        $camStmt->bind_param("isissiissi", $companyId, $name, $templateId, $targetType, $targetValue, $totalRecipients, $totalRecipients, $camStatus, $scheduledAt, $userId);

        if (!$camStmt->execute()) {
            error_log("[WAAMINI Campaign Create] " . $conn->error);
            $error = "Error creating campaign record.";
            goto form_done;
        }
        $campaignId = $conn->insert_id;

        // Populate campaign_queue — this is what cron_campaign_sender.php reads
        $qStmt = $conn->prepare("INSERT INTO campaign_queue (company_id, campaign_id, contact_id, phone, message_payload, scheduled_at, status) VALUES (?,?,?,?,?,FROM_UNIXTIME(?),'pending')");
        if (!$qStmt) {
            error_log("[WAAMINI Admin Campaign Create] queue insert prepare failed: " . $conn->error);
            $error = "Failed to queue campaign messages.";
            goto form_done;
        }
        foreach ($allContacts as $idx => $contact) {
            // Spacing strategy with anti-ban rate limiting:
            // - Immediate: 100-200ms apart (ensures unique timestamps for ordering)
            // - Scheduled: 2-5 sec apart (spreads load over time)
            // Actual sending delay is enforced by cron_campaign_sender.php rate limiter (default: 15 MPS)
            $delay = $isImmediate ? rand(100, 200) / 1000 : rand(2, 5);
            $baseTime += $delay;
            // Throttle: 5-second rest after each batch of 250 (only for scheduled)
            if (!$isImmediate && $idx > 0 && $idx % 250 === 0) $baseTime += 300;

            $fullName = trim($contact['name'] ?? '');

            $payload = [
                'phone_number'      => $contact['phone'],
                'template_name'     => $templateName,
                'template_language' => $templateLang,
            ];
            // Variable substitution {{1}}, {{2}} etc
            for ($i = 1; $i <= 5; $i++) {
                if (strpos($bodyText, "{{{$i}}}") !== false) {
                    $payload["field_{$i}"] = match($i) {
                        1 => $fullName,
                        2 => $fullName,
                        3 => $contact['phone'],
                        4 => $contact['email'] ?? '',
                        default => ''
                    };
                }
            }

            $jsonPayload = json_encode($payload);
            $contactId = (int)($contact['id'] ?? 0);
            $contactPhone = (string)($contact['phone'] ?? '');
            $qStmt->bind_param("iiissi", $companyId, $campaignId, $contactId, $contactPhone, $jsonPayload, $baseTime);
            $qStmt->execute();
        }

        // AUTO-TRIGGER: For immediate campaigns, run the cron sender NOW so messages send right away
        if ($isImmediate) {
            $cronFile = realpath(__DIR__ . '/../cron_campaign_sender.php');
            if ($cronFile && file_exists($cronFile)) {
                if (!defined('WAAMINI_ADMIN_CRON_TRIGGER')) {
                    define('WAAMINI_ADMIN_CRON_TRIGGER', true);
                }
                ob_start();
                try {
                    include $cronFile;
                } catch (\Throwable $e) {
                    error_log("[WAAMINI Campaign Create] Auto-trigger cron error: " . $e->getMessage());
                }
                ob_end_clean();
            }
        }

        header("Location: campaign_view.php?id=$campaignId&msg=created");
        exit();
    }
}
form_done:

include '../includes/admin_header.php';
?>

<div class="page-header">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create New Campaign</h2>
    <a href="campaigns.php" class="btn btn-sm btn-edit">← Back to Campaigns</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php
endif; ?>

<div style="display: grid; grid-template-columns: 1fr 380px; gap: 24px;">

    <!-- Left: Form -->
    <div class="form-card">
        <form method="POST" id="campaignForm">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Campaign Name *</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Diwali Special Offer 2025" required 
                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Send From (Company Account) *</label>
                <?php if ($companies && $companies->num_rows === 1):
                    $comp = $companies->fetch_assoc(); ?>
                    <input type="hidden" name="company_id" value="<?php echo (int)$comp['id']; ?>">
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($comp['name']); ?>" disabled>
                <?php elseif ($companies && $companies->num_rows > 1): ?>
                    <select name="company_id" class="form-control" required>
                        <option value="">— Choose a company account —</option>
                        <?php while ($comp = $companies->fetch_assoc()): ?>
                            <option value="<?php echo (int)$comp['id']; ?>" <?php echo (intval($_POST['company_id'] ?? 0) === (int)$comp['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($comp['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                <?php else: ?>
                    <div class="alert alert-danger" style="margin:0;">No active company with WhatsApp configured. <a href="companies.php">Add one →</a></div>
                    <input type="hidden" name="company_id" value="0">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Select Template *</label>
                <select name="template_id" class="form-control" id="templateSelect" required>
                    <option value="">— Choose a template —</option>
                    <?php while ($t = $templates->fetch_assoc()): ?>
                        <option value="<?php echo $t['id']; ?>" data-body="<?php echo htmlspecialchars($t['body_text']); ?>" data-category="<?php echo htmlspecialchars($t['category'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($t['name']); ?> (<?php echo htmlspecialchars(ucfirst($t['category']), ENT_QUOTES, 'UTF-8'); ?>)
                        </option>
                    <?php
endwhile; ?>
                </select>
                <small style="color:var(--text-muted);">Don't see your template? <a href="templates.php" style="color:#a78bfa;">Create one →</a></small>
            </div>

            <div class="form-group">
                <label>Target Audience *</label>
                <select name="target_type" class="form-control" id="targetType">
                    <option value="all" id="allContactsOption">All Contacts (<?php echo number_format($totalContacts); ?>)</option>
                    <option value="group">Select Group</option>
                    <option value="tag">Filter by Tag</option>
                </select>
            </div>

            <div class="form-group" id="groupSelect" style="display:none;">
                <label>Select Group</label>
                <select name="target_value" class="form-control" id="groupValue">
                    <option value="">— Choose a group —</option>
                    <?php while ($g = $groups->fetch_assoc()): ?>
                        <option value="<?php echo $g['id']; ?>" data-group-id="<?php echo $g['id']; ?>" data-group-name="<?php echo htmlspecialchars($g['name']); ?>">
                            <?php echo htmlspecialchars($g['name']); ?> (<?php echo number_format($g['member_count']); ?> contacts)
                        </option>
                    <?php
endwhile; ?>
                </select>
            </div>

            <div class="form-group" id="tagSelect" style="display:none;">
                <label>Select Tag</label>
                <select name="target_value" class="form-control" id="tagValue">
                    <option value="">— Choose a tag —</option>
                    <?php foreach ($allTags as $tag): ?>
                        <option value="<?php echo htmlspecialchars($tag); ?>"><?php echo htmlspecialchars($tag); ?></option>
                    <?php
endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Schedule (Optional)</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" 
                       min="<?php echo date('Y-m-d\TH:i'); ?>">
                <small style="color:var(--text-muted);">Leave empty to save as draft. Set a time to auto-schedule.</small>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" name="create_campaign" class="btn btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg> Create Campaign</button>
                <a href="campaigns.php" class="btn btn-edit">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Right: Preview -->
    <div>
        <div class="stat-card" style="position: sticky; top: 80px;">
            <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: var(--text-secondary);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg> Template Preview</h3>
            <div id="templatePreview" style="background: var(--bg-input); border-radius: 12px; padding: 16px; min-height: 150px; border-left: 3px solid #25d366;">
                <div style="color: var(--text-muted); font-size: 13px; text-align: center; padding: 40px 0;">
                    Select a template to preview
                </div>
            </div>
            <div style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> Variables like {{1}}, {{2}} will be replaced with contact data (name, var1, var2...)</div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
// Contact & group counts per company (from PHP)
var contactCounts = <?php echo json_encode($contactCountsByCompany); ?>;
var groupCounts   = <?php echo json_encode($groupCountsByCompany); ?>;

function updateCountsForCompany(companyId) {
    companyId = String(companyId);
    // Update "All Contacts" count
    var allOpt = document.getElementById('allContactsOption');
    var cnt = (contactCounts[companyId] !== undefined) ? contactCounts[companyId] : 0;
    allOpt.textContent = 'All Contacts (' + cnt.toLocaleString() + ')';

    // Update group member counts
    var groupSelect = document.getElementById('groupValue');
    if (groupSelect) {
        var options = groupSelect.querySelectorAll('option[data-group-id]');
        options.forEach(function(opt) {
            var gid = opt.getAttribute('data-group-id');
            var gName = opt.getAttribute('data-group-name');
            var gCnt = 0;
            if (groupCounts[companyId] && groupCounts[companyId][gid] !== undefined) {
                gCnt = groupCounts[companyId][gid];
            }
            opt.textContent = gName + ' (' + gCnt.toLocaleString() + ' contacts)';
        });
    }
}

function toggleTarget(el) {
    document.getElementById('groupSelect').style.display = el.value === 'group' ? 'block' : 'none';
    document.getElementById('tagSelect').style.display = el.value === 'tag' ? 'block' : 'none';
    document.getElementById('groupValue').disabled = el.value !== 'group';
    document.getElementById('tagValue').disabled = el.value !== 'tag';
}

function previewTemplate(el) {
    const opt = el.options[el.selectedIndex];
    const body = opt.dataset.body;
    const preview = document.getElementById('templatePreview');
    if (body) {
        preview.innerHTML = '<div style="font-size:13px; color:var(--text-primary); line-height:1.7; white-space:pre-line;">' +
            body.replace(/\{\{(\d+)\}\}/g, '<span style="color:#f59e0b; font-weight:600;">{{$1}}</span>') + '</div>';
    } else {
        preview.innerHTML = '<div style="color: var(--text-muted); font-size: 13px; text-align: center; padding: 40px 0;">Select a template to preview</div>';
    }
}

// Wire selects via addEventListener (replaces inline onchange — CSP-safe)
document.getElementById('targetType').addEventListener('change', function() { toggleTarget(this); });
document.getElementById('templateSelect').addEventListener('change', function() { previewTemplate(this); });

// Wire company select to update counts dynamically
var companySelect = document.querySelector('select[name="company_id"]');
var companyHidden = document.querySelector('input[type="hidden"][name="company_id"]');
if (companySelect) {
    companySelect.addEventListener('change', function() { updateCountsForCompany(this.value); });
    // Initialize with current selection
    if (companySelect.value) updateCountsForCompany(companySelect.value);
} else if (companyHidden) {
    // Single company — auto-selected via hidden input
    updateCountsForCompany(companyHidden.value);
}

// Initialize on load
toggleTarget(document.getElementById('targetType'));
</script>

<?php include '../includes/admin_footer.php'; ?>

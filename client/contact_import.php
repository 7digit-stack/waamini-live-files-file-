<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/drip_engine.php';
require_once __DIR__ . '/../includes/phone_helper.php';
require_once __DIR__ . '/../includes/chat_contact_sync.php';
require_once __DIR__ . '/../includes/contact_fields.php';
$cfFields = cf_enabled() ? cf_list($conn, (int)$companyId) : [];

if (!has_feature('contacts')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Import Contacts';
$currentPage = 'contacts';

// Ensure group_id column exists on contacts


// --- CSV Template Download ---
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="contacts_template.csv"');
    echo "\xEF\xBB\xBF";   // UTF-8 BOM: Excel shows Tamil names correctly
    $cfCsv = static fn($s) => preg_match('/[",\r\n]/', (string)$s) ? '"' . str_replace('"', '""', (string)$s) . '"' : (string)$s;
    $cfHead = ''; $cfEx1 = ''; $cfEx2 = '';
    foreach ($cfFields as $f) { $ex = cf_types()[$f['field_type']][2] ?? ''; $cfHead .= ',' . $cfCsv($f['name']); $cfEx1 .= ',' . $cfCsv($ex); $cfEx2 .= ','; }
    echo "name,phone_number,email,group_name,custom_field" . $cfHead . "\n";
    echo "John Kumar,+91 98765 43210,john@example.com,VIP Customers,Premium" . $cfEx1 . "\n";   // spaced: Excel keeps it as text
    echo "Priya Sharma,+91 87654 32109,priya@example.com,Leads," . $cfEx2 . "\n";
    exit();
}

$success = '';
$error = '';
$imported = 0;
$skipped = 0;
$linked = 0;      // matched an existing contact (updated instead of duplicated)
$linkedChat = 0;  // ...of which already have a WhatsApp chat

// Pre-selected group from URL (e.g., clicking Import from groups page)
$preGroupId = intval($_GET['group_id'] ?? 0);

// Fetch groups for dropdown
$st3 = $conn->prepare("SELECT id, name FROM contact_groups WHERE company_id=? ORDER BY name ASC");
$st3->bind_param("i", $companyId);
$st3->execute();
$groupsRes = $st3->get_result();
$groups = [];
if ($groupsRes) {
    while ($g = $groupsRes->fetch_assoc()) $groups[] = $g;
}

if (isset($_POST['import'])) {
    csrf_verify();

    if ($viewOnlyMode) {
        $error = "Plan expired. Renew to import contacts.";
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {

        if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $error = "File too large. Max 5MB allowed.";
        } else {
            $isValidExt = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) === 'csv';
            $serverMime = mime_content_type($_FILES['csv_file']['tmp_name']);
            $allowedMime = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
            $isValidMime = in_array($serverMime, $allowedMime);

            if ($isValidMime || $isValidExt) {
                $selectedGroupId = intval($_POST['group_id'] ?? 0);
                $newGroupName    = trim(sanitize($_POST['new_group_name'] ?? ''));

                // Create new group if requested
                if ($selectedGroupId === -1 && !empty($newGroupName)) {
                    $chk = $conn->prepare("SELECT id FROM contact_groups WHERE company_id=? AND name=?");
                    $chk->bind_param("is", $companyId, $newGroupName);
                    $chk->execute();
                    $existing = $chk->get_result()->fetch_assoc();
                    if ($existing) {
                        $selectedGroupId = $existing['id'];
                    } else {
                        $ins = $conn->prepare("INSERT INTO contact_groups (company_id, name) VALUES (?,?)");
                        $ins->bind_param("is", $companyId, $newGroupName);
                        $ins->execute();
                        $selectedGroupId = $ins->insert_id;
                    }
                }

                $groupIdVal = $selectedGroupId > 0 ? $selectedGroupId : null;

                $file   = fopen($_FILES['csv_file']['tmp_name'], 'r');
                $header = fgetcsv($file); // skip header row

                // Detect column positions from header
                $colMap = ['name' => 0, 'phone_number' => 1, 'email' => 2, 'group_name' => 3, 'custom_field' => -1];   // custom/tags only when the header names it (a 5th column such as created_at must never become a tag)
                if (is_array($header)) {
                    foreach ($header as $i => $h) {
                        $h = strtolower(trim(preg_replace("/^\x{FEFF}/u", "", (string)$h)));   // BOM from Excel-friendly CSVs
                        if (in_array($h, ['name'])) $colMap['name'] = $i;
                        elseif (in_array($h, ['phone', 'phone_number', 'mobile', 'mobile_number'])) $colMap['phone_number'] = $i;
                        elseif (in_array($h, ['email', 'email_address'])) $colMap['email'] = $i;
                        elseif (in_array($h, ['group', 'group_name', 'list'])) $colMap['group_name'] = $i;
                        elseif (in_array($h, ['custom', 'custom_field', 'notes', 'note', 'tag', 'tags'])) $colMap['custom_field'] = $i;
                    }
                    // A real header row (it names the phone column): use only the columns it names, so a file
                    // without "email" does not read another column (e.g. group_name) as the email address
                    $named = array_map(static fn($h) => strtolower(trim(preg_replace("/^\x{FEFF}/u", '', (string)$h))), $header);
                    if (array_intersect($named, ['phone', 'phone_number', 'mobile', 'mobile_number'])) {
                        if (!array_intersect($named, ['name'])) $colMap['name'] = -1;
                        if (!array_intersect($named, ['email', 'email_address'])) $colMap['email'] = -1;
                        if (!array_intersect($named, ['group', 'group_name', 'list'])) $colMap['group_name'] = -1;
                    }
                }

                // Columns named like a custom field (e.g. "Birthday") fill that field
                $cfCols = ($cfFields && is_array($header)) ? cf_header_map($cfFields, $header) : [];
                $cfBad = 0;
                $cfRow = static function (array $row) use ($cfCols): array { $o = []; foreach ($cfCols as $i => $f) { $o[(int)$f['id']] = (string)($row[$i] ?? ''); } return $o; };

                $stmt      = $conn->prepare("INSERT INTO contacts (company_id, name, phone, email, tags, group_id) VALUES (?, ?, ?, ?, ?, ?)");
                $defaultCc = preg_replace('/\D+/', '', (string)($_POST['country_code'] ?? '91')) ?: '91';

                // Cache: group name → id  (for csv group_name column)
                $groupCache = [];
                foreach ($groups as $g) $groupCache[strtolower($g['name'])] = $g['id'];

                while (($row = fgetcsv($file)) !== FALSE) {

                    $name   = sanitize($row[$colMap['name']] ?? '');
                    $phone  = wa_phone_normalize((string)($row[$colMap['phone_number']] ?? ''), $defaultCc);
                    $email  = sanitize_email($row[$colMap['email']] ?? '');
                    $custom = $colMap['custom_field'] >= 0 ? sanitize($row[$colMap['custom_field']] ?? '') : '';
                    if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', trim($custom))) { $custom = ''; }   // a date/time is never a tag

                    // Inject protection
                    $name   = ltrim($name, '=+-@|%');
                    $email  = ltrim($email, '=+-@|%');
                    $custom = ltrim($custom, '=+-@|%');

                    if (empty($phone)) { $skipped++; continue; }

                    // Resolve group from CSV column (if no group selected in UI, use CSV value)
                    $rowGroupId = $groupIdVal;
                    if ($rowGroupId === null) {
                        $csvGroupName = mb_substr(ltrim(sanitize((string)($row[$colMap['group_name']] ?? '')), '=+-@|%'), 0, 100);
                        $csvGroup = mb_strtolower(trim($csvGroupName));
                        if (!empty($csvGroup)) {
                            if (isset($groupCache[$csvGroup])) {
                                $rowGroupId = $groupCache[$csvGroup];
                            } else {
                                // Auto-create group from CSV
                                $gc = $conn->prepare("INSERT INTO contact_groups (company_id, name) VALUES (?,?)");
                                $gc->bind_param("is", $companyId, $csvGroupName);
                                $gc->execute();
                                $rowGroupId = $gc->insert_id;
                                $groupCache[$csvGroup] = $rowGroupId;
                            }
                        }
                    }

                    // Same person already here (any number format, e.g. created by an incoming WhatsApp chat)?
                    // Update that contact instead of creating a duplicate, so the import lands on the chat.
                    $existing = wa_find_contact_by_phone($conn, (int)$companyId, $phone);
                    if ($existing) {
                        $set = []; $types = ''; $vals = [];
                        $curName = trim((string)$existing['name']);
                        if ($name !== '' && ($curName === '' || preg_match('/^[\d\s+()-]+$/', $curName))) { $set[] = 'name=?'; $types .= 's'; $vals[] = $name; }
                        if ($email !== '' && trim((string)$existing['email']) === '') { $set[] = 'email=?'; $types .= 's'; $vals[] = $email; }
                        if ($rowGroupId !== null) { $set[] = 'group_id=?'; $types .= 'i'; $vals[] = (int)$rowGroupId; }
                        if ($custom !== '') {
                            $tagList = array_filter(array_map('trim', explode(',', (string)$existing['tags'])));
                            if (!in_array(mb_strtolower($custom), array_map('mb_strtolower', $tagList), true)) {
                                $tagList[] = $custom;
                                $set[] = 'tags=?'; $types .= 's'; $vals[] = implode(', ', $tagList);
                            }
                        }
                        if ($set) {
                            $types .= 'ii'; $vals[] = (int)$existing['id']; $vals[] = (int)$companyId;
                            $up = $conn->prepare('UPDATE contacts SET ' . implode(', ', $set) . ' WHERE id=? AND company_id=?');
                            if ($up) { $up->bind_param($types, ...$vals); $up->execute(); $up->close(); }
                        }
                        if ($cfCols) { $cfBad += count(cf_save($conn, (int)$companyId, (int)$existing['id'], array_values($cfCols), $cfRow($row), true)); }
                        $linked++;
                        if (!empty($existing['has_chat'])) { $linkedChat++; }
                        continue;
                    }

                    if (!can_use('contacts')) {
                        $error = "Plan limit reached — only {$imported} new contacts imported.";
                        break;
                    }

                    $stmt->bind_param("issssi", $companyId, $name, $phone, $email, $custom, $rowGroupId);
                    if ($stmt->execute()) {
                        $imported++;
                        $newId = $stmt->insert_id;
                        if ($cfCols && $newId > 0) { $cfBad += count(cf_save($conn, (int)$companyId, (int)$newId, array_values($cfCols), $cfRow($row), true)); }
                        if ($newId > 0) {
                            // Trigger drips for imported contact using custom/tags string
                            trigger_drip_campaigns($conn, (int)$companyId, (int)$newId, (string)$custom);
                        }
                    } else {
                        $skipped++;
                    }
                }
                fclose($file);

                if (empty($error)) {
                    $redirectGroup = ($selectedGroupId > 0) ? "&group=$selectedGroupId" : '';
                    $cfInfo = $cfCols ? '&cf=' . count($cfCols) . '&cfbad=' . (int)$cfBad : '';
                    header("Location: contact_import.php?msg=imported&count=$imported&skipped=$skipped&linked=$linked&chat=$linkedChat$redirectGroup$cfInfo");
                    exit();
                }
            } else {
                $error = "Please upload a valid .csv file.";
            }
        }
    } else {
        $error = "No file uploaded or upload error occurred.";
    }
}

// ── Sync with WhatsApp chats: fix number formats + add chatting customers to a group ──
if (isset($_POST['sync_chat'])) {
    csrf_verify();
    if ($viewOnlyMode) {
        $error = "Plan expired. Renew to sync contacts.";
    } else {
        $sgid = (int)($_POST['sync_group_id'] ?? 0);
        if ($sgid === -1) {
            $ng = mb_substr(trim(ltrim(sanitize((string)($_POST['sync_new_group'] ?? '')), '=+-@|%')), 0, 100);
            $sgid = 0;
            if ($ng !== '') {
                $chk = $conn->prepare("SELECT id FROM contact_groups WHERE company_id=? AND name=? LIMIT 1");
                $chk->bind_param("is", $companyId, $ng);
                $chk->execute();
                $ex = $chk->get_result()->fetch_assoc();
                if ($ex) { $sgid = (int)$ex['id']; }
                else {
                    $ins = $conn->prepare("INSERT INTO contact_groups (company_id, name) VALUES (?,?)");
                    $ins->bind_param("is", $companyId, $ng);
                    $ins->execute();
                    $sgid = (int)$ins->insert_id;
                }
            }
        } elseif ($sgid > 0) {
            $chk = $conn->prepare("SELECT id FROM contact_groups WHERE id=? AND company_id=? LIMIT 1");
            $chk->bind_param("ii", $sgid, $companyId);
            $chk->execute();
            if (!$chk->get_result()->fetch_row()) { $sgid = 0; }
        }
        $res = ccs_sync($conn, (int)$companyId, [
            'scope' => (string)($_POST['sync_scope'] ?? 'all'),
            'only_ungrouped' => !empty($_POST['sync_only_ungrouped']),
            'group_id' => $sgid,
            'fix_format' => !empty($_POST['sync_fix_format']),
            'add_cc' => !empty($_POST['sync_add_cc']),
            'cc' => (string)($_POST['sync_cc'] ?? '91'),
        ]);
        header('Location: contact_import.php?msg=synced&' . http_build_query($res + ['g' => $sgid]) . '#chats');
        exit();
    }
}

// Usage stats
$contactsLimit = get_limit('contacts');
$contactsUsage = get_usage('contacts');
$contactsPct   = $contactsLimit > 0 ? min(100, ($contactsUsage / $contactsLimit) * 100) : 0;
$sync = ccs_analysis($conn, (int)$companyId);

include '../includes/client_header.php';
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'synced'): ?>
    <div class="alert alert-success">
        Sync with chat complete:
        <strong><?php echo (int)($_GET['grouped'] ?? 0); ?></strong> chat contacts added to the group<?php if ((int)($_GET['g'] ?? 0) === 0): ?> (no group was chosen, so only numbers were checked)<?php elseif ((int)($_GET['grouped'] ?? 0) === 0): ?> (everyone in that time range was already in a group; untick "Only chats that are not in a group yet" to move them)<?php endif; ?>,
        <strong><?php echo (int)($_GET['formatted'] ?? 0) + (int)($_GET['cc_added'] ?? 0); ?></strong> numbers fixed<?php if ((int)($_GET['conflicts'] ?? 0) > 0): ?>,
        <strong><?php echo (int)$_GET['conflicts']; ?></strong> left unchanged because the fixed number already belongs to another contact (see duplicates below)<?php endif; ?>.
        <?php if ((int)($_GET['g'] ?? 0) > 0): ?><a href="contacts.php?group=<?php echo (int)$_GET['g']; ?>" style="color:inherit;font-weight:700;">View group &rarr;</a><?php endif; ?>
    </div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'imported'): ?>
    <div class="alert alert-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Import complete! <strong><?php echo intval($_GET['count'] ?? 0); ?></strong> new contacts,
        <strong><?php echo intval($_GET['linked'] ?? 0); ?></strong> matched existing contacts and were updated<?php if (intval($_GET['chat'] ?? 0) > 0): ?> (<strong><?php echo intval($_GET['chat']); ?></strong> already chatting on WhatsApp, now linked to their chat)<?php endif; ?>,
        <strong><?php echo intval($_GET['skipped'] ?? 0); ?></strong> skipped (missing or invalid phone).
        <?php if (intval($_GET['cf'] ?? 0) > 0): ?><br><?php echo intval($_GET['cf']); ?> custom field column<?php echo intval($_GET['cf']) === 1 ? '' : 's'; ?> imported<?php if (intval($_GET['cfbad'] ?? 0) > 0): ?> — <strong><?php echo intval($_GET['cfbad']); ?></strong> value<?php echo intval($_GET['cfbad']) === 1 ? ' was' : 's were'; ?> skipped because the format did not match the field type (for example text in a Date column)<?php endif; ?>. <a href="contact_fields.php" style="color:inherit;font-weight:700;">Custom fields →</a><?php endif; ?>
        <?php if (!empty($_GET['group'])): ?>
            — <a href="contact_groups.php" style="color:inherit;font-weight:700;">View Groups →</a>
        <?php endif; ?>
    </div>
<?php elseif ($error): ?>
    <div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo $error; ?></div>
<?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Import Contacts</h2>
    <div class="action-btns">
        <a href="#chats" class="btn btn-sm btn-primary" id="ciSyncBtn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Sync with chat</a>
        <a href="contact_groups.php" class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Groups</a>
        <a href="contacts.php" class="btn btn-sm btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> All Contacts</a>
    </div>
</div>

<div class="cs-top">
    <div class="cs-kpis">
        <div class="cs-k"><small>All contacts</small><b><?php echo number_format($sync['total']); ?></b></div>
        <div class="cs-k"><small>Chatting on WhatsApp</small><b><?php echo number_format($sync['chat']); ?></b><em><?php echo number_format($sync['chat_30']); ?> in last 30 days</em></div>
        <div class="cs-k <?php echo $sync['chat_no_group'] > 0 ? 'is-warn' : ''; ?>"><small>Chats not in a group</small><b><?php echo number_format($sync['chat_no_group']); ?></b><em>Not reached by group campaigns</em></div>
        <div class="cs-k <?php echo ($sync['format_fix'] + $sync['missing_cc']) > 0 ? 'is-warn' : ''; ?>"><small>Numbers to fix</small><b><?php echo number_format($sync['format_fix'] + $sync['missing_cc']); ?></b><em><?php echo (int)$sync['missing_cc']; ?> missing country code</em></div>
        <div class="cs-k <?php echo $sync['dup_count'] > 0 ? 'is-bad' : ''; ?>"><small>Possible duplicates</small><b><?php echo number_format($sync['dup_count']); ?></b><em>Same number saved twice</em></div>
    </div>

</div>

<div class="dashboard-grid ci-grid" style="grid-template-columns:minmax(0,1fr) 340px;gap:24px;">

    <!-- LEFT: Upload Form -->
    <div class="form-card ci-card" id="chatSync">
        <div class="ci-tabs" role="tablist" aria-label="Import source">
            <button type="button" class="ci-tab is-on" data-tab="csv" role="tab" aria-selected="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                Upload CSV<span class="ci-long"> file</span></button>
            <button type="button" class="ci-tab" data-tab="chats" role="tab" aria-selected="false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
                <span class="ci-lbl">From<span class="ci-long"> WhatsApp</span> chats</span> <em><?php echo number_format($sync['chat']); ?></em></button>
        </div>

        <div class="ci-pane" data-pane="csv">

        <!-- Format guide -->
        <?php $ciCols = [
            ['phone_number', true,  '9876543210',        'Also read as: phone, mobile'],
            ['name',         false, 'Rahul Kumar',       ''],
            ['email',        false, 'rahul@example.com', ''],
            ['group_name',   false, 'Diwali offer',      'New groups are created for you'],
            ['custom_field', false, 'VIP',               'Also read as: tags, notes'],
        ];
        foreach ($cfFields as $f) { $ciCols[] = [$f['name'], false, cf_types()[$f['field_type']][2] ?? '', 'Your custom field (' . (cf_types()[$f['field_type']][0] ?? 'Text') . ')']; } ?>
        <div class="ci-fmt">
            <div class="ci-fmt-h">
                <div>
                    <b>CSV column format</b>
                    <span>Put these headers in the first row. Only <code>phone_number</code> is required.</span>
                </div>
                <a href="contact_import.php?download_template=1" class="ci-fmt-dl"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Sample CSV</a>
            </div>
            <div class="ci-cols" role="table" aria-label="CSV columns">
                <div class="ci-col ci-col-head" role="row"><span role="columnheader">Column</span><span role="columnheader">Example</span><span role="columnheader">Notes</span></div>
                <?php foreach ($ciCols as [$col, $req, $ex, $note]): ?>
                <div class="ci-col" role="row">
                    <span role="cell"><code><?php echo $col; ?></code><em class="<?php echo $req ? 'is-req' : ''; ?>"><?php echo $req ? 'Required' : 'Optional'; ?></em></span>
                    <span role="cell" class="ci-ex"><?php echo htmlspecialchars($ex); ?></span>
                    <span role="cell" class="ci-note"><?php echo htmlspecialchars($note); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="ci-fmt-tip">Numbers without a country code get the code you pick below, so <code>9876543210</code>, <code>09876543210</code> and <code>+91 98765 43210</code> all match the same WhatsApp chat. Existing contacts are updated, not duplicated.</p>
        </div>
        <style>
        .ci-fmt{background:#F4FBF8;border-radius:16px;padding:18px;margin-bottom:24px}
        .ci-fmt-h{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
        .ci-fmt-h b{display:block;font-size:15px;font-weight:700;color:var(--text-primary)}
        .ci-fmt-h span{display:block;margin-top:3px;font-size:13px;color:var(--text-muted);line-height:1.45}
        .ci-fmt code{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;font-weight:600;color:#0B6B5E;background:#E3F5EE;padding:2px 7px;border-radius:6px}
        .ci-fmt-dl{display:inline-flex;align-items:center;gap:6px;flex:none;height:36px;padding:0 14px;border-radius:10px;background:#fff;color:#0B6B5E;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 1px 2px rgba(15,23,42,.08)}
        .ci-fmt-dl:hover{background:#E3F5EE}
        .ci-cols{background:#fff;border-radius:12px;overflow:hidden}
        .ci-col{display:grid;grid-template-columns:minmax(170px,1.1fr) minmax(120px,1fr) minmax(0,1.3fr);gap:12px;align-items:center;padding:11px 14px;font-size:13px}
        .ci-col:nth-child(even){background:#F8FBFA}
        .ci-col-head{padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);background:#EEF5F2!important}
        .ci-col>span:first-child{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .ci-col em{font-style:normal;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:#EEF2F1;color:#64748B}
        .ci-col em.is-req{background:#FFF1E6;color:#C2410C}
        .ci-ex{color:var(--text-primary);font-variant-numeric:tabular-nums}
        .ci-note{color:var(--text-muted);font-size:12.5px}
        .ci-fmt-tip{margin:12px 0 0;font-size:12.5px;line-height:1.55;color:var(--text-muted)}
        @media (max-width:640px){
          .ci-fmt{padding:14px;border-radius:14px}
          .ci-fmt-h{flex-direction:column;gap:10px}
          .ci-fmt-dl{width:100%;justify-content:center;height:40px}
          .ci-col-head{display:none}
          .ci-col{grid-template-columns:1fr auto;grid-template-areas:"name ex" "note note";gap:4px 10px;padding:12px}
          .ci-col>span:first-child{grid-area:name}
          .ci-ex{grid-area:ex;text-align:right;font-size:12.5px;color:var(--text-secondary)}
          .ci-note{grid-area:note}
          .ci-note:empty{display:none}
          .ci-col:nth-child(even){background:transparent}
          .ci-col+.ci-col{box-shadow:inset 0 1px 0 #EEF3F1}
        }
        </style>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <!-- Country code for numbers written without one -->
            <div class="form-group" style="margin-bottom:18px;">
                <label for="ccSel" style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;display:block;">COUNTRY CODE FOR NUMBERS WITHOUT ONE</label>
                <select name="country_code" id="ccSel" class="form-control" style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-primary);padding:11px 14px;border-radius:8px;width:100%;">
                    <?php foreach (['91' => 'India (+91)', '971' => 'UAE (+971)', '966' => 'Saudi Arabia (+966)', '65' => 'Singapore (+65)', '60' => 'Malaysia (+60)', '94' => 'Sri Lanka (+94)', '1' => 'USA / Canada (+1)', '44' => 'UK (+44)'] as $ccv => $ccl): ?>
                        <option value="<?php echo $ccv; ?>"><?php echo $ccl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Group Assignment -->
            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;display:block;">ASSIGN TO GROUP</label>
                <select name="group_id" id="groupSelect" class="form-control" onchange="toggleNewGroup(this.value)"
                    style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-primary);padding:11px 14px;border-radius:8px;width:100%;">
                    <option value="0">— No Group / Use CSV group_name column —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?php echo $g['id']; ?>" <?php echo ($preGroupId === $g['id']) ? 'selected' : ''; ?>>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> <?php echo htmlspecialchars($g['name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="-1"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Create New Group…</option>
                </select>
            </div>

            <div id="newGroupBox" style="display:none;margin-bottom:18px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;display:block;">NEW GROUP NAME</label>
                <input type="text" name="new_group_name" class="form-control" placeholder="e.g. Diwali Campaign List"
                    style="background:var(--bg-input);border:1px solid var(--border);color:var(--text-primary);padding:11px 14px;border-radius:8px;width:100%;">
            </div>

            <!-- File Upload -->
            <div class="form-group" style="margin-bottom:24px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;display:block;">SELECT CSV FILE *</label>
                <!-- label[for] is the reliable cross-browser way to trigger a file input click -->
                <label for="csvFileInput" style="display:block;border:2px dashed var(--border);border-radius:10px;padding:32px;text-align:center;background:var(--bg-dark);cursor:pointer;transition:border-color 0.2s;"
                       onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
                    <div style="font-size:32px;margin-bottom:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></div>
                    <div style="font-size:13px;color:var(--text-secondary);font-weight:600;">Click to choose CSV file</div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Max 5MB · .csv format only</div>
                    <div id="fileNameDisplay" style="font-size:12px;color:var(--primary);margin-top:8px;font-weight:600;"></div>
                </label>
                <input type="file" id="csvFileInput" name="csv_file" accept=".csv" required
                    style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;pointer-events:none;"
                    onchange="document.getElementById('fileNameDisplay').textContent = this.files[0]?.name || ''">
            </div>

            <?php if ($viewOnlyMode): ?>
                <button type="button" class="btn btn-primary" style="width:100%;opacity:0.5;cursor:not-allowed;" disabled><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Plan Expired</button>
            <?php elseif (!can_use('contacts')): ?>
                <button type="button" class="btn btn-edit" style="width:100%;opacity:0.5;cursor:not-allowed;" disabled><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Contact Limit Reached</button>
            <?php else: ?>
                <button type="submit" name="import" class="btn btn-primary" style="width:100%;padding:13px;font-size:14px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Start Import
                </button>
            <?php endif; ?>
        </form>
        </div>

        <div class="ci-pane" data-pane="chats" hidden>
        <form method="POST" class="cs-form" id="csForm">
            <?php echo csrf_field(); ?>
            <p class="ci-lead">Import the customers who messaged you on WhatsApp into a group, and fix their numbers so chats, imports and campaigns all match. Nothing is sent to customers.</p>
            <label class="cs-lab" for="csScope">Which chats</label>
            <select name="sync_scope" id="csScope" class="cs-in">
                <option value="all">Everyone who has messaged you</option>
                <option value="30">Messaged in the last 30 days</option>
                <option value="90">Messaged in the last 90 days</option>
            </select>
            <label class="cs-lab" for="csGroup">Add them to group</label>
            <select name="sync_group_id" id="csGroup" class="cs-in">
                <option value="-1" selected>+ New group&hellip;</option>
                <?php foreach ($groups as $g): ?><option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option><?php endforeach; ?>
                <option value="0">Don't add to a group (only fix numbers)</option>
            </select>
            <input type="text" name="sync_new_group" id="csNewGroup" class="cs-in" maxlength="100" value="WhatsApp chats" placeholder="New group name, e.g. WhatsApp leads">
            <label class="cs-chk"><input type="checkbox" name="sync_only_ungrouped" value="1" checked> Only chats that are not in a group yet <small>(leaves customers you already grouped alone)</small></label>
            <label class="cs-chk"><input type="checkbox" name="sync_fix_format" value="1" checked> Save numbers in WhatsApp format (+country code, no spaces) <small><?php echo (int)$sync['format_fix']; ?> found</small></label>
            <label class="cs-chk"><input type="checkbox" name="sync_add_cc" value="1" <?php echo $sync['missing_cc'] > 0 ? 'checked' : ''; ?>> Add the country code to 10-digit mobile numbers that are missing it <small><?php echo (int)$sync['missing_cc']; ?> found</small></label>
            <select name="sync_cc" class="cs-in" aria-label="Country code">
                <?php foreach (['91' => 'India (+91)', '971' => 'UAE (+971)', '966' => 'Saudi Arabia (+966)', '65' => 'Singapore (+65)', '60' => 'Malaysia (+60)', '94' => 'Sri Lanka (+94)', '1' => 'USA / Canada (+1)', '44' => 'UK (+44)'] as $ccv => $ccl): ?>
                    <option value="<?php echo $ccv; ?>"><?php echo $ccl; ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($viewOnlyMode): ?>
                <button type="button" class="btn btn-primary cs-go" disabled style="opacity:.5">Plan expired</button>
            <?php else: ?>
                <button type="submit" name="sync_chat" value="1" class="btn btn-primary" style="width:100%;padding:13px;font-size:14px;margin-top:8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Start Import
                </button>
            <?php endif; ?>
            <p class="cs-note">Nothing is sent to customers. This only updates contacts and groups.</p>
        </form>
        </div>
    </div>

    <!-- RIGHT: Tips + Download -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <div class="form-card ci-side">
        <div class="cs-box">
            <h4>Chat contacts by group</h4>
            <?php if (!$sync['groups']): ?>
                <p class="cs-empty">No WhatsApp chats yet.</p>
            <?php else: $max = max(array_column($sync['groups'], 'count')) ?: 1; foreach ($sync['groups'] as $gr): ?>
                <div class="cs-bar">
                    <span class="cs-bar-n"><i style="background:<?php echo htmlspecialchars($gr['color']); ?>"></i><?php echo htmlspecialchars($gr['name']); ?></span>
                    <span class="cs-bar-t"><span style="width:<?php echo round($gr['count'] / $max * 100); ?>%;background:<?php echo htmlspecialchars($gr['color']); ?>"></span></span>
                    <b><?php echo number_format($gr['count']); ?></b>
                </div>
            <?php endforeach; endif; ?>
            <?php if ($sync['dups']): ?>
                <h4 style="margin-top:20px">Possible duplicates <small>(same last 10 digits)</small></h4>
                <?php foreach ($sync['dups'] as $d): ?>
                    <div class="cs-dup"><b>&hellip;<?php echo htmlspecialchars($d['last10']); ?></b>
                        <?php foreach ($d['rows'] as $dr): ?><a href="whatsapp.php?c=<?php echo (int)$dr['id']; ?>&crm=open"><?php echo htmlspecialchars(($dr['name'] ?: 'No name') . ' · ' . $dr['phone']); ?></a><?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p class="cs-note">Duplicates are not merged automatically, because each copy can hold its own chat, labels and drips. Keep one and delete the others from Contacts.</p>
            <?php endif; ?>
        </div>



        </div>
        <!-- Download Template -->
        <div class="form-card" style="text-align:center;padding:28px 24px;">
            <div style="font-size:40px;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg></div>
            <h3 style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:8px;">Download CSV Template</h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
                Get an empty CSV with the exact column headers. Fill it in and upload it back here.
            </p>
            <a href="contact_import.php?download_template=1" class="btn btn-primary" style="display:inline-block;padding:10px 20px;">
                ⬇️ Download Template
            </a>
        </div>

        <!-- Plan Usage -->
        <div class="form-card">
            <h3 style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Contact Limit</h3>
            <div class="usage-bar-wrapper">
                <div class="usage-bar-header">
                    <span class="usage-bar-label">Used</span>
                    <span class="usage-bar-value"><?php echo number_format($contactsUsage); ?> / <?php echo $contactsLimit == 0 ? 'Unlimited' : number_format($contactsLimit); ?></span>
                </div>
                <div class="usage-bar">
                    <div class="usage-bar-fill <?php echo $contactsPct > 85 ? 'warning' : ''; ?>" style="width:<?php echo $contactsPct; ?>%"></div>
                </div>
            </div>
            <?php if (!can_use('contacts')): ?>
            <div style="margin-top:12px;padding:10px;background:rgba(255,71,87,0.1);color:#ff4757;border-radius:8px;font-size:11px;font-weight:600;text-align:center;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Limit reached. <a href="subscription.php" style="color:#ff4757;">Upgrade →</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick links -->
        <div class="form-card">
            <h3 style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Quick Actions</h3>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <a href="contact_groups.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg-dark);border-radius:8px;text-decoration:none;color:var(--text-secondary);font-size:13px;">
                    <span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Manage Groups
                </a>
                <a href="contact_add.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg-dark);border-radius:8px;text-decoration:none;color:var(--text-secondary);font-size:13px;">
                    <span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M5 12h14"/><path d="M12 5v14"/></svg></span> Add Single Contact
                </a>
                <a href="campaigns.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg-dark);border-radius:8px;text-decoration:none;color:var(--text-secondary);font-size:13px;">
                    <span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg></span> View Campaigns
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.cs{background:var(--bg-card,#fff);border-radius:18px;padding:24px;margin-top:24px;box-shadow:0 1px 3px rgba(15,38,34,.06)}
.cs-hd{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.cs-ic{width:46px;height:46px;border-radius:13px;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;flex:none}
.cs-hd h3{margin:0 0 4px;font-size:17px;font-weight:800;color:var(--text-primary,#0F2622)}
.cs-hd p{margin:0;font-size:13.5px;line-height:1.55;color:var(--text-muted,#5F6F6A)}
.cs-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}
.cs-k{padding:14px 16px;border-radius:14px;background:#F3FBF7}
.cs-k small{display:block;font-size:12px;font-weight:600;color:#5F6F6A}
.cs-k b{display:block;font-size:24px;margin-top:3px;color:#0F2622}
.cs-k em{display:block;font-style:normal;font-size:11.5px;color:#7a8c86;margin-top:2px}
.cs-k.is-warn{background:#FFF6E0}.cs-k.is-warn b{color:#92580a}
.cs-k.is-bad{background:#FDECEC}.cs-k.is-bad b{color:#A12222}
.cs-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:18px}
.cs-box{padding:18px;border-radius:14px;background:#F8FBFA}
.cs-box h4{margin:0 0 12px;font-size:14px;color:#0F2622}
.cs-box h4 small{font-weight:500;color:#7a8c86}
.cs-empty{margin:0;font-size:13px;color:#7a8c86}
.cs-bar{display:grid;grid-template-columns:minmax(0,150px) minmax(0,1fr) 44px;align-items:center;gap:10px;margin-bottom:9px;font-size:13px}
.cs-bar-n{display:flex;align-items:center;gap:7px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0F2622}
.cs-bar-n i{width:9px;height:9px;border-radius:50%;flex:none}
.cs-bar-t{height:8px;border-radius:8px;background:#E6EEEA;overflow:hidden}
.cs-bar-t span{display:block;height:100%;border-radius:8px}
.cs-bar b{text-align:right;color:#0F2622}
.cs-dup{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:8px;font-size:12.5px}
.cs-dup b{color:#A12222}
.cs-dup a{padding:4px 10px;border-radius:999px;background:#fff;color:#0F2622;text-decoration:none}
.cs-dup a:hover{color:#128C7E}
.cs-note{margin:10px 0 0;font-size:12px;line-height:1.5;color:#7a8c86}
.cs-form{display:flex;flex-direction:column;gap:8px}
.cs-lab{font-size:12px;font-weight:700;color:#5F6F6A;margin-top:4px}
.cs-in{width:100%;height:42px;padding:0 12px;border:1px solid #DDEFE7;border-radius:10px;background:#fff;font:inherit;font-size:13.5px;color:#0F2622}
.cs-chk{display:flex;gap:9px;align-items:flex-start;font-size:13px;line-height:1.45;color:#0F2622;cursor:pointer;margin-top:4px}
.cs-chk input{margin-top:3px;accent-color:#128C7E}
.cs-chk small{display:block;color:#7a8c86;font-size:11.5px}
.cs-go{margin-top:8px;padding:12px;font-size:14px;width:100%}
@media (max-width:1100px){.cs-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.cs-grid{grid-template-columns:1fr}}
</style>

<style>
.ci-grid{align-items:start}
.ci-grid .form-card{max-width:none}
.cs-top{margin-bottom:20px}
.cs-top .cs-kpis{margin-bottom:0}
.ci-tabs{display:flex;gap:6px;padding:5px;border-radius:14px;background:#F1F6F4;margin-bottom:20px}
.ci-tab{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;border:0;border-radius:10px;background:transparent;color:#4b5d57;font:700 14px Inter,system-ui,sans-serif;cursor:pointer}
.ci-tab em{font-style:normal;padding:2px 8px;border-radius:999px;background:#E3F7EE;color:#0B6B5E;font-size:12px}
.ci-tab.is-on{background:#fff;color:#0F2622;box-shadow:0 1px 3px rgba(15,38,34,.12)}
.ci-tab.is-on svg{color:#128C7E}
@media (max-width:640px){.ci-tabs{gap:4px;padding:4px}.ci-tab{gap:6px;height:42px;padding:0 6px;font-size:13px;white-space:nowrap;min-width:0}.ci-tab svg{display:none}.ci-long{display:none}.ci-tab{overflow:hidden}.ci-lbl{overflow:hidden;text-overflow:ellipsis}.ci-tab em{flex:none}.ci-tab em{padding:1px 7px;font-size:11.5px}}
.ci-lead{margin:0 0 8px;font-size:13.5px;line-height:1.55;color:#5F6F6A}
.ci-side .cs-box{padding:0;background:transparent}
</style>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
    var tabs = document.querySelectorAll('.ci-tab'), panes = document.querySelectorAll('.ci-pane');
    function show(name) {
        tabs.forEach(function (t) { var on = t.getAttribute('data-tab') === name; t.classList.toggle('is-on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
        panes.forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab')); }); });
    var b = document.getElementById('ciSyncBtn');
    if (b) { b.addEventListener('click', function (e) { e.preventDefault(); show('chats'); document.getElementById('chatSync').scrollIntoView({behavior: 'smooth', block: 'start'}); }); }
    if (location.hash === '#chats' || location.hash === '#chatSync') { show('chats'); }
})();
</script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
    var g = document.getElementById('csGroup'), n = document.getElementById('csNewGroup');
    if (g && n) { g.addEventListener('change', function () { n.style.display = g.value === '-1' ? '' : 'none'; if (g.value === '-1') { n.focus(); } }); }
})();
</script>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
function toggleNewGroup(val) {
    document.getElementById('newGroupBox').style.display = (val === '-1') ? 'block' : 'none';
}
// Pre-select group from URL
<?php if ($preGroupId > 0): ?>
document.getElementById('groupSelect').value = '<?php echo $preGroupId; ?>';
<?php endif; ?>
</script>

<?php include '../includes/client_footer.php'; ?>


<?php
include '../includes/client_auth_check.php';
if (!has_feature('contact_groups')) { header('Location: dashboard.php'); exit(); }  // plan switch: Admin > Plans > Edit > Client menu pages
if (!has_feature('contacts')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Contact Groups';
$currentPage = 'contact_groups';

$success = '';
$error = '';

/* Older installs may lack optional columns, so detect them once instead of assuming. */
$getCols = static function (mysqli $conn, string $tbl): array {
    $out = [];
    $r = $conn->query("SHOW COLUMNS FROM `$tbl`");
    while ($r && ($row = $r->fetch_assoc())) { $out[strtolower((string)$row['Field'])] = true; }
    return $out;
};
$cgCols = $getCols($conn, 'contact_groups');
$ctCols = $getCols($conn, 'contacts');
$hasDesc    = isset($cgCols['description']);
$hasColor   = isset($cgCols['color']);
$hasCreated = isset($cgCols['created_at']);
$hasGroupId = isset($ctCols['group_id']);

$swatches = ['#128C7E', '#1d6fd6', '#6d44d8', '#d1356f', '#b7791f', '#c62828', '#0e7490', '#475569'];
$cleanColor = static function ($c) use ($swatches): string {
    $c = strtolower(trim((string)$c));
    foreach ($swatches as $s) { if (strtolower($s) === $c) return $s; }
    return $swatches[0];
};
$strlen = static function (string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); };

$reopen = ''; $form = ['id' => 0, 'name' => '', 'desc' => '', 'color' => $swatches[0]];

/* A duplicate-name check that ignores the group being edited */
$nameTaken = static function (mysqli $conn, int $companyId, string $name, int $exceptId): bool {
    $st = $conn->prepare("SELECT id FROM contact_groups WHERE company_id=? AND name=? AND id<>? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("isi", $companyId, $name, $exceptId);
    $st->execute();
    $hit = $st->get_result()->num_rows > 0;
    $st->close();
    return $hit;
};

// --- Handle CREATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    csrf_verify();
    // trim(), not sanitize(): output is escaped on display, so storing escaped text would double-encode it
    $gname = trim((string)($_POST['group_name'] ?? ''));
    $gdesc = trim((string)($_POST['group_desc'] ?? ''));
    $gcol  = $cleanColor($_POST['group_color'] ?? '');
    $form  = ['id' => 0, 'name' => $gname, 'desc' => $gdesc, 'color' => $gcol];
    if ($viewOnlyMode) { $error = "Plan expired. Renew to create groups."; }
    elseif ($gname === '') { $error = "Group name is required."; }
    elseif ($strlen($gname) > 100) { $error = "Group name can be at most 100 characters."; }
    elseif ($strlen($gdesc) > 255) { $error = "Description can be at most 255 characters."; }
    elseif ($nameTaken($conn, $companyId, $gname, 0)) { $error = "A group with that name already exists."; }
    else {
        $cols = ['company_id', 'name']; $ph = ['?', '?']; $types = 'is'; $vals = [$companyId, $gname];
        if ($hasDesc)  { $cols[] = 'description'; $ph[] = '?'; $types .= 's'; $vals[] = $gdesc; }
        if ($hasColor) { $cols[] = 'color';       $ph[] = '?'; $types .= 's'; $vals[] = $gcol; }
        $ins = $conn->prepare("INSERT INTO contact_groups (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")");
        if ($ins) {
            $ins->bind_param($types, ...$vals);
            if ($ins->execute()) { header("Location: contact_groups.php?msg=created"); exit(); }
        }
        $error = "Could not create the group. Please try again.";
    }
    if ($error) $reopen = 'create';
}

// --- Handle UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_group'])) {
    csrf_verify();
    $gid   = intval($_POST['group_id'] ?? 0);
    $gname = trim((string)($_POST['group_name'] ?? ''));
    $gdesc = trim((string)($_POST['group_desc'] ?? ''));
    $gcol  = $cleanColor($_POST['group_color'] ?? '');
    $form  = ['id' => $gid, 'name' => $gname, 'desc' => $gdesc, 'color' => $gcol];
    if ($viewOnlyMode) { $error = "Plan expired. Renew to edit groups."; }
    elseif ($gid <= 0) { $error = "Group not found."; }
    elseif ($gname === '') { $error = "Group name is required."; }
    elseif ($strlen($gname) > 100) { $error = "Group name can be at most 100 characters."; }
    elseif ($strlen($gdesc) > 255) { $error = "Description can be at most 255 characters."; }
    elseif ($nameTaken($conn, $companyId, $gname, $gid)) { $error = "Another group already uses that name."; }
    else {
        $set = ['name=?']; $types = 's'; $vals = [$gname];
        if ($hasDesc)  { $set[] = 'description=?'; $types .= 's'; $vals[] = $gdesc; }
        if ($hasColor) { $set[] = 'color=?';       $types .= 's'; $vals[] = $gcol; }
        $types .= 'ii'; array_push($vals, $gid, $companyId);
        $upd = $conn->prepare("UPDATE contact_groups SET " . implode(',', $set) . " WHERE id=? AND company_id=?");
        if ($upd) {
            $upd->bind_param($types, ...$vals);
            if ($upd->execute()) { header("Location: contact_groups.php?msg=updated"); exit(); }
        }
        $error = "Could not save the group. Please try again.";
    }
    if ($error) $reopen = 'edit';
}

// --- Handle DELETE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    csrf_verify();
    if ($viewOnlyMode) { header("Location: contact_groups.php?msg=expired_block"); exit(); }
    $gid = intval($_POST['group_id'] ?? 0);
    if ($gid > 0) {
        if ($hasGroupId) {
            // Contacts are never deleted, only ungrouped
            $unlinkStmt = $conn->prepare("UPDATE contacts SET group_id=NULL WHERE group_id=? AND company_id=?");
            if ($unlinkStmt) { $unlinkStmt->bind_param("ii", $gid, $companyId); $unlinkStmt->execute(); }
        }
        $del = $conn->prepare("DELETE FROM contact_groups WHERE id=? AND company_id=?");
        if ($del) { $del->bind_param("ii", $gid, $companyId); $del->execute(); }
    }
    header("Location: contact_groups.php?msg=deleted");
    exit();
}

// --- Fetch groups with contact counts ---
$groups = [];
$orderBy = $hasCreated ? "g.created_at DESC, g.id DESC" : "g.id DESC";
if ($hasGroupId) {
    $sql = "SELECT g.*, COUNT(c.id) AS contact_count FROM contact_groups g LEFT JOIN contacts c ON c.group_id = g.id AND c.company_id = ? WHERE g.company_id = ? GROUP BY g.id ORDER BY $orderBy";
    $grpStmt = $conn->prepare($sql);
    if ($grpStmt) $grpStmt->bind_param("ii", $companyId, $companyId);
} else {
    $grpStmt = $conn->prepare("SELECT g.*, 0 AS contact_count FROM contact_groups g WHERE g.company_id = ? ORDER BY $orderBy");
    if ($grpStmt) $grpStmt->bind_param("i", $companyId);
}
if ($grpStmt) {
    $grpStmt->execute();
    $gr = $grpStmt->get_result();
    while ($gr && ($r = $gr->fetch_assoc())) { $groups[] = $r; }
}

$totalContacts = 0; $ungrouped = 0;
$st = $conn->prepare("SELECT COUNT(*) AS c FROM contacts WHERE company_id=?");
if ($st) { $st->bind_param("i", $companyId); $st->execute(); $totalContacts = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); }
$grouped = 0; $largest = ['name' => '', 'n' => 0];
foreach ($groups as $g) {
    $n = (int)$g['contact_count']; $grouped += $n;
    if ($n > $largest['n']) $largest = ['name' => (string)$g['name'], 'n' => $n];
}
$ungrouped = max(0, $totalContacts - $grouped);

include '../includes/client_header.php';
?>

<style>
/* ── Contact Groups — dashboard design system, no divider lines ── */
.cg,.cg-modal{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.cg *,.cg-modal *{box-sizing:border-box}
.cg-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.cg-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.cg-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.cg-hero-ico svg{width:22px;height:22px}
.cg-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.cg-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:540px;line-height:1.5}
.cg-acts{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
.cg-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s}
.cg-btn:hover{border-color:var(--g);color:var(--g)}
.cg-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.cg-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.cg-btn--muted{opacity:.55;cursor:not-allowed}
.cg-btn--danger{background:#c62828;border-color:#c62828;color:#fff}
.cg-btn--danger:hover{background:#a81f1f;border-color:#a81f1f;color:#fff}
.cg-btn.sm{height:36px;padding:0 13px;font-size:13px;border-radius:10px}
.cg-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;font-weight:600;background:#E3F7EE;color:#0B6B5E}
.cg-notice.err{background:#FDE7E7;color:#9b1c1c}
.cg-notice a{color:inherit;text-decoration:underline;font-weight:700}

.cg-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.cg-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);transition:transform .2s,box-shadow .2s;min-width:0}
.cg-kpi:hover{transform:translateY(-2px);box-shadow:0 16px 34px -22px rgba(18,140,126,.6)}
.cg-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.cg-kpi-ico svg{width:20px;height:20px}
.cg-kpi.blue .cg-kpi-ico{background:#E4EFFD;color:#1d6fd6}.cg-kpi.amber .cg-kpi-ico{background:#FEF3DC;color:#b7791f}.cg-kpi.purple .cg-kpi-ico{background:#EFE9FD;color:#6d44d8}
.cg-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.cg-kpi-v.sm{font-size:16px;letter-spacing:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:150px}
.cg-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}

.cg-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.cg-search{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:0 14px;height:42px;min-width:260px;flex:1;max-width:400px}
.cg-search svg{width:16px;height:16px;color:var(--mu);flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.cg-search input{border:0;background:transparent;outline:none;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);width:100%}
.cg-search:focus-within{border-color:var(--g);box-shadow:0 0 0 3px rgba(18,140,126,.12)}
.cg-sel{height:42px;border:1px solid var(--line);border-radius:11px;background:#fff;padding:0 34px 0 14px;font:600 13.5px Inter,system-ui,sans-serif;color:var(--tx);outline:none;cursor:pointer}

.cg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:18px}
.cg-card{--c:#128C7E;background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);display:flex;flex-direction:column;gap:14px;transition:transform .2s,box-shadow .2s;min-width:0}
.cg-card:hover{transform:translateY(-2px);box-shadow:0 18px 36px -22px rgba(18,140,126,.6)}
.cg-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.cg-tile{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;flex-shrink:0;color:var(--c);background:#E3F7EE;background:color-mix(in srgb,var(--c) 14%,#fff)}
.cg-tile svg{width:22px;height:22px}
.cg-menu{display:flex;gap:4px}
.cg-ib{width:34px;height:34px;border-radius:9px;border:0;background:var(--soft);color:var(--tx);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none;transition:background .15s,color .15s}
.cg-ib:hover{background:var(--g);color:#fff}.cg-ib.danger:hover{background:#c62828}
.cg-ib svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.cg-name{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px;overflow-wrap:anywhere}
.cg-desc{margin:4px 0 0;font-size:13.5px;color:var(--mu);line-height:1.5;overflow-wrap:anywhere}
.cg-count{display:flex;align-items:baseline;gap:6px}
.cg-count b{font-size:26px;font-weight:800;letter-spacing:-.5px}
.cg-count span{font-size:13px;color:var(--mu);font-weight:600}
.cg-bar{height:7px;border-radius:99px;background:#E7F0EC;overflow:hidden}
.cg-bar i{display:block;height:100%;border-radius:99px;background:var(--c)}
.cg-share{font-size:12.5px;color:var(--mu);font-weight:600}
.cg-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:auto}
.cg-foot small{font-size:12px;color:var(--mu)}
.cg-links{display:flex;gap:8px}
.cg-empty{background:#fff;border:1px solid var(--line);border-radius:16px;padding:56px 24px;text-align:center;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.cg-empty-ico{width:72px;height:72px;border-radius:50%;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto 16px}
.cg-empty-ico svg{width:30px;height:30px}
.cg-empty h3{margin:0 0 6px;font-size:19px;font-weight:800}
.cg-empty p{margin:0 auto 18px;color:var(--mu);font-size:14.5px;max-width:420px;line-height:1.55}
.cg-none{display:none;text-align:center;color:var(--mu);padding:34px;font-size:14.5px}

.cg-tips{display:flex;gap:14px;align-items:center;margin-top:22px;padding:18px 22px;border-radius:16px;background:var(--soft)}
.cg-tips-ico{width:42px;height:42px;border-radius:10px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.cg-tips-ico svg{width:20px;height:20px}
.cg-tips b{display:block;font-size:14.5px;margin-bottom:2px}
.cg-tips span{font-size:13.5px;color:var(--mu);line-height:1.5}

/* modal */
.cg-modal{position:fixed;inset:0;z-index:9999;background:rgba(11,20,26,.5);backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;padding:16px}
.cg-modal.open{display:flex}
.cg-dialog{width:min(500px,100%);max-height:calc(100vh - 32px);overflow:auto;background:#fff;border-radius:20px;box-shadow:0 30px 70px rgba(0,0,0,.3);padding:26px 26px 22px;animation:cgin .22s ease both}
.cg-dlg-hd{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.cg-dlg-ic{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;flex:none;background:#E3F7EE;color:var(--g);transition:background .2s,color .2s}
.cg-dlg-ic svg{width:22px;height:22px}
.cg-dlg-hd h2{margin:0 0 3px}
.cg-dlg-hd p{margin:0;color:var(--mu);font-size:13.5px;line-height:1.45}
.cg-prev{display:flex;align-items:center;gap:10px;margin:2px 0 4px;padding:12px 14px;border-radius:12px;background:var(--soft);font-size:13px;color:var(--mu)}
.cg-prev b{display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:999px;background:#fff;color:var(--ink);font-size:13px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cg-prev i{width:10px;height:10px;border-radius:50%;flex:none}
.cg-dlg-acts .cg-btn{min-width:120px}
.cg-f input[type=text]::placeholder{color:#8C9C96}
@keyframes cgin{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.cg-dialog h2{margin:0 0 4px;font-size:20px;font-weight:800}
.cg-dialog>p{margin:0 0 18px;color:var(--mu);font-size:14px}
.cg-f{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.cg-f label{font-size:13px;font-weight:700;display:flex;justify-content:space-between}
.cg-f label em{font-style:normal;font-weight:600;color:var(--mu);font-size:12px}
.cg-f input[type=text]{width:100%;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);background:var(--soft);border:1.5px solid transparent;border-radius:11px;padding:12px 14px;min-height:46px;outline:none;transition:border-color .15s,background .15s}
.cg-f input[type=text]:focus{border-color:var(--g);background:#fff}
.cg-sw{display:flex;gap:10px;flex-wrap:wrap}
.cg-sw label{position:relative;cursor:pointer}
.cg-sw input{position:absolute;opacity:0}
.cg-sw span{display:block;width:32px;height:32px;border-radius:50%;box-shadow:inset 0 0 0 2px rgba(255,255,255,.7);transition:transform .15s,outline-color .15s;outline:3px solid transparent;outline-offset:2px}
.cg-sw input:checked+span{outline-color:currentColor;transform:scale(1.08)}
.cg-sw input:focus-visible+span{outline-color:#0A78BE}
.cg-dlg-acts{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;flex-wrap:wrap}

@media (max-width:1100px){.cg-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.cg-hero{padding:18px}.cg-hero h1{font-size:21px}.cg-kpis{grid-template-columns:1fr}.cg-search{min-width:0;max-width:none}.cg-acts,.cg-acts .cg-btn{width:100%}}
@media (prefers-reduced-motion:reduce){.cg-dialog{animation:none}}
</style>

<div class="cg">

<?php if (isset($_GET['msg'])):
    $msgMap = ['created' => 'Group created.', 'updated' => 'Group updated.', 'deleted' => 'Group deleted. Its contacts were kept and are now ungrouped.', 'expired_block' => null];
    $m = (string)$_GET['msg'];
    if ($m === 'expired_block'): ?>
        <div class="cg-notice err">Action restricted. Your plan has expired. <a href="subscription.php">Renew now</a></div>
    <?php elseif (isset($msgMap[$m])): ?>
        <div class="cg-notice"><?php echo htmlspecialchars($msgMap[$m]); ?></div>
    <?php endif; endif; ?>
<?php if ($error): ?><div class="cg-notice err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<div class="cg-hero">
    <div class="cg-hero-l">
        <div class="cg-hero-ico"><?php echo cn_svg('groups'); ?></div>
        <div>
            <h1>Contact Groups</h1>
            <p>Organise your audience into groups, then send each campaign to exactly the people who should get it.</p>
        </div>
    </div>
    <div class="cg-acts">
        <a href="contacts.php" class="cg-btn">All contacts</a>
        <a href="contact_import.php" class="cg-btn">Import CSV</a>
        <?php if ($viewOnlyMode): ?>
            <span class="cg-btn cg-btn--muted">New group</span>
        <?php else: ?>
            <button type="button" class="cg-btn cg-btn--primary" id="cgNew">+ New group</button>
        <?php endif; ?>
    </div>
</div>

<div class="cg-kpis">
    <div class="cg-kpi"><div class="cg-kpi-ico"><?php echo cn_svg('groups'); ?></div><div><div class="cg-kpi-v"><?php echo number_format(count($groups)); ?></div><div class="cg-kpi-l">Groups</div></div></div>
    <div class="cg-kpi blue"><div class="cg-kpi-ico"><?php echo cn_svg('contacts'); ?></div><div><div class="cg-kpi-v"><?php echo number_format($grouped); ?></div><div class="cg-kpi-l">Contacts in groups</div></div></div>
    <div class="cg-kpi amber"><div class="cg-kpi-ico"><?php echo cn_svg('profile'); ?></div><div><div class="cg-kpi-v"><?php echo number_format($ungrouped); ?></div><div class="cg-kpi-l">Not in any group</div></div></div>
    <div class="cg-kpi purple"><div class="cg-kpi-ico"><?php echo cn_svg('growth'); ?></div><div><div class="cg-kpi-v sm" title="<?php echo htmlspecialchars($largest['name']); ?>"><?php echo $largest['n'] > 0 ? htmlspecialchars($largest['name']) : 'None yet'; ?></div><div class="cg-kpi-l">Largest group<?php echo $largest['n'] > 0 ? ' (' . number_format($largest['n']) . ')' : ''; ?></div></div></div>
</div>

<?php if (count($groups) > 0): ?>
<div class="cg-toolbar">
    <label class="cg-search">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="cgSearch" placeholder="Search groups" autocomplete="off">
    </label>
    <select class="cg-sel" id="cgSort" aria-label="Sort groups">
        <option value="new">Newest first</option>
        <option value="size">Most contacts</option>
        <option value="name">Name A to Z</option>
    </select>
</div>

<div class="cg-grid" id="cgGrid">
    <?php foreach ($groups as $i => $g):
        $cnt   = (int)$g['contact_count'];
        $color = $hasColor ? $cleanColor($g['color'] ?? '') : $swatches[0];
        $desc  = $hasDesc ? (string)($g['description'] ?? '') : '';
        $pct   = $totalContacts > 0 ? (int)round($cnt / $totalContacts * 100) : 0;
        $made  = ($hasCreated && !empty($g['created_at'])) ? date('d M Y', strtotime((string)$g['created_at'])) : '';
    ?>
    <article class="cg-card" style="--c:<?php echo $color; ?>" data-name="<?php echo htmlspecialchars(strtolower((string)$g['name']), ENT_QUOTES); ?>" data-size="<?php echo $cnt; ?>" data-order="<?php echo $i; ?>">
        <div class="cg-card-top">
            <div class="cg-tile"><?php echo cn_svg('groups'); ?></div>
            <?php if (!$viewOnlyMode): ?>
            <div class="cg-menu">
                <button type="button" class="cg-ib" title="Edit group" aria-label="Edit group"
                    data-edit data-id="<?php echo (int)$g['id']; ?>" data-name="<?php echo htmlspecialchars((string)$g['name'], ENT_QUOTES); ?>" data-desc="<?php echo htmlspecialchars($desc, ENT_QUOTES); ?>" data-color="<?php echo $color; ?>"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg></button>
                <button type="button" class="cg-ib danger" title="Delete group" aria-label="Delete group"
                    data-del data-id="<?php echo (int)$g['id']; ?>" data-name="<?php echo htmlspecialchars((string)$g['name'], ENT_QUOTES); ?>" data-count="<?php echo $cnt; ?>"><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
            </div>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="cg-name"><?php echo htmlspecialchars((string)$g['name']); ?></h3>
            <?php if ($desc !== ''): ?><p class="cg-desc"><?php echo htmlspecialchars($desc); ?></p><?php endif; ?>
        </div>
        <div>
            <div class="cg-count"><b><?php echo number_format($cnt); ?></b><span>contact<?php echo $cnt === 1 ? '' : 's'; ?></span></div>
            <div class="cg-bar" style="margin:8px 0 5px"><i style="width:<?php echo max($cnt > 0 ? 4 : 0, $pct); ?>%"></i></div>
            <div class="cg-share"><?php echo $pct; ?>% of all contacts</div>
        </div>
        <div class="cg-foot">
            <small><?php echo $made !== '' ? 'Created ' . $made : ''; ?></small>
            <div class="cg-links">
                <?php if ($hasGroupId): ?><a href="contacts.php?group=<?php echo (int)$g['id']; ?>" class="cg-btn sm">View</a><?php endif; ?>
                <?php if (!$viewOnlyMode): ?><a href="contact_import.php?group_id=<?php echo (int)$g['id']; ?>" class="cg-btn sm">Import</a><?php endif; ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<div class="cg-none" id="cgNone">No groups match your search.</div>

<div class="cg-tips">
    <div class="cg-tips-ico"><?php echo cn_svg('discover'); ?></div>
    <div><b>How groups work</b><span>Create a group, import contacts into it, then choose it as the audience when you create a campaign. Deleting a group never deletes its contacts.</span></div>
</div>

<?php else: ?>
<div class="cg-empty">
    <div class="cg-empty-ico"><?php echo cn_svg('groups'); ?></div>
    <h3>No groups yet</h3>
    <p>Create your first group to organise your audience, for example VIP customers or a festival list, and send targeted campaigns.</p>
    <?php if (!$viewOnlyMode): ?><button type="button" class="cg-btn cg-btn--primary" id="cgNew2">+ Create your first group</button><?php endif; ?>
</div>
<?php endif; ?>

</div>

<!-- Create / edit dialog -->
<div class="cg-modal" id="cgModal" role="dialog" aria-modal="true" aria-labelledby="cgModalTitle">
    <div class="cg-dialog">
        <div class="cg-dlg-hd">
            <span class="cg-dlg-ic" id="cgDlgIc"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
            <div><h2 id="cgModalTitle">New group</h2>
            <p id="cgModalSub">Give it a clear name so it is easy to pick in a campaign.</p></div>
        </div>
        <form method="POST" id="cgForm" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="group_id" id="cgId" value="0">
            <input type="hidden" name="create_group" id="cgAction" value="1">
            <div class="cg-f"><label for="cgName">Group name <em id="cgNameCnt">0/100</em></label>
                <input type="text" name="group_name" id="cgName" maxlength="100" required placeholder="e.g. VIP customers, Diwali list"></div>
            <div class="cg-f"><label for="cgDesc">Description <em id="cgDescCnt">Optional · 0/255</em></label>
                <input type="text" name="group_desc" id="cgDesc" maxlength="255" placeholder="e.g. Customers who spent over Rs 5,000"></div>
            <?php if ($hasColor): ?>
            <div class="cg-f"><label>Colour</label>
                <div class="cg-sw" id="cgSw">
                    <?php foreach ($swatches as $k => $sw): ?>
                        <label style="color:<?php echo $sw; ?>"><input type="radio" name="group_color" value="<?php echo $sw; ?>"<?php echo $k === 0 ? ' checked' : ''; ?>><span style="background:<?php echo $sw; ?>"></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="cg-prev">Preview <b id="cgPrev"><i id="cgPrevDot" style="background:#128C7E"></i><span id="cgPrevName">New group</span></b></div>
            <div class="cg-dlg-acts">
                <button type="button" class="cg-btn" id="cgCancel">Cancel</button>
                <button type="submit" class="cg-btn cg-btn--primary" id="cgSubmit">Create group</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete confirmation -->
<div class="cg-modal" id="cgDelModal" role="dialog" aria-modal="true" aria-labelledby="cgDelTitle">
    <div class="cg-dialog">
        <h2 id="cgDelTitle">Delete this group?</h2>
        <p id="cgDelText"></p>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="delete_group" value="1">
            <input type="hidden" name="group_id" id="cgDelId" value="0">
            <div class="cg-dlg-acts">
                <button type="button" class="cg-btn" id="cgDelCancel">Keep group</button>
                <button type="submit" class="cg-btn cg-btn--danger">Delete group</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var modal = $('cgModal'), del = $('cgDelModal');
    var restore = <?php echo json_encode(['mode' => $reopen, 'form' => $form], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    function counts() {
        $('cgNameCnt').textContent = $('cgName').value.length + '/100';
        $('cgDescCnt').textContent = 'Optional · ' + $('cgDesc').value.length + '/255';
    }
    function openForm(mode, d) {
        var edit = mode === 'edit';
        $('cgModalTitle').textContent = edit ? 'Edit group' : 'New group';
        $('cgModalSub').textContent = edit ? 'Rename it or change its description and colour.' : 'Give it a clear name so it is easy to pick in a campaign.';
        $('cgSubmit').textContent = edit ? 'Save changes' : 'Create group';
        var a = $('cgAction'); a.name = edit ? 'update_group' : 'create_group'; a.value = '1';
        $('cgId').value = edit ? d.id : 0;
        $('cgName').value = d.name || ''; $('cgDesc').value = d.desc || '';
        var sw = document.querySelectorAll('#cgSw input'); var want = d.color || '';
        sw.forEach(function (r, i) { r.checked = want ? (r.value.toLowerCase() === want.toLowerCase()) : i === 0; });
        if (sw.length && !document.querySelector('#cgSw input:checked')) sw[0].checked = true;
        counts(); modal.classList.add('open'); setTimeout(function () { $('cgName').focus(); }, 30);
    }
    function close() { modal.classList.remove('open'); del.classList.remove('open'); }

    ['cgNew', 'cgNew2'].forEach(function (id) { var b = $(id); if (b) b.addEventListener('click', function () { openForm('create', {}); }); });
    $('cgCancel').addEventListener('click', close);
    $('cgDelCancel').addEventListener('click', close);
    [modal, del].forEach(function (m) { m.addEventListener('click', function (e) { if (e.target === m) close(); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    $('cgName').addEventListener('input', counts); $('cgDesc').addEventListener('input', counts);

    document.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]'), dl = e.target.closest('[data-del]');
        if (ed) openForm('edit', { id: ed.dataset.id, name: ed.dataset.name, desc: ed.dataset.desc, color: ed.dataset.color });
        if (dl) {
            var n = parseInt(dl.dataset.count, 10) || 0;
            $('cgDelId').value = dl.dataset.id;
            $('cgDelText').textContent = '“' + dl.dataset.name + '” will be removed. ' + (n ? 'Its ' + n + ' contact' + (n === 1 ? '' : 's') + ' will not be deleted, they will simply have no group.' : 'It has no contacts.');
            del.classList.add('open');
        }
    });

    // Search + sort (client side, nothing to reload)
    var grid = $('cgGrid');
    if (grid) {
        var cards = Array.prototype.slice.call(grid.children), none = $('cgNone');
        function apply() {
            var q = ($('cgSearch').value || '').toLowerCase().trim(), shown = 0, mode = $('cgSort').value;
            cards.sort(function (a, b) {
                if (mode === 'size') return (+b.dataset.size) - (+a.dataset.size);
                if (mode === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                return (+a.dataset.order) - (+b.dataset.order);
            }).forEach(function (c) { grid.appendChild(c); });
            cards.forEach(function (c) { var ok = !q || c.dataset.name.indexOf(q) !== -1; c.style.display = ok ? '' : 'none'; if (ok) shown++; });
            none.style.display = shown ? 'none' : 'block';
        }
        $('cgSearch').addEventListener('input', apply); $('cgSort').addEventListener('change', apply);
    }

    // Re-open the dialog with the user's input after a validation error
    if (restore.mode === 'create') openForm('create', restore.form);
    else if (restore.mode === 'edit') openForm('edit', restore.form);
})();
</script>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    var name = document.getElementById('cgName'), nm = document.getElementById('cgPrevName'), dot = document.getElementById('cgPrevDot'), ic = document.getElementById('cgDlgIc');
    if (!name || !nm) { return; }
    function sync() {
        nm.textContent = name.value.trim() || 'New group';
        var c = document.querySelector('#cgSw input:checked');
        var col = c ? c.value : '#128C7E';
        dot.style.background = col;
        if (ic) { ic.style.color = col; ic.style.background = col + '1F'; }
    }
    name.addEventListener('input', sync);
    document.querySelectorAll('#cgSw input').forEach(function (r) { r.addEventListener('change', sync); });
    var m = document.getElementById('cgModal');
    if (m && window.MutationObserver) { new MutationObserver(sync).observe(m, {attributes: true, attributeFilter: ['class']}); }
    sync();
})();
</script>
<?php include '../includes/client_footer.php'; ?>

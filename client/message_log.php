<?php
include '../includes/client_auth_check.php';
if (!has_feature('message_log')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Message Log';
$currentPage = 'message_log';

// Filters — prepared statements to prevent SQL injection
$direction = in_array($_GET['direction'] ?? '', ['incoming', 'outgoing'], true) ? (string)$_GET['direction'] : '';
$status    = in_array($_GET['status'] ?? '', ['queued','sending','sent','delivered','read','failed','pending'], true) ? (string)$_GET['status'] : '';
$q         = trim((string)($_GET['q'] ?? ''));
$fromDate  = trim((string)($_GET['from'] ?? '')); // YYYY-MM-DD
$toDate    = trim((string)($_GET['to'] ?? ''));   // YYYY-MM-DD
$perPage   = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [25,50,100,200], true)) $perPage = 100;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;
$export    = (int)($_GET['export'] ?? 0) === 1;

$whereClauses = ['m.company_id = ?'];
$types        = 'i';
$params       = [$companyId];

if ($direction) {
    $whereClauses[] = 'm.direction = ?';
    $types          .= 's';
    $params[]        = $direction;
}
if ($status) {
    $whereClauses[] = 'm.status = ?';
    $types          .= 's';
    $params[]        = $status;
}
if ($q !== '') {
    // Match contact + message content + WA message id
    $whereClauses[] = "(c.name LIKE ? OR c.phone LIKE ? OR m.content LIKE ? OR m.message LIKE ? OR m.whatsapp_message_id LIKE ? OR m.error_message LIKE ?)";
    $types .= 'ssssss';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $whereClauses[] = "DATE(m.created_at) >= ?";
    $types .= 's';
    $params[] = $fromDate;
} else {
    $fromDate = '';
}
if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $whereClauses[] = "DATE(m.created_at) <= ?";
    $types .= 's';
    $params[] = $toDate;
} else {
    $toDate = '';
}

$whereSQL = implode(' AND ', $whereClauses);

// Total for pagination / export
$countStmt = $conn->prepare("
    SELECT COUNT(*) as c
    FROM messages m
    LEFT JOIN contacts c ON m.contact_id = c.id AND c.company_id = m.company_id
    WHERE $whereSQL
");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / max(1, $perPage)));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Export CSV (up to 5000 rows for safety)
if ($export) {
    $limit = min(5000, max(1, $perPage));
    $msgStmt = $conn->prepare("
        SELECT m.id, m.direction, m.status, m.type, m.whatsapp_message_id, m.error_message, m.created_at,
               COALESCE(NULLIF(m.content,''), m.message) as body,
               c.name as contact_name, c.phone as contact_phone
        FROM messages m
        LEFT JOIN contacts c ON m.contact_id = c.id AND c.company_id = m.company_id
        WHERE $whereSQL
        ORDER BY m.created_at DESC
        LIMIT $limit
    ");
    $msgStmt->bind_param($types, ...$params);
    $msgStmt->execute();
    $messages = $msgStmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="message_log.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Contact','Phone','Direction','Status','Type','WhatsApp Message ID','Message','Error','Created At']);
    while ($messages && ($m = $messages->fetch_assoc())) {
        fputcsv($out, [
            $m['id'] ?? '',
            $m['contact_name'] ?? '',
            $m['contact_phone'] ?? '',
            $m['direction'] ?? '',
            $m['status'] ?? '',
            $m['type'] ?? '',
            $m['whatsapp_message_id'] ?? '',
            $m['body'] ?? '',
            $m['error_message'] ?? '',
            $m['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit();
}

$msgStmt = $conn->prepare("
    SELECT m.id, m.direction, m.status, m.type, m.whatsapp_message_id, m.error_message, m.created_at,
           COALESCE(NULLIF(m.content,''), m.message) as body,
           c.name as contact_name, c.phone as contact_phone
    FROM messages m
    LEFT JOIN contacts c ON m.contact_id = c.id AND c.company_id = m.company_id
    WHERE $whereSQL
    ORDER BY m.created_at DESC
    LIMIT ? OFFSET ?
");
$typesPage = $types . 'ii';
$paramsPage = array_merge($params, [$perPage, $offset]);
$msgStmt->bind_param($typesPage, ...$paramsPage);
$msgStmt->execute();
$messages = $msgStmt->get_result();

// Stats — prepared statements
$stSent = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='outgoing'");
$stSent->bind_param("i", $companyId); $stSent->execute();
$totalSent = (int)($stSent->get_result()->fetch_assoc()['c'] ?? 0);

$stRec = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='incoming'");
$stRec->bind_param("i", $companyId); $stRec->execute();
$totalReceived = (int)($stRec->get_result()->fetch_assoc()['c'] ?? 0);

$stFail = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE company_id=? AND status='failed'");
$stFail->bind_param("i", $companyId); $stFail->execute();
$totalFailed = (int)($stFail->get_result()->fetch_assoc()['c'] ?? 0);

include '../includes/client_header.php';
?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(0,184,148,0.12);color:#00b894;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg></div><div class="stat-info"><div class="stat-number"><?php echo number_format($totalSent); ?></div><div class="stat-label">Sent</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(9,132,227,0.12);color:#0984e3;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg></div><div class="stat-info"><div class="stat-number"><?php echo number_format($totalReceived); ?></div><div class="stat-label">Received</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(231,76,60,0.12);color:#e74c3c;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></div><div class="stat-info"><div class="stat-number"><?php echo $totalFailed; ?></div><div class="stat-label">Failed</div></div></div>
</div>

<!-- Filters -->
<div class="filter-bar" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;align-items:center;">
    <?php
        $base = 'message_log.php';
        $keep = [
            'direction' => $direction,
            'status' => $status,
            'q' => $q,
            'from' => $fromDate,
            'to' => $toDate,
            'per_page' => (string)$perPage
        ];
        $qs = function(array $over = []) use ($keep): string {
            $m = array_merge($keep, $over);
            // drop empties
            foreach ($m as $k => $v) { if ($v === '' || $v === null) unset($m[$k]); }
            return http_build_query($m);
        };
    ?>
    <a href="<?php echo $base; ?>" class="btn btn-sm <?php echo(!$direction && !$status && $q==='' && $fromDate==='' && $toDate==='') ? 'btn-primary' : 'btn-edit'; ?>">All</a>
    <a href="<?php echo $base . '?' . $qs(['direction'=>'outgoing','page'=>1]); ?>" class="btn btn-sm <?php echo $direction === 'outgoing' ? 'btn-primary' : 'btn-edit'; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Outgoing</a>
    <a href="<?php echo $base . '?' . $qs(['direction'=>'incoming','page'=>1]); ?>" class="btn btn-sm <?php echo $direction === 'incoming' ? 'btn-primary' : 'btn-edit'; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Incoming</a>
    <a href="<?php echo $base . '?' . $qs(['status'=>'failed','page'=>1]); ?>" class="btn btn-sm <?php echo $status === 'failed' ? 'btn-primary' : 'btn-edit'; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Failed</a>
    <a href="<?php echo $base . '?' . $qs(['status'=>'sending','page'=>1]); ?>" class="btn btn-sm <?php echo $status === 'sending' ? 'btn-primary' : 'btn-edit'; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Sending</a>
    <a href="<?php echo $base . '?' . $qs(['export'=>1]); ?>" class="btn btn-sm btn-edit" style="margin-left:auto;">Export CSV</a>
</div>

<form method="GET" action="message_log.php" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;align-items:end;">
    <div class="form-group" style="margin:0;min-width:220px;flex:1;">
        <label style="font-size:12px;color:var(--text-muted);">Search</label>
        <input class="form-control" type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="name, phone, message, WA id, error">
    </div>
    <div class="form-group" style="margin:0;">
        <label style="font-size:12px;color:var(--text-muted);">From</label>
        <input class="form-control" type="date" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
    </div>
    <div class="form-group" style="margin:0;">
        <label style="font-size:12px;color:var(--text-muted);">To</label>
        <input class="form-control" type="date" name="to" value="<?php echo htmlspecialchars($toDate); ?>">
    </div>
    <div class="form-group" style="margin:0;">
        <label style="font-size:12px;color:var(--text-muted);">Show</label>
        <select class="form-control" name="per_page">
            <?php foreach ([25,50,100,200] as $n): ?>
                <option value="<?php echo $n; ?>" <?php echo $perPage===$n?'selected':''; ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <input type="hidden" name="direction" value="<?php echo htmlspecialchars($direction); ?>">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
    <button class="btn btn-primary" type="submit">Apply</button>
</form>

<div class="data-table-wrapper">
    <table class="data-table">
        <thead>
            <tr><th>Contact</th><th>Phone</th><th>Message</th><th>Direction</th><th>Status</th><th>Time</th></tr>
        </thead>
        <tbody>
            <?php if ($messages && $messages->num_rows > 0):
    while ($m = $messages->fetch_assoc()): ?>
            <tr>
                <td style="color:var(--text-primary);font-weight:500;"><?php echo htmlspecialchars($m['contact_name'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($m['contact_phone'] ?? '—'); ?></td>
                <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(substr((string)($m['body'] ?? ''), 0, 80)); ?></td>
                <td><span class="badge <?php echo $m['direction'] === 'incoming' ? 'badge-active' : 'badge-admin'; ?>"><?php echo $m['direction'] === 'incoming' ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> In' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Out'; ?></span></td>
                <td>
                    <?php
        $sMap = [
            'queued' => ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Queued', 'badge-draft'],
            'pending' => ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending', 'badge-draft'],
            'sending' => ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Sending', 'badge-draft'],
            'sent' => ['✓ Sent', 'badge-active'],
            'delivered' => ['<span style="color:#53bdeb;font-size:12px;font-weight:700">✓✓</span> Delivered', 'badge-active'],
            'read' => ['<span style="color:#53bdeb;font-size:12px;font-weight:700">✓✓</span> Read', 'badge-admin'],
            'received' => ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Received', 'badge-active'],
            'failed' => ['<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Failed', 'badge-suspended'],
        ];
        $st = (string)($m['status'] ?? '');
        $s = $sMap[$st] ?? [$st !== '' ? $st : '—', ''];
?>
                    <span class="badge <?php echo htmlspecialchars($s[1], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$s[0], ENT_QUOTES, 'UTF-8'); ?></span>
                </td>
                <td style="font-size:12px;">
                    <?php
                        $ts = isset($m['created_at']) ? strtotime((string)$m['created_at']) : false;
                        echo $ts ? date('d M H:i', $ts) : '—';
                    ?>
                </td>
            </tr>
            <?php
    endwhile;
else: ?>
                <tr><td colspan="6"><div class="empty-state"><p>No messages logged</p></div></td></tr>
            <?php
endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalRows > 0): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:10px;flex-wrap:wrap;">
        <div style="font-size:12px;color:var(--text-muted);">
            Showing <?php echo number_format(min($totalRows, $offset + 1)); ?>–<?php echo number_format(min($totalRows, $offset + $perPage)); ?>
            of <?php echo number_format($totalRows); ?>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
            <?php
                $mk = function(int $p) use ($base, $qs): string { return $base . '?' . $qs(['page'=>$p]); };
                $prev = max(1, $page - 1);
                $next = min($totalPages, $page + 1);
            ?>
            <a class="btn btn-sm btn-edit" href="<?php echo htmlspecialchars($mk(1)); ?>" <?php echo $page<=1?'style="pointer-events:none;opacity:0.5"':''; ?>>First</a>
            <a class="btn btn-sm btn-edit" href="<?php echo htmlspecialchars($mk($prev)); ?>" <?php echo $page<=1?'style="pointer-events:none;opacity:0.5"':''; ?>>Prev</a>
            <span style="font-size:12px;color:var(--text-muted);padding:0 6px;">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
            <a class="btn btn-sm btn-edit" href="<?php echo htmlspecialchars($mk($next)); ?>" <?php echo $page>=$totalPages?'style="pointer-events:none;opacity:0.5"':''; ?>>Next</a>
            <a class="btn btn-sm btn-edit" href="<?php echo htmlspecialchars($mk($totalPages)); ?>" <?php echo $page>=$totalPages?'style="pointer-events:none;opacity:0.5"':''; ?>>Last</a>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/client_footer.php'; ?>

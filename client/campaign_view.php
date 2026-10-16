<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
if (!has_feature('campaigns')) {
    header("Location: dashboard.php");
    exit();
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: campaigns.php");
    exit();
}

// Fetch Campaign Details with template content for preview
$campaign = null;
$cStmt = $conn->prepare("
    SELECT c.*, mt.name as template_name, mt.body_text, mt.header_text, mt.footer_text, mt.category, mt.header_type AS tpl_header_type, mt.header_link AS tpl_header_link, cg.name as group_name 
    FROM campaigns c 
    LEFT JOIN message_templates mt ON c.template_id = mt.id 
    LEFT JOIN contact_groups cg ON c.group_id = cg.id 
    WHERE c.id = ? AND c.company_id = ?
");
if ($cStmt) {
    $cStmt->bind_param("ii", $id, $companyId);
    $cStmt->execute();
    $campaign = $cStmt->get_result()->fetch_assoc();
}

if (!$campaign) {
    header("Location: campaigns.php?msg=not_found");
    exit();
}

// Ensure campaign_queue has processed_at column (backwards compat)
try { $conn->query("ALTER TABLE campaign_queue ADD COLUMN processed_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) { /* ignore */ }

// Handle manual "Process Queue" trigger (styled like admin button)
if (isset($_POST['process_queue']) && in_array($campaign['status'], ['running', 'scheduled'])) {
    csrf_verify();
    // Redirect with a flag to trigger the JS tick
    header("Location: campaign_view.php?id=$id&msg=processing&trigger_tick=1");
    exit();
}

// Handle "Retry Failed"
if (isset($_POST['retry_failed'])) {
    csrf_verify();
    // Templates with an image/video/document header need that media in every message, otherwise Meta
    // rejects them again (#132012). Ask for it here and put it into the failed messages before retrying.
    $hdrType = strtolower(trim((string)($campaign['tpl_header_type'] ?? '')));
    if (in_array($hdrType, ['image', 'video', 'document'], true)) {
        $mediaLink = trim((string)($_POST['retry_media_url'] ?? ''));
        if (isset($_FILES['retry_media_file']) && (int)$_FILES['retry_media_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string)$_FILES['retry_media_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'pdf'], true)) {
                @mkdir('../uploads/campaigns/', 0755, true);
                $fn = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($_FILES['retry_media_file']['tmp_name'], '../uploads/campaigns/' . $fn)) {
                    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
                    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                    $base = ($https && stripos($host, 'localhost') === false && stripos($host, '127.0.0.1') === false)
                        ? 'https://' . $host . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/')
                        : rtrim((string)(\Waamini\Config\Env::get('PUBLIC_URL', '') ?: ''), '/');
                    $mediaLink = $base !== '' ? $base . '/uploads/campaigns/' . $fn : '';
                }
            }
        }
        if ($mediaLink === '') { $mediaLink = trim((string)($campaign['media_url'] ?? '')) ?: trim((string)($campaign['tpl_header_link'] ?? '')); }
        if (!preg_match('#^https://#i', $mediaLink) || preg_match('#//(localhost|127\.0\.0\.1)[:/]#i', $mediaLink)) {
            header("Location: campaign_view.php?id=$id&msg=retry_needs_media");
            exit();
        }
        $rows = $conn->prepare("SELECT id, message_payload FROM campaign_queue WHERE campaign_id=? AND company_id=? AND status='failed'");
        $rows->bind_param('ii', $id, $companyId);
        $rows->execute();
        $fix = $conn->prepare("UPDATE campaign_queue SET message_payload=? WHERE id=? AND company_id=?");
        foreach ($rows->get_result()->fetch_all(MYSQLI_ASSOC) as $q) {
            $p = json_decode((string)$q['message_payload'], true);
            if (!is_array($p)) { continue; }
            $p['header_type'] = $hdrType;
            $p['header_link'] = $mediaLink;
            $pj = json_encode($p, JSON_UNESCAPED_UNICODE);
            $qid = (int)$q['id'];
            $fix->bind_param('sii', $pj, $qid, $companyId);
            $fix->execute();
        }
        $um = $conn->prepare("UPDATE campaigns SET media_url=?, media_type=? WHERE id=? AND company_id=?");
        $um->bind_param('ssii', $mediaLink, $hdrType, $id, $companyId);
        $um->execute();
    }
    $retryStmt = $conn->prepare("UPDATE campaign_queue SET status='pending', error_message=NULL WHERE campaign_id=? AND company_id=? AND status='failed'");
    if ($retryStmt) {
        $retryStmt->bind_param('ii', $id, $companyId);
        $retryStmt->execute();
        $retriedCount = $retryStmt->affected_rows;
    }
    if ($campaign['status'] === 'completed' && ($retriedCount ?? 0) > 0) {
        $rerunStmt = $conn->prepare("UPDATE campaigns SET status='running', completed_at=NULL WHERE id=? AND company_id=?");
        if ($rerunStmt) {
            $rerunStmt->bind_param('ii', $id, $companyId);
            $rerunStmt->execute();
        }
    }
    header("Location: campaign_view.php?id=$id&msg=retried&trigger_tick=1");
    exit();
}

// Recampaign (create a new campaign from a segment)
if (isset($_POST['recampaign_segment'])) {
    csrf_verify();
    if ($viewOnlyMode) {
        header("Location: campaign_view.php?id=" . $id . "&msg=expired_block");
        exit();
    }
    $segment = (string)($_POST['recampaign_segment'] ?? '');
    $allowed = ['accepted', 'queue', 'sent', 'delivered', 'read', 'failed', 'expired'];
    if (!in_array($segment, $allowed, true)) {
        header("Location: campaign_view.php?id=" . $id . "&msg=bad_segment");
        exit();
    }

    $where = "";
    $whereTypes = '';
    $whereParams = [];
    if ($segment === 'queue') {
        $where = " AND q.status IN ('pending','processing')";
    } elseif ($segment === 'accepted') {
        $where = " AND q.status=?";
        $whereTypes = 's';
        $whereParams[] = 'sent';
    } elseif ($segment === 'failed') {
        $where = " AND q.status=?";
        $whereTypes = 's';
        $whereParams[] = 'failed';
    } elseif ($segment === 'expired') {
        $where = " AND q.status=?";
        $whereTypes = 's';
        $whereParams[] = 'expired';
    } else {
        $where = " AND q.wa_delivery_status=?";
        $whereTypes = 's';
        $whereParams[] = $segment;
    }

    $selQ = $conn->prepare("SELECT q.contact_id, q.phone FROM campaign_queue q WHERE q.company_id=? AND q.campaign_id=? $where GROUP BY q.contact_id, q.phone");
    $recipients = [];
    if ($selQ) {
        $bindTypes = 'ii' . $whereTypes;
        $bindParams = array_merge([$companyId, $id], $whereParams);
        $selQ->bind_param($bindTypes, ...$bindParams);
        $selQ->execute();
        $resQ = $selQ->get_result();
        while ($r = $resQ->fetch_assoc()) {
            $cid = (int)($r['contact_id'] ?? 0);
            $ph = (string)($r['phone'] ?? '');
            if ($cid > 0 && $ph !== '') $recipients[] = ['contact_id' => $cid, 'phone' => $ph];
        }
    }

    if (empty($recipients)) {
        header("Location: campaign_view.php?id=" . $id . "&msg=none");
        exit();
    }

    $newName = trim($campaign['name'] . ' (Recampaign ' . ucfirst($segment) . ')');
    $tplId = (int)($campaign['template_id'] ?? 0);
    $grpId = (int)($campaign['group_id'] ?? 0);
    $mediaUrl = (string)($campaign['media_url'] ?? '');
    $mediaType = (string)($campaign['media_type'] ?? '');
    $waPhoneId = (string)($campaign['whatsapp_phone_number_id'] ?? '');
    $total = count($recipients);

    $insC = $conn->prepare("INSERT INTO campaigns (company_id, name, template_id, group_id, status, total_contacts, sent_count, send_type, scheduled_at, media_url, media_type, whatsapp_phone_number_id) VALUES (?,?,?,?,?, ?,0,'now', NOW(), ?,?,?)");
    $newCampaignId = 0;
    if ($insC) {
        $status = 'running';
        $insC->bind_param('isiisisss', $companyId, $newName, $tplId, $grpId, $status, $total, $mediaUrl, $mediaType, $waPhoneId);
        $insC->execute();
        $newCampaignId = (int)$conn->insert_id;
    }

    if ($newCampaignId <= 0) {
        header("Location: campaign_view.php?id=" . $id . "&msg=fail");
        exit();
    }

    $tplName = ''; $tplLang = 'en';
    $stT = $conn->prepare("SELECT name, language FROM message_templates WHERE id=? LIMIT 1");
    if ($stT) {
        $stT->bind_param('i', $tplId);
        $stT->execute();
        $t = $stT->get_result()->fetch_assoc();
        $tplName = (string)($t['name'] ?? '');
        $tplLang = (string)($t['language'] ?? 'en');
    }

    $insQ = $conn->prepare("INSERT INTO campaign_queue (company_id, campaign_id, contact_id, phone, wa_phone_id, message_payload, scheduled_at, status) VALUES (?,?,?,?,?,?, NOW(),'pending')");
    if ($insQ) {
        foreach ($recipients as $r) {
            $payload = ['phone_number' => $r['phone'], 'template_name' => $tplName, 'template_language' => $tplLang];
            if ($mediaUrl !== '') {
                $payload['header_type'] = $mediaType !== '' ? $mediaType : 'image';
                $payload['header_link'] = $mediaUrl;
            }
            $json = json_encode($payload);
            $cid = (int)$r['contact_id'];
            $ph = (string)$r['phone'];
            $insQ->bind_param('iiisss', $companyId, $newCampaignId, $cid, $ph, $waPhoneId, $json);
            $insQ->execute();
        }
    }
    header("Location: campaign_view.php?id=" . $newCampaignId . "&msg=recampaigned");
    exit();
}

// Read stats from campaign_queue (Admin style)
$stats = ['total' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'delivered' => 0, 'read_count' => 0, 'failed' => 0];
$sStmt = $conn->prepare("SELECT COUNT(*) as total,
    SUM(status='pending') as queued,
    SUM(status='processing') as processing,
    SUM(status='sent' OR status='queued_wa') as sent,
    SUM(wa_delivery_status='delivered') as delivered,
    SUM(wa_delivery_status='read') as read_count,
    SUM(status='failed' OR wa_delivery_status='failed') as failed
    FROM campaign_queue WHERE campaign_id = ? AND company_id = ?");
if ($sStmt) {
    $sStmt->bind_param('ii', $id, $companyId);
    $sStmt->execute();
    $sRes = $sStmt->get_result();
    $stats = $sRes ? ($sRes->fetch_assoc() ?: $stats) : $stats;
}

// Auto-complete: if campaign is 'running' and ALL queue entries are done
if ($campaign['status'] === 'running' && ($stats['total'] ?? 0) > 0) {
    $pendingLeft = ($stats['queued'] ?? 0) + ($stats['processing'] ?? 0);
    if ($pendingLeft === 0) {
        $doneStmt = $conn->prepare("UPDATE campaigns SET status='completed', completed_at=NOW() WHERE id=? AND company_id=? AND status='running'");
        if ($doneStmt) {
            $doneStmt->bind_param('ii', $id, $companyId);
            $doneStmt->execute();
            if ($doneStmt->affected_rows > 0) {
                $campaign['status'] = 'completed';
            }
        }
    }
}

// Fix stuck 'processing' entries
$stuckStmt = $conn->prepare("UPDATE campaign_queue SET status='pending' WHERE campaign_id=? AND company_id=? AND status='processing' AND processed_at IS NOT NULL AND processed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
if ($stuckStmt) {
    $stuckStmt->bind_param('ii', $id, $companyId);
    $stuckStmt->execute();
}

// Export current view to CSV
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $tab = strtolower((string)($_GET['tab'] ?? 'executed'));
    $search = trim((string)($_GET['q'] ?? ''));
    $filterSql = "1=1";
    if ($tab === 'queue') $filterSql = "q.status IN ('pending','processing')";
    elseif ($tab === 'expired') $filterSql = "q.status='expired'";
    else $filterSql = "(q.status IN ('sent','failed','expired') OR (q.status NOT IN ('pending','processing') AND q.status IS NOT NULL) OR (q.wa_delivery_status IS NOT NULL AND q.wa_delivery_status<>''))";

    $extraWhere = ""; $params = [$id, $companyId]; $types = "ii";
    if ($search !== '') { $extraWhere .= " AND (c.name LIKE ? OR q.phone LIKE ?)"; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; $types .= "ss"; }

    $ex = $conn->prepare("SELECT COALESCE(c.name,'') as contact_name, COALESCE(q.phone,'') as phone, COALESCE(q.wa_delivery_status,'') as wa_delivery_status, COALESCE(q.status,'') as queue_status, COALESCE(q.wa_delivery_ts, q.scheduled_at) as last_status_at, COALESCE(q.error_message,'') as error_message FROM campaign_queue q LEFT JOIN contacts c ON q.contact_id=c.id AND c.company_id=q.company_id WHERE q.campaign_id=? AND q.company_id=? AND ($filterSql) $extraWhere ORDER BY q.id ASC");
    if ($ex) {
        $ex->bind_param($types, ...$params); $ex->execute(); $rs = $ex->get_result();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="campaign_' . $id . '_' . $tab . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Phone', 'Message Delivery Status', 'Queue Status', 'Last Status Updated At', 'Error']);
        while ($r = $rs->fetch_assoc()) fputcsv($out, [$r['contact_name'], $r['phone'], $r['wa_delivery_status'] !== '' ? $r['wa_delivery_status'] : $r['queue_status'], $r['queue_status'], $r['last_status_at'], $r['error_message']]);
        fclose($out); exit();
    }
}

// Progress calculations
$sentCount = ($stats['sent'] ?? 0);
$failedCount = ($stats['failed'] ?? 0);
$processedCount = $sentCount + $failedCount;
$totalContacts = max(1, $campaign['total_contacts']);
$progress = round(($processedCount / $totalContacts) * 100);
if ($progress > 100) $progress = 100;
$successRate = $processedCount > 0 ? round(($sentCount / $processedCount) * 100) : 0;

// Status config
$statusConfig = [
    'draft'     => ['color' => '#ff9800', 'bg' => 'rgba(255,152,0,0.10)', 'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>', 'label' => 'Draft'],
    'scheduled' => ['color' => '#2196f3', 'bg' => 'rgba(33,150,243,0.10)', 'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', 'label' => 'Scheduled'],
    'running'   => ['color' => '#00c853', 'bg' => 'rgba(0,200,83,0.10)',   'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><polygon points="6 3 20 12 6 21 6 3"/></svg>', 'label' => 'Running'],
    'completed' => ['color' => '#00c853', 'bg' => 'rgba(0,200,83,0.10)',   'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>', 'label' => 'Completed'],
    'failed'    => ['color' => '#ff4757', 'bg' => 'rgba(255,71,87,0.10)',  'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>', 'label' => 'Failed'],
    'cancelled' => ['color' => '#9e9e9e', 'bg' => 'rgba(158,158,158,0.10)','icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>', 'label' => 'Cancelled'],
];
$sc = $statusConfig[$campaign['status']] ?? $statusConfig['draft'];
$logClasses = ['pending' => 'cv-status-pending', 'queued' => 'cv-status-pending', 'processing' => 'cv-status-processing', 'sent' => 'cv-status-sent', 'delivered' => 'cv-status-delivered', 'read' => 'cv-status-read', 'failed' => 'cv-status-failed'];

// Pagination for queue logs
$tab = strtolower((string)($_GET['tab'] ?? 'executed'));
if (!in_array($tab, ['queue', 'executed', 'expired'], true)) $tab = 'executed';
$search = trim((string)($_GET['q'] ?? ''));
$page = intval($_GET['p'] ?? 1); if ($page < 1) $page = 1;
$limit = intval($_GET['limit'] ?? 50);
$allowedLimits = [10, 25, 50, 100, 200];
if (!in_array($limit, $allowedLimits, true)) $limit = 50;
$offset = ($page - 1) * $limit;

$filterSql = "1=1";
if ($tab === 'queue') $filterSql = "q.status IN ('pending','processing')";
elseif ($tab === 'expired') $filterSql = "q.status='expired'";
else $filterSql = "(q.status IN ('sent','failed','expired') OR (q.status NOT IN ('pending','processing') AND q.status IS NOT NULL) OR (q.wa_delivery_status IS NOT NULL AND q.wa_delivery_status<>''))";

$searchWhere = ""; if ($search !== '') $searchWhere = " AND (c.name LIKE ? OR q.phone LIKE ?)";

$qStmt = $conn->prepare("SELECT q.*, c.name as contact_name, c.phone as phone FROM campaign_queue q LEFT JOIN contacts c ON q.contact_id = c.id AND c.company_id = q.company_id WHERE q.campaign_id = ? AND q.company_id = ? AND ($filterSql) $searchWhere ORDER BY q.id ASC LIMIT ? OFFSET ?");
$logs = null;
if ($qStmt) {
    if ($search !== '') { $like = '%' . $search . '%'; $qStmt->bind_param("iissii", $id, $companyId, $like, $like, $limit, $offset); }
    else { $qStmt->bind_param("iiii", $id, $companyId, $limit, $offset); }
    $qStmt->execute(); $logs = $qStmt->get_result();
}

$totalLogsStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM campaign_queue q LEFT JOIN contacts c ON q.contact_id = c.id AND c.company_id = q.company_id WHERE q.campaign_id = ? AND q.company_id = ? AND ($filterSql) $searchWhere");
$totalLogs = 0;
if ($totalLogsStmt) {
    if ($search !== '') { $like = '%' . $search . '%'; $totalLogsStmt->bind_param("iiss", $id, $companyId, $like, $like); }
    else { $totalLogsStmt->bind_param("ii", $id, $companyId); }
    $totalLogsStmt->execute(); $totalLogs = $totalLogsStmt->get_result()->fetch_assoc()['cnt'] ?? 0;
}
$totalPages = ceil($totalLogs / $limit);
$showFrom = ($totalLogs > 0) ? ($offset + 1) : 0;
$showTo = min($offset + $limit, (int)$totalLogs);

$pageTitle = $campaign['name'];
$currentPage = 'campaigns';

// Calculate if any messages are stuck in queue (more than 10 mins old and still pending/processing)
$duePending = 0;
$dpStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM campaign_queue WHERE campaign_id=? AND company_id=? AND status IN ('pending','processing') AND (scheduled_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
if ($dpStmt) {
    $dpStmt->bind_param('ii', $id, $companyId);
    $dpStmt->execute();
    $duePending = (int)($dpStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
}

include '../includes/client_header.php';
?>


<!-- Toast Notifications -->
<?php if (isset($_GET['msg'])): ?>
    <div class="cv-toast-container">
    <?php if ($_GET['msg'] === 'created'): ?>
        <div class="cv-toast cv-toast-success">
            <span class="cv-toast-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg></span>
            <span>Campaign created successfully!</span>
        </div>
    <?php elseif ($_GET['msg'] === 'processing'): ?>
        <div class="cv-toast cv-toast-info">
            <span class="cv-toast-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></span>
            <span>Queue processing triggered! Refresh to see updated status.</span>
        </div>
    <?php elseif ($_GET['msg'] === 'retried'): ?>
        <div class="cv-toast cv-toast-info">
            <span class="cv-toast-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg></span>
            <span>Failed entries reset to pending. Processing triggered!</span>
        </div>
    <?php elseif ($_GET['msg'] === 'retry_needs_media'): ?>
        <div class="cv-toast cv-toast-error" style="background:#FDECEC;color:#A12222;">
            <span>This template has a <?php echo htmlspecialchars((string)($campaign['tpl_header_type'] ?? 'media')); ?> header. Choose the file (or paste a public https link) next to Retry Failed, then retry. On localhost, uploads need PUBLIC_URL in .env.</span>
        </div>
    <?php elseif ($_GET['msg'] === 'recampaigned'): ?>
        <div class="cv-toast cv-toast-success">
            <span class="cv-toast-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></span>
            <span>New campaign created from segment!</span>
        </div>
    <?php elseif ($_GET['msg'] === 'expired_block'): ?>
        <div class="cv-toast cv-toast-error">
            <span class="cv-toast-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
            <span>Action restricted. Your plan has expired. <a href="subscription.php" style="color:inherit;font-weight:700;text-decoration:underline;">Renew Now</a></span>
        </div>
    <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div class="cv-header">
    <div class="cv-header-left">
        <a href="campaigns.php" class="cv-back-link">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 12L6 8L10 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Campaigns
        </a>
        <div class="cv-title-row">
            <h1 class="cv-title"><?php echo htmlspecialchars($campaign['name']); ?></h1>
            <span class="cv-status-badge" style="color:<?php echo $sc['color']; ?>;background:<?php echo $sc['bg']; ?>;border:1px solid <?php echo $sc['color']; ?>22;">
                <?php echo $sc['icon'] . ' ' . $sc['label']; ?>
            </span>
            <span class="cv-id-badge">#<?php echo $id; ?></span>
        </div>
    </div>
    <div class="cv-header-actions">
        <?php if (in_array($campaign['status'], ['running', 'scheduled']) && (($stats['queued'] ?? 0) > 0 || $duePending > 0)): ?>
            <form method="POST" style="display:inline;" id="processForm">
                <?php echo csrf_field(); ?>
                <button type="button" name="process_queue" class="cv-btn cv-btn-glow" onclick="campaignTickNow()">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 2L12 8L4 14V2Z" fill="currentColor"/></svg>
                    Process Queue (<?php echo (int)(($stats['queued'] ?? 0) + $duePending); ?>)
                </button>
            </form>
        <?php endif; ?>
        <?php if (($stats['failed'] ?? 0) > 0): ?>
            <?php $needsMedia = in_array(strtolower((string)($campaign['tpl_header_type'] ?? '')), ['image', 'video', 'document'], true); ?>
            <form method="POST" enctype="multipart/form-data" style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:8px;">
                <?php echo csrf_field(); ?>
                <?php if ($needsMedia): ?>
                    <span style="font-size:12px;color:var(--text-muted);">This template needs a <?php echo htmlspecialchars((string)$campaign['tpl_header_type']); ?>:</span>
                    <input type="file" name="retry_media_file" accept="image/*,video/mp4,application/pdf" style="font-size:12px;max-width:210px;">
                    <input type="url" name="retry_media_url" placeholder="or paste https:// link" value="<?php echo htmlspecialchars((string)($campaign['media_url'] ?? '')); ?>" style="height:36px;padding:0 10px;border:1px solid var(--border,#DDEFE7);border-radius:8px;font-size:12px;min-width:200px;">
                <?php endif; ?>
                <button type="submit" name="retry_failed" class="cv-btn" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;border:none;">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 8a6 6 0 0 1 10.2-4.3L14 2v5h-5l1.8-1.8A4 4 0 1 0 12 8h2a6 6 0 0 1-12 0z" fill="currentColor"/></svg>
                    Retry Failed (<?php echo (int)$stats['failed']; ?>)
                </button>
            </form>
        <?php endif; ?>
        <a href="campaign_create.php" class="cv-btn cv-btn-outline" style="margin-left:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Create New
        </a>
    </div>
</div>

<!-- Stuck Queue Alert -->
<?php if ($duePending > 0): ?>
<div class="cv-toast" style="margin-bottom:28px; background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.2); color:#f59e0b; display:flex; align-items:center; gap:12px;">
    <span class="cv-toast-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span>
    <div style="flex:1;">
        <b><?php echo number_format($duePending); ?></b> message(s) are due but still in Queue. The sender cron might be inactive.
    </div>
    <button type="button" class="cv-btn cv-btn-glow" style="padding:6px 14px; font-size:11px; background:#f59e0b; box-shadow:none;" onclick="campaignTickNow()">Process Now</button>
</div>
<?php endif; ?>

<!-- Stats Ring + Cards -->
<div class="cv-overview">
    <!-- Left: Progress Ring -->
    <div class="cv-ring-card">
        <div class="cv-ring-wrap">
            <svg class="cv-ring-svg" viewBox="0 0 120 120">
                <circle cx="60" cy="60" r="52" fill="none" stroke="var(--border)" stroke-width="8"/>
                <circle cx="60" cy="60" r="52" fill="none" stroke="url(#cvGrad)" stroke-width="8" stroke-linecap="round"
                    stroke-dasharray="<?php echo round(326.7 * $progress / 100); ?> 326.7"
                    transform="rotate(-90 60 60)" class="cv-ring-progress"/>
                <defs><linearGradient id="cvGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#6c5ce7"/><stop offset="100%" stop-color="#00c853"/></linearGradient></defs>
            </svg>
            <div class="cv-ring-center">
                <span class="cv-ring-pct"><?php echo $progress; ?>%</span>
                <span class="cv-ring-label">Complete</span>
            </div>
        </div>
        <div class="cv-ring-meta">
            <div class="cv-ring-meta-row">
                <span class="cv-dot" style="background:#00c853;"></span>
                <span><?php echo number_format($sentCount); ?> Sent</span>
                <?php if ($sentCount > 0 && !$viewOnlyMode): ?>
                    <form method="POST" style="margin-left:auto;">
                        <?php echo csrf_field(); ?><input type="hidden" name="recampaign_segment" value="sent">
                        <button type="submit" class="cv-mini-btn" title="Recampaign Sent"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="cv-ring-meta-row">
                <span class="cv-dot" style="background:#ff4757;"></span>
                <span><?php echo number_format($failedCount); ?> Failed</span>
                <?php if ($failedCount > 0 && !$viewOnlyMode): ?>
                    <form method="POST" style="margin-left:auto;">
                        <?php echo csrf_field(); ?><input type="hidden" name="recampaign_segment" value="failed">
                        <button type="submit" class="cv-mini-btn" title="Recampaign Failed"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="cv-ring-meta-row">
                <span class="cv-dot" style="background:#ff9800;"></span>
                <span><?php echo number_format($stats['queued'] ?? 0); ?> Queued</span>
            </div>
        </div>
    </div>

    <!-- Right: Stat Cards Grid -->
    <div class="cv-stat-grid">
        <div class="cv-stat" style="--accent-c:#6c5ce7;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($campaign['total_contacts']); ?></div>
            <div class="cv-stat-label">Total Recipients</div>
        </div>
        <div class="cv-stat" style="--accent-c:#2196f3;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($stats['sent'] ?? 0); ?></div>
            <div class="cv-stat-label">Accepted</div>
        </div>
        <div class="cv-stat" style="--accent-c:#00c853;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($stats['delivered'] ?? 0); ?></div>
            <div class="cv-stat-label">Delivered</div>
            <?php if ($stats['delivered'] > 0 && !$viewOnlyMode): ?>
                <form method="POST" style="position:absolute; top:15px; right:15px;">
                    <?php echo csrf_field(); ?><input type="hidden" name="recampaign_segment" value="delivered">
                    <button type="submit" class="cv-mini-btn" title="Recampaign Delivered"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="cv-stat" style="--accent-c:#a78bfa;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($stats['read_count'] ?? 0); ?></div>
            <div class="cv-stat-label">Read</div>
            <?php if ($stats['read_count'] > 0 && !$viewOnlyMode): ?>
                <form method="POST" style="position:absolute; top:15px; right:15px;">
                    <?php echo csrf_field(); ?><input type="hidden" name="recampaign_segment" value="read">
                    <button type="submit" class="cv-mini-btn" title="Recampaign Read"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="cv-stat" style="--accent-c:#ff4757;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></div>
            <div class="cv-stat-value"><?php echo number_format($failedCount); ?></div>
            <div class="cv-stat-label">Failed</div>
        </div>
        <div class="cv-stat" style="--accent-c:#29b6f6;">
            <div class="cv-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg></div>
            <div class="cv-stat-value"><?php echo $successRate; ?>%</div>
            <div class="cv-stat-label">Success Rate</div>
        </div>
    </div>
</div>

<!-- Details + Message Preview -->
<div class="cv-details-grid">
    <!-- Campaign Details -->
    <div class="cv-card">
        <div class="cv-card-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
            <span>Campaign Details</span>
        </div>
        <div class="cv-detail-list">
            <div class="cv-detail-row">
                <span class="cv-detail-key">Template</span>
                <span class="tag-badge" style="font-size:12px;"><?php echo htmlspecialchars($campaign['template_name'] ?? 'None'); ?></span>
            </div>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Category</span>
                <span class="cv-detail-val"><?php echo ucfirst($campaign['category'] ?? '—'); ?></span>
            </div>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Audience</span>
                <span class="cv-detail-val"><?php echo htmlspecialchars($campaign['group_name'] ?? 'All Contacts'); ?></span>
            </div>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Created</span>
                <span class="cv-detail-val"><?php echo date('d M Y, h:i A', strtotime($campaign['created_at'])); ?></span>
            </div>
            <?php if (!empty($campaign['scheduled_at'])): ?>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Scheduled</span>
                <span class="cv-detail-val" style="color:#2196f3;"><?php echo date('d M Y, h:i A', strtotime($campaign['scheduled_at'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($campaign['completed_at'])): ?>
            <div class="cv-detail-row">
                <span class="cv-detail-key">Completed</span>
                <span class="cv-detail-val" style="color:#00c853;"><?php echo date('d M Y, h:i A', strtotime($campaign['completed_at'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- WhatsApp Message Preview -->
    <div class="cv-card">
        <div class="cv-card-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#25d366" stroke-width="2"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
            <span>Message Preview</span>
        </div>
        <div class="cv-wa-phone">
            <div class="cv-wa-topbar">
                <div class="cv-wa-avatar">W</div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:#fff;">WAAMINI</div>
                    <div style="font-size:10px;color:rgba(255,255,255,0.6);">Business Account</div>
                </div>
            </div>
            <div class="cv-wa-body">
                <?php if (!empty($campaign['header_text'])): ?>
                    <div class="cv-wa-header"><?php echo htmlspecialchars($campaign['header_text']); ?></div>
                <?php endif; ?>
                <div class="cv-wa-bubble">
                    <div class="cv-wa-text"><?php echo nl2br(htmlspecialchars($campaign['body_text'] ?? 'No template body')); ?></div>
                    <?php if (!empty($campaign['footer_text'])): ?>
                        <div class="cv-wa-footer"><?php echo htmlspecialchars($campaign['footer_text']); ?></div>
                    <?php endif; ?>
                    <div class="cv-wa-time"><?php echo date('h:i A'); ?> <span style="color:#53bdeb;"><span style="color:#53bdeb;font-size:12px;font-weight:700">✓✓</span></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delivery Log -->
<div class="cv-card cv-card-full">
    <div class="cv-card-header" style="margin-bottom:0;padding-bottom:16px;border-bottom:none;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>
        <span>Delivery Log</span>
        <span class="cv-log-count"><?php echo number_format($totalLogs); ?> entries</span>
    </div>

    <!-- Tabs + Toolbar -->
    <div class="cv-toolbar">
        <div class="cv-tabs">
            <?php
                $tabs = [['k' => 'executed', 'l' => 'Executed'], ['k' => 'queue', 'l' => 'Queue'], ['k' => 'expired', 'l' => 'Expired']];
                foreach ($tabs as $t):
                    $active = ($tab === $t['k']);
                    $url = "?id=$id&tab=".$t['k']."&q=".urlencode($search)."&limit=$limit";
            ?>
                <a href="<?php echo $url; ?>" class="cv-tab <?php echo $active ? 'active' : ''; ?>"><?php echo $t['l']; ?></a>
            <?php endforeach; ?>
        </div>
        <div class="cv-toolbar-actions">
            <form method="GET" class="cv-search-form">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search phone or name..." class="cv-search-input">
                <button type="submit" class="cv-search-btn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></button>
            </form>
            <a href="?id=<?php echo $id; ?>&tab=<?php echo $tab; ?>&q=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&export=1" class="cv-btn-icon" title="Export CSV">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            </a>
        </div>
    </div>

    <div class="cv-log-table-wrap">
        <table class="cv-log-table">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Last Update</th>
                    <th>Error Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && $logs->num_rows > 0): ?>
                    <?php while ($l = $logs->fetch_assoc()):
                        $lsc = $logClasses[$l['status']] ?? 'cv-status-pending';
                        $delivery = strtolower((string)($l['wa_delivery_status'] ?? ''));
                        $queueStatus = strtolower((string)($l['status'] ?? ''));
                        $showStatus = $delivery !== '' ? $delivery : $queueStatus;
                        
                        $statusIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                        if ($delivery === 'read') $statusIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>';
                        elseif ($delivery === 'delivered') $statusIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
                        elseif ($delivery === 'sent' || $queueStatus === 'sent') $statusIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>';
                        elseif ($delivery === 'failed' || $queueStatus === 'failed') $statusIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
                        
                        $ts = $l['wa_delivery_ts'] ?? $l['scheduled_at'] ?? '';
                    ?>
                    <tr>
                        <td>
                            <div class="cv-contact-cell">
                                <div class="cv-contact-avatar"><?php echo strtoupper(substr($l['contact_name'] ?? '?', 0, 1)); ?></div>
                                <span style="color:var(--text-primary);font-weight:500;"><?php echo htmlspecialchars($l['contact_name'] ?? 'Unknown'); ?></span>
                            </div>
                        </td>
                        <td><code class="cv-phone"><?php echo htmlspecialchars($l['phone']); ?></code></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:16px;"><?php echo $statusIcon; ?></span>
                                <span class="cv-log-badge <?php echo $logClasses[$showStatus] ?? 'cv-status-pending'; ?>"><?php echo ucfirst($showStatus); ?></span>
                            </div>
                        </td>
                        <td class="cv-date"><?php echo $ts ? date('d M, H:i', strtotime($ts)) : '—'; ?></td>
                        <td>
                            <?php if (!empty($l['error_message']) && $l['error_message'] !== '—'): ?>
                                <span class="cv-error-msg" title="<?php echo htmlspecialchars($l['error_message']); ?>"><?php echo htmlspecialchars(mb_strimwidth($l['error_message'], 0, 50, '...')); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="cv-empty-log">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                        <span>No entries found in this view</span>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="cv-pagination">
        <div class="cv-pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
        <div class="cv-pagination-btns">
            <?php if ($page > 1): ?>
                <a href="?id=<?php echo $id; ?>&tab=<?php echo $tab; ?>&q=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&p=<?php echo $page - 1; ?>" class="cv-page-btn">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?id=<?php echo $id; ?>&tab=<?php echo $tab; ?>&q=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&p=<?php echo $page + 1; ?>" class="cv-page-btn">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
function campaignTickNow() {
  const btn = document.querySelector('[name="process_queue"]');
  if(btn) { btn.disabled = true; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Processing...'; }
  fetch('../api/campaign_tick.php?campaign_id=<?php echo (int)$id; ?>&limit=25', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(j => {
      if (!j || j.ok !== true) throw new Error((j && j.error) ? j.error : 'tick_failed');
      window.location.href = 'campaign_view.php?id=<?php echo (int)$id; ?>&msg=processing';
    })
    .catch(e => {
      alert('Unable to process now: ' + (e && e.message ? e.message : 'error'));
      if(btn) { btn.disabled = false; btn.innerHTML = 'Process Queue'; }
    });
}
<?php if (isset($_GET['trigger_tick'])): ?>
window.addEventListener('load', campaignTickNow);
<?php endif; ?>
</script>

<style>
/* ============ CAMPAIGN VIEW PREMIUM ============ */

/* Toast */
.cv-toast-container { position:relative; z-index:100; }
.cv-toast { display:flex;align-items:center;gap:10px;padding:14px 20px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;animation:cvSlideIn .4s ease; }
.cv-toast-success { background:rgba(0,200,83,0.08);border:1px solid rgba(0,200,83,0.2);color:#00c853; }
.cv-toast-info { background:rgba(108,92,231,0.08);border:1px solid rgba(108,92,231,0.2);color:#a78bfa; }
.cv-toast-error { background:rgba(255,71,87,0.08);border:1px solid rgba(255,71,87,0.2);color:#ff4757; }
.cv-toast-icon { font-size:18px; }

/* Header */
.cv-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;gap:16px; }
.cv-header-left { display:flex;flex-direction:column;gap:8px; }
.cv-back-link { display:inline-flex;align-items:center;gap:4px;font-size:13px;color:var(--text-muted);text-decoration:none;transition:color .2s; }
.cv-back-link:hover { color:var(--accent); }
.cv-title-row { display:flex;align-items:center;gap:12px;flex-wrap:wrap; }
.cv-title { font-size:26px;font-weight:800;letter-spacing:-0.5px;color:var(--text-primary);margin:0; }
.cv-status-badge { display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:5px 14px;border-radius:20px; }
.cv-id-badge { font-size:12px;color:var(--text-muted);background:var(--bg-input);padding:4px 10px;border-radius:6px;font-weight:600; }
.cv-header-actions { display:flex;gap:10px;align-items:center;padding-top:28px; }
.cv-btn { display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .2s; text-decoration:none; line-height:1; }
.cv-btn-glow { background:linear-gradient(135deg,#6c5ce7,#a855f7);color:#fff;box-shadow:0 4px 20px rgba(108,92,231,0.4); }
.cv-btn-glow:hover { transform:translateY(-2px);box-shadow:0 6px 30px rgba(108,92,231,0.5); }
.cv-btn-outline { background:var(--bg-input); border:1px solid var(--border); color:var(--text-primary); }
.cv-btn-outline:hover { background:var(--border); }

/* Overview: Ring + Stats */
.cv-overview { display:grid;grid-template-columns:280px 1fr;gap:24px;margin-bottom:28px; }
.cv-ring-card { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:28px;display:flex;flex-direction:column;align-items:center;gap:20px; }
.cv-ring-wrap { position:relative;width:140px;height:140px; }
.cv-ring-svg { width:100%;height:100%; }
.cv-ring-progress { transition:stroke-dasharray 1s ease; }
.cv-ring-center { position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center; }
.cv-ring-pct { font-size:28px;font-weight:800;color:var(--text-primary);letter-spacing:-1px; }
.cv-ring-label { font-size:11px;color:var(--text-muted);font-weight:500;text-transform:uppercase;letter-spacing:1px; }
.cv-ring-meta { display:flex;flex-direction:column;gap:8px;width:100%; }
.cv-ring-meta-row { display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary); }
.cv-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.cv-mini-btn { border:none; background:none; cursor:pointer; padding:2px 6px; border-radius:4px; font-size:12px; transition:all 0.2s; }
.cv-mini-btn:hover { background:rgba(108,92,231,0.1); transform:scale(1.2); }

/* Stat cards */
.cv-stat-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:16px; }
.cv-stat { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;position:relative;overflow:hidden;transition:all .3s; }
.cv-stat::after { content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent-c);opacity:0;transition:opacity .3s; }
.cv-stat:hover { border-color:color-mix(in srgb, var(--accent-c) 30%, transparent);transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2); }
.cv-stat:hover::after { opacity:1; }
.cv-stat-icon { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:14px;background:color-mix(in srgb, var(--accent-c) 12%, transparent);color:var(--accent-c); }
.cv-stat-value { font-size:26px;font-weight:800;color:var(--text-primary);letter-spacing:-0.5px;margin-bottom:2px; }
.cv-stat-label { font-size:12px;color:var(--text-muted);font-weight:500; }

/* Details Grid */
.cv-details-grid { display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px; }
.cv-card { background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;overflow:hidden; }
.cv-card-full { grid-column:1/-1; }
.cv-card-header { display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;color:var(--text-primary);margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border); }
.cv-card-header span:last-child:not(:nth-child(2)) { margin-left:auto; }

/* Detail list */
.cv-detail-list { display:flex;flex-direction:column; }
.cv-detail-row { display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(42,45,62,0.5); }
.cv-detail-row:last-child { border-bottom:none; }
.cv-detail-key { font-size:13px;color:var(--text-muted); }
.cv-detail-val { font-size:13px;color:var(--text-primary);font-weight:500; }

/* WhatsApp Preview */
.cv-wa-phone { border-radius:16px;overflow:hidden;border:1px solid var(--border); }
.cv-wa-topbar { display:flex;align-items:center;gap:10px;padding:12px 16px;background:#075e54; }
.cv-wa-avatar { width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6c5ce7,#a855f7);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff; }
.cv-wa-body { background:#0b141a;padding:16px;min-height:160px; }
.cv-wa-header { font-size:13px;font-weight:600;color:#e4e6f0;margin-bottom:8px;padding:8px 12px;background:rgba(255,255,255,0.05);border-radius:8px; }
.cv-wa-bubble { background:#1f2c34;border-radius:0 10px 10px 10px;padding:12px 14px;max-width:95%;position:relative; }
.cv-wa-bubble::before { content:'';position:absolute;top:0;left:-6px;width:0;height:0;border-top:6px solid #1f2c34;border-left:6px solid transparent; }
.cv-wa-text { font-size:13px;color:#e4e6eb;line-height:1.6;white-space:pre-wrap; }
.cv-wa-footer { font-size:11px;color:rgba(255,255,255,0.4);margin-top:6px;font-style:italic; }
.cv-wa-time { text-align:right;font-size:10px;color:rgba(255,255,255,0.35);margin-top:4px; }

/* Toolbar */
.cv-toolbar { display:flex; align-items:center; justify-content:space-between; padding:12px 0; margin-bottom:16px; border-top:1px solid var(--border); border-bottom:1px solid var(--border); gap:16px; flex-wrap:wrap; }
.cv-tabs { display:flex; gap:6px; }
.cv-tab { padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; color:var(--text-muted); text-decoration:none; transition:all 0.2s; background:var(--bg-input); border:1px solid var(--border); }
.cv-tab:hover { color:var(--text-primary); background:var(--border); }
.cv-tab.active { background:rgba(108,92,231,0.12); color:var(--accent); border-color:rgba(108,92,231,0.25); }
.cv-toolbar-actions { display:flex; align-items:center; gap:12px; }
.cv-search-form { display:flex; position:relative; }
.cv-search-input { background:var(--bg-input); border:1px solid var(--border); border-radius:8px; padding:8px 36px 8px 12px; font-size:13px; color:var(--text-primary); width:240px; }
.cv-search-btn { position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; }
.cv-btn-icon { display:flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:8px; background:var(--bg-input); border:1px solid var(--border); color:var(--text-primary); transition:all 0.2s; }
.cv-btn-icon:hover { background:var(--border); color:var(--accent); }

/* Log Table */
.cv-log-count { margin-left:auto;font-size:12px;font-weight:500;color:var(--text-muted);background:var(--bg-input);padding:3px 10px;border-radius:6px; }
.cv-log-table-wrap { overflow-x:auto;margin:0 -24px;padding:0 24px; }
.cv-log-table { width:100%;border-collapse:collapse; }
.cv-log-table th { text-align:left;padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);border-bottom:1px solid var(--border);background:rgba(108,92,231,0.03); }
.cv-log-table td { padding:14px 16px;font-size:13px;border-bottom:1px solid rgba(42,45,62,0.4);color:var(--text-secondary); }
.cv-log-table tr:last-child td { border-bottom:none; }
.cv-log-table tr:hover td { background:rgba(108,92,231,0.03); }

.cv-contact-cell { display:flex;align-items:center;gap:10px; }
.cv-contact-avatar { width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#a855f7);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0; }
.cv-phone { background:var(--bg-input);padding:3px 8px;border-radius:4px;font-size:12px;color:var(--text-secondary); }
.cv-date { font-size:12px;color:var(--text-muted); }
.cv-error-msg { font-size:12px;color:#ff4757;background:rgba(255,71,87,0.08);padding:4px 8px;border-radius:4px;display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }

/* Log status badges */
.cv-log-badge { display:inline-flex;align-items:center;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600; }
.cv-status-pending { background:rgba(255,152,0,0.10);color:#ff9800; }
.cv-status-processing { background:rgba(33,150,243,0.10);color:#2196f3; }
.cv-status-sent { background:rgba(33,150,243,0.10);color:#2196f3; }
.cv-status-delivered { background:rgba(0,200,83,0.10);color:#00c853; }
.cv-status-read { background:rgba(167,139,250,0.10);color:#a78bfa; }
.cv-status-failed { background:rgba(255,71,87,0.10);color:#ff4757; }

.cv-pagination { display:flex; align-items:center; justify-content:space-between; padding:16px 24px; border-top:1px solid var(--border); margin:16px -24px -24px -24px; background:rgba(108,92,231,0.02); }
.cv-pagination-info { font-size:12px; color:var(--text-muted); }
.cv-pagination-btns { display:flex; gap:8px; }
.cv-page-btn { padding:6px 14px; border-radius:8px; background:var(--bg-input); border:1px solid var(--border); color:var(--text-primary); font-size:12px; text-decoration:none; font-weight:600; }
.cv-page-btn:hover { background:var(--border); }

.cv-empty-log { text-align:center;padding:48px 20px !important;color:var(--text-muted);display:flex;flex-direction:column;align-items:center;gap:8px; }

/* Animation */
@keyframes cvSlideIn { from { opacity:0;transform:translateY(-10px); } to { opacity:1;transform:translateY(0); } }

/* Responsive */
@media(max-width:900px) {
    .cv-overview { grid-template-columns:1fr; }
    .cv-stat-grid { grid-template-columns:repeat(2,1fr); }
    .cv-details-grid { grid-template-columns:1fr; }
    .cv-header { flex-direction:column; }
    .cv-header-actions { padding-top:0; }
}
@media(max-width:600px) {
    .cv-stat-grid { grid-template-columns:1fr; }
    .cv-title { font-size:20px; }
}
</style>


<script>
/**
 * Auto-Process Queue (Production-Grade UX)
 * Automatically triggers campaign_tick if there are pending items and the campaign is running.
 */
(function() {
    const isRunning = "<?php echo $campaign['status']; ?>" === "running";
    let pendingCount = <?php echo (int)($stats['queued'] ?? 0); ?>;
    const campaignId = <?php echo $id; ?>;
    
    if (isRunning && pendingCount > 0) {
        console.log("[WAAMINI] Auto-processing triggered for campaign #" + campaignId);
        
        async function tick() {
            try {
                const res = await fetch("../api/campaign_tick.php?campaign_id=" + campaignId + "&limit=25");
                const data = await res.json();
                if (data.ok && data.processed > 0) {
                    console.log("[WAAMINI] Processed " + data.processed + " items automatically.");
                    // Refresh stats/UI after a short delay
                    setTimeout(() => location.reload(), 2000);
                }
            } catch (e) {
                console.error("[WAAMINI] Auto-tick failed:", e);
            }
        }
        
        // Initial tick after 1s
        setTimeout(tick, 1000);
    }
})();
</script>

<?php include '../includes/client_footer.php'; ?>


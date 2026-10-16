<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
$pageTitle = 'Order Management';
$currentPage = 'orders';

$ORDER_STATUSES = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'failed', 'refunded'];

// Optional columns used by this UI (ignore if already present)
try {
    $conn->query("ALTER TABLE ecommerce_orders ADD COLUMN order_source VARCHAR(64) DEFAULT NULL");
} catch (\Throwable $e) { /* exists */ }
try {
    $conn->query("ALTER TABLE ecommerce_orders ADD COLUMN recovery_sent TINYINT(1) DEFAULT 0");
} catch (\Throwable $e) { /* exists */ }
try {
    $conn->query('ALTER TABLE ecommerce_orders ADD COLUMN invoice_sent TINYINT(1) DEFAULT 0');
} catch (\Throwable $e) { /* exists */ }
try {
    $conn->query('ALTER TABLE ecommerce_orders ADD COLUMN invoice_sent_at DATATETIME DEFAULT NULL');
} catch (\Throwable $e) { /* exists */ }

// Quick-add service / product (same `products` table as catalog.php — each company has its own list)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add_product'])) {
    csrf_verify();
    $pname  = trim((string)($_POST['product_name'] ?? ''));
    $pdesc  = trim((string)($_POST['product_description'] ?? ''));
    $pprice = (float)($_POST['product_price'] ?? 0);
    if ($pname !== '' && $pprice >= 0) {
        $pname = sanitize($pname);
        $pdesc = sanitize($pdesc);
        $imgEmpty = '';
        $insP = $conn->prepare('INSERT INTO products (company_id, name, description, price, image_url) VALUES (?, ?, ?, ?, ?)');
        if ($insP) {
            $insP->bind_param('issds', $companyId, $pname, $pdesc, $pprice, $imgEmpty);
            $insP->execute();
        }
    }
    header('Location: orders.php?msg=product_saved');
    exit();
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    $oid          = max(0, (int)($_POST['order_id'] ?? 0));
    $newStatus    = in_array($_POST['status'] ?? '', $ORDER_STATUSES, true) ? $_POST['status'] : 'pending';
    // Store URLs/notes as plain text; escape on output (sanitize() double-escapes DB + WhatsApp)
    $trackingUrl  = trim((string)($_POST['tracking_url'] ?? ''));
    $rawEst       = trim((string)($_POST['estimated_delivery'] ?? ''));
    $estDelivery  = ($rawEst !== '' && strtotime($rawEst) !== false) ? $rawEst : null;
    $statusNotes  = trim((string)($_POST['status_notes'] ?? ''));

    if ($oid <= 0) {
        header('Location: orders.php?msg=invalid');
        exit();
    }

    $prevStatus = '';
    $alreadyInv = 1;
    $stmt_prev = $conn->prepare('SELECT status, COALESCE(invoice_sent, 0) AS invsent FROM ecommerce_orders WHERE id=? AND company_id=? LIMIT 1');
    if ($stmt_prev) {
        $stmt_prev->bind_param('ii', $oid, $companyId);
        $stmt_prev->execute();
        $pv = $stmt_prev->get_result()->fetch_assoc();
        if ($pv) {
            $prevStatus = strtolower(trim((string)($pv['status'] ?? '')));
            $alreadyInv = (int)($pv['invsent'] ?? 0);
        }
    }

    $stmt_upd = $conn->prepare('UPDATE ecommerce_orders SET status=?, tracking_url=?, estimated_delivery=?, status_notes=?, updated_at=NOW() WHERE id=? AND company_id=?');
    $updOk = false;
    if ($stmt_upd) {
        $stmt_upd->bind_param('ssssii', $newStatus, $trackingUrl, $estDelivery, $statusNotes, $oid, $companyId);
        $updOk = $stmt_upd->execute();
    }

    // After vendor confirms fulfilment (processing) for a PAID order, queue WhatsApp invoice once
    if ($updOk && $newStatus === 'processing' && $prevStatus === 'paid' && $alreadyInv === 0) {
        require_once __DIR__ . '/../includes/order_invoice_whatsapp.php';
        waamini_queue_invoice_message_for_order($conn, $companyId, $oid);
    }

    // Send WhatsApp notification to customer
    if (!empty($_POST['notify_customer'])) {
        require_once '../includes/order_tracking_handler.php';
        $stmt_on = $conn->prepare('SELECT o.*, c.phone, c.id AS contact_id FROM ecommerce_orders o JOIN contacts c ON o.contact_id=c.id WHERE o.id=? AND o.company_id=?');
        if ($stmt_on) {
            $stmt_on->bind_param('ii', $oid, $companyId);
            $stmt_on->execute();
            $orderRow = $stmt_on->get_result()->fetch_assoc();
            if ($orderRow) {
                $stmt_ts = $conn->prepare('SELECT * FROM order_tracking_settings WHERE company_id=?');
                if ($stmt_ts) {
                    $stmt_ts->bind_param('i', $companyId);
                    $stmt_ts->execute();
                    $tSettings = $stmt_ts->get_result()->fetch_assoc();
                    $template = $tSettings['tracking_message'] ?? "📦 Order *{{order_number}}* update:\n\nStatus: *{{status}}*\n{{tracking_url_line}}Thank you! ❤️";

                    $statusEmoji = [
                        'pending'    => '⏳',
                        'paid'       => '✅',
                        'processing' => '⚙️',
                        'shipped'    => '🚚',
                        'delivered'  => '📬',
                        'failed'     => '❌',
                        'refunded'   => '💰',
                    ];
                    $emoji = $statusEmoji[$newStatus] ?? '📦';
                    $estD = ($estDelivery !== null && $estDelivery !== '') ? date('d M Y', strtotime((string)$estDelivery)) : '';
                    $msg = str_replace(
                        ['{{order_number}}', '{{status}}', '{{tracking_url_line}}', '{{delivery_line}}', '{{notes_line}}'],
                        [
                            $orderRow['order_number'] ?? 'ORD-' . $oid,
                            $emoji . ' ' . ucfirst((string)$newStatus),
                            $trackingUrl !== '' ? 'Track: ' . $trackingUrl . "\n" : '',
                            $estD !== '' ? 'Est. Delivery: ' . $estD . "\n" : '',
                            $statusNotes !== '' ? $statusNotes . "\n" : '',
                        ],
                        $template
                    );
                    $cid = (int)$orderRow['contact_id'];
                    $stmt_notif = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?,?,'instant',?,NOW())");
                    if ($stmt_notif) {
                        $stmt_notif->bind_param('iis', $companyId, $cid, $msg);
                        $stmt_notif->execute();
                    }
                }
            }
        }
    }

    // Upsell when paid — contact must come from DB (not only when “notify” is checked)
    if ($newStatus === 'paid') {
        require_once '../includes/upsell_handler.php';
        $stmt_uc = $conn->prepare('SELECT contact_id FROM ecommerce_orders WHERE id=? AND company_id=? LIMIT 1');
        if ($stmt_uc) {
            $stmt_uc->bind_param('ii', $oid, $companyId);
            $stmt_uc->execute();
            $ucRow = $stmt_uc->get_result()->fetch_assoc();
            $cidUpsell = (int)($ucRow['contact_id'] ?? 0);
            if ($cidUpsell > 0) {
                send_upsell_message($conn, $companyId, $cidUpsell, $oid);
            }
        }
    }

    $preserve = trim((string)($_POST['preserve_status'] ?? ''));
    $redir = 'orders.php?msg=updated';
    if ($preserve !== '' && in_array($preserve, $ORDER_STATUSES, true)) {
        $redir .= '&status=' . rawurlencode($preserve);
    }
    header('Location: ' . $redir);
    exit();
}

// Filters (whitelist — do not use sanitize() here; it HTML-escapes and breaks SQL matching)
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
if ($statusFilter !== '' && !in_array($statusFilter, $ORDER_STATUSES, true)) {
    $statusFilter = '';
}
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

if ($search !== '') {
    $sp = '%' . $search . '%';
    $stmt_o = $conn->prepare('SELECT o.*, c.name AS customer_name, c.phone FROM ecommerce_orders o LEFT JOIN contacts c ON o.contact_id=c.id WHERE o.company_id=? AND (o.order_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?) ORDER BY o.id DESC LIMIT 50');
    if ($stmt_o) {
        $stmt_o->bind_param('isss', $companyId, $sp, $sp, $sp);
    }
} elseif ($statusFilter !== '') {
    $stmt_o = $conn->prepare('SELECT o.*, c.name AS customer_name, c.phone FROM ecommerce_orders o LEFT JOIN contacts c ON o.contact_id=c.id WHERE o.company_id=? AND o.status=? ORDER BY o.id DESC LIMIT 50');
    if ($stmt_o) {
        $stmt_o->bind_param('is', $companyId, $statusFilter);
    }
} else {
    $stmt_o = $conn->prepare('SELECT o.*, c.name AS customer_name, c.phone FROM ecommerce_orders o LEFT JOIN contacts c ON o.contact_id=c.id WHERE o.company_id=? ORDER BY o.id DESC LIMIT 50');
    if ($stmt_o) {
        $stmt_o->bind_param('i', $companyId);
    }
}

$orders = false;
if ($stmt_o && $stmt_o->execute()) {
    $orders = $stmt_o->get_result();
}

// Stats
$stmt_st = $conn->prepare('SELECT status, COUNT(*) AS c, SUM(amount) AS total FROM ecommerce_orders WHERE company_id=? GROUP BY status');
$statsByStatus = [];
if ($stmt_st) {
    $stmt_st->bind_param('i', $companyId);
    $stmt_st->execute();
    $statsRes = $stmt_st->get_result();
    while ($s = $statsRes->fetch_assoc()) {
        $statsByStatus[strtolower(trim((string)$s['status']))] = $s;
    }
}

$catalogProducts = [];
$stmt_cat = $conn->prepare('SELECT id, name, description, price, created_at FROM products WHERE company_id = ? ORDER BY created_at DESC LIMIT 20');
if ($stmt_cat) {
    $stmt_cat->bind_param('i', $companyId);
    $stmt_cat->execute();
    $catRes = $stmt_cat->get_result();
    if ($catRes) {
        while ($cp = $catRes->fetch_assoc()) {
            $catalogProducts[] = $cp;
        }
    }
}

include '../includes/client_header.php';
?>

<style>
/* ── Order Management ───────────────────────────────────────────── */
.om-wrap { max-width: 1400px; margin: 0 auto; padding: 24px 20px 40px; }

/* Toast */
#omToast {
    position: fixed; bottom: 24px; right: 24px;
    padding: 12px 20px; border-radius: var(--radius);
    font-size: 13px; font-weight: 600; color: #fff;
    z-index: 9999; opacity: 0; pointer-events: none;
    transform: translateY(8px); transition: opacity .25s, transform .25s;
}
#omToast.show { opacity: 1; transform: none; }

/* Flash banner */
.om-flash {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: var(--radius);
    font-size: 13px; font-weight: 600; margin-bottom: 20px;
}
.om-flash svg { width: 16px; height: 16px; flex-shrink: 0; }
.om-flash-ok  { background: var(--success-bg);  border: 1px solid var(--success-border);  color: var(--success); }
.om-flash-err { background: var(--danger-bg);   border: 1px solid var(--danger-border);   color: var(--danger); }

/* Page header — same pattern as order_dashboard */
.om-hdr {
    display: flex; justify-content: space-between; align-items: center;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px 24px; margin-bottom: 20px;
    box-shadow: var(--shadow); position: relative; overflow: hidden;
    gap: 16px; flex-wrap: wrap;
}
.om-hdr::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--accent), #8b5cf6);
    border-radius: var(--radius) var(--radius) 0 0;
}
.om-hdr-left  { display: flex; align-items: center; gap: 14px; }
.om-hdr-icon  {
    width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
    background: var(--accent-light); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
}
.om-hdr-icon svg { width: 22px; height: 22px; }
.om-hdr-title { font-size: 18px; font-weight: 700; color: var(--text-primary); margin: 0 0 3px; }
.om-hdr-sub   { font-size: 12px; color: var(--text-muted); margin: 0; }
.om-hdr-right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

/* Search bar */
.om-search {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-input); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 7px 13px;
    transition: var(--transition);
}
.om-search:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,124,227,.1); }
.om-search svg { width: 14px; height: 14px; color: var(--text-muted); flex-shrink: 0; }
.om-search input {
    background: transparent; border: none; outline: none;
    font-size: 13px; color: var(--text-primary); font-family: inherit; width: 190px;
}
.om-search input::placeholder { color: var(--text-muted); }

/* Shared button styles */
.om-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 15px; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; text-decoration: none; border: 1.5px solid transparent;
    transition: var(--transition); white-space: nowrap;
}
.om-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.om-btn-pri { background: var(--accent); color: #fff; border-color: var(--accent); }
.om-btn-pri:hover { filter: brightness(1.08); color: #fff; }
.om-btn-sec { background: var(--bg-card); color: var(--text-secondary); border-color: var(--border); }
.om-btn-sec:hover { border-color: var(--accent); color: var(--accent); }

/* Status filter stats */
.om-stats { display: grid; grid-template-columns: repeat(7,1fr); gap: 12px; margin-bottom: 20px; }
@media (max-width: 1100px) { .om-stats { grid-template-columns: repeat(4,1fr); } }
@media (max-width: 600px)  { .om-stats { grid-template-columns: repeat(2,1fr); } }

.om-stat {
    background: var(--bg-card); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 16px 10px; text-align: center;
    text-decoration: none; transition: var(--transition);
    position: relative; overflow: hidden;
}
.om-stat::after {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
    background: var(--s-col, var(--accent));
}
.om-stat:hover  { border-color: var(--s-col, var(--accent)); box-shadow: var(--shadow-md); transform: translateY(-2px); }
.om-stat.active { border-color: var(--s-col, var(--accent)); background: var(--bg-input); }
.om-stat-n { display: block; font-size: 24px; font-weight: 800; color: var(--s-col, var(--accent)); line-height: 1; margin-bottom: 5px; }
.om-stat-l { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); }

/* Card shell — same as odash-card */
.om-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
    margin-bottom: 20px;
}
.om-card-hdr {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    background: var(--bg-input);
}
.om-card-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
.om-card-body  { padding: 20px; }

/* Catalog form */
.om-cat-form { display: grid; grid-template-columns: 1.2fr 2fr minmax(110px,.6fr) auto; gap: 10px; align-items: end; margin-bottom: 18px; }
@media (max-width: 860px) { .om-cat-form { grid-template-columns: 1fr; } }
.om-cat-form label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 5px; }
.om-cat-form .form-control { background: var(--bg-input); }
.om-cat-form .om-btn { width: 100%; justify-content: center; }
.om-cat-sep { border: 0; border-top: 1px solid var(--border); margin: 0 0 14px; }
.om-cat-chips-hd { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin-bottom: 10px; }
.om-cat-chips { display: flex; flex-wrap: wrap; gap: 10px; }
.om-chip { background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius); padding: 11px 13px; min-width: 140px; flex: 1 1 170px; }
.om-chip-name  { font-size: 13px; font-weight: 700; color: var(--text-primary); margin-bottom: 3px; }
.om-chip-price { font-size: 13px; font-weight: 800; color: var(--accent); }
.om-chip-desc  { font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* Table */
.om-tbl-wrap { overflow-x: auto; }
.om-tbl { width: 100%; border-collapse: collapse; min-width: 680px; }
.om-tbl th {
    padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
    background: var(--bg-input); border-bottom: 1px solid var(--border); white-space: nowrap;
}
.om-tbl th:last-child { text-align: right; }
.om-tbl td { padding: 13px 16px; vertical-align: middle; border-bottom: 1px solid var(--border); font-size: 13px; }
.om-tbl tr:last-child td { border-bottom: none; }
.om-tbl tr:hover td { background: rgba(45,124,227,.025); }

/* Order ID */
.om-oid { font-size: 12px; font-weight: 700; color: var(--accent); background: var(--accent-light); padding: 4px 9px; border-radius: 6px; font-family: monospace; white-space: nowrap; }

/* Customer cell */
.om-cust { display: flex; align-items: center; gap: 10px; }
.om-av   { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-light); color: var(--accent); font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.om-cust-name  { font-size: 13px; font-weight: 700; color: var(--text-primary); }
.om-cust-phone { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

/* Amount */
.om-amt { font-size: 14px; font-weight: 800; color: var(--text-primary); }
.om-gw  { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: .05em; margin-top: 1px; }

/* Status badge */
.om-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; border: 1px solid transparent; white-space: nowrap;
}
.om-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.om-b-pending    { color: var(--warning);  background: rgba(245,158,11,.1);   border-color: rgba(245,158,11,.25); }
.om-b-paid       { color: var(--success);  background: var(--success-bg);     border-color: var(--success-border); }
.om-b-processing { color: #8b5cf6;         background: rgba(139,92,246,.1);   border-color: rgba(139,92,246,.25); }
.om-b-shipped    { color: var(--accent);   background: var(--accent-light);   border-color: rgba(45,124,227,.3); }
.om-b-delivered  { color: #059669;         background: rgba(5,150,105,.08);   border-color: rgba(5,150,105,.2); }
.om-b-failed     { color: var(--danger);   background: var(--danger-bg);      border-color: var(--danger-border); }
.om-b-refunded   { color: #d97706;         background: rgba(217,119,6,.1);    border-color: rgba(217,119,6,.25); }

/* Source pill */
.om-src { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: 3px 8px; border-radius: 5px; }

/* Date */
.om-date { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.om-rec  { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; margin-top: 3px; }
.om-rec svg { width: 11px; height: 11px; flex-shrink: 0; }

/* Row actions */
.om-acts { display: flex; gap: 6px; justify-content: flex-end; }
.om-act  {
    display: inline-flex; align-items: center; gap: 5px;
    background: var(--bg-input); border: 1px solid var(--border);
    color: var(--text-secondary); padding: 6px 10px; border-radius: 7px;
    font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition); white-space: nowrap;
}
.om-act svg { width: 13px; height: 13px; flex-shrink: 0; }
.om-act:hover      { background: var(--accent-light); border-color: rgba(45,124,227,.35); color: var(--accent); }
.om-act-ok:hover   { background: var(--success-bg); border-color: var(--success-border); color: var(--success); }
.om-act-edit:hover { background: var(--accent-light); border-color: var(--accent); color: var(--accent); }

/* Empty state */
.om-empty { padding: 56px 20px; text-align: center; }
.om-empty svg { width: 40px; height: 40px; color: var(--text-muted); opacity: .2; display: block; margin: 0 auto 12px; }
.om-empty p { font-size: 14px; color: var(--text-muted); font-weight: 600; margin: 0; }

/* Modal overlay */
.om-overlay { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.45); align-items: center; justify-content: center; }
.om-overlay.open { display: flex; }
.om-modal {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 26px; width: 440px; max-width: 92vw;
    box-shadow: var(--shadow-lg); position: relative;
}
.om-modal-title { font-size: 17px; font-weight: 800; color: var(--text-primary); margin: 0 0 4px; }
.om-modal-sub   { font-size: 13px; color: var(--text-muted); margin: 0 0 20px; }
.om-fld { margin-bottom: 14px; }
.om-fld label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 6px; }
.om-fld .form-control { background: var(--bg-input); }
.om-fld textarea.form-control { resize: vertical; }
.om-notify { display: flex; align-items: center; gap: 9px; margin-bottom: 16px; }
.om-notify input { width: 15px; height: 15px; accent-color: var(--accent); cursor: pointer; }
.om-notify label { font-size: 13px; color: var(--text-secondary); font-weight: 600; cursor: pointer; }
.om-modal-foot { display: flex; gap: 10px; margin-top: 18px; }
.om-modal-foot button { flex: 1; padding: 11px; border-radius: var(--radius); font-size: 13px; font-weight: 700; cursor: pointer; border: none; transition: var(--transition); }
.om-cancel { background: var(--bg-input); color: var(--text-secondary); border: 1.5px solid var(--border) !important; }
.om-cancel:hover { border-color: var(--accent) !important; color: var(--accent); }
.om-submit { background: var(--accent); color: #fff; flex: 2 !important; }
.om-submit:hover { filter: brightness(1.08); }
.om-mtoa { margin-top: 12px; font-size: 12px; font-weight: 700; display: none; }

@media (max-width: 768px) {
    .om-hdr { flex-direction: column; align-items: flex-start; }
    .om-hdr-right { width: 100%; }
    .om-acts { flex-wrap: wrap; }
}
</style>

<div id="omToast"></div>

<div class="om-wrap">

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'product_saved'): ?>
    <div class="om-flash om-flash-ok">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
        Service / product saved to your catalog.
    </div>
    <?php elseif ($_GET['msg'] === 'updated'): ?>
    <div class="om-flash om-flash-ok">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
        Order status updated successfully.
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Page header -->
<div class="om-hdr">
    <div class="om-hdr-left">
        <div class="om-hdr-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg>
        </div>
        <div>
            <h1 class="om-hdr-title">Order Management</h1>
            <p class="om-hdr-sub">Track and manage customer orders for <?php echo htmlspecialchars((string)($_companyName ?? 'your store'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>
    <div class="om-hdr-right">
        <form method="GET" class="om-search">
            <?php if ($statusFilter !== ''): ?>
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" name="search" placeholder="Search orders..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </form>
        <a href="catalog.php" class="om-btn om-btn-sec">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
            Catalog
        </a>
        <a href="order_dashboard.php" class="om-btn om-btn-pri">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
            Analytics
        </a>
    </div>
</div>

<!-- Status filter stats -->
<div class="om-stats">
<?php
$stMeta = [
    'pending'    => '#f59e0b',
    'paid'       => '#10b981',
    'processing' => '#8b5cf6',
    'shipped'    => '#2d7ce3',
    'delivered'  => '#059669',
    'failed'     => '#ef4444',
    'refunded'   => '#d97706',
];
foreach ($stMeta as $st => $col):
    $cnt      = $statsByStatus[$st]['c'] ?? 0;
    $isActive = ($statusFilter === $st);
?>
<a href="orders.php?status=<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>"
   class="om-stat<?php echo $isActive ? ' active' : ''; ?>"
   style="--s-col:<?php echo $col; ?>">
    <span class="om-stat-n"><?php echo $cnt; ?></span>
    <span class="om-stat-l"><?php echo $st; ?></span>
</a>
<?php endforeach; ?>
</div>

<!-- Services / Products catalog -->
<div class="om-card">
    <div class="om-card-hdr">
        <span class="om-card-title">Your Services &amp; Products</span>
        <a href="catalog.php" class="om-btn om-btn-sec" style="padding:5px 12px;font-size:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
            Full catalog
        </a>
    </div>
    <div class="om-card-body">
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;line-height:1.5;">Add what you sell — each account has its own list, visible in your catalog integration.</p>
        <form method="POST" class="om-cat-form">
            <?php echo csrf_field(); ?>
            <div>
                <label for="product_name">Name</label>
                <input type="text" id="product_name" name="product_name" class="form-control" maxlength="255" placeholder="e.g. Business website package" required>
            </div>
            <div>
                <label for="product_description">Description <span style="font-weight:400;opacity:.7;">(optional)</span></label>
                <textarea id="product_description" name="product_description" class="form-control" maxlength="4000" rows="2" placeholder="What's included…"></textarea>
            </div>
            <div>
                <label for="product_price">Price (₹)</label>
                <input type="number" id="product_price" name="product_price" class="form-control" step="0.01" min="0" placeholder="0" required>
            </div>
            <div>
                <label style="opacity:0;">Add</label>
                <button type="submit" name="quick_add_product" value="1" class="om-btn om-btn-pri">Add</button>
            </div>
        </form>
        <hr class="om-cat-sep">
        <div class="om-cat-chips-hd">Recently added (<?php echo count($catalogProducts); ?>)</div>
        <?php if (count($catalogProducts) === 0): ?>
        <p style="margin:0;font-size:13px;color:var(--text-muted);">No items yet — use the form above or <a href="catalog.php" style="color:var(--accent);font-weight:700;">full catalog</a> to add images.</p>
        <?php else: ?>
        <div class="om-cat-chips">
            <?php foreach ($catalogProducts as $cp): ?>
            <div class="om-chip">
                <div class="om-chip-name"><?php echo htmlspecialchars((string)$cp['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="om-chip-price">₹<?php echo number_format((float)$cp['price'], 2); ?></div>
                <?php if (trim((string)($cp['description'] ?? '')) !== ''): ?>
                <div class="om-chip-desc"><?php echo htmlspecialchars((string)$cp['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="margin:12px 0 0;font-size:12px;color:var(--text-muted);">To edit, upload images or delete — use <a href="catalog.php" style="color:var(--accent);font-weight:700;">Product catalog</a>.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Orders table -->
<div class="om-card" style="margin-bottom:0;">
    <div class="om-card-hdr">
        <span class="om-card-title">
            Orders
            <?php if ($statusFilter !== '' || $search !== ''): ?>
            <span style="font-size:11px;font-weight:600;color:var(--text-muted);margin-left:6px;">(filtered)</span>
            <?php endif; ?>
        </span>
        <?php if ($statusFilter !== '' || $search !== ''): ?>
        <a href="orders.php" class="om-btn om-btn-sec" style="padding:5px 12px;font-size:12px;">Clear filter</a>
        <?php endif; ?>
    </div>
    <div class="om-tbl-wrap">
        <table class="om-tbl">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($orders === false || $orders->num_rows === 0): ?>
            <tr>
                <td colspan="7">
                    <div class="om-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg>
                        <p>No orders found<?php echo ($statusFilter !== '' || $search !== '') ? ' for the current filter' : ''; ?></p>
                    </div>
                </td>
            </tr>
            <?php else: while ($o = $orders->fetch_assoc()):
                $stKey    = strtolower(trim((string)($o['status'] ?? 'pending')));
                $badgeCls = in_array($stKey, $ORDER_STATUSES, true) ? 'om-b-' . $stKey : 'om-b-pending';
                $src      = $o['order_source'] ?: 'Manual';
                $srcClr   = $src === 'Bot' ? '#8b5cf6' : ($src === 'External' ? '#2d7ce3' : '#64748b');
                $srcBg    = $src === 'Bot' ? 'rgba(139,92,246,.1)' : ($src === 'External' ? 'rgba(45,124,227,.1)' : 'rgba(100,116,139,.1)');
            ?>
            <tr>
                <td><span class="om-oid">#<?php echo htmlspecialchars((string)($o['order_number'] ?? 'ORD-'.$o['id']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td>
                    <div class="om-cust">
                        <div class="om-av"><?php echo strtoupper(substr((string)($o['buyer_name'] ?? $o['customer_name'] ?? 'U'), 0, 1)); ?></div>
                        <div>
                            <div class="om-cust-name"><?php echo htmlspecialchars((string)($o['buyer_name'] ?? $o['customer_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="om-cust-phone"><?php echo htmlspecialchars((string)($o['buyer_phone'] ?? $o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if (!empty($o['buyer_address'])): ?>
                            <div class="om-cust-phone" title="<?php echo htmlspecialchars((string)($o['buyer_address'] . ', ' . $o['buyer_city'] . ' - ' . $o['buyer_pincode']), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string)($o['buyer_city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string)($o['buyer_pincode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="om-amt">₹<?php echo number_format((float)$o['amount'], 2); ?></div>
                    <div class="om-gw"><?php echo htmlspecialchars(strtoupper((string)($o['gateway'] ?: 'Manual')), ENT_QUOTES, 'UTF-8'); ?></div>
                </td>
                <td><span class="om-badge <?php echo $badgeCls; ?>"><?php echo htmlspecialchars(ucfirst((string)($o['status'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td>
                    <span class="om-src" style="color:<?php echo $srcClr; ?>;background:<?php echo $srcBg; ?>">
                        <?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </td>
                <td>
                    <div class="om-date"><?php echo date('d M, H:i', strtotime((string)$o['created_at'])); ?></div>
                    <?php if (($o['status'] ?? '') === 'pending'): ?>
                    <div class="om-rec" style="color:<?php echo !empty($o['recovery_sent']) ? 'var(--success)' : 'var(--text-muted)'; ?>">
                        <?php if (!empty($o['recovery_sent'])): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
                        Recovered
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Pending recovery
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="om-acts">
                        <button type="button" class="om-act" onclick="sendPayment(<?php echo (int)$o['id']; ?>, 'upi')" title="Send UPI payment link">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/></svg>
                            UPI
                        </button>
                        <button type="button" class="om-act" onclick="sendPayment(<?php echo (int)$o['id']; ?>, 'razorpay')" title="Send Razorpay link">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                            Pay
                        </button>
                        <?php if (strtolower((string)($o['status'] ?? '')) === 'pending' && strtolower((string)($o['gateway'] ?? '')) === 'upi'): ?>
                        <button type="button" class="om-act om-act-ok" onclick="openUpiPaidModal(<?php echo (int)$o['id']; ?>)" title="Mark as paid">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
                            Paid
                        </button>
                        <?php endif; ?>
                        <button type="button" class="om-act om-act-edit" onclick="openUpdateModal(<?php echo (int)$o['id']; ?>, <?php echo json_encode((string)($o['status'] ?? 'pending'), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>, <?php echo json_encode((string)($o['tracking_url'] ?? ''), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>, <?php echo json_encode((string)($o['estimated_delivery'] ?? ''), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>, <?php echo json_encode((string)($o['status_notes'] ?? ''), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                            Update
                        </button>
                    </div>
                </td>
            </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /.om-wrap -->

<!-- UPI Paid Modal -->
<div id="upiPaidModal" class="om-overlay">
    <div class="om-modal">
        <h3 class="om-modal-title">Confirm UPI Payment</h3>
        <p class="om-modal-sub">Use this only after verifying the payment in your bank / UPI app.</p>
        <div class="om-fld">
            <label for="upiPaidUtr">UTR / Reference <span style="font-weight:400;opacity:.7;">(optional)</span></label>
            <input type="text" id="upiPaidUtr" class="form-control" placeholder="e.g. 324512345678">
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 4px;line-height:1.5;">
            This will mark the order as <strong style="color:var(--success);">Paid</strong> and queue a WhatsApp confirmation.
        </p>
        <div class="om-modal-foot">
            <button type="button" class="om-cancel" onclick="closeUpiPaidModal()">Cancel</button>
            <button type="button" class="om-submit" onclick="submitUpiPaid()">Mark as Paid</button>
        </div>
        <div id="upiPaidToast" class="om-mtoa"></div>
    </div>
</div>

<!-- Update Status Modal -->
<div id="updateModal" class="om-overlay">
    <div class="om-modal">
        <h3 class="om-modal-title">Update Order Status</h3>
        <p class="om-modal-sub">Change status and optionally notify the customer on WhatsApp.</p>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="order_id" id="modalOrderId">
            <input type="hidden" name="preserve_status" id="preserve_status" value="">
            <div class="om-fld">
                <label>Status</label>
                <select name="status" id="modalStatus" class="form-control">
                    <?php foreach ($ORDER_STATUSES as $st): ?>
                    <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($st), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="om-fld">
                <label>Tracking URL</label>
                <input type="text" name="tracking_url" id="modalTracking" class="form-control" placeholder="https://tracking.example.com/...">
            </div>
            <div class="om-fld">
                <label>Estimated Delivery</label>
                <input type="date" name="estimated_delivery" id="modalEstDelivery" class="form-control">
            </div>
            <div class="om-fld">
                <label>Notes <span style="font-weight:400;opacity:.7;">(shown in WhatsApp message)</span></label>
                <textarea name="status_notes" id="modalNotes" class="form-control" rows="2" placeholder="Optional notes for the customer…"></textarea>
            </div>
            <div class="om-notify">
                <input type="checkbox" name="notify_customer" id="modalNotify" value="1">
                <label for="modalNotify">Notify customer on WhatsApp (queued)</label>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-cancel" onclick="document.getElementById('updateModal').classList.remove('open')">Cancel</button>
                <button type="submit" name="update_status" class="om-submit">Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
window.ordersStatusFilter = <?php echo json_encode($statusFilter, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

function omToast(msg, type) {
    var t = document.getElementById('omToast');
    t.textContent = msg;
    t.style.background = type === 'error' ? 'var(--danger)' : type === 'success' ? 'var(--success)' : 'var(--text-primary)';
    t.classList.add('show');
    clearTimeout(t._tid);
    t._tid = setTimeout(function() { t.classList.remove('show'); }, 3500);
}

document.querySelectorAll('.om-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
});

function openUpdateModal(id, status, tracking, delivery, notes) {
    document.getElementById('modalOrderId').value = id;
    document.getElementById('modalStatus').value = status;
    document.getElementById('modalTracking').value = tracking || '';
    var est = document.getElementById('modalEstDelivery');
    if (est) { var dlv = delivery || ''; if (dlv.indexOf(' ') !== -1) dlv = dlv.slice(0, 10); est.value = dlv; }
    var nt = document.getElementById('modalNotes');
    if (nt) nt.value = notes || '';
    var pf = document.getElementById('preserve_status');
    if (pf) pf.value = (typeof window.ordersStatusFilter === 'string') ? window.ordersStatusFilter : '';
    document.getElementById('updateModal').classList.add('open');
}

var _upiOid = 0;
function openUpiPaidModal(orderId) {
    _upiOid = parseInt(orderId || 0, 10) || 0;
    var utr = document.getElementById('upiPaidUtr');
    var t   = document.getElementById('upiPaidToast');
    if (t) { t.style.display = 'none'; t.textContent = ''; }
    if (utr) { utr.value = ''; }
    document.getElementById('upiPaidModal').classList.add('open');
    if (utr) setTimeout(function(){ utr.focus(); }, 80);
}
function closeUpiPaidModal() {
    document.getElementById('upiPaidModal').classList.remove('open');
    _upiOid = 0;
}
function submitUpiPaid() {
    if (!_upiOid) return;
    var utr = (document.getElementById('upiPaidUtr') || {}).value || '';
    var t   = document.getElementById('upiPaidToast');
    var fd  = new FormData();
    fd.append('order_id', String(_upiOid));
    fd.append('utr', utr);
    var tok = document.querySelector('input[name="_token"]') || document.querySelector('input[name="csrf_token"]');
    if (tok && tok.value) fd.append('_token', tok.value);
    fetch('ajax_mark_upi_paid.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json().catch(function() { return { status: 'error', message: 'Bad response' }; }); })
        .then(function(data) {
            if (t) {
                t.style.display = 'block';
                t.textContent = data.message || (data.status === 'success' ? 'Marked as paid.' : 'Failed.');
                t.style.color = data.status === 'success' ? 'var(--success)' : 'var(--danger)';
            }
            if (data.status === 'success') setTimeout(function(){ window.location.reload(); }, 700);
        })
        .catch(function() {
            if (t) { t.style.display = 'block'; t.textContent = 'Network error. Please retry.'; t.style.color = 'var(--danger)'; }
        });
}

function sendPayment(orderId, method) {
    if (!confirm('Send payment link via ' + method.toUpperCase() + ' to the customer?')) return;
    var body = '_token=' + encodeURIComponent('<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>')
        + '&order_id=' + encodeURIComponent(orderId)
        + '&method=' + encodeURIComponent(method);
    fetch('ajax_send_order_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body, credentials: 'same-origin'
    })
    .then(function(r) { return r.json().catch(function() { return { status: 'error', message: 'Bad response' }; }); })
    .then(function(data) {
        if (data.status === 'success') {
            omToast('Payment link sent via WhatsApp.', 'success');
        } else {
            omToast('Failed: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(function() { omToast('Network error. Please retry.', 'error'); });
}
</script>

<?php include '../includes/client_footer.php'; ?>

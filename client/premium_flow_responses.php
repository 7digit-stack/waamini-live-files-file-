<?php
include '../includes/client_auth_check.php';
require_once '../includes/wa_flows.php';

if (!has_feature('whatsapp_flows')) {
    header('Location: dashboard.php');
    exit();
}

waf_ensure_schema($conn);

$flowId = (int)($_GET['flow_id'] ?? 0);
$flow = null;
if ($flowId > 0) {
    $st = $conn->prepare("SELECT id, flow_name FROM whatsapp_flows WHERE id=? AND company_id=? LIMIT 1");
    $st->bind_param('ii', $flowId, $companyId);
    $st->execute();
    $flow = $st->get_result()->fetch_assoc();
    if (!$flow) { header('Location: premium_flows.php'); exit(); }
}

$sql = "SELECT r.*, c.name AS contact_name, f.flow_name
        FROM whatsapp_flow_responses r
        LEFT JOIN contacts c ON c.id = r.contact_id AND c.company_id = r.company_id
        LEFT JOIN whatsapp_flows f ON f.id = r.flow_id AND f.company_id = r.company_id
        WHERE r.company_id = ?" . ($flowId > 0 ? " AND r.flow_id = ?" : "") . "
        ORDER BY r.id DESC LIMIT " . (isset($_GET['export']) ? 20000 : 500);
$st = $conn->prepare($sql);
if ($flowId > 0) $st->bind_param('ii', $companyId, $flowId); else $st->bind_param('i', $companyId);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// Answer columns = union of keys across responses, in first-seen order.
$fields = [];
foreach ($rows as &$r) {
    $a = json_decode((string)$r['response_json'], true);
    $r['answers'] = is_array($a) ? array_diff_key($a, ['flow_token' => 1]) : [];
    foreach (array_keys($r['answers']) as $k) $fields[$k] = true;
}
unset($r);
$fields = array_keys($fields);
$cell = static fn($v) => is_array($v) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v)) : (string)$v;
$label = static fn($k) => ucfirst(trim(preg_replace('/[_\-]+/', ' ', preg_replace('/^screen_\d+_/', '', (string)$k))));
$dec = static fn($s) => html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="form-responses-' . ($flowId ?: 'all') . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = static fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v; // block CSV formula injection
    fputcsv($out, array_merge(['Submitted', 'Form', 'Contact', 'Phone'], array_map($label, $fields)));
    foreach ($rows as $r) {
        $line = [$r['created_at'], $dec($r['flow_name'] ?? 'Unmatched'), $r['contact_name'] ?? '', $r['phone'] ?? ''];
        foreach ($fields as $k) $line[] = $cell($r['answers'][$k] ?? '');
        fputcsv($out, array_map($safe, $line));
    }
    exit();
}

$pageTitle = 'Form Responses';
$currentPage = 'premium_flows';
include '../includes/client_header.php';
$e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
.wr-top{background:#fff;border-radius:18px;padding:26px 30px;margin-bottom:22px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45);display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap}
.wr-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#128C7E;margin-bottom:6px}
.wr-title{font-size:22px;font-weight:800;color:var(--text-primary);margin:0;letter-spacing:-.3px}
.wr-sub{font-size:13px;color:var(--text-secondary);margin:6px 0 0}
.wr-btns{display:flex;gap:8px;flex-wrap:wrap}
.wr-btns .btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:10px;font-weight:700;height:36px;padding:0 14px;font-size:12.5px}
.wr-btns .btn-edit{background:#EEF3F1;color:#34453f}.wr-btns .btn-primary{background:#128C7E;color:#fff}.wr-btns .btn-primary:hover{background:#0B6B5E;color:#fff}
.wr-list{display:grid;gap:14px}
.wr-card{background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45)}
.wr-who{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13px}
.wr-who b{color:var(--text-primary)}
.wr-when{font-size:12px;color:var(--text-muted)}
.wr-ans{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:12px}
.wr-a{background:#F3FBF7;border-radius:12px;padding:10px 12px}
.wr-k{font-size:11px;color:var(--text-muted);margin-bottom:2px}
.wr-v{font-size:13px;color:var(--text-primary);word-break:break-word}
.wr-tag{font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;background:#E3F7EE;color:#0B6B5E}
</style>

<div class="wr-top">
    <div>
        <div class="wr-eyebrow">WA Native Forms · Responses</div>
        <h1 class="wr-title"><?php echo $flow ? $e($dec($flow['flow_name'])) : 'All form responses'; ?></h1>
        <p class="wr-sub"><?php echo count($rows); ?> <?php echo count($rows) === 1 ? 'response' : 'responses'; ?><?php echo count($rows) >= 500 ? ' (latest 500 shown; the export has all)' : ''; ?>. New answers appear here as soon as a customer submits the form.</p>
    </div>
    <div class="wr-btns">
        <a href="premium_flows.php" class="btn btn-edit">← Back to forms</a>
        <?php if ($rows): ?><a class="btn btn-primary" href="?<?php echo $flowId ? 'flow_id=' . $flowId . '&' : ''; ?>export=1">Export CSV</a><?php endif; ?>
    </div>
</div>

<div class="wr-list">
<?php if (!$rows): ?>
    <div class="wr-card"><div class="empty-state"><p>No responses yet. They appear here as soon as a customer submits the form in WhatsApp.</p></div></div>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
    <div class="wr-card">
        <div class="wr-who">
            <div>
                <b><?php echo $e($r['contact_name'] ?: ('+' . ltrim((string)$r['phone'], '+'))); ?></b>
                <?php if ($r['contact_name']): ?><span class="wr-when"> · +<?php echo $e(ltrim((string)$r['phone'], '+')); ?></span><?php endif; ?>
                <?php if (!$flowId): ?> <span class="wr-tag"><?php echo $e($r['flow_name'] ? $dec($r['flow_name']) : 'Unmatched form'); ?></span><?php endif; ?>
            </div>
            <div class="wr-when"><?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?></div>
        </div>
        <div class="wr-ans">
            <?php foreach ($r['answers'] as $k => $v): ?>
                <div class="wr-a"><div class="wr-k"><?php echo $e($label($k)); ?></div><div class="wr-v"><?php echo $e($cell($v)) ?: '—'; ?></div></div>
            <?php endforeach; ?>
            <?php if (!$r['answers']): ?><div class="wr-a"><div class="wr-v">Empty submission</div></div><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php include '../includes/client_footer.php'; ?>

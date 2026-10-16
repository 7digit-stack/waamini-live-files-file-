<?php
include '../includes/client_auth_check.php';
include_once '../includes/schema_guard.php';
require_once '../includes/wa_flows.php';

if (!has_feature('whatsapp_flows')) {
    header('Location: dashboard.php');
    exit();
}

$pageTitle = 'WhatsApp Native Forms';
$currentPage = 'premium_flows';

waf_ensure_schema($conn);
$waCfg = waf_config($conn, $companyId);

$back = static function (string $key, string $msg, int $open = 0): void {
    header('Location: premium_flows.php?' . $key . '=' . urlencode($msg) . ($open ? '&open=' . $open : '') . ($open ? '#flow-' . $open : ''));
    exit();
};

/** Validate pasted Flow JSON. Returns [canonical JSON, error]. */
$cleanJson = static function (string $raw): array {
    if (trim($raw) === '') return ['', 'Flow JSON is required.'];
    if (strlen($raw) > 512000) return ['', 'Flow JSON is too large (max ~500KB).'];
    $p = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return ['', 'Invalid JSON: ' . json_last_error_msg() . '.'];
    if (!is_array($p) || empty($p['screens']) || !is_array($p['screens'])) return ['', 'This is not a Flow definition. It needs a "version" and a "screens" list (copy it from Meta\'s Flow Builder).'];
    $c = waf_normalize_json($raw); // keeps {} as {} (assoc decode would turn it into [])
    return $c === '' ? ['', 'Could not normalize JSON.'] : [$c, ''];
};

$loadFlow = static function (int $id) use ($conn, $companyId): ?array {
    $st = $conn->prepare("SELECT * FROM whatsapp_flows WHERE id=? AND company_id=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('ii', $id, $companyId);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
};

$saveMeta = static function (int $id, ?string $metaId, ?string $metaStatus, string $err) use ($conn, $companyId): void {
    $local = strtoupper((string)$metaStatus) === 'PUBLISHED' ? 'published' : 'draft';
    $st = $conn->prepare("UPDATE whatsapp_flows SET meta_flow_id=COALESCE(?, meta_flow_id), meta_status=COALESCE(?, meta_status), status=?, last_error=?, updated_at=NOW(),
        published_at = IF(? = 'published' AND published_at IS NULL, NOW(), published_at) WHERE id=? AND company_id=?");
    if (!$st) return;
    $errVal = $err === '' ? null : mb_substr($err, 0, 4000);
    $st->bind_param('sssssii', $metaId, $metaStatus, $local, $errVal, $local, $id, $companyId);
    $st->execute();
};

$doPublish = static function (int $id, string $metaId) use (&$waCfg, $saveMeta): array {
    $r = waf_graph($waCfg, 'POST', $metaId . '/publish', []);
    $s = waf_fetch_status($waCfg, $metaId);
    $saveMeta($id, null, $s['status'] ?: null, $r['ok'] ? $s['error'] : $r['error']);
    return $r;
};

/** After saving JSON: 'draft' = keep local, 'upload' = push to Meta, 'publish' = push + publish. Returns [ok, message]. */
$deliver = static function (int $id, string $mode) use (&$waCfg, $loadFlow, $saveMeta, $doPublish): array {
    if ($mode === 'draft') return [true, 'Saved as a draft. Upload it to Meta when you are ready.'];
    if (!$waCfg) return [true, 'Saved. Connect WhatsApp in settings to upload and publish it.'];
    $r = waf_push($waCfg, $loadFlow($id));
    $saveMeta($id, $r['meta_flow_id'] ?: null, $r['meta_flow_id'] ? 'DRAFT' : null, $r['error']);
    if (!$r['ok']) return [false, 'Saved, but Meta found a problem. See the red box on the form.'];
    if ($mode !== 'publish') return [true, 'Saved and uploaded to Meta. Send yourself a test, then publish.'];
    $p = $doPublish($id, $r['meta_flow_id']);
    return $p['ok'] ? [true, 'Published! Your form is live. Next: create a template with its Open form button.'] : [false, 'Uploaded, but publishing failed: ' . $p['error']];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['flow_id'] ?? 0);
    $mode = in_array($_POST['submit_mode'] ?? '', ['draft', 'upload', 'publish'], true) ? $_POST['submit_mode'] : 'upload';
    $name = mb_substr(trim((string)($_POST['flow_name'] ?? '')), 0, 100);
    $cat = (string)($_POST['category'] ?? 'OTHER');
    if (!isset(WAF_CATEGORIES[$cat])) $cat = 'OTHER';

    if ($action === 'create') {
        [$json, $err] = $cleanJson((string)($_POST['flow_json'] ?? ''));
        if ($name === '') $err = 'Form name is required.';
        if ($err !== '') $back('err', $err);
        $st = $conn->prepare("INSERT INTO whatsapp_flows (company_id, flow_name, flow_json, status, category, updated_at) VALUES (?, ?, ?, 'draft', ?, NOW())");
        if (!$st) $back('err', 'Database error. Please try again.');
        $st->bind_param('isss', $companyId, $name, $json, $cat);
        $st->execute();
        $newId = (int)$conn->insert_id;
        [$ok, $msg] = $deliver($newId, $mode);
        $back($ok ? 'ok' : 'err', $msg, $newId);
    }

    $flow = $id > 0 ? $loadFlow($id) : null;
    if (!$flow) $back('err', 'Form not found.');
    $metaStatus = strtoupper((string)($flow['meta_status'] ?? ''));
    $locked = in_array($metaStatus, ['PUBLISHED', 'DEPRECATED'], true);

    // Visual builder "Open in builder" save for an existing draft.
    if ($action === 'builder_save') {
        if ($locked) $back('err', 'Published forms cannot be changed on Meta. Create a new form instead.', $id);
        [$json, $err] = $cleanJson((string)($_POST['flow_json'] ?? ''));
        if ($name === '') $err = 'Form name is required.';
        if ($err !== '') $back('err', $err, $id);
        $st = $conn->prepare("UPDATE whatsapp_flows SET flow_json=?, flow_name=?, category=?, updated_at=NOW() WHERE id=? AND company_id=?");
        $st->bind_param('sssii', $json, $name, $cat, $id, $companyId);
        $st->execute();
        // A form already on Meta is always re-uploaded so Meta's copy matches what was saved here.
        if ($mode === 'draft' && !empty($flow['meta_flow_id'])) $mode = 'upload';
        [$ok, $msg] = $deliver($id, $mode);
        $back($ok ? 'ok' : 'err', $msg, $id);
    }

    if ($action === 'save_json') {
        if ($locked) $back('err', 'Published forms cannot be changed on Meta. Create a new form instead.', $id);
        [$json, $err] = $cleanJson((string)($_POST['flow_json'] ?? ''));
        if ($err !== '') $back('err', $err, $id);
        $st = $conn->prepare("UPDATE whatsapp_flows SET flow_json=?, updated_at=NOW() WHERE id=? AND company_id=?");
        $st->bind_param('sii', $json, $id, $companyId);
        $st->execute();
        if (!empty($flow['meta_flow_id']) && $waCfg) {
            $u = waf_upload_json($waCfg, (string)$flow['meta_flow_id'], $json);
            $saveMeta($id, null, null, $u['error']);
            $back($u['ok'] ? 'ok' : 'err', $u['ok'] ? 'JSON updated on Meta.' : 'Saved, but Meta reported problems with the JSON.', $id);
        }
        $back('ok', 'JSON saved.', $id);
    }

    // Delete works without a WhatsApp connection so local-only drafts can always be removed.
    if ($action === 'delete') {
        if ($metaStatus === 'PUBLISHED') $back('err', 'Retire a published form before deleting it.', $id);
        if (!empty($flow['meta_flow_id']) && $metaStatus === 'DRAFT' && $waCfg) waf_graph($waCfg, 'DELETE', (string)$flow['meta_flow_id']);
        $st = $conn->prepare("DELETE FROM whatsapp_flows WHERE id=? AND company_id=?");
        $st->bind_param('ii', $id, $companyId);
        $st->execute();
        $back('ok', 'Form deleted.');
    }

    if (!$waCfg) $back('err', 'Connect WhatsApp first: WABA ID and access token are missing in WhatsApp settings.', $id);

    if ($action === 'push') {
        if ($locked) $back('err', 'This form is already published.', $id);
        $r = waf_push($waCfg, $flow);
        $saveMeta($id, $r['meta_flow_id'] ?: null, $r['meta_flow_id'] ? 'DRAFT' : null, $r['error']);
        $back($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Uploaded to Meta. Send yourself a test, then publish.' : 'Meta reported problems. See the errors on the form.', $id);
    }

    $metaId = (string)($flow['meta_flow_id'] ?? '');
    if ($metaId === '') $back('err', 'Upload this form to Meta first.', $id);

    if ($action === 'publish') {
        $r = $doPublish($id, $metaId);
        $back($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Published. You can now add it to a template with an "Open form" button.' : 'Publish failed: ' . $r['error'], $id);
    }

    if ($action === 'sync') {
        $s = waf_fetch_status($waCfg, $metaId);
        if (!$s['ok']) $back('err', 'Could not read status: ' . $s['error'], $id);
        $saveMeta($id, null, $s['status'], $s['error']);
        $back('ok', 'Status refreshed: ' . ($s['status'] ?: 'unknown') . '.', $id);
    }

    if ($action === 'send_test') {
        $phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 1024);
        $cta = mb_substr(trim((string)($_POST['cta'] ?? '')), 0, 20);
        if (strlen($phone) < 8 || $body === '' || $cta === '') $back('err', 'Phone, message and button text are required.', $id);
        $r = waf_send_interactive($waCfg, $flow, $phone, $body, $cta);
        $back($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Sent to +' . $phone . '.' : 'Send failed: ' . $r['error'], $id);
    }

    if ($action === 'send_group') {
        if (!has_feature('campaigns') && !has_feature('feat_campaigns')) $back('err', 'Bulk sending needs the Campaigns feature on your plan.', $id);
        if (!empty($viewOnlyMode)) $back('err', 'Your plan has expired. Renew to send campaigns.', $id);
        if ($metaStatus !== 'PUBLISHED') $back('err', 'Publish the form before sending it to a group.', $id);
        $tplId = (int)($_POST['template_id'] ?? 0);
        $tpl = null;
        foreach (waf_flow_templates($conn, $companyId, $metaId) as $t) if ((int)$t['id'] === $tplId) $tpl = $t;
        if (!$tpl) $back('err', 'Pick an approved template that has this form\'s button.', $id);
        $at = null;
        if (($_POST['when'] ?? 'now') === 'schedule') {
            $ts = strtotime((string)($_POST['scheduled_at'] ?? ''));
            if (!$ts || $ts <= time()) $back('err', 'Pick a date and time in the future.', $id);
            $at = date('Y-m-d H:i:s', $ts);
        }
        $cName = trim((string)($_POST['campaign_name'] ?? ''));
        if ($cName === '') $cName = 'Form: ' . html_entity_decode((string)$flow['flow_name'], ENT_QUOTES, 'UTF-8') . ' · ' . date('d M, h:i A');
        $r = waf_send_to_group($conn, $companyId, $flow, $tpl, max(0, (int)($_POST['group_id'] ?? 0)), sanitize(mb_substr($cName, 0, 150)), $at);
        if (!$r['ok']) $back('err', $r['error'], $id);
        header('Location: campaign_view.php?id=' . (int)$r['campaign_id']);
        exit();
    }

    if ($action === 'deprecate') {
        $r = waf_graph($waCfg, 'POST', $metaId . '/deprecate', []);
        if ($r['ok']) $saveMeta($id, null, 'DEPRECATED', '');
        $back($r['ok'] ? 'ok' : 'err', $r['ok'] ? 'Form retired. Templates using it will stop opening it.' : 'Could not retire: ' . $r['error'], $id);
    }

    $back('err', 'Unknown action.');
}

$flows = [];
$st = $conn->prepare("SELECT * FROM whatsapp_flows WHERE company_id = ? ORDER BY id DESC");
if ($st) {
    $st->bind_param('i', $companyId);
    $st->execute();
    $flows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}
$counts = [];
$st = $conn->prepare("SELECT COALESCE(flow_id,0) AS f, COUNT(*) AS n FROM whatsapp_flow_responses WHERE company_id=? GROUP BY flow_id");
if ($st) {
    $st->bind_param('i', $companyId);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $counts[(int)$r['f']] = (int)$r['n'];
}
$openId = (int)($_GET['open'] ?? 0);
$e = static fn($s) => htmlspecialchars(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');

$statusInfo = static function (array $f): array {
    $ms = strtoupper((string)($f['meta_status'] ?? ''));
    if (empty($f['meta_flow_id'])) return ['Not on Meta', 'muted', 'Saved here only. Upload it to Meta to test.'];
    switch ($ms) {
        case 'PUBLISHED':  return ['Published', 'ok', 'Live. Add it to a template with an "Open form" button.'];
        case 'DEPRECATED': return ['Retired', 'muted', 'No longer opens for customers.'];
        case 'BLOCKED':    return ['Blocked', 'bad', 'Meta blocked this form. Check WhatsApp Manager.'];
        case 'THROTTLED':  return ['Throttled', 'warn', 'Meta is limiting this form. Check WhatsApp Manager.'];
        default:           return ['Draft on Meta', 'warn', 'Send yourself a test, then publish.'];
    }
};

$wabaQ = ($waCfg && !empty($waCfg['waba_id'])) ? '?waba_id=' . rawurlencode((string)$waCfg['waba_id']) : '';
$metaFlowsUrl = 'https://business.facebook.com/wa/manage/flows/' . $wabaQ;
$metaPlayUrl  = 'https://developers.facebook.com/docs/whatsapp/flows/playground';
$metaDocsUrl  = 'https://developers.facebook.com/docs/whatsapp/flows';

$nPublished = count(array_filter($flows, fn($f) => strtoupper((string)$f['meta_status']) === 'PUBLISHED'));
$nDraft = count(array_filter($flows, fn($f) => strtoupper((string)$f['meta_status']) !== 'PUBLISHED' && strtoupper((string)$f['meta_status']) !== 'DEPRECATED'));
$nResponses = array_sum($counts);

// Bulk send: groups + approved templates that carry each published form's button.
$canCampaign = has_feature('campaigns') || has_feature('feat_campaigns');
$audience = $canCampaign ? waf_contact_groups($conn, $companyId) : ['groups' => [], 'all' => 0];
$flowTpls = [];
foreach ($flows as $f) {
    if (strtoupper((string)$f['meta_status']) === 'PUBLISHED') $flowTpls[(int)$f['id']] = waf_flow_templates($conn, $companyId, (string)$f['meta_flow_id']);
}

// Ready-made forms for beginners. stdClass keeps "data": {} an object in JSON.
$starterFooter = static fn(array $fields) => ['type' => 'Footer', 'label' => 'Submit', 'on-click-action' => ['name' => 'complete', 'payload' =>
    array_combine($fields, array_map(fn($f) => '${form.' . $f . '}', $fields))]];
$starters = [
    'lead' => ['name' => 'Lead enquiry form', 'category' => 'LEAD_GENERATION', 'json' => ['version' => '6.0', 'screens' => [[
        'id' => 'LEAD_FORM', 'title' => 'Get in touch', 'terminal' => true, 'success' => true, 'data' => new stdClass(),
        'layout' => ['type' => 'SingleColumnLayout', 'children' => [
            ['type' => 'TextSubheading', 'text' => 'Share your details and our team will call you back.'],
            ['type' => 'TextInput', 'name' => 'full_name', 'label' => 'Full name', 'input-type' => 'text', 'required' => true],
            ['type' => 'TextInput', 'name' => 'mobile', 'label' => 'Mobile number', 'input-type' => 'phone', 'required' => true],
            ['type' => 'Dropdown', 'name' => 'interest', 'label' => 'Interested in', 'required' => true, 'data-source' => [
                ['id' => 'product', 'title' => 'Product enquiry'], ['id' => 'price', 'title' => 'Price details'],
                ['id' => 'demo', 'title' => 'Book a demo'], ['id' => 'other', 'title' => 'Something else']]],
            ['type' => 'TextArea', 'name' => 'message', 'label' => 'Message (optional)', 'required' => false],
            $starterFooter(['full_name', 'mobile', 'interest', 'message']),
        ]]]]]],
    'feedback' => ['name' => 'Customer feedback', 'category' => 'SURVEY', 'json' => ['version' => '6.0', 'screens' => [[
        'id' => 'FEEDBACK', 'title' => 'Rate us', 'terminal' => true, 'success' => true, 'data' => new stdClass(),
        'layout' => ['type' => 'SingleColumnLayout', 'children' => [
            ['type' => 'TextSubheading', 'text' => 'How was your experience with us?'],
            ['type' => 'RadioButtonsGroup', 'name' => 'rating', 'label' => 'Your rating', 'required' => true, 'data-source' => [
                ['id' => '5', 'title' => '★★★★★ Excellent'], ['id' => '4', 'title' => '★★★★☆ Good'], ['id' => '3', 'title' => '★★★☆☆ Average'],
                ['id' => '2', 'title' => '★★☆☆☆ Poor'], ['id' => '1', 'title' => '★☆☆☆☆ Very poor']]],
            ['type' => 'TextArea', 'name' => 'comments', 'label' => 'Anything we can do better?', 'required' => false],
            $starterFooter(['rating', 'comments']),
        ]]]]]],
    'appointment' => ['name' => 'Appointment booking', 'category' => 'APPOINTMENT_BOOKING', 'json' => ['version' => '6.0', 'screens' => [[
        'id' => 'BOOKING', 'title' => 'Book a visit', 'terminal' => true, 'success' => true, 'data' => new stdClass(),
        'layout' => ['type' => 'SingleColumnLayout', 'children' => [
            ['type' => 'TextSubheading', 'text' => 'Pick a day and time that suits you.'],
            ['type' => 'TextInput', 'name' => 'full_name', 'label' => 'Full name', 'input-type' => 'text', 'required' => true],
            ['type' => 'DatePicker', 'name' => 'visit_date', 'label' => 'Preferred date', 'required' => true],
            ['type' => 'Dropdown', 'name' => 'time_slot', 'label' => 'Time slot', 'required' => true, 'data-source' => [
                ['id' => 'morning', 'title' => 'Morning (10 AM – 12 PM)'], ['id' => 'afternoon', 'title' => 'Afternoon (2 PM – 4 PM)'],
                ['id' => 'evening', 'title' => 'Evening (5 PM – 7 PM)']]],
            $starterFooter(['full_name', 'visit_date', 'time_slot']),
        ]]]]]],
];
foreach ($starters as &$s) $s['json'] = json_encode($s['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
unset($s);

$wI = static fn(string $p) => '<svg class="wI" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$ico = [
    'form'  => $wI('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>'),
    'plus'  => $wI('<path d="M5 12h14"/><path d="M12 5v14"/>'),
    'ext'   => $wI('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'),
    'copy'  => $wI('<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>'),
    'up'    => $wI('<path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/>'),
    'phone' => $wI('<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>'),
    'check' => $wI('<path d="M20 6 9 17l-5-5"/>'),
    'send'  => $wI('<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>'),
    'inbox' => $wI('<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'),
    'book'  => $wI('<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>'),
    'help'  => $wI('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'),
    'x'     => $wI('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
    'edit'  => $wI('<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>'),
    'users' => $wI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
];

include '../includes/client_header.php';
?>
<style>
/* ── WA Native Forms: line-free tiles, matches Drip Campaigns ── */
.wI{width:16px;height:16px;flex-shrink:0}
.wf-hero{background:#fff;border-radius:18px;padding:30px 32px;margin-bottom:22px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45);display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap}
.wf-eyebrow{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#128C7E;margin-bottom:8px}
.wf-title{font-size:22px;font-weight:800;color:var(--text-primary);margin:0 0 8px;letter-spacing:-.3px}
.wf-sub{font-size:13.5px;color:var(--text-secondary);line-height:1.6;max-width:560px;margin:0}
.wf-hero-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
@media (max-width:680px){.wf-hero{padding:22px 18px;flex-direction:column}.wf-hero-actions{width:100%}}

.wf-page .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:10px;font-weight:700}
.wf-page .btn-primary{background:#128C7E;color:#fff}.wf-page .btn-primary:hover{background:#0B6B5E;color:#fff}
.wf-page .btn-edit{background:#E6FCF2;color:#0B6B5E}.wf-page .btn-edit:hover{background:#CDF5E3;color:#0B6B5E}
.wf-page .btn-soft{background:#EEF3F1;color:#34453f}.wf-page .btn-soft:hover{background:#E2EAE7;color:#34453f}
.wf-page .btn-danger{background:#FEEDED;color:#c62828}.wf-page .btn-danger:hover{background:#FBD9D9;color:#c62828}
.wf-page .form-control{border:0;background:#F3F7F5;border-radius:10px}
.wf-page .form-control:focus{box-shadow:0 0 0 2px rgba(18,140,126,.35);outline:none}
.wf-page .alert{border:0;border-radius:12px}

.wf-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
@media (max-width:800px){.wf-stats{grid-template-columns:repeat(2,1fr)}}
.wf-stat{background:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 12px 30px -24px rgba(15,38,34,.45)}
.wf-stat b{display:block;font-size:24px;font-weight:800;color:var(--text-primary)}
.wf-stat span{font-size:12px;color:var(--text-muted)}

.wf-guide{background:#fff;border-radius:18px;margin-bottom:22px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45)}
.wf-guide>summary{list-style:none;cursor:pointer;padding:20px 24px;display:flex;align-items:center;gap:12px}
.wf-guide>summary::-webkit-details-marker{display:none}
.wf-guide-ico{width:36px;height:36px;border-radius:10px;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;flex:none}
.wf-guide-t{font-size:15px;font-weight:800;color:var(--text-primary)}
.wf-guide-s{font-size:12.5px;color:var(--text-muted)}
.wf-guide-tog{margin-left:auto;font-size:12px;font-weight:700;color:#128C7E;background:#F3FBF7;padding:6px 12px;border-radius:99px;white-space:nowrap}
.wf-guide[open] .wf-guide-tog .o{display:none}.wf-guide:not([open]) .wf-guide-tog .c{display:none}
.wf-guide-body{padding:0 24px 24px}
.wf-steps{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
.wf-step{background:#F3FBF7;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:8px}
.wf-step-top{display:flex;align-items:center;gap:10px}
.wf-num{width:26px;height:26px;border-radius:50%;background:#128C7E;color:#fff;font-size:12px;font-weight:800;display:grid;place-items:center;flex:none}
.wf-step h4{margin:0;font-size:13.5px;font-weight:800;color:var(--text-primary)}
.wf-step p{margin:0;font-size:12.5px;color:var(--text-secondary);line-height:1.55}
.wf-step .btn{align-self:flex-start;margin-top:auto;height:32px;padding:0 12px;font-size:12px}
.wf-kbd{background:#fff;border-radius:5px;padding:1px 6px;font-family:ui-monospace,Consolas,monospace;font-size:11px;color:#0B6B5E}
.wf-faq{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;margin-top:18px}
.wf-faq details{background:#F6F8F7;border-radius:12px;padding:12px 14px}
.wf-faq summary{cursor:pointer;font-size:13px;font-weight:700;color:var(--text-primary)}
.wf-faq p{margin:8px 0 0;font-size:12.5px;color:var(--text-secondary);line-height:1.55}
.wf-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.wf-links .btn{height:34px;padding:0 14px;font-size:12.5px}

.wf-warn{background:#FFF6E5;color:#8a5300;border-radius:14px;padding:14px 16px;font-size:13px;margin-bottom:18px}
.wf-warn a{color:#8a5300;font-weight:700}

.wf-sec{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 14px;flex-wrap:wrap}
.wf-sec h3{margin:0;font-size:16px;font-weight:800;color:var(--text-primary)}
.wf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(440px,1fr));gap:18px}
@media (max-width:560px){.wf-grid{grid-template-columns:1fr}}
.wf-card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 12px 30px -22px rgba(15,38,34,.45);display:flex;flex-direction:column;gap:4px}
.wf-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.wf-name{font-size:17px;font-weight:800;color:var(--text-primary);margin:0}
.wf-meta{font-size:12px;color:var(--text-muted);margin-top:4px}
.wf-chip{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;padding:5px 12px;border-radius:99px;white-space:nowrap}
.wf-chip::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.wf-chip.ok{background:#E3F7EE;color:#0B6B5E}.wf-chip.warn{background:#FFF3DC;color:#a15c00}
.wf-chip.bad{background:#FEEDED;color:#c62828}.wf-chip.muted{background:#EEF1F0;color:#6b7a76}
.wf-track{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 4px}
.wf-track span{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:5px 10px;border-radius:99px;background:#F3F5F4;color:#8a9893}
.wf-track span.done{background:#E3F7EE;color:#0B6B5E}
.wf-track .wI{width:12px;height:12px}
.wf-next{background:#F3FBF7;border-radius:12px;padding:11px 14px;font-size:12.5px;color:#0B6B5E;margin-top:10px}
.wf-next b{font-weight:800}
.wf-err{margin-top:10px;background:#FEEDED;color:#b91c1c;border-radius:12px;padding:11px 14px;font-size:12px;white-space:pre-wrap;line-height:1.5}
.wf-err b{display:block;margin-bottom:4px}
.wf-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.wf-actions form{margin:0}
.wf-actions .btn{height:36px;padding:0 14px;font-size:12.5px}
.wf-panel{margin-top:12px;background:#F6F8F7;border-radius:12px;padding:12px 14px}
.wf-panel summary{cursor:pointer;font-size:12.5px;font-weight:700;color:#128C7E}
.wf-panel textarea{font-family:ui-monospace,Consolas,monospace;font-size:11px;background:#fff !important}
.wf-panel .form-control{background:#fff}
.wf-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:600px){.wf-row{grid-template-columns:1fr}}
.wf-small{font-size:11.5px;color:var(--text-muted);margin:0 0 10px;line-height:1.5}

.wf-empty{grid-column:1/-1;text-align:center;padding:56px 32px;background:#F3FBF7;border-radius:18px}
.wf-empty-ico{width:68px;height:68px;border-radius:50%;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;margin:0 auto 16px}
.wf-empty-ico .wI{width:28px;height:28px}
.wf-empty h3{font-size:19px;font-weight:800;margin:0 0 8px;color:var(--text-primary)}
.wf-empty p{color:var(--text-secondary);max-width:440px;margin:0 auto 20px;line-height:1.6;font-size:13.5px}

.wf-ov{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:2000;padding:16px}
.wf-ov.open{display:flex}
.wf-modal{background:#fff;border-radius:18px;width:640px;max-width:100%;max-height:calc(100vh - 32px);overflow:auto;box-shadow:0 30px 60px -20px rgba(15,23,42,.45)}
.wf-mhead{padding:20px 24px 6px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.wf-mhead h3{margin:0;font-size:16px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:10px}
.wf-mclose{width:32px;height:32px;border-radius:9px;border:0;background:#EEF3F1;color:#34453f;display:grid;place-items:center;cursor:pointer}
.wf-mbody{padding:12px 24px 4px}
.wf-mfoot{padding:14px 24px 22px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
/* ── Visual form builder (New Form popup) ── */
.wf-modal-xl{width:1240px}
.wf-row-3{grid-template-columns:1.3fr 1fr 1fr}
@media (max-width:820px){.wf-row-3{grid-template-columns:1fr}}
.wf-tabs{display:flex;background:#F3F7F5;border-radius:10px;padding:4px;gap:4px}
.wf-tabs button{flex:1;border:0;background:transparent;border-radius:8px;padding:8px 10px;font:700 12.5px inherit;color:#586a63;cursor:pointer}
.wf-tabs button.on{background:#fff;color:#0B6B5E;box-shadow:0 4px 12px -6px rgba(18,140,126,.5)}
.wf-startbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;font-size:12.5px;color:var(--text-muted)}
.wf-starter{border:0;background:#F3FBF7;border-radius:99px;padding:7px 14px;cursor:pointer;font:700 12.5px inherit;color:#0B6B5E}
.wf-starter:hover,.wf-starter.on{background:#CDF5E3}
.wf-foot-note{margin-right:auto;font-size:12px;color:var(--text-muted);align-self:center}
.wf-mfoot .btn{height:40px;padding:0 16px;font-size:13px}
.wf-mfoot .btn[disabled]{opacity:.5;cursor:not-allowed}

.wfb{display:grid;grid-template-columns:210px minmax(0,1fr) 340px;gap:16px;align-items:start}
@media (max-width:1100px){.wfb{grid-template-columns:190px minmax(0,1fr)}.wfb-right{grid-column:1/-1}}
@media (max-width:700px){.wfb{grid-template-columns:1fr}}
.wfb-col{background:#F6F8F7;border-radius:14px;padding:14px}
.wfb-mid{max-height:62vh;overflow:auto}
.wfb-h{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#586a63;margin:0 0 8px}
.wfb-scr{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:10px;cursor:pointer;font-size:13px;color:var(--text-primary);margin-bottom:4px}
.wfb-scr:hover{background:#EEF3F1}
.wfb-scr.on{background:#fff;box-shadow:0 6px 16px -10px rgba(15,38,34,.4);font-weight:700}
.wfb-scr-n{width:22px;height:22px;border-radius:50%;background:#E3F7EE;color:#0B6B5E;font-size:11px;font-weight:800;display:grid;place-items:center;flex:none}
.wfb-scr-t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wfb-x{border:0;background:transparent;color:#8a9893;font-size:17px;line-height:1;cursor:pointer;padding:2px 6px;border-radius:6px}
.wfb-x:hover{background:#FEEDED;color:#c62828}
.wfb-add-scr,.wfb-link{border:0;background:transparent;color:#128C7E;font:700 12.5px inherit;cursor:pointer;padding:6px 2px}
.wfb-tip{font-size:11.5px;color:var(--text-muted);line-height:1.5;margin:10px 0 0}
.wfb-scr-set{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
@media (max-width:560px){.wfb-scr-set{grid-template-columns:1fr}}
.wfb-fld{position:relative;margin-bottom:10px}
.wfb-fld label{display:block;font-size:11.5px;font-weight:700;color:#586a63;margin-bottom:4px}
.wfb .form-control{background:#fff;font-size:13px;padding-right:56px}
.wfb select.form-control{padding-right:12px}
.wfb-cnt{position:absolute;right:10px;bottom:9px;font-size:10.5px;color:#8a9893;pointer-events:none}
.wfb-cnt.over{color:#c62828;font-weight:700}
.wfb-fld textarea+.wfb-cnt{bottom:10px}
.wfb-opt{position:relative;display:flex;align-items:center;gap:4px;margin-bottom:6px}
.wfb-opt .form-control{flex:1}
.wfb-opt .wfb-cnt{right:40px;bottom:auto;top:50%;transform:translateY(-50%)}
.wfb-item{background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:10px;box-shadow:0 6px 16px -12px rgba(15,38,34,.35)}
.wfb-item .form-control{background:#F3F7F5}
.wfb-item-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.wfb-item-h b{font-size:12px;color:#0B6B5E;background:#E3F7EE;padding:3px 10px;border-radius:99px}
.wfb-item-h button{border:0;background:#F3F7F5;color:#34453f;width:26px;height:26px;border-radius:7px;cursor:pointer;margin-left:4px;font-size:13px}
.wfb-item-h button.del:hover{background:#FEEDED;color:#c62828}
.wfb-req{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--text-secondary);cursor:pointer}
.wfb-empty{background:#fff;border-radius:12px;padding:18px;text-align:center;font-size:12.5px;color:var(--text-muted);margin-bottom:10px}
.wfb-addbox{background:#fff;border-radius:12px;padding:12px 14px}
.wfb-adds{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.wfb-adds:last-child{margin-bottom:0}
.wfb-adds button{border:0;background:#F3FBF7;color:#0B6B5E;border-radius:99px;padding:6px 12px;font:700 12px inherit;cursor:pointer}
.wfb-adds button:hover{background:#CDF5E3}
.wfb-adds button::before{content:'+ '}

.wfb-right{background:#ECE5DD;position:sticky;top:0}
.wfb-ph-cap{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#586a63;margin-bottom:8px;text-align:center}
.wfb-phone{position:relative;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 18px 40px -20px rgba(0,0,0,.45);display:flex;flex-direction:column;min-height:460px;max-height:58vh;font-family:Roboto,'Segoe UI',system-ui,sans-serif}
.wfb-ph-top{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;font-size:15px;color:#111}
.wfb-ph-top b{font-size:14.5px;font-weight:600;text-align:center;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 8px}
.wfb-ph-body{flex:1;overflow:auto;padding:4px 18px 14px;color:#111}
.wfb-ph-body h3{font-size:19px;font-weight:700;margin:10px 0 8px}
.wfb-ph-body h4{font-size:16px;font-weight:700;margin:12px 0 6px}
.wfb-ph-body p{font-size:14px;line-height:1.45;margin:6px 0}
.wfb-ph-body .cap{display:block;font-size:12px;color:#667;margin:4px 0}
.wfb-ph-body .muted{color:#99a}
.wfb-ph-body .in{margin:12px 0}
.wfb-ph-body .in label{display:block;font-size:12px;color:#556;margin-bottom:4px}
.wfb-ph-body .in input,.wfb-ph-body .in textarea,.wfb-ph-body .in select{width:100%;border:1.5px solid #c9ced3;border-radius:8px;padding:12px;font:14px inherit;background:#fff;box-sizing:border-box;resize:none}
.wfb-ph-body .in small{display:block;font-size:11.5px;color:#667;margin-top:4px}
.wfb-ph-body .grp{margin:12px 0}
.wfb-ph-body .gl{font-size:14px;color:#333;margin-bottom:6px}
.wfb-ph-body .ch{display:flex;justify-content:space-between;align-items:center;padding:10px 0;font-size:14px;cursor:pointer}
.wfb-ph-body .ch input{width:20px;height:20px;accent-color:#128C7E}
.wfb-ph-body .opt{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;margin:12px 0;cursor:pointer}
.wfb-ph-body .opt input{width:18px;height:18px;accent-color:#128C7E;flex:none;margin-top:1px}
.wfb-ph-foot{padding:10px 16px 12px;text-align:center}
.wfb-ph-foot button{width:100%;border:0;border-radius:99px;background:#128C7E;color:#fff;font:600 14px inherit;padding:12px;cursor:pointer}
.wfb-ph-foot small{display:block;font-size:11px;color:#667;margin-top:8px}
.wfb-ph-foot u{color:#128C7E;text-decoration:none}
.wfb-ph-done{position:absolute;inset:0;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;padding:24px}
.wfb-ph-done div{font-size:38px}
.wfb-ph-done b{font-size:16px}
.wfb-ph-done span{font-size:12.5px;color:#667}
.wfb-ph-done button{margin-top:10px;border:0;background:#F3FBF7;color:#0B6B5E;border-radius:99px;padding:8px 16px;font:700 12.5px inherit;cursor:pointer}
.wfb-right .wfb-link{display:block;margin:8px auto 0}
.wf-or{font-size:12px;color:var(--text-muted);margin:12px 0 8px}
.wf-lbl{display:block;font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:6px}
.wf-check{display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--text-secondary)}
.wf-bulk{background:#F3FBF7}
.wf-bulk>summary{color:#0B6B5E}
.wf-need{margin-top:12px;background:#fff;border-radius:12px;padding:14px}
.wf-need b{font-size:13px;color:var(--text-primary)}
.wf-need p{margin:6px 0 0;font-size:12.5px;color:var(--text-secondary);line-height:1.55}
.wf-need-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.wf-need-btns .btn{height:34px;padding:0 14px;font-size:12.5px}
.wf-when{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:10px;font-size:13px;color:var(--text-secondary)}
.wf-when label{display:flex;align-items:center;gap:6px;cursor:pointer}
</style>

<div class="wf-page">
<?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?php echo htmlspecialchars((string)$_GET['ok']); ?></div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['err']); ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <div class="wf-eyebrow"><?php echo $ico['form']; ?> Premium Growth</div>
        <h1 class="wf-title">WhatsApp Native Forms</h1>
        <p class="wf-sub">Forms that open inside WhatsApp. Customers tap a button, fill in a few fields and submit without leaving the chat. Every answer lands in your inbox and here.</p>
    </div>
    <div class="wf-hero-actions">
        <a class="btn btn-soft" href="<?php echo $e($metaFlowsUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $ico['ext']; ?> Meta Flow Builder</a>
        <button type="button" class="btn btn-primary" onclick="wfOpen()"><?php echo $ico['plus']; ?> New Form</button>
    </div>
</div>

<?php if (!$waCfg): ?>
    <div class="wf-warn">WhatsApp isn't fully connected yet. Add your <b>WABA ID</b> and <b>access token</b> in <a href="settings_whatsapp.php">WhatsApp settings</a> so forms can be uploaded, published and sent.</div>
<?php endif; ?>

<div class="wf-stats">
    <div class="wf-stat"><b><?php echo count($flows); ?></b><span>Forms</span></div>
    <div class="wf-stat"><b><?php echo $nPublished; ?></b><span>Published</span></div>
    <div class="wf-stat"><b><?php echo $nDraft; ?></b><span>Drafts</span></div>
    <div class="wf-stat"><b><?php echo $nResponses; ?></b><span>Responses</span></div>
</div>

<details class="wf-guide" id="wfGuide" <?php echo $flows ? '' : 'open'; ?>>
    <summary>
        <span class="wf-guide-ico"><?php echo $ico['book']; ?></span>
        <span><span class="wf-guide-t">Beginner guide: your first WhatsApp form</span><br><span class="wf-guide-s">7 simple steps, about 10 minutes. No coding needed.</span></span>
        <span class="wf-guide-tog"><span class="o">Show guide</span><span class="c">Hide guide</span></span>
    </summary>
    <div class="wf-guide-body">
        <div class="wf-steps">
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">1</span><h4>Design the form in Meta</h4></div>
                <p>Open Meta's Flow Builder, click <b>Create flow</b>, pick a ready template such as <i>Get leads</i> or <i>Book an appointment</i>, and change the questions to your own.</p>
                <p><b>Easier:</b> skip steps 1–2 and build the form visually in step 3.</p>
                <a class="btn btn-edit" href="<?php echo $e($metaFlowsUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $ico['ext']; ?> Open Flow Builder</a>
            </div>
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">2</span><h4>Copy the form code</h4></div>
                <p>The Flow Builder shows the form's code (JSON) on the left. Click inside it, press <span class="wf-kbd">Ctrl + A</span> then <span class="wf-kbd">Ctrl + C</span> to copy it all.</p>
                <p>You don't need to publish it in Meta. We do that for you.</p>
            </div>
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">3</span><h4>Paste it here and save</h4></div>
                <p>Click <b>New Form</b> and build it right here: add screens and questions and watch the phone preview, just like Meta's Playground. Or start from a ready-made form, or paste JSON. Then click <b>Save &amp; upload to test</b>.</p>
                <button type="button" class="btn btn-edit" onclick="wfOpen()"><?php echo $ico['plus']; ?> New Form</button>
            </div>
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">4</span><h4>Test it on your phone</h4></div>
                <p>From your own WhatsApp, send <b>"hi"</b> to your business number. Then open <b>Send a test</b> on the form below and enter your number. The form arrives on your phone.</p>
            </div>
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">5</span><h4>Publish</h4></div>
                <p>Happy with the test? Click <b>Publish</b>. A published form can't be edited. To change it later, create a new form.</p>
            </div>
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">6</span><h4>Send it to customers</h4></div>
                <p>Create a template with an <b>Open form</b> button (Templates → Create → Add button). Once Meta approves it, click <b>Send to group</b> on your form, pick a contact group and send.</p>
                <a class="btn btn-edit" href="template_create.php"><?php echo $ico['send']; ?> Create template</a>
            </div>
            <div class="wf-step">
                <div class="wf-step-top"><span class="wf-num">7</span><h4>Read the answers</h4></div>
                <p>Each submission shows up in the chat, in the contact's notes and under <b>Responses</b>, where you can download everything as Excel (CSV).</p>
                <a class="btn btn-edit" href="premium_flow_responses.php"><?php echo $ico['inbox']; ?> Responses</a>
            </div>
        </div>

        <div class="wf-faq">
            <details><summary>What does the customer see?</summary><p>A normal WhatsApp message with a button such as "Book a visit". Tapping it opens a full-screen form inside WhatsApp. After they submit, the chat shows "Response sent".</p></details>
            <details><summary>Why must I message my business number before testing?</summary><p>WhatsApp only allows a business to send non-template messages within 24 hours of the customer writing. Templates sent from Campaigns don't have this limit.</p></details>
            <details><summary>Meta shows a red error. What now?</summary><p>The error names the problem and the line, for example a missing field or a wrong type. Fix it in <b>Edit JSON</b> or in Meta's Flow Builder, then click <b>Re-upload to Meta</b>.</p></details>
            <details><summary>Does it cost extra?</summary><p>Filling in the form is free. Meta charges for the template message that carries the button, the same as any other template.</p></details>
            <details><summary>Can I change a form after publishing?</summary><p>No, Meta locks published forms. Create a new form with the changes, publish it, and point your template to it. Click <b>Retire</b> on the old one.</p></details>
            <details><summary>Where do the answers go?</summary><p>Into the chat in your inbox, the contact's notes and the <b>Responses</b> page for this form. Use <b>Export CSV</b> to open them in Excel or Google Sheets.</p></details>
        </div>

        <div class="wf-links">
            <a class="btn btn-soft" href="<?php echo $e($metaFlowsUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $ico['ext']; ?> Meta Flow Builder (WhatsApp Manager)</a>
            <a class="btn btn-soft" href="<?php echo $e($metaPlayUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $ico['ext']; ?> Flow Playground (try designs)</a>
            <a class="btn btn-soft" href="<?php echo $e($metaDocsUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $ico['help']; ?> Meta Flows help</a>
        </div>
    </div>
</details>

<div class="wf-sec">
    <h3>Your forms</h3>
    <a class="btn btn-edit" style="height:34px;padding:0 14px;font-size:12.5px" href="premium_flow_responses.php"><?php echo $ico['inbox']; ?> All responses (<?php echo $nResponses; ?>)</a>
</div>

<div class="wf-grid">
<?php if (!$flows): ?>
    <div class="wf-empty">
        <div class="wf-empty-ico"><?php echo $ico['form']; ?></div>
        <h3>No forms yet</h3>
        <p>Start with a ready-made lead, feedback or appointment form, or paste one you designed in Meta's Flow Builder.</p>
        <button type="button" class="btn btn-primary" onclick="wfOpen()"><?php echo $ico['plus']; ?> Create your first form</button>
    </div>
<?php endif; ?>
<?php foreach ($flows as $f):
    $fid = (int)$f['id'];
    [$sLabel, $sTone, $sHint] = $statusInfo($f);
    $ms = strtoupper((string)($f['meta_status'] ?? ''));
    $onMeta = !empty($f['meta_flow_id']);
    $isPub = $ms === 'PUBLISHED';
    $editable = !in_array($ms, ['PUBLISHED', 'DEPRECATED'], true);
    $nResp = $counts[$fid] ?? 0;
    $norm = waf_normalize_json((string)$f['flow_json']);
    $pretty = $norm !== '' ? json_encode(json_decode($norm), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$f['flow_json'];
    if (!$onMeta) $next = 'Click <b>Upload to Meta</b> so Meta can check the form.';
    elseif (!empty($f['last_error'])) $next = 'Fix the error below in <b>Edit JSON</b>, then <b>Re-upload to Meta</b>.';
    elseif ($ms === 'DRAFT') $next = 'Open <b>Send a test</b> to try it on your phone, then click <b>Publish</b>.';
    elseif ($isPub && empty($flowTpls[$fid])) $next = 'Create a template with this form\'s button, then use <b>Send to group</b>. <a href="template_create.php?flow=' . $e($f['meta_flow_id']) . '">Create template →</a>';
    elseif ($isPub && !$nResp) $next = 'Ready. Click <b>Send to group</b> to send it to your contacts.';
    elseif ($isPub) $next = 'Live and collecting answers. Open <b>Responses</b> to see them.';
    else $next = $sHint;
?>
    <div class="wf-card" id="flow-<?php echo $fid; ?>" data-name="<?php echo $e($f['flow_name']); ?>" data-cat="<?php echo $e($f['category'] ?? 'OTHER'); ?>">
        <div class="wf-head">
            <div>
                <h4 class="wf-name"><?php echo $e($f['flow_name']); ?></h4>
                <div class="wf-meta"><?php echo $e(WAF_CATEGORIES[$f['category'] ?? 'OTHER'] ?? 'Other'); ?> · Created <?php echo date('d M Y', strtotime($f['created_at'])); ?><?php if ($onMeta): ?> · Meta ID <?php echo $e($f['meta_flow_id']); ?><?php endif; ?></div>
            </div>
            <span class="wf-chip <?php echo $sTone; ?>"><?php echo $sLabel; ?></span>
        </div>

        <div class="wf-track">
            <span class="done"><?php echo $ico['check']; ?> Saved</span>
            <span class="<?php echo $onMeta && empty($f['last_error']) ? 'done' : ''; ?>"><?php echo $onMeta && empty($f['last_error']) ? $ico['check'] : ''; ?> On Meta</span>
            <span class="<?php echo $isPub || $ms === 'DEPRECATED' ? 'done' : ''; ?>"><?php echo $isPub || $ms === 'DEPRECATED' ? $ico['check'] : ''; ?> Published</span>
            <span class="<?php echo $nResp ? 'done' : ''; ?>"><?php echo $nResp ? $ico['check'] : ''; ?> <?php echo $nResp; ?> responses</span>
        </div>

        <div class="wf-next"><b>Next:</b> <?php echo $next; ?></div>
        <?php if (!empty($f['last_error'])): ?><div class="wf-err"><b>Meta found a problem</b><?php echo $e($f['last_error']); ?></div><?php endif; ?>

        <div class="wf-actions">
            <?php if ($editable): ?>
                <button type="button" class="btn btn-edit" onclick="wfEdit(<?php echo $fid; ?>)"><?php echo $ico['edit']; ?> Open in builder</button>
            <?php endif; ?>
            <?php if ($editable): ?>
                <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>">
                    <button class="btn <?php echo $onMeta ? 'btn-soft' : 'btn-primary'; ?>" name="action" value="push"><?php echo $ico['up']; ?> <?php echo $onMeta ? 'Re-upload to Meta' : 'Upload to Meta'; ?></button></form>
            <?php endif; ?>
            <?php if ($onMeta && $ms === 'DRAFT'): ?>
                <form method="POST" onsubmit="return confirm('Publish this form? Published forms cannot be edited.')"><?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>">
                    <button class="btn btn-primary" name="action" value="publish"><?php echo $ico['check']; ?> Publish</button></form>
            <?php endif; ?>
            <?php if ($isPub): ?>
                <button type="button" class="btn btn-primary" onclick="wfBulk(<?php echo $fid; ?>)"><?php echo $ico['users']; ?> Send to group</button>
            <?php endif; ?>
            <a class="btn btn-edit" href="premium_flow_responses.php?flow_id=<?php echo $fid; ?>"><?php echo $ico['inbox']; ?> Responses (<?php echo $nResp; ?>)</a>
            <?php if ($onMeta): ?>
                <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>">
                    <button class="btn btn-soft" name="action" value="sync">Refresh status</button></form>
            <?php endif; ?>
            <?php if ($isPub): ?>
                <form method="POST" onsubmit="return confirm('Retire this form? Templates using it will stop opening it.')"><?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>">
                    <button class="btn btn-danger" name="action" value="deprecate">Retire</button></form>
            <?php else: ?>
                <form method="POST" onsubmit="return confirm('Delete this form?')"><?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>">
                    <button class="btn btn-danger" name="action" value="delete">Delete</button></form>
            <?php endif; ?>
        </div>

        <?php if ($isPub):
            $tpls = $flowTpls[$fid] ?? []; ?>
        <details class="wf-panel wf-bulk" id="bulk-<?php echo $fid; ?>">
            <summary>Send to a group (bulk)</summary>
            <?php if (!$canCampaign): ?>
                <p class="wf-small" style="margin-top:10px">Bulk sending uses Campaigns, which isn't on your plan. <a href="subscription.php">See plans</a></p>
            <?php elseif (!$tpls): ?>
                <div class="wf-need">
                    <b>One more step: a template for this form</b>
                    <p>WhatsApp only lets you message many people with an <b>approved template</b>. Create one with an <b>Open form</b> button for this form. Meta usually approves it within minutes to a few hours.</p>
                    <div class="wf-need-btns">
                        <a class="btn btn-primary" href="template_create.php?flow=<?php echo $e($f['meta_flow_id']); ?>"><?php echo $ico['plus']; ?> Create template for this form</a>
                        <a class="btn btn-soft" href="templates.php?sync=1">Made it in Meta? Sync templates</a>
                    </div>
                    <p class="wf-small" style="margin:8px 0 0">Only <b>approved</b> templates appear here. Check the status under Templates.</p>
                </div>
            <?php else: ?>
            <form method="POST" class="wf-bulk-form" style="margin-top:12px" onsubmit="return wfBulkConfirm(this)">
                <?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>"><input type="hidden" name="action" value="send_group">
                <div class="wf-row">
                    <div class="form-group"><label class="wf-lbl">Send to</label>
                        <select name="group_id" class="form-control wf-grp" required>
                            <?php foreach ($audience['groups'] as $g): ?>
                                <option value="<?php echo (int)$g['id']; ?>" data-n="<?php echo (int)$g['cnt']; ?>"><?php echo $e($g['name']); ?> (<?php echo (int)$g['cnt']; ?>)</option>
                            <?php endforeach; ?>
                            <option value="0" data-n="<?php echo (int)$audience['all']; ?>">All contacts (<?php echo (int)$audience['all']; ?>)</option>
                        </select>
                        <a class="wf-small" style="display:inline-block;margin-top:6px" href="contact_groups.php">+ Manage groups</a>
                    </div>
                    <div class="form-group"><label class="wf-lbl">Template (with this form's button)</label>
                        <select name="template_id" class="form-control" required>
                            <?php foreach ($tpls as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"><?php echo $e($t['name']); ?> · <?php echo $e($t['language']); ?><?php echo $t['flow_button_text'] !== '' ? ' · “' . $e($t['flow_button_text']) . '”' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="wf-lbl">Campaign name (optional)</label><input class="form-control" name="campaign_name" maxlength="150" placeholder="Form: <?php echo $e($f['flow_name']); ?> · <?php echo date('d M'); ?>"></div>
                <div class="wf-when">
                    <label><input type="radio" name="when" value="now" checked onchange="wfWhen(this)"> Send now</label>
                    <label><input type="radio" name="when" value="schedule" onchange="wfWhen(this)"> Schedule</label>
                    <input type="datetime-local" name="scheduled_at" class="form-control wf-at" style="display:none;max-width:240px">
                </div>
                <p class="wf-small">Contacts who opted out (replied STOP) are skipped. Messages go out a few seconds apart, and {{1}} becomes the contact's first name. Meta charges per template message.</p>
                <button class="btn btn-primary wf-bulk-btn" style="height:38px;padding:0 16px;font-size:13px"><?php echo $ico['send']; ?> <span>Send</span></button>
            </form>
            <?php endif; ?>
        </details>
        <?php endif; ?>

        <?php if ($onMeta && in_array($ms, ['DRAFT', 'PUBLISHED'], true)): ?>
        <details class="wf-panel" <?php echo $openId === $fid ? 'open' : ''; ?>>
            <summary>Send a test to my phone</summary>
            <form method="POST" style="margin-top:12px">
                <?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>"><input type="hidden" name="action" value="send_test">
                <p class="wf-small">First send "hi" from this number to your business WhatsApp. Tests only work within 24 hours of that message.<?php echo $ms === 'DRAFT' ? ' Drafts open in preview mode.' : ''; ?></p>
                <div class="wf-row">
                    <div class="form-group"><label class="wf-lbl">WhatsApp number with country code</label><input class="form-control" name="phone" inputmode="numeric" placeholder="919876543210" required></div>
                    <div class="form-group"><label class="wf-lbl">Button text</label><input class="form-control" name="cta" maxlength="20" value="Open form" required></div>
                </div>
                <div class="form-group"><label class="wf-lbl">Message</label><input class="form-control" name="body" maxlength="1024" value="Please fill in this quick form." required></div>
                <button class="btn btn-primary" style="height:36px;padding:0 14px;font-size:12.5px"><?php echo $ico['phone']; ?> Send test</button>
            </form>
        </details>
        <?php endif; ?>

        <details class="wf-panel">
            <summary><?php echo $editable ? 'Edit JSON' : 'View JSON'; ?></summary>
            <form method="POST" style="margin-top:12px">
                <?php echo csrf_field(); ?><input type="hidden" name="flow_id" value="<?php echo $fid; ?>"><input type="hidden" name="action" value="save_json">
                <textarea name="flow_json" class="form-control" rows="14" spellcheck="false" <?php echo $editable ? '' : 'readonly'; ?>><?php echo htmlspecialchars((string)$pretty, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <?php if ($editable): ?><button class="btn btn-primary" style="margin-top:10px;height:36px;padding:0 14px;font-size:12.5px"><?php echo $onMeta ? 'Save and update on Meta' : 'Save'; ?></button><?php endif; ?>
            </form>
        </details>
    </div>
<?php endforeach; ?>
</div>
</div>

<!-- New form modal -->
<div class="wf-ov" id="wfModal" role="dialog" aria-modal="true" aria-labelledby="wfModalT" onclick="if(event.target===this)wfClose()">
    <div class="wf-modal wf-modal-xl">
        <form method="POST" id="wfForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="wfAction" value="create">
            <input type="hidden" name="flow_id" id="wfFlowId" value="0">
            <div class="wf-mhead">
                <h3 id="wfModalT"><span class="wf-guide-ico" style="width:32px;height:32px"><?php echo $ico['form']; ?></span> <span id="wfModalTxt">New WhatsApp form</span></h3>
                <button type="button" class="wf-mclose" onclick="wfClose()" aria-label="Close"><?php echo $ico['x']; ?></button>
            </div>
            <div class="wf-mbody">
                <div class="wf-row wf-row-3">
                    <div class="form-group"><label class="wf-lbl">Form name (only you see this)</label><input type="text" id="wfName" name="flow_name" class="form-control" maxlength="100" placeholder="e.g. 7digit feedback" required></div>
                    <div class="form-group"><label class="wf-lbl">Category</label>
                        <select name="category" id="wfCat" class="form-control">
                            <?php foreach (WAF_CATEGORIES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label class="wf-lbl">How do you want to build it?</label>
                        <div class="wf-tabs" role="tablist">
                            <button type="button" class="on" data-tab="build" role="tab">Build visually</button>
                            <button type="button" data-tab="paste" role="tab">Paste JSON</button>
                        </div></div>
                </div>

                <div id="wfPaneBuild">
                    <div class="wf-startbar" id="wfStartBar">
                        <span>Start from:</span>
                        <button type="button" class="wf-starter" data-k="blank">Blank</button>
                        <button type="button" class="wf-starter" data-k="lead">Lead enquiry</button>
                        <button type="button" class="wf-starter" data-k="feedback">Feedback</button>
                        <button type="button" class="wf-starter" data-k="appointment">Appointment</button>
                    </div>
                    <div class="wfb" id="wfBuilder"></div>
                </div>

                <div id="wfPanePaste" hidden>
                    <p class="wf-small">Paste the JSON from <a href="<?php echo $e($metaPlayUrl); ?>" target="_blank" rel="noopener noreferrer">Meta's Flow Playground</a> (click <b>Copy Flow JSON</b>) or from the <a href="<?php echo $e($metaFlowsUrl); ?>" target="_blank" rel="noopener noreferrer">Flow Builder</a>. Then click <b>Open in visual builder</b> to edit it, or save it as it is.</p>
                    <textarea name="flow_json" id="wfJson" class="form-control" rows="16" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;font-size:11px" placeholder="{ &quot;version&quot;: &quot;6.0&quot;, &quot;screens&quot;: [ ... ] }"></textarea>
                    <button type="button" class="btn btn-edit" style="margin-top:10px;height:34px;padding:0 14px;font-size:12.5px" onclick="wfPasteToBuilder()">Open in visual builder →</button>
                </div>
            </div>
            <div class="wf-mfoot">
                <span class="wf-foot-note"><?php echo $waCfg ? 'Tip: upload and test on your phone before publishing. Published forms can\'t be edited.' : 'Connect WhatsApp in settings to upload and publish.'; ?></span>
                <button type="button" class="btn btn-soft" onclick="wfClose()">Cancel</button>
                <button type="submit" name="submit_mode" value="draft" class="btn btn-soft">Save draft</button>
                <button type="submit" name="submit_mode" value="upload" class="btn btn-edit" <?php echo $waCfg ? '' : 'disabled'; ?>><?php echo $ico['up']; ?> Save &amp; upload to test</button>
                <button type="submit" name="submit_mode" value="publish" class="btn btn-primary" <?php echo $waCfg ? '' : 'disabled'; ?>><?php echo $ico['check']; ?> Save &amp; publish</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/wa-form-builder.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/wa-form-builder.js'); ?>"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
const WF_STARTERS = <?php echo json_encode($starters, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
let wfTab = 'build';
WFBuilder.mount(document.getElementById('wfBuilder'));

function wfSetTab(t) {
    wfTab = t;
    document.querySelectorAll('.wf-tabs button').forEach(b => b.classList.toggle('on', b.dataset.tab === t));
    document.getElementById('wfPaneBuild').hidden = t !== 'build';
    document.getElementById('wfPanePaste').hidden = t !== 'paste';
    if (t === 'paste' && !document.getElementById('wfJson').value.trim()) document.getElementById('wfJson').value = WFBuilder.json();
}
document.querySelectorAll('.wf-tabs button').forEach(b => b.addEventListener('click', () => wfSetTab(b.dataset.tab)));

function wfPasteToBuilder() {
    const raw = document.getElementById('wfJson').value.trim();
    if (!raw) return alert('Paste the Flow JSON first.');
    try { JSON.parse(raw); } catch (e) { return alert('That is not valid JSON: ' + e.message); }
    if (!WFBuilder.load(raw)) return alert('This form uses advanced parts (dynamic data, images, routing…) that the visual builder can\'t edit. You can still save it as it is from this tab.');
    wfSetTab('build');
}

/** Open the builder. edit = {id, name, cat, json} for an existing draft, or nothing for a new form. */
function wfOpen(edit) {
    const f = document.getElementById('wfForm');
    f.reset();
    document.getElementById('wfJson').value = '';
    document.querySelectorAll('.wf-starter').forEach(x => x.classList.remove('on'));
    if (edit && edit.id) {
        if (!WFBuilder.load(edit.json)) {
            alert('This form uses advanced parts the visual builder can\'t edit. Use "Edit JSON" on the form instead.');
            return;
        }
        document.getElementById('wfAction').value = 'builder_save';
        document.getElementById('wfFlowId').value = edit.id;
        document.getElementById('wfName').value = edit.name;
        document.getElementById('wfCat').value = edit.cat;
        document.getElementById('wfModalTxt').textContent = 'Edit form: ' + edit.name;
        document.getElementById('wfStartBar').hidden = true;
    } else {
        WFBuilder.reset();
        document.getElementById('wfAction').value = 'create';
        document.getElementById('wfFlowId').value = '0';
        document.getElementById('wfModalTxt').textContent = 'New WhatsApp form';
        document.getElementById('wfStartBar').hidden = false;
    }
    wfSetTab('build');
    document.getElementById('wfModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('wfName').focus(), 50);
}
function wfClose() { document.getElementById('wfModal').classList.remove('open'); document.body.style.overflow = ''; }
function wfEdit(id) {
    const card = document.getElementById('flow-' + id);
    wfOpen({ id: id, name: card.dataset.name, cat: card.dataset.cat, json: card.querySelector('textarea[name="flow_json"]').value });
}
document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.getElementById('wfModal').classList.contains('open')) wfClose(); });

document.querySelectorAll('.wf-starter').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.wf-starter').forEach(x => x.classList.toggle('on', x === b));
    if (b.dataset.k === 'blank') { WFBuilder.reset(); return; }
    const s = WF_STARTERS[b.dataset.k]; if (!s) return;
    WFBuilder.load(s.json);
    document.getElementById('wfCat').value = s.category;
    const n = document.getElementById('wfName'); if (!n.value.trim()) n.value = s.name;
}));

document.getElementById('wfForm').addEventListener('submit', e => {
    const mode = e.submitter ? e.submitter.value : 'upload';
    const json = document.getElementById('wfJson');
    if (wfTab === 'build') {
        const err = WFBuilder.validate();
        if (err) { e.preventDefault(); alert(err); return; }
        json.value = WFBuilder.json();
    } else if (!json.value.trim()) {
        e.preventDefault(); alert('Paste the Flow JSON first.'); return;
    }
    if (mode === 'publish' && !confirm('Publish now? Customers can open it right away, and published forms can\'t be edited.\n\nTip: choose "Save & upload to test" first if you haven\'t tried it on your phone.')) {
        e.preventDefault(); return;
    }
    e.submitter && setTimeout(() => { e.submitter.disabled = true; e.submitter.textContent = 'Saving…'; }, 0);
});
// Bulk send: open the panel, live recipient count on the button, schedule toggle, confirm.
function wfBulk(id) {
    const d = document.getElementById('bulk-' + id); if (!d) return;
    d.open = true; d.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function wfCount(form) {
    const o = form.querySelector('.wf-grp').selectedOptions[0];
    return o ? parseInt(o.dataset.n || '0', 10) : 0;
}
function wfLabel(form) {
    const n = wfCount(form), sched = form.querySelector('input[name="when"]:checked').value === 'schedule';
    form.querySelector('.wf-bulk-btn span').textContent = (sched ? 'Schedule for ' : 'Send to ') + n + (n === 1 ? ' contact' : ' contacts');
}
function wfWhen(r) {
    const f = r.form, at = f.querySelector('.wf-at'), on = r.value === 'schedule';
    at.style.display = on ? '' : 'none'; at.required = on; wfLabel(f);
}
function wfBulkConfirm(form) {
    const n = wfCount(form);
    if (n === 0) { alert('This group has no contacts with a phone number.'); return false; }
    return confirm('Send this form to ' + n + ' contact' + (n === 1 ? '' : 's') + '? Contacts who opted out are skipped.');
}
document.querySelectorAll('.wf-bulk-form').forEach(f => { f.querySelector('.wf-grp').addEventListener('change', () => wfLabel(f)); wfLabel(f); });
// Remember whether the guide was left open or closed.
(function () {
    const g = document.getElementById('wfGuide');
    try { const v = localStorage.getItem('wf_guide'); if (v !== null && <?php echo $flows ? 'true' : 'false'; ?>) g.open = v === '1'; } catch (e) {}
    g.addEventListener('toggle', () => { try { localStorage.setItem('wf_guide', g.open ? '1' : '0'); } catch (e) {} });
})();
</script>

<?php include '../includes/client_footer.php'; ?>

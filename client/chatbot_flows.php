<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/flow/store.php';
flow_ensure_schema($conn);
require_once __DIR__ . '/../includes/chat_followup.php';
// Safe to rerun: it only converts legacy flows that still have no graph rows.
flow_migrate_legacy($conn);
$pageTitle = 'Flow Builder';
$currentPage = 'chatbot_flows';
$companyId = (int)($_SESSION['company_id'] ?? 0);

// Delete a flow (scoped to this company)
if (isset($_POST['delete_id'])) {
    csrf_verify();
    $did = intval($_POST['delete_id']);
    foreach (['flow_jobs', 'flow_logs', 'flow_sessions', 'flow_edges', 'flow_nodes'] as $table) {
        $clean = $conn->prepare("DELETE FROM `$table` WHERE flow_id=? AND company_id=?");
        if ($clean) { $clean->bind_param('ii', $did, $companyId); $clean->execute(); $clean->close(); }
    }
    $stmt_d = $conn->prepare("DELETE FROM chatbot_flows WHERE id=? AND company_id=?");
    $stmt_d->bind_param("ii", $did, $companyId);
    $stmt_d->execute();
    header("Location: chatbot_flows.php?msg=deleted");
    exit();
}

// Account-level auto follow-up (customer's last message unanswered for N minutes)
if (isset($_POST['save_followup'])) {
    csrf_verify();
    chat_followup_save($conn, $companyId, !empty($_POST['fu_enabled']), (int)($_POST['fu_minutes'] ?? 60), (string)($_POST['fu_message'] ?? ''));
    header("Location: chatbot_flows.php?msg=followup_saved");
    exit();
}

// Switch a flow on or off
if (isset($_POST['toggle_id'])) {
    csrf_verify();
    $tid = intval($_POST['toggle_id']);
    $row = flow_get($conn, $companyId, $tid);
    $result = $row ? flow_set_status($conn, $companyId, $tid, ($row['status'] ?? 'draft') === 'published' ? 'draft' : 'published') : ['ok' => false];
    header("Location: chatbot_flows.php?msg=" . (!empty($result['ok']) ? 'updated' : 'invalid'));
    exit();
}

// Create a flow, then open the builder
$formError = '';
if (isset($_POST['create_flow'])) {
    csrf_verify();
    $name         = trim((string)($_POST['name'] ?? ''));
    $trigger_type = in_array($_POST['trigger_type'] ?? '', ['keyword', 'any_message', 'new_contact'], true) ? $_POST['trigger_type'] : 'keyword';
    $trigger_kw   = $trigger_type === 'keyword' ? trim((string)($_POST['trigger_keyword'] ?? '')) : null;
    if ($name === '') {
        $formError = 'Give your flow a name.';
    } elseif ($trigger_type === 'keyword' && $trigger_kw === '') {
        $formError = 'Enter the keyword that starts this flow.';
    } else {
        $name = mb_substr($name, 0, 120);
        $stmt_c = $conn->prepare("INSERT INTO chatbot_flows (company_id, name, trigger_type, trigger_keyword) VALUES (?,?,?,?)");
        $stmt_c->bind_param("isss", $companyId, $name, $trigger_type, $trigger_kw);
        $stmt_c->execute();
        header("Location: flow_builder.php?flow_id=" . (int)$stmt_c->insert_id);
        exit();
    }
}

// Flows with node count and live conversations
$flows = [];
$stmt_f = $conn->prepare("SELECT f.*,
    (SELECT COUNT(*) FROM flow_nodes n WHERE n.flow_id = f.id AND n.company_id = f.company_id) AS node_count,
    (SELECT COUNT(*) FROM flow_sessions s WHERE s.flow_id = f.id AND s.company_id = f.company_id AND s.active_key = 1) AS active_sessions
    FROM chatbot_flows f WHERE f.company_id = ? ORDER BY f.id DESC");
if ($stmt_f) { $stmt_f->bind_param("i", $companyId); $stmt_f->execute(); $fr = $stmt_f->get_result(); while ($fr && ($x = $fr->fetch_assoc())) { $flows[] = $x; } }

$nFlows = count($flows);
$nLive = count(array_filter($flows, static fn($f) => ($f['status'] ?? 'draft') === 'published'));
$nNodes = array_sum(array_map(static fn($f) => (int)$f['node_count'], $flows));
$nSessions = array_sum(array_map(static fn($f) => (int)$f['active_sessions'], $flows));
$trig = ['keyword' => 0, 'new_contact' => 0, 'any_message' => 0];
foreach ($flows as $f) { if (isset($trig[$f['trigger_type']])) { $trig[$f['trigger_type']]++; } }
$top = $flows; usort($top, static fn($a, $b) => (int)$b['node_count'] <=> (int)$a['node_count']); $top = array_slice($top, 0, 8);
$tLabels = array_map(static fn($f) => mb_strimwidth((string)$f['name'], 0, 18, '…'), $top);
$tData = array_map(static fn($f) => (int)$f['node_count'], $top);

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'flow'   => '<rect width="8" height="8" x="3" y="3" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect width="8" height="8" x="13" y="13" rx="2"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'bolt'   => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
    'key'    => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    'user'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
    'list'   => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
    'grid'   => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
];
$trigInfo = ['keyword' => ['Keyword', 'key', 'is-info'], 'new_contact' => ['New contact', 'user', 'is-ok'], 'any_message' => ['Any message', 'chat', 'is-warn']];
$fu = chat_followup_get($conn, $companyId);
$msgMap = ['followup_saved' => 'Auto follow-up saved.', 'deleted' => 'Flow deleted.', 'updated' => 'Flow status updated.', 'invalid' => 'This flow has validation problems. Open it, fix the highlighted nodes, then publish again.'];
$flash = $msgMap[$_GET['msg'] ?? ''] ?? null;
?>

<div class="db cf">

  <div class="db-head">
    <div>
      <h1>Flow Builder</h1>
      <p>Build automated WhatsApp conversations that start from a keyword, a new contact or any message.</p>
    </div>
    <div class="db-head__actions">
      <button type="button" class="db-btn db-btn--primary" id="cfOpen"><?php echo $__ic($I['plus'], 16); ?> New flow</button>
    </div>
  </div>

  <?php if ($flash): ?><div class="cf-toast is-ok" role="status"><?php echo $__ic($I['check'], 18); ?> <span><?php echo $esc($flash); ?></span></div><?php endif; ?>
  <?php if ($formError): ?><div class="cf-toast is-err" role="alert"><?php echo $__ic($I['alert'], 18); ?> <span><?php echo $esc($formError); ?></span></div><?php endif; ?>

  <div class="db-row db-row--top">
    <section class="db-card db-report">
      <div class="db-chart">
        <div class="db-chart__top">
          <div>
            <h2>Flow size</h2>
            <div class="db-legend"><span><i style="background:#128C7E"></i>Steps (nodes) per flow</span><span class="db-mut">Top <?php echo count($top); ?></span></div>
          </div>
        </div>
        <div class="db-canvas"><canvas id="cfChart" aria-label="Nodes per flow" role="img"></canvas></div>
      </div>
      <div class="db-kpis">
        <div class="db-kpi"><div class="db-kpi__ic is-green"><?php echo $__ic($I['flow'], 19); ?></div><div class="db-kpi__l">Flows</div><div class="db-kpi__v"><?php echo number_format($nFlows); ?></div><div class="db-kpi__d"><?php echo number_format($nLive); ?> live</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-blue"><?php echo $__ic($I['bolt'], 19); ?></div><div class="db-kpi__l">Live now</div><div class="db-kpi__v"><?php echo number_format($nLive); ?></div><div class="db-kpi__d"><?php echo number_format($nFlows - $nLive); ?> switched off</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-purple"><?php echo $__ic($I['chat'], 19); ?></div><div class="db-kpi__l">Active conversations</div><div class="db-kpi__v"><?php echo number_format($nSessions); ?></div><div class="db-kpi__d">Customers in a flow</div></div>
        <div class="db-kpi"><div class="db-kpi__ic is-amber"><?php echo $__ic($I['layers'], 19); ?></div><div class="db-kpi__l">Total steps</div><div class="db-kpi__v"><?php echo number_format($nNodes); ?></div><div class="db-kpi__d">Across all flows</div></div>
      </div>
    </section>

    <section class="db-card db-donut">
      <div class="db-donut__top"><h2>Triggers</h2><span class="db-pill is-off"><?php echo number_format($nFlows); ?> flows</span></div>
      <div class="db-donut__chart">
        <canvas id="cfDonut" width="200" height="200" aria-label="Flows by trigger" role="img"></canvas>
        <div class="db-donut__center"><div><b><?php echo number_format($nFlows); ?></b><span>flows</span></div></div>
      </div>
      <div class="db-dleg">
        <span><i style="background:#3B82F6"></i>Keyword: <?php echo $trig['keyword']; ?></span>
        <span><i style="background:#25D366"></i>New contact: <?php echo $trig['new_contact']; ?></span>
        <span><i style="background:#F59E0B"></i>Any message: <?php echo $trig['any_message']; ?></span>
      </div>
    </section>
  </div>

  <section class="db-card cf-fu">
    <form method="POST" class="cf-fu__form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="save_followup" value="1">
      <div class="cf-fu__head">
        <span class="cf-fu__ic"><?php echo $__ic($I['bolt'], 20); ?></span>
        <div>
          <h2>Auto follow-up for unanswered chats</h2>
          <p>If a customer's last message gets no reply from your team, the bot or a flow, send one polite message. It is sent once per message, never during a flow conversation, and only within WhatsApp's 24-hour window.</p>
        </div>
        <label class="cf-sw2"><input type="checkbox" name="fu_enabled" value="1" <?php echo $fu['enabled'] ? 'checked' : ''; ?>><span></span></label>
      </div>
      <div class="cf-fu__grid">
        <div class="cf-fu__f">
          <label for="fu_minutes">Send it after</label>
          <select name="fu_minutes" id="fu_minutes">
            <?php foreach ([15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 120 => '2 hours', 240 => '4 hours', 480 => '8 hours', 720 => '12 hours', 1380 => '23 hours'] as $v => $l): ?>
              <option value="<?php echo $v; ?>" <?php echo (int)$fu['after_minutes'] === $v ? 'selected' : ''; ?>><?php echo $l; ?> without a reply</option>
            <?php endforeach; ?>
            <?php if (!in_array((int)$fu['after_minutes'], [15, 30, 60, 120, 240, 480, 720, 1380], true)): ?><option value="<?php echo (int)$fu['after_minutes']; ?>" selected><?php echo (int)$fu['after_minutes']; ?> minutes without a reply</option><?php endif; ?>
          </select>
        </div>
        <div class="cf-fu__f cf-fu__f--wide">
          <label for="fu_message">Message <span class="db-mut">use {{contact.first_name}} for the name</span></label>
          <textarea name="fu_message" id="fu_message" rows="2" maxlength="1000"><?php echo $esc($fu['message']); ?></textarea>
        </div>
        <div class="cf-fu__act"><button type="submit" class="db-btn db-btn--primary">Save</button></div>
      </div>
      <p class="cf-fu__note"><?php echo $fu['enabled'] ? 'On: customers get this after ' . ((int)$fu['after_minutes'] >= 60 ? rtrim(rtrim(number_format($fu['after_minutes'] / 60, 1), '0'), '.') . ' h' : (int)$fu['after_minutes'] . ' min') . ' of silence from your side.' : 'Off: nothing is sent automatically.'; ?> Runs every minute with your other background jobs.</p>
    </form>
  </section>

  <section class="db-card db-tablecard">
    <div class="db-card__head">
      <div><h2>Your flows</h2><p><span id="cfShown"><?php echo $nFlows; ?></span> shown</p></div>
      <label class="cf-search"><?php echo $__ic($I['search'], 16); ?><input type="search" id="cfSearch" placeholder="Search flows" aria-label="Search flows"></label>
    </div>
    <?php if ($flows): ?>
    <div class="cf-list" id="cfList">
      <?php foreach ($flows as $f):
          $ti = $trigInfo[$f['trigger_type']] ?? $trigInfo['keyword']; $live = ($f['status'] ?? 'draft') === 'published'; ?>
        <article class="cf-item" data-q="<?php echo $esc(strtolower($f['name'] . ' ' . ($f['trigger_keyword'] ?? ''))); ?>">
          <span class="cf-ic"><?php echo $__ic($I[$ti[1]], 20); ?></span>
          <div class="cf-main">
            <b><?php echo $esc($f['name']); ?></b>
            <div class="cf-sub">
              <span class="db-pill <?php echo $ti[2]; ?>"><?php echo $esc($ti[0]); ?></span>
              <?php if ($f['trigger_type'] === 'keyword'): ?><code><?php echo $esc($f['trigger_keyword'] ?? ''); ?></code><?php endif; ?>
              <span class="db-mut">Created <?php echo $esc(date('d M Y', strtotime((string)$f['created_at']))); ?></span>
            </div>
          </div>
          <div class="cf-nums"><div><b><?php echo (int)$f['node_count']; ?></b><span>Steps</span></div><div><b><?php echo (int)$f['active_sessions']; ?></b><span>Active</span></div></div>
          <form method="POST" class="cf-sw">
            <?php echo csrf_field(); ?><input type="hidden" name="toggle_id" value="<?php echo (int)$f['id']; ?>">
            <button type="submit" class="cf-tg<?php echo $live ? ' is-on' : ''; ?>" aria-label="<?php echo $live ? 'Switch off' : 'Switch on'; ?> <?php echo $esc($f['name']); ?>" title="<?php echo $live ? 'Live. Click to switch off' : 'Off. Click to go live'; ?>"><i></i></button>
            <span class="cf-live<?php echo $live ? ' is-on' : ''; ?>"><?php echo $live ? 'Live' : 'Off'; ?></span>
          </form>
          <div class="cf-acts">
            <a class="db-btn cf-btn" href="flow_builder.php?flow_id=<?php echo (int)$f['id']; ?>"><?php echo $__ic($I['grid'], 15); ?> Open canvas</a>
            <form method="POST" class="cf-del" data-confirm="Delete this flow and all its steps?">
              <?php echo csrf_field(); ?><input type="hidden" name="delete_id" value="<?php echo (int)$f['id']; ?>">
              <button type="submit" class="cf-ib" title="Delete flow"><?php echo $__ic($I['trash'], 16); ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
      <div class="db-empty" id="cfNone" style="display:none">No flows match your search.</div>
    </div>
    <?php else: ?>
      <div class="cf-empty">
        <span><?php echo $__ic($I['flow'], 34); ?></span>
        <h3>No flows yet</h3>
        <p>Create your first flow to automate conversations and turn chats into sales.</p>
        <button type="button" class="db-btn db-btn--primary" id="cfOpen2"><?php echo $__ic($I['plus'], 16); ?> Create first flow</button>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- create flow dialog -->
<div class="cf-modal" id="cfModal" aria-hidden="true">
  <div class="cf-dialog" role="dialog" aria-modal="true" aria-labelledby="cfDlgT">
    <div class="cf-head">
      <span class="cf-headic"><?php echo $__ic($I['flow'], 22); ?></span>
      <div><h2 id="cfDlgT">New flow</h2><p>Name your flow and choose what starts it.</p></div>
    </div>
    <form method="POST" id="cfForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="create_flow" value="1">
      <input type="hidden" name="trigger_type" id="cfTrig" value="keyword">
      <div class="cf-f"><label for="cf_name">Flow name</label><input type="text" name="name" id="cf_name" placeholder="e.g. Product enquiry" maxlength="120" required></div>
      <div class="cf-f"><label>Starts when</label>
        <div class="cf-opts" id="cfOpts">
          <button type="button" class="is-on" data-t="keyword"><span class="cf-oic"><?php echo $__ic($I['key'], 20); ?></span><b>Keyword</b><small>A customer sends a word</small></button>
          <button type="button" data-t="new_contact"><span class="cf-oic"><?php echo $__ic($I['user'], 20); ?></span><b>New contact</b><small>First message ever</small></button>
          <button type="button" data-t="any_message"><span class="cf-oic"><?php echo $__ic($I['chat'], 20); ?></span><b>Any message</b><small>Every incoming message</small></button>
        </div></div>
      <div class="cf-f" id="cfKw"><label for="cf_kw">Trigger keyword</label><input type="text" name="trigger_keyword" id="cf_kw" placeholder='e.g. menu, hi, price' maxlength="60"><p>When a customer sends this word the flow starts automatically.</p></div>
      <div class="cf-dlgacts"><button type="button" class="cf-cancel" id="cfCancel">Cancel</button><button type="submit" class="cf-create"><?php echo $__ic($I['flow'], 16); ?> Create and build</button></div>
    </form>
  </div>
</div>

<style>
.cf{max-width:none}
.cf-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.cf-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.cf-toast.is-err{background:#FDE4E1;color:#c0372b}.cf-toast svg{margin-top:2px;flex:none}
.cf .db-canvas{height:270px}
.cf .db-donut__chart canvas{max-width:200px;max-height:200px}
.cf-search{display:flex;align-items:center;gap:9px;width:260px;max-width:100%;height:42px;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--mu)}
.cf-search:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cf-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink)}
.cf-list{display:flex;flex-direction:column;gap:10px;padding:6px 20px 20px}
.cf-item{display:grid;grid-template-columns:48px minmax(0,1fr) auto auto auto;gap:18px;align-items:center;padding:14px 16px;border-radius:14px;background:var(--soft);transition:background .15s,box-shadow .15s}
.cf-item:hover{background:#fff;box-shadow:0 10px 26px -16px rgba(18,140,126,.45)}
.cf-ic{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center}
.cf-main b{display:block;font-size:16px;font-weight:800;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cf-sub{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;font-size:13px}
.cf-sub code{padding:3px 9px;border-radius:7px;background:#fff;font:700 12.5px ui-monospace,Consolas,monospace;color:var(--gd)}
.cf-nums{display:flex;gap:10px}
.cf-nums div{min-width:64px;padding:8px 12px;border-radius:11px;background:#fff;text-align:center}
.cf-nums b{display:block;font-size:18px;font-weight:800;color:var(--ink)}
.cf-nums span{font-size:12px;color:var(--mu);font-weight:600}
.cf-sw{display:flex;align-items:center;gap:9px;margin:0}
.cf-tg{position:relative;width:46px;height:26px;border:0;border-radius:99px;background:#C7D1CD;cursor:pointer;padding:0;transition:background .2s}
.cf-tg i{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.cf-tg.is-on{background:linear-gradient(135deg,#25D366,#128C7E)}.cf-tg.is-on i{transform:translateX(20px)}
.cf-live{font-size:13px;font-weight:800;color:var(--mu);min-width:30px}.cf-live.is-on{color:#12803f}
.cf-acts{display:flex;align-items:center;gap:8px}
.cf-btn{height:38px;padding:0 14px;font-size:13px}
.cf-del{margin:0}
.cf-ib{width:38px;height:38px;border:0;border-radius:10px;background:#fff;color:var(--tx);display:grid;place-items:center;cursor:pointer;transition:background .15s,color .15s}
.cf-ib:hover{background:#FDE4E1;color:#c0372b}
.cf-empty{display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;padding:50px 20px 56px}
.cf-empty>span{width:84px;height:84px;border-radius:50%;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;margin-bottom:6px}
.cf-empty h3{margin:0;font-size:20px;color:var(--ink)}
.cf-empty p{margin:0 0 10px;max-width:360px;color:var(--mu);line-height:1.6}
.cf-modal{position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,38,34,.5)}
.cf-modal.open{display:flex}
.cf-dialog{width:100%;max-width:580px;max-height:calc(100vh - 40px);overflow:auto;padding:28px 28px 24px;border-radius:24px;background:#fff;box-shadow:0 30px 70px -20px rgba(0,0,0,.45)}
.cf-head{display:flex;align-items:center;gap:14px;margin-bottom:22px}
.cf-headic{width:48px;height:48px;border-radius:14px;background:#E6FCF2;color:#128C7E;display:grid;place-items:center;flex:none}
.cf-dialog h2{margin:0;font-size:21px;font-weight:800;color:var(--ink);letter-spacing:-.01em}
.cf-head p{margin:3px 0 0;color:var(--mu);font-size:14px}
.cf-f{margin-bottom:18px}
.cf-f>label{display:block;margin:0 0 8px;font-size:13px;font-weight:700;color:var(--tx);letter-spacing:.01em}
.cf-dialog .cf-f input{width:100%;height:48px;padding:0 16px;border:2px solid transparent;border-radius:13px;background:#F1F6F4;font:500 15px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:background .15s,border-color .15s,box-shadow .15s}
.cf-dialog .cf-f input::placeholder{color:#8A9A95}
.cf-dialog .cf-f input:focus{background:#fff;border-color:#128C7E;box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cf-f p{margin:8px 0 0;font-size:12.5px;color:var(--mu);line-height:1.5}
.cf-opts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.cf-dialog .cf-opts button{position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:3px;padding:14px 14px 15px;border:2px solid transparent;border-radius:16px;background:#F1F6F4;text-align:left;cursor:pointer;color:#128C7E;font-family:inherit;transition:background .15s,border-color .15s,box-shadow .15s,transform .15s}
.cf-oic{width:38px;height:38px;border-radius:11px;background:#fff;color:#128C7E;display:grid;place-items:center;margin-bottom:8px;transition:background .15s,color .15s}
.cf-opts button b{font-size:14px;color:var(--ink)}
.cf-opts button small{font-size:12px;color:var(--mu);line-height:1.4}
.cf-dialog .cf-opts button:hover{background:#E9F2EF;transform:translateY(-1px)}
.cf-dialog .cf-opts button.is-on{background:#E6FCF2;border-color:#128C7E;box-shadow:0 8px 20px -12px rgba(18,140,126,.6)}
.cf-opts button.is-on .cf-oic{background:#128C7E;color:#fff}
.cf-opts button.is-on::after{content:"";position:absolute;top:12px;right:12px;width:18px;height:18px;border-radius:50%;background:#128C7E url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'/%3E%3C/svg%3E") center/11px no-repeat}
.cf-fu{margin-bottom:18px;padding:22px}
.cf-fu__head{display:flex;align-items:flex-start;gap:14px;margin-bottom:16px}
.cf-fu__head h2{margin:0;font-size:17px;font-weight:800;color:var(--ink)}
.cf-fu__head p{margin:4px 0 0;font-size:13.5px;line-height:1.55;color:var(--mu);max-width:760px}
.cf-fu__head>div{flex:1}
.cf-fu__ic{width:44px;height:44px;border-radius:13px;background:#E6FCF2;color:#128C7E;display:grid;place-items:center;flex:none}
.cf-sw2{position:relative;width:48px;height:28px;flex:none;margin-top:4px}
.cf-sw2 input{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:2}
.cf-sw2 span{position:absolute;inset:0;border-radius:99px;background:#C7D1CD;transition:background .2s}
.cf-sw2 span::after{content:"";position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.cf-sw2 input:checked+span{background:linear-gradient(135deg,#25D366,#128C7E)}
.cf-sw2 input:checked+span::after{transform:translateX(20px)}
.cf-fu__grid{display:grid;grid-template-columns:230px 1fr auto;gap:14px;align-items:end}
.cf-fu__f label{display:block;margin:0 0 7px;font-size:13px;font-weight:700;color:var(--tx)}
.cf-fu__f select,.cf-fu__f textarea{width:100%;border:2px solid transparent;border-radius:12px;background:#F1F6F4;padding:11px 13px;font:500 14px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:background .15s,border-color .15s,box-shadow .15s}
.cf-fu__f textarea{resize:vertical;min-height:48px}
.cf-fu__f select:focus,.cf-fu__f textarea:focus{background:#fff;border-color:#128C7E;box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.cf-fu__note{margin:12px 0 0;font-size:12.5px;color:var(--mu)}
@media (max-width:900px){.cf-fu__grid{grid-template-columns:1fr}}
.cf-dlgacts{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:24px}
.cf-dialog .cf-cancel{height:46px;padding:0 22px;border:0;border-radius:999px;background:#F1F6F4;color:#374151;font:700 14px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s}
.cf-dialog .cf-cancel:hover{background:#E4ECE9}
.cf-dialog .cf-create{display:inline-flex;align-items:center;gap:8px;height:46px;padding:0 26px;border:0;border-radius:999px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;font:700 14.5px Inter,system-ui,sans-serif;cursor:pointer;box-shadow:0 10px 22px -10px rgba(18,140,126,.8);transition:transform .15s,box-shadow .15s}
.cf-dialog .cf-create:hover{transform:translateY(-1px);box-shadow:0 14px 26px -10px rgba(18,140,126,.9)}
.cf-dialog .cf-create svg{color:#fff}
@media (max-width:1100px){.cf .db-row{grid-template-columns:1fr}.cf-item{grid-template-columns:48px minmax(0,1fr) auto}.cf-nums,.cf-sw,.cf-acts{grid-column:2 / -1}}
@media (max-width:560px){.cf-opts{grid-template-columns:1fr}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var modal = $('cfModal');
  function open() { modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); setTimeout(function () { $('cf_name').focus(); }, 50); }
  function close() { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); }
  ['cfOpen', 'cfOpen2'].forEach(function (id) { var b = $(id); if (b) { b.addEventListener('click', open); } });
  $('cfCancel').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
  $('cfOpts').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-t]'); if (!b) { return; }
    $('cfTrig').value = b.getAttribute('data-t');
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
    $('cfKw').style.display = b.getAttribute('data-t') === 'keyword' ? '' : 'none';
    $('cf_kw').required = b.getAttribute('data-t') === 'keyword';
  });
  $('cf_kw').required = true;
  <?php if ($formError): ?>open();<?php endif; ?>

  var s = $('cfSearch');
  if (s) { s.addEventListener('input', function () {
    var q = this.value.toLowerCase().trim(), n = 0, items = document.querySelectorAll('#cfList .cf-item');
    items.forEach(function (el) { var ok = !q || (el.getAttribute('data-q') || '').indexOf(q) !== -1; el.style.display = ok ? '' : 'none'; if (ok) { n++; } });
    $('cfShown').textContent = n; $('cfNone').style.display = n ? 'none' : 'block';
  }); }
  document.addEventListener('submit', function (e) { var f = e.target.closest && e.target.closest('form.cf-del[data-confirm]'); if (f && !confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); } });

  if (typeof Chart === 'undefined') { return; }
  var ctx = $('cfChart').getContext('2d'), g = ctx.createLinearGradient(0, 0, 0, 270); g.addColorStop(0, '#25D366'); g.addColorStop(1, '#128C7E');
  new Chart(ctx, {type: 'bar', data: {labels: <?php echo json_encode(array_values($tLabels), JSON_UNESCAPED_UNICODE); ?>, datasets: [{label: 'Steps', data: <?php echo json_encode(array_values($tData)); ?>, backgroundColor: g, borderRadius: 8, maxBarThickness: 44}]},
    options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#0F2622', padding: 10, cornerRadius: 8, displayColors: false}},
      scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#586a63', font: {size: 11}, maxRotation: 0}}, y: {beginAtZero: true, border: {display: false}, grid: {color: 'rgba(18,140,126,.08)'}, ticks: {color: '#586a63', font: {size: 11}, precision: 0, maxTicksLimit: 5}}}}});
  var vals = [<?php echo (int)$trig['keyword']; ?>, <?php echo (int)$trig['new_contact']; ?>, <?php echo (int)$trig['any_message']; ?>], total = vals[0] + vals[1] + vals[2];
  new Chart($('cfDonut'), {type: 'doughnut', data: {labels: ['Keyword', 'New contact', 'Any message'], datasets: [{data: total > 0 ? vals : [1], backgroundColor: total > 0 ? ['#3B82F6', '#25D366', '#F59E0B'] : ['#E8F1ED'], borderWidth: 0, hoverOffset: 4}]},
    options: {responsive: true, maintainAspectRatio: true, cutout: '72%', plugins: {legend: {display: false}, tooltip: {enabled: total > 0, backgroundColor: '#0F2622', padding: 10, cornerRadius: 8}}}});
})();
</script>

<?php include '../includes/client_footer.php'; ?>

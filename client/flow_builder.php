<?php
/**
 * Flow Builder canvas (n8n style). The graph is saved by client/flow_api.php and executed by includes/flow/engine.php.
 * Full-screen page: it does not use the dashboard shell so the canvas gets the whole window.
 */
include '../includes/client_auth_check.php';
if (!has_feature('feat_chatbot_builder')) { header('Location: dashboard.php'); exit(); }
require_once __DIR__ . '/../includes/flow/schema.php';
flow_ensure_schema($conn);

$flowId = (int)($_GET['flow_id'] ?? 0);
$st = $conn->prepare("SELECT id, name FROM chatbot_flows WHERE id = ? AND company_id = ?");
$st->bind_param('ii', $flowId, $companyId);
$st->execute();
$flow = $st->get_result()->fetch_assoc();
$st->close();
if (!$flow) { header('Location: chatbot_flows.php'); exit(); }

$nonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
$cfg = [
    'flowId' => (int)$flow['id'],
    'api'    => app_url('client/flow_api.php'),
    'csrf'   => csrf_get_token(),
    'back'   => app_url('client/chatbot_flows.php'),
    'upload' => app_url('client/flow_upload.php'),
];
$ic = static fn(string $p) => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flow: <?php echo htmlspecialchars($flow['name'], ENT_QUOTES, 'UTF-8'); ?> · WAAMINI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo app_url('assets/css/flow-canvas.css?v=' . @filemtime(__DIR__ . '/../assets/css/flow-canvas.css')); ?>">
</head>
<body style="margin:0">
<div class="fb" id="fb">

  <header class="fb-top">
    <a class="fb-back" href="<?php echo htmlspecialchars($cfg['back']); ?>" title="Back to flows"><?php echo $ic('<path d="m15 18-6-6 6-6"/>'); ?></a>
    <input class="fb-name" id="fbName" maxlength="150" value="<?php echo htmlspecialchars($flow['name'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Flow name">
    <span class="fb-chip" id="fbStatus"><i></i>Draft</span>
    <span class="fb-save" id="fbSave">Loading…</span>
    <span class="fb-sp"></span>
    <button class="fb-ib" id="fbUndo" title="Undo (Ctrl+Z)" disabled><?php echo $ic('<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>'); ?></button>
    <button class="fb-ib" id="fbRedo" title="Redo (Ctrl+Y)" disabled><?php echo $ic('<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13"/>'); ?></button>
    <button class="fb-btn" id="fbTemplatesBtn"><?php echo $ic('<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>'); ?> Templates</button>
    <button class="fb-btn" id="fbLogBtn"><?php echo $ic('<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>'); ?> Run log</button>
    <button class="fb-ib" id="fbSettingsBtn" title="Flow settings"><?php echo $ic('<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>'); ?></button>
    <button class="fb-btn is-primary" id="fbPublish">Publish</button>
  </header>

  <div class="fb-work">
    <aside class="fb-pal">
      <h4>Add step</h4>
      <div id="fbPalList"></div>
      <div class="fb-hint" style="padding:6px 8px 2px">Click or drag onto the canvas. Drag from an output dot to connect.</div>
    </aside>

    <div class="fb-view" id="fbView">
      <div class="fb-world" id="fbWorld"><svg class="fb-edges" id="fbEdges"></svg></div>
      <div class="fb-selbox" id="fbSelBox"></div>
      <div class="fb-empty" id="fbEmpty">Double-click the canvas to add your first step</div>
      <div class="fb-menu" id="fbMenu"></div>
    </div>

    <div class="fb-zoom">
      <button id="fbZin" title="Zoom in (+)"><?php echo $ic('<path d="M5 12h14"/><path d="M12 5v14"/>'); ?></button>
      <button id="fbZout" title="Zoom out (-)"><?php echo $ic('<path d="M5 12h14"/>'); ?></button>
      <button id="fbFit" title="Fit to screen (0)"><?php echo $ic('<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>'); ?></button>
    </div>
    <div class="fb-mini" id="fbMini"><canvas id="fbMiniCv"></canvas></div>

    <div class="fb-probs" id="fbProbs">
      <h4 style="display:flex;align-items:center;justify-content:space-between">Checks before publishing <button class="fb-ix" id="fbProbClose"><?php echo $ic('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'); ?></button></h4>
      <div class="fb-plist" id="fbProbList"></div>
    </div>
    <button class="fb-pb is-ok" id="fbProbBtn">No problems</button>

    <aside class="fb-insp" id="fbInsp">
      <div class="fb-ih">
        <div class="fb-ic" id="fbInspIc"></div>
        <div class="fb-nt"><b id="fbInspTitle"></b><small id="fbInspSub" style="color:var(--mu);text-transform:none;letter-spacing:0;font-weight:600"></small></div>
        <button class="fb-ix" id="fbInspDel" title="Delete step (Del)"><?php echo $ic('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>'); ?></button>
        <button class="fb-ix" id="fbInspClose" title="Close"><?php echo $ic('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'); ?></button>
      </div>
      <div class="fb-ib2" id="fbInspBody"></div>
    </aside>

    <section class="fb-log" id="fbLog">
      <div class="fb-lh"><h3>Run log</h3>
        <button class="fb-btn" id="fbLogRefresh">Refresh</button>
        <button class="fb-ix" id="fbLogClose"><?php echo $ic('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'); ?></button></div>
      <div class="fb-lb"><div class="fb-ls" id="fbLogSess"></div><div class="fb-ll" id="fbLogRows"></div></div>
    </section>
  </div>

  <div class="fb-modal" id="fbTemplates">
    <div class="fb-dlg" style="max-width:640px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><h3 style="margin:0">Start from a template</h3><button class="fb-ix" id="fbTemplatesClose" title="Close"><?php echo $ic('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'); ?></button></div>
      <div class="fb-hint" style="margin:0 0 14px">A ready-made flow you can edit. It replaces the current canvas.</div>
      <div class="fb-tgrid">
        <button class="fb-tcard" data-template="welcome"><span class="fb-ic" style="--c:#0284C7"><?php echo $ic('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'); ?></span><b>Welcome message</b><small>Greets the customer, then ends.</small></button>
        <button class="fb-tcard" data-template="menu"><span class="fb-ic" style="--c:#128C7E"><?php echo $ic('<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>'); ?></span><b>Button menu</b><small>3 buttons, each opens its own reply.</small></button>
        <button class="fb-tcard" data-template="lead"><span class="fb-ic" style="--c:#7C3AED"><?php echo $ic('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'); ?></span><b>Lead capture</b><small>Asks for an email and saves it.</small></button>
        <button class="fb-tcard" data-template="handoff"><span class="fb-ic" style="--c:#EA580C"><?php echo $ic('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></span><b>Hand over to a person</b><small>Tells the customer, then pauses the bots.</small></button>
      </div>
    </div>
  </div>
  <div class="fb-modal" id="fbSettings">
    <div class="fb-dlg">
      <h3>Flow settings</h3>
      <div class="fb-f"><label>Priority <em>lower runs first</em></label><input class="fb-in" id="sPrio" type="number" min="1" max="999"><div class="fb-hint">When two flows match the same message, the lower number wins.</div></div>
      <div class="fb-f"><label>End a waiting conversation after <em>minutes</em></label><input class="fb-in" id="sIdle" type="number" min="5" max="10080"><div class="fb-hint">If the customer does not answer for this long, the flow stops.</div></div>
      <div class="fb-f"><label>Maximum steps per message</label><input class="fb-in" id="sSteps" type="number" min="5" max="100"><div class="fb-hint">Safety limit that stops a run that goes in circles.</div></div>
      <div class="fb-acts"><button class="fb-btn" id="sCancel">Cancel</button><button class="fb-btn is-primary" id="sSave">Save settings</button></div>
    </div>
  </div>
  <div class="fb-toast" id="fbToast"></div>
</div>
<script nonce="<?php echo $nonce; ?>">window.FLOW_CFG = <?php echo json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?php echo app_url('assets/js/flow-canvas.js?v=' . @filemtime(__DIR__ . '/../assets/js/flow-canvas.js')); ?>"></script>
</body>
</html>

<?php
include '../includes/client_auth_check.php';
if (!has_feature('crm_advance')) {
    header("Location: dashboard.php");
    exit();
}

$pageTitle = 'Lead Automation';
$currentPage = 'automations';

$success = '';
$error = '';

// Fetch existing settings
$st4 = $conn->prepare("SELECT * FROM lead_automation_settings WHERE company_id = ?");
$st4->bind_param("i", $companyId);
$st4->execute();
$settings = $st4->get_result()->fetch_assoc();

// Default values if no settings exist
if (!$settings) {
    $settings = [
        'followup_msg_1' => "Hi, just following up on our previous conversation. Do you have any questions?",
        'followup_days_1' => 3,
        'followup_msg_2' => "Checking in again regarding your interest in WAAMINI. How can we help you get started?",
        'followup_days_2' => 7,
        'thanks_msg' => "Thank you for getting in touch with us. We understand this isn't the right time for you. Please feel free to reach out if your requirements change in the future!",
        'converted_msg' => "Thanks for choosing us! We are excited to have you onboard. Let us know if you need any assistance."
    ];
}

// ── AI auto-detect (lead outcome from the chat) ──
require_once __DIR__ . '/../includes/ai_crm_sync.php';
if (isset($_POST['save_ai'])) {
    csrf_verify();
    crm_ai_save_settings($conn, $companyId, !empty($_POST['ai_auto_outcome']), (int)($_POST['ai_min_confidence'] ?? 80), !empty($_POST['ai_auto_profile']));
    header('Location: lead_automation.php?ai=saved');
    exit();
}
$aiCfg = crm_ai_settings($conn, $companyId);
$aiReady = false;
try {
    require_once __DIR__ . '/../includes/waamini_bot/bootstrap.php';
    $aiReady = WB_Store::platformReady(WB_Store::settings($conn)) || (string)(getenv('OPENAI_API_KEY') ?: '') !== '';
} catch (Throwable $e) {}
$aiRecent = [];
try {
    crm_ensure_analysis_table($conn);
    $st = $conn->prepare("SELECT a.status, a.confidence, a.reason, a.auto_applied, a.applied_outcome, a.analyzed_at, c.id AS cid, c.name, c.phone
                          FROM ai_lead_analysis a JOIN contacts c ON c.id = a.contact_id AND c.company_id = a.company_id
                          WHERE a.company_id = ? ORDER BY a.analyzed_at DESC LIMIT 6");
    $st->bind_param('i', $companyId);
    $st->execute();
    $aiRecent = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} catch (Throwable $e) {}

// Handle Form Submission
if (isset($_POST['save'])) {
    csrf_verify();

    // trim(), not sanitize(): these are plain-text WhatsApp message bodies sent
    // verbatim to the API, not HTML. Output is escaped with htmlspecialchars() on display;
    // sanitize() here would double-encode quotes/apostrophes into the outbound message.
    $f1_msg  = trim((string)($_POST['followup_msg_1'] ?? ''));
    $f2_msg  = trim((string)($_POST['followup_msg_2'] ?? ''));
    $t_msg   = trim((string)($_POST['thanks_msg'] ?? ''));
    $c_msg   = trim((string)($_POST['converted_msg'] ?? ''));
    $f1_days = max(1, min(365, intval($_POST['followup_days_1'] ?? 0)));
    $f2_days = max(1, min(365, intval($_POST['followup_days_2'] ?? 0)));

    $len = static function (string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); };
    if ($f1_msg === '' || $f2_msg === '' || $t_msg === '' || $c_msg === '') {
        $error = "All four messages are required.";
    } elseif ($len($f1_msg) > 1000 || $len($f2_msg) > 1000 || $len($t_msg) > 1000 || $len($c_msg) > 1000) {
        $error = "Each message can be at most 1000 characters.";
    } elseif ($f2_days <= $f1_days) {
        $error = "The second follow-up must be sent later than the first.";
    } else {
        $stmt = $conn->prepare("REPLACE INTO lead_automation_settings (company_id, followup_msg_1, followup_days_1, followup_msg_2, followup_days_2, thanks_msg, converted_msg) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isisiss", $companyId, $f1_msg, $f1_days, $f2_msg, $f2_days, $t_msg, $c_msg);

        if ($stmt->execute()) {
            $success = "Automation settings saved successfully!";
            $st4b = $conn->prepare("SELECT * FROM lead_automation_settings WHERE company_id = ?");
            $st4b->bind_param("i", $companyId);
            $st4b->execute();
            $settings = $st4b->get_result()->fetch_assoc();
        } else {
            $error = "Failed to save settings.";
        }
    }

    if ($error) {
        // Keep what the user typed so nothing is lost on a validation error
        $settings = array_merge($settings, [
            'followup_msg_1' => $f1_msg, 'followup_days_1' => $f1_days,
            'followup_msg_2' => $f2_msg, 'followup_days_2' => $f2_days,
            'thanks_msg' => $t_msg, 'converted_msg' => $c_msg,
        ]);
    }
}

// Handle manual queue trigger
if (isset($_POST['run_queue'])) {
    csrf_verify();
    $st5a = $conn->prepare("SELECT COUNT(*) c FROM automation_queue WHERE company_id=? AND status='pending' AND scheduled_at<=NOW()");
    $st5a->bind_param("i", $companyId);
    $st5a->execute();
    $pendingCount = (int)($st5a->get_result()->fetch_assoc()['c'] ?? 0);
    $st5b = $conn->prepare("SELECT q.*, c.phone as contact_phone FROM automation_queue q JOIN contacts c ON c.id=q.contact_id AND c.company_id=q.company_id WHERE q.status='pending' AND q.scheduled_at<=NOW() AND q.company_id=? ORDER BY q.scheduled_at ASC LIMIT 20");
    $st5b->bind_param("i", $companyId);
    $st5b->execute();
    $qRes = $st5b->get_result();
    $manualSent = 0;
    if ($qRes) {
        $cfgStmt2 = $conn->prepare("SELECT access_token, phone_number_id FROM whatsapp_config WHERE company_id=? LIMIT 1");
        $cfgStmt2->bind_param("i", $companyId); $cfgStmt2->execute();
        $cfg2 = $cfgStmt2->get_result()->fetch_assoc();
        if ($cfg2 && !empty($cfg2['access_token'])) {
            while ($qRow = $qRes->fetch_assoc()) {
                $qId = intval($qRow['id']); $phone2 = ltrim($qRow['contact_phone'], '+');
                $payload2 = json_encode(['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$phone2,'type'=>'text','text'=>['preview_url'=>false,'body'=>$qRow['message']]]);
                $apiVer2 = $cfg2['api_version'] ?? $_ENV['WHATSAPP_API_VERSION'] ?? 'v18.0';
                $accessToken2 = waamini_whatsapp_decrypt_config_token($cfg2);
                $ch2 = curl_init("https://graph.facebook.com/{$apiVer2}/{$cfg2['phone_number_id']}/messages");
                curl_setopt_array($ch2, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload2,CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$accessToken2],CURLOPT_TIMEOUT=>10]);
                $apiResp2 = curl_exec($ch2); curl_close($ch2);
                $wid2 = json_decode($apiResp2, true)['messages'][0]['id'] ?? null;
                if ($wid2) {
                    $stUpd = $conn->prepare("UPDATE automation_queue SET status='sent',sent_at=NOW() WHERE id=?");
                    $stUpd->bind_param("i", $qId); $stUpd->execute();
                    $cid2 = intval($qRow['contact_id']); $msg2 = $qRow['message'];
                    $stIns = $conn->prepare("INSERT INTO messages (company_id,contact_id,direction,type,message,is_read,status,whatsapp_message_id) VALUES (?,?,'outgoing','text',?,1,'sent',?)");
                    $stIns->bind_param("iiss", $companyId, $cid2, $msg2, $wid2); $stIns->execute();
                    $stCon = $conn->prepare("UPDATE contacts SET last_message_at=NOW() WHERE id=?");
                    $stCon->bind_param("i", $cid2); $stCon->execute();
                    $manualSent++;
                } else {
                    $stFail = $conn->prepare("UPDATE automation_queue SET status='failed',sent_at=NOW() WHERE id=?");
                    $stFail->bind_param("i", $qId); $stFail->execute();
                }
            }
        }
    }
    header("Location: lead_automation.php?run_done=1&sent={$manualSent}");
    exit();
}

$queueStats = ['pending'=>0,'sent'=>0,'failed'=>0,'due_now'=>0];
try {
    $st5c = $conn->prepare("SELECT status, COUNT(*) c FROM automation_queue WHERE company_id=? GROUP BY status");
    $st5c->bind_param("i", $companyId);
    $st5c->execute();
    $qStat = $st5c->get_result();
    if($qStat) while ($qs = $qStat->fetch_assoc()) $queueStats[$qs['status']] = (int)$qs['c'];
    $st5d = $conn->prepare("SELECT COUNT(*) c FROM automation_queue WHERE company_id=? AND status='pending' AND scheduled_at<=NOW()");
    $st5d->bind_param("i", $companyId);
    $st5d->execute();
    $dueRes = $st5d->get_result();
    if($dueRes) $queueStats['due_now'] = (int)$dueRes->fetch_assoc()['c'];
} catch (\Exception $e) {}

include '../includes/client_header.php';

$stages = [
    ['key' => 'f1', 'field' => 'followup_msg_1', 'days' => 'followup_days_1', 'tone' => 'amber', 'icon' => 'scheduled', 'title' => 'First follow-up',
     'trigger' => 'Lead marked "Follow-up"', 'hint' => 'A gentle nudge a few days after the conversation.'],
    ['key' => 'f2', 'field' => 'followup_msg_2', 'days' => 'followup_days_2', 'tone' => 'amber', 'icon' => 'scheduled', 'title' => 'Second follow-up',
     'trigger' => 'Lead marked "Follow-up"', 'hint' => 'One last check-in if there was still no reply.'],
    ['key' => 'th', 'field' => 'thanks_msg', 'days' => null, 'tone' => 'red', 'icon' => 'blacklist', 'title' => 'Polite thank-you',
     'trigger' => 'Lead marked "Declined" or "Not interested"', 'hint' => 'Sent automatically 1 day later, leaving the door open.'],
    ['key' => 'cv', 'field' => 'converted_msg', 'days' => null, 'tone' => 'green', 'icon' => 'shield', 'title' => 'Welcome message',
     'trigger' => 'Lead marked "Converted"', 'hint' => 'Sent instantly to welcome your new customer.'],
];
$dueNote = $queueStats['due_now'];
?>

<style>
/* ── Lead Automation — dashboard design system, no divider lines ── */
.la{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#34453f;--mu:#586a63;--line:#E3EEE9;--soft:#F3FBF7;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.la *{box-sizing:border-box}
.la-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding:24px 28px;border-radius:18px;border:1px solid var(--line);background:#fff;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);position:relative;overflow:hidden}
.la-hero-l{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.la-hero-ico{width:52px;height:52px;border-radius:13px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;flex-shrink:0}
.la-hero-ico svg{width:22px;height:22px}
.la-hero h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.4px}
.la-hero p{margin:0;font-size:14.5px;color:var(--tx);max-width:560px;line-height:1.5}
.la-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:11px;font:700 14px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:var(--ink);transition:border-color .2s,color .2s,transform .2s;position:relative;z-index:1}
.la-btn:hover{border-color:var(--g);color:var(--g)}
.la-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(18,140,126,.7)}
.la-btn--primary:hover{color:#fff;transform:translateY(-1px)}
.la-btn[disabled]{opacity:.55;cursor:not-allowed;transform:none}
.la-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:14px;font-weight:600;background:#E3F7EE;color:#0B6B5E}
.la-notice.err{background:#FDE7E7;color:#9b1c1c}

.la-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
.la-kpi{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5)}
.la-kpi-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.la-kpi-ico svg{width:20px;height:20px}
.la-kpi.amber .la-kpi-ico{background:#FEF3DC;color:#b7791f}.la-kpi.blue .la-kpi-ico{background:#E4EFFD;color:#1d6fd6}.la-kpi.red .la-kpi-ico{background:#FDE7E7;color:#c62828}
.la-kpi-v{font-size:26px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.la-kpi-l{font-size:12.5px;color:var(--mu);margin-top:4px;font-weight:600}
.la-run{background:linear-gradient(135deg,#fff,var(--soft));display:flex;flex-direction:column;justify-content:center;gap:8px}
.la-run form{margin:0}
.la-run .la-btn{width:100%;height:40px;font-size:13.5px}
.la-run small{font-size:12.5px;color:var(--mu);font-weight:600}

.la-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px;align-items:start}
.la-col{display:flex;flex-direction:column;gap:18px;min-width:0}
.la-stage{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);display:flex;flex-direction:column;gap:16px}
.la-stage-hd{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.la-st-ico{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
.la-st-ico svg{width:20px;height:20px}
.la-st-ico.amber{background:#FEF3DC;color:#b7791f}.la-st-ico.red{background:#FDE7E7;color:#c62828}.la-st-ico.green{background:#E3F7EE;color:var(--g)}
.la-stage-hd h2{margin:0;font-size:17px;font-weight:800;letter-spacing:-.2px}
.la-stage-hd p{margin:2px 0 0;font-size:13px;color:var(--mu)}
.la-chips{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.la-chip{font-size:12px;font-weight:700;padding:4px 11px;border-radius:999px;background:var(--soft);color:var(--tx);white-space:nowrap}
.la-chip.amber{background:#FEF3DC;color:#8a5a00}.la-chip.red{background:#FDE7E7;color:#9b1c1c}.la-chip.green{background:#E3F7EE;color:var(--gd)}
.la-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,260px);gap:16px;align-items:start}
.la-f{display:flex;flex-direction:column;gap:6px;min-width:0}
.la-lab{display:flex;justify-content:space-between;align-items:baseline;font-size:13px;font-weight:700}
.la-count{font-size:12px;font-weight:600;color:var(--mu);font-variant-numeric:tabular-nums}
.la-count.over{color:#c62828}
.la textarea{width:100%;font:500 14.5px/1.55 Inter,system-ui,sans-serif;color:var(--ink);background:var(--soft);border:1.5px solid transparent;border-radius:12px;padding:12px 14px;min-height:130px;resize:vertical;outline:none;transition:border-color .15s,background .15s}
.la textarea:focus{border-color:var(--g);background:#fff}
.la-delay{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--soft);border-radius:12px;padding:10px 14px;font-size:14px;color:var(--tx);font-weight:600}
.la-step{display:inline-flex;align-items:center;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 0 0 1px var(--line)}
.la-step button{width:34px;height:36px;border:0;background:transparent;font:700 18px Inter,system-ui,sans-serif;color:var(--gd);cursor:pointer}
.la-step button:hover{background:var(--soft)}
.la-step input{width:54px;height:36px;border:0;text-align:center;font:700 15px Inter,system-ui,sans-serif;color:var(--ink);background:transparent;outline:none;-moz-appearance:textfield}
.la-step input::-webkit-outer-spin-button,.la-step input::-webkit-inner-spin-button{-webkit-appearance:none}
.la-fixed{background:var(--soft);border-radius:12px;padding:10px 14px;font-size:14px;color:var(--tx);font-weight:600}
.la-warn{display:none;font-size:13px;font-weight:600;color:#9b1c1c;background:#FDE7E7;border-radius:10px;padding:8px 12px}
.la-warn.on{display:block}

.la-wa{background:#efeae2 url(../assets/img/wa-wallpaper.jpg) repeat;background-size:320px auto;border-radius:14px;padding:14px}
.la-bub{position:relative;background:#fff;border-radius:0 9px 9px 9px;padding:7px 9px 5px;font-size:13.5px;line-height:1.42;color:#111B21;box-shadow:0 1px .5px rgba(11,20,26,.16);white-space:pre-wrap;overflow-wrap:anywhere;max-height:200px;overflow:auto}
.la-bub::before{content:'';position:absolute;left:-7px;top:0;border-top:9px solid #fff;border-left:8px solid transparent}
.la-bub.out{background:#d9fdd3;border-radius:9px 0 9px 9px;margin-left:auto}
.la-bub.out::before{left:auto;right:-7px;border-top-color:#d9fdd3;border-left:0;border-right:8px solid transparent}
.la-bub small{display:block;text-align:right;color:#6B7C85;font-size:11px;margin-top:2px;white-space:normal}
.la-bub .empty{color:#8696a0}
.la-prev-lab{font-size:12px;font-weight:700;color:var(--mu);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}

.la-side{position:sticky;top:90px;display:flex;flex-direction:column;gap:18px}
.la-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 12px 30px -24px rgba(18,140,126,.5);display:flex;flex-direction:column;gap:14px}
.la-card h3{margin:0;font-size:16px;font-weight:800}
.la-tl{display:flex;flex-direction:column}
.la-tl-r{display:flex;gap:12px;align-items:flex-start;position:relative;padding-bottom:18px}
.la-tl-r:last-child{padding-bottom:0}
.la-tl-r:not(:last-child)::before{content:'';position:absolute;left:13px;top:30px;bottom:2px;width:2px;background:var(--line)}
.la-tl-d{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;background:#E3F7EE;color:var(--g)}
.la-tl-d svg{width:14px;height:14px}
.la-tl-d.amber{background:#FEF3DC;color:#b7791f}.la-tl-d.red{background:#FDE7E7;color:#c62828}.la-tl-d.blue{background:#E4EFFD;color:#1d6fd6}
.la-tl-day{font-size:12px;font-weight:800;color:var(--mu);text-transform:uppercase;letter-spacing:.5px}
.la-tl-t{font-size:14px;font-weight:700;margin-top:1px}
.la-how{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.la-how li{display:flex;gap:10px;font-size:13.5px;color:var(--tx);line-height:1.5;background:var(--soft);border-radius:11px;padding:10px 12px}
.la-how li b{color:var(--ink)}

.la-bar{position:sticky;bottom:0;z-index:5;display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 -6px 20px -10px rgba(18,140,126,.35);padding:12px 18px;margin-top:4px}
.la-bar span{margin-right:auto;font-size:13.5px;color:var(--mu);font-weight:600}
.la-bar span.dirty{color:#8a5a00}
.la-bar .la-btn{height:42px}

@media (max-width:1100px){.la-grid{grid-template-columns:minmax(0,1fr)}.la-side{position:static}.la-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.la-body{grid-template-columns:minmax(0,1fr)}.la-chips{margin-left:0}}
@media (max-width:560px){.la-hero{padding:18px}.la-hero h1{font-size:21px}.la-kpis{grid-template-columns:1fr}}
</style>

<div class="la">

<?php if ($success): ?><div class="la-notice"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="la-notice err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (isset($_GET['run_done'])): ?><div class="la-notice"><?php echo intval($_GET['sent']); ?> queued message(s) sent.</div><?php endif; ?>

<div class="la-hero">
    <div class="la-hero-l">
        <div class="la-hero-ico"><?php echo cn_svg('automations'); ?></div>
        <div>
            <h1>Lead Automation</h1>
            <p>Decide what WhatsApp says when a lead's status changes. Set it once and every lead gets the right message at the right time.</p>
        </div>
    </div>
    <a href="whatsapp.php" class="la-btn">Open WhatsApp Chat &rarr;</a>
</div>

<div class="la-kpis">
    <div class="la-kpi amber"><div class="la-kpi-ico"><?php echo cn_svg('scheduled'); ?></div><div><div class="la-kpi-v"><?php echo number_format($queueStats['pending']); ?></div><div class="la-kpi-l">Pending in queue</div></div></div>
    <div class="la-kpi"><div class="la-kpi-ico"><?php echo cn_svg('blast'); ?></div><div><div class="la-kpi-v"><?php echo number_format($queueStats['sent']); ?></div><div class="la-kpi-l">Sent</div></div></div>
    <div class="la-kpi red"><div class="la-kpi-ico"><?php echo cn_svg('blacklist'); ?></div><div><div class="la-kpi-v"><?php echo number_format($queueStats['failed']); ?></div><div class="la-kpi-l">Failed</div></div></div>
    <div class="la-kpi blue la-run">
        <?php if ($dueNote > 0): ?>
            <form method="POST"><?php echo csrf_field(); ?>
                <button type="submit" name="run_queue" class="la-btn la-btn--primary">Send <?php echo (int)$dueNote; ?> due now</button>
            </form>
            <small>Messages whose time has come</small>
        <?php else: ?>
            <div class="la-kpi-v" style="font-size:20px">0 due</div><small>Nothing is waiting to go out</small>
        <?php endif; ?>
    </div>
</div>

<section class="la-ai">
    <div class="la-ai-hd">
        <div class="la-ai-ico"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg></div>
        <div class="la-ai-t">
            <h2>AI CRM from WhatsApp chats</h2>
            <p>AI reads each customer's WhatsApp chat, fills their CRM card (name, company, interests, summary, lead score) and sets the lead to <b>Follow-up</b>, <b>Converted</b>, <b>Declined</b> or <b>Not interested</b>. That starts the messages below and your Drip Campaigns, exactly like pressing the button in the CRM panel.</p>
        </div>
        <?php if (!$aiReady): ?>
            <span class="la-ai-pill bad">AI not connected</span>
        <?php elseif ((int)$aiCfg['auto_outcome'] === 1): ?>
            <span class="la-ai-pill ok">On</span>
        <?php else: ?>
            <span class="la-ai-pill off">Off</span>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['ai'])): ?><div class="la-ai-note ok">AI auto-detect settings saved.</div><?php endif; ?>
    <?php if (!$aiReady): ?><div class="la-ai-note bad">No AI provider is connected for this platform yet, so nothing is analysed. Ask your WAAMINI admin to connect one in Admin &rarr; WAAMINI Bot.</div><?php endif; ?>

    <div class="la-ai-grid">
        <form method="POST" class="la-ai-form">
            <?php echo csrf_field(); ?>
            <label class="la-ai-row">
                <span><b>Set lead status automatically</b><small>Runs about 30 seconds after the customer's last message.</small></span>
                <span class="la-ai-sw"><input type="checkbox" name="ai_auto_outcome" value="1" <?php echo (int)$aiCfg['auto_outcome'] === 1 ? 'checked' : ''; ?>><i></i></span>
            </label>
            <label class="la-ai-row">
                <span><b>Fill customer details from the chat</b><small>Name, email, company, city, interest tags, lead score and an AI summary in Notes. Never overwrites what your team typed.</small></span>
                <span class="la-ai-sw"><input type="checkbox" name="ai_auto_profile" value="1" <?php echo (int)($aiCfg['auto_profile'] ?? 1) === 1 ? 'checked' : ''; ?>><i></i></span>
            </label>
            <label class="la-ai-row">
                <span><b>Only when AI is at least</b><small>Lower = acts more often. Higher = only when it is very sure.</small></span>
                <select name="ai_min_confidence">
                    <?php foreach ([60 => '60% sure', 70 => '70% sure', 80 => '80% sure (recommended)', 90 => '90% sure'] as $cv => $cl): ?>
                        <option value="<?php echo $cv; ?>" <?php echo (int)$aiCfg['min_confidence'] === $cv ? 'selected' : ''; ?>><?php echo $cl; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <ul class="la-ai-rules">
                <li>A customer already marked <b>Converted</b> is never changed by AI.</li>
                <li>Greetings-only chats are skipped until there is a clear signal.</li>
                <li>You can always change the status by hand in the CRM panel.</li>
            </ul>
            <button type="submit" name="save_ai" class="la-btn la-btn--primary">Save AI setting</button>
        </form>

        <div class="la-ai-log">
            <h3>Latest AI decisions</h3>
            <?php if (!$aiRecent): ?>
                <p class="la-ai-empty">No chats analysed yet. New WhatsApp messages will appear here.</p>
            <?php else: foreach ($aiRecent as $ar):
                $lbl = ['follow_up' => 'Follow-up', 'converted' => 'Converted', 'declined' => 'Declined', 'not_interested' => 'Not interested', 'insufficient' => 'Too early'][$ar['status']] ?? $ar['status'];
                $tone = ['follow_up' => 'blue', 'converted' => 'ok', 'declined' => 'bad', 'not_interested' => 'amber'][$ar['status']] ?? 'off';
            ?>
                <a class="la-ai-item" href="whatsapp.php?c=<?php echo (int)$ar['cid']; ?>&crm=open">
                    <span class="la-ai-who"><b><?php echo htmlspecialchars($ar['name'] ?: $ar['phone']); ?></b><small><?php echo htmlspecialchars((string)$ar['reason']); ?></small></span>
                    <span class="la-ai-res"><span class="la-ai-pill <?php echo $tone; ?>"><?php echo htmlspecialchars($lbl); ?> &middot; <?php echo (int)$ar['confidence']; ?>%</span>
                    <small><?php echo !empty($ar['auto_applied']) ? 'Applied automatically' : 'Suggestion only'; ?></small></span>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</section>
<style>
.la-ai{background:#fff;border-radius:18px;padding:22px;margin:0 0 20px;box-shadow:0 1px 3px rgba(15,38,34,.06)}
.la-ai-hd{display:flex;gap:14px;align-items:flex-start}
.la-ai-ico{width:44px;height:44px;border-radius:13px;background:#E3F7EE;color:#128C7E;display:grid;place-items:center;flex:none}
.la-ai-t{flex:1;min-width:0}
.la-ai-t h2{margin:0 0 4px;font-size:17px;color:#0F2622}
.la-ai-t p{margin:0;font-size:13.5px;line-height:1.6;color:#4b5d57}
.la-ai-pill{display:inline-block;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:#EEF2F1;color:#4b5d57}
.la-ai-pill.ok{background:#E3F7EE;color:#0B6B5E}.la-ai-pill.bad{background:#FDECEC;color:#A12222}.la-ai-pill.blue{background:#E4EFFD;color:#1d6fd6}.la-ai-pill.amber{background:#FEF3DC;color:#92580a}
.la-ai-note{margin:14px 0 0;padding:11px 14px;border-radius:12px;font-size:13px;font-weight:600}
.la-ai-note.ok{background:#E3F7EE;color:#0B6B5E}.la-ai-note.bad{background:#FFF6E0;color:#8A5A00}
.la-ai-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;margin-top:18px}
.la-ai-form{display:flex;flex-direction:column;gap:10px}
.la-ai-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 15px;border-radius:13px;background:#F3FBF7;cursor:pointer}
.la-ai-row b{display:block;font-size:13.5px;color:#0F2622}
.la-ai-row small{display:block;font-size:12px;color:#7a8c86;margin-top:2px}
.la-ai-row select{height:38px;border:0;border-radius:10px;padding:0 10px;background:#fff;font:inherit;font-size:13px}
.la-ai-sw{position:relative;width:46px;height:26px;flex:none}
.la-ai-sw input{opacity:0;width:0;height:0;position:absolute}
.la-ai-sw i{position:absolute;inset:0;border-radius:26px;background:#D5E3DD;transition:background .2s}
.la-ai-sw i::before{content:"";position:absolute;left:3px;top:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s}
.la-ai-sw input:checked + i{background:#128C7E}.la-ai-sw input:checked + i::before{transform:translateX(20px)}
.la-ai-rules{margin:4px 0 6px;padding-left:18px;font-size:12.5px;line-height:1.7;color:#4b5d57}
.la-ai-form .la-btn{align-self:flex-start}
.la-ai-log h3{margin:0 0 10px;font-size:14px;color:#0F2622}
.la-ai-empty{margin:0;padding:16px;border-radius:13px;background:#F3FBF7;font-size:13px;color:#7a8c86}
.la-ai-item{display:flex;justify-content:space-between;gap:12px;padding:11px 13px;border-radius:12px;text-decoration:none;margin-bottom:6px;background:#F7FAF9}
.la-ai-item:hover{background:#EEF7F3}
.la-ai-who{min-width:0}.la-ai-who b{display:block;font-size:13.5px;color:#0F2622}
.la-ai-who small{display:block;font-size:12px;color:#7a8c86;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.la-ai-res{text-align:right;flex:none}.la-ai-res small{display:block;font-size:11.5px;color:#7a8c86;margin-top:3px}
@media (max-width:900px){.la-ai-grid{grid-template-columns:1fr}}
</style>
<form method="POST" id="laForm">
<?php echo csrf_field(); ?>
<div class="la-grid">

    <div class="la-col">
    <?php foreach ($stages as $st): $val = (string)($settings[$st['field']] ?? ''); ?>
        <section class="la-stage" data-stage="<?php echo $st['key']; ?>">
            <div class="la-stage-hd">
                <div class="la-st-ico <?php echo $st['tone']; ?>"><?php echo cn_svg($st['icon']); ?></div>
                <div><h2><?php echo htmlspecialchars($st['title']); ?></h2><p><?php echo htmlspecialchars($st['hint']); ?></p></div>
                <div class="la-chips">
                    <span class="la-chip <?php echo $st['tone']; ?>"><?php echo htmlspecialchars($st['trigger']); ?></span>
                    <?php if ($st['key'] === 'th'): ?><span class="la-chip">After 1 day</span><?php elseif ($st['key'] === 'cv'): ?><span class="la-chip">Instant</span><?php endif; ?>
                </div>
            </div>
            <div class="la-body">
                <div class="la-f">
                    <div class="la-lab"><label for="la_<?php echo $st['key']; ?>">Message</label><span class="la-count" id="cnt_<?php echo $st['key']; ?>">0/1000</span></div>
                    <textarea id="la_<?php echo $st['key']; ?>" name="<?php echo $st['field']; ?>" maxlength="1000" required data-prev="pv_<?php echo $st['key']; ?>" data-cnt="cnt_<?php echo $st['key']; ?>"><?php echo htmlspecialchars($val); ?></textarea>
                    <?php if ($st['days']): ?>
                    <div class="la-delay">Send after
                        <span class="la-step"><button type="button" data-step="-1" data-for="<?php echo $st['days']; ?>" aria-label="Fewer days">&minus;</button><input type="number" id="<?php echo $st['days']; ?>" name="<?php echo $st['days']; ?>" min="1" max="365" value="<?php echo (int)$settings[$st['days']]; ?>" required><button type="button" data-step="1" data-for="<?php echo $st['days']; ?>" aria-label="More days">+</button></span>
                        days
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="la-prev-lab">Customer sees</div>
                    <div class="la-wa"><div class="la-bub out" id="pv_<?php echo $st['key']; ?>"></div></div>
                </div>
            </div>
            <?php if ($st['key'] === 'f2'): ?><div class="la-warn" id="laWarn">The second follow-up must be later than the first.</div><?php endif; ?>
        </section>
    <?php endforeach; ?>

        <div class="la-bar">
            <span id="laState">All changes saved</span>
            <button type="submit" name="save" class="la-btn la-btn--primary" id="laSave">Save automation</button>
        </div>
    </div>

    <aside class="la-side">
        <div class="la-card">
            <h3>Cycle preview</h3>
            <div class="la-tl">
                <div class="la-tl-r"><div class="la-tl-d blue"><?php echo cn_svg('profile'); ?></div><div><div class="la-tl-day">Day 0</div><div class="la-tl-t">Lead marked "Follow-up"</div></div></div>
                <div class="la-tl-r"><div class="la-tl-d amber"><?php echo cn_svg('scheduled'); ?></div><div><div class="la-tl-day" id="tl_d1">Day <?php echo (int)$settings['followup_days_1']; ?></div><div class="la-tl-t">First follow-up</div></div></div>
                <div class="la-tl-r"><div class="la-tl-d amber"><?php echo cn_svg('scheduled'); ?></div><div><div class="la-tl-day" id="tl_d2">Day <?php echo (int)$settings['followup_days_2']; ?></div><div class="la-tl-t">Second follow-up</div></div></div>
                <div class="la-tl-r"><div class="la-tl-d red"><?php echo cn_svg('blacklist'); ?></div><div><div class="la-tl-day">1 day after</div><div class="la-tl-t">Declined: polite thank-you</div></div></div>
                <div class="la-tl-r"><div class="la-tl-d"><?php echo cn_svg('shield'); ?></div><div><div class="la-tl-day">Instant</div><div class="la-tl-t">Converted: welcome message</div></div></div>
            </div>
        </div>
        <div class="la-card">
            <h3>How it works</h3>
            <ul class="la-how">
                <li>Change a lead's status in <b>WhatsApp Chat</b> and the matching messages are queued.</li>
                <li><b>Follow-up</b> schedules both reminders to re-engage the lead.</li>
                <li><b>Declined</b> sends a thank-you after 24 hours. <b>Converted</b> sends instantly.</li>
            </ul>
        </div>
    </aside>

</div>
</form>
</div>

<script>
(function () {
    var form = document.getElementById('laForm'), dirty = false;
    var state = document.getElementById('laState'), saveBtn = document.getElementById('laSave');
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function preview(t) {
        var pv = document.getElementById(t.dataset.prev), cnt = document.getElementById(t.dataset.cnt), v = t.value;
        pv.innerHTML = v.trim() ? esc(v) + '<small>now ✓✓</small>' : '<span class="empty">Type a message to preview it</span>';
        cnt.textContent = v.length + '/1000';
        cnt.classList.toggle('over', v.length > 1000);
    }
    function markDirty() { if (dirty) return; dirty = true; state.textContent = 'You have unsaved changes'; state.className = 'dirty'; }
    function timeline() {
        var d1 = parseInt(document.getElementById('followup_days_1').value, 10) || 0;
        var d2 = parseInt(document.getElementById('followup_days_2').value, 10) || 0;
        document.getElementById('tl_d1').textContent = 'Day ' + d1;
        document.getElementById('tl_d2').textContent = 'Day ' + d2;
        var bad = d2 <= d1;
        document.getElementById('laWarn').classList.toggle('on', bad);
        saveBtn.disabled = bad;
    }
    document.querySelectorAll('textarea[data-prev]').forEach(function (t) { preview(t); t.addEventListener('input', function () { preview(t); markDirty(); }); });
    document.querySelectorAll('.la-step input').forEach(function (i) { i.addEventListener('input', function () { timeline(); markDirty(); }); });
    document.querySelectorAll('.la-step button').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = document.getElementById(b.dataset.for), v = (parseInt(i.value, 10) || 1) + parseInt(b.dataset.step, 10);
            i.value = Math.max(1, Math.min(365, v)); timeline(); markDirty();
        });
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    form.addEventListener('submit', function () { dirty = false; });
    timeline();
})();
</script>

<?php include '../includes/client_footer.php'; ?>

<?php
include '../includes/auth_check.php';
include '../db.php';
require_once __DIR__ . '/../includes/whatsapp_settings.php';
require_once __DIR__ . '/../includes/whatsapp_api_helper.php';

$pageTitle = 'Admin Broadcasts';
$currentPage = 'broadcast';

/**
 * Load broadcast recipients with duplicate subscription rows collapsed (same normalized phone → one send).
 *
 * @return array{ok:bool, error:?string, recipients:list<array{name:string, phone:string}>, duplicate_rows:int}
 */
function waamini_broadcast_fetch_recipients(mysqli $conn, string $recipientGroup): array {
    if (!in_array($recipientGroup, ['active_clients', 'all_clients'], true)) {
        $recipientGroup = 'active_clients';
    }

    $query = 'SELECT s.whatsapp_number, c.name FROM subscriptions s
        INNER JOIN companies c ON s.company_id = c.id
        WHERE s.whatsapp_number IS NOT NULL AND TRIM(s.whatsapp_number) != \'\'\n        AND s.status IN (\'active\', \'trial\')';
    
    if ($recipientGroup === 'all_clients') {
        $query = 'SELECT s.whatsapp_number, c.name FROM subscriptions s
            INNER JOIN companies c ON s.company_id = c.id
            WHERE s.whatsapp_number IS NOT NULL AND TRIM(s.whatsapp_number) != \'\'\n            ';
    }

    try {
        $targets = $conn->query($query);
        if (!$targets) {
            return ['ok' => false, 'error' => $conn->error ?? 'Unknown error', 'recipients' => [], 'duplicate_rows' => 0];
        }

        $seen = [];
        $duplicateRows = 0;
        $recipients = [];

        while ($row = $targets->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $phoneRaw = trim((string)($row['whatsapp_number'] ?? ''));
            if ($phoneRaw === '') {
                continue;
            }
            $dedupeKey = preg_replace('/\D+/', '', $phoneRaw);
            if ($dedupeKey === '') {
                continue;
            }
            if (isset($seen[$dedupeKey])) {
                $duplicateRows++;
                continue;
            }
            $seen[$dedupeKey] = true;
            $recipients[] = [
                'name' => htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'phone' => $phoneRaw,
            ];
        }

        return ['ok' => true, 'error' => null, 'recipients' => $recipients, 'duplicate_rows' => $duplicateRows];
    } catch (Throwable $e) {
        error_log('[WAAMINI broadcast] Exception during recipient fetch: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Exception occurred while loading recipients', 'recipients' => [], 'duplicate_rows' => 0];
    }
}

function waamini_broadcast_mask_phone(string $phoneRaw): string {
    $digits = preg_replace('/\D+/', '', $phoneRaw);
    $len = strlen($digits);
    if ($len <= 4) {
        return str_repeat('•', max(4, $len));
    }

    return str_repeat('•', max(4, $len - 4)) . substr($digits, -4);
}

// Fetch system settings
$settings = [];
try {
    $settings = waamini_whatsapp_load_system_settings($conn);
} catch (Throwable $e) {
    error_log('[WAAMINI broadcast] Exception loading settings: ' . $e->getMessage());
    $settings = [];
}

$isConfigured = !empty($settings['system_wa_token']) && !empty($settings['system_wa_phone_id']);

$error = '';
$success = '';
/** @var ?array{total:int, duplicate_rows:int, sample:list<array{company:string, phone_masked:string}>, filter:string} $preview */
$preview = null;

if (isset($_POST['preview_broadcast'])) {
    csrf_verify();

    $recipientGroup = (string)($_POST['recipients'] ?? 'active_clients');
    if (!in_array($recipientGroup, ['active_clients', 'all_clients'], true)) {
        $recipientGroup = 'active_clients';
    }

    try {
        $result = waamini_broadcast_fetch_recipients($conn, $recipientGroup);
        if (!$result['ok']) {
            $error = htmlspecialchars($result['error'] ?? 'Could not load recipients. Check that the subscriptions table exists.', ENT_QUOTES, 'UTF-8');
            error_log('[WAAMINI broadcast preview] query failed: ' . ($result['error'] ?? 'Unknown'));
        } else {
            $sample = [];
            foreach (array_slice($result['recipients'], 0, 15) as $r) {
                $sample[] = [
                    'company' => htmlspecialchars((string)($r['name'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                    'phone_masked' => waamini_broadcast_mask_phone((string)$r['phone']),
                ];
            }
            $preview = [
                'total' => count($result['recipients']),
                'duplicate_rows' => (int)$result['duplicate_rows'],
                'sample' => $sample,
                'filter' => $recipientGroup,
            ];
        }
    } catch (Throwable $e) {
        error_log('[WAAMINI broadcast preview] Exception: ' . $e->getMessage());
        $error = 'An error occurred while loading recipients.';
    }
}

if (isset($_POST['send_broadcast'])) {
    csrf_verify();

    if (!$isConfigured) {
        $error = 'WhatsApp System Account is not configured. Please go to Configurations → WhatsApp Onboarding first.';
    } else {
        $message = trim((string)($_POST['message'] ?? ''));
        $recipientGroup = (string)($_POST['recipients'] ?? 'active_clients');
        if (!in_array($recipientGroup, ['active_clients', 'all_clients'], true)) {
            $recipientGroup = 'active_clients';
        }

        // Validate message
        if ($message === '') {
            $error = 'Message cannot be empty.';
        } elseif ((function_exists('mb_strlen') ? mb_strlen($message) : strlen($message)) > 4096) {
            $error = 'Message exceeds WhatsApp text limit (4096 characters).';
        } else {
            try {
                $result = waamini_broadcast_fetch_recipients($conn, $recipientGroup);
                if (!$result['ok']) {
                    $error = htmlspecialchars($result['error'] ?? 'Could not load recipients', ENT_QUOTES, 'UTF-8');
                    error_log('[WAAMINI broadcast] query failed: ' . ($result['error'] ?? 'Unknown'));
                } else {
                    $sent = 0;
                    $failed = 0;
                    $skippedDup = (int)$result['duplicate_rows'];
                    $token = (string)($settings['system_wa_token'] ?? '');
                    $phoneId = (string)($settings['system_wa_phone_id'] ?? '');
                    $apiVersion = (string)($settings['wa_default_api_version'] ?? 'v18.0');

                    if ($token === '' || $phoneId === '') {
                        $error = 'WhatsApp credentials are missing. Please reconfigure WhatsApp settings.';
                    } else {
                        foreach ($result['recipients'] as $row) {
                            if (!is_array($row)) {
                                $failed++;
                                continue;
                            }
                            $phoneRaw = (string)($row['phone'] ?? '');
                            $companyName = (string)($row['name'] ?? '');
                            
                            if ($phoneRaw === '') {
                                $failed++;
                                continue;
                            }

                            try {
                                $personalizedMessage = str_replace('{{name}}', $companyName, $message);
                                // Ensure message doesn't exceed limit after personalization
                                if ((function_exists('mb_strlen') ? mb_strlen($personalizedMessage) : strlen($personalizedMessage)) > 4096) {
                                    $failed++;
                                    continue;
                                }

                                $msgId = send_whatsapp_text($phoneRaw, $personalizedMessage, $token, $phoneId, $apiVersion);
                                if ($msgId) {
                                    $sent++;
                                } else {
                                    $failed++;
                                }
                            } catch (Throwable $e) {
                                error_log('[WAAMINI broadcast] Exception sending to ' . $phoneRaw . ': ' . $e->getMessage());
                                $failed++;
                            }
                        }

                        if (($sent + $failed) === 0 && $skippedDup === 0) {
                            $success = 'No recipients matched this filter with a WhatsApp number on file.';
                        } else {
                            $success = 'Broadcast complete! Sent: ' . $sent . ' | Failed: ' . $failed;
                            if ($skippedDup > 0) {
                                $success .= ' | Skipped duplicate numbers: ' . $skippedDup;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[WAAMINI broadcast] Exception during broadcast: ' . $e->getMessage());
                $error = 'An error occurred while sending the broadcast.';
            }
        }
    }
}

$formRecipients = (string)($_POST['recipients'] ?? 'active_clients');
if (!in_array($formRecipients, ['active_clients', 'all_clients'], true)) {
    $formRecipients = 'active_clients';
}
$formMessage = isset($_POST['message']) ? (string)($_POST['message']) : '';

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'send'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'phone'  => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'copy'   => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'gear'   => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
];
// recipient counts for the summary cards (dry run only — nothing is sent)
$cntActive = $cntAll = $cntDup = 0;
try {
    $ra = waamini_broadcast_fetch_recipients($conn, 'active_clients');
    if ($ra['ok']) { $cntActive = count($ra['recipients']); $cntDup = (int)$ra['duplicate_rows']; }
    $rb = waamini_broadcast_fetch_recipients($conn, 'all_clients');
    if ($rb['ok']) { $cntAll = count($rb['recipients']); }
} catch (Throwable $e) { error_log('[WAAMINI broadcast] counts: ' . $e->getMessage()); }
?>

<div class="db bc">

  <div class="db-head">
    <div>
      <h1>Admin Broadcasts</h1>
      <p>Send a WhatsApp message to your clients using the master system account.</p>
    </div>
    <div class="db-head__actions">
      <a href="settings_whatsapp.php" class="db-btn"><?php echo $__ic($P['gear'], 16); ?> WhatsApp setup</a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="bc-alert is-err" role="alert"><?php echo $__ic($P['alert'], 18); ?><div><?php echo $esc($error); ?></div></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="bc-alert is-ok" role="alert"><?php echo $__ic($P['check'], 18); ?><div><?php echo $esc($success); ?></div></div>
  <?php endif; ?>

  <!-- summary -->
  <section class="bc-stats">
    <div class="bc-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['users'], 20); ?></span><div><span>Active &amp; trial recipients</span><b><?php echo number_format($cntActive); ?></b></div></div>
    <div class="bc-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['layers'], 20); ?></span><div><span>All subscribed clients</span><b><?php echo number_format($cntAll); ?></b></div></div>
    <div class="bc-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['copy'], 20); ?></span><div><span>Duplicate numbers skipped</span><b><?php echo number_format($cntDup); ?></b></div></div>
    <div class="bc-stat"><span class="db-kpi__ic <?php echo $isConfigured ? 'is-green' : 'is-pink'; ?>"><?php echo $__ic($P['phone'], 20); ?></span><div><span>Master account</span><b class="bc-st <?php echo $isConfigured ? 'is-ok' : 'is-bad'; ?>"><?php echo $isConfigured ? 'Connected' : 'Not set up'; ?></b></div></div>
  </section>

  <?php if (!$isConfigured): ?>
    <section class="db-card bc-setup">
      <span class="bc-setup__ic"><?php echo $__ic($P['alert'], 26); ?></span>
      <div>
        <h2>Master account not configured</h2>
        <p>You can't send broadcasts or automated expiry reminders until you save your official Meta access token and phone number ID.</p>
      </div>
      <a href="settings_whatsapp.php" class="db-btn db-btn--primary">Go to WhatsApp setup</a>
    </section>
  <?php else: ?>

  <div class="db-row db-row--bottom bc-layout">

    <!-- ===== compose ===== -->
    <section class="db-card">
      <div class="db-card__head"><div><h2>Compose broadcast</h2><p>Choose who receives it and write your message</p></div></div>

      <form method="POST" id="broadcastForm" class="bc-form">
        <?php echo csrf_field(); ?>

        <div class="bc-field">
          <label>Send to</label>
          <div class="bc-aud">
            <label class="bc-opt">
              <input type="radio" name="recipients" value="active_clients" <?php echo $formRecipients === 'active_clients' ? 'checked' : ''; ?>>
              <span class="bc-opt__box"><b>Active &amp; trial clients</b><em><?php echo number_format($cntActive); ?> recipients · recommended</em></span>
            </label>
            <label class="bc-opt">
              <input type="radio" name="recipients" value="all_clients" <?php echo $formRecipients === 'all_clients' ? 'checked' : ''; ?>>
              <span class="bc-opt__box"><b>All subscribed clients</b><em><?php echo number_format($cntAll); ?> recipients · includes expired</em></span>
            </label>
          </div>
        </div>

        <div class="bc-field">
          <div class="bc-label-row">
            <label for="bcMsg">Message</label>
            <button type="button" class="bc-var" id="bcVar" title="Insert the client's company name">+ {{name}}</button>
          </div>
          <textarea name="message" id="bcMsg" class="bc-input" rows="9" placeholder="Hi {{name}}, your subscription renews soon. Reply to this message if you need help."><?php echo $esc($formMessage); ?></textarea>
          <div class="bc-count">
            <div class="bc-count__bar"><i id="bcBar"></i></div>
            <span><b id="charCount">0</b> / 4096 characters</span>
          </div>
          <div class="bc-hint"><?php echo $__ic($P['info'], 15); ?> Use <code>{{name}}</code> to insert each client's company name.</div>
        </div>

        <div class="bc-actions">
          <button type="submit" name="preview_broadcast" value="1" class="db-btn"><?php echo $__ic($P['eye'], 16); ?> Preview recipients</button>
          <button type="submit" name="send_broadcast" value="1" class="db-btn db-btn--primary js-confirm"
                  data-confirm="Ready to send this message to your clients? Outside the 24-hour window WhatsApp only delivers APPROVED templates."><?php echo $__ic($P['send'], 16); ?> Send broadcast</button>
        </div>
      </form>
    </section>

    <!-- ===== side ===== -->
    <div class="bc-side">

      <section class="db-card">
        <div class="db-card__head"><div><h2>Live preview</h2><p>How clients will see it</p></div></div>
        <div class="bc-phone">
          <div class="bc-phone__bar"><span class="bc-phone__av"><?php echo $__ic($P['phone'], 16); ?></span><div><b>Waamini</b><small>Master account</small></div></div>
          <div class="bc-phone__chat">
            <div class="bc-bubble" id="bcPrev"><span class="bc-bubble__ph">Your message will appear here.</span><small>10:42 AM</small></div>
          </div>
        </div>
        <div class="bc-note"><?php echo $__ic($P['info'], 16); ?> <div>Sample company name shown as <b>Acme Corp</b>. WhatsApp delivers free-text messages only inside the 24-hour customer window.</div></div>
      </section>

      <?php if ($preview): ?>
      <section class="db-card">
        <div class="db-card__head"><div><h2>Recipient preview</h2><p>Dry run, nothing was sent</p></div><span class="db-pill is-info"><?php echo (int)$preview['total']; ?> numbers</span></div>
        <div class="db-list" style="padding-top:6px">
          <?php if ($preview['total'] === 0): ?>
            <div class="db-empty" style="padding:22px 8px">No matching subscriptions with a WhatsApp number.</div>
          <?php else: foreach ((array)$preview['sample'] as $row): if (!is_array($row)) continue; ?>
            <div class="db-li">
              <span class="db-li__ic"><?php echo $esc(strtoupper(substr(html_entity_decode((string)($row['company'] ?? '?'), ENT_QUOTES, 'UTF-8'), 0, 1))); ?></span>
              <div class="db-li__t"><b><?php echo (string)($row['company'] ?? '—'); ?></b><small><?php echo $esc($row['phone_masked'] ?? ''); ?></small></div>
            </div>
          <?php endforeach; if ($preview['total'] > 15): ?>
            <div class="db-empty" style="padding:14px 8px">…and <?php echo (int)($preview['total'] - 15); ?> more.</div>
          <?php endif; endif; ?>
        </div>
        <div class="bc-note" style="margin-top:4px"><?php echo $__ic($P['info'], 16); ?> <div>Filter: <b><?php echo $preview['filter'] === 'all_clients' ? 'All clients' : 'Active &amp; trial'; ?></b><?php echo $preview['duplicate_rows'] > 0 ? ' · ' . (int)$preview['duplicate_rows'] . ' duplicate rows skipped' : ''; ?></div></div>
      </section>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
.bc{max-width:none;margin:0}
.bc-alert{display:flex;align-items:flex-start;gap:11px;margin-bottom:16px;padding:13px 16px;border-radius:13px;font-size:14.5px;line-height:1.5;font-weight:600}
.bc-alert .db-ic{margin-top:2px}
.bc-alert.is-ok{background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E}
.bc-alert.is-err{background:#FEF0EE;border:1px solid #F8C9C2;color:#B42318}
.bc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.bc-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:0}
.bc-stat .db-kpi__ic{margin:0}
.bc-stat>div{min-width:0}
.bc-stat span:not(.db-kpi__ic){display:block;font-size:12.5px;font-weight:600;color:var(--mu)}
.bc-stat b{display:block;margin-top:1px;font-size:26px;font-weight:800;letter-spacing:-.5px;color:var(--ink);white-space:nowrap}
.bc-st{font-size:20px!important}.bc-st.is-ok{color:#12803f}.bc-st.is-bad{color:#c0372b}

.bc-setup{display:flex;align-items:center;gap:18px;padding:22px 24px;flex-wrap:wrap}
.bc-setup h2{margin:0 0 4px;font-size:17.5px;font-weight:800}
.bc-setup p{margin:0;font-size:14.5px;color:var(--tx);line-height:1.55;max-width:560px}
.bc-setup>div{flex:1;min-width:240px}
.bc-setup__ic{width:54px;height:54px;border-radius:16px;background:#FDE4E1;color:#c0372b;display:grid;place-items:center;flex:none}

.bc-layout{align-items:start}
.bc-side{display:flex;flex-direction:column;gap:18px;min-width:0}
.bc-form{padding:18px 20px 22px}
.bc-field{margin-bottom:20px}
.bc-field>label,.bc-label-row label{display:block;margin-bottom:9px;font-size:14px;font-weight:700;color:var(--ink)}
.bc-label-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
.bc-label-row label{margin-bottom:9px}

.bc-aud{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.bc-opt{position:relative;cursor:pointer;margin:0!important}
.bc-opt input{position:absolute;opacity:0;pointer-events:none}
.bc-opt__box{display:block;padding:14px 16px 14px 46px;border:1.5px solid var(--line);border-radius:13px;background:#fff;transition:border-color .2s,background .2s,box-shadow .2s;position:relative}
.bc-opt__box::before{content:'';position:absolute;left:16px;top:17px;width:18px;height:18px;border-radius:50%;border:2px solid #b7c6c0;background:#fff;transition:border-color .2s,background .2s,box-shadow .2s}
.bc-opt__box b{display:block;font-size:14.5px;color:var(--ink)}
.bc-opt__box em{display:block;margin-top:3px;font-style:normal;font-size:12.5px;color:var(--tx)}
.bc-opt:hover .bc-opt__box{border-color:#9ED9BF}
.bc-opt input:checked+.bc-opt__box{border-color:var(--g);background:var(--soft);box-shadow:0 10px 22px -16px rgba(18,140,126,.6)}
.bc-opt input:checked+.bc-opt__box::before{border-color:var(--g);background:var(--g);box-shadow:inset 0 0 0 3px #fff}
.bc-opt input:focus-visible+.bc-opt__box{outline:2px solid var(--g);outline-offset:2px}

.bc-var{padding:5px 12px;border:1.5px solid #BFEBD8;border-radius:8px;background:var(--soft);font:700 12.5px ui-monospace,Menlo,Consolas,monospace;color:#0B6B5E;cursor:pointer;margin-bottom:9px}
.bc-var:hover{background:var(--g);border-color:var(--g);color:#fff}
.bc-input{width:100%;padding:13px 15px;border:1.5px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);font:500 15px/1.6 Inter,system-ui,sans-serif;resize:vertical;min-height:170px;transition:border-color .2s,box-shadow .2s}
.bc-input:focus{outline:0;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.bc-count{display:flex;align-items:center;gap:12px;margin-top:10px;font-size:13px;color:var(--tx)}
.bc-count__bar{flex:1;height:6px;border-radius:4px;background:#E8F1ED;overflow:hidden}
.bc-count__bar i{display:block;height:100%;width:0;border-radius:4px;background:linear-gradient(90deg,var(--g),var(--g2));transition:width .2s,background .2s}
.bc-count b{color:var(--ink)}
.bc-hint{display:flex;align-items:center;gap:7px;margin-top:9px;font-size:13px;color:var(--mu)}
.bc-hint code{padding:1px 7px;border-radius:6px;background:var(--soft);color:#0B6B5E;font-size:12.5px}
.bc-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;padding-top:6px;border-top:1px solid #EEF3F1;margin-top:6px;padding-top:18px}
.bc-actions .db-btn{height:46px;padding:0 22px;font-size:14.5px}

.bc-phone{margin:14px 20px 0;border-radius:16px;overflow:hidden;border:1px solid var(--line)}
.bc-phone__bar{display:flex;align-items:center;gap:11px;padding:11px 14px;background:#128C7E;color:#fff}
.bc-phone__bar b{display:block;font-size:14.5px;line-height:1.2}
.bc-phone__bar small{display:block;font-size:12px;opacity:.85}
.bc-phone__av{width:34px;height:34px;border-radius:50%;background:#25D366;display:grid;place-items:center;flex:none}
.bc-phone__chat{padding:16px 14px 18px;min-height:150px;background:#f4efe6 url(../img/wa-doodle.jpg) repeat;background-size:360px auto}
.bc-bubble{position:relative;max-width:90%;margin-left:0;padding:8px 11px 22px;border-radius:0 9px 9px 9px;background:#fff;box-shadow:0 1px 1px rgba(11,20,26,.15);font-size:14px;line-height:1.5;color:#111b21;white-space:pre-wrap;word-break:break-word}
.bc-bubble::before{content:'';position:absolute;top:0;left:-8px;width:9px;height:12px;background:#fff;clip-path:polygon(100% 0,100% 100%,0 0)}
.bc-bubble small{position:absolute;right:9px;bottom:4px;font-size:10.5px;color:#667781}
.bc-bubble__ph{color:#8696a0}
.bc-note{display:flex;gap:10px;margin:14px 20px 18px;padding:12px 14px;border-radius:12px;background:var(--soft);border:1px solid var(--line);font-size:13px;line-height:1.55;color:var(--tx)}
.bc-note .db-ic{color:var(--g);margin-top:2px}
.bc-note b{color:var(--ink)}

@media (max-width:1100px){.bc-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.bc-stats{grid-template-columns:1fr}.bc-aud{grid-template-columns:1fr}.bc-actions{flex-direction:column-reverse}.bc-actions .db-btn{width:100%;justify-content:center}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    var field = document.getElementById('bcMsg');
    var count = document.getElementById('charCount');
    var bar = document.getElementById('bcBar');
    var prev = document.getElementById('bcPrev');
    var LIMIT = 4096;

    function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function update() {
        if (!field) return;
        var len = field.value.length;
        if (count) { count.textContent = String(len); count.style.color = len > LIMIT ? '#c0372b' : (len > LIMIT * 0.9 ? '#b7791f' : ''); }
        if (bar) { bar.style.width = Math.min(100, len / LIMIT * 100) + '%'; bar.style.background = len > LIMIT ? '#e5484d' : (len > LIMIT * 0.9 ? '#F59E0B' : ''); }
        if (prev) {
            var t = field.value.trim();
            var stamp = '<small>10:42 AM</small>';
            prev.innerHTML = t ? esc(t.replace(/\{\{\s*name\s*\}\}/g, 'Acme Corp')) + stamp : '<span class="bc-bubble__ph">Your message will appear here.</span>' + stamp;
        }
    }
    if (field) {
        field.addEventListener('input', update);
        field.addEventListener('change', update);
        update();
    }

    var v = document.getElementById('bcVar');
    if (v && field) v.addEventListener('click', function () {
        var s = field.selectionStart, e = field.selectionEnd, t = '{{name}}';
        field.value = field.value.slice(0, s) + t + field.value.slice(e);
        field.focus(); field.selectionStart = field.selectionEnd = s + t.length;
        update();
    });

    // Confirm dialogs on buttons/links with data-confirm (CSP-safe, no inline onclick)
    document.addEventListener('click', function (e) {
        var el = e.target.closest('.js-confirm[data-confirm]');
        if (el && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>

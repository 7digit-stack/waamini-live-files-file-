<?php
/**
 * In-app WhatsApp connection prompts for the client panel.
 *   none : no WhatsApp number linked yet        -> "Connect your WhatsApp Business number"
 *   lost : linked, but Meta rejects our access  -> "WhatsApp connection lost"
 *   ok   : nothing to show
 * The live check is a single 3-second Graph call, cached per company in the session for 10 minutes.
 * Only an explicit rejection from Meta (HTTP 400/401/403 with an error) counts as "lost";
 * timeouts and network problems never raise a false alarm.
 */

function wa_connection_state(mysqli $conn, int $companyId): string
{
    if ($companyId <= 0) return 'ok';
    $ck = '_wa_conn_' . $companyId;
    if (!empty($_SESSION[$ck]) && (time() - (int)$_SESSION[$ck]['at']) < 600) return (string)$_SESSION[$ck]['state'];

    $state = 'ok';
    try {
        $st = $conn->prepare("SELECT phone_number_id, access_token FROM whatsapp_config WHERE company_id = ? ORDER BY id DESC LIMIT 1");
        $st->bind_param('i', $companyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row || trim((string)$row['phone_number_id']) === '' || trim((string)$row['access_token']) === '') {
            $state = 'none';
        } else {
            if (!function_exists('decrypt_secret')) { @include_once __DIR__ . '/security.php'; }
            $token = function_exists('decrypt_secret') ? (decrypt_secret((string)$row['access_token']) ?? (string)$row['access_token']) : (string)$row['access_token'];
            $ch = curl_init('https://graph.facebook.com/v18.0/' . rawurlencode(trim((string)$row['phone_number_id'])) . '?fields=id');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim($token)]]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res !== false && in_array($code, [400, 401, 403], true)) {
                $j = json_decode((string)$res, true);
                if (!empty($j['error'])) $state = 'lost';
            }
        }
    } catch (\Throwable $e) {
        error_log('[wa_connection_state] ' . $e->getMessage());
    }
    $_SESSION[$ck] = ['state' => $state, 'at' => time()];
    return $state;
}

/** Echo the prompt card (nothing when the connection is fine). */
function wa_connection_notice_render(string $state, string $connectUrl, int $companyId): void
{
    if ($state !== 'none' && $state !== 'lost') return;
    $lost = $state === 'lost';
    $title = $lost ? 'WhatsApp connection lost' : 'Connect your WhatsApp Business number';
    $msg = $lost
        ? 'We could not reach your WhatsApp Business account. Messages will not send or arrive until you reconnect.'
        : 'Your account is ready, but no WhatsApp number is linked yet. Connect it to start sending broadcasts and replying to customers.';
    $primary = $lost ? 'Reconnect' : 'Connect now';
    $secondary = $lost ? 'Dismiss' : 'Later';
    $key = 'wa_notice_' . $state . '_' . $companyId;
    $tile = $lost ? '#FEF2F2' : '#E6FCF2';
    $ink  = $lost ? '#DC2626' : '#128C7E';
    $icon = $lost
        ? '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'
        : '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>';
    ?>
    <div id="waNotice" role="<?php echo $lost ? 'alert' : 'status'; ?>" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:#fff;border-radius:16px;padding:16px 20px;margin-bottom:16px;box-shadow:0 4px 18px rgba(17,24,39,.06);">
        <div style="width:44px;height:44px;border-radius:12px;background:<?php echo $tile; ?>;color:<?php echo $ink; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icon; ?></svg>
        </div>
        <div style="flex:1;min-width:240px;">
            <div style="font-weight:700;font-size:15px;color:#111827;"><?php echo htmlspecialchars($title); ?></div>
            <div style="font-size:13px;color:#4B5563;line-height:1.5;margin-top:2px;"><?php echo htmlspecialchars($msg); ?></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <a href="<?php echo htmlspecialchars($connectUrl, ENT_QUOTES); ?>" style="background:#128C7E;color:#fff;text-decoration:none;font-weight:600;font-size:13px;padding:9px 18px;border-radius:999px;"><?php echo $primary; ?></a>
            <button type="button" id="waNoticeClose" style="background:#F3F4F6;color:#374151;border:0;font-weight:600;font-size:13px;padding:9px 16px;border-radius:999px;cursor:pointer;"><?php echo $secondary; ?></button>
        </div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('waNotice'), k = <?php echo json_encode($key); ?>;
        try { var until = parseInt(localStorage.getItem(k) || '0', 10); if (until > Date.now()) { box.style.display = 'none'; return; } } catch (e) {}
        document.getElementById('waNoticeClose').addEventListener('click', function () {
            box.style.display = 'none';
            try { localStorage.setItem(k, String(Date.now() + <?php echo $lost ? 6 : 24; ?> * 3600 * 1000)); } catch (e) {}
        });
    })();
    </script>
    <?php
}

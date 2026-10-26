<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/wa_chat_helpers.php';
require_once __DIR__ . '/../includes/chat_message_render.php';
wa_chat_ensure_meta_column($conn);
// Ads & Attribution: lead_stage / won_value / lead_source columns (one-time, flag-file guarded)
require_once __DIR__ . '/../includes/ads/schema.php';
require_once __DIR__ . '/../includes/ads/helpers.php';
ads_ensure_schema($conn);

$pageTitle = 'WhatsApp Chat';
$currentPage = 'whatsapp';

if (!has_feature('whatsapp_chat')) {
    include '../includes/client_header.php';
    ?>
    <div class="form-card" style="max-width:none;text-align:center;padding:40px;">
        <h2 style="margin-bottom:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Upgrade Required</h2>
        <p style="color:var(--text-secondary);margin:0 0 16px 0;">WhatsApp Chat is not available on your current plan. Upgrade to unlock the inbox.</p>
        <a href="subscription.php" class="btn btn-primary">View plans</a>
    </div>
    <?php
    include '../includes/client_footer.php';
    exit();
}

// View-only / restricted:
// - allow viewing
// - block sending messages (handled via api/send_message.php)
// - BUT still allow CRM updates + outcome buttons to save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($viewOnlyMode) || !empty($isRestricted))) {
    $isCrmPost = isset($_POST['update_crm']) || isset($_POST['wa_outcome']);
    if (!$isCrmPost) {
        header('Location: whatsapp.php' . (isset($_GET['c']) ? ('?c=' . (int)$_GET['c']) : ''));
        exit();
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function wa_relative_time(?string $datetime): string {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '';
    $t = strtotime($datetime);
    if (!$t) return '';
    $diff = time() - $t;
    if ($diff <= 0)      return 'just now';
    if ($diff < 60)      return $diff . 's ago';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    $w = floor($diff/604800);
    $d = floor(($diff%604800)/86400);
    return $w . 'w' . ($d ? (' ' . $d . 'd') : '') . ' ago';
}

function wa_avatar_color(string $name): string {
    $colours = ['#25D366','#34B7F1','#6C63FF','#FF6B6B','#F9CA24','#F0932B','#00B894','#E84393','#6AB04C','#EB4D4B','#7ED6DF','#e55039'];
    return $colours[abs(crc32($name ?: 'U')) % count($colours)];
}

function wa_initials(string $nameOrPhone): string {
    $s = strtoupper($nameOrPhone);
    $s = preg_replace('/[^A-Z0-9]+/', '', $s);
    if ($s === '') $s = 'U';
    return substr($s, 0, 2);
}

function wa_labels_parse(string $labels): array {
    $out = [];
    foreach (explode(',', $labels) as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $k = mb_strtolower($p, 'UTF-8');
        $out[$k] = $p; // keep original casing of last occurrence
    }
    return $out;
}

function wa_labels_string(array $map): string {
    return implode(', ', array_values($map));
}

function wa_labels_set_outcome(string $existing, string $outcomeLabel): string {
    // Ensure a single outcome label exists, and remove other outcome labels.
    $map = wa_labels_parse($existing);
    $outcomes = ['follow-up', 'converted', 'declined', 'not interested', 'not_interested'];
    foreach ($outcomes as $o) {
        unset($map[$o]);
    }
    $k = mb_strtolower($outcomeLabel, 'UTF-8');
    $map[$k] = $outcomeLabel;
    return wa_labels_string($map);
}

function wa_labels_get_outcome(string $existing): string {
    $map = wa_labels_parse($existing);
    foreach (['converted' => 'Converted', 'declined' => 'Declined', 'not interested' => 'Not Interested', 'follow-up' => 'Follow-up'] as $k => $v) {
        if (isset($map[$k])) return $v;
    }
    return '';
}

/**
 * Lead Automation (lead_automation.php) → automation_queue scheduling.
 * This is separate from Drip Campaigns (premium_drip.php) which use drip_executions.
 */
function wa_lead_automation_settings(mysqli $conn, int $companyId): array {
    $defaults = [
        'followup_msg_1' => "Hi, just following up on our previous conversation. Do you have any questions?",
        'followup_days_1' => 3,
        'followup_msg_2' => "Checking in again regarding your interest in WAAMINI. How can we help you get started?",
        'followup_days_2' => 7,
        'thanks_msg' => "Thank you for getting in touch with us. We understand this isn't the right time for you. Please feel free to reach out if your requirements change in the future!",
        'converted_msg' => "Thanks for choosing us! We are excited to have you onboard. Let us know if you need any assistance.",
    ];

    $st = $conn->prepare("SELECT * FROM lead_automation_settings WHERE company_id=? LIMIT 1");
    if (!$st) return $defaults;
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!is_array($row)) return $defaults;
    return array_merge($defaults, $row);
}

function wa_queue_insert(mysqli $conn, int $companyId, int $contactId, string $type, string $message, int $delaySeconds): void {
    $type = trim($type);
    $message = trim($message);
    if ($type === '' || $message === '' || $companyId <= 0 || $contactId <= 0) return;

    // Derive phone from contacts (cron_automation_sender LEFT JOIN contacts supports this)
    $scheduledAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
    $st = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
    if ($st) {
        $st->bind_param('iisss', $companyId, $contactId, $type, $message, $scheduledAt);
        $st->execute();
        $st->close();
    }
}

function wa_queue_cancel_pending(mysqli $conn, int $companyId, int $contactId, string $reason): void {
    // Cancel only lead-automation followups (keep ai_reply/bot_reply/etc untouched)
    $reason = trim($reason);
    $reason = $reason !== '' ? $reason : 'outcome_changed';
    $st = $conn->prepare("UPDATE automation_queue SET status='failed', error_msg=? WHERE company_id=? AND contact_id=? AND status='pending' AND type IN ('followup_1','followup_2','delayed','instant')");
    if ($st) {
        $st->bind_param('sii', $reason, $companyId, $contactId);
        $st->execute();
        $st->close();
    }
}

// ── Inputs ────────────────────────────────────────────────────────────────
$activeContactId = isset($_GET['c']) ? (int)$_GET['c'] : 0;
$filterTabRaw = (string)($_GET['tab'] ?? 'all');
$filterTab = in_array($filterTabRaw, ['all', 'mine', 'unassigned'], true) ? $filterTabRaw : 'all';
$myUserId = (int)($_SESSION['user_id'] ?? 0);

// ── Lead outcome quick actions (follow-up / converted / declined / not interested)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wa_outcome']) && $activeContactId > 0) {
    csrf_verify();
    $outcome = strtolower(trim((string)($_POST['wa_outcome'] ?? '')));
    $label = '';
    if ($outcome === 'followup' || $outcome === 'follow-up') $label = 'Follow-up';
    elseif ($outcome === 'converted') $label = 'Converted';
    elseif ($outcome === 'declined') $label = 'Declined';
    elseif ($outcome === 'not_interested' || $outcome === 'not interested') $label = 'Not Interested';

    if ($label !== '') {
        // Load existing labels so we can merge safely
        $curLabels = '';
        $stGet = $conn->prepare("SELECT chat_labels FROM contacts WHERE id=? AND company_id=? LIMIT 1");
        if ($stGet) {
            $stGet->bind_param("ii", $activeContactId, $companyId);
            $stGet->execute();
            $curLabels = (string)($stGet->get_result()->fetch_assoc()['chat_labels'] ?? '');
            $stGet->close();
        }

        $newLabels = wa_labels_set_outcome($curLabels, $label);
        $stUp = $conn->prepare("UPDATE contacts SET chat_labels=? WHERE id=? AND company_id=?");
        if ($stUp) {
            $stUp->bind_param("sii", $newLabels, $activeContactId, $companyId);
            $stUp->execute();
            $stUp->close();
            wa_cache_bump("company:{$companyId}");
        }
        // Ads & Attribution: pipeline stage (Ad Leads reports) + Meta Conversions API event when enabled
        ads_set_stage($conn, (int)$companyId, (int)$activeContactId, ads_stage_for_outcome($label));

        // Lead Automation (lead_automation.php) — schedule messages into automation_queue
        // This is independent from Drip Campaigns.
        try {
            $la = wa_lead_automation_settings($conn, (int)$companyId);
            // Always clear previous pending lead-automation followups when outcome changes
            wa_queue_cancel_pending($conn, (int)$companyId, (int)$activeContactId, 'lead_outcome_' . strtolower(str_replace(' ', '_', $label)));

            if ($label === 'Follow-up') {
                wa_queue_insert($conn, (int)$companyId, (int)$activeContactId, 'followup_1', (string)$la['followup_msg_1'], (int)$la['followup_days_1'] * 86400);
                wa_queue_insert($conn, (int)$companyId, (int)$activeContactId, 'followup_2', (string)$la['followup_msg_2'], (int)$la['followup_days_2'] * 86400);
            } elseif ($label === 'Converted') {
                wa_queue_insert($conn, (int)$companyId, (int)$activeContactId, 'instant', (string)$la['converted_msg'], 0);
            } elseif ($label === 'Declined' || $label === 'Not Interested') {
                // 24h delay thank-you
                wa_queue_insert($conn, (int)$companyId, (int)$activeContactId, 'delayed', (string)$la['thanks_msg'], 86400);
            }
        } catch (\Throwable $e) {
            // best-effort only
        }

        // Trigger drip scheduling for any label change. 
        // drip_engine.php will check if the labels match any ACTIVE drip campaign trigger.
        require_once __DIR__ . '/../includes/drip_engine.php';
        trigger_drip_campaigns($conn, (int)$companyId, (int)$activeContactId, (string)$newLabels);

        // Optionally still stop pending drips if the new label is 'Converted' or 'Declined'
        // and there isn't a specific drip campaign for those labels.
        // But for now, let's just let it trigger.
    }

    $crmParam = (isset($_GET['crm']) && $_GET['crm'] === 'open') ? '&crm=open' : '';
    header("Location: whatsapp.php?c=" . (int)$activeContactId . "&tab=" . urlencode($filterTab) . $crmParam);
    exit();
}

// ── CRM panel updates (assignment / labels / notes) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_crm']) && $activeContactId > 0) {
    csrf_verify();
    $assignTo = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : 0;
    $contactNameIn = trim((string)($_POST['contact_name'] ?? ''));
    $labelsRaw = (string)($_POST['chat_labels'] ?? '');
    $notesRaw  = (string)($_POST['notes'] ?? '');

    $contactName = trim(str_replace("\0", '', $contactNameIn));
    if ($contactName === '') {
        // Keep existing if user leaves blank
        $contactName = (string)($activeContact['name'] ?? '');
    }
    if (function_exists('mb_strlen') && mb_strlen($contactName, 'UTF-8') > 120) $contactName = mb_substr($contactName, 0, 120, 'UTF-8');
    elseif (strlen($contactName) > 120) $contactName = substr($contactName, 0, 120);

    $labels = trim(str_replace("\0", '', $labelsRaw));
    $notes  = trim(str_replace("\0", '', $notesRaw));

    // keep within reasonable limits for TEXT fields
    if (function_exists('mb_strlen') && mb_strlen($labels, 'UTF-8') > 1000) $labels = mb_substr($labels, 0, 1000, 'UTF-8');
    elseif (strlen($labels) > 1000) $labels = substr($labels, 0, 1000);
    if (function_exists('mb_strlen') && mb_strlen($notes, 'UTF-8') > 65000) $notes = mb_substr($notes, 0, 65000, 'UTF-8');
    elseif (strlen($notes) > 65000) $notes = substr($notes, 0, 65000);

    $stUp = $conn->prepare("UPDATE contacts SET name=?, assigned_to=?, chat_labels=?, notes=? WHERE id=? AND company_id=?");
    if ($stUp) {
        $stUp->bind_param("sissii", $contactName, $assignTo, $labels, $notes, $activeContactId, $companyId);
        $stUp->execute();
        $stUp->close();
        wa_cache_bump("company:{$companyId}");
    }

    // Sale value (₹) for won leads — Ad Leads adds it up per ad. Empty clears it.
    if (array_key_exists('won_value', $_POST) && ads_columns_ready($conn)) {
        $wvRaw = trim(str_replace([',', '₹', ' '], '', (string)$_POST['won_value']));
        $wv = ($wvRaw !== '' && is_numeric($wvRaw) && (float)$wvRaw >= 0) ? round(min((float)$wvRaw, 9999999999.99), 2) : null;
        $stWv = $conn->prepare("UPDATE contacts SET won_value=? WHERE id=? AND company_id=?");
        if ($stWv) { $stWv->bind_param('dii', $wv, $activeContactId, $companyId); $stWv->execute(); }
    }

    // Group (only when the picker was on the form). 0 = no group; any other id must belong to this company.
    if (array_key_exists('group_id', $_POST)) {
        $gid = (int)$_POST['group_id'];
        if ($gid > 0) {
            $stG = $conn->prepare("SELECT id FROM contact_groups WHERE id=? AND company_id=? LIMIT 1");
            $stG->bind_param('ii', $gid, $companyId);
            $stG->execute();
            if (!$stG->get_result()->fetch_row()) { $gid = 0; }
            $stG->close();
        }
        $stG = $gid > 0
            ? $conn->prepare("UPDATE contacts SET group_id=? WHERE id=? AND company_id=?")
            : $conn->prepare("UPDATE contacts SET group_id=NULL WHERE id=? AND company_id=?");
        if ($stG) {
            if ($gid > 0) { $stG->bind_param('iii', $gid, $activeContactId, $companyId); } else { $stG->bind_param('ii', $activeContactId, $companyId); }
            $stG->execute();
            $stG->close();
        }
    }

    // If user manually edited labels, (re)trigger drip scheduling based on those labels.
    // This is how premium_drip decides who enters a sequence (trigger label match).
    require_once __DIR__ . '/../includes/drip_engine.php';
    trigger_drip_campaigns($conn, (int)$companyId, (int)$activeContactId, (string)$labels);

    $crmParam = (isset($_GET['crm']) && $_GET['crm'] === 'open') ? '&crm=open' : '';
    header("Location: whatsapp.php?c=" . (int)$activeContactId . "&tab=" . urlencode($filterTab) . $crmParam);
    exit();
}

// Mark active contact messages as read
if ($activeContactId > 0 && empty($viewOnlyMode) && empty($isRestricted)) {
    $st = $conn->prepare("UPDATE messages SET is_read=1 WHERE company_id=? AND contact_id=? AND direction='incoming' AND is_read=0");
    if ($st) {
        $st->bind_param("ii", $companyId, $activeContactId);
        $st->execute();
        $st->close();
    }
}

// Tab counts (single query)
$tabCounts = ['all' => 0, 'mine' => 0, 'unassigned' => 0];
$stCounts = $conn->prepare("
    SELECT
      COUNT(*) AS all_c,
      SUM(CASE WHEN assigned_to = ? THEN 1 ELSE 0 END) AS mine_c,
      SUM(CASE WHEN (assigned_to IS NULL OR assigned_to = 0) THEN 1 ELSE 0 END) AS unassigned_c
    FROM contacts
    WHERE company_id = ?
");
if ($stCounts) {
    $stCounts->bind_param("ii", $myUserId, $companyId);
    $stCounts->execute();
    $row = $stCounts->get_result()->fetch_assoc();
    $tabCounts['all'] = (int)($row['all_c'] ?? 0);
    $tabCounts['mine'] = (int)($row['mine_c'] ?? 0);
    $tabCounts['unassigned'] = (int)($row['unassigned_c'] ?? 0);
    $stCounts->close();
}

// Contact list: single optimized query (no N+1 / no correlated subqueries)
$contacts = [];
$where = "c.company_id = ?";
$types = "i";
$params = [$companyId];
if ($filterTab === 'mine') {
    $where .= " AND c.assigned_to = ?";
    $types .= "i";
    $params[] = $myUserId;
} elseif ($filterTab === 'unassigned') {
    $where .= " AND (c.assigned_to IS NULL OR c.assigned_to = 0)";
}

$sqlContacts = "
  SELECT
    c.id, c.name, c.phone, c.chat_labels,
    cv.last_message_at AS last_message_at,
    COALESCE(cv.unread_count, 0) AS unread_count,
    COALESCE(cv.last_message, '') AS last_msg_preview
  FROM chat_conversations cv FORCE INDEX (idx_chat_conv_company)
  STRAIGHT_JOIN contacts c
    ON c.id = cv.contact_id AND c.company_id = cv.company_id
  WHERE cv.company_id = ? " . ($filterTab === 'mine' ? " AND c.assigned_to = ? " : ($filterTab === 'unassigned' ? " AND (c.assigned_to IS NULL OR c.assigned_to=0) " : "")) . "
  ORDER BY cv.last_message_at DESC
  LIMIT 100
";

$stList = $conn->prepare($sqlContacts);
if ($stList) {
    if ($filterTab === 'mine') {
        $stList->bind_param("ii", $companyId, $myUserId);
    } else {
        $stList->bind_param("i", $companyId);
    }
    $stList->execute();
    $res = $stList->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) $contacts[] = $r;
    }
    $stList->close();
}

// Active contact details + recent messages (LIMIT!)
$activeContact = null;
$messages = [];
$lastMsgId = 0;
$teamMembers = [];
$quickReplies = [];
$lastInboundAt = null;
if ($activeContactId > 0) {
    $adsCols = ads_columns_ready($conn) ? ', lead_source, lead_stage, won_value' : '';
    $stC = $conn->prepare("SELECT id, name, phone, chat_labels, notes, assigned_to, group_id, email, client_company, city, ai_lead_score, ai_sentiment, language, ai_score_updated{$adsCols} FROM contacts WHERE id=? AND company_id=? LIMIT 1");
    if (!$stC) {
        // A missing optional column (schema not migrated) must not stop the chat from opening.
        error_log('[WAAMINI whatsapp] contact query failed, using basic columns: ' . $conn->error);
        $stC = $conn->prepare("SELECT * FROM contacts WHERE id=? AND company_id=? LIMIT 1");
    }
    if ($stC) {
        $stC->bind_param("ii", $activeContactId, $companyId);
        $stC->execute();
        $activeContact = $stC->get_result()->fetch_assoc() ?: null;
        $stC->close();
    }

    if ($activeContact) {
        // Last inbound message time for 24-hour window indicator
        try {
            $stLi = $conn->prepare("SELECT MAX(created_at) AS last_in FROM messages WHERE company_id=? AND contact_id=? AND direction='incoming' LIMIT 1");
            if ($stLi) {
                $stLi->bind_param("ii", $companyId, $activeContactId);
                $stLi->execute();
                $lastInboundAt = $stLi->get_result()->fetch_assoc()['last_in'] ?? null;
                $stLi->close();
            }
        } catch (\Throwable $e) {
            $lastInboundAt = null;
        }

        // Team members for assignment dropdown (optional)
        try {
            $stTm = $conn->prepare("SELECT id, name, role FROM team_members WHERE company_id=? AND status='active' ORDER BY name ASC LIMIT 200");
            if ($stTm) {
                $stTm->bind_param("i", $companyId);
                $stTm->execute();
                $resTm = $stTm->get_result();
                if ($resTm) {
                    while ($t = $resTm->fetch_assoc()) $teamMembers[] = $t;
                }
                $stTm->close();
            }
        } catch (\Throwable $e) {
            $teamMembers = [];
        }

        // Contact groups for the Group picker (same contacts.group_id the Groups page and campaigns use)
        $contactGroups = [];
        try {
            $stGr = $conn->prepare("SELECT id, name, color FROM contact_groups WHERE company_id=? ORDER BY name ASC LIMIT 300");
            if ($stGr) {
                $stGr->bind_param('i', $companyId);
                $stGr->execute();
                $contactGroups = $stGr->get_result()->fetch_all(MYSQLI_ASSOC);
                $stGr->close();
            }
        } catch (\Throwable $e) {
            $contactGroups = [];
        }

        // Quick replies list (optional feature)
        try {
            if (function_exists('has_feature') && has_feature('quick_replies')) {
                $stQr = $conn->prepare("SELECT shortcut, message FROM quick_replies WHERE company_id=? ORDER BY shortcut ASC LIMIT 300");
                if ($stQr) {
                    $stQr->bind_param("i", $companyId);
                    $stQr->execute();
                    $resQr = $stQr->get_result();
                    if ($resQr) {
                        while ($qr = $resQr->fetch_assoc()) $quickReplies[] = $qr;
                    }
                    $stQr->close();
                }
            }
        } catch (\Throwable $e) {
            $quickReplies = [];
        }

        $stM = $conn->prepare("
          SELECT id, direction, type, content, message, media_url, meta_json, status, error_message, created_at
          FROM messages
          WHERE company_id=? AND contact_id=?
          ORDER BY id DESC
          LIMIT 200
        ");
        if ($stM) {
            $stM->bind_param("ii", $companyId, $activeContactId);
            $stM->execute();
            $resM = $stM->get_result();
            $tmp = [];
            if ($resM) {
                while ($m = $resM->fetch_assoc()) {
                    $tmp[] = $m;
                    $lastMsgId = max($lastMsgId, (int)$m['id']);
                }
            }
            $messages = array_reverse($tmp); // oldest->newest
            $stM->close();
        }

        // Products for the "Quote" attach option (moved here from the old Multi-Agent Chat page)
        $quoteProducts = [];
        try {
            $stQp = $conn->prepare("SELECT name, price FROM products WHERE company_id=? ORDER BY name ASC LIMIT 300");
            if ($stQp) {
                $stQp->bind_param('i', $companyId);
                $stQp->execute();
                $quoteProducts = $stQp->get_result()->fetch_all(MYSQLI_ASSOC);
                $stQp->close();
            }
        } catch (\Throwable $e) {
            $quoteProducts = [];
        }
    }
}

include '../includes/client_header.php';

$waNonce = htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8');
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tabQ = '&tab=' . urlencode($filterTab);
$canSend = empty($viewOnlyMode) && empty($isRestricted);
$meName = (string)($_SESSION['name'] ?? 'Me');

// 24-hour customer service window (shown like WhatsApp's "online" line under the name)
$windowOpen = false;
$windowText = 'No message from this customer yet';
if ($activeContact && $lastInboundAt && ($tsIn = strtotime((string)$lastInboundAt))) {
    $remaining = 86400 - (time() - $tsIn);
    if ($remaining > 0) {
        $windowOpen = true;
        $windowText = 'Reply window open · ' . (int)floor($remaining / 3600) . 'h ' . (int)floor(($remaining % 3600) / 60) . 'm left';
    } else {
        $windowText = 'Reply window closed · templates only';
    }
}

$ico = [
    'chats'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>',
    'cast'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.364 18.364a9 9 0 0 0 0-12.728"/></svg>',
    'users'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'tpl'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9.5 8h5"/><path d="M9.5 12H16"/><path d="M9.5 16H14"/></svg>',
    'form'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/></svg>',
    'gear'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
    'newchat'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>',
    'dots'   => '<svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>',
    'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
    'back'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>',
    'x'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
    'info'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'plus'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
    'smile'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg>',
    'send'   => '<svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',
    'down'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
    'up'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>',
    'lock'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'person' => '<svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
];
$avatar = static function (string $name, string $display, string $cls = '') use ($h, $ico): string {
    if ($name === '') return '<div class="wa-avatar ghost ' . $cls . '">' . $ico['person'] . '</div>';
    return '<div class="wa-avatar ' . $cls . '" style="background:' . $h(wa_avatar_color($display)) . '">' . $h(wa_initials($display)) . '</div>';
};
$emojis = ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','😉','😍','🥰','😘','😋','😎','🤩','🥳','😏','😌','🤔','🤗','🤭','😶','😐','🙄','😬','😴','😷','🤒','😢','😭','😤','😡','😱','😳','🥺','🙏','👍','👎','👌','✌️','🤞','👏','🙌','💪','👋','🤝','✍️','👀','🎉','🎊','🎁','🎂','🔥','✨','⭐','🌟','💯','✅','❌','⚠️','❗','❓','💡','📌','📍','📞','📱','💬','📩','📦','🛒','💰','💳','🧾','📅','⏰','🚚','🏠','🏢','🚗','✈️','❤️','🧡','💛','💚','💙','💜','🖤','💔','🌹','🌸','☀️','🌙','☕','🍽️','🍰','🎶'];
?>

<input type="hidden" id="csrfToken" value="<?php echo $h(csrf_get_token()); ?>">

<style>
/* ══════════════════════════════════════════════════════════════════════
   WHATSAPP CHAT — matches WhatsApp Web (light): rail · chat list · chat · contact info
   ══════════════════════════════════════════════════════════════════════ */
.wa-app{
  --wa-green:#00a884;--wa-green-d:#008069;--wa-badge:#1daa61;--wa-out:#d9fdd3;--wa-in:#fff;--wa-panel:#f0f2f5;
  --wa-ink:#111b21;--wa-sub:#667781;--wa-icon:#54656f;--wa-hover:#f5f6f6;--wa-sel:#f0f2f5;--wa-chat:#efeae2;--wa-tick:#8696a0;--wa-blue:#53bdeb;--wa-link:#027eb5;
  position:relative;display:flex;height:calc(100vh - var(--topbar-height,64px) - 56px);min-height:520px;
  border-radius:14px;overflow:hidden;background:var(--wa-panel);box-shadow:0 14px 40px -24px rgba(11,20,26,.45);
  font-family:"Segoe UI","Helvetica Neue",Helvetica,"Lucida Grande",Arial,Ubuntu,Cantarell,"Fira Sans",sans-serif;color:var(--wa-ink);font-size:14.2px
}
.wa-app *{box-sizing:border-box}
.wa-app button{font-family:inherit}
.wa-ib{width:40px;height:40px;border-radius:50%;border:0;background:transparent;color:var(--wa-icon);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;flex:none;transition:background .15s}
.wa-ib:hover,.wa-ib.on{background:rgba(11,20,26,.08);color:var(--wa-ink)}
.wa-ib svg{width:22px;height:22px}

/* ── Left rail (WhatsApp Web navigation) ── */
.wa-rail{width:64px;flex:0 0 64px;background:var(--wa-panel);display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 0}
.wa-rail .wa-ib{width:40px;height:40px;position:relative}
.wa-rail .wa-ib.on{background:rgba(11,20,26,.1)}
.wa-rail .wa-ib.on::before{content:'';position:absolute;left:-12px;top:10px;bottom:10px;width:3px;border-radius:0 3px 3px 0;background:var(--wa-green)}
.wa-rail-dot{position:absolute;top:4px;right:3px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:var(--wa-badge);color:#fff;font-size:11px;font-weight:600;display:grid;place-items:center}
.wa-rail-sp{flex:1}
.wa-rail .wa-me{width:32px;height:32px;border-radius:50%;background:#dfe5e7;color:#54656f;display:grid;place-items:center;font-size:13px;font-weight:600}

/* ── Chat list ── */
.wa-sidebar{width:30%;min-width:300px;max-width:440px;flex:none;background:#fff;display:flex;flex-direction:column;border-radius:0}
.wa-side-h{height:64px;padding:0 16px 0 20px;display:flex;align-items:center;justify-content:space-between;flex:none}
.wa-side-h h1{margin:0;font-size:22px;font-weight:700;color:var(--wa-ink);letter-spacing:-.2px}
.wa-side-h .acts{display:flex;gap:4px}
.wa-search{padding:2px 12px 8px;flex:none}
.wa-search-box{display:flex;align-items:center;gap:12px;background:var(--wa-panel);border-radius:20px;padding:0 14px;height:40px;transition:box-shadow .15s,background .15s}
.wa-search-box:focus-within{background:#fff;box-shadow:inset 0 0 0 2px var(--wa-green)}
.wa-search-box svg{width:17px;height:17px;color:var(--wa-icon);flex:none}
.wa-search-box input{flex:1;min-width:0;border:0;background:transparent;outline:none;font-size:15px;color:var(--wa-ink);height:100%;padding:0}
.wa-search-box input::placeholder{color:var(--wa-sub)}
.wa-filters{display:flex;gap:8px;padding:0 12px 10px;flex:none;overflow-x:auto;scrollbar-width:none}
.wa-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:16px;background:var(--wa-panel);color:var(--wa-icon);font-size:14px;text-decoration:none;white-space:nowrap;border:0;cursor:pointer;transition:background .15s,color .15s}
.wa-chip:hover{background:#e9ebee;color:var(--wa-ink)}
.wa-chip.on{background:var(--wa-out);color:var(--wa-green-d);font-weight:600}
.wa-chip em{font-style:normal;font-size:12px;opacity:.8}
.wa-contact-list{flex:1;min-height:0;overflow-y:auto}
.wa-contact-list::-webkit-scrollbar,.wa-chat-messages::-webkit-scrollbar,.wa-crm-body::-webkit-scrollbar{width:6px}
.wa-contact-list::-webkit-scrollbar-thumb,.wa-chat-messages::-webkit-scrollbar-thumb,.wa-crm-body::-webkit-scrollbar-thumb{background:rgba(11,20,26,.2);border-radius:6px}
.wa-contact-item{display:flex;align-items:center;gap:13px;height:72px;padding:0 12px 0 13px;margin:0 8px;border-radius:10px;text-decoration:none;color:inherit;transition:background .1s}
.wa-contact-item:hover{background:var(--wa-hover)}
.wa-contact-item.active{background:var(--wa-sel)}
.wa-avatar{width:49px;height:49px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:17px;flex:none;overflow:hidden;letter-spacing:.3px}
.wa-avatar svg{width:62%;height:62%;color:#fff}
.wa-avatar.ghost{background:#dfe5e7!important}
.wa-contact-info{flex:1;min-width:0}
.wa-contact-name-row,.wa-contact-sub-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.wa-contact-name{font-size:17px;color:var(--wa-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-contact-reltime{font-size:12px;color:var(--wa-sub);flex:none}
.wa-contact-item.has-unread .wa-contact-reltime{color:var(--wa-badge);font-weight:600}
.wa-contact-sub-row{margin-top:3px}
.wa-contact-snippet{font-size:14px;color:var(--wa-sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.wa-unread-badge{min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:var(--wa-badge);color:#fff;font-size:12px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;flex:none}
.wa-empty-list{padding:48px 24px;text-align:center;color:var(--wa-sub);font-size:14px}
.wa-list-foot{padding:18px 16px 22px;text-align:center;font-size:12px;color:var(--wa-sub);display:flex;align-items:center;justify-content:center;gap:6px}

/* ── Conversation ── */
.wa-chat-area{flex:1;min-width:0;display:flex;flex-direction:column;background:var(--wa-chat);position:relative}
.wa-chat-header{height:64px;padding:0 16px;background:var(--wa-panel);display:flex;align-items:center;gap:12px;flex:none}
.wa-chat-header .wa-avatar{width:40px;height:40px;font-size:15px}
.wa-chat-title{flex:1;min-width:0;cursor:pointer}
.wa-chat-title .name{font-size:16px;color:var(--wa-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-chat-title .sub{font-size:13px;color:var(--wa-sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.wa-chat-title .sub.open{color:var(--wa-green-d)}
.wa-hd-right{display:flex;align-items:center;gap:6px}
.wa-bot-pill{height:34px;padding:0 14px;border-radius:17px;border:0;background:var(--wa-out);color:var(--wa-green-d);font-size:13px;font-weight:600;cursor:pointer}
.wa-menu{position:absolute;top:56px;right:14px;min-width:200px;background:#fff;border-radius:12px;padding:8px 0;box-shadow:0 2px 5px rgba(11,20,26,.26),0 2px 10px rgba(11,20,26,.16);z-index:60;display:none}
.wa-menu.show{display:block}
.wa-menu button,.wa-menu a{display:block;width:100%;text-align:left;border:0;background:none;padding:11px 22px;font-size:14.5px;color:var(--wa-ink);cursor:pointer;text-decoration:none}
.wa-menu button:hover,.wa-menu a:hover{background:var(--wa-hover)}

.wa-findbar{display:none;align-items:center;gap:6px;padding:8px 16px;background:#fff;flex:none}
.wa-findbar.show{display:flex}
.wa-findbar input{flex:1;border:0;background:var(--wa-panel);border-radius:8px;height:36px;padding:0 12px;font-size:14.5px;outline:none}
.wa-findbar span{font-size:12.5px;color:var(--wa-sub);min-width:64px;text-align:center}
.wa-findbar .wa-ib{width:34px;height:34px}
.wa-findbar .wa-ib svg{width:18px;height:18px}

.wa-ai-bar{display:none;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 16px;background:#fffbea;font-size:13px;flex:none}
.wa-ai-bar .btn{border:0;border-radius:16px}

.wa-chat-messages{flex:1;min-height:0;overflow-y:auto;padding:16px 8% 10px;background-color:var(--wa-chat);background-image:url(../assets/img/wa-wallpaper.jpg);background-repeat:repeat;background-size:412px auto;position:relative}
.wa-sysmsg,.wa-date-sep{display:flex;justify-content:center;margin:10px 0}
.wa-sysmsg span{max-width:min(560px,85%);background:#ffeecd;color:#54656f;font-size:12.5px;line-height:1.45;text-align:center;padding:6px 12px 7px;border-radius:7.5px;box-shadow:0 1px .5px rgba(11,20,26,.13)}
.wa-sysmsg svg{vertical-align:-1px;margin-right:4px}
.wa-date-sep span{background:#fff;color:#54656f;font-size:12.5px;padding:5px 12px 6px;border-radius:7.5px;box-shadow:0 1px .5px rgba(11,20,26,.13)}

.wa-message-row{display:flex;margin-top:12px;padding:0 9px}
.wa-message-row.incoming+.wa-message-row.incoming,.wa-message-row.outgoing+.wa-message-row.outgoing{margin-top:2px}
.wa-message-row.incoming{justify-content:flex-start}
.wa-message-row.outgoing{justify-content:flex-end}
.wa-message-bubble{position:relative;max-width:65%;min-width:76px;padding:6px 7px 8px 9px;border-radius:7.5px;background:var(--wa-in);box-shadow:0 1px .5px rgba(11,20,26,.13);color:var(--wa-ink);font-size:14.2px;line-height:19px;overflow-wrap:anywhere;word-break:break-word}
.wa-message-row.outgoing .wa-message-bubble{background:var(--wa-out)}
/* Tail only on the first bubble of a run, like WhatsApp */
.wa-message-row.incoming .wa-message-bubble{border-top-left-radius:0}
.wa-message-row.outgoing .wa-message-bubble{border-top-right-radius:0}
.wa-message-row.incoming .wa-message-bubble::before{content:'';position:absolute;top:0;left:-8px;width:8px;height:13px;background:var(--wa-in);clip-path:polygon(100% 0,0 0,100% 100%)}
.wa-message-row.outgoing .wa-message-bubble::before{content:'';position:absolute;top:0;right:-8px;width:8px;height:13px;background:var(--wa-out);clip-path:polygon(0 0,100% 0,0 100%)}
.wa-message-row.incoming+.wa-message-row.incoming .wa-message-bubble{border-top-left-radius:7.5px}
.wa-message-row.outgoing+.wa-message-row.outgoing .wa-message-bubble{border-top-right-radius:7.5px}
.wa-message-row.incoming+.wa-message-row.incoming .wa-message-bubble::before,.wa-message-row.outgoing+.wa-message-row.outgoing .wa-message-bubble::before{display:none}
.wa-message-row.wa-hit .wa-message-bubble{box-shadow:0 0 0 2px #ffd279,0 1px .5px rgba(11,20,26,.13)}
.wa-message-row.wa-hit.cur .wa-message-bubble{box-shadow:0 0 0 3px #f5a623}

.wa-message-text{white-space:pre-wrap}
.wa-message-text::after{content:'';display:inline-block;width:70px}
.wa-message-row.outgoing .wa-message-text::after{width:88px}
.wa-message-text a{color:var(--wa-link);text-decoration:none}
.wa-message-text a:hover{text-decoration:underline}
.wa-message-text code{font-family:Consolas,"Courier New",monospace;font-size:13px}
.wa-message-meta{float:right;position:relative;margin:-10px 0 -5px 4px;display:flex;align-items:center;gap:3px;font-size:11px;line-height:15px;color:var(--wa-sub);white-space:nowrap}
.wa-tick{display:inline-flex;align-items:center;color:var(--wa-tick)}
.wa-tick.read{color:var(--wa-blue)}
.wa-tick.failed{color:#ea0038;font-weight:700}
.wa-tick svg{display:block}
.wa-message-bubble.media-only{padding:3px}
.wa-message-bubble.media-only .wa-message-meta{position:absolute;right:8px;bottom:6px;margin:0;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.6)}
.wa-message-bubble.media-only .wa-tick,.wa-message-bubble.media-only .wa-tick.read{color:#fff}
.wa-media{display:block;margin:-3px -4px 4px -6px;border-radius:6px;overflow:hidden;background:#cfd4d6}
.wa-message-bubble.media-only .wa-media{margin:0}
.wa-media img,.wa-media video{display:block;width:100%;max-width:330px;max-height:340px;object-fit:cover}
.wa-doc{display:flex;align-items:center;gap:10px;margin:-2px -3px 6px -5px;padding:10px 12px;border-radius:6px;background:rgba(11,20,26,.05);color:var(--wa-ink);text-decoration:none;min-width:240px}
.wa-doc-ico{width:34px;height:40px;border-radius:4px;background:#e2564d;color:#fff;font-size:10px;font-weight:700;display:grid;place-items:center;flex:none}
.wa-doc-name{flex:1;min-width:0;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wa-doc svg{color:var(--wa-icon);flex:none}
.wa-ifoot{font-size:12.5px;color:var(--wa-sub);margin-top:4px}
.wa-ibtns{margin:8px -7px -8px -9px;display:grid;gap:2px;padding-top:2px}
.wa-ibtn{display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 10px;color:var(--wa-link);font-size:14px;background:rgba(255,255,255,.55)}
.wa-message-row.incoming .wa-ibtn{background:#f7f8fa}
.wa-ibtns .wa-ibtn:last-child{border-radius:0 0 7.5px 7.5px}
/* Button messages: time sits under the text, buttons hang at the bottom of the bubble (WhatsApp layout) */
.wa-message-bubble:has(.wa-ibtns){display:flex;flex-direction:column}
.wa-message-bubble:has(.wa-ibtns) .wa-message-meta{order:2;float:none;align-self:flex-end;margin:2px 0 0}
.wa-message-bubble:has(.wa-ibtns) .wa-message-text::after{display:none}
.wa-message-bubble:has(.wa-ibtns) .wa-ibtns{order:3}
.wa-form-card{min-width:240px;margin-bottom:6px}
.wa-form-h{display:flex;align-items:center;gap:6px;font-weight:600;color:var(--wa-green-d);margin-bottom:6px}
.wa-form-row{display:flex;justify-content:space-between;gap:14px;padding:5px 8px;border-radius:6px;background:rgba(11,20,26,.04);margin-bottom:3px;font-size:13px}
.wa-form-row span{color:var(--wa-sub)}
.wa-form-row b{font-weight:600;text-align:right}
.wa-voice{display:flex;flex-direction:column;gap:6px;min-width:250px;max-width:330px;padding-bottom:12px}
.wa-voice audio{width:100%;height:40px}
.wa-voice-tag{font-size:13px;color:var(--wa-icon)}
.wa-voice-tr{font-size:13.5px;line-height:1.45;background:rgba(11,20,26,.05);border-radius:6px;padding:7px 9px}
.wa-voice-tr small{display:block;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--wa-green-d);margin-bottom:2px}
.wa-voice-btn{align-self:flex-start;border:0;border-radius:14px;padding:5px 12px;background:rgba(0,168,132,.12);color:var(--wa-green-d);font-size:12.5px;font-weight:600;cursor:pointer}
.wa-voice-btn[disabled]{opacity:.6;cursor:wait}
.wa-voice-err{font-size:12px;color:#c62828}

.wa-scroll-down{position:absolute;right:22px;bottom:84px;width:42px;height:42px;border-radius:50%;border:0;background:#fff;color:var(--wa-icon);box-shadow:0 1px 3px rgba(11,20,26,.25);cursor:pointer;display:none;align-items:center;justify-content:center;z-index:5}
.wa-scroll-down.show{display:flex}
.wa-scroll-down svg{width:22px;height:22px}
.wa-scroll-down i{position:absolute;top:-6px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:var(--wa-badge);color:#fff;font-style:normal;font-size:11.5px;font-weight:600;display:none;place-items:center}
.wa-scroll-down i.show{display:grid}

/* ── Composer ── */
.wa-chat-input{background:var(--wa-panel);padding:8px 16px;flex:none;position:relative}
.wa-closed-note{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12.5px;color:#8a5a00;background:#fff4dc;border-radius:8px;padding:7px 12px;margin-bottom:8px;text-align:center}
.wa-closed-note a{color:var(--wa-green-d);font-weight:600;text-decoration:none}
.wa-cmp{display:flex;align-items:flex-end;gap:6px}
.wa-cmp>.wa-ib{margin-bottom:3px}
.wa-cmp-box{flex:1;min-width:0;display:flex;align-items:flex-end;gap:4px;background:#fff;border-radius:24px;padding:3px 6px 3px 4px;min-height:46px}
.wa-cmp-box .wa-ib{width:40px;height:40px}
.wa-cmp-box textarea{flex:1;min-width:0;border:0;outline:none;resize:none;background:transparent;font:inherit;font-size:15px;line-height:20px;color:var(--wa-ink);padding:10px 6px;max-height:120px;height:40px}
.wa-cmp-box textarea::placeholder{color:var(--wa-sub)}
.wa-send{width:46px;height:46px;border-radius:50%;border:0;background:var(--wa-green);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;flex:none;transition:background .15s,transform .1s}
.wa-send:hover{background:var(--wa-green-d)}
.wa-send:active{transform:scale(.94)}
.wa-send:disabled{opacity:.6;cursor:wait}
.wa-send svg{width:22px;height:22px;margin-left:2px}
.wa-file-chip{display:none;align-items:center;gap:8px;margin:0 0 8px 54px;background:#fff;border-radius:10px;padding:8px 12px;font-size:13px;color:var(--wa-ink);max-width:420px;box-shadow:0 1px .5px rgba(11,20,26,.13)}
.wa-file-chip span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wa-file-chip button{border:0;background:none;color:var(--wa-icon);cursor:pointer;font-size:18px;line-height:1}
.wa-composer-note{padding:10px 0;color:var(--wa-sub);font-size:14px;text-align:center}

.wa-pop{display:none;position:absolute;bottom:66px;left:16px;background:#fff;border-radius:16px;box-shadow:0 2px 5px rgba(11,20,26,.26),0 2px 10px rgba(11,20,26,.16);z-index:70}
.wa-pop.show{display:block;opacity:1;animation:waPopIn .16s ease-out}
@keyframes waPopIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.wa-pop.show{animation:none}}
.wa-attach{padding:8px;min-width:230px}
.wa-attach button{display:flex;align-items:center;gap:14px;width:100%;border:0;background:none;padding:9px 12px;border-radius:10px;font-size:14.5px;color:var(--wa-ink);cursor:pointer;text-align:left}
.wa-attach button:hover{background:var(--wa-hover)}
.wa-attach i{width:26px;height:26px;display:grid;place-items:center;flex:none;font-style:normal}
.wa-attach i svg{width:22px;height:22px}
.wa-emoji{left:56px;width:352px;padding:10px}
.wa-emoji-grid{display:grid;grid-template-columns:repeat(9,1fr);gap:2px;max-height:250px;overflow-y:auto}
.wa-emoji-grid button{border:0;background:none;font-size:23px;line-height:34px;border-radius:8px;cursor:pointer;padding:0}
.wa-emoji-grid button:hover{background:var(--wa-hover)}
.wa-pop-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:2px 4px 8px}
.wa-pop-h b{font-size:13px;color:var(--wa-sub);font-weight:600}
.wa-pop-h a,.wa-pop-h button{font-size:13px;color:var(--wa-green-d);text-decoration:none;font-weight:600;border:0;background:none;cursor:pointer}
.wa-quick{width:360px;max-height:320px;overflow-y:auto;padding:10px}
.wa-qr-item{padding:9px 10px;border-radius:10px;cursor:pointer}
.wa-qr-item:hover{background:var(--wa-hover)}
.wa-qr-item .sc{font-size:13px;font-weight:600;color:var(--wa-green-d)}
.wa-qr-item .msg{font-size:14px;color:var(--wa-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wa-form-pop{width:320px;padding:14px}
.wa-form-pop label{display:block;font-size:12.5px;color:var(--wa-sub);margin:8px 0 4px}
.wa-form-pop input,.wa-form-pop select{width:100%;height:38px;border:0;border-radius:8px;background:var(--wa-panel);padding:0 10px;font:inherit;font-size:14px;outline:none}
.wa-form-pop input:focus,.wa-form-pop select:focus{box-shadow:inset 0 0 0 2px var(--wa-green);background:#fff}
.wa-form-pop .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.wa-form-pop .total{display:flex;justify-content:space-between;margin:12px 0 4px;font-size:14px}
.wa-form-pop .go{width:100%;height:40px;margin-top:12px;border:0;border-radius:20px;background:var(--wa-green);color:#fff;font-weight:600;font-size:14px;cursor:pointer}
.wa-form-pop .go:hover{background:var(--wa-green-d)}

/* ── Landing (no chat selected) ── */
/* Start screen — WhatsApp Web style: feature card + round quick actions */
.wa-landing{background:#f5f3f0;position:relative}
.wa-land{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 20px 70px;text-align:center}
.wa-land-card{width:min(360px,100%);background:#fff;border-radius:24px;padding:30px 28px 26px;box-shadow:0 1px 2px rgba(11,20,26,.06)}
.wa-land-art{width:150px;height:auto;display:block;margin:0 auto 18px}
.wa-land-card h2{margin:0 0 8px;font-size:20px;font-weight:400;line-height:1.35;color:var(--wa-ink)}
.wa-land-card p{margin:0 auto 18px;font-size:14px;line-height:1.5;color:var(--wa-sub)}
.wa-land-pill{display:inline-flex;align-items:center;justify-content:center;height:34px;padding:0 18px;border:0;border-radius:17px;background:#d9fdd3;color:#0a5c48;font:500 14px inherit;cursor:pointer;text-decoration:none}
.wa-land-pill:hover{background:#c6f6be}
.wa-land-acts{display:flex;justify-content:center;gap:22px;margin-top:30px;flex-wrap:wrap}
.wa-land-act{display:flex;flex-direction:column;align-items:center;gap:8px;width:88px;border:0;background:none;padding:0;font:inherit;font-size:12.5px;color:var(--wa-ink);text-decoration:none;cursor:pointer}
.wa-land-act>span{width:60px;height:46px;border-radius:23px;background:#ebe8e4;display:grid;place-items:center;color:var(--wa-ink);transition:background .15s}
.wa-land-act:hover>span{background:#e0dcd7}
.wa-land-act>span svg{width:22px;height:22px}
.wa-bot-ring svg{width:24px!important;height:24px!important}
.wa-bot-ring.is-off svg circle{fill:#a9b3b0}
.wa-landing-foot{position:absolute;bottom:24px;left:0;right:0;font-size:13px;color:#8696a0;display:flex;align-items:center;justify-content:center;gap:6px}

/* Ask WAAMINI Bot panel */
.wa-askbot{flex:1;min-height:0;display:flex;flex-direction:column;background:var(--wa-chat)}
.wa-askbot[hidden]{display:none}
.wa-bot-av{background:#00a884!important}
.wa-bot-av svg{width:70%;height:70%}
.wa-askbot-sugg{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:14px 0}
.wa-askbot-sugg button{border:0;background:#fff;color:var(--wa-green-d);border-radius:18px;padding:8px 14px;font:inherit;font-size:13.5px;cursor:pointer;box-shadow:0 1px .5px rgba(11,20,26,.13)}
.wa-askbot-sugg button:hover{background:#f0fdf4}
.wa-typing{display:inline-flex;gap:4px;padding:4px 2px}
.wa-typing i{width:7px;height:7px;border-radius:50%;background:#8696a0;animation:waDot 1.2s infinite}
.wa-typing i:nth-child(2){animation-delay:.2s}.wa-typing i:nth-child(3){animation-delay:.4s}
@keyframes waDot{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
.wa-bot-note{display:block;margin-top:6px;font-size:12px;color:#8a5a00;background:#fff4dc;border-radius:6px;padding:4px 8px}

/* ── Contact info (CRM) ── */
.wa-crm-sidebar{width:0;flex:none;overflow:hidden;background:var(--wa-panel);display:flex;flex-direction:column;transition:width .2s ease}
.wa-crm-sidebar.open{width:min(400px,34%)}
.wa-crm-h{height:64px;flex:none;display:flex;align-items:center;gap:18px;padding:0 16px;background:#fff;font-size:16px;color:var(--wa-ink);min-width:320px}
.wa-crm-body{flex:1;min-height:0;overflow-y:auto;min-width:320px;display:flex;flex-direction:column;gap:10px;padding-bottom:12px}
.wa-crm-profile{background:#fff;padding:28px 24px 22px;text-align:center}
.wa-crm-profile .wa-avatar{width:120px;height:120px;font-size:40px;margin:0 auto 14px}
.wa-crm-profile .nm{font-size:22px;color:var(--wa-ink)}
.wa-crm-profile .ph{font-size:16px;color:var(--wa-sub);margin-top:4px}
.wa-crm-profile .win{display:inline-block;margin-top:10px;font-size:12.5px;padding:4px 10px;border-radius:12px;background:#fff4dc;color:#8a5a00}
.wa-crm-profile .win.open{background:var(--wa-out);color:var(--wa-green-d)}
.wa-crm-sec{background:#fff;padding:14px 20px 16px}
.wa-crm-sec h4{margin:0 0 10px;font-size:14px;font-weight:400;color:var(--wa-sub);display:flex;align-items:center;justify-content:space-between}
.wa-crm-sec h4 b{font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:10px}
.wa-crm-sec label{display:block;font-size:13px;color:var(--wa-sub);margin:10px 0 4px}
.wa-crm-sec label:first-of-type{margin-top:0}
.wa-crm-sec .form-control{width:100%;font-size:14.5px;background:var(--wa-panel);border:0;border-radius:8px;color:var(--wa-ink)}
.wa-crm-sec .form-control:focus{box-shadow:inset 0 0 0 2px var(--wa-green);background:#fff}
.wa-crm-sec .hint{font-size:12px;color:var(--wa-sub);margin-top:8px;line-height:1.45}
.wa-crm-sec .hint a{color:var(--wa-green-d);font-weight:600;text-decoration:none}
.wa-adsrc .wa-ad{display:flex;gap:12px;align-items:center}
.wa-adsrc .wa-ad img,.wa-adsrc .wa-ad-ph{width:56px;height:56px;border-radius:9px;object-fit:cover;flex:none;background:var(--wa-panel);display:grid;place-items:center;color:var(--wa-icon)}
.wa-adsrc .wa-ad b{display:block;font-size:14.5px;font-weight:600;color:var(--wa-ink)}
.wa-adsrc .wa-ad-id,.wa-adsrc .wa-ad-when{display:block;font-size:12.5px;color:var(--wa-sub);margin-top:2px}
.wa-ad-copy{border:0;background:var(--wa-panel);border-radius:6px;padding:1px 7px;font-size:11.5px;color:var(--wa-green-d);cursor:pointer;margin-left:4px}
.wa-ad-all{display:inline-block;margin-top:10px;font-size:13px;font-weight:600;color:var(--wa-green-d);text-decoration:none}
.wa-ad-hist{margin-top:10px;font-size:13px;color:var(--wa-ink)}
.wa-ad-hist span{display:block;font-size:12px;color:var(--wa-sub);margin-bottom:4px}
.wa-ad-hist div{padding:6px 10px;background:var(--wa-panel);border-radius:8px;margin-bottom:4px}
.wa-ad-hist em{font-style:normal;color:var(--wa-sub);font-size:12px;margin-left:4px}
.wa-outcomes{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.wa-outcomes form{margin:0}
.wa-outcomes button{width:100%;display:flex;align-items:center;gap:8px;border:0;border-radius:10px;padding:10px 12px;font-size:13.5px;font-weight:600;cursor:pointer;background:var(--wa-panel);color:var(--wa-ink)}
.wa-outcomes button i{width:9px;height:9px;border-radius:50%;flex:none}
.wa-outcomes button.on{box-shadow:inset 0 0 0 2px currentColor}
.wa-crm-save{position:sticky;bottom:0;padding:10px 20px;background:var(--wa-panel);display:flex;gap:8px}
.wa-crm-save button{flex:1;height:40px;border:0;border-radius:20px;font-weight:600;font-size:14px;cursor:pointer}
.wa-crm-save .p{background:var(--wa-green);color:#fff}
.wa-crm-save .p:hover{background:var(--wa-green-d)}
.wa-crm-save .s{background:#fff;color:var(--wa-ink)}

/* ── Responsive ── */
.wa-mobile-back{display:none!important}
#waSidebarOverlay{pointer-events:none;z-index:9997}
#waSidebarOverlay.show{pointer-events:auto}
@media (max-width:1100px){.wa-rail{display:none}.wa-chat-messages{padding-left:3%;padding-right:3%}.wa-crm-sidebar.open{position:absolute;right:0;top:0;bottom:0;width:min(380px,92%);z-index:80;box-shadow:-8px 0 28px rgba(11,20,26,.18)}}
@media (max-width:768px){
  .wa-app{height:calc(100vh - var(--topbar-height,64px));border-radius:0}
  .wa-sidebar{position:fixed;left:-110%;top:0;bottom:0;width:min(400px,94vw);max-width:none;z-index:9999;transition:left .25s ease}
  .wa-sidebar.open{left:0}
  .wa-mobile-back{display:inline-flex!important}
  .wa-message-bubble{max-width:85%}
  .wa-emoji{left:8px;right:8px;width:auto}
  .wa-quick,.wa-form-pop{left:8px;right:8px;width:auto}
  .wa-crm-sidebar.open{position:fixed;z-index:10000;width:100%}
  .wa-hd-right .wa-bot-pill{padding:0 10px}
}

/* message types without text (sticker, reaction, contact card, missing file) */
.wa-sticker{display:block;width:128px;max-width:100%;height:auto;margin:2px 0 14px}
.wa-msg-note{color:var(--wa-sub);font-style:italic}

/* ══════════════════════════════════════════════════════════════════════
   PHONE (≤768px) — looks and works like the WhatsApp app.
   Desktop is untouched: every rule below lives inside this media query.
   ══════════════════════════════════════════════════════════════════════ */
@media (max-width:768px){
  body:has(.wa-app){overflow:hidden}
  .wa-app{position:fixed;left:0;right:0;bottom:0;top:var(--topbar-height,64px);height:auto;min-height:0;
          border-radius:0;box-shadow:none;z-index:5;background:#fff;font-size:15px}
  .wa-rail{display:none}

  /* ── 1. Chat list (no chat open): the list is the whole screen ───────── */
  .wa-app.no-chat .wa-sidebar{position:static;left:auto;width:100%;min-width:0;max-width:none;height:100%;transition:none}
  .wa-app.no-chat .wa-chat-area{display:none}
  .wa-app.no-chat .wa-side-h .wa-mobile-back{display:none!important}
  .wa-side-h{height:58px;padding:0 8px 0 18px}
  .wa-side-h h1{font-size:25px;font-weight:700;color:#1daa61;letter-spacing:-.3px}
  .wa-search{padding:2px 16px 12px}
  .wa-search-box{height:46px;border-radius:24px;padding:0 16px;gap:14px;background:#f0f2f5}
  .wa-search-box svg{width:20px;height:20px}
  .wa-search-box input{font-size:16px}
  .wa-filters{padding:0 16px 8px;gap:8px}
  .wa-chip{padding:7px 14px;border-radius:20px;font-size:14.5px;background:#fff;color:#3b4a54;box-shadow:inset 0 0 0 1px #d1d7db}
  .wa-chip.on{background:#d9fdd3;color:#0a5c36;box-shadow:none;font-weight:600}
  .wa-contact-list{padding-bottom:96px}
  .wa-contact-item{height:76px;margin:0;border-radius:0;padding:0 16px;gap:14px}
  .wa-contact-item.active{background:transparent}
  .wa-contact-item:active{background:#f0f2f5}
  .wa-contact-list .wa-avatar{width:52px;height:52px;font-size:18px}
  .wa-contact-name{font-size:17px;font-weight:500}
  .wa-contact-reltime{font-size:12.5px}
  .wa-contact-item.has-unread .wa-contact-reltime{color:#1daa61;font-weight:700}
  .wa-contact-sub-row{margin-top:4px}
  .wa-contact-snippet{font-size:15px}
  .wa-unread-badge{min-width:22px;height:22px;border-radius:11px;font-size:12.5px;font-weight:700;background:#25d366}
  /* "new chat" becomes WhatsApp's floating green button */
  .wa-app.no-chat .wa-side-h .acts a.wa-ib{position:fixed;right:18px;bottom:calc(20px + env(safe-area-inset-bottom));z-index:30;
          width:58px;height:58px;border-radius:18px;background:#1daa61;color:#fff;box-shadow:0 4px 14px rgba(11,20,26,.25)}
  .wa-app.no-chat .wa-side-h .acts a.wa-ib svg{width:24px;height:24px}

  /* ── 2. Open chat: full screen, panel top bar hidden ──────────────────── */
  body:has(.wa-app.has-chat) .topbar{display:none!important}
  .wa-app.has-chat{top:0;background:var(--wa-chat)}
  .wa-app.has-chat .wa-sidebar{width:100vw}
  .wa-chat-header{height:60px;padding:0 6px 0 2px;gap:6px;background:#fff;box-shadow:0 1px 0 rgba(11,20,26,.06);position:relative;z-index:3}
  .wa-chat-header .wa-avatar{width:40px;height:40px}
  .wa-chat-title .name{font-size:17px;font-weight:600}
  .wa-chat-title .sub{font-size:12.5px}
  .wa-hd-right{gap:0}
  .wa-hd-right .wa-bot-pill{height:30px;font-size:12px}

  /* AI analysis: one quiet line instead of a big yellow block */
  .wa-ai-bar{flex-wrap:nowrap;gap:8px;padding:6px 12px;font-size:12px;overflow:hidden}
  .wa-ai-bar > span:first-child{font-size:10.5px}
  #aiLeadReason{min-width:0!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #aiLeadApply,#aiLeadRefresh{flex:none}

  /* messages */
  .wa-chat-messages{padding:6px 2px 10px;background-size:330px auto}
  .wa-sysmsg span{font-size:12px;max-width:92%}
  .wa-date-sep span{font-size:12.5px}
  .wa-message-row{padding:0 10px}
  .wa-message-bubble{max-width:84%;font-size:15.5px;line-height:21px;padding:6px 8px 8px 9px;border-radius:9px}
  .wa-message-meta{font-size:11.5px}
  .wa-message-bubble.media-only{max-width:78%}

  /* composer: white pill (emoji · text · attach) + round green send */
  .wa-chat-input{background:transparent;padding:6px 6px calc(8px + env(safe-area-inset-bottom))}
  .wa-closed-note{font-size:12px;border-radius:10px;margin:0 2px 6px}
  .wa-cmp{position:relative;gap:6px;align-items:flex-end}
  .wa-cmp-box{min-height:50px;border-radius:26px;padding:4px 46px 4px 4px;box-shadow:0 1px 1.5px rgba(11,20,26,.12)}
  .wa-cmp-box textarea{font-size:16px;line-height:21px;padding:10px 4px;height:42px}
  .wa-cmp>#waBtnAttach{position:absolute;right:60px;bottom:5px;margin:0;z-index:2;width:40px;height:40px}
  .wa-cmp>#waBtnAttach:hover{background:transparent}
  .wa-send{width:50px;height:50px;background:#1daa61}
  .wa-send svg{width:23px;height:23px}
  .wa-attach{left:8px;right:8px;width:auto}
  .wa-scroll-down{right:12px}
}
</style>

<div class="sidebar-overlay" id="waSidebarOverlay" style="display:none;"></div>

<div class="wa-app<?php echo $activeContact ? ' has-chat' : ' no-chat'; ?>" id="waApp" data-wm-skip>
  <!-- Navigation rail, like WhatsApp Web -->
  <nav class="wa-rail" aria-label="Inbox navigation">
    <a class="wa-ib on" href="whatsapp.php" title="Chats" aria-label="Chats"><?php echo $ico['chats']; ?><?php if ($tabCounts['all'] && ($railUnread = array_sum(array_map(fn($c) => (int)$c['unread_count'], $contacts)))): ?><span class="wa-rail-dot"><?php echo $railUnread > 99 ? '99+' : $railUnread; ?></span><?php endif; ?></a>
    <a class="wa-ib" href="campaigns.php" title="Broadcasts / campaigns" aria-label="Campaigns"><?php echo $ico['cast']; ?></a>
    <a class="wa-ib" href="contacts.php" title="Contacts" aria-label="Contacts"><?php echo $ico['users']; ?></a>
    <a class="wa-ib" href="templates.php" title="Message templates" aria-label="Templates"><?php echo $ico['tpl']; ?></a>
    <?php if (has_feature('whatsapp_flows')): ?><a class="wa-ib" href="premium_flows.php" title="WhatsApp forms" aria-label="WhatsApp forms"><?php echo $ico['form']; ?></a><?php endif; ?>
    <div class="wa-rail-sp"></div>
    <a class="wa-ib" href="settings_whatsapp.php" title="WhatsApp settings" aria-label="Settings"><?php echo $ico['gear']; ?></a>
    <span class="wa-me" title="<?php echo $h($meName); ?>"><?php echo $h(wa_initials($meName)); ?></span>
  </nav>

  <!-- Chat list -->
  <aside class="wa-sidebar" id="waSidebar">
    <div class="wa-side-h">
      <h1>Chats</h1>
      <div class="acts">
        <a class="wa-ib" href="contact_add.php" title="New chat" aria-label="New chat"><?php echo $ico['newchat']; ?></a>
        <button type="button" class="wa-ib wa-mobile-back" onclick="toggleWaSidebar(false)" aria-label="Close chat list"><?php echo $ico['x']; ?></button>
      </div>
    </div>
    <div class="wa-search">
      <label class="wa-search-box"><?php echo $ico['search']; ?><input type="text" id="contactSearch" placeholder="Search or start a new chat" autocomplete="off"></label>
    </div>
    <div class="wa-filters">
      <?php foreach (['all' => 'All', 'unread' => 'Unread', 'mine' => 'Mine', 'unassigned' => 'Unassigned'] as $tabKey => $tabLabel): ?>
        <?php if ($tabKey === 'unread'): ?>
          <button type="button" class="wa-chip" id="waUnreadChip">Unread</button>
        <?php else: ?>
          <a class="wa-chip<?php echo $filterTab === $tabKey ? ' on' : ''; ?>" href="whatsapp.php?tab=<?php echo $tabKey; ?><?php echo $activeContactId ? '&c=' . (int)$activeContactId : ''; ?>"><?php echo $tabLabel; ?><?php if (($tabCounts[$tabKey] ?? 0) > 0): ?> <em><?php echo (int)$tabCounts[$tabKey]; ?></em><?php endif; ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="wa-contact-list" id="contactList" data-tab="<?php echo $h($filterTab); ?>">
      <?php if ($contacts): ?>
        <?php foreach ($contacts as $c):
          $cid = (int)$c['id'];
          $isActive = ($cid === $activeContactId);
          $name = (string)($c['name'] ?? '');
          $display = $name !== '' ? $name : (string)($c['phone'] ?? '');
          $snippet = trim(preg_replace('/\s+/', ' ', (string)($c['last_msg_preview'] ?? '')));
          if (mb_strlen($snippet, 'UTF-8') > 70) $snippet = mb_substr($snippet, 0, 70, 'UTF-8') . '…';
          $unread = (int)($c['unread_count'] ?? 0);
          $hasUnread = ($unread > 0 && !$isActive);
        ?>
          <a class="wa-contact-item<?php echo $isActive ? ' active' : ''; ?><?php echo $hasUnread ? ' has-unread' : ''; ?>" data-cid="<?php echo $cid; ?>" href="whatsapp.php?c=<?php echo $cid . $tabQ; ?>">
            <?php echo $avatar($name, $display); ?>
            <div class="wa-contact-info">
              <div class="wa-contact-name-row">
                <div class="wa-contact-name"><?php echo $h($display); ?></div>
                <div class="wa-contact-reltime" id="contact-time-<?php echo $cid; ?>"><?php echo $h(wa_chat_time((string)($c['last_message_at'] ?? ''))); ?></div>
              </div>
              <div class="wa-contact-sub-row">
                <div class="wa-contact-snippet" id="contact-snippet-<?php echo $cid; ?>"><?php echo $h($snippet); ?></div>
                <div class="wa-unread-badge" id="unread-badge-<?php echo $cid; ?>"<?php echo $hasUnread ? '' : ' style="display:none;"'; ?>><?php echo $hasUnread ? $unread : ''; ?></div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="wa-empty-list">No chats yet. Messages from customers appear here.</div>
      <?php endif; ?>
      <div class="wa-list-foot"><?php echo $ico['lock']; ?> Messages go through your WhatsApp Business number</div>
    </div>
  </aside>

  <?php if (!$activeContact): ?>
  <?php $hasBot = has_feature('ai_chatbot'); ?>
  <main class="wa-chat-area wa-landing" id="waLanding">
    <button type="button" class="wa-ib wa-mobile-back" style="position:absolute;top:12px;left:12px" onclick="toggleWaSidebar(true)" aria-label="Open chats"><?php echo $ico['chats']; ?></button>

    <div class="wa-land" id="waLandHome">
      <div class="wa-land-card">
        <svg class="wa-land-art" viewBox="0 0 160 96" aria-hidden="true">
          <rect x="14" y="10" width="132" height="70" rx="7" fill="#fff" stroke="#1F2C33" stroke-width="2"/>
          <rect x="22" y="18" width="34" height="54" rx="3" fill="#25D366" stroke="#1F2C33" stroke-width="2"/>
          <line x1="29" y1="30" x2="49" y2="30" stroke="#1F2C33" stroke-width="2" stroke-linecap="round"/>
          <line x1="29" y1="38" x2="49" y2="38" stroke="#1F2C33" stroke-width="2" stroke-linecap="round"/>
          <line x1="29" y1="46" x2="43" y2="46" stroke="#1F2C33" stroke-width="2" stroke-linecap="round"/>
          <rect x="62" y="18" width="76" height="54" rx="3" fill="#FCF4EA" stroke="#1F2C33" stroke-width="2"/>
          <rect x="70" y="28" width="40" height="11" rx="5.5" fill="#fff" stroke="#1F2C33" stroke-width="1.6"/>
          <rect x="88" y="45" width="42" height="11" rx="5.5" fill="#D9FDD3" stroke="#1F2C33" stroke-width="1.6"/>
          <circle cx="124" cy="31" r="7" fill="#fff" stroke="#1F2C33" stroke-width="1.6"/><circle cx="121.5" cy="30" r="1.1" fill="#1F2C33"/><circle cx="126.5" cy="30" r="1.1" fill="#1F2C33"/><path d="M121 33.2c1.6 1.3 4.4 1.3 6 0" fill="none" stroke="#1F2C33" stroke-width="1.2" stroke-linecap="round"/>
          <path d="M4 80h152l-6 8H10z" fill="#E7FCE3" stroke="#1F2C33" stroke-width="2" stroke-linejoin="round"/>
        </svg>
        <?php if ($hasBot): ?>
          <h2>WAAMINI Bot is ready to reply</h2>
          <p>Your bot answers customers on this number, day and night. Ask it anything to see how it replies.</p>
          <button type="button" class="wa-land-pill" data-askbot>Ask WAAMINI Bot</button>
        <?php else: ?>
          <h2>Your WhatsApp inbox is ready</h2>
          <p>Send and receive WhatsApp messages from your business number. Pick a chat on the left to start replying.</p>
          <a class="wa-land-pill" href="campaign_create.php">Send a campaign</a>
        <?php endif; ?>
      </div>

      <div class="wa-land-acts">
        <a class="wa-land-act" href="campaign_create.php"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></span>Send template</a>
        <a class="wa-land-act" href="contact_add.php"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg></span>Add contact</a>
        <?php if (has_feature('ads_attribution')): ?>
        <a class="wa-land-act" href="ads_leads.php"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></span>Ad leads</a>
        <?php else: ?>
        <a class="wa-land-act" href="contacts.php"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>Contacts</a>
        <?php endif; ?>
        <?php if ($hasBot): ?>
        <button type="button" class="wa-land-act" data-askbot><span class="wa-bot-ring"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.6" r="2.4" fill="#25D366"/><circle cx="18.4" cy="8.3" r="2.4" fill="#1DA1F2"/><circle cx="18.4" cy="15.7" r="2.4" fill="#7C5CFF"/><circle cx="12" cy="19.4" r="2.4" fill="#128C7E"/><circle cx="5.6" cy="15.7" r="2.4" fill="#34B7F1"/><circle cx="5.6" cy="8.3" r="2.4" fill="#00A884"/></svg></span>Ask WAAMINI Bot</button>
        <?php else: ?>
        <a class="wa-land-act" href="subscription.php" title="WAAMINI Bot is part of the AI plans"><span class="wa-bot-ring is-off"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.6" r="2.4"/><circle cx="18.4" cy="8.3" r="2.4"/><circle cx="18.4" cy="15.7" r="2.4"/><circle cx="12" cy="19.4" r="2.4"/><circle cx="5.6" cy="15.7" r="2.4"/><circle cx="5.6" cy="8.3" r="2.4"/></svg></span>Ask WAAMINI Bot</a>
        <?php endif; ?>
      </div>
      <div class="wa-landing-foot"><?php echo $ico['lock']; ?> Connected to your WhatsApp Business number</div>
    </div>

    <?php if ($hasBot): ?>
    <!-- Ask WAAMINI Bot: a private test chat with the company's own bot (nothing goes to customers) -->
    <section class="wa-askbot" id="waAskBot" hidden>
      <header class="wa-chat-header">
        <span class="wa-avatar wa-bot-av"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.6" r="2.4" fill="#fff"/><circle cx="18.4" cy="8.3" r="2.4" fill="#fff" opacity=".85"/><circle cx="18.4" cy="15.7" r="2.4" fill="#fff" opacity=".7"/><circle cx="12" cy="19.4" r="2.4" fill="#fff" opacity=".85"/><circle cx="5.6" cy="15.7" r="2.4" fill="#fff" opacity=".7"/><circle cx="5.6" cy="8.3" r="2.4" fill="#fff"/></svg></span>
        <div class="wa-chat-title"><div class="name">WAAMINI Bot</div><div class="sub open">Your AI assistant · only you see this chat</div></div>
        <div class="wa-hd-right">
          <a class="wa-ib" href="waamini_bot.php" title="Bot settings" aria-label="Bot settings"><?php echo $ico['gear']; ?></a>
          <button type="button" class="wa-ib" id="waAskReset" title="New chat" aria-label="New chat"><?php echo $ico['newchat']; ?></button>
          <button type="button" class="wa-ib" id="waAskClose" title="Close" aria-label="Close"><?php echo $ico['x']; ?></button>
        </div>
      </header>
      <div class="wa-chat-messages" id="waAskMsgs">
        <div class="wa-sysmsg"><span><?php echo $ico['lock']; ?> Ask your bot like a customer would. Replies use your bot's knowledge and instructions. Nothing is sent on WhatsApp.</span></div>
        <div class="wa-askbot-sugg" id="waAskSugg">
          <button type="button">What are your timings?</button>
          <button type="button">What is the price?</button>
          <button type="button">Do you deliver to Karaikudi?</button>
          <button type="button">I want to talk to a person</button>
        </div>
      </div>
      <div class="wa-chat-input">
        <form class="wa-cmp" id="waAskForm" autocomplete="off">
          <div class="wa-cmp-box"><textarea id="waAskIn" rows="1" placeholder="Ask WAAMINI Bot"></textarea></div>
          <button type="submit" class="wa-send" aria-label="Send"><?php echo $ico['send']; ?></button>
        </form>
      </div>
    </section>
    <?php endif; ?>
  </main>
  <?php else:
    $hdrName = (string)($activeContact['name'] ?? '');
    $hdrDisplay = $hdrName !== '' ? $hdrName : (string)$activeContact['phone'];
    $isCrmOpen = (isset($_GET['crm']) && $_GET['crm'] === 'open');
  ?>
  <main class="wa-chat-area">
    <header class="wa-chat-header">
      <a class="wa-ib wa-mobile-back" href="whatsapp.php?tab=<?php echo $h($filterTab); ?>" aria-label="Back to chats"><?php echo $ico['back']; ?></a>
      <?php echo $avatar($hdrName, $hdrDisplay); ?>
      <div class="wa-chat-title" onclick="toggleWaCrm(true)" title="Contact info">
        <div class="name"><?php echo $h($hdrDisplay); ?></div>
        <div class="sub<?php echo $windowOpen ? ' open' : ''; ?>"><?php echo $h($windowText); ?></div>
      </div>
      <div class="wa-hd-right">
        <?php
          // WAAMINI Bot: "Resume bot" when the bot is paused or the chat is assigned to a person
          try {
              require_once __DIR__ . '/../includes/waamini_bot/bootstrap.php';
              if (WB_Store::bot($conn, (int)$companyId) !== null
                  && (WB_Guard::isPaused($conn, (int)$companyId, (int)$activeContactId) || !empty($activeContact['assigned_to']))) {
                  echo '<button type="button" class="wa-bot-pill" id="wbResumeBtn" data-contact="' . (int)$activeContactId . '" title="Hand this chat back to the WAAMINI Bot (also removes the assignment)">Resume bot</button>';
                  echo '<script nonce="' . $waNonce . '">document.getElementById("wbResumeBtn").addEventListener("click",function(){var b=this;b.disabled=true;fetch("ajax_waamini_bot.php",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF-Token":' . json_encode(csrf_get_token()) . '},body:JSON.stringify({action:"resume_bot",contact_id:Number(b.dataset.contact)})}).then(function(r){return r.json()}).then(function(j){if(j.ok){location.reload()}else{b.disabled=false;alert(j.error||"Could not resume the bot.")}}).catch(function(){b.disabled=false;alert("Network problem.")})});</script>';
              }
          } catch (\Throwable $e) { /* bot module optional */ }
        ?>
        <button type="button" class="wa-ib" id="waFindBtn" title="Search" aria-label="Search messages"><?php echo $ico['search']; ?></button>
        <button type="button" class="wa-ib" id="waMenuBtn" title="Menu" aria-label="Menu"><?php echo $ico['dots']; ?></button>
      </div>
      <div class="wa-menu" id="waMenu" role="menu">
        <button type="button" onclick="toggleWaCrm(true)">Contact info</button>
        <button type="button" onclick="waFindOpen()">Search messages</button>
        <button type="button" id="waMenuAi">AI lead analysis</button>
        <a href="contacts.php?search=<?php echo urlencode((string)$activeContact['phone']); ?>">Open in contacts</a>
        <a href="whatsapp.php?tab=<?php echo $h($filterTab); ?>">Close chat</a>
      </div>
    </header>

    <div class="wa-findbar" id="waFindBar">
      <input type="text" id="waFindInput" placeholder="Search in this chat" autocomplete="off">
      <span id="waFindCount"></span>
      <button type="button" class="wa-ib" id="waFindUp" aria-label="Previous match"><?php echo $ico['up']; ?></button>
      <button type="button" class="wa-ib" id="waFindDown" aria-label="Next match"><?php echo $ico['down']; ?></button>
      <button type="button" class="wa-ib" id="waFindClose" aria-label="Close search"><?php echo $ico['x']; ?></button>
    </div>

    <!-- AI Lead Analysis bar — classifies the chat & applies via the SAME wa_outcome flow -->
    <div id="aiLeadBar" class="wa-ai-bar">
      <span style="font-weight:700;letter-spacing:.4px;color:#7a5b00;white-space:nowrap;">AI ANALYSIS</span>
      <span id="aiLeadBadge" style="padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;"></span>
      <span id="aiLeadReason" style="color:#41525d;flex:1;min-width:180px;line-height:1.4;"></span>
      <button type="button" id="aiLeadApply" class="btn btn-sm btn-primary" style="display:none;padding:4px 12px;font-size:11.5px;white-space:nowrap;">✓ Apply → Drip</button>
      <span id="aiLeadCurrent" style="display:none;font-size:11px;color:#06705c;font-weight:700;white-space:nowrap;">✓ Already set</span>
      <button type="button" id="aiLeadRefresh" class="btn btn-sm btn-edit" title="Re-analyze conversation" style="padding:4px 10px;font-size:11.5px;">↻</button>
    </div>
    <form method="POST" id="aiLeadApplyForm" action="whatsapp.php?c=<?php echo (int)$activeContactId . $tabQ; ?>" style="display:none;">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="wa_outcome" id="aiLeadOutcomeInput" value="">
    </form>

    <div class="wa-chat-messages" id="chatWindow" data-last-id="<?php echo (int)$lastMsgId; ?>">
      <div class="wa-sysmsg"><span><?php echo $ico['lock']; ?> Messages are sent from your WhatsApp Business number. The customer sees them in their WhatsApp.</span></div>
      <?php $prevDay = ''; foreach ($messages as $m):
        $ts = strtotime((string)($m['created_at'] ?? ''));
        $dayKey = $ts ? date('Y-m-d', $ts) : '';
        if ($dayKey !== '' && $dayKey !== $prevDay): $prevDay = $dayKey; ?>
          <div class="wa-date-sep" data-day="<?php echo $dayKey; ?>"><span><?php echo $h(wa_day_label((int)$ts)); ?></span></div>
        <?php endif;
        echo wa_chat_row_html($m);
      endforeach; ?>
      <?php if (!$messages): ?><div class="wa-sysmsg"><span>No messages yet. Say hello 👋</span></div><?php endif; ?>
    </div>
    <button type="button" class="wa-scroll-down" id="waScrollDown" aria-label="Scroll to latest message"><?php echo $ico['down']; ?><i id="waNewCount"></i></button>

    <div class="wa-chat-input">
      <?php if ($canSend): ?>
        <?php if (!$windowOpen): ?>
          <div class="wa-closed-note"><?php echo $ico['lock']; ?> The 24-hour reply window is closed, so normal messages may not be delivered. Send an approved template to restart the chat. <a href="templates.php">Templates</a></div>
        <?php endif; ?>
        <div class="wa-file-chip" id="waFileChip"><span id="waFileName"></span><button type="button" id="waFileClear" aria-label="Remove attachment">×</button></div>
        <form method="POST" action="/api/send_message.php" id="chatForm" autocomplete="off" enctype="multipart/form-data">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="contact_id" value="<?php echo (int)$activeContactId; ?>">
          <input type="file" name="media_file" id="waMediaFile" style="display:none;">
          <div class="wa-cmp">
            <button type="button" class="wa-ib" id="waBtnAttach" title="Attach" aria-label="Attach"><?php echo $ico['plus']; ?></button>
            <div class="wa-cmp-box">
              <button type="button" class="wa-ib" id="waBtnEmoji" title="Emoji" aria-label="Emoji"><?php echo $ico['smile']; ?></button>
              <textarea name="message_text" id="messageInput" rows="1" placeholder="Type a message"></textarea>
            </div>
            <button type="submit" name="send_message" class="wa-send" id="sendBtn" title="Send" aria-label="Send"><?php echo $ico['send']; ?></button>
          </div>
        </form>

        <div class="wa-pop wa-attach" id="waAttachMenu" role="menu">
          <button type="button" data-attach="doc"><i style="color:#7f66ff"><svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg></i>Document</button>
          <button type="button" data-attach="media"><i style="color:#007bfc"><svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg></i>Photos &amp; videos</button>
          <?php if (!empty($quickReplies)): ?><button type="button" data-attach="quick"><i style="color:#ff2e74"><svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg></i>Quick replies</button><?php endif; ?>
          <button type="button" data-attach="product"><i style="color:#02a698"><svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></i>Product catalog</button>
          <?php if (has_feature('feat_native_checkout')): ?><button type="button" data-attach="pay"><i style="color:#ffbc38"><svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></i>Payment link</button><?php endif; ?>
          <button type="button" data-attach="quote"><i style="color:#00a5f4"><svg fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M13 8H7"/><path d="M17 12H7"/></svg></i>Quotation</button>
        </div>

        <div class="wa-pop wa-emoji" id="waEmojiPop">
          <div class="wa-pop-h"><b>Emoji</b></div>
          <div class="wa-emoji-grid"><?php foreach ($emojis as $em): ?><button type="button"><?php echo $em; ?></button><?php endforeach; ?></div>
        </div>

        <div class="wa-pop wa-quick" id="waQuickPopover">
          <div class="wa-pop-h"><b>Quick replies</b><a href="quick_replies.php">Manage</a></div>
          <?php foreach ($quickReplies as $qr): ?>
            <div class="wa-qr-item" data-qr="<?php echo $h($qr['message']); ?>"><div class="sc">/<?php echo $h($qr['shortcut']); ?></div><div class="msg"><?php echo $h($qr['message']); ?></div></div>
          <?php endforeach; ?>
        </div>

        <?php if (has_feature('feat_native_checkout')): ?>
        <div class="wa-pop wa-form-pop" id="waPayPop">
          <div class="wa-pop-h"><b>Request payment</b><button type="button" data-close>Close</button></div>
          <label for="payAmount">Amount (₹)</label><input type="number" id="payAmount" min="1" step="0.01" placeholder="500">
          <label for="payDesc">What is it for?</label><input type="text" id="payDesc" maxlength="120" placeholder="e.g. Website design advance">
          <button type="button" class="go" id="paySend">Send payment link</button>
        </div>
        <?php endif; ?>

        <div class="wa-pop wa-form-pop" id="waQuotePop">
          <div class="wa-pop-h"><b>Send quotation</b><button type="button" data-close>Close</button></div>
          <?php if (!empty($quoteProducts)): ?>
            <label for="quoteProductSelect">Product</label>
            <select id="quoteProductSelect"><option value="">Choose a product…</option><?php foreach ($quoteProducts as $qp): ?><option value="<?php echo (float)$qp['price']; ?>" data-name="<?php echo $h($qp['name']); ?>"><?php echo $h($qp['name']); ?> (₹<?php echo number_format((float)$qp['price'], 2); ?>)</option><?php endforeach; ?></select>
          <?php endif; ?>
          <label for="quoteItem">Item</label><input type="text" id="quoteItem" maxlength="120" placeholder="Item name">
          <div class="row2">
            <div><label for="quotePrice">Price (₹)</label><input type="number" id="quotePrice" min="0" step="0.01" placeholder="0.00"></div>
            <div><label for="quoteDiscount">Discount (₹)</label><input type="number" id="quoteDiscount" min="0" step="0.01" placeholder="0"></div>
          </div>
          <div class="total"><span>Total</span><b id="quoteFinalTotal">₹0.00</b></div>
          <button type="button" class="go" id="quoteSend">Send quotation</button>
        </div>
        <script nonce="<?php echo $waNonce; ?>">
        // Composer controls (+ menu, emoji, file pickers, quick replies, payment, quotation).
        // Kept in their own small script right after the composer, so an error anywhere else on
        // the page can never stop the + menu from opening.
        (function () {
          var ids = ['waAttachMenu', 'waEmojiPop', 'waQuickPopover', 'waPayPop', 'waQuotePop'];
          var pops = ids.map(function (id) { return document.getElementById(id); }).filter(Boolean);
          var file = document.getElementById('waMediaFile');
          var input = document.getElementById('messageInput');
          function closeAll() { pops.forEach(function (p) { p.classList.remove('show'); }); }
          function toggle(id) {
            pops.forEach(function (p) {
              var open = p.id === id && !p.classList.contains('show');
              // clear anything an animation left behind, so an open menu is always fully visible
              if (open) { p.style.opacity = ''; p.style.transform = ''; p.style.visibility = ''; }
              p.classList.toggle('show', open);
            });
          }
          function chip(f) {
            var c = document.getElementById('waFileChip'), n = document.getElementById('waFileName');
            if (!c) return;
            c.style.display = f ? 'flex' : 'none';
            if (n) n.textContent = f ? '📎 ' + (f.name || 'attachment') : '';
            if (!f && file) file.value = '';
          }
          var attach = document.getElementById('waBtnAttach'), emoji = document.getElementById('waBtnEmoji');
          if (attach) attach.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); toggle('waAttachMenu'); });
          if (emoji) emoji.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); toggle('waEmojiPop'); });
          document.addEventListener('click', function (e) {
            if (!e.target.closest || !e.target.closest('.wa-pop')) closeAll();
          });
          pops.forEach(function (p) {
            var x = p.querySelector('[data-close]');
            if (x) x.addEventListener('click', function () { p.classList.remove('show'); });
          });
          document.querySelectorAll('#waAttachMenu [data-attach]').forEach(function (b) {
            b.addEventListener('click', function (e) {
              e.stopPropagation();
              var k = b.getAttribute('data-attach');
              if (k === 'doc' || k === 'media') {
                closeAll();
                if (!file) return;
                file.accept = k === 'doc' ? '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.ppt,.pptx' : 'image/*,video/*';
                file.click();
              } else if (k === 'product') {
                closeAll();
                if (input) {
                  input.value += (input.value ? '\n' : '') + 'View our catalog: ' + window.location.origin + '/client/catalog.php';
                  input.focus();
                  input.dispatchEvent(new Event('input'));
                }
              } else {
                toggle({ quick: 'waQuickPopover', pay: 'waPayPop', quote: 'waQuotePop' }[k]);
              }
            });
          });
          if (file) file.addEventListener('change', function () { chip(file.files && file.files[0] ? file.files[0] : null); });
          var clear = document.getElementById('waFileClear');
          if (clear) clear.addEventListener('click', function () { chip(null); });
        })();
        </script>
      <?php else: ?>
        <div class="wa-composer-note">Sending is turned off for this account.</div>
      <?php endif; ?>
    </div>
  </main>

  <!-- Contact info (CRM) -->
  <aside class="wa-crm-sidebar<?php echo $isCrmOpen ? ' open' : ''; ?>" id="waCrmSidebar">
    <div class="wa-crm-h">
      <button type="button" class="wa-ib" onclick="toggleWaCrm(false)" aria-label="Close contact info"><?php echo $ico['x']; ?></button>
      <span>Contact info</span>
    </div>
    <?php
      $currentOutcome = wa_labels_get_outcome((string)($activeContact['chat_labels'] ?? ''));
      $outcomes = ['follow-up' => ['Follow-up', '#1d6fd6'], 'converted' => ['Converted', '#06705c'], 'declined' => ['Declined', '#c62828'], 'not_interested' => ['Not interested', '#a15c00']];
      $curColor = '#667781';
      foreach ($outcomes as $o) if (strcasecmp($o[0], $currentOutcome) === 0) $curColor = $o[1];
      $curG = (int)($activeContact['group_id'] ?? 0);
    ?>
    <div class="wa-crm-body">
      <div class="wa-crm-profile">
        <?php echo $avatar($hdrName, $hdrDisplay); ?>
        <div class="nm"><?php echo $h($hdrDisplay); ?></div>
        <div class="ph"><?php echo $h($activeContact['phone']); ?></div>
        <span class="win<?php echo $windowOpen ? ' open' : ''; ?>"><?php echo $h($windowText); ?></span>
        <?php if (!empty($activeContact['ai_score_updated'])):
          $aiSc = (int)($activeContact['ai_lead_score'] ?? 0);
          $aiBits = array_filter([(string)($activeContact['client_company'] ?? ''), (string)($activeContact['city'] ?? ''), (string)($activeContact['email'] ?? ''), ucfirst((string)($activeContact['ai_sentiment'] ?? '')), (string)($activeContact['language'] ?? '')]); ?>
          <div style="margin-top:12px;font-size:12.5px;color:var(--wa-sub)"><b style="color:<?php echo $aiSc >= 70 ? '#0B6B5E' : ($aiSc >= 40 ? '#92580a' : '#A12222'); ?>">AI score <?php echo $aiSc; ?></b><?php echo $aiBits ? ' · ' . $h(implode(' · ', $aiBits)) : ''; ?></div>
        <?php endif; ?>
      </div>

      <?php
        // Ads & Attribution: which ad(s) this customer came from
        $adTouches = [];
        if (has_feature('ads_attribution')) {
            require_once __DIR__ . '/../services/AttributionService.php';
            $adTouches = AttributionService::touches($conn, (int)$companyId, (int)$activeContactId, 10);
        }
      ?>
      <?php if (has_feature('ads_attribution')): ?>
      <div class="wa-crm-sec wa-adsrc">
        <h4>Lead source <b style="background:<?php echo $adTouches ? '#E6FCF2;color:#0B6B5E' : '#EEF1F0;color:#667781'; ?>"><?php echo $adTouches ? ($adTouches[0]['source_type'] === 'post' ? 'Post' : 'Ad') : 'Organic'; ?></b></h4>
        <?php if (!$adTouches): ?>
          <div class="hint" style="margin-top:0">This customer did not come from a Click-to-WhatsApp ad, or messaged before tracking started.</div>
        <?php else: $t0 = $adTouches[0]; $hasPendingThumb = false; ?>
          <div class="wa-ad">
            <?php if ($t0['local_media_path']): ?><img src="ad_media.php?r=<?php echo (int)$t0['id']; ?>" alt="" loading="lazy">
            <?php else: if ($t0['media_status'] === 'pending') $hasPendingThumb = true; ?><span class="wa-ad-ph"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><?php echo $t0['media_type'] === 'video' ? '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>' : '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>'; ?></svg></span><?php endif; ?>
            <div>
              <b><?php echo $h($t0['ad_name'] ?: ($t0['headline'] ?: 'Click-to-WhatsApp ad')); ?></b>
              <?php if ($t0['source_id']): ?><span class="wa-ad-id">ID <?php echo $h($t0['source_id']); ?> <button type="button" class="wa-ad-copy" data-copy="<?php echo $h($t0['source_id']); ?>" title="Copy ad ID">Copy</button></span><?php endif; ?>
              <span class="wa-ad-when">Tapped <?php echo $h(date('d M Y, h:i A', strtotime((string)$t0['clicked_at']))); ?></span>
            </div>
          </div>
          <?php if ($t0['source_id'] || $t0['headline']): ?><a class="wa-ad-all" href="ads_leads.php?ad=<?php echo urlencode($t0['source_id'] !== '' && $t0['source_id'] !== null ? $t0['source_id'] : 'h:' . mb_substr((string)$t0['headline'], 0, 60)); ?>&amp;range=custom&amp;from=<?php echo date('Y-m-d', strtotime('-365 days')); ?>&amp;to=<?php echo date('Y-m-d'); ?>">View all leads from this ad →</a><?php endif; ?>
          <?php if (count($adTouches) > 1): ?>
            <div class="wa-ad-hist"><span>Earlier ads (<?php echo count($adTouches) - 1; ?>)</span>
              <?php foreach (array_slice($adTouches, 1) as $t): ?>
                <div><?php echo $h($t['ad_name'] ?: ($t['headline'] ?: ($t['source_type'] === 'post' ? 'Post' : 'Ad'))); ?> <em><?php echo $h(date('d M Y', strtotime((string)$t['clicked_at']))); ?></em></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php
            // Conversions API: what this customer's stage changes sent to Meta
            if (has_feature('ads_capi')) {
                require_once __DIR__ . '/../services/CapiService.php';
                ads_ensure_capi_schema($conn);
                $stCe = $conn->prepare("SELECT event_name, status, last_error FROM capi_events WHERE company_id=? AND contact_id=? ORDER BY id DESC LIMIT 5");
                $stCe->bind_param('ii', $companyId, $activeContactId);
                $stCe->execute();
                $capiRows = $stCe->get_result()->fetch_all(MYSQLI_ASSOC);
                if ($capiRows): ?>
            <div class="wa-ad-hist"><span>Sent to Meta</span>
              <?php foreach ($capiRows as $ce): $lbl = ['sent' => 'Sent', 'pending' => 'Waiting', 'processing' => 'Sending', 'failed' => 'Failed', 'skipped' => 'Skipped'][$ce['status']] ?? $ce['status']; ?>
                <div title="<?php echo $h((string)$ce['last_error']); ?>"><?php echo $h(CapiService::EVENTS[$ce['event_name']] ?? $ce['event_name']); ?> <em><?php echo $h($lbl); ?></em></div>
              <?php endforeach; ?>
            </div>
          <?php endif; } ?>
          <?php if ($hasPendingThumb): ?><script nonce="<?php echo $waNonce; ?>">fetch('../api/ads_media_tick.php', { credentials: 'same-origin' }).catch(function () {});</script><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="wa-crm-sec">
        <h4>Lead outcome <b style="background:<?php echo $curColor; ?>1f;color:<?php echo $curColor; ?>"><?php echo $h($currentOutcome !== '' ? $currentOutcome : 'Not set'); ?></b></h4>
        <div class="wa-outcomes">
          <?php foreach ($outcomes as $val => [$lbl, $col]): ?>
            <form method="POST" action="whatsapp.php?c=<?php echo (int)$activeContactId . $tabQ; ?>&crm=open">
              <?php echo csrf_field(); ?><input type="hidden" name="wa_outcome" value="<?php echo $val; ?>">
              <button type="submit" class="<?php echo strcasecmp($lbl, $currentOutcome) === 0 ? 'on' : ''; ?>" style="color:<?php echo $col; ?>"><i style="background:<?php echo $col; ?>"></i><?php echo $lbl; ?></button>
            </form>
          <?php endforeach; ?>
        </div>
        <div class="hint">Follow-up adds the <b>Follow-up</b> label, which starts your Drip Campaigns. Converted, Declined and Not interested stop pending drip messages.</div>
      </div>

      <form method="POST" action="whatsapp.php?c=<?php echo (int)$activeContactId . $tabQ; ?>&crm=open" style="display:contents">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="update_crm" value="1">
        <div class="wa-crm-sec">
          <h4>About</h4>
          <label for="crmName">Name</label>
          <input type="text" id="crmName" name="contact_name" class="form-control" value="<?php echo $h($activeContact['name'] ?? ''); ?>" placeholder="Customer name">
          <?php if (array_key_exists('won_value', $activeContact)): ?>
            <label for="crmWon">Sale value (₹)</label>
            <input type="text" id="crmWon" name="won_value" class="form-control" inputmode="decimal" value="<?php echo $activeContact['won_value'] !== null ? $h(rtrim(rtrim(number_format((float)$activeContact['won_value'], 2, '.', ''), '0'), '.')) : ''; ?>" placeholder="e.g. 4999">
            <div class="hint">Enter the amount when this lead buys (mark them <b>Converted</b>). Ad Leads adds it up per ad.</div>
          <?php endif; ?>
        </div>
        <div class="wa-crm-sec">
          <h4>Group &amp; assignment</h4>
          <label for="crmGroup">Contact group</label>
          <select id="crmGroup" name="group_id" class="form-control">
            <option value="0"<?php echo $curG === 0 ? ' selected' : ''; ?>>No group</option>
            <?php foreach (($contactGroups ?? []) as $gr): ?><option value="<?php echo (int)$gr['id']; ?>"<?php echo $curG === (int)$gr['id'] ? ' selected' : ''; ?>><?php echo $h($gr['name']); ?></option><?php endforeach; ?>
          </select>
          <label for="crmAssign">Assigned to</label>
          <select id="crmAssign" name="assigned_to" class="form-control">
            <option value="0"<?php echo empty($activeContact['assigned_to']) ? ' selected' : ''; ?>>Unassigned</option>
            <?php foreach ($teamMembers as $tm): ?><option value="<?php echo (int)$tm['id']; ?>"<?php echo ((int)($activeContact['assigned_to'] ?? 0) === (int)$tm['id']) ? ' selected' : ''; ?>><?php echo $h($tm['name']); ?> (<?php echo $h($tm['role']); ?>)</option><?php endforeach; ?>
          </select>
          <div class="hint"><?php echo empty($contactGroups) ? 'No groups yet. ' : ''; ?><a href="contact_groups.php"><?php echo empty($contactGroups) ? '+ Create group' : 'Manage groups'; ?></a></div>
        </div>
        <div class="wa-crm-sec">
          <h4>Labels</h4>
          <input type="text" name="chat_labels" class="form-control" value="<?php echo $h($activeContact['chat_labels'] ?? ''); ?>" placeholder="VIP, Lead, Pending">
          <div class="hint">Separate labels with commas. Labels can start Drip Campaigns.</div>
        </div>
        <div class="wa-crm-sec">
          <h4>Notes</h4>
          <textarea name="notes" class="form-control" rows="5" style="resize:vertical" placeholder="Only your team sees these notes"><?php echo $h($activeContact['notes'] ?? ''); ?></textarea>
        </div>
        <div class="wa-crm-save">
          <button type="submit" class="p">Save</button>
          <button type="button" class="s" onclick="toggleWaCrm(false)">Close</button>
        </div>
      </form>
    </div>
  </aside>
  <?php endif; ?>
</div>

<script nonce="<?php echo $waNonce; ?>">
function toggleWaSidebar(open) {
  const sidebar = document.getElementById('waSidebar'), overlay = document.getElementById('waSidebarOverlay');
  if (!sidebar || !overlay) return;
  sidebar.classList.toggle('open', !!open);
  overlay.style.display = open ? 'block' : 'none';
  overlay.classList.toggle('show', !!open);
}
document.getElementById('waSidebarOverlay')?.addEventListener('click', () => toggleWaSidebar(false));
function toggleWaCrm(open) {
  try { toggleWaSidebar(false); } catch (e) {}
  document.getElementById('waCrmSidebar')?.classList.toggle('open', !!open);
  document.getElementById('waMenu')?.classList.remove('show');
}
// Ask WAAMINI Bot (start screen): private test chat with the company's own bot, like "Ask Meta AI"
(function () {
  const panel = document.getElementById('waAskBot'), home = document.getElementById('waLandHome');
  if (!panel || !home) return;
  const msgs = document.getElementById('waAskMsgs'), form = document.getElementById('waAskForm'), input = document.getElementById('waAskIn');
  const token = document.getElementById('csrfToken')?.value || '';
  let history = [], busy = false;
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = s => esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/https?:\/\/[^\s<]+/g, u => '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>').replace(/\n/g, '<br>');
  const now = () => new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  function open() { home.hidden = true; panel.hidden = false; setTimeout(() => input.focus(), 50); }
  function close() { panel.hidden = true; home.hidden = false; }
  function row(dir, html) {
    const r = document.createElement('div');
    r.className = 'wa-message-row ' + dir;
    r.innerHTML = '<div class="wa-message-bubble"><div class="wa-message-text">' + html + '</div><div class="wa-message-meta"><span>' + now() + '</span></div></div>';
    msgs.appendChild(r); msgs.scrollTop = msgs.scrollHeight; return r;
  }
  async function ask(text) {
    text = text.trim();
    if (!text || busy) return;
    busy = true;
    document.getElementById('waAskSugg')?.remove();
    row('outgoing', esc(text));
    history.push({ role: 'user', content: text });
    const typing = row('incoming', '<span class="wa-typing"><i></i><i></i><i></i></span>');
    try {
      const r = await fetch('ajax_waamini_bot.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ action: 'test_chat', messages: history.slice(-12), _token: token }) });
      const d = await r.json().catch(() => null);
      typing.remove();
      if (!d || !d.ok || d.error || !d.text) {
        row('incoming', '<span class="wa-bot-note">' + esc((d && (d.error || (d.ok && !d.text ? 'Your bot had no answer. Add more knowledge in WAAMINI Bot settings.' : ''))) || 'The bot could not reply. Check WAAMINI Bot settings.') + '</span>');
      } else {
        history.push({ role: 'assistant', content: d.text });
        let extra = '';
        if (d.handoff || String(d.outcome || '').indexOf('handoff') === 0) extra = '<span class="wa-bot-note">With a real customer, this chat would now go to your team.</span>';
        row('incoming', fmt(d.text) + extra);
      }
    } catch (e) {
      typing.remove();
      row('incoming', '<span class="wa-bot-note">Network problem. Please try again.</span>');
    } finally { busy = false; input.focus(); }
  }
  document.querySelectorAll('[data-askbot]').forEach(b => b.addEventListener('click', open));
  document.getElementById('waAskClose').addEventListener('click', close);
  document.getElementById('waAskReset').addEventListener('click', () => { history = []; msgs.querySelectorAll('.wa-message-row').forEach(n => n.remove()); input.focus(); });
  document.querySelectorAll('#waAskSugg button').forEach(b => b.addEventListener('click', () => ask(b.textContent)));
  form.addEventListener('submit', e => { e.preventDefault(); const t = input.value; input.value = ''; input.style.height = '40px'; ask(t); });
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  input.addEventListener('input', () => { input.style.height = '40px'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; });
})();

// Lead source card: copy the ad ID
document.addEventListener('click', e => {
  const b = e.target.closest('.wa-ad-copy'); if (!b) return;
  if (navigator.clipboard) navigator.clipboard.writeText(b.dataset.copy);
  b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1500);
});
// Phones: with no chat open, show the chat list straight away.
// phones: with no chat open the chat list already fills the screen (see the phone CSS), so no slide-in panel

// Chat list search + Unread filter
(function () {
  const search = document.getElementById('contactSearch'), chip = document.getElementById('waUnreadChip');
  function apply() {
    const q = (search?.value || '').toLowerCase().trim(), onlyUnread = chip?.classList.contains('on');
    document.querySelectorAll('#contactList .wa-contact-item').forEach(el => {
      const ok = (!q || (el.textContent || '').toLowerCase().includes(q)) && (!onlyUnread || el.classList.contains('has-unread'));
      el.style.display = ok ? '' : 'none';
    });
  }
  search?.addEventListener('input', apply);
  chip?.addEventListener('click', () => { chip.classList.toggle('on'); apply(); });
  window.waApplyListFilter = apply;
})();

const chatWindow = document.getElementById('chatWindow');
const activeContactId = <?php echo (int)$activeContactId; ?>;
if (chatWindow) chatWindow.scrollTop = chatWindow.scrollHeight;

// Header ⋮ menu
(function () {
  const btn = document.getElementById('waMenuBtn'), menu = document.getElementById('waMenu');
  btn?.addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('show'); });
  document.addEventListener('click', e => { if (menu && !menu.contains(e.target)) menu.classList.remove('show'); });
  document.getElementById('waMenuAi')?.addEventListener('click', () => { menu.classList.remove('show'); document.getElementById('aiLeadRefresh')?.click(); });
})();

// Search inside the conversation
(function () {
  const bar = document.getElementById('waFindBar'), input = document.getElementById('waFindInput'), countEl = document.getElementById('waFindCount');
  if (!bar || !chatWindow) return;
  let hits = [], cur = -1;
  function clear() { chatWindow.querySelectorAll('.wa-hit').forEach(r => r.classList.remove('wa-hit', 'cur')); hits = []; cur = -1; countEl.textContent = ''; }
  function go(i) {
    if (!hits.length) return;
    cur = (i + hits.length) % hits.length;
    hits.forEach((r, k) => r.classList.toggle('cur', k === cur));
    hits[cur].scrollIntoView({ block: 'center', behavior: 'smooth' });
    countEl.textContent = (cur + 1) + ' of ' + hits.length;
  }
  function run() {
    clear();
    const q = input.value.trim().toLowerCase();
    if (!q) return;
    hits = [...chatWindow.querySelectorAll('.wa-message-row')].filter(r => (r.querySelector('.wa-message-bubble')?.textContent || '').toLowerCase().includes(q));
    hits.forEach(r => r.classList.add('wa-hit'));
    if (hits.length) go(hits.length - 1); else countEl.textContent = 'No results';
  }
  window.waFindOpen = function () { document.getElementById('waMenu')?.classList.remove('show'); bar.classList.add('show'); input.focus(); };
  document.getElementById('waFindBtn')?.addEventListener('click', () => bar.classList.contains('show') ? (bar.classList.remove('show'), clear()) : waFindOpen());
  document.getElementById('waFindClose')?.addEventListener('click', () => { bar.classList.remove('show'); input.value = ''; clear(); });
  document.getElementById('waFindUp')?.addEventListener('click', () => go(cur - 1));
  document.getElementById('waFindDown')?.addEventListener('click', () => go(cur + 1));
  let t; input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 200); });
  input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(cur + (e.shiftKey ? 1 : -1)); } if (e.key === 'Escape') document.getElementById('waFindClose').click(); });
})();

// Composer: attach menu, emoji, quick replies, payment link, quotation
const chatForm = document.getElementById('chatForm');
const mediaInput = document.getElementById('waMediaFile');
const msgInput = document.getElementById('messageInput');
const fileChip = document.getElementById('waFileChip');
const csrf = document.getElementById('csrfToken')?.value || '';
function waToast(msg) {
  let t = document.getElementById('waToast');
  if (!t) { t = document.createElement('div'); t.id = 'waToast'; t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#111b21;color:#fff;padding:10px 18px;border-radius:8px;font-size:14px;z-index:99999;box-shadow:0 6px 20px rgba(0,0,0,.25)'; document.body.appendChild(t); }
  t.textContent = msg; t.style.display = 'block'; clearTimeout(t._h); t._h = setTimeout(() => { t.style.display = 'none'; }, 3200);
}
function setAttachment(file) {
  if (!fileChip) return;
  fileChip.style.display = file ? 'flex' : 'none';
  document.getElementById('waFileName').textContent = file ? '📎 ' + (file.name || 'attachment') : '';
  if (!file && mediaInput) mediaInput.value = '';
}
function waAutoGrow() { if (!msgInput) return; msgInput.style.height = '40px'; msgInput.style.height = Math.min(msgInput.scrollHeight, 120) + 'px'; }
function waInsert(txt) {
  if (!msgInput) return;
  const s = msgInput.selectionStart ?? msgInput.value.length, e = msgInput.selectionEnd ?? s;
  msgInput.value = msgInput.value.slice(0, s) + txt + msgInput.value.slice(e);
  msgInput.selectionStart = msgInput.selectionEnd = s + txt.length;
  msgInput.focus(); waAutoGrow();
}
const pops = ['waAttachMenu', 'waEmojiPop', 'waQuickPopover', 'waPayPop', 'waQuotePop'].map(id => document.getElementById(id)).filter(Boolean);
function waPop(id) { pops.forEach(p => p.classList.toggle('show', p.id === id && !p.classList.contains('show'))); }
// + menu, emoji and file pickers are wired in the small script right after the composer
document.querySelectorAll('#waEmojiPop .wa-emoji-grid button').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); waInsert(b.textContent); }));
document.querySelectorAll('#waQuickPopover .wa-qr-item').forEach(el => el.addEventListener('click', () => {
  msgInput.value = el.getAttribute('data-qr') || ''; waAutoGrow(); msgInput.focus(); pops.forEach(p => p.classList.remove('show'));
}));
msgInput?.addEventListener('input', waAutoGrow);
// Enter sends, Shift+Enter adds a new line — like WhatsApp Web
msgInput?.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); chatForm.requestSubmit(); } });

async function waPost(url, data) {
  const fd = new FormData();
  Object.keys(data).forEach(k => fd.append(k, data[k]));
  fd.append('contact_id', String(activeContactId));
  fd.append('_token', csrf);
  const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
  return r.json().catch(() => ({ status: 'error', message: 'Invalid response. Refresh and try again.' }));
}
document.getElementById('paySend')?.addEventListener('click', async () => {
  const amount = document.getElementById('payAmount').value, desc = document.getElementById('payDesc').value;
  if (!amount || +amount <= 0) return waToast('Enter an amount.');
  const d = await waPost('ajax_send_payment.php', { amount: amount, description: desc }).catch(() => null);
  if (d && d.status === 'success') { waToast('Payment link sent'); pops.forEach(p => p.classList.remove('show')); pollChat(); }
  else waToast((d && d.message) || 'Could not send the payment link.');
});
function quoteTotal() {
  const p = parseFloat(document.getElementById('quotePrice')?.value) || 0, d = parseFloat(document.getElementById('quoteDiscount')?.value) || 0;
  const el = document.getElementById('quoteFinalTotal');
  if (el) el.textContent = '₹' + Math.max(0, p - d).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}
document.getElementById('quoteProductSelect')?.addEventListener('change', function () {
  const o = this.options[this.selectedIndex];
  if (o.value) { document.getElementById('quoteItem').value = o.dataset.name; document.getElementById('quotePrice').value = o.value; quoteTotal(); }
});
['quotePrice', 'quoteDiscount'].forEach(id => document.getElementById(id)?.addEventListener('input', quoteTotal));
document.getElementById('quoteSend')?.addEventListener('click', async () => {
  const item = document.getElementById('quoteItem').value.trim(), price = document.getElementById('quotePrice').value;
  if (!item || !price) return waToast('Enter the item and price.');
  const d = await waPost('ajax_send_quote.php', { item: item, price: price, discount: document.getElementById('quoteDiscount').value || '0' }).catch(() => null);
  if (d && d.status === 'success') { waToast('Quotation sent'); pops.forEach(p => p.classList.remove('show')); pollChat(); }
  else waToast((d && d.message) || 'Could not send the quotation.');
});

// Send (text + media) through the API; the message shows up at once via pollChat()
chatForm?.addEventListener('submit', async e => {
  e.preventDefault();
  const hasFile = mediaInput && mediaInput.files && mediaInput.files.length;
  if (!msgInput.value.trim() && !hasFile) return;
  const fd = new FormData(chatForm);
  const btn = document.getElementById('sendBtn');
  btn.disabled = true;
  try {
    const res = await fetch(chatForm.action, { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json().catch(() => null);
    if (!res.ok || !j || j.success !== true) { waToast((j && (j.error || j.message)) || 'Message not sent. Please try again.'); return; }
    msgInput.value = ''; waAutoGrow(); setAttachment(null);
    await pollChat(true);
  } catch (err) {
    waToast('Network problem. Message not sent.');
  } finally { btn.disabled = false; msgInput.focus(); }
});

// Live updates: new messages, date chips, ticks (✓ → ✓✓ → blue) and the scroll-down button
const scrollBtn = document.getElementById('waScrollDown'), newCountEl = document.getElementById('waNewCount');
let newWhileUp = 0;
function nearBottom() { return chatWindow.scrollHeight - chatWindow.scrollTop - chatWindow.clientHeight < 120; }
function toBottom(smooth) { chatWindow.scrollTo({ top: chatWindow.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }); }
chatWindow?.addEventListener('scroll', () => {
  const nb = nearBottom();
  scrollBtn?.classList.toggle('show', !nb);
  if (nb) { newWhileUp = 0; newCountEl?.classList.remove('show'); }
});
scrollBtn?.addEventListener('click', () => toBottom(true));
function lastDay() { const rows = chatWindow.querySelectorAll('[data-day]'); return rows.length ? rows[rows.length - 1].getAttribute('data-day') : ''; }
async function pollChat(forceScroll) {
  if (!activeContactId || !chatWindow) return;
  const lastId = parseInt(chatWindow.getAttribute('data-last-id') || '0', 10) || 0;
  try {
    const res = await fetch(`ajax_load_chat.php?json=1&c=${activeContactId}&after_id=${lastId}`, { credentials: 'same-origin' });
    if (!res.ok) return;
    const data = await res.json();
    const wasBottom = nearBottom();
    if (data && data.html) {
      const tmp = document.createElement('div');
      tmp.innerHTML = data.html;
      let incoming = 0;
      [...tmp.children].forEach(row => {
        if (chatWindow.querySelector('.wa-message-row[data-msgid="' + row.dataset.msgid + '"]')) return;
        const day = row.getAttribute('data-day');
        if (day && day !== lastDay()) {
          const sep = document.createElement('div');
          sep.className = 'wa-date-sep'; sep.setAttribute('data-day', day);
          sep.innerHTML = '<span></span>'; sep.firstChild.textContent = row.getAttribute('data-daylabel') || day;
          chatWindow.appendChild(sep);
        }
        if (row.classList.contains('incoming')) incoming++;
        chatWindow.appendChild(row);
      });
      if (data.last_id) chatWindow.setAttribute('data-last-id', String(data.last_id));
      if (forceScroll || wasBottom) toBottom(!forceScroll);
      else if (incoming) { newWhileUp += incoming; newCountEl.textContent = newWhileUp; newCountEl.classList.add('show'); scrollBtn.classList.add('show'); }
    }
    if (data && data.ticks) {
      Object.keys(data.ticks).forEach(id => {
        const t = chatWindow.querySelector('.wa-message-row[data-msgid="' + id + '"] .wa-tick');
        if (t && t.outerHTML !== data.ticks[id]) t.outerHTML = data.ticks[id];
      });
    }
  } catch (e) {}
}
if (activeContactId) setInterval(pollChat, 4000);
</script>

<script nonce="<?php echo $waNonce; ?>">
/* Live chat-list sync — keeps previews, times, unread badges and ordering in step with incoming WhatsApp messages */
(function () {
  var list = document.getElementById('contactList');
  if (!list) return;
  var activeId = <?php echo (int)$activeContactId; ?>;
  var tab = list.getAttribute('data-tab') || 'all';
  var busy = false, lastFull = 0;
  function reapply() { if (window.waApplyListFilter) window.waApplyListFilter(); }
  async function fullRefresh() {
    if (Date.now() - lastFull < 30000) return;
    lastFull = Date.now();
    try {
      var r = await fetch(window.location.href, { credentials: 'same-origin' });
      if (!r.ok) return;
      var doc = new DOMParser().parseFromString(await r.text(), 'text/html');
      var fresh = doc.getElementById('contactList');
      if (fresh) { list.innerHTML = fresh.innerHTML; reapply(); }
    } catch (e) {}
  }
  async function sync() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      var r = await fetch('../api/get_sidebar_updates.php', { credentials: 'same-origin' });
      if (!r.ok) return;
      var rows = await r.json();
      if (!Array.isArray(rows)) return;
      var unknown = false, ordered = [];
      rows.forEach(function (u) {
        var el = list.querySelector('.wa-contact-item[data-cid="' + u.id + '"]');
        if (!el) { unknown = true; return; }
        ordered.push(el);
        var sn = document.getElementById('contact-snippet-' + u.id);
        if (sn && u.last_snippet && sn.textContent !== u.last_snippet) sn.textContent = u.last_snippet;
        var tm = document.getElementById('contact-time-' + u.id);
        if (tm && u.chat_time && tm.textContent !== u.chat_time) tm.textContent = u.chat_time;
        var bd = document.getElementById('unread-badge-' + u.id);
        var n = parseInt(u.unread_count, 10) || 0;
        if (bd) {
          if (n > 0 && parseInt(u.id, 10) !== activeId) { bd.textContent = n; bd.style.display = ''; el.classList.add('has-unread'); }
          else { bd.style.display = 'none'; el.classList.remove('has-unread'); }
        }
      });
      var moveRows = function () { for (var i = ordered.length - 1; i >= 0; i--) list.prepend(ordered[i]); };
      if (window.WaaminiMotion) WaaminiMotion.reorderList(list, moveRows); else moveRows();
      reapply();
      if (unknown && tab === 'all') fullRefresh();
    } catch (e) {} finally { busy = false; }
  }
  setInterval(sync, 6000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(); });
})();
</script>

<?php
// Send message (canonical column is `content`)
if (isset($_POST['send_message']) && $activeContactId > 0) {
    csrf_verify();
    $msgRaw = (string)($_POST['message_text'] ?? '');
    $msgText = trim(str_replace("\0", '', preg_replace('/\r\n?/', "\n", strip_tags($msgRaw))));
    if (function_exists('mb_strlen') && mb_strlen($msgText, 'UTF-8') > 4096) $msgText = mb_substr($msgText, 0, 4096, 'UTF-8');
    elseif (strlen($msgText) > 4096) $msgText = substr($msgText, 0, 4096);

    if ($msgText !== '') {
        $stPh = $conn->prepare("SELECT phone FROM contacts WHERE id=? AND company_id=? LIMIT 1");
        if ($stPh) {
            $stPh->bind_param("ii", $activeContactId, $companyId);
            $stPh->execute();
            $row = $stPh->get_result()->fetch_assoc();
            $stPh->close();

            if ($row && !empty($row['phone'])) {
                $stmt = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, is_read, status) VALUES (?, ?, 'outgoing', 'text', ?, ?, 1, 'queued')");
                $newMsgId = 0;
                if ($stmt) {
                    $stmt->bind_param("iiss", $companyId, $activeContactId, $msgText, $msgText);
                    $stmt->execute();
                    $newMsgId = (int)$conn->insert_id;
                    $stmt->close();
                }
                // Invalidate company dashboard cache (message counts/recent)
                wa_cache_bump("company:{$companyId}");

                $stLm = $conn->prepare("UPDATE contacts SET last_message_at=NOW() WHERE id=? AND company_id=?");
                if ($stLm) {
                    $stLm->bind_param("ii", $activeContactId, $companyId);
                    $stLm->execute();
                    $stLm->close();
                }
                wa_cache_bump("company:{$companyId}");

                // A person replied: the WAAMINI Bot steps back for this chat until "Resume bot" is pressed
                try { require_once __DIR__ . '/../includes/waamini_bot/bootstrap.php'; WB_Guard::staffReplied($conn, (int)$companyId, (int)$activeContactId); } catch (\Throwable $e) { error_log('[WAAMINI Bot] pause hook: ' . $e->getMessage()); }
                // Async send via queue (never block UI)
                try {
                    require_once __DIR__ . '/../services/QueueService.php';
                    $toPhone = preg_replace('/\D+/', '', ltrim((string)$row['phone'], '+'));
                    if ($toPhone !== '') {
                        $enqueued = QueueService::enqueue($conn, [
                            'company_id' => $companyId,
                            'contact_id' => $activeContactId,
                            'type'       => 'text',
                            'phone'      => $toPhone,
                            'message'    => $msgText,
                            'payload'    => [
                                'message_id' => $newMsgId,
                                'source' => 'client_whatsapp_ui'
                            ],
                        ]);
                        if (!$enqueued && $newMsgId > 0) {
                            $failed = $conn->prepare("UPDATE messages SET status='failed', error_message='queue_enqueue_failed', updated_at=NOW() WHERE id=? AND company_id=?");
                            if ($failed) {
                                $failed->bind_param('ii', $newMsgId, $companyId);
                                $failed->execute();
                                $failed->close();
                            }
                            error_log("[WAAMINI whatsapp.php] Queue enqueue failed for company={$companyId}, message={$newMsgId}");
                        }
                    }
                } catch (\Throwable $e) {
                    if ($newMsgId > 0) {
                        $failed = $conn->prepare("UPDATE messages SET status='failed', error_message='queue_enqueue_failed', updated_at=NOW() WHERE id=? AND company_id=?");
                        if ($failed) {
                            $failed->bind_param('ii', $newMsgId, $companyId);
                            $failed->execute();
                            $failed->close();
                        }
                    }
                    error_log('[WAAMINI whatsapp.php enqueue] ' . $e->getMessage());
                }
            }
        }
    }
    header("Location: whatsapp.php?c=" . (int)$activeContactId . "&tab=" . urlencode($filterTab));
    exit();
}

// 🤖 AI Lead Analysis bar — fetches classification & applies via the standard wa_outcome flow
if ($activeContact): ?>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var bar = document.getElementById('aiLeadBar');
  if (!bar) return;
  var badge = document.getElementById('aiLeadBadge');
  var reasonEl = document.getElementById('aiLeadReason');
  var applyBtn = document.getElementById('aiLeadApply');
  var currentEl = document.getElementById('aiLeadCurrent');
  var refreshBtn = document.getElementById('aiLeadRefresh');
  var form = document.getElementById('aiLeadApplyForm');
  var outcomeInput = document.getElementById('aiLeadOutcomeInput');
  var tokenInput = form ? form.querySelector('input[name="_token"]') : null;
  var contactId = <?php echo (int)$activeContactId; ?>;

  var STYLE = {
    follow_up:      { bg: 'rgba(52,152,219,0.15)', col: '#1d6fd6', outcome: 'follow-up' },
    converted:      { bg: 'rgba(0,200,83,0.14)',   col: '#06705c', outcome: 'converted' },
    declined:       { bg: 'rgba(255,71,87,0.14)',  col: '#c62828', outcome: 'declined' },
    not_interested: { bg: 'rgba(255,167,38,0.14)', col: '#a15c00', outcome: 'not_interested' },
    insufficient:   { bg: 'rgba(11,20,26,0.06)', col: 'var(--text-muted)', outcome: '' }
  };

  function render(d) {
    var s = STYLE[d.status] || STYLE.insufficient;
    bar.style.display = 'flex';
    badge.textContent = d.label + (d.confidence ? ' · ' + d.confidence + '%' : '');
    badge.style.background = s.bg;
    badge.style.color = s.col;
    var txt = d.reason || '';
    if (d.next_action) txt += '  →  ' + d.next_action;
    reasonEl.textContent = txt;

    var applicable = s.outcome !== '' && d.status !== 'insufficient';
    var alreadySet = applicable && d.current_outcome === d.label;
    applyBtn.style.display = (applicable && !alreadySet) ? 'inline-flex' : 'none';
    currentEl.style.display = alreadySet ? 'inline' : 'none';
    applyBtn.dataset.outcome = s.outcome;
    applyBtn.dataset.label = d.label;
  }

  function analyze(force) {
    badge.textContent = 'Analyzing…';
    badge.style.background = 'rgba(11,20,26,0.06)';
    badge.style.color = 'var(--text-muted)';
    reasonEl.textContent = '';
    applyBtn.style.display = 'none';
    currentEl.style.display = 'none';
    bar.style.display = 'flex';

    var body = new URLSearchParams();
    body.set('contact_id', String(contactId));
    if (force) body.set('force', '1');
    if (tokenInput) body.set('_token', tokenInput.value);

    fetch('ajax_ai_lead_analysis.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.ok) { render(d); }
      else {
        bar.style.display = 'flex';
        badge.textContent = 'AI unavailable';
        badge.style.background = 'rgba(255,71,87,0.12)';
        badge.style.color = '#c62828';
        reasonEl.textContent = (d && d.error) ? d.error : 'AI analysis is unavailable right now.';
        applyBtn.style.display = 'none';
        currentEl.style.display = 'none';
      }
    })
    .catch(function () { bar.style.display = 'none'; });
  }

  applyBtn.addEventListener('click', function () {
    var outcome = applyBtn.dataset.outcome || '';
    if (outcome === '') return;
    if (!confirm('Apply AI suggestion "' + (applyBtn.dataset.label || outcome) + '"?\n\nThis updates the lead outcome and triggers your Drip Campaign flow (same as the CRM buttons).')) return;
    outcomeInput.value = outcome;
    form.submit();
  });

  refreshBtn.addEventListener('click', function () { analyze(true); });

  analyze(false);
})();
</script>
<?php endif;

// Background drip sender tick while inbox is open (avoids OS cron for local/testing).
// Runs only for authenticated sessions and only if drip feature is enabled.
// Ads & Attribution: send due Conversions API events + ad thumbnails while the inbox is open (fallback when cron is not set up).
if (has_feature('ads_capi') || has_feature('ads_attribution')): ?>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  function tick() { fetch('../api/ads_media_tick.php', { credentials: 'same-origin' }).catch(function () {}); }
  setTimeout(tick, 5000);
  setInterval(tick, 120000);
})();
</script>
<?php endif;

// Scheduled Blasts: send due scheduled messages while the inbox is open (fallback when cron is not set up).
if (has_feature('feat_scheduled_msg')): ?>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  function tick() { fetch('../api/scheduled_tick.php', { credentials: 'same-origin' }).catch(function () {}); }
  tick();
  setInterval(tick, 60000);
})();
</script>
<?php endif;

if (has_feature('drip_campaign') || has_feature('feat_drip_campaign')): ?>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  try {
    function tick() {
      fetch('../api/drip_tick.php?limit=25', { credentials: 'same-origin' }).catch(function () {});
    }
    tick();
    setInterval(tick, 30000);
  } catch (e) {}
})();
</script>
<?php endif;

?>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
document.addEventListener('click', function (e) {
  var b = e.target.closest('.wa-voice-btn'); if (!b) { return; }
  var tok = document.querySelector('input[name="_token"]');
  b.disabled = true; b.textContent = 'Transcribing…';
  var body = new URLSearchParams(); body.set('message_id', b.getAttribute('data-voice-mid')); if (tok) { body.set('_token', tok.value); }
  fetch('ajax_voice_transcribe.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()})
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var box = b.parentNode, el = document.createElement('div');
      if (d && d.ok) { el.className = 'wa-voice-tr'; el.innerHTML = '<small>Transcript</small>'; el.appendChild(document.createTextNode(d.text)); box.replaceChild(el, b); }
      else { b.disabled = false; b.textContent = 'Transcribe'; el.className = 'wa-voice-err'; el.textContent = (d && d.error) || 'Could not transcribe.'; box.appendChild(el); }
    })
    .catch(function () { b.disabled = false; b.textContent = 'Transcribe'; });
});
</script>
<?php
include '../includes/client_footer.php';
?>

<?php
include '../includes/client_auth_check.php';

if (!has_feature('feat_voice_analyzer')) {
    header('Location: dashboard.php');
    exit();
}

$pageTitle = 'AI Voice Note Analyzer';
$currentPage = 'premium_voice';

/** @var mysqli $conn */
/** @var int $companyId */

// Inbound voice/audio is stored on `messages` (see webhook.php): type audio/voice, media_url = WA media id, optional text in `message`.
$voiceLogs = null;
$voiceMonthCount = 0;
$voiceTotalCount = 0;
$voiceTranscriptCount = 0;

$monthYm = date('Y-m');

// List filters (table only)
$listDays = (int)($_GET['days'] ?? 30);
if (!in_array($listDays, [1, 7, 30], true)) $listDays = 30;
$withTextOnly = (int)($_GET['with_text'] ?? 0) === 1;

// This month — inbound audio/voice notes
$stM = $conn->prepare("
    SELECT COUNT(*) AS c FROM messages m
    WHERE m.company_id = ? AND m.direction = 'incoming'
      AND m.type IN ('audio', 'voice')
      AND DATE_FORMAT(m.created_at, '%Y-%m') = ?
");
if ($stM) {
    $stM->bind_param('is', $companyId, $monthYm);
    $stM->execute();
    $r = $stM->get_result();
    $voiceMonthCount = (int)($r->fetch_assoc()['c'] ?? 0);
}

// All-time count (for context)
$stT = $conn->prepare("
    SELECT COUNT(*) AS c FROM messages m
    WHERE m.company_id = ? AND m.direction = 'incoming' AND m.type IN ('audio', 'voice')
");
if ($stT) {
    $stT->bind_param('i', $companyId);
    $stT->execute();
    $voiceTotalCount = (int)($stT->get_result()->fetch_assoc()['c'] ?? 0);
}

// Rows where some text was captured (caption or future transcript stored in `message`)
$stTr = $conn->prepare("
    SELECT COUNT(*) AS c FROM messages m
    WHERE m.company_id = ? AND m.direction = 'incoming' AND m.type IN ('audio', 'voice')
      AND m.message IS NOT NULL AND TRIM(m.message) <> ''
");
if ($stTr) {
    $stTr->bind_param('i', $companyId);
    $stTr->execute();
    $voiceTranscriptCount = (int)($stTr->get_result()->fetch_assoc()['c'] ?? 0);
}

$stList = $conn->prepare("
    SELECT m.id, m.contact_id, m.message, m.media_url, m.type, m.created_at,
           c.name AS contact_name, c.phone AS contact_phone
    FROM messages m
    LEFT JOIN contacts c ON m.contact_id = c.id AND c.company_id = m.company_id
    WHERE m.company_id = ? AND m.direction = 'incoming' AND m.type IN ('audio', 'voice')
      AND m.created_at >= (NOW() - INTERVAL ? DAY)
      AND (? = 0 OR (m.message IS NOT NULL AND TRIM(m.message) <> ''))
    ORDER BY m.created_at DESC
    LIMIT 50
");
if ($stList) {
    $withTextFlag = $withTextOnly ? 1 : 0;
    $stList->bind_param('iii', $companyId, $listDays, $withTextFlag);
    $stList->execute();
    $voiceLogs = $stList->get_result();
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <div>
        <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg> AI Voice Note Analyzer</h2>
        <p style="color:var(--text-secondary); font-size:14px;">Inbound WhatsApp voice notes from your connected number. Text appears when a caption exists or optional Whisper transcription is wired in.</p>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/voice_ai.php';
voice_ai_schema($conn);
$vRoutes = voice_ai_routes($conn);
$vOn = in_array(true, $vRoutes, true);
$vFail = 0; $vErr = '';
$vq = $conn->prepare("SELECT COUNT(*) AS n, MAX(error) AS e FROM ai_voice_jobs WHERE company_id = ? AND status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
if ($vq) { $vq->bind_param('i', $companyId); $vq->execute(); $vr = $vq->get_result()->fetch_assoc(); $vFail = (int)($vr['n'] ?? 0); $vErr = (string)($vr['e'] ?? ''); }
?>
<div style="display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border-radius:14px;margin-bottom:18px;background:<?php echo $vOn ? '#E3F7EE' : '#FFF6E0'; ?>;color:<?php echo $vOn ? '#0B6B5E' : '#8A5A00'; ?>;font-size:13.5px;line-height:1.55;">
    <div>
        <?php if ($vOn): ?>
            <b>Voice notes are transcribed automatically.</b> Each customer voice note shows its text in WhatsApp Chat (with a player), WAAMINI Bot answers what was said, and AI CRM uses it for the lead status.
            Using: <?php echo htmlspecialchars(implode(', ', array_keys(array_filter($vRoutes)))); ?>.
            <?php if ($vFail > 0): ?><br><b><?php echo $vFail; ?> voice note(s) failed in the last 7 days:</b> <?php echo htmlspecialchars(mb_substr($vErr, 0, 200)); ?><?php endif; ?>
        <?php else: ?>
            <b>Transcription is not set up yet.</b> Voice notes are saved and playable in WhatsApp Chat, but not turned into text. Your WAAMINI admin can enable it for free by adding a Groq or Google Gemini key in Admin &rarr; WAAMINI Bot &rarr; Provider keys.
        <?php endif; ?>
    </div>
</div>
<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(52,152,219,0.12);color:#3498db;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($voiceMonthCount); ?></div>
            <div class="stat-label">This month</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,184,148,0.12);color:#00b894;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($voiceTranscriptCount); ?></div>
            <div class="stat-label">With text/caption</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(241,196,15,0.12);color:#f1c40f;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg></div>
        <div class="stat-info">
            <div class="stat-number"><?php echo number_format($voiceTotalCount); ?></div>
            <div class="stat-label">All time</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,206,201,0.12);color:#00cec9;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg></div>
        <div class="stat-info">
            <div class="stat-number">Active</div>
            <div class="stat-label">Logging status</div>
        </div>
    </div>
</div>

<div class="form-card" style="max-width:none;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
        <h3 style="font-size:15px;font-weight:800;margin:0;color:var(--text-primary);">Recent Voice Notes</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
            <?php
                $qs = function(array $over = []) use ($listDays, $withTextOnly): string {
                    $m = [
                        'days' => $listDays,
                        'with_text' => $withTextOnly ? 1 : 0,
                    ];
                    $m = array_merge($m, $over);
                    foreach ($m as $k => $v) { if ($v === '' || $v === null) unset($m[$k]); }
                    return http_build_query($m);
                };
            ?>
            <a class="btn btn-sm <?php echo $listDays===1?'btn-primary':'btn-edit'; ?>" href="premium_voice.php?<?php echo htmlspecialchars($qs(['days'=>1]), ENT_QUOTES, 'UTF-8'); ?>">24h</a>
            <a class="btn btn-sm <?php echo $listDays===7?'btn-primary':'btn-edit'; ?>" href="premium_voice.php?<?php echo htmlspecialchars($qs(['days'=>7]), ENT_QUOTES, 'UTF-8'); ?>">7d</a>
            <a class="btn btn-sm <?php echo $listDays===30?'btn-primary':'btn-edit'; ?>" href="premium_voice.php?<?php echo htmlspecialchars($qs(['days'=>30]), ENT_QUOTES, 'UTF-8'); ?>">30d</a>
            <a class="btn btn-sm <?php echo $withTextOnly?'btn-primary':'btn-edit'; ?>" href="premium_voice.php?<?php echo htmlspecialchars($qs(['with_text'=>$withTextOnly?0:1]), ENT_QUOTES, 'UTF-8'); ?>">With text only</a>
        </div>
    </div>
    <div class="data-table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Text / Caption</th>
                    <th>Time</th>
                    <th style="width:120px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($voiceLogs && $voiceLogs->num_rows > 0):
    while ($log = $voiceLogs->fetch_assoc()):
        $txt = isset($log['message']) ? trim((string)$log['message']) : '';
        $ts = !empty($log['created_at']) ? strtotime((string)$log['created_at']) : false;
        ?>
                <tr>
                    <td>
                        <div style="color:var(--text-primary);font-weight:700;"><?php echo htmlspecialchars((string)($log['contact_name'] ?? '—')); ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?php echo htmlspecialchars((string)($log['contact_phone'] ?? '—')); ?></div>
                    </td>
                    <td>
                        <span class="badge badge-admin"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> <?php echo htmlspecialchars((string)($log['type'] ?? 'audio')); ?></span>
                    </td>
                    <td style="max-width:520px;">
                        <?php if ($txt !== ''): ?>
                            <div style="background:var(--bg-input);border:1px solid var(--border);padding:10px;border-radius:10px;line-height:1.35;">
                                <?php echo nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        <?php else: ?>
                            <div style="color:var(--text-muted);font-size:12px;">No caption/transcript stored yet.</div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;">
                        <?php echo $ts ? htmlspecialchars(date('d M, H:i', $ts), ENT_QUOTES, 'UTF-8') : '—'; ?>
                    </td>
                    <td>
                        <?php if (!empty($log['contact_id'])): ?>
                            <a href="whatsapp.php?c=<?php echo (int)$log['contact_id']; ?>" class="btn btn-sm btn-primary">Open chat</a>
                        <?php else: ?>
                            <span style="font-size:12px;color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
    endwhile;
else: ?>
                <tr><td colspan="5"><div class="empty-state"><p>No voice notes found yet.</p></div></td></tr>
                <?php
endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="form-card" style="max-width:none;margin-top:18px;">
    <h3 style="font-size:14px;font-weight:900;margin:0 0 10px;color:var(--text-primary);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> How it works</h3>
    <ul style="font-size:13px;color:var(--text-secondary);line-height:1.6;margin:0;padding-left:18px;">
        <li>Incoming audio is stored in <b>messages</b> (<code>type</code> = audio/voice, <code>media_url</code> = WhatsApp media id).</li>
        <li>If you wire Whisper transcription, store the transcript into <code>messages.message</code> so it appears here (auto language detection supported).</li>
        <li>Use <b>Open chat</b> to jump to the WhatsApp Chat UI for that contact.</li>
    </ul>
</div>

<style>
@media (max-width: 900px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr) !important; }
}
@media (max-width: 520px) {
  .stats-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php include '../includes/client_footer.php'; ?>

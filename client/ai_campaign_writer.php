<?php
include '../includes/client_auth_check.php';
$pageTitle = 'AI Campaign Writer';
$currentPage = 'ai_campaign_writer';

$stmt_ai = $conn->prepare("SELECT openai_api_key FROM ai_bot_settings WHERE company_id=? AND is_active=1 LIMIT 1");
$stmt_ai->bind_param("i", $companyId);
$stmt_ai->execute();
$aiSettings = $stmt_ai->get_result()->fetch_assoc();
$hasAI = !empty($aiSettings['openai_api_key']);

include '../includes/client_header.php';
?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> AI Campaign Writer</h2>
    <div style="font-size:13px;color:var(--text-muted);">Describe your campaign → AI writes WhatsApp-ready messages</div>
</div>

<?php if (!$hasAI): ?>
<div class="alert" style="background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.2);color:#e74c3c;border-radius:10px;padding:16px;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg> OpenAI API key not configured. Contact <a href="support.php">support</a> to add your key.
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <!-- Input Panel -->
    <div class="form-card" style="max-width:none;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Campaign Brief</h3>
        <div class="form-group">
            <label>Campaign Goal</label>
            <select id="goalSelect" class="form-control">
                <option value="sale"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg> Announce a Sale / Offer</option>
                <option value="launch"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg> Product Launch</option>
                <option value="event"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Event / Webinar Invite</option>
                <option value="followup"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> Follow-up / Re-engage</option>
                <option value="festive"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/></svg> Festive Greeting</option>
                <option value="reminder"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Reminder</option>
                <option value="custom"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg> Custom</option>
            </select>
        </div>
        <div class="form-group">
            <label>Business Type</label>
            <input type="text" id="bizType" class="form-control" placeholder="e.g. clothing store, restaurant, coaching institute">
        </div>
        <div class="form-group">
            <label>Key Details</label>
            <textarea id="details" class="form-control" rows="4" placeholder="e.g. 50% off on all items, valid till Sunday, use code SAVE50"></textarea>
        </div>
        <div class="form-group">
            <label>Tone</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php foreach (['Friendly','Professional','Urgent','Festive','Casual'] as $t): ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 14px;border-radius:20px;border:1px solid var(--border);background:var(--bg-input);">
                    <input type="radio" name="tone" value="<?php echo $t; ?>" <?php echo $t==='Friendly'?'checked':''; ?>> <?php echo $t; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group">
            <label>Language</label>
            <select id="langSelect" class="form-control">
                <option value="English">English</option>
                <option value="Hindi">Hindi</option>
                <option value="Tamil">Tamil</option>
                <option value="Telugu">Telugu</option>
                <option value="Hinglish">Hinglish (Hindi+English mix)</option>
            </select>
        </div>
        <button onclick="generateCampaign()" id="genBtn" class="btn btn-primary" style="width:100%;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Generate Campaign Messages</button>
    </div>

    <!-- Output Panel -->
    <div>
        <div class="form-card" style="max-width:none;min-height:300px;" id="outputCard">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg> Generated Messages</h3>
            <div id="outputArea" style="color:var(--text-muted);font-size:13px;text-align:center;padding:40px 0;">
                Click "Generate" to create AI-written campaign messages →
            </div>
        </div>
    </div>
</div>

<script>
function generateCampaign() {
    var btn = document.getElementById('genBtn');
    var out = document.getElementById('outputArea');
    btn.disabled = true; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Writing...';
    out.innerHTML = '<div style="text-align:center;padding:40px;"><div style="font-size:32px;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg></div><p style="color:var(--text-muted);">AI is writing your campaign...</p></div>';

    var goal = document.getElementById('goalSelect').value;
    var biz  = document.getElementById('bizType').value;
    var det  = document.getElementById('details').value;
    var tone = document.querySelector('input[name="tone"]:checked')?.value || 'Friendly';
    var lang = document.getElementById('langSelect').value;
    var csrf = '<?php echo csrf_token(); ?>';

    fetch('../api/ai_campaign_writer.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: '_csrf_token='+encodeURIComponent(csrf)+'&goal='+encodeURIComponent(goal)+'&biz='+encodeURIComponent(biz)+'&details='+encodeURIComponent(det)+'&tone='+encodeURIComponent(tone)+'&lang='+encodeURIComponent(lang)
    }).then(r=>r.json()).then(data=>{
        btn.disabled=false; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Generate Campaign Messages';
        if (data.error) { out.innerHTML='<p style="color:#e74c3c;">Error: '+data.error+'</p>'; return; }
        var html = '';
        (data.messages||[]).forEach((msg,i)=>{
            html += '<div style="background:var(--bg-input);border-radius:10px;padding:14px;margin-bottom:12px;position:relative;">';
            html += '<div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;">Version '+(i+1)+'</div>';
            html += '<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;color:var(--text-primary);margin:0;">'+escHtml(msg)+'</pre>';
            html += '<button onclick="copyMsg(this)" data-msg="'+escAttr(msg)+'" style="margin-top:10px;font-size:11px;padding:4px 12px;border-radius:20px;background:rgba(0,184,148,0.1);color:var(--accent);border:1px solid rgba(0,184,148,0.2);cursor:pointer;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy</button>';
            html += '</div>';
        });
        out.innerHTML = html;
    }).catch(e=>{ btn.disabled=false; btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg> Generate'; out.innerHTML='<p style="color:#e74c3c;">Network error. Try again.</p>'; });
}
function escHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s){ return s.replace(/"/g,'&quot;'); }
function copyMsg(btn){
    navigator.clipboard.writeText(btn.dataset.msg).then(()=>{
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Copied!';
        setTimeout(()=>btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Copy',2000);
    });
}
</script>
<?php endif; ?>
<?php include '../includes/client_footer.php'; ?>

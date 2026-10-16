<?php
include '../includes/client_auth_check.php';
if (!has_feature('ai_chatbot')) {
    header('Location: dashboard.php');
    exit();
}
require_once '../includes/waamini_bot/bootstrap.php';

$pageTitle = 'WAAMINI Bot Knowledge';
$currentPage = 'waamini_bot_knowledge';

$S = WB_Store::settings($conn);
$bot = WB_Store::bot($conn, $companyId, true);
$available = WB_Store::platformReady($S) && $bot && $bot['is_admin_enabled'];
$tab = in_array($_GET['tab'] ?? 'web', ['web', 'docs', 'qa'], true) ? $_GET['tab'] : 'web';

$botId = (int)($bot['id'] ?? 0);
$sources = []; $qas = [];
if ($available) {
    $st = $conn->prepare("SELECT id, type, uri, title, status, bytes, error FROM ai_knowledge_sources WHERE company_id = ? AND bot_id = ? ORDER BY type, id");
    $st->bind_param('ii', $companyId, $botId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) { $sources[] = $x; }
    $q = $conn->prepare("SELECT id, question, answer, is_active FROM ai_qa_pairs WHERE company_id = ? AND bot_id = ? ORDER BY id DESC");
    $q->bind_param('ii', $companyId, $botId);
    $q->execute();
    $rr = $q->get_result();
    while ($x = $rr->fetch_assoc()) { $qas[] = $x; }
}
$limits = WB_Store::limitsFor($conn, $companyId);
$urlN = count(array_filter($sources, static fn($s) => $s['type'] === 'url'));
$docN = count(array_filter($sources, static fn($s) => $s['type'] === 'file'));
$kbBytes = array_sum(array_map(static fn($s) => $s['status'] === 'success' ? (int)$s['bytes'] : 0, $sources));
$kbKb = (int)ceil($kbBytes / 1024);
$pct = static fn($a, $b) => $b > 0 ? min(100, round($a / $b * 100)) : 0;

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<link rel="stylesheet" href="../assets/css/waamini-bot.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'plus'  => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    'back'  => '<path d="m15 18-6-6 6-6"/>',
    'lock'  => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
];
?>
<div class="db wbc" data-wbc data-api="ajax_waamini_bot.php" data-token="<?php echo $esc(csrf_get_token()); ?>">
  <div class="db-head">
    <div><h1>Knowledge</h1><p>What your bot knows: website pages, documents and approved answers.</p></div>
    <div class="db-head__actions"><a href="waamini_bot.php" class="db-btn"><?php echo $__ic($P['back'], 16); ?> Back to bot</a></div>
  </div>
  <div id="wbcToast" class="wbc-toast" role="status"></div>

<?php if (!$available): ?>
  <section class="db-card wbc-off"><span><?php echo $__ic($P['lock'], 36); ?></span><h2>Bot is not available</h2><p>Bot is not available, contact support.</p></section>
<?php else: ?>
  <div class="wbc-ktabs">
    <a class="wbc-ktab <?php echo $tab === 'web' ? 'is-active' : ''; ?>" href="?tab=web">Website <em><?php echo $urlN; ?></em></a>
    <a class="wbc-ktab <?php echo $tab === 'docs' ? 'is-active' : ''; ?>" href="?tab=docs">Documents <em><?php echo $docN; ?></em></a>
    <a class="wbc-ktab <?php echo $tab === 'qa' ? 'is-active' : ''; ?>" href="?tab=qa">Q&amp;A <em><?php echo count($qas); ?></em></a>
  </div>

  <div class="wbc-kgrid">
    <section class="db-card" style="padding:22px">
    <?php if ($tab === 'web'): ?>
      <div class="wbc-fetch">
        <input class="wbc-input" id="kUrl" placeholder="https://yourbusiness.com/page or a sitemap.xml" value="">
        <button type="button" class="db-btn db-btn--primary" id="kAdd"><?php echo $__ic($P['plus'], 16); ?> Add URL</button>
        <button type="button" class="db-btn" id="kRetrainAll"><?php echo $__ic($P['refresh'], 16); ?> Retrain all</button>
      </div>
      <div style="overflow-x:auto"><table class="wbc-table"><thead><tr><th>Page</th><th>Status</th><th>Size</th><th></th></tr></thead><tbody id="kSrc"></tbody></table></div>

    <?php elseif ($tab === 'docs'): ?>
      <label class="wbc-drop" id="kDrop" for="kFile">
        <?php echo $__ic($P['upload'], 28); ?>
        <b>Upload a document</b>
        <span>PDF, DOCX or TXT, up to 5 MB. Scanned PDFs are not supported.</span>
        <input type="file" id="kFile" accept=".pdf,.docx,.txt" style="display:none">
      </label>
      <div style="overflow-x:auto;margin-top:16px"><table class="wbc-table"><thead><tr><th>Document</th><th>Status</th><th>Size</th><th></th></tr></thead><tbody id="kSrc"></tbody></table></div>

    <?php else: ?>
      <div class="wbc-field"><label class="wbc-lab" for="qaQ">Question</label><input class="wbc-input" id="qaQ" placeholder="What are your working hours?"></div>
      <div class="wbc-field"><label class="wbc-lab" for="qaA">Answer</label><textarea class="wbc-area" id="qaA" rows="3" placeholder="We are open Monday to Saturday, 9 am to 7 pm."></textarea></div>
      <div class="wbc-btn-row" style="margin-bottom:20px"><button type="button" class="db-btn db-btn--primary" id="qaSave"><?php echo $__ic($P['plus'], 16); ?> <span id="qaSaveTxt">Add Q&amp;A</span></button><button type="button" class="db-btn" id="qaCancel" style="display:none">Cancel edit</button></div>
      <div class="wbc-qa" id="qaList">
        <?php if (!$qas): ?><div class="wbc-empty">No Q&amp;As yet. Approved answers are always used first.</div><?php endif; ?>
        <?php foreach ($qas as $qa): ?>
          <div class="wbc-qa__i <?php echo (int)$qa['is_active'] ? '' : 'is-off'; ?>" data-id="<?php echo (int)$qa['id']; ?>">
            <b><?php echo $esc($qa['question']); ?></b>
            <p><?php echo $esc($qa['answer']); ?></p>
            <div class="wbc-btn-row">
              <button type="button" class="wbc-link" data-edit>Edit</button>
              <button type="button" class="wbc-link" data-toggle><?php echo (int)$qa['is_active'] ? 'Turn off' : 'Turn on'; ?></button>
              <button type="button" class="wbc-link" data-del style="color:#c0372b">Delete</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    </section>

    <aside class="db-card" style="padding:22px">
      <h3 style="margin:0 0 14px;font-size:17px;font-weight:800;color:var(--ink)">Usage</h3>
      <div class="wbc-meter"><div class="wbc-meter__t"><span>Website pages</span><b><?php echo $urlN; ?> / <?php echo $limits['pages']; ?></b></div><div class="wbc-track"><u class="<?php echo $pct($urlN, $limits['pages']) >= 100 ? 'is-full' : ''; ?>" style="width:<?php echo $pct($urlN, $limits['pages']); ?>%"></u></div></div>
      <div class="wbc-meter"><div class="wbc-meter__t"><span>Documents</span><b><?php echo $docN; ?> / <?php echo $limits['docs']; ?></b></div><div class="wbc-track"><u class="<?php echo $pct($docN, $limits['docs']) >= 100 ? 'is-full' : ''; ?>" style="width:<?php echo $pct($docN, $limits['docs']); ?>%"></u></div></div>
      <div class="wbc-meter"><div class="wbc-meter__t"><span>Knowledge size</span><b><?php echo number_format($kbKb); ?> / <?php echo number_format($limits['kb_kb']); ?> KB</b></div><div class="wbc-track"><u class="<?php echo $pct($kbKb, $limits['kb_kb']) >= 100 ? 'is-full' : ''; ?>" style="width:<?php echo $pct($kbKb, $limits['kb_kb']); ?>%"></u></div></div>
      <p class="wbc-hint">Limits come from your plan. Delete content you no longer need to free up space.</p>
    </aside>
  </div>
<?php endif; ?>
</div>

<?php if ($available): ?>
<script src="../assets/js/waamini-bot.js?v=1"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var W = window.WBC; if (!W) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var TAB = <?php echo json_encode($tab); ?>;
  var sources = <?php echo json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;

  if (TAB === 'web' || TAB === 'docs') {
    var type = TAB === 'web' ? 'url' : 'file', tb = $('kSrc');
    function draw() { return W.renderSources(tb, sources, type); }
    function pump() { W.tickLoop(function (r) { if (r && r.sources) { sources = r.sources; draw(); } }); }
    draw();
    if (type === 'url' && sources.some(function (s) { return s.status === 'queued' || s.status === 'training'; })) { pump(); }

    tb.addEventListener('click', function (e) {
      var rt = e.target.closest('[data-retrain]'), dl = e.target.closest('[data-del]');
      if (rt) { W.api('retrain', {id: rt.getAttribute('data-retrain')}).then(function (r) { if (r.ok) { sources = r.sources; draw(); pump(); } }); }
      if (dl && confirm('Delete this item and everything learned from it?')) {
        W.api('delete_source', {id: dl.getAttribute('data-del')}).then(function (r) { if (r.ok) { sources = r.sources; draw(); W.toast('Deleted.', true); setTimeout(function () { location.reload(); }, 600); } });
      }
    });
    if (TAB === 'web') {
      $('kAdd').addEventListener('click', function () {
        var b = this; b.disabled = true;
        W.api('add_url', {url: $('kUrl').value}).then(function (r) {
          b.disabled = false;
          if (!r.ok) { W.toast(r.error || 'Could not add.', false); return; }
          $('kUrl').value = ''; W.toast(r.message, true); sources = r.sources; draw(); pump();
        });
      });
      $('kRetrainAll').addEventListener('click', function () {
        if (!confirm('Retrain every page? This reads your website again.')) { return; }
        W.api('retrain', {id: 'all'}).then(function (r) { if (r.ok) { W.toast(r.queued + ' pages queued.', true); sources = r.sources; draw(); pump(); } });
      });
    } else {
      var drop = $('kDrop'), file = $('kFile');
      function upload(f) {
        if (!f) { return; }
        var fd = new FormData(); fd.append('file', f);
        drop.classList.add('is-over'); W.toast('Reading ' + f.name + '...', true);
        W.api('upload_doc', null, fd).then(function (r) {
          drop.classList.remove('is-over');
          if (r.ok) { W.toast(r.message, true); sources = r.sources; draw(); setTimeout(function () { location.reload(); }, 700); } else { W.toast(r.error || 'Upload failed.', false); }
        });
      }
      file.addEventListener('change', function () { upload(file.files[0]); file.value = ''; });
      ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
      drop.addEventListener('drop', function (e) { upload(e.dataTransfer.files[0]); });
    }
  }

  if (TAB === 'qa') {
    var editId = null;
    function resetForm() { editId = null; $('qaQ').value = ''; $('qaA').value = ''; $('qaSaveTxt').textContent = 'Add Q&A'; $('qaCancel').style.display = 'none'; }
    $('qaCancel').addEventListener('click', resetForm);
    $('qaSave').addEventListener('click', function () {
      var b = this; b.disabled = true;
      W.api('qa_save', {id: editId, question: $('qaQ').value, answer: $('qaA').value}).then(function (r) {
        b.disabled = false;
        if (r.ok) { W.toast('Saved.', true); setTimeout(function () { location.reload(); }, 500); } else { W.toast(r.error || 'Could not save.', false); }
      });
    });
    $('qaList').addEventListener('click', function (e) {
      var item = e.target.closest('.wbc-qa__i'); if (!item) { return; }
      var id = item.getAttribute('data-id');
      if (e.target.closest('[data-edit]')) {
        editId = id; $('qaQ').value = item.querySelector('b').textContent; $('qaA').value = item.querySelector('p').textContent;
        $('qaSaveTxt').textContent = 'Update Q&A'; $('qaCancel').style.display = ''; $('qaQ').focus(); window.scrollTo({top: 0, behavior: 'smooth'});
      } else if (e.target.closest('[data-toggle]')) {
        W.api('qa_toggle', {id: id}).then(function (r) { if (r.ok) { location.reload(); } });
      } else if (e.target.closest('[data-del]')) {
        if (confirm('Delete this Q&A?')) { W.api('qa_delete', {id: id}).then(function (r) { if (r.ok) { location.reload(); } }); }
      }
    });
  }
})();
</script>
<?php endif; ?>

<?php include '../includes/client_footer.php'; ?>

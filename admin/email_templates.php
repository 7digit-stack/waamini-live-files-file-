<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'Email Templates';
$currentPage = 'email_templates';

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    csrf_verify();
    $id = intval($_POST['id']);
    $subject = sanitize($_POST['subject']);
    $body = $_POST['body'];

    $stmt = $conn->prepare("UPDATE email_templates SET subject = ?, body = ? WHERE id = ?");
    $stmt->bind_param("ssi", $subject, $body, $id);
    $stmt->execute();
    header("Location: email_templates.php?msg=saved&t=" . $id);
    exit();
}

$templates = [];
$res = $conn->query("SELECT id, name, slug, subject, body, updated_at FROM email_templates ORDER BY id ASC");
if ($res) {
    while ($t = $res->fetch_assoc()) {
        $templates[] = $t;
    }
}
$selectedId = isset($_GET['t']) ? intval($_GET['t']) : 0;

include '../includes/admin_header.php';

$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="et-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'mail'   => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'save'   => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'edit'   => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'tag'    => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'reset'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    'chev'   => '<path d="m9 18 6-6-6-6"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
];
$iconFor = static function (string $slug) use ($P): string {
    if (strpos($slug, 'welcome') !== false) return $P['mail'];
    if (strpos($slug, 'expir') !== false || strpos($slug, 'remind') !== false) return $P['clock'];
    if (strpos($slug, 'pay') !== false) return $P['check'];
    return $P['mail'];
};
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="et-toast" role="status"><?php echo $__ic($P['check'], 18); ?> Template saved successfully.</div>
<?php endif; ?>

<div class="et">

  <header class="et-head">
    <div class="et-head__ic"><?php echo $__ic($P['mail'], 26); ?></div>
    <div class="et-head__txt">
      <h1>Email Templates</h1>
      <p>Customize the automated system emails sent to your clients.</p>
    </div>
    <span class="et-count"><?php echo count($templates); ?> template<?php echo count($templates) === 1 ? '' : 's'; ?></span>
  </header>

  <div class="et-layout">

    <!-- ===== list ===== -->
    <aside class="et-list" aria-label="Templates">
      <?php foreach ($templates as $t): ?>
        <button type="button" class="et-item" data-id="<?php echo (int)$t['id']; ?>">
          <span class="et-item__ic"><?php echo $__ic($iconFor((string)$t['slug']), 20); ?></span>
          <span class="et-item__txt">
            <strong><?php echo htmlspecialchars($t['name']); ?></strong>
            <em><?php echo htmlspecialchars($t['subject']); ?></em>
            <span class="et-slug"><?php echo $__ic($P['tag'], 11); ?><?php echo htmlspecialchars($t['slug']); ?></span>
          </span>
          <?php echo $__ic($P['chev'], 16); ?>
        </button>
      <?php endforeach; ?>
      <?php if (!$templates): ?>
        <div class="et-empty-list">No templates found.</div>
      <?php endif; ?>
    </aside>

    <!-- ===== editor ===== -->
    <section class="et-editor">

      <div class="et-placeholder" id="etPlaceholder">
        <div class="et-placeholder__ic"><?php echo $__ic($P['edit'], 34); ?></div>
        <h3>Select a template to edit</h3>
        <p>Choose an automated email from the list to customize its subject and content.</p>
      </div>

      <form method="POST" id="etForm" class="et-form" hidden>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" id="etId">

        <div class="et-form__head">
          <div>
            <h2 id="etTitle">Editing template</h2>
            <span class="et-meta" id="etMeta"></span>
          </div>
          <div class="et-tabs" role="tablist">
            <button type="button" class="is-active" data-tab="edit" role="tab"><?php echo $__ic($P['edit'], 15); ?> Edit</button>
            <button type="button" data-tab="preview" role="tab"><?php echo $__ic($P['eye'], 15); ?> Preview</button>
          </div>
        </div>

        <div class="et-field">
          <label for="etSubject">Subject line</label>
          <input type="text" name="subject" id="etSubject" class="et-input" required>
        </div>

        <!-- edit pane -->
        <div id="etPaneEdit">
          <div class="et-field">
            <div class="et-label-row">
              <label for="etBody">Email body <span>(plain text or HTML)</span></label>
              <span class="et-chars" id="etChars">0 characters</span>
            </div>
            <div class="et-vars" aria-label="Insert variable">
              <span class="et-vars__lbl">Insert variable:</span>
              <?php foreach (['name', 'email', 'amount', 'expiry_date', 'plan_name'] as $v): ?>
                <button type="button" class="et-var" data-var="{{<?php echo $v; ?>}}">{{<?php echo $v; ?>}}</button>
              <?php endforeach; ?>
            </div>
            <textarea name="body" id="etBody" class="et-input et-textarea" rows="14" spellcheck="false"></textarea>
            <small>Click a variable to insert it where your cursor is. Variables are replaced with real values when the email is sent.</small>
          </div>
        </div>

        <!-- preview pane -->
        <div id="etPanePreview" hidden>
          <div class="et-preview">
            <div class="et-preview__bar">
              <span>Subject</span>
              <b id="etPrevSubject"></b>
            </div>
            <iframe id="etPrevFrame" title="Email preview" sandbox="" class="et-preview__frame"></iframe>
          </div>
          <small class="et-note">Preview uses sample values: Rahul, rahul@example.com, ₹2,999, 31 Dec 2026, Enterprise.</small>
        </div>

        <div class="et-savebar">
          <span class="et-dirty" id="etDirty" hidden>You have unsaved changes</span>
          <div>
            <button type="button" class="et-btn et-btn--ghost" id="etReset"><?php echo $__ic($P['reset'], 15); ?> Reset</button>
            <button type="submit" name="save_template" value="1" class="et-btn et-btn--primary"><?php echo $__ic($P['save'], 16); ?> Save template</button>
          </div>
        </div>
      </form>
    </section>
  </div>
</div>

<style>
.et{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#4b5d57;--mu:#7a8c86;--line:#DDEFE7;--soft:#F3FBF7;max-width:none;margin:0;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.et *{box-sizing:border-box}
.et-ic{flex:none;display:inline-block;vertical-align:middle}
.et-toast{max-width:none;margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;border:1px solid #BFEBD8;color:var(--gd);font-weight:600;font-size:14px;display:flex;align-items:center;gap:10px}

.et-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.et-head__ic{width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,var(--g),var(--g2));color:#fff;display:grid;place-items:center;box-shadow:0 12px 24px -10px rgba(18,140,126,.6)}
.et-head__txt{flex:1;min-width:220px}
.et-head h1{margin:0 0 4px;font-size:26px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}
.et-head p{margin:0;font-size:14px;color:var(--tx)}
.et-count{padding:6px 14px;border-radius:999px;background:#fff;border:1px solid var(--line);font-size:12.5px;font-weight:700;color:var(--gd)}

.et-layout{display:grid;grid-template-columns:340px minmax(0,1fr);gap:22px;align-items:start}

/* list */
.et-list{display:flex;flex-direction:column;gap:10px}
.et-item{display:flex;align-items:center;gap:13px;width:100%;padding:14px 16px;border:1.5px solid var(--line);border-radius:16px;background:#fff;text-align:left;cursor:pointer;font:inherit;color:inherit;transition:border-color .2s,box-shadow .2s,transform .2s,background .2s}
.et-item:hover{border-color:#9ED9BF;transform:translateX(3px)}
.et-item.is-active{border-color:var(--g);background:var(--soft);box-shadow:0 14px 28px -18px rgba(18,140,126,.6)}
.et-item__ic{flex:none;width:42px;height:42px;border-radius:12px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;transition:background .2s,color .2s}
.et-item.is-active .et-item__ic{background:var(--g);color:#fff}
.et-item__txt{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.et-item__txt strong{font-size:14.5px;font-weight:700;color:var(--ink)}
.et-item__txt em{font-style:normal;font-size:12.5px;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.et-slug{display:inline-flex;align-items:center;gap:5px;width:fit-content;margin-top:3px;padding:2px 8px;border-radius:6px;background:#fff;border:1px solid var(--line);font:600 11px ui-monospace,Menlo,Consolas,monospace;color:var(--gd)}
.et-item>.et-ic:last-child{color:#a9bbb5}
.et-item.is-active>.et-ic:last-child{color:var(--g)}
.et-empty-list{padding:24px;text-align:center;color:var(--mu);border:1.5px dashed var(--line);border-radius:16px}

/* editor */
.et-editor{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 14px 34px -26px rgba(18,140,126,.45);min-height:460px}
.et-placeholder{display:grid;justify-items:center;align-content:center;gap:10px;min-height:460px;padding:30px;text-align:center}
.et-placeholder__ic{width:76px;height:76px;border-radius:22px;background:#E3F7EE;color:var(--g);display:grid;place-items:center}
.et-placeholder h3{margin:6px 0 0;font-size:18px;font-weight:800;color:var(--ink)}
.et-placeholder p{margin:0;max-width:340px;font-size:14px;line-height:1.6;color:var(--tx)}
.et-form{padding:24px}
.et-form[hidden],.et-placeholder[hidden],#etPaneEdit[hidden],#etPanePreview[hidden],.et-dirty[hidden]{display:none}
.et-form__head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.et-form__head h2{margin:0 0 4px;font-size:19px;font-weight:800;color:var(--ink)}
.et-meta{font-size:12.5px;color:var(--mu)}
.et-tabs{display:inline-flex;padding:4px;border-radius:12px;background:var(--soft);border:1px solid var(--line)}
.et-tabs button{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border:0;border-radius:9px;background:transparent;font:700 13px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer;transition:background .2s,color .2s,box-shadow .2s}
.et-tabs button.is-active{background:#fff;color:var(--gd);box-shadow:0 2px 8px rgba(18,140,126,.18)}

.et-field{margin-bottom:18px}
.et-field label{display:block;margin-bottom:7px;font-size:13px;font-weight:700;color:var(--ink)}
.et-field label span{font-weight:500;color:var(--mu)}
.et-field small{display:block;margin-top:8px;font-size:12px;line-height:1.5;color:var(--mu)}
.et-label-row{display:flex;justify-content:space-between;align-items:center;gap:10px}
.et-label-row label{margin-bottom:7px}
.et-chars{font-size:11.5px;font-weight:600;color:var(--mu);font-variant-numeric:tabular-nums}
.et-input{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);font:500 14px Inter,system-ui,sans-serif;transition:border-color .2s,box-shadow .2s}
.et-input:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.et-textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.65;resize:vertical;min-height:260px}

.et-vars{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:10px}
.et-vars__lbl{font-size:12px;font-weight:600;color:var(--mu)}
.et-var{padding:5px 11px;border:1.5px solid #BFEBD8;border-radius:8px;background:var(--soft);font:600 12px ui-monospace,Menlo,Consolas,monospace;color:var(--gd);cursor:pointer;transition:background .2s,border-color .2s,color .2s}
.et-var:hover{background:var(--g);border-color:var(--g);color:#fff}

.et-preview{border:1.5px solid var(--line);border-radius:14px;overflow:hidden;background:#fff}
.et-preview__bar{display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--soft);border-bottom:1px solid var(--line);font-size:13px}
.et-preview__bar span{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mu)}
.et-preview__bar b{color:var(--ink);font-weight:700}
.et-preview__frame{display:block;width:100%;height:340px;border:0;background:#fff}
.et-note{display:block;margin-top:10px;font-size:12px;color:var(--mu)}

.et-savebar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:24px;padding-top:18px;border-top:1px solid var(--line)}
.et-savebar>div{display:flex;gap:10px;margin-left:auto}
.et-dirty{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:#92580a}
.et-dirty::before{content:'';width:8px;height:8px;border-radius:50%;background:#f59e0b}
.et-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:12px;font:700 13.5px Inter,system-ui,sans-serif;cursor:pointer;border:1.5px solid transparent;transition:background .2s,border-color .2s,color .2s,transform .2s,box-shadow .2s}
.et-btn--ghost{background:#fff;border-color:var(--line);color:var(--ink)}
.et-btn--ghost:hover{border-color:var(--g);color:var(--g)}
.et-btn--primary{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;box-shadow:0 10px 22px -10px rgba(18,140,126,.7)}
.et-btn--primary:hover{transform:translateY(-1px);box-shadow:0 14px 26px -10px rgba(18,140,126,.8)}

@media (max-width:980px){.et-layout{grid-template-columns:1fr}.et-list{flex-direction:row;overflow-x:auto;padding-bottom:6px;gap:12px}.et-item{min-width:280px}.et-editor,.et-placeholder{min-height:0}.et-placeholder{padding:40px 20px}}
@media (max-width:560px){.et-form{padding:18px 16px}.et-savebar>div{width:100%}.et-savebar .et-btn{flex:1}}
</style>

<script type="application/json" id="etData"><?php echo json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var data = {};
  JSON.parse($('etData').textContent).forEach(function (t) { data[t.id] = t; });
  var items = document.querySelectorAll('.et-item');
  var form = $('etForm'), subject = $('etSubject'), body = $('etBody');
  var current = null, saved = { s: '', b: '' };

  var SAMPLE = { name: 'Rahul', email: 'rahul@example.com', amount: '₹2,999', expiry_date: '31 Dec 2026', plan_name: 'Enterprise' };
  function fill(str) { return String(str).replace(/\{\{\s*(\w+)\s*\}\}/g, function (m, k) { return SAMPLE.hasOwnProperty(k) ? SAMPLE[k] : m; }); }
  function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function counts() { $('etChars').textContent = body.value.length.toLocaleString() + ' characters'; }
  function dirty() { $('etDirty').hidden = !(subject.value !== saved.s || body.value !== saved.b); }

  function select(id) {
    var t = data[id]; if (!t) return;
    current = t;
    $('etPlaceholder').hidden = true; form.hidden = false;
    $('etId').value = t.id; subject.value = t.subject; body.value = t.body;
    saved = { s: t.subject, b: t.body };
    $('etTitle').textContent = t.name;
    $('etMeta').textContent = 'Slug: ' + t.slug + (t.updated_at ? '  ·  Last updated ' + t.updated_at : '');
    items.forEach(function (el) { el.classList.toggle('is-active', +el.dataset.id === +id); });
    tab('edit'); counts(); dirty();
    if (history.replaceState) history.replaceState(null, '', '?t=' + id);
  }

  function tab(name) {
    document.querySelectorAll('.et-tabs button').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === name); });
    $('etPaneEdit').hidden = name !== 'edit';
    $('etPanePreview').hidden = name !== 'preview';
    if (name === 'preview') {
      $('etPrevSubject').textContent = fill(subject.value);
      var html = fill(body.value), isHtml = /<[a-z][\s\S]*>/i.test(html);
      var inner = isHtml ? html : '<div style="white-space:pre-wrap">' + esc(html) + '</div>';
      $('etPrevFrame').srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:0;padding:22px;font:15px/1.6 Arial,sans-serif;color:#1f2933">' + inner + '</body>';
    }
  }

  items.forEach(function (el) { el.addEventListener('click', function () { select(el.dataset.id); }); });
  document.querySelectorAll('.et-tabs button').forEach(function (b) { b.addEventListener('click', function () { tab(b.dataset.tab); }); });

  // insert a variable at the cursor
  document.querySelectorAll('.et-var').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.dataset.var, s = body.selectionStart, e = body.selectionEnd;
      body.value = body.value.slice(0, s) + v + body.value.slice(e);
      body.focus(); body.selectionStart = body.selectionEnd = s + v.length;
      counts(); dirty();
    });
  });

  body.addEventListener('input', function () { counts(); dirty(); });
  subject.addEventListener('input', dirty);
  $('etReset').addEventListener('click', function () { if (current) { subject.value = saved.s; body.value = saved.b; counts(); dirty(); } });

  // open the template from ?t=ID (e.g. right after saving)
  var want = <?php echo (int)$selectedId; ?>;
  if (want && data[want]) select(want);
})();
</script>

<?php include '../includes/admin_footer.php'; ?>

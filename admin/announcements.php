<?php
include '../includes/auth_check.php';
include '../db.php';

$pageTitle = 'System Announcements';
$currentPage = 'announcements';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['add_announcement'])) {
        $title = sanitize($_POST['title']);
        $message = $_POST['message'];
        $type = $_POST['type'];
        $target = intval($_POST['target_plan_id']);
        
        $stmt = $conn->prepare("INSERT INTO announcements (title, message, type, target_plan_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $title, $message, $type, $target);
        $stmt->execute();
        header("Location: announcements.php?msg=added");
        exit();
    }
    
    if (isset($_POST['toggle_status'])) {
        $id = intval($_POST['id']);
        $conn->query("UPDATE announcements SET is_active = NOT is_active WHERE id = $id");
        header("Location: announcements.php?msg=updated");
        exit();
    }
    
    if (isset($_POST['delete'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM announcements WHERE id = $id");
        header("Location: announcements.php?msg=deleted");
        exit();
    }
}

$announcements = $conn->query("SELECT a.*, p.name as plan_name 
    FROM announcements a 
    LEFT JOIN subscription_plans p ON a.target_plan_id = p.id 
    ORDER BY a.id DESC");
$plans = $conn->query("SELECT id, name FROM subscription_plans WHERE is_enabled = 1");

include '../includes/admin_header.php';

?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$rows = [];
if ($announcements) { while ($r = $announcements->fetch_assoc()) { $rows[] = $r; } }
$planList = [];
if ($plans) { while ($p = $plans->fetch_assoc()) { $planList[] = $p; } }
$total = count($rows);
$activeN = $hiddenN = $critN = 0;
foreach ($rows as $r) { if ($r['is_active']) $activeN++; else $hiddenN++; if ($r['type'] === 'error') $critN++; }
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$P = [
    'bell'   => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
    'plus'   => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'check'  => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'eyeoff' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
    'eye'    => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    'trash'  => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'info'   => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'users'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'tag'    => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    'x'      => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
];
$typeMeta = [
    'info'    => ['Info',     'is-info', $P['info']],
    'success' => ['Success',  'is-ok',   $P['check']],
    'warning' => ['Warning',  'is-warn', $P['alert']],
    'error'   => ['Critical', 'is-bad',  $P['alert']],
];
$msgs = ['added' => 'Announcement published.', 'updated' => 'Announcement updated.', 'deleted' => 'Announcement deleted.'];
?>

<?php if (isset($_GET['msg']) && isset($msgs[$_GET['msg']])): ?>
  <div class="an-toast" role="status"><?php echo $__ic($P['check'], 18); ?> <?php echo $esc($msgs[$_GET['msg']]); ?></div>
<?php endif; ?>

<div class="db an">

  <div class="db-head">
    <div>
      <h1>System Announcements</h1>
      <p>Publish notices that appear inside your clients' dashboards.</p>
    </div>
    <div class="db-head__actions">
      <button type="button" class="db-btn db-btn--primary" id="anOpen"><?php echo $__ic($P['plus'], 16); ?> New announcement</button>
    </div>
  </div>

  <!-- summary -->
  <section class="an-stats">
    <div class="an-stat"><span class="db-kpi__ic is-blue"><?php echo $__ic($P['bell'], 19); ?></span><div><span>Total</span><b><?php echo $total; ?></b></div></div>
    <div class="an-stat"><span class="db-kpi__ic is-green"><?php echo $__ic($P['eye'], 19); ?></span><div><span>Live</span><b><?php echo $activeN; ?></b></div></div>
    <div class="an-stat"><span class="db-kpi__ic is-amber"><?php echo $__ic($P['eyeoff'], 19); ?></span><div><span>Hidden</span><b><?php echo $hiddenN; ?></b></div></div>
    <div class="an-stat"><span class="db-kpi__ic is-pink"><?php echo $__ic($P['alert'], 19); ?></span><div><span>Critical</span><b><?php echo $critN; ?></b></div></div>
  </section>

  <!-- table -->
  <section class="db-card db-tablecard">
    <div class="db-card__head">
      <div><h2>All announcements</h2><p>Newest first</p></div>
      <div class="db-seg" id="anFilter">
        <button type="button" class="is-active" data-f="all">All</button>
        <button type="button" data-f="live">Live</button>
        <button type="button" data-f="hidden">Hidden</button>
      </div>
    </div>
    <div class="db-tablewrap">
      <table class="db-table" id="anTable">
        <thead><tr><th>Notice</th><th>Audience</th><th>Type</th><th>Status</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a):
            [$tl, $tc, $ti] = $typeMeta[$a['type']] ?? $typeMeta['info'];
            $msg = (string)$a['message']; $short = mb_strlen($msg) > 110 ? mb_substr($msg, 0, 110) . '…' : $msg; ?>
          <tr data-state="<?php echo $a['is_active'] ? 'live' : 'hidden'; ?>">
            <td>
              <div class="db-who">
                <span class="an-ico <?php echo $tc; ?>"><?php echo $__ic($ti, 18); ?></span>
                <div style="min-width:0"><b><?php echo $esc($a['title']); ?></b><small class="an-clip"><?php echo $esc($short); ?></small></div>
              </div>
            </td>
            <td><span class="db-pill is-off"><?php echo $__ic($a['target_plan_id'] == 0 ? $P['users'] : $P['tag'], 13); ?> <?php echo $a['target_plan_id'] == 0 ? 'All clients' : $esc($a['plan_name'] ?? 'Plan') . ' only'; ?></span></td>
            <td><span class="db-pill <?php echo $tc; ?>"><?php echo $tl; ?></span></td>
            <td><span class="an-state <?php echo $a['is_active'] ? 'is-live' : ''; ?>"><i></i><?php echo $a['is_active'] ? 'Live' : 'Hidden'; ?></span></td>
            <td class="db-mut" style="white-space:nowrap"><?php echo date('M d, Y', strtotime($a['created_at'])); ?></td>
            <td>
              <div class="an-actions">
                <form method="POST"><?php echo csrf_field(); ?>
                  <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                  <button type="submit" name="toggle_status" value="1" class="an-icon" title="<?php echo $a['is_active'] ? 'Hide' : 'Show'; ?>" aria-label="<?php echo $a['is_active'] ? 'Hide announcement' : 'Show announcement'; ?>"><?php echo $__ic($a['is_active'] ? $P['eyeoff'] : $P['eye'], 17); ?></button>
                </form>
                <form method="POST" class="an-del"><?php echo csrf_field(); ?>
                  <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                  <button type="submit" name="delete" value="1" class="an-icon is-danger" title="Delete" aria-label="Delete announcement"><?php echo $__ic($P['trash'], 17); ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="db-empty" id="anEmpty" <?php echo $total ? 'hidden' : ''; ?>>
        <div class="an-empty__ic"><?php echo $__ic($P['bell'], 30); ?></div>
        <b>No announcements yet</b>
        <p>Create one to show a notice to all clients or to a single plan.</p>
        <button type="button" class="db-btn db-btn--primary" id="anOpen2"><?php echo $__ic($P['plus'], 16); ?> New announcement</button>
      </div>
    </div>
  </section>
</div>

<!-- ===== modal ===== -->
<div class="an-modal" id="annModal" hidden>
  <div class="an-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="anTitle">
    <div class="an-modal__head">
      <div><h3 id="anTitle">New announcement</h3><p>Shown to clients inside their dashboard.</p></div>
      <button type="button" class="an-icon" id="anClose" aria-label="Close"><?php echo $__ic($P['x'], 18); ?></button>
    </div>

    <form method="POST" id="anForm">
      <?php echo csrf_field(); ?>
      <div class="an-modal__body">
        <div class="an-field">
          <label for="anHead">Headline</label>
          <input type="text" name="title" id="anHead" class="an-input" required maxlength="120" placeholder="Maintenance update / New feature">
        </div>
        <div class="an-field">
          <label for="anMsg">Message</label>
          <textarea name="message" id="anMsg" class="an-input an-textarea" rows="4" required placeholder="Details about the announcement..."></textarea>
        </div>

        <div class="an-field">
          <label>Notice type</label>
          <input type="hidden" name="type" id="anType" value="info">
          <div class="an-types" id="anTypes">
            <?php foreach ($typeMeta as $k => [$tl, $tc, $ti]): ?>
              <button type="button" class="an-type <?php echo $tc; ?><?php echo $k === 'info' ? ' is-active' : ''; ?>" data-type="<?php echo $k; ?>"><?php echo $__ic($ti, 16); ?><?php echo $tl; ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="an-field">
          <label for="anTarget">Target audience</label>
          <select name="target_plan_id" id="anTarget" class="an-input">
            <option value="0">All clients</option>
            <?php foreach ($planList as $p): ?>
              <option value="<?php echo (int)$p['id']; ?>"><?php echo $esc($p['name']); ?> only</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="an-preview">
          <span class="an-preview__lbl">Preview</span>
          <div class="an-pv is-info" id="anPv">
            <span class="an-pv__ic" id="anPvIc"><?php echo $__ic($P['info'], 18); ?></span>
            <div><b id="anPvT">Headline</b><p id="anPvM">Your message will appear here.</p></div>
          </div>
        </div>
      </div>

      <div class="an-modal__foot">
        <button type="button" class="db-btn" id="anCancel">Discard</button>
        <button type="submit" name="add_announcement" value="1" class="db-btn db-btn--primary"><?php echo $__ic($P['bell'], 16); ?> Publish announcement</button>
      </div>
    </form>
  </div>
</div>

<style>
.an-toast{max-width:none;margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#E6FCF2;border:1px solid #BFEBD8;color:#0B6B5E;font-weight:600;font-size:14px;display:flex;align-items:center;gap:10px}
.an{max-width:none;margin:0}
.an-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.an-stat{display:flex;align-items:center;gap:13px;padding:15px 16px;background:#fff;border:1px solid var(--line);border-radius:16px}
.an-stat .db-kpi__ic{margin:0}
.an-stat span:not(.db-kpi__ic){display:block;font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--mu)}
.an-stat b{display:block;margin-top:1px;font-size:26px;font-weight:800;color:var(--ink)}

.an-ico{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;flex:none;background:#EEF2F1;color:#5f6f6a}
.an-ico.is-info{background:#E4EFFD;color:#1d6fd6}.an-ico.is-ok{background:#DDF7E7;color:#12803f}.an-ico.is-warn{background:#FEF3DC;color:#b7791f}.an-ico.is-bad{background:#FDE4E1;color:#c0372b}
.an-clip{max-width:380px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.an-state{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:700;color:var(--mu)}
.an-state i{width:8px;height:8px;border-radius:50%;background:#c4cfcb}
.an-state.is-live{color:#12803f}.an-state.is-live i{background:#25D366;box-shadow:0 0 0 3px rgba(37,211,102,.2)}
.an-actions{display:flex;justify-content:flex-end;gap:8px}
.an-actions form{margin:0}
.an-icon{width:36px;height:36px;border-radius:10px;border:1.5px solid var(--line);background:#fff;color:var(--tx);display:grid;place-items:center;cursor:pointer;transition:border-color .2s,color .2s,background .2s}
.an-icon:hover{border-color:var(--g);color:var(--g)}
.an-icon.is-danger:hover{border-color:#e5484d;color:#e5484d;background:#FFF6F5}
.db-empty[hidden]{display:none}
.db-empty b{display:block;font-size:15px;color:var(--ink);margin-top:10px}
.db-empty p{margin:4px 0 14px}
.an-empty__ic{width:64px;height:64px;border-radius:18px;background:#E3F7EE;color:var(--g);display:grid;place-items:center;margin:0 auto}
.db-empty .db-btn{margin:0 auto}

/* modal */
.an-modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,38,34,.55);backdrop-filter:blur(6px)}
.an-modal[hidden]{display:none}
.an-modal__dialog{width:100%;max-width:560px;max-height:calc(100vh - 40px);overflow:auto;background:#fff;border-radius:20px;box-shadow:0 40px 90px -20px rgba(0,0,0,.45);animation:anIn .25s ease}
@keyframes anIn{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:none}}
.an-modal__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:22px 24px 16px;border-bottom:1px solid #EEF3F1}
.an-modal__head h3{margin:0 0 3px;font-size:20px;font-weight:800;color:var(--ink)}
.an-modal__head p{margin:0;font-size:14px;color:var(--tx)}
.an-modal__body{padding:20px 24px}
.an-modal__foot{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px 22px;border-top:1px solid #EEF3F1}
.an-field{margin-bottom:16px}
.an-field label{display:block;margin-bottom:7px;font-size:14.5px;font-weight:700;color:var(--ink)}
.an-input{width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);font:500 14px Inter,system-ui,sans-serif;transition:border-color .2s,box-shadow .2s}
.an-input:focus{outline:none;border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.an-textarea{resize:vertical;min-height:96px;line-height:1.55}
select.an-input{height:44px;padding:0 38px 0 14px;appearance:none;-webkit-appearance:none;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8c86' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 13px center}
.an-types{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.an-type{display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 6px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:700 13.5px Inter,system-ui,sans-serif;color:var(--tx);cursor:pointer;transition:border-color .2s,background .2s,color .2s}
.an-type:hover{border-color:#9ED9BF}
.an-type.is-active.is-info{border-color:#1d6fd6;background:#E4EFFD;color:#1d6fd6}
.an-type.is-active.is-ok{border-color:#12803f;background:#DDF7E7;color:#12803f}
.an-type.is-active.is-warn{border-color:#b7791f;background:#FEF3DC;color:#92580a}
.an-type.is-active.is-bad{border-color:#c0372b;background:#FDE4E1;color:#c0372b}
.an-preview{padding-top:4px}
.an-preview__lbl{display:block;margin-bottom:8px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mu)}
.an-pv{display:flex;gap:12px;padding:14px 16px;border-radius:13px;border:1px solid;transition:background .2s,border-color .2s}
.an-pv.is-info{background:#EEF5FE;border-color:#C9DEFA;color:#1d5fb5}
.an-pv.is-ok{background:#EAF9F0;border-color:#BFE7CE;color:#12803f}
.an-pv.is-warn{background:#FFF7E6;border-color:#F6DFA6;color:#92580a}
.an-pv.is-bad{background:#FEF0EE;border-color:#F8C9C2;color:#c0372b}
.an-pv__ic{margin-top:1px}
.an-pv b{display:block;font-size:15.5px;color:var(--ink);word-break:break-word}
.an-pv p{margin:3px 0 0;font-size:14.5px;line-height:1.5;color:var(--tx);word-break:break-word;white-space:pre-wrap}

@media (max-width:900px){.an-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:520px){.an-stats{grid-template-columns:1fr}.an-types{grid-template-columns:1fr 1fr}.an-modal__foot{flex-direction:column-reverse}.an-modal__foot .db-btn{width:100%;justify-content:center}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var modal = $('annModal');
  var ICON = {
    info: '<?php echo addslashes($P['info']); ?>', success: '<?php echo addslashes($P['check']); ?>',
    warning: '<?php echo addslashes($P['alert']); ?>', error: '<?php echo addslashes($P['alert']); ?>'
  };
  var CLS = { info: 'is-info', success: 'is-ok', warning: 'is-warn', error: 'is-bad' };

  function open() { modal.hidden = false; $('anHead').focus(); document.body.style.overflow = 'hidden'; }
  function close() { modal.hidden = true; document.body.style.overflow = ''; }
  ['anOpen', 'anOpen2'].forEach(function (id) { var b = $(id); if (b) b.addEventListener('click', open); });
  $('anClose').addEventListener('click', close);
  $('anCancel').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

  // live preview + type picker
  function pv() {
    $('anPvT').textContent = $('anHead').value.trim() || 'Headline';
    $('anPvM').textContent = $('anMsg').value.trim() || 'Your message will appear here.';
  }
  $('anHead').addEventListener('input', pv); $('anMsg').addEventListener('input', pv);
  $('anTypes').addEventListener('click', function (e) {
    var b = e.target.closest('.an-type'); if (!b) return;
    var t = b.dataset.type;
    this.querySelectorAll('.an-type').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    $('anType').value = t;
    var box = $('anPv'); box.className = 'an-pv ' + CLS[t];
    $('anPvIc').querySelector('svg').innerHTML = ICON[t];
  });

  // filter tabs
  $('anFilter').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
    var f = b.dataset.f, shown = 0;
    document.querySelectorAll('#anTable tbody tr').forEach(function (tr) {
      var ok = f === 'all' || tr.dataset.state === f;
      tr.hidden = !ok; if (ok) shown++;
    });
    var empty = $('anEmpty');
    if (empty) { empty.hidden = shown > 0; }
  });

  // confirm before deleting
  document.querySelectorAll('.an-del').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm('Delete this announcement?')) e.preventDefault(); });
  });
})();
</script>

<?php include '../includes/admin_footer.php'; ?>

<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/contact_fields.php';
if (!has_feature('contacts')) { header('Location: dashboard.php'); exit(); }

$pageTitle = 'Custom Fields';
$currentPage = 'contact_fields';
$cid = (int)$companyId;
$enabled = cf_enabled();
$types = cf_types();
$error = '';
$limit = (int)(function_exists('get_limit') ? get_limit('custom_fields') : 0);   // 0 = unlimited

if ($enabled) { cf_ensure_tables($conn); }
$fields = $enabled ? cf_list($conn, $cid) : [];

$back = static function (string $msg) { header('Location: contact_fields.php?msg=' . $msg); exit(); };
$nameError = static function (string $name, int $exceptId) use ($fields): string {
    if ($name === '') return 'Enter a field name.';
    if (mb_strlen($name) > 60) return 'Keep the name under 60 characters.';
    if (in_array(mb_strtolower($name), cf_reserved_names(), true) || in_array(cf_key($name), cf_reserved_names(), true)) return '“' . $name . '” is already a standard contact column. Choose another name.';
    foreach ($fields as $f) {
        if ((int)$f['id'] !== $exceptId && (mb_strtolower($f['name']) === mb_strtolower($name) || $f['field_key'] === cf_key($name))) return 'You already have a field called “' . $f['name'] . '”.';
    }
    return '';
};

if ($enabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = trim(preg_replace('/\s+/', ' ', strip_tags((string)($_POST['name'] ?? ''))));
    $type = isset($types[$_POST['type'] ?? '']) ? (string)$_POST['type'] : 'text';

    if ($act === 'add') {
        if ($limit > 0 && count($fields) >= $limit) {
            $error = 'Your plan allows ' . $limit . ' custom field' . ($limit === 1 ? '' : 's') . '. Delete one or upgrade your plan to add more.';
        } elseif (($error = $nameError($name, 0)) === '') {
            $key = cf_key($name);
            $order = $fields ? max(array_column($fields, 'sort_order')) + 1 : 1;
            $st = $conn->prepare('INSERT INTO contact_custom_fields (company_id, name, field_key, field_type, sort_order) VALUES (?,?,?,?,?)');
            if ($st && $st->bind_param('isssi', $cid, $name, $key, $type, $order) && $st->execute()) { $back('added'); }
            $error = 'Could not save the field. Please try again.';
        }
    } elseif ($act === 'edit' && $id > 0) {
        if (($error = $nameError($name, $id)) === '') {
            $key = cf_key($name);
            $st = $conn->prepare('UPDATE contact_custom_fields SET name=?, field_key=?, field_type=? WHERE id=? AND company_id=?');
            if ($st && $st->bind_param('sssii', $name, $key, $type, $id, $cid) && $st->execute()) { $back('updated'); }
            $error = 'Could not update the field. Please try again.';
        }
    } elseif ($act === 'delete' && $id > 0) {
        $st = $conn->prepare('DELETE FROM contact_custom_fields WHERE id=? AND company_id=?');
        if ($st) { $st->bind_param('ii', $id, $cid); $st->execute(); }
        $st = $conn->prepare('DELETE FROM contact_field_values WHERE field_id=? AND company_id=?');
        if ($st) { $st->bind_param('ii', $id, $cid); $st->execute(); }
        $back('deleted');
    } elseif (($act === 'up' || $act === 'down') && $id > 0) {
        $ids = array_column($fields, 'id');
        $i = array_search($id, $ids, true);
        $j = $act === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $st = $conn->prepare('UPDATE contact_custom_fields SET sort_order=? WHERE id=? AND company_id=?');
            foreach ($ids as $n => $fid) { $o = $n + 1; $st->bind_param('iii', $o, $fid, $cid); $st->execute(); }
        }
        $back('moved');
    }
}

// How many contacts have a value for each field
$used = [];
if ($enabled && $fields) {
    $st = $conn->prepare('SELECT v.field_id, COUNT(*) AS n FROM contact_field_values v JOIN contacts c ON c.id = v.contact_id AND c.company_id = v.company_id WHERE v.company_id=? GROUP BY v.field_id');
    if ($st) { $st->bind_param('i', $cid); $st->execute(); $r = $st->get_result(); while ($x = $r->fetch_assoc()) { $used[(int)$x['field_id']] = (int)$x['n']; } }
}
$atLimit = $limit > 0 && count($fields) >= $limit;
$msgs = ['added' => 'Field added. It now shows on every contact.', 'updated' => 'Field updated.', 'deleted' => 'Field deleted, together with its saved values.', 'moved' => 'Order saved.'];

$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$ic = static fn(string $p, int $s = 18) => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
$P = [
    'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    'del'  => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'up'   => '<path d="m18 15-6-6-6 6"/>',
    'down' => '<path d="m6 9 6 6 6-6"/>',
    'list' => '<path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h16"/>',
    'xls'  => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 13 4 5"/><path d="m13 13-4 5"/>',
    'x'    => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
];
$typeIcon = [
    'text' => '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>',
    'number' => '<path d="M4 9h16"/><path d="M4 15h16"/><path d="M10 3 8 21"/><path d="M16 3l-2 18"/>',
    'email' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'url' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'date' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    'time' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'datetime' => '<path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h5"/><circle cx="16" cy="16" r="6"/><path d="M16 14v2l1 1"/>',
];

include '../includes/client_header.php';
?>
<style>
.cf{--g:#128C7E;--gd:#0B6B5E;--ink:#0F1E2E;--tx:#3A4B45;--mu:#5B6878;--line:#E3E8EE;--line2:#CDD5DE;--soft:#F6F8FA;--tint:#E7F4EE;
  max-width:1100px;margin:0 auto;display:flex;flex-direction:column;gap:18px;font-family:Inter,system-ui,sans-serif;color:var(--ink)}
.cf *,.cf-modal *{box-sizing:border-box}
.cf a{text-decoration:none;color:inherit}
.cf-card{background:#fff;border:1px solid var(--line);border-radius:14px}
.cf-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:22px 24px}
.cf-head h1{margin:0;font-size:22px;font-weight:800;letter-spacing:-.01em}
.cf-head p{margin:4px 0 0;color:var(--mu);font-size:14px;max-width:62ch}
.cf-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 18px;border-radius:10px;border:1px solid transparent;font:600 14px Inter,system-ui,sans-serif;cursor:pointer;white-space:nowrap}
.cf-btn--pri{background:#25D366;color:#fff}.cf-btn--pri:hover{background:#1DB954}
.cf-btn--pri[disabled]{background:#B8C4BF;cursor:not-allowed}
.cf-btn--grey{background:#6B7280;color:#fff}.cf-btn--grey:hover{background:#4B5563}
.cf-btn--ghost{background:#fff;border-color:var(--line2);color:var(--ink)}.cf-btn--ghost:hover{border-color:var(--ink)}
.cf-meter{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--mu)}
.cf-meter i{width:120px;height:6px;border-radius:99px;background:var(--line);overflow:hidden}
.cf-meter i::after{content:'';display:block;height:100%;width:var(--p);background:var(--g)}
.cf-alert{padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600}
.cf-alert--ok{background:var(--tint);color:#0B5A3C}.cf-alert--err{background:#FCECEE;color:#B4344A}
.cf-table{width:100%;border-collapse:collapse}
.cf-table th{padding:12px 16px;text-align:left;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mu);border-bottom:1px solid var(--line);background:var(--soft)}
.cf-table th:first-child{border-top-left-radius:14px}.cf-table th:last-child{border-top-right-radius:14px}
.cf-table td{padding:14px 16px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:middle}
.cf-table tr:last-child td{border-bottom:0}
.cf-name{display:flex;align-items:center;gap:12px;font-weight:700}
.cf-ti{width:36px;height:36px;border-radius:10px;background:var(--tint);color:var(--g);display:grid;place-items:center;flex:none}
.cf-type{display:inline-flex;padding:3px 10px;border-radius:99px;background:var(--soft);border:1px solid var(--line);font-size:12.5px;font-weight:600;color:var(--tx)}
.cf-key{font:500 12.5px ui-monospace,Menlo,Consolas,monospace;color:var(--mu)}
.cf-acts{display:flex;gap:6px;justify-content:flex-end}
.cf-acts form{margin:0}
.cf-ib{all:unset;cursor:pointer;width:34px;height:34px;border-radius:9px;display:grid;place-items:center;color:var(--mu);border:1px solid var(--line)}
.cf-ib:hover{color:var(--ink);border-color:var(--line2);background:var(--soft)}
.cf-ib.del:hover{color:#B4344A;border-color:#F3C3CB;background:#FCECEE}
.cf-ib[disabled]{opacity:.35;cursor:default}
.cf-ib:focus-visible{outline:2px solid var(--g);outline-offset:2px}
.cf-empty{display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;padding:48px 20px}
.cf-empty .i{width:64px;height:64px;border-radius:50%;background:var(--tint);color:var(--g);display:grid;place-items:center;margin-bottom:6px}
.cf-empty b{font-size:17px}.cf-empty p{margin:0;color:var(--mu);max-width:46ch;font-size:14px}
.cf-ideas{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:6px}
.cf-ideas button{all:unset;cursor:pointer;padding:6px 12px;border-radius:99px;border:1px dashed var(--line2);font-size:13px;font-weight:600;color:var(--tx)}
.cf-ideas button:hover{border-color:var(--g);color:var(--g)}
.cf-help{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.cf-step{padding:18px}
.cf-step b{display:flex;align-items:center;gap:10px;font-size:15px;margin-bottom:6px}
.cf-step b span{width:26px;height:26px;border-radius:50%;background:var(--g);color:#fff;font-size:13px;display:grid;place-items:center;flex:none}
.cf-step p{margin:0;color:var(--mu);font-size:13.5px;line-height:1.55}
.cf-step{display:flex;flex-direction:column}
.cf-btn--dl{margin-top:14px;align-self:flex-start;height:40px;padding:0 16px;background:var(--tint);color:var(--gd) !important;border-color:#BFE3D4}
.cf-btn--dl:hover{background:var(--g);color:#fff !important;border-color:var(--g)}
.cf-btn--dl:focus-visible{outline:2px solid var(--g);outline-offset:2px}
@media (max-width:520px){.cf-btn--dl{align-self:stretch}}
.cf-step code{font:500 12.5px ui-monospace,Menlo,monospace;background:var(--soft);border:1px solid var(--line);border-radius:5px;padding:0 5px;color:var(--ink)}
.cf-lock{padding:40px 24px;text-align:center}
/* modal (matches the "Input Name / Input Type" design) */
.cf-modal{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(15,30,46,.45);font-family:Inter,system-ui,sans-serif}
.cf-modal.open{display:flex}
.cf-dlg{width:min(600px,100%);max-height:calc(100dvh - 32px);overflow:auto;background:#F6F8FA;border-radius:16px;padding:22px 22px 18px;box-shadow:0 30px 70px -20px rgba(15,30,46,.5)}
.cf-dlg h2{margin:0 0 18px;font-size:19px;font-weight:800;color:#0F1E2E}
.cf-dlg label{display:block;margin:0 0 8px;font-size:15px;color:#5B6B80}
.cf-dlg input,.cf-dlg select{width:100%;height:50px;padding:0 16px;border:1px solid #D5DCE4;border-radius:14px;background:#fff;font:500 15px Inter,system-ui,sans-serif;color:#0F1E2E;outline:0;margin-bottom:22px}
.cf-dlg select{border-radius:6px;border-color:#7DB7F0}
.cf-dlg input:focus,.cf-dlg select:focus{border-color:#4A90D9;box-shadow:0 0 0 3px rgba(74,144,217,.18)}
.cf-dlg .hint{margin:-14px 0 20px;font-size:12.5px;color:#6B7A8C}
.cf-dlg .err{margin:-12px 0 16px;font-size:13px;font-weight:600;color:#B4344A}
.cf-dlg .foot{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
.cf-dlg .foot .cf-btn{border-radius:22px;padding:0 22px}
@media (max-width:820px){.cf-help{grid-template-columns:1fr}.cf-table .c-key,.cf-table .c-used{display:none}}
@media (max-width:520px){.cf-head .cf-btn{width:100%}.cf-table td,.cf-table th{padding:12px 10px}.cf-acts{flex-wrap:wrap}}
</style>

<div class="cf">
  <div class="cf-card cf-head">
    <div>
      <h1>Custom fields</h1>
      <p>Add your own details to every contact — like Birthday, Policy number or Preferred branch. Fill them in by hand, or import them from Excel with your contacts and groups.</p>
    </div>
    <?php if ($enabled): ?>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <?php if ($limit > 0): ?><span class="cf-meter" style="--p:<?php echo min(100, (int)round(count($fields) / $limit * 100)); ?>%"><i></i><?php echo count($fields); ?> of <?php echo $limit; ?> used</span><?php endif; ?>
      <button type="button" class="cf-btn cf-btn--pri" id="cfAdd"<?php echo $atLimit ? ' disabled title="Plan limit reached"' : ''; ?>><?php echo $ic($P['plus'], 16); ?> Add field</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$enabled): ?>
  <div class="cf-card cf-lock">
    <div class="cf-empty"><span class="i"><?php echo $ic($P['list'], 28); ?></span><b>Custom fields are not in your plan</b><p>Upgrade your plan to store your own details on contacts and import them from Excel.</p><a class="cf-btn cf-btn--pri" href="subscription.php" style="margin-top:8px">View plans</a></div>
  </div>
  <?php else: ?>

  <?php if (isset($_GET['msg'], $msgs[$_GET['msg']])): ?><div class="cf-alert cf-alert--ok" role="status"><?php echo $esc($msgs[$_GET['msg']]); ?></div><?php endif; ?>
  <?php if ($error !== '' && ($_POST['action'] ?? '') === 'delete'): ?><div class="cf-alert cf-alert--err" role="alert"><?php echo $esc($error); ?></div><?php endif; ?>

  <div class="cf-card">
    <?php if ($fields): ?>
    <table class="cf-table">
      <thead><tr><th>Field</th><th>Type</th><th class="c-key">Excel column</th><th class="c-used">Contacts with a value</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($fields as $n => $f): $fid = (int)$f['id']; $t = $types[$f['field_type']] ?? $types['text']; ?>
        <tr>
          <td><span class="cf-name"><span class="cf-ti"><?php echo $ic($typeIcon[$f['field_type']] ?? $typeIcon['text'], 17); ?></span><?php echo $esc($f['name']); ?></span></td>
          <td><span class="cf-type"><?php echo $esc($t[0]); ?></span></td>
          <td class="c-key"><span class="cf-key"><?php echo $esc($f['name']); ?></span></td>
          <td class="c-used"><?php echo number_format($used[$fid] ?? 0); ?></td>
          <td>
            <div class="cf-acts">
              <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $fid; ?>"><button class="cf-ib" name="action" value="up" title="Move up" aria-label="Move <?php echo $esc($f['name']); ?> up"<?php echo $n === 0 ? ' disabled' : ''; ?>><?php echo $ic($P['up'], 16); ?></button></form>
              <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $fid; ?>"><button class="cf-ib" name="action" value="down" title="Move down" aria-label="Move <?php echo $esc($f['name']); ?> down"<?php echo $n === count($fields) - 1 ? ' disabled' : ''; ?>><?php echo $ic($P['down'], 16); ?></button></form>
              <button type="button" class="cf-ib" data-edit="<?php echo $fid; ?>" data-name="<?php echo $esc($f['name']); ?>" data-type="<?php echo $esc($f['field_type']); ?>" title="Edit" aria-label="Edit <?php echo $esc($f['name']); ?>"><?php echo $ic($P['edit'], 16); ?></button>
              <form method="POST" onsubmit="return confirm('Delete the field “<?php echo $esc(addslashes($f['name'])); ?>” and its values on <?php echo (int)($used[$fid] ?? 0); ?> contact(s)? This cannot be undone.')"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $fid; ?>"><button class="cf-ib del" name="action" value="delete" title="Delete" aria-label="Delete <?php echo $esc($f['name']); ?>"><?php echo $ic($P['del'], 16); ?></button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="cf-empty">
      <span class="i"><?php echo $ic($P['list'], 28); ?></span>
      <b>No custom fields yet</b>
      <p>Create a field and it appears on every contact, in the contact form, the Excel import and the export.</p>
      <div class="cf-ideas" aria-label="Ideas">
        <?php foreach ([['Birthday', 'date'], ['Anniversary', 'date'], ['Policy number', 'text'], ['Preferred branch', 'text'], ['Order value', 'number'], ['Appointment', 'datetime']] as [$iname, $itype]): ?>
        <button type="button" data-idea="<?php echo $esc($iname); ?>" data-type="<?php echo $itype; ?>">+ <?php echo $esc($iname); ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="cf-help">
    <div class="cf-card cf-step"><b><span>1</span> Create the field</b><p>Give it a name and pick a type: text, number, email, URL, date, time or date and time.</p></div>
    <div class="cf-card cf-step"><b><span>2</span> Fill it in</b><p>It shows on <a href="contact_add.php" style="color:var(--g);font-weight:600">Add contact</a> and when you edit a contact. Values show under each name in the <a href="contacts.php" style="color:var(--g);font-weight:600">Contacts list</a>.</p></div>
    <div class="cf-card cf-step"><b><span>3</span> Import from Excel</b><p>Add a column named exactly like the field (e.g. <code><?php echo $esc($fields[0]['name'] ?? 'Birthday'); ?></code>) next to <code>phone_number</code> and <code>group_name</code>. The sample file already has your columns.</p>
      <a class="cf-btn cf-btn--dl" href="contact_import.php?download_template=1" download><?php echo $ic('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>', 16); ?> Download sample CSV</a></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($enabled): ?>
<div class="cf-modal" id="cfModal" role="dialog" aria-modal="true" aria-labelledby="cfTitle">
  <form class="cf-dlg" method="POST" id="cfForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="cfAction" value="add">
    <input type="hidden" name="id" id="cfId" value="0">
    <h2 id="cfTitle">Add custom field</h2>
    <label for="cfName">Input Name</label>
    <input type="text" id="cfName" name="name" maxlength="60" required autocomplete="off" value="<?php echo $esc($_POST['name'] ?? ''); ?>">
    <?php if ($error !== '' && in_array($_POST['action'] ?? '', ['add', 'edit'], true)): ?><p class="err" role="alert"><?php echo $esc($error); ?></p><?php endif; ?>
    <label for="cfType">Input Type</label>
    <select id="cfType" name="type">
      <?php foreach ($types as $k => [$lbl]): ?><option value="<?php echo $k; ?>"<?php echo ($_POST['type'] ?? 'text') === $k ? ' selected' : ''; ?>><?php echo $esc($lbl === 'Date and time' ? 'Date and Time Local' : $lbl); ?></option><?php endforeach; ?>
    </select>
    <p class="hint" id="cfHint">Changing the type later keeps saved values.</p>
    <div class="foot">
      <button type="submit" class="cf-btn cf-btn--pri">Submit</button>
      <button type="button" class="cf-btn cf-btn--grey" id="cfClose">Close</button>
    </div>
  </form>
</div>
<script nonce="<?php echo $esc($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var m = document.getElementById('cfModal'), name = document.getElementById('cfName'), type = document.getElementById('cfType');
  var act = document.getElementById('cfAction'), idf = document.getElementById('cfId'), title = document.getElementById('cfTitle'), last = null;
  function open(mode, id, n, t) {
    last = document.activeElement;
    act.value = mode; idf.value = id || 0;
    title.textContent = mode === 'edit' ? 'Edit custom field' : 'Add custom field';
    if (n !== undefined) name.value = n;
    if (t) type.value = t;
    m.classList.add('open'); document.body.style.overflow = 'hidden';
    setTimeout(function () { name.focus(); name.select(); }, 30);
  }
  function close() { m.classList.remove('open'); document.body.style.overflow = ''; if (last && last.focus) last.focus(); }
  var add = document.getElementById('cfAdd');
  if (add) add.addEventListener('click', function () { if (!add.disabled) open('add', 0, '', 'text'); });
  document.querySelectorAll('[data-edit]').forEach(function (b) { b.addEventListener('click', function () { open('edit', b.getAttribute('data-edit'), b.getAttribute('data-name'), b.getAttribute('data-type')); }); });
  document.querySelectorAll('[data-idea]').forEach(function (b) { b.addEventListener('click', function () { open('add', 0, b.getAttribute('data-idea'), b.getAttribute('data-type')); }); });
  document.getElementById('cfClose').addEventListener('click', close);
  m.addEventListener('click', function (e) { if (e.target === m) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && m.classList.contains('open')) close(); });
  <?php if ($error !== '' && in_array($_POST['action'] ?? '', ['add', 'edit'], true)): ?>open(<?php echo json_encode((string)$_POST['action']); ?>, <?php echo (int)($_POST['id'] ?? 0); ?>);<?php endif; ?>
})();
</script>
<?php endif; ?>

<?php include '../includes/client_footer.php'; ?>

<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/drip_engine.php';

if (!has_feature('contacts')) {
    header("Location: dashboard.php");
    exit();
}
if (!can_use('contacts')) {
    header("Location: contacts.php?msg=limit_reached");
    exit();
}

$pageTitle = 'Add Contact';
$currentPage = 'contacts';

// Load groups for dropdown (Contact Groups feature uses contacts.group_id)
$groups = [];
try {
    $gStmt = $conn->prepare("SELECT id, name FROM contact_groups WHERE company_id=? ORDER BY created_at DESC");
    if ($gStmt) {
        $gStmt->bind_param('i', $companyId);
        $gStmt->execute();
        $gRes = $gStmt->get_result();
        if ($gRes) {
            while ($gr = $gRes->fetch_assoc()) {
                $groups[] = ['id' => (int)$gr['id'], 'name' => (string)$gr['name']];
            }
        }
        $gStmt->close();
    }
} catch (\Throwable $e) {
    // best-effort only
}

// Any POST to this endpoint must be CSRF-protected (even if the submit button name is missing)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$formError = '';
$dupContact = null;
$flashAdded = (($_GET['msg'] ?? '') === 'added');

if (isset($_POST['add'])) {
    // Store raw values; escape on output (avoid double-escaping)
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = sanitize_phone($_POST['phone'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $address = trim((string)($_POST['address'] ?? ''));
    $clientCompany = trim((string)($_POST['client_company'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));
    $groupId = isset($_POST['group_id']) && $_POST['group_id'] !== '' ? (int)$_POST['group_id'] : null;
    if ($groupId !== null && $groupId <= 0) $groupId = null;
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    $rawEmail = trim((string)($_POST['email'] ?? ''));

    if ($name === '') {
        $formError = 'Please enter the contact\'s name.';
    } elseif ($phone === '' || strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
        $formError = 'Enter a valid phone number with country code (8 to 15 digits).';
    } elseif ($rawEmail !== '' && !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
        $formError = 'That email address does not look right.';
    } else {
        // Duplicate check inside this company (the same number written any way)
        $dq = $conn->prepare("SELECT id, name FROM contacts WHERE company_id = ? AND REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(','') = ? LIMIT 1");
        if ($dq) {
            $dq->bind_param('is', $companyId, $phoneDigits);
            $dq->execute();
            $dupContact = $dq->get_result()->fetch_assoc() ?: null;
            $dq->close();
        }
        if ($dupContact) {
            $formError = 'A contact with this number already exists.';
        }
    }

    if ($formError === '') {
        // IDOR protection: group must belong to this company
        if ($groupId !== null) {
            $chk = $conn->prepare("SELECT id FROM contact_groups WHERE id=? AND company_id=? LIMIT 1");
            if ($chk) {
                $chk->bind_param('ii', $groupId, $companyId);
                $chk->execute();
                $ok = $chk->get_result()->num_rows > 0;
                $chk->close();
                if (!$ok) $groupId = null;
            } else {
                $groupId = null;
            }
        }

        $stmt = $conn->prepare("INSERT INTO contacts (company_id, name, phone, email, address, client_company, tags, group_id) VALUES (?,?,?,?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param("issssssi", $companyId, $name, $phone, $email, $address, $clientCompany, $tags, $groupId);
            $stmt->execute();
            $newContactId = $conn->insert_id;
            if ($newContactId > 0) {
                // Trigger any matching drip campaigns based on tags
                trigger_drip_campaigns($conn, (int)$companyId, (int)$newContactId, (string)$tags);
            }
            if (isset($_POST['add_another'])) {
                header("Location: contact_add.php?msg=added" . ($groupId ? '&group_id=' . (int)$groupId : ''));
            } else {
                header("Location: contacts.php?msg=added");
            }
            exit();
        }
        error_log('[WAAMINI] contact_add.php: prepare() failed for insert');
        $formError = 'Could not save the contact. Please try again.';
    }
}

// Existing tags in this company (most used first) for one-click picking
$tagStats = [];
try {
    $tq = $conn->prepare("SELECT tags FROM contacts WHERE company_id = ? AND tags IS NOT NULL AND tags <> '' LIMIT 1000");
    if ($tq) {
        $tq->bind_param('i', $companyId);
        $tq->execute();
        $tr = $tq->get_result();
        while ($tr && ($row = $tr->fetch_assoc())) {
            foreach (explode(',', (string)$row['tags']) as $t) {
                $t = trim($t);
                if ($t !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $t)) { $k = mb_strtolower($t); $tagStats[$k] = ['label' => $t, 'n' => ($tagStats[$k]['n'] ?? 0) + 1]; }
            }
        }
        $tq->close();
    }
} catch (\Throwable $e) { /* optional */ }
uasort($tagStats, static fn($a, $b) => $b['n'] <=> $a['n']);
$tagSuggest = array_slice(array_column($tagStats, 'label'), 0, 10);

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="../assets/css/dash-pro.css?v=2">
<?php
$esc = static fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$__ic = static function (string $p, int $s = 18): string {
    return '<svg class="db-ic" viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$I = [
    'back'  => '<path d="m15 18-6-6 6-6"/>',
    'user'  => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'build' => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
    'groups'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'check' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'upload'=> '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    'bolt'  => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
];
$v = static fn(string $k) => $esc((string)($_POST[$k] ?? ''));
$preGroup = isset($_GET['group_id']) ? (int)$_GET['group_id'] : (isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0);
$countries = ['91' => 'India +91', '1' => 'US / Canada +1', '44' => 'UK +44', '971' => 'UAE +971', '966' => 'Saudi Arabia +966', '65' => 'Singapore +65', '61' => 'Australia +61', '60' => 'Malaysia +60', '94' => 'Sri Lanka +94', '977' => 'Nepal +977', '880' => 'Bangladesh +880', '92' => 'Pakistan +92'];
?>

<div class="db ca">

  <div class="db-head">
    <div>
      <h1>Add contact</h1>
      <p>Save a customer so you can message them, add them to groups and campaigns, and track their chats.</p>
    </div>
    <div class="db-head__actions">
      <a href="contact_import.php" class="db-btn"><?php echo $__ic($I['upload'], 16); ?> Import from file</a>
      <a href="contacts.php" class="db-btn"><?php echo $__ic($I['back'], 16); ?> Back to contacts</a>
    </div>
  </div>

  <?php if ($flashAdded && $formError === ''): ?>
    <div class="ca-toast is-ok" role="status"><?php echo $__ic($I['check'], 18); ?> <span>Contact saved. Add the next one below.</span></div>
  <?php endif; ?>
  <?php if ($formError !== ''): ?>
    <div class="ca-toast is-err" role="alert"><?php echo $__ic($I['alert'], 18); ?> <span><?php echo $esc($formError); ?>
      <?php if ($dupContact): ?> <a href="contact_edit.php?id=<?php echo (int)$dupContact['id']; ?>">Open <?php echo $esc($dupContact['name']); ?></a><?php endif; ?></span></div>
  <?php endif; ?>

  <form method="POST" autocomplete="on" id="caForm" novalidate>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="add" value="1">
    <input type="hidden" name="phone" id="caPhoneFull" value="<?php echo $v('phone'); ?>">

    <div class="ca-grid">
      <div class="ca-main">

        <section class="db-card ca-card">
          <div class="ca-ch"><span class="ca-ci"><?php echo $__ic($I['user'], 20); ?></span><div><h2>Basic details</h2><p>Name and phone are required.</p></div></div>
          <div class="ca-field">
            <label class="ca-lab" for="c_name">Full name <b>*</b></label>
            <input id="c_name" type="text" name="name" required value="<?php echo $v('name'); ?>" placeholder="e.g. Priya Sharma" autocomplete="name" autofocus>
            <p class="ca-err" id="eName"></p>
          </div>
          <div class="ca-field">
            <label class="ca-lab" for="c_phone">WhatsApp number <b>*</b></label>
            <div class="ca-phone">
              <select id="caCode" aria-label="Country code">
                <?php foreach ($countries as $code => $lbl): ?><option value="<?php echo $code; ?>" <?php echo $code === '91' ? 'selected' : ''; ?>><?php echo $esc($lbl); ?></option><?php endforeach; ?>
              </select>
              <input id="c_phone" type="tel" inputmode="tel" value="" placeholder="98765 43210" autocomplete="tel-national">
            </div>
            <p class="ca-hint">We send WhatsApp messages to this number. You can also paste a full number starting with +.</p>
            <p class="ca-err" id="ePhone"></p>
          </div>
          <div class="ca-field">
            <label class="ca-lab" for="c_email">Email <span class="ca-opt">optional</span></label>
            <input id="c_email" type="email" name="email" value="<?php echo $v('email'); ?>" placeholder="name@example.com" autocomplete="email">
            <p class="ca-err" id="eEmail"></p>
          </div>
        </section>

        <section class="db-card ca-card">
          <div class="ca-ch"><span class="ca-ci"><?php echo $__ic($I['groups'], 20); ?></span><div><h2>Organise</h2><p>Groups and tags make campaigns and filters easy.</p></div></div>
          <div class="ca-field">
            <label class="ca-lab" for="c_group">Group <span class="ca-opt">optional</span></label>
            <select id="c_group" name="group_id">
              <option value="">No group</option>
              <?php foreach ($groups as $g): ?><option value="<?php echo (int)$g['id']; ?>" <?php echo $preGroup === (int)$g['id'] ? 'selected' : ''; ?>><?php echo $esc($g['name']); ?></option><?php endforeach; ?>
            </select>
            <p class="ca-hint">Manage groups in <a href="contact_groups.php">Groups</a>.</p>
          </div>
          <div class="ca-field">
            <label class="ca-lab" for="c_tagin">Tags <span class="ca-opt">optional</span></label>
            <div class="ca-tagbox" id="caTagBox"><input id="c_tagin" type="text" placeholder="Type a tag and press Enter" autocomplete="off"></div>
            <input type="hidden" name="tags" id="caTags" value="<?php echo $v('tags'); ?>">
            <div class="ca-sug" id="caSug"><?php foreach ($tagSuggest as $t): ?><button type="button" data-t="<?php echo $esc($t); ?>">+ <?php echo $esc($t); ?></button><?php endforeach; ?></div>
            <p class="ca-hint"><?php echo $__ic($I['bolt'], 13); ?> Tags can start automations such as drip campaigns the moment the contact is saved.</p>
          </div>
        </section>

        <section class="db-card ca-card">
          <details class="ca-more" <?php echo ($v('client_company') !== '' || $v('address') !== '') ? 'open' : ''; ?>>
            <summary><span class="ca-ci"><?php echo $__ic($I['build'], 20); ?></span><div><h2>Business and billing details</h2><p>Optional. Used on quotations and invoices.</p></div></summary>
            <div class="ca-field">
              <label class="ca-lab" for="c_company">Business name</label>
              <input id="c_company" type="text" name="client_company" value="<?php echo $v('client_company'); ?>" placeholder="e.g. Acme Traders">
            </div>
            <div class="ca-field">
              <label class="ca-lab" for="c_address">Billing address</label>
              <textarea id="c_address" name="address" rows="3" placeholder="Street, city, state, PIN code"><?php echo $v('address'); ?></textarea>
            </div>
          </details>
        </section>

        <div class="ca-actions">
          <button type="submit" class="db-btn db-btn--primary" id="caSave"><?php echo $__ic($I['check'], 16); ?> Save contact</button>
          <button type="submit" name="add_another" value="1" class="db-btn" id="caSave2">Save and add another</button>
          <a href="contacts.php" class="ca-cancel">Cancel</a>
        </div>
      </div>

      <aside class="ca-side">
        <section class="db-card ca-card ca-prev">
          <div class="ca-ch"><div><h2>Preview</h2><p>How this contact will look.</p></div></div>
          <div class="ca-pc">
            <span class="ca-av" id="pvAv">?</span>
            <b id="pvName">Contact name</b>
            <span id="pvPhone" class="ca-pp"><?php echo $__ic($I['phone'], 14); ?> <span>+91 &hellip;</span></span>
            <span id="pvMail" class="ca-pm" style="display:none"></span>
            <div class="ca-pt" id="pvTags"></div>
          </div>
          <div class="ca-tips">
            <div><?php echo $__ic($I['check'], 15); ?> Use the full number with country code so messages deliver.</div>
            <div><?php echo $__ic($I['check'], 15); ?> Duplicates are caught, so a number is only saved once.</div>
            <div><?php echo $__ic($I['check'], 15); ?> Have many contacts? <a href="contact_import.php">Import a CSV file</a>.</div>
          </div>
        </section>
      </aside>
    </div>
  </form>
</div>

<style>
.ca{max-width:none;margin:0}
.ca-toast{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;padding:13px 16px;border-radius:12px;font-size:14.5px;font-weight:600;line-height:1.45}
.ca-toast.is-ok{background:#E6FCF2;color:#0B6B5E}.ca-toast.is-err{background:#FDE4E1;color:#c0372b}
.ca-toast svg{margin-top:2px;flex:none}.ca-toast a{color:inherit;font-weight:800}
.ca-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px;align-items:start}
.ca-main{display:flex;flex-direction:column;gap:18px;min-width:0}
.ca-card{padding:22px}
.ca-ch{display:flex;align-items:center;gap:14px;margin-bottom:18px}
.ca-ch h2,.ca-more h2{margin:0;font-size:17.5px;font-weight:800;color:var(--ink)}
.ca-ch p,.ca-more p{margin:3px 0 0;font-size:13.5px;color:var(--mu)}
.ca-ci{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center;flex:none}
.ca-field{margin-bottom:16px}.ca-field:last-child{margin-bottom:0}
.ca-lab{display:block;margin:0 0 6px;font-size:13.5px;font-weight:700;color:var(--tx)}
.ca-lab b{color:#E5483A}.ca-opt{font-weight:500;color:var(--mu);font-size:12.5px;margin-left:4px}
.ca input[type=text],.ca input[type=tel],.ca input[type=email],.ca select,.ca textarea{width:100%;padding:0 14px;border:1.5px solid var(--line);border-radius:11px;background:#fff;font:500 14.5px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s}
.ca input[type=text],.ca input[type=tel],.ca input[type=email],.ca select{height:46px}
.ca textarea{padding:12px 14px;line-height:1.55;resize:vertical}
.ca input:focus,.ca select:focus,.ca textarea:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.ca .is-bad{border-color:#E5483A!important}
.ca-hint{margin:6px 0 0;font-size:12.5px;color:var(--mu);line-height:1.5;display:flex;gap:6px;align-items:flex-start}
.ca-hint svg{margin-top:2px;color:var(--g);flex:none}
.ca-hint a{color:#128C7E;font-weight:700;text-decoration:none}
.ca-err{margin:6px 0 0;font-size:12.5px;font-weight:600;color:#c0372b;display:none}
.ca-err.is-show{display:block}
.ca-phone{display:flex;gap:10px}
.ca-phone select{width:170px;flex:none}
.ca-phone input{flex:1;min-width:0}
.ca-tagbox{display:flex;flex-wrap:wrap;gap:8px;padding:8px 10px;border:1.5px solid var(--line);border-radius:11px;background:#fff;min-height:46px;align-items:center;cursor:text}
.ca-tagbox:focus-within{border-color:var(--g);box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.ca-tagbox input{flex:1;min-width:140px;border:0!important;box-shadow:none!important;height:30px!important;padding:0 4px!important}
.ca-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 6px 5px 12px;border-radius:999px;background:#E6FCF2;color:#0B6B5E;font-size:13px;font-weight:700}
.ca-chip button{width:20px;height:20px;border:0;border-radius:50%;background:rgba(18,140,126,.15);color:#0B6B5E;cursor:pointer;line-height:1;font-size:14px;padding:0}
.ca-chip button:hover{background:rgba(18,140,126,.3)}
.ca-sug{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.ca-sug button{padding:6px 13px;border:0;border-radius:999px;background:var(--soft);color:var(--tx);font:700 13px Inter,system-ui,sans-serif;cursor:pointer;transition:background .15s,color .15s}
.ca-sug button:hover{background:#E6FCF2;color:var(--gd)}
.ca-sug button.is-used{display:none}
.ca-more summary{display:flex;align-items:center;gap:14px;cursor:pointer;list-style:none}
.ca-more summary::-webkit-details-marker{display:none}
.ca-more summary::after{content:"+";margin-left:auto;font-size:22px;font-weight:600;color:var(--mu)}
.ca-more[open] summary::after{content:"\2212"}
.ca-more[open] summary{margin-bottom:18px}
.ca-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.ca-cancel{font-weight:700;font-size:14px;color:var(--mu);text-decoration:none;padding:0 6px}
.ca-cancel:hover{color:var(--ink)}
.ca-side{position:sticky;top:16px}
.ca-pc{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:24px 16px;border-radius:16px;background:linear-gradient(135deg,#F3FBF7,#E6FCF2)}
.ca-av{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;display:grid;place-items:center;font-size:26px;font-weight:800;margin-bottom:4px}
.ca-pc b{font-size:18px;color:var(--ink);word-break:break-word}
.ca-pp{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:var(--gd)}
.ca-pm{font-size:13px;color:var(--mu);word-break:break-all}
.ca-pt{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:6px}
.ca-pt span{padding:4px 11px;border-radius:999px;background:#fff;color:#0B6B5E;font-size:12.5px;font-weight:700}
.ca-tips{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.ca-tips div{display:flex;gap:9px;align-items:flex-start;font-size:13px;line-height:1.5;color:var(--tx)}
.ca-tips svg{color:var(--g);margin-top:2px;flex:none}
.ca-tips a{color:#128C7E;font-weight:700;text-decoration:none}
@media (max-width:1000px){.ca-grid{grid-template-columns:1fr}.ca-side{position:static;order:-1}}
@media (max-width:560px){.ca-phone{flex-direction:column}.ca-phone select{width:100%}}
</style>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var form = $('caForm'), tags = [];
  var codes = <?php echo json_encode(array_map('strval', array_keys($countries))); ?>;

  // restore a number submitted earlier (stored as +CCNUMBER)
  (function restore() {
    var full = $('caPhoneFull').value.replace(/[^\d+]/g, ''); if (!full) { return; }
    var d = full.replace(/\D/g, ''), best = '';
    codes.forEach(function (c) { if (d.indexOf(c) === 0 && c.length > best.length && full.charAt(0) === '+') { best = c; } });
    if (best) { $('caCode').value = best; $('c_phone').value = d.slice(best.length); } else { $('c_phone').value = d; }
  })();

  function fullPhone() {
    var raw = $('c_phone').value.trim();
    if (!raw) { return ''; }
    if (raw.charAt(0) === '+') { return '+' + raw.replace(/\D/g, ''); }
    var d = raw.replace(/\D/g, '').replace(/^0+/, '');
    return '+' + $('caCode').value + d;
  }
  function phoneOk(p) { var n = p.replace(/\D/g, '').length; return n >= 8 && n <= 15; }

  /* tags */
  var box = $('caTagBox'), tin = $('c_tagin'), hid = $('caTags');
  function setTags(arr) {
    var seen = {}; tags = [];
    arr.forEach(function (t) { t = t.trim(); var k = t.toLowerCase(); if (t && !seen[k]) { seen[k] = 1; tags.push(t); } });
    box.querySelectorAll('.ca-chip').forEach(function (c) { c.remove(); });
    tags.forEach(function (t, i) {
      var c = document.createElement('span'); c.className = 'ca-chip'; c.appendChild(document.createTextNode(t));
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Remove ' + t); b.textContent = '×'; b.setAttribute('data-i', i);
      c.appendChild(b); box.insertBefore(c, tin);
    });
    hid.value = tags.join(', ');
    document.querySelectorAll('#caSug button').forEach(function (s) { s.classList.toggle('is-used', tags.some(function (t) { return t.toLowerCase() === s.getAttribute('data-t').toLowerCase(); })); });
    preview();
  }
  function addTag(t) { setTags(tags.concat(t.split(','))); }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-i]');
    if (b) { var i = parseInt(b.getAttribute('data-i'), 10); setTags(tags.filter(function (_, k) { return k !== i; })); return; }
    tin.focus();
  });
  tin.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); if (tin.value.trim()) { addTag(tin.value); tin.value = ''; } }
    else if (e.key === 'Backspace' && !tin.value && tags.length) { setTags(tags.slice(0, -1)); }
  });
  tin.addEventListener('blur', function () { if (tin.value.trim()) { addTag(tin.value); tin.value = ''; } });
  $('caSug').addEventListener('click', function (e) { var b = e.target.closest('button[data-t]'); if (b) { addTag(b.getAttribute('data-t')); } });

  /* live preview */
  function initials(n) { var p = n.trim().split(/\s+/).filter(Boolean); return p.length ? (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() : '?'; }
  function preview() {
    var n = $('c_name').value.trim(), ph = fullPhone(), em = $('c_email').value.trim();
    $('pvAv').textContent = initials(n); $('pvName').textContent = n || 'Contact name';
    $('pvPhone').lastElementChild.textContent = ph ? ph.replace(/^(\+\d{1,3})(\d{5})(\d+)$/, '$1 $2 $3') : '+91 …';
    var pm = $('pvMail'); pm.style.display = em ? '' : 'none'; pm.textContent = em;
    var tb = $('pvTags'); tb.innerHTML = '';
    tags.forEach(function (t) { var s = document.createElement('span'); s.textContent = t; tb.appendChild(s); });
  }
  ['c_name', 'c_phone', 'c_email', 'caCode'].forEach(function (id) { $(id).addEventListener('input', preview); });
  $('caCode').addEventListener('change', preview);
  setTags(hid.value ? hid.value.split(',') : []);

  /* validation */
  function err(id, input, msg) { var e = $(id); e.textContent = msg || ''; e.classList.toggle('is-show', !!msg); if (input) { input.classList.toggle('is-bad', !!msg); } return !msg; }
  $('c_name').addEventListener('input', function () { err('eName', this, ''); });
  $('c_phone').addEventListener('input', function () { err('ePhone', this, ''); });
  $('c_email').addEventListener('input', function () { err('eEmail', this, ''); });
  form.addEventListener('submit', function (e) {
    if (tin.value.trim()) { addTag(tin.value); tin.value = ''; }
    var ph = fullPhone(); $('caPhoneFull').value = ph;
    var ok = true;
    ok = err('eName', $('c_name'), $('c_name').value.trim() ? '' : 'Please enter a name.') && ok;
    ok = err('ePhone', $('c_phone'), phoneOk(ph) ? '' : 'Enter a valid number with country code (8 to 15 digits).') && ok;
    var em = $('c_email').value.trim();
    ok = err('eEmail', $('c_email'), (!em || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) ? '' : 'That email address does not look right.') && ok;
    if (!ok) { e.preventDefault(); var first = form.querySelector('.is-bad'); if (first) { first.focus(); } return; }
    // a disabled submit button is not posted, so carry "save and add another" in a hidden field
    if (e.submitter && e.submitter.name === 'add_another') { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'add_another'; h.value = '1'; form.appendChild(h); }
    $('caSave').disabled = true; $('caSave2').disabled = true;
  });
})();
</script>

<?php include '../includes/client_footer.php'; ?>

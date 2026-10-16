<?php
/**
 * Landing CMS: Home Page sections.
 * Edits the blocks that drive the public home page (hero extras, trust strip, features hub, pricing, FAQ, final CTA).
 * Hero text/buttons live in "Hero Sections", stories in "Testimonials", site details in "Site Settings".
 * Each block falls back to the built-in landing content when it is switched off, so the site never breaks.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sync.php';

la_require_login();

$laTitle = 'Home Page';
$laPage  = 'home-sections';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

$defs = la_site_blocks();   // key => [title, default data]
$icons = ['send' => 'Send', 'bot' => 'Bot', 'cart' => 'Cart', 'repeat' => 'Repeat', 'file' => 'File', 'users' => 'Users', 'target' => 'Target', 'bell' => 'Bell', 'link' => 'Link'];

/** textarea lines -> clean list */
$lines = static fn($v) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$v) ?: []), static fn($x) => $x !== ''));
$rows  = static fn($v) => is_array($v) ? array_values($v) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $key = (string)($_POST['block'] ?? '');

    if ($action === 'sync') {
        $log = la_sync_from_site($conn, !empty($_POST['overwrite']));
        la_flash_set('success', implode(' · ', $log));
        header('Location: home-sections.php'); exit;
    }
    if (!isset($defs[$key])) { la_flash_set('error', 'Unknown section.'); header('Location: home-sections.php'); exit; }
    $title = $defs[$key][0];
    $sort = array_search($key, array_keys($defs), true) + 1;

    if ($action === 'reset') {
        la_block_save($conn, $key, $title, $defs[$key][1], 1, $sort);
        la_purge_page_cache();
        la_flash_set('success', $title . ' reset to the landing page content.');
        header('Location: home-sections.php#' . $key); exit;
    }

    $active = isset($_POST['is_active']) ? 1 : 0;
    $data = [];
    switch ($key) {
        case 'hero_extra':
            $data = ['typed' => $lines($_POST['typed'] ?? ''), 'bullets' => $lines($_POST['bullets'] ?? ''), 'meta' => $lines($_POST['meta'] ?? '')];
            break;
        case 'trust_stats':
            $items = [];
            foreach ($rows($_POST['items'] ?? []) as $r) {
                if (trim((string)($r['label'] ?? '')) === '') continue;
                $items[] = ['prefix' => trim((string)$r['prefix']), 'value' => (float)$r['value'], 'suffix' => trim((string)$r['suffix']), 'label' => trim((string)$r['label'])];
            }
            $data = ['items' => $items];
            break;
        case 'feature_hub':
            $items = [];
            foreach ($rows($_POST['items'] ?? []) as $r) {
                if (trim((string)($r['title'] ?? '')) === '') continue;
                $items[] = [
                    'side' => (($r['side'] ?? 'L') === 'R') ? 'R' : 'L',
                    'icon' => isset($icons[$r['icon'] ?? '']) ? $r['icon'] : 'link',
                    'title' => trim((string)$r['title']), 'tag' => trim((string)($r['tag'] ?? '')), 'desc' => trim((string)($r['desc'] ?? '')),
                    'in' => trim((string)($r['in'] ?? '')), 'out' => trim((string)($r['out'] ?? '')),
                    'chat_in' => trim((string)($r['chat_in'] ?? '')), 'chat_out' => trim((string)($r['chat_out'] ?? '')), 'chip' => trim((string)($r['chip'] ?? '')),
                ];
            }
            $data = ['items' => $items];
            break;
        case 'home_pricing':
            $items = [];
            foreach ($rows($_POST['items'] ?? []) as $r) {
                if (trim((string)($r['name'] ?? '')) === '') continue;
                $items[] = [
                    'name' => trim((string)$r['name']), 'tagline' => trim((string)($r['tagline'] ?? '')), 'price' => (float)($r['price'] ?? 0),
                    'currency' => strtoupper(substr(trim((string)($r['currency'] ?? 'INR')), 0, 3)) ?: 'INR', 'popular' => !empty($r['popular']) ? 1 : 0,
                    'cta_text' => trim((string)($r['cta_text'] ?? '')), 'cta_url' => trim((string)($r['cta_url'] ?? '')), 'features' => $lines($r['features'] ?? ''),
                ];
            }
            $data = ['items' => $items];
            break;
        case 'faq':
            $items = [];
            foreach ($rows($_POST['items'] ?? []) as $r) {
                if (trim((string)($r['q'] ?? '')) === '' || trim((string)($r['a'] ?? '')) === '') continue;
                $items[] = ['q' => trim((string)$r['q']), 'a' => trim((string)$r['a'])];
            }
            $data = ['items' => $items];
            break;
        case 'final_cta':
            foreach (['heading', 'highlight', 'text', 'primary_text', 'primary_url', 'secondary_text', 'secondary_url'] as $f) { $data[$f] = trim((string)($_POST[$f] ?? '')); }
            $data['meta'] = $lines($_POST['meta'] ?? '');
            break;
    }
    if (!$data || (isset($data['items']) && !$data['items'])) {
        la_flash_set('error', $title . ': add at least one entry (or use Reset).');
    } else {
        la_block_save($conn, $key, $title, $data, $active, $sort);
        la_purge_page_cache();
        la_flash_set('success', $title . ' saved' . ($active ? ' — live on the home page.' : ' but switched OFF — the site shows the built-in content.'));
    }
    header('Location: home-sections.php#' . $key); exit;
}

/* current values (CMS row or default) */
$hsCur = [];
foreach ($defs as $k => [$t, $d]) {
    $st = $conn->prepare("SELECT data_json, is_active, updated_at FROM lp_page_sections WHERE page='home' AND section_key=? LIMIT 1");
    $st->bind_param('s', $k); $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
    $j = $r ? json_decode((string)$r['data_json'], true) : null;
    $hsCur[$k] = ['data' => is_array($j) && $j ? $j : $d, 'active' => $r ? (int)$r['is_active'] : 0, 'saved' => (bool)$r, 'at' => $r['updated_at'] ?? ''];
}

$sections = [
    'hero_extra' => 'Hero: typed words, bullet points, small print',
    'trust_stats' => 'Trust strip (numbers under the hero)',
    'feature_hub' => 'Platform features hub',
    'home_pricing' => 'Pricing cards on the home page',
    'faq' => 'FAQ',
    'final_cta' => 'Final call to action',
];

function hs_input(string $name, $val, string $ph = '', string $cls = ''): string
{
    return '<input class="la-input ' . $cls . '" name="' . e($name) . '" value="' . e((string)$val) . '" placeholder="' . e($ph) . '">';
}
function hs_area(string $name, $val, int $rows = 3): string
{
    return '<textarea class="la-textarea" style="min-height:0" rows="' . $rows . '" name="' . e($name) . '">' . e((string)$val) . '</textarea>';
}

require __DIR__ . '/includes/header.php';
echo la_flash_html();
?>
<style>
.hs-wrap{display:flex;flex-direction:column;gap:18px}
.hs-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.hs-hd{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.hs-status{font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;background:#E6FCF2;color:#128C7E}
.hs-status.off{background:#F3F4F6;color:#6B7280}
.hs-body{padding:20px 24px;display:flex;flex-direction:column;gap:14px}
.hs-item{background:#F8FAFB;border-radius:12px;padding:14px;display:grid;gap:10px}
.hs-item .g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.hs-item .g4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.hs-item .g3{display:grid;grid-template-columns:1fr 2fr 1fr;gap:10px}
.hs-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.hs-hint{font-size:12px;color:var(--text-muted)}
.hs-rm{background:none;border:0;color:#dc2626;font-weight:600;cursor:pointer;justify-self:end;font-size:12px}
@media(max-width:700px){.hs-item .g2,.hs-item .g3,.hs-item .g4{grid-template-columns:1fr}}
</style>

<div class="hs-wrap">
  <div class="hs-top">
    <div>
      <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Home Page</h1>
      <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Home Page</span></div>
    </div>
    <div class="hs-actions">
      <a class="la-btn la-btn-outline la-btn-sm" href="/" target="_blank" rel="noopener">View site</a>
      <form method="POST" onsubmit="return confirm('Replace ALL home page sections, hero and testimonials in the CMS with the current landing page content?')" style="display:inline">
        <?php echo la_csrf_field(); ?><input type="hidden" name="action" value="sync"><input type="hidden" name="overwrite" value="1">
        <button class="la-btn la-btn-primary la-btn-sm" type="submit">Sync from landing page</button>
      </form>
    </div>
  </div>

  <div class="la-card"><div class="hs-body">
    <div class="hs-hint">Each section below is live on the public home page. Saving updates the site immediately. Switch a section off to show the built-in content again. Hero headline and buttons are in <a href="hero.php">Hero Sections</a>, customer stories in <a href="testimonials.php">Testimonials</a>, footer / email / analytics in <a href="settings.php">Site Settings</a>.</div>
  </div></div>

<?php foreach ($sections as $key => $label): $c = $hsCur[$key]; $d = $c['data']; ?>
  <div class="la-card" id="<?php echo $key; ?>">
    <div class="la-card-hd hs-hd"><span><?php echo e($label); ?></span>
      <span class="hs-status <?php echo $c['active'] ? '' : 'off'; ?>"><?php echo $c['active'] ? 'Live from CMS' : 'Built-in content'; ?></span></div>
    <form method="POST" class="hs-body">
      <?php echo la_csrf_field(); ?>
      <input type="hidden" name="block" value="<?php echo $key; ?>">

<?php if ($key === 'hero_extra'): ?>
      <div class="la-form-group"><label class="la-label">Typed words (one per line, shown after "Complete")</label><?php echo hs_area('typed', implode("\n", $d['typed'] ?? []), 4); ?></div>
      <div class="la-form-group"><label class="la-label">Bullet points (one per line)</label><?php echo hs_area('bullets', implode("\n", $d['bullets'] ?? []), 6); ?></div>
      <div class="la-form-group"><label class="la-label">Small print under the buttons (one per line)</label><?php echo hs_area('meta', implode("\n", $d['meta'] ?? []), 3); ?></div>

<?php elseif ($key === 'trust_stats'): ?>
      <div data-rows="<?php echo $key; ?>">
      <?php foreach (($d['items'] ?? []) as $i => $s): ?>
        <div class="hs-item" data-row><div class="g4">
          <?php echo hs_input("items[$i][prefix]", $s['prefix'] ?? '', 'Prefix e.g. ₹'); ?>
          <?php echo hs_input("items[$i][value]", $s['value'] ?? 0, 'Number'); ?>
          <?php echo hs_input("items[$i][suffix]", $s['suffix'] ?? '', 'Suffix e.g. +'); ?>
          <?php echo hs_input("items[$i][label]", $s['label'] ?? '', 'Label'); ?>
        </div><button type="button" class="hs-rm" data-rm>Remove</button></div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="la-btn la-btn-outline la-btn-sm" data-add="<?php echo $key; ?>">+ Add number</button>

<?php elseif ($key === 'feature_hub'): ?>
      <div class="hs-hint">Side = which column the card sits in. Icon picks the small icon. The two chat lines appear in the animated preview.</div>
      <div data-rows="<?php echo $key; ?>">
      <?php foreach (($d['items'] ?? []) as $i => $f): ?>
        <div class="hs-item" data-row>
          <div class="g3">
            <select class="la-select" name="items[<?php echo $i; ?>][side]"><option value="L" <?php echo ($f['side'] ?? 'L') === 'L' ? 'selected' : ''; ?>>Left</option><option value="R" <?php echo ($f['side'] ?? '') === 'R' ? 'selected' : ''; ?>>Right</option></select>
            <?php echo hs_input("items[$i][title]", $f['title'] ?? '', 'Title'); ?>
            <select class="la-select" name="items[<?php echo $i; ?>][icon]"><?php foreach ($icons as $ik => $il): ?><option value="<?php echo $ik; ?>" <?php echo ($f['icon'] ?? '') === $ik ? 'selected' : ''; ?>><?php echo $il; ?></option><?php endforeach; ?></select>
          </div>
          <?php echo hs_input("items[$i][tag]", $f['tag'] ?? '', 'Small tag line'); ?>
          <?php echo hs_area("items[$i][desc]", $f['desc'] ?? '', 2); ?>
          <div class="g2"><?php echo hs_input("items[$i][in]", $f['in'] ?? '', 'Input label'); ?><?php echo hs_input("items[$i][out]", $f['out'] ?? '', 'Output label'); ?></div>
          <div class="g2"><?php echo hs_input("items[$i][chat_in]", $f['chat_in'] ?? '', 'Chat: customer says'); ?><?php echo hs_input("items[$i][chat_out]", $f['chat_out'] ?? '', 'Chat: business replies'); ?></div>
          <?php echo hs_input("items[$i][chip]", $f['chip'] ?? '', 'Chip text'); ?>
          <button type="button" class="hs-rm" data-rm>Remove</button>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="la-btn la-btn-outline la-btn-sm" data-add="<?php echo $key; ?>">+ Add feature</button>

<?php elseif ($key === 'home_pricing'): ?>
      <div class="hs-hint">Features: one per line. Use **text** for bold, start a line with ✓ for a tick item.</div>
      <div data-rows="<?php echo $key; ?>">
      <?php foreach (($d['items'] ?? []) as $i => $p): ?>
        <div class="hs-item" data-row>
          <div class="g4">
            <?php echo hs_input("items[$i][name]", $p['name'] ?? '', 'Plan name'); ?>
            <?php echo hs_input("items[$i][price]", $p['price'] ?? 0, 'Monthly price'); ?>
            <?php echo hs_input("items[$i][currency]", $p['currency'] ?? 'INR', 'INR'); ?>
            <label class="hs-hint" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="items[<?php echo $i; ?>][popular]" value="1" <?php echo !empty($p['popular']) ? 'checked' : ''; ?>> Most popular</label>
          </div>
          <?php echo hs_input("items[$i][tagline]", $p['tagline'] ?? '', 'Tagline'); ?>
          <div class="g2"><?php echo hs_input("items[$i][cta_text]", $p['cta_text'] ?? '', 'Button text'); ?><?php echo hs_input("items[$i][cta_url]", $p['cta_url'] ?? '', 'Button link'); ?></div>
          <?php echo hs_area("items[$i][features]", implode("\n", (array)($p['features'] ?? [])), 6); ?>
          <button type="button" class="hs-rm" data-rm>Remove</button>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="la-btn la-btn-outline la-btn-sm" data-add="<?php echo $key; ?>">+ Add plan</button>

<?php elseif ($key === 'faq'): ?>
      <div data-rows="<?php echo $key; ?>">
      <?php foreach (($d['items'] ?? []) as $i => $q): ?>
        <div class="hs-item" data-row>
          <?php echo hs_input("items[$i][q]", $q['q'] ?? '', 'Question'); ?>
          <?php echo hs_area("items[$i][a]", $q['a'] ?? '', 3); ?>
          <button type="button" class="hs-rm" data-rm>Remove</button>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="la-btn la-btn-outline la-btn-sm" data-add="<?php echo $key; ?>">+ Add question</button>

<?php elseif ($key === 'final_cta'): ?>
      <div class="la-form-grid">
        <div class="la-form-group"><label class="la-label">Heading</label><?php echo hs_input('heading', $d['heading'] ?? ''); ?></div>
        <div class="la-form-group"><label class="la-label">Highlighted word (inside heading)</label><?php echo hs_input('highlight', $d['highlight'] ?? ''); ?></div>
        <div class="la-form-group full"><label class="la-label">Text</label><?php echo hs_area('text', $d['text'] ?? '', 3); ?></div>
        <div class="la-form-group"><label class="la-label">Primary button text</label><?php echo hs_input('primary_text', $d['primary_text'] ?? ''); ?></div>
        <div class="la-form-group"><label class="la-label">Primary button link</label><?php echo hs_input('primary_url', $d['primary_url'] ?? ''); ?></div>
        <div class="la-form-group"><label class="la-label">Secondary button text</label><?php echo hs_input('secondary_text', $d['secondary_text'] ?? ''); ?></div>
        <div class="la-form-group"><label class="la-label">Secondary button link</label><?php echo hs_input('secondary_url', $d['secondary_url'] ?? ''); ?></div>
        <div class="la-form-group full"><label class="la-label">Small print (one per line)</label><?php echo hs_area('meta', implode("\n", $d['meta'] ?? []), 3); ?></div>
      </div>
<?php endif; ?>

      <div class="hs-actions">
        <label class="hs-hint" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="is_active" value="1" <?php echo $c['active'] ? 'checked' : ''; ?>> Use this content on the website</label>
        <button class="la-btn la-btn-primary la-btn-sm" type="submit" name="action" value="save">Save</button>
        <button class="la-btn la-btn-outline la-btn-sm" type="submit" name="action" value="reset" onclick="return confirm('Reset this section to the landing page content?')">Reset to landing page</button>
        <?php if ($c['at']): ?><span class="hs-hint">Last saved <?php echo e(la_time_ago($c['at'])); ?></span><?php endif; ?>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>

<script>
document.querySelectorAll('[data-add]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var box = document.querySelector('[data-rows="' + btn.dataset.add + '"]');
    var rows = box.querySelectorAll('[data-row]');
    var clone = rows[rows.length - 1].cloneNode(true);
    var n = rows.length;
    clone.querySelectorAll('input,textarea,select').forEach(function (el) {
      el.name = el.name.replace(/items\[\d+\]/, 'items[' + n + ']');
      if (el.type === 'checkbox') el.checked = false; else if (el.tagName !== 'SELECT') el.value = '';
    });
    box.appendChild(clone);
  });
});
document.addEventListener('click', function (e) {
  if (!e.target.matches('[data-rm]')) return;
  var row = e.target.closest('[data-row]');
  if (row.parentNode.querySelectorAll('[data-row]').length > 1) row.remove();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

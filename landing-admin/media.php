<?php
/** Landing Admin — Media Library */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
la_require_login();

$laTitle = 'Media Library';
$laPage  = 'media';
$draftCount = (int)($conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'")->fetch_assoc()['n'] ?? 0);

/* ── Handle AJAX delete ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['media_id'] ?? 0);
        if ($id) {
            $st = $conn->prepare("SELECT url FROM lp_media WHERE id=? LIMIT 1");
            $st->bind_param('i', $id); $st->execute();
            $row = $st->get_result()->fetch_assoc(); $st->close();
            if ($row) {
                $filePath = realpath(__DIR__ . '/..') . str_replace('/waamini', '', $row['url']);
                if (is_file($filePath)) @unlink($filePath);
            }
            $st2 = $conn->prepare("DELETE FROM lp_media WHERE id=?");
            $st2->bind_param('i', $id); $st2->execute(); $st2->close();
            la_flash_set('success', 'File deleted.');
        }
        header('Location: media.php'); exit;

    } elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        foreach ($ids as $id) {
            if (!$id) continue;
            $st = $conn->prepare("SELECT url FROM lp_media WHERE id=? LIMIT 1");
            $st->bind_param('i', $id); $st->execute();
            $row = $st->get_result()->fetch_assoc(); $st->close();
            if ($row) {
                $filePath = realpath(__DIR__ . '/..') . str_replace('/waamini', '', $row['url']);
                if (is_file($filePath)) @unlink($filePath);
            }
            $st2 = $conn->prepare("DELETE FROM lp_media WHERE id=?");
            $st2->bind_param('i', $id); $st2->execute(); $st2->close();
        }
        la_flash_set('success', count($ids) . ' file(s) deleted.');
        header('Location: media.php'); exit;
    }
}

/* ── Pagination & filter ──────────────────────────────────────────────── */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 24;
$offset = ($page - 1) * $limit;
$q      = trim($_GET['q'] ?? '');
$type   = trim($_GET['type'] ?? '');

$where  = ['1=1'];
$types  = '';
$params = [];

if ($q) {
    $where[] = 'filename LIKE ?';
    $types  .= 's';
    $params[] = '%' . $q . '%';
}
if ($type) {
    $where[] = 'mime_type LIKE ?';
    $types  .= 's';
    $params[] = $type . '%';
}

$whereSQL = implode(' AND ', $where);

$total = 0;
if ($types) {
    $cStmt = $conn->prepare("SELECT COUNT(*) AS n FROM lp_media WHERE $whereSQL");
    $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $total = (int)$cStmt->get_result()->fetch_assoc()['n'];
    $cStmt->close();
} else {
    $total = (int)$conn->query("SELECT COUNT(*) AS n FROM lp_media")->fetch_assoc()['n'];
}

$pages = (int)ceil($total / $limit);
$media = [];

$sql = "SELECT id,filename,url,mime_type,file_size,created_at FROM lp_media WHERE $whereSQL ORDER BY id DESC LIMIT ? OFFSET ?";
if ($types) {
    $mStmt = $conn->prepare($sql);
    $allTypes  = $types . 'ii';
    $allParams = array_merge($params, [$limit, $offset]);
    $mStmt->bind_param($allTypes, ...$allParams);
} else {
    $mStmt = $conn->prepare("SELECT id,filename,url,mime_type,file_size,created_at FROM lp_media ORDER BY id DESC LIMIT ? OFFSET ?");
    $mStmt->bind_param('ii', $limit, $offset);
}
$mStmt->execute();
$res = $mStmt->get_result();
while ($r = $res->fetch_assoc()) $media[] = $r;
$mStmt->close();

// Storage stats
$storageRow = $conn->query("SELECT COUNT(*) AS cnt, SUM(file_size) AS total_bytes FROM lp_media")->fetch_assoc();

require __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Media Library</h1>
        <div class="la-breadcrumb"><a href="/landing-admin/">Dashboard</a><span>/</span><span>Media</span></div>
    </div>
    <button onclick="document.getElementById('uploadZone').scrollIntoView({behavior:'smooth'})" class="la-btn la-btn-primary">↑ Upload Files</button>
</div>

<!-- Upload Zone -->
<div class="la-card" id="uploadZone" style="margin-bottom:20px;">
    <div class="la-card-hd"><span>Upload Images</span><span style="font-size:11px;color:var(--text-muted);">JPG, PNG, GIF, WebP · Max 5 MB each</span></div>
    <div style="padding:20px;">
        <div id="dropArea"
             style="border:2px dashed var(--border);border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;"
             onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.style.borderColor='var(--accent)';this.style.background='rgba(0,210,255,.05)'"
             ondragleave="this.style.borderColor='var(--border)';this.style.background=''"
             ondrop="handleDrop(event)">
            <div style="width:52px;height:52px;border-radius:12px;background:var(--accent-light);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:var(--accent);">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/></svg>
            </div>
            <div style="font-size:15px;font-weight:600;color:var(--text-primary);margin-bottom:6px;">Drop files here or click to browse</div>
            <div style="font-size:12px;color:var(--text-muted);">Supported: JPG, PNG, GIF, WebP — up to 5 MB each</div>
        </div>
        <input type="file" id="fileInput" multiple accept="image/*" style="display:none" onchange="uploadFiles(this.files)">

        <!-- Upload progress list -->
        <div id="uploadList" style="margin-top:16px;display:flex;flex-direction:column;gap:8px;"></div>
    </div>
</div>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
    <div class="la-card" style="padding:16px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:var(--accent);"><?php echo number_format((int)$storageRow['cnt']); ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Total Files</div>
    </div>
    <div class="la-card" style="padding:16px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:var(--accent);"><?php echo la_filesize((int)$storageRow['total_bytes']); ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Storage Used</div>
    </div>
    <div class="la-card" style="padding:16px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:var(--accent);"><?php echo $total; ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Filtered Results</div>
    </div>
</div>

<!-- Filters + grid -->
<div class="la-card">
    <div class="la-card-hd" style="flex-wrap:wrap;gap:10px;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" name="q" class="la-input" placeholder="Search filename…"
                value="<?php echo e($q); ?>" style="width:200px;">
            <select name="type" class="la-select" style="width:130px;">
                <option value="">All types</option>
                <option value="image/jpeg" <?php echo $type==='image/jpeg'?'selected':''; ?>>JPEG</option>
                <option value="image/png"  <?php echo $type==='image/png' ?'selected':''; ?>>PNG</option>
                <option value="image/gif"  <?php echo $type==='image/gif' ?'selected':''; ?>>GIF</option>
                <option value="image/webp" <?php echo $type==='image/webp'?'selected':''; ?>>WebP</option>
            </select>
            <button type="submit" class="la-btn la-btn-outline la-btn-sm">Filter</button>
            <?php if ($q || $type): ?><a href="media.php" class="la-btn la-btn-ghost la-btn-sm">Clear</a><?php endif; ?>
        </form>
        <div style="display:flex;gap:8px;margin-left:auto;">
            <button id="btnSelectAll" onclick="toggleSelectAll()" class="la-btn la-btn-outline la-btn-sm">Select All</button>
            <button onclick="bulkDelete()" class="la-btn la-btn-danger la-btn-sm" id="btnBulkDel" style="display:none;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                <span id="btnBulkDelText">Delete Selected</span>
            </button>
        </div>
    </div>

    <?php if (!$media): ?>
    <div class="la-empty" style="padding:60px;">
        <div class="la-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg></div>
        <h3>No media files yet</h3><p>Upload your first image using the upload zone above.</p>
    </div>
    <?php else: ?>

    <form id="bulkForm" method="POST" onsubmit="return confirm('Delete selected files? This cannot be undone.')">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="action" value="bulk_delete">

    <div id="mediaGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;padding:16px;">
    <?php foreach ($media as $m): ?>
    <div class="media-item" data-id="<?php echo $m['id']; ?>"
         style="position:relative;border-radius:8px;overflow:hidden;border:2px solid var(--border);background:var(--bg-input);cursor:pointer;transition:border-color .15s;"
         onclick="selectItem(this)"
         ondblclick="showMediaModal(<?php echo $m['id']; ?>, '<?php echo e(addslashes($m['url'])); ?>', '<?php echo e(addslashes($m['filename'])); ?>', '<?php echo e($m['mime_type']); ?>', <?php echo $m['file_size']; ?>)">
        <input type="checkbox" name="ids[]" value="<?php echo $m['id']; ?>"
               style="position:absolute;top:6px;left:6px;z-index:2;width:16px;height:16px;cursor:pointer;"
               onclick="event.stopPropagation();updateBulkBtn()">
        <div style="aspect-ratio:1;overflow:hidden;background:#0a0f1e;">
            <img src="<?php echo e($m['url']); ?>" alt="<?php echo e($m['filename']); ?>"
                 loading="lazy"
                 style="width:100%;height:100%;object-fit:cover;transition:transform .2s;"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div style="display:none;align-items:center;justify-content:center;width:100%;height:100%;color:var(--text-dim);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
            </div>
        </div>
        <div style="padding:8px;">
            <div style="font-size:11px;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                 title="<?php echo e($m['filename']); ?>"><?php echo e(mb_strimwidth($m['filename'],0,22,'…')); ?></div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;"><?php echo la_filesize((int)$m['file_size']); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    </form>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:16px;border-top:1px solid var(--border);">
        <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($q); ?>&type=<?php echo urlencode($type); ?>" class="la-btn la-btn-outline la-btn-sm">← Prev</a><?php endif; ?>
        <span style="font-size:12px;color:var(--text-muted);">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
        <?php if ($page < $pages): ?><a href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($q); ?>&type=<?php echo urlencode($type); ?>" class="la-btn la-btn-outline la-btn-sm">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Media Detail Modal -->
<div id="mediaModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:var(--bg-card);border-radius:12px;max-width:700px;width:90%;max-height:90vh;overflow-y:auto;position:relative;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);">
            <h3 style="margin:0;font-size:15px;" id="modalTitle">Image Details</h3>
            <button onclick="closeModal()" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;">×</button>
        </div>
        <div style="padding:20px;display:grid;grid-template-columns:1fr 240px;gap:20px;">
            <div style="text-align:center;background:var(--bg-input);border-radius:8px;overflow:hidden;">
                <img id="modalImg" src="" alt="" style="max-width:100%;max-height:400px;object-fit:contain;display:block;margin:0 auto;">
            </div>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <div><div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">Filename</div><div id="modalFilename" style="font-size:13px;word-break:break-all;"></div></div>
                <div><div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">Type</div><div id="modalMime" style="font-size:13px;"></div></div>
                <div><div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">Size</div><div id="modalSize" style="font-size:13px;"></div></div>
                <div>
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">URL (click to copy)</div>
                    <div id="modalUrl" onclick="copyUrl()" style="font-size:11px;font-family:monospace;background:var(--bg-input);padding:8px;border-radius:6px;word-break:break-all;cursor:pointer;border:1px solid var(--border);" title="Click to copy"></div>
                </div>
                <button onclick="copyUrl()" class="la-btn la-btn-primary la-btn-sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    Copy URL
                </button>
                <form method="POST" onsubmit="return confirm('Permanently delete this file?');" style="margin-top:auto;">
                    <?php echo la_csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="media_id" id="modalDeleteId" value="">
                    <button type="submit" class="la-btn la-btn-danger" style="width:100%;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        Delete File
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ── Upload via Fetch ────────────────────────────────────────────────────
const csrfToken = '<?php echo la_csrf_token(); ?>';

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropArea').style.borderColor = 'var(--border)';
    document.getElementById('dropArea').style.background  = '';
    uploadFiles(e.dataTransfer.files);
}

function uploadFiles(files) {
    [...files].forEach(file => {
        const row = document.createElement('div');
        row.style.cssText = 'background:var(--bg-input);border-radius:8px;padding:12px 14px;display:flex;align-items:center;gap:12px;';
        row.innerHTML = `<span style="font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${file.name}</span><span class="status" style="font-size:12px;color:var(--text-muted);">Uploading…</span>`;
        document.getElementById('uploadList').prepend(row);

        const fd = new FormData();
        fd.append('file', file);
        fd.append('_la_csrf', csrfToken);

        fetch('/api/landing/upload.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    row.querySelector('.status').textContent = 'Uploaded';
                    row.querySelector('.status').style.color = '#10b981';
                    setTimeout(() => { row.remove(); location.reload(); }, 1200);
                } else {
                    row.querySelector('.status').textContent = 'Error: ' + (data.error || 'Failed');
                    row.querySelector('.status').style.color = '#ef4444';
                }
            })
            .catch(() => {
                row.querySelector('.status').textContent = 'Network error';
                row.querySelector('.status').style.color = '#ef4444';
            });
    });
}

// ── Grid selection ──────────────────────────────────────────────────────
function selectItem(el) {
    const cb = el.querySelector('input[type=checkbox]');
    cb.checked = !cb.checked;
    el.style.borderColor = cb.checked ? 'var(--accent)' : 'var(--border)';
    updateBulkBtn();
}
function updateBulkBtn() {
    const checked = document.querySelectorAll('#bulkForm input[type=checkbox]:checked').length;
    document.getElementById('btnBulkDel').style.display = checked > 0 ? 'inline-flex' : 'none';
    const txt = document.getElementById('btnBulkDelText');
    if (txt) txt.textContent = `Delete (${checked})`;
}
let allSelected = false;
function toggleSelectAll() {
    allSelected = !allSelected;
    document.querySelectorAll('#bulkForm input[type=checkbox]').forEach(cb => {
        cb.checked = allSelected;
        cb.closest('.media-item').style.borderColor = allSelected ? 'var(--accent)' : 'var(--border)';
    });
    document.getElementById('btnSelectAll').textContent = allSelected ? 'Deselect All' : 'Select All';
    updateBulkBtn();
}
function bulkDelete() {
    const count = document.querySelectorAll('#bulkForm input[type=checkbox]:checked').length;
    if (!count) return;
    if (!confirm(`Delete ${count} file(s)? This cannot be undone.`)) return;
    document.getElementById('bulkForm').submit();
}

// ── Media detail modal ──────────────────────────────────────────────────
function showMediaModal(id, url, filename, mime, size) {
    document.getElementById('mediaModal').style.display = 'flex';
    document.getElementById('modalImg').src       = url;
    document.getElementById('modalTitle').textContent  = filename;
    document.getElementById('modalFilename').textContent = filename;
    document.getElementById('modalMime').textContent   = mime;
    document.getElementById('modalSize').textContent   = formatBytes(size);
    document.getElementById('modalUrl').textContent    = url;
    document.getElementById('modalDeleteId').value     = id;
}
function closeModal() { document.getElementById('mediaModal').style.display = 'none'; }
document.getElementById('mediaModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
function copyUrl() {
    const url = document.getElementById('modalUrl').textContent;
    navigator.clipboard?.writeText(url).then(() => {
        const el = document.getElementById('modalUrl');
        const prev = el.style.background;
        el.style.background = 'rgba(0,210,255,.2)';
        setTimeout(() => el.style.background = prev, 600);
    });
}
function formatBytes(b) {
    if (b < 1024)    return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

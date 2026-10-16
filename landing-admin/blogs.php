<?php
/**
 * Landing Admin — Blog Posts List
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

la_require_login();

$laTitle = 'Blog Posts';
$laPage  = 'blogs';

/* ── Draft count (for sidebar badge) ─────────────────────────────── */
$stDC = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE status='draft'");
$draftCount = (int)($stDC->fetch_assoc()['n'] ?? 0);

/* ── Handle POST actions ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    la_csrf_verify();

    $action = $_POST['action'] ?? '';

    /* Single or bulk delete */
    if ($action === 'delete') {
        $ids = [];
        if (!empty($_POST['id'])) {
            $ids = [(int)$_POST['id']];
        } elseif (!empty($_POST['ids']) && is_array($_POST['ids'])) {
            foreach ($_POST['ids'] as $rawId) {
                $ids[] = (int)$rawId;
            }
        }
        $ids = array_filter($ids);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types        = str_repeat('i', count($ids));
            $st = $conn->prepare("DELETE FROM lp_blogs WHERE id IN ($placeholders)");
            if ($st) {
                $st->bind_param($types, ...$ids);
                $st->execute();
                $deleted = $st->affected_rows;
                $st->close();
                la_flash_set('success', "Deleted $deleted post(s) successfully.");
            }
        }
        $qs = http_build_query(array_filter([
            'status' => $_GET['status'] ?? '',
            'q'      => $_GET['q']      ?? '',
            'pg'     => $_GET['pg']     ?? '',
        ]));
        header('Location: /landing-admin/blogs.php' . ($qs ? "?$qs" : ''));
        exit;
    }

    /* Toggle featured */
    if ($action === 'toggle_featured') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $st = $conn->prepare("UPDATE lp_blogs SET featured = NOT featured WHERE id=?");
            if ($st) { $st->bind_param('i', $id); $st->execute(); $st->close(); }
        }
        $qs = http_build_query(array_filter([
            'status' => $_GET['status'] ?? '',
            'q'      => $_GET['q']      ?? '',
            'pg'     => $_GET['pg']     ?? '',
        ]));
        header('Location: /landing-admin/blogs.php' . ($qs ? "?$qs" : ''));
        exit;
    }
}

/* ── Filters ──────────────────────────────────────────────────────── */
$status  = in_array($_GET['status'] ?? '', ['published','draft','featured']) ? $_GET['status'] : 'all';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

/* ── Build WHERE ──────────────────────────────────────────────────── */
$where  = '1';
$params = [];
$types  = '';

if ($status === 'published') {
    $where   .= " AND b.status='published'";
} elseif ($status === 'draft') {
    $where   .= " AND b.status='draft'";
} elseif ($status === 'featured') {
    $where   .= " AND b.featured=1";
}

if ($search !== '') {
    $where   .= " AND b.title LIKE ?";
    $params[] = '%' . $search . '%';
    $types   .= 's';
}

/* ── Count total for pagination ───────────────────────────────────── */
$countSql = "SELECT COUNT(*) AS n FROM lp_blogs b WHERE $where";
$stCount  = $conn->prepare($countSql);
if ($types) $stCount->bind_param($types, ...$params);
$stCount->execute();
$totalRows = (int)($stCount->get_result()->fetch_assoc()['n'] ?? 0);
$stCount->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ── Fetch page of posts ──────────────────────────────────────────── */
$posts = [];
$sql = "
    SELECT b.id, b.title, b.slug, b.status, b.featured, b.author_name,
           b.cover_image, b.views, b.published_at, b.created_at,
           c.name AS cat_name, c.color AS cat_color
    FROM lp_blogs b
    LEFT JOIN lp_blog_categories c ON c.id = b.category_id
    WHERE $where
    ORDER BY b.id DESC
    LIMIT ? OFFSET ?
";
$allParams  = array_merge($params, [$perPage, $offset]);
$allTypes   = $types . 'ii';
$stPosts = $conn->prepare($sql);
$stPosts->bind_param($allTypes, ...$allParams);
$stPosts->execute();
$res = $stPosts->get_result();
while ($row = $res->fetch_assoc()) $posts[] = $row;
$stPosts->close();

/* ── Tab counts ───────────────────────────────────────────────────── */
$tabCounts = [];
foreach (['all' => '1', 'published' => "status='published'", 'draft' => "status='draft'", 'featured' => 'featured=1'] as $tab => $cond) {
    $r = $conn->query("SELECT COUNT(*) AS n FROM lp_blogs WHERE $cond");
    $tabCounts[$tab] = (int)($r->fetch_assoc()['n'] ?? 0);
}

/* ── Build query string helpers ───────────────────────────────────── */
function blogs_qs(array $overrides = []): string {
    global $status, $search, $page;
    $base = ['status' => $status, 'q' => $search, 'pg' => $page];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== 'all' && $v !== 1);
    return $merged ? '?' . http_build_query($merged) : '';
}

include __DIR__ . '/includes/header.php';
?>

<?php echo la_flash_html(); ?>

<!-- Page header -->
<div class="la-page-hd">
    <div>
        <h1 style="font-size:20px;font-weight:600;margin:0 0 4px;">Blog Posts</h1>
        <div class="la-breadcrumb">
            <a href="/landing-admin/">Dashboard</a>
            <span>/</span>
            <span>Blog Posts</span>
        </div>
    </div>
    <a href="/landing-admin/blog-edit.php" class="la-btn la-btn-primary">+ New Post</a>
</div>

<!-- Filter tabs + Search bar -->
<div class="la-card" style="padding:0;">
    <!-- Tabs row -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid var(--border);">
        <div style="display:flex;gap:0;">
            <?php
            $tabs = ['all' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'featured' => 'Featured'];
            foreach ($tabs as $tabKey => $tabLabel):
                $isActive = $status === $tabKey;
                $href = '/landing-admin/blogs.php' . blogs_qs(['status' => $tabKey === 'all' ? '' : $tabKey, 'pg' => 1]);
            ?>
            <a href="<?php echo $href; ?>" style="display:inline-flex;align-items:center;gap:6px;padding:14px 16px;font-size:13px;font-weight:<?php echo $isActive ? '600' : '400'; ?>;color:<?php echo $isActive ? 'var(--accent)' : 'var(--text-muted)'; ?>;text-decoration:none;border-bottom:2px solid <?php echo $isActive ? 'var(--accent)' : 'transparent'; ?>;transition:color .15s;">
                <?php echo $tabLabel; ?>
                <span style="background:<?php echo $isActive ? 'var(--accent)' : 'var(--bg-base)'; ?>;color:<?php echo $isActive ? '#fff' : 'var(--text-muted)'; ?>;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:600;border:1px solid var(--border);"><?php echo $tabCounts[$tabKey]; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <!-- Search -->
        <form method="GET" action="/landing-admin/blogs.php" style="display:flex;gap:8px;align-items:center;">
            <?php if ($status !== 'all'): ?>
                <input type="hidden" name="status" value="<?php echo e($status); ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search posts…"
                   class="la-input" style="width:220px;padding:7px 12px;font-size:13px;">
            <button type="submit" class="la-btn la-btn-outline la-btn-sm">Search</button>
            <?php if ($search): ?>
                <a href="/landing-admin/blogs.php<?php echo blogs_qs(['q' => '', 'pg' => 1]); ?>" class="la-btn la-btn-outline la-btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bulk actions + table -->
    <form method="POST" action="/landing-admin/blogs.php" id="bulkForm">
        <?php echo la_csrf_field(); ?>
        <input type="hidden" name="action" value="delete">

        <?php if (!empty($posts)): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);background:#fafbfc;">
            <input type="checkbox" id="selectAll" title="Select all" style="width:15px;height:15px;accent-color:var(--accent);">
            <label for="selectAll" style="font-size:13px;color:var(--text-muted);cursor:pointer;">Select all</label>
            <button type="submit" class="la-btn la-btn-danger la-btn-sm" onclick="return confirm('Delete selected posts? This cannot be undone.');" style="margin-left:8px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                Delete Selected
            </button>
        </div>
        <?php endif; ?>

        <div class="la-table-wrap">
            <?php if (empty($posts)): ?>
                <div class="la-empty">
                    <div class="la-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                    </div>
                    <h3>No posts found</h3>
                    <p><?php echo $search ? 'No results for "' . e($search) . '"' : 'Create your first blog post.'; ?></p>
                    <a href="/landing-admin/blog-edit.php" class="la-btn la-btn-primary" style="margin-top:12px;">Create New Post</a>
                </div>
            <?php else: ?>
            <table class="la-table">
                <thead>
                    <tr>
                        <th style="width:36px;"></th>
                        <th style="width:52px;">Cover</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Author</th>
                        <th>Status</th>
                        <th style="width:36px;" title="Featured"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg></th>
                        <th>Views</th>
                        <th>Date</th>
                        <th style="width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $p): ?>
                    <tr>
                        <!-- Checkbox -->
                        <td>
                            <input type="checkbox" name="ids[]" value="<?php echo $p['id']; ?>"
                                   class="row-check" style="width:15px;height:15px;accent-color:var(--accent);">
                        </td>
                        <!-- Cover -->
                        <td>
                            <?php if ($p['cover_image']): ?>
                                <img src="<?php echo e($p['cover_image']); ?>" alt=""
                                     style="width:44px;height:44px;object-fit:cover;border-radius:6px;display:block;"
                                     onerror="this.style.display='none'">
                            <?php else: ?>
                                <div style="width:44px;height:44px;border-radius:6px;background:var(--bg-base);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--text-dim);">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                                </div>
                            <?php endif; ?>
                        </td>
                        <!-- Title -->
                        <td>
                            <a href="/landing-admin/blog-edit.php?id=<?php echo $p['id']; ?>"
                               style="font-weight:500;color:var(--text-primary);text-decoration:none;display:block;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                               title="<?php echo e($p['title']); ?>">
                                <?php echo e($p['title']); ?>
                            </a>
                            <div style="font-size:11px;color:var(--text-muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                /blogs/<?php echo e($p['slug']); ?>
                            </div>
                        </td>
                        <!-- Category -->
                        <td>
                            <?php if ($p['cat_name']): ?>
                                <span class="la-badge" style="background:<?php echo e($p['cat_color'] ?? '#00d2ff'); ?>22;color:<?php echo e($p['cat_color'] ?? '#00d2ff'); ?>;border-color:<?php echo e($p['cat_color'] ?? '#00d2ff'); ?>44;white-space:nowrap;">
                                    <?php echo e($p['cat_name']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <!-- Author -->
                        <td style="font-size:12px;color:var(--text-secondary);white-space:nowrap;"><?php echo e($p['author_name']); ?></td>
                        <!-- Status -->
                        <td>
                            <?php if ($p['status'] === 'published'): ?>
                                <span class="la-badge la-badge-published">Published</span>
                            <?php else: ?>
                                <span class="la-badge la-badge-draft">Draft</span>
                            <?php endif; ?>
                        </td>
                        <!-- Featured toggle -->
                        <td style="text-align:center;">
                            <form method="POST" style="display:inline;">
                                <?php echo la_csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_featured">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <button type="submit" style="background:none;border:none;cursor:pointer;display:flex;align-items:center;opacity:<?php echo $p['featured'] ? '1' : '.25'; ?>;color:#f59e0b;" title="<?php echo $p['featured'] ? 'Unfeature' : 'Mark Featured'; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="<?php echo $p['featured'] ? '#f59e0b' : 'none'; ?>" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </button>
                            </form>
                        </td>
                        <!-- Views -->
                        <td style="font-size:12px;color:var(--text-muted);"><?php echo number_format((int)$p['views']); ?></td>
                        <!-- Date -->
                        <td style="font-size:11px;color:var(--text-muted);white-space:nowrap;">
                            <?php
                            $dt = $p['published_at'] ?? $p['created_at'];
                            echo $dt ? la_time_ago($dt) : '—';
                            ?>
                        </td>
                        <!-- Actions -->
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <a href="/landing-admin/blog-edit.php?id=<?php echo $p['id']; ?>" class="la-btn la-btn-outline la-btn-sm">Edit</a>
                                <a href="/blogs.php?slug=<?php echo urlencode($p['slug']); ?>" target="_blank" class="la-btn la-btn-outline la-btn-sm" title="View on site">↗</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this post permanently?');">
                                    <?php echo la_csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="la-btn la-btn-danger la-btn-sm la-btn-icon" title="Delete">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </form>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid var(--border);">
        <div style="font-size:13px;color:var(--text-muted);">
            Showing <?php echo min($offset + 1, $totalRows); ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?> posts
        </div>
        <div style="display:flex;gap:4px;">
            <?php if ($page > 1): ?>
                <a href="/landing-admin/blogs.php<?php echo blogs_qs(['pg' => $page - 1]); ?>" class="la-btn la-btn-outline la-btn-sm">← Prev</a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="/landing-admin/blogs.php<?php echo blogs_qs(['pg' => $i]); ?>"
                   class="la-btn la-btn-sm <?php echo $i === $page ? 'la-btn-primary' : 'la-btn-outline'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="/landing-admin/blogs.php<?php echo blogs_qs(['pg' => $page + 1]); ?>" class="la-btn la-btn-outline la-btn-sm">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.la-card -->

<script>
/* Select all checkbox */
document.getElementById('selectAll')?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

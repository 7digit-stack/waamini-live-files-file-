<?php
/**
 * Landing API — Blog Posts
 * GET /api/landing/blogs.php
 * Params: page, limit, category (slug), featured (1), q (search), status
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=120');

$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int)($_GET['limit'] ?? 9)));
$offset   = ($page - 1) * $limit;
$catSlug  = trim((string)($_GET['category'] ?? ''));
$featured = isset($_GET['featured']) ? (int)$_GET['featured'] : null;
$q        = trim((string)($_GET['q'] ?? ''));
$status   = in_array($_GET['status'] ?? '', ['draft','published']) ? $_GET['status'] : 'published';

// Build WHERE
$where   = ['b.status = ?'];
$types   = 's';
$params  = [$status];

if ($catSlug) {
    $where[] = 'c.slug = ?';
    $types  .= 's';
    $params[] = $catSlug;
}
if ($featured !== null) {
    $where[] = 'b.featured = ?';
    $types  .= 'i';
    $params[] = $featured;
}
if ($q) {
    $where[] = '(b.title LIKE ? OR b.excerpt LIKE ?)';
    $types  .= 'ss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = implode(' AND ', $where);

// Count
$countSQL = "SELECT COUNT(*) AS total FROM lp_blogs b
             LEFT JOIN lp_blog_categories c ON b.category_id = c.id
             WHERE $whereSQL";
$cStmt = $conn->prepare($countSQL);
$total = 0;
if ($cStmt) {
    $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $total = (int)$cStmt->get_result()->fetch_assoc()['total'];
    $cStmt->close();
}

// Posts
$sql = "SELECT b.id, b.title, b.slug, b.excerpt, b.cover_image,
               b.author_name, b.read_time, b.featured, b.views,
               b.published_at, b.created_at,
               c.id AS cat_id, c.name AS cat_name, c.slug AS cat_slug, c.color AS cat_color
        FROM lp_blogs b
        LEFT JOIN lp_blog_categories c ON b.category_id = c.id
        WHERE $whereSQL
        ORDER BY b.featured DESC, b.published_at DESC, b.created_at DESC
        LIMIT ? OFFSET ?";

$posts = [];
$pStmt = $conn->prepare($sql);
if ($pStmt) {
    $allTypes  = $types . 'ii';
    $allParams = array_merge($params, [$limit, $offset]);
    $pStmt->bind_param($allTypes, ...$allParams);
    $pStmt->execute();
    $res = $pStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $posts[] = [
            'id'          => (int)$row['id'],
            'title'       => $row['title'],
            'slug'        => $row['slug'],
            'excerpt'     => $row['excerpt'],
            'cover_image' => $row['cover_image'],
            'author_name' => $row['author_name'],
            'read_time'   => $row['read_time'],
            'featured'    => (bool)$row['featured'],
            'views'       => (int)$row['views'],
            'published_at'=> $row['published_at'],
            'created_at'  => $row['created_at'],
            'category'    => $row['cat_id'] ? [
                'id'    => (int)$row['cat_id'],
                'name'  => $row['cat_name'],
                'slug'  => $row['cat_slug'],
                'color' => $row['cat_color'],
            ] : null,
        ];
    }
    $pStmt->close();
}

echo json_encode([
    'total'  => $total,
    'page'   => $page,
    'limit'  => $limit,
    'pages'  => (int)ceil($total / $limit),
    'posts'  => $posts,
], JSON_UNESCAPED_UNICODE);

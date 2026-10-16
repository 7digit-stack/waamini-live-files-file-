<?php
/**
 * Landing API — Single Blog Post
 * GET /api/landing/blog.php?slug=xxx  OR  ?id=N
 */
define('WAAMINI_LOADED', true);
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

$slug = trim((string)($_GET['slug'] ?? ''));
$id   = (int)($_GET['id'] ?? 0);

if (!$slug && !$id) {
    http_response_code(400);
    echo json_encode(['error' => 'slug or id required']);
    exit;
}

$col  = $slug ? 'b.slug' : 'b.id';
$val  = $slug ?: $id;
$type = $slug ? 's' : 'i';

$stmt = $conn->prepare(
    "SELECT b.*, c.name AS cat_name, c.slug AS cat_slug, c.color AS cat_color
     FROM lp_blogs b
     LEFT JOIN lp_blog_categories c ON b.category_id = c.id
     WHERE $col = ? AND b.status = 'published'
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

$stmt->bind_param($type, $val);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$post) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

// Increment views
$conn->query("UPDATE lp_blogs SET views=views+1 WHERE id=".(int)$post['id']);

echo json_encode([
    'id'           => (int)$post['id'],
    'title'        => $post['title'],
    'slug'         => $post['slug'],
    'excerpt'      => $post['excerpt'],
    'body'         => $post['body'],
    'cover_image'  => $post['cover_image'],
    'author_name'  => $post['author_name'],
    'author_avatar'=> $post['author_avatar'],
    'read_time'    => $post['read_time'],
    'featured'     => (bool)$post['featured'],
    'views'        => (int)$post['views'] + 1,
    'meta_title'   => $post['meta_title'],
    'meta_desc'    => $post['meta_desc'],
    'published_at' => $post['published_at'],
    'category'     => $post['category_id'] ? [
        'name'  => $post['cat_name'],
        'slug'  => $post['cat_slug'],
        'color' => $post['cat_color'],
    ] : null,
], JSON_UNESCAPED_UNICODE);

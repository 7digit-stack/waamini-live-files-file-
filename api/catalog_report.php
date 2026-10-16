<?php
/**
 * WAAMINI API - Catalog Report Data
 * GET /waamini/api/catalog_report.php?from=YYYY-MM-DD&to=YYYY-MM-DD&type=summary|chart|products
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Login required.']);
    exit();
}

$companyId = intval($_SESSION['company_id'] ?? 0);
$fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'summary';

// Ensure table exists
try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS catalog_interactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT DEFAULT NULL,
            customer_phone VARCHAR(30) NOT NULL,
            product_id INT DEFAULT NULL,
            product_name VARCHAR(255) NOT NULL,
            interaction_type ENUM('pay_now','enquire','buy_now','more_products','catalog_view') NOT NULL,
            auto_reply_sent TINYINT(1) DEFAULT 0,
            auto_reply_message TEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cat_int_company (company_id, interaction_type),
            INDEX idx_cat_int_product (product_id),
            INDEX idx_cat_int_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) { /* exists */ }

if ($type === 'summary') {
    $stats = ['total' => 0, 'pay_now' => 0, 'enquire' => 0, 'catalog_view' => 0, 'auto_replies' => 0];
    $st = $conn->prepare("SELECT interaction_type, COUNT(*) as cnt, SUM(auto_reply_sent) as ar_sent FROM catalog_interactions WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY interaction_type");
    if ($st) {
        $st->bind_param('iss', $companyId, $fromDate, $toDate);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $t = $row['interaction_type'];
            $stats[$t] = (int)$row['cnt'];
            $stats['total'] += (int)$row['cnt'];
            $stats['auto_replies'] += (int)($row['ar_sent'] ?? 0);
        }
    }
    echo json_encode(['status' => 'success', 'data' => $stats]);

} elseif ($type === 'chart') {
    $chart = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $chart[$d] = ['date' => $d, 'pay_now' => 0, 'enquire' => 0, 'catalog_view' => 0];
    }
    $st = $conn->prepare("SELECT DATE(created_at) as day, interaction_type, COUNT(*) as cnt FROM catalog_interactions WHERE company_id=? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY day, interaction_type");
    if ($st) {
        $st->bind_param('i', $companyId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $d = $row['day'];
            $t = $row['interaction_type'];
            if (isset($chart[$d]) && isset($chart[$d][$t])) {
                $chart[$d][$t] = (int)$row['cnt'];
            }
        }
    }
    echo json_encode(['status' => 'success', 'data' => array_values($chart)]);

} elseif ($type === 'products') {
    $products = [];
    $st = $conn->prepare("SELECT product_name, product_id, interaction_type, COUNT(*) as cnt FROM catalog_interactions WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ? AND product_id > 0 GROUP BY product_name, product_id, interaction_type ORDER BY cnt DESC LIMIT 50");
    if ($st) {
        $st->bind_param('iss', $companyId, $fromDate, $toDate);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) { $products[] = $row; }
    }
    echo json_encode(['status' => 'success', 'data' => $products]);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid type parameter.']);
}

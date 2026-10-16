<?php
/**
 * WAAMINI API - Product Enquiry Handler
 * Endpoint: POST /waamini/api/product_enquiry.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required.']);
    exit();
}

// CSRF check
csrf_verify();

// Session-based auth
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Login required.']);
    exit();
}

$companyId = intval($_SESSION['company_id'] ?? 0);
$contactId = intval($_SESSION['user_id'] ?? 0);

$productId = intval($_POST['product_id'] ?? 0);
$productName = trim($_POST['product_name'] ?? '');
$customerName = trim($_POST['customer_name'] ?? '');
$customerPhone = trim($_POST['customer_phone'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($productName === '' || $customerPhone === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Product name and phone number are required.']);
    exit();
}

// Sanitize
$productName = mb_substr($productName, 0, 255, 'UTF-8');
$customerName = mb_substr($customerName, 0, 255, 'UTF-8');
$customerPhone = preg_replace('/[^0-9+]/', '', $customerPhone);
$message = mb_substr($message, 0, 1000, 'UTF-8');

// Ensure table exists
try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS product_enquiries (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            contact_id INT DEFAULT NULL,
            customer_phone VARCHAR(30) NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            product_name VARCHAR(255) NOT NULL,
            product_id INT DEFAULT NULL,
            message TEXT DEFAULT NULL,
            status ENUM('new','contacted','converted','closed') DEFAULT 'new',
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_enq_company (company_id, status),
            INDEX idx_enq_phone (customer_phone),
            INDEX idx_enq_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) { /* exists */ }

$stmt = $conn->prepare(
    "INSERT INTO product_enquiries (company_id, contact_id, customer_phone, customer_name, product_name, product_id, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    exit();
}

$stmt->bind_param('iisssis', $companyId, $contactId, $customerPhone, $customerName, $productName, $productId, $message);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Enquiry submitted successfully.']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save enquiry.']);
}

<?php
include '../includes/client_auth_check.php';
require_once __DIR__ . '/../includes/whatsapp_catalog_checkout.php';

$pageTitle = 'Product Catalog';
$currentPage = 'catalog';

$success = '';
$error = '';

// Ensure buy_url column exists (run once, check first)
try {
    $chk = $conn->query("SHOW COLUMNS FROM products LIKE 'buy_url'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE products ADD COLUMN buy_url VARCHAR(500) DEFAULT NULL AFTER image_url");
    }
} catch (\Throwable $e) { /* exists */ }

// Ensure product_enquiries table exists
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

// Handle Enquiry Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_enquiry_status'])) {
    csrf_verify();
    $eid = intval($_POST['enquiry_id'] ?? 0);
    $newStatus = sanitize($_POST['new_status'] ?? '');
    $allowedStatuses = ['new', 'contacted', 'converted', 'closed'];
    if ($eid > 0 && in_array($newStatus, $allowedStatuses, true)) {
        $upd = $conn->prepare("UPDATE product_enquiries SET status=?, updated_at=NOW() WHERE id=? AND company_id=?");
        if ($upd) {
            $upd->bind_param("sii", $newStatus, $eid, $companyId);
            $upd->execute();
        }
    }
    header("Location: catalog.php?tab=enquiries&msg=status_updated");
    exit();
}

// Handle Delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    csrf_verify();
    $pid = intval($_POST['product_id'] ?? 0);
    if ($pid > 0) {
        $delStmt = $conn->prepare("DELETE FROM products WHERE id = ? AND company_id = ?");
        if ($delStmt) {
            $delStmt->bind_param("ii", $pid, $companyId);
            $delStmt->execute();
        }
    }
    header("Location: catalog.php?msg=deleted");
    exit();
}

// Handle Add/Edit
if (isset($_POST['save_product'])) {
    csrf_verify();
    $name = sanitize($_POST['name']);
    $desc = sanitize($_POST['description']);
    $price = floatval($_POST['price']);
    $buyUrl = sanitize($_POST['buy_url'] ?? '');
    $pid = intval($_POST['id'] ?? 0);
    $img = sanitize($_POST['existing_image'] ?? '');

    // Handle File Upload
    $imgWarning = '';
    $upErr = $_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
        $imgWarning = 'The image was not saved: it is larger than the ' . ini_get('upload_max_filesize') . 'B upload limit. Choose a smaller photo.';
    } elseif ($upErr !== UPLOAD_ERR_OK && $upErr !== UPLOAD_ERR_NO_FILE) {
        $imgWarning = 'The image was not saved because the upload failed (code ' . (int)$upErr . '). Please try again.';
    } elseif ($upErr === UPLOAD_ERR_OK) {
        $targetDir = "../storage/products/";
        if (!is_dir($targetDir))
            mkdir($targetDir, 0777, true);

        $fileExtension = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!in_array($fileExtension, $allowedExtensions) || @getimagesize($_FILES['product_image']['tmp_name']) === false) {
            $imgWarning = 'The image was not saved: use a JPG, PNG, WebP or GIF photo.';
        } else {
            $fileName = $companyId . "_" . time() . "." . $fileExtension;
            $targetPath = $targetDir . $fileName;

            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $targetPath)) {
                $img = "/storage/products/" . $fileName;
            } else {
                $imgWarning = 'The image was not saved: the server could not store the file.';
            }
        }
    }

    if ($pid > 0) {
        $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, image_url=?, buy_url=? WHERE id=? AND company_id=?");
        $stmt->bind_param("ssdssii", $name, $desc, $price, $img, $buyUrl, $pid, $companyId);
    }
    else {
        $stmt = $conn->prepare("INSERT INTO products (company_id, name, description, price, image_url, buy_url) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdss", $companyId, $name, $desc, $price, $img, $buyUrl);
    }

    if ($stmt->execute()) {
        $success = "Product saved successfully!";
        if ($imgWarning !== '') {
            $error = $imgWarning;
        }
    }
    else {
        $error = "Failed to save product.";
    }
}

// Product image as a URL for this install (handles older "/waamini/storage/..." paths)
$productImgSrc = static function (?string $stored): string {
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if (preg_match('#^https?://#i', $stored)) return $stored;
    $rel = waamini_product_image_rel($stored);
    if ($rel === '' || !is_file(__DIR__ . '/../' . $rel)) return '';
    return app_url($rel);
};

// Fetch products
$st2 = $conn->prepare("SELECT * FROM products WHERE company_id = ? ORDER BY created_at DESC");
$st2->bind_param("i", $companyId);
$st2->execute();
$products = $st2->get_result();

// Fetch enquiries
$enqStmt = $conn->prepare("SELECT * FROM product_enquiries WHERE company_id = ? ORDER BY created_at DESC LIMIT 100");
$enqStmt->bind_param("i", $companyId);
$enqStmt->execute();
$enquiries = $enqStmt->get_result();

// Enquiry counts by status
$enqCounts = ['new' => 0, 'contacted' => 0, 'converted' => 0, 'closed' => 0];
$countStmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM product_enquiries WHERE company_id = ? GROUP BY status");
if ($countStmt) {
    $countStmt->bind_param("i", $companyId);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    while ($row = $countRes->fetch_assoc()) {
        $enqCounts[$row['status']] = (int)$row['cnt'];
    }
}
$totalEnquiries = array_sum($enqCounts);

$activeTab = $_GET['tab'] ?? 'products';

include '../includes/client_header.php';
?>

<div class="page-header">
    <div style="display:flex; align-items:center; gap:14px;">
        <a href="order_dashboard.php" class="btn btn-sm" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;" title="Back to Order Analytics"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg> Back</a>
        <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Product Catalog</h2>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="catalog_reports.php" class="btn btn-sm btn-edit" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> Reports</a>
        <?php if ($activeTab === 'products'): ?>
        <button onclick="openProductModal()" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add Product</button>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Product deleted!</div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'status_updated'): ?><div class="alert alert-success">Enquiry status updated!</div><?php endif; ?>

<!-- Tabs -->
<div class="ec-tabs">
    <a href="catalog.php?tab=products" class="<?php echo $activeTab === 'products' ? 'on' : ''; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg> Products (<?php echo $products->num_rows; ?>)</a>
    <a href="catalog.php?tab=enquiries" class="<?php echo $activeTab === 'enquiries' ? 'on' : ''; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Enquiries (<?php echo $totalEnquiries; ?>)
        <?php if ($enqCounts['new'] > 0): ?><span class="ec-dot"><?php echo $enqCounts['new']; ?></span><?php endif; ?>
    </a>
</div>

<?php if ($activeTab === 'products'): ?>

<!-- PRODUCTS GRID -->
<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
    <?php if ($products->num_rows > 0):
    while ($p = $products->fetch_assoc()): ?>
        <div class="form-card" style="padding:0; overflow:hidden; display:flex; flex-direction:column; border:1px solid var(--border);">
            <?php $pImg = $productImgSrc($p['image_url']); $p['image_src'] = $pImg; ?>
            <?php if ($pImg !== ''): ?>
                <div style="height:160px; background: url('<?php echo htmlspecialchars($pImg, ENT_QUOTES); ?>') center/cover no-repeat;"></div>
            <?php else: ?>
                <div style="height:160px; background:var(--bg-input); display:flex; align-items:center; justify-content:center; font-size:40px; color:#128C7E;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/></svg></div>
            <?php endif; ?>
            
            <div style="padding:16px; flex:1;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                    <h4 style="margin:0; color:var(--text-primary);"><?php echo htmlspecialchars($p['name']); ?></h4>
                    <span style="font-weight:700; color:var(--accent); font-size:14px;">₹<?php echo number_format($p['price'], 2); ?></span>
                </div>
                <p style="font-size:12px; color:var(--text-secondary); line-height:1.4; margin-bottom:12px;">
                    <?php echo htmlspecialchars(substr($p['description'], 0, 100)) . (strlen($p['description']) > 100 ? '...' : ''); ?>
                </p>

                <!-- Buy URL indicator -->
                <?php if (!empty($p['buy_url'])): ?>
                    <div style="margin-bottom:12px;">
                        <a href="<?php echo htmlspecialchars($p['buy_url']); ?>" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg, #10b981, #059669); color:#fff; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; width:100%; justify-content:center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> Buy Now
                        </a>
                    </div>
                <?php endif; ?>

                <div style="display:flex; gap:8px;">
                    <!-- Buy / Enquiry button -->
                    <button onclick='openEnquiryModal(<?php echo htmlspecialchars(json_encode([
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'price' => $p['price'],
                        'buy_url' => $p['buy_url'] ?? ''
                    ]), ENT_QUOTES); ?>)' class="btn btn-sm" style="flex:1; background:linear-gradient(135deg, #8b5cf6, #7c3aed); color:#fff; border:none; cursor:pointer;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> Enquire
                    </button>
                    <button onclick="editProduct(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>)" class="btn btn-sm btn-edit" style="flex:1;">Edit</button>
                    <form method="POST" action="catalog.php" style="margin:0;" onsubmit="return confirm('Delete this product?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="delete_product" value="1">
                        <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" style="padding:8px 12px;" aria-label="Delete product"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg></button>
                    </form>
                </div>
            </div>
        </div>
    <?php endwhile;
    else: ?>
        <div style="grid-column: 1 / -1; text-align:center; padding:60px; color:var(--text-muted);">
            <div style="font-size:48px; margin-bottom:20px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></div>
            <h3>Your catalog is empty</h3>
            <p>Add products to showcase them to your customers in chat.</p>
        </div>
    <?php endif; ?>
</div>

<?php else: ?>

<!-- ENQUIRIES TAB -->
<div style="display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
    <div style="padding:16px 18px; background:#fff; border-radius:16px; flex:1; min-width:130px; text-align:center; box-shadow:0 12px 30px -22px rgba(15,38,34,.45);">
        <div style="font-size:28px; font-weight:800; letter-spacing:-.03em; color:var(--accent);"><?php echo $totalEnquiries; ?></div>
        <div style="font-size:12.5px; font-weight:700; color:var(--text-muted);">Total</div>
    </div>
    <div style="padding:16px 18px; background:#fff; border-radius:16px; flex:1; min-width:130px; text-align:center; box-shadow:0 12px 30px -22px rgba(15,38,34,.45);">
        <div style="font-size:28px; font-weight:800; letter-spacing:-.03em; color:#f59e0b;"><?php echo $enqCounts['new']; ?></div>
        <div style="font-size:12.5px; font-weight:700; color:var(--text-muted);">New</div>
    </div>
    <div style="padding:16px 18px; background:#fff; border-radius:16px; flex:1; min-width:130px; text-align:center; box-shadow:0 12px 30px -22px rgba(15,38,34,.45);">
        <div style="font-size:28px; font-weight:800; letter-spacing:-.03em; color:#3b82f6;"><?php echo $enqCounts['contacted']; ?></div>
        <div style="font-size:12.5px; font-weight:700; color:var(--text-muted);">Contacted</div>
    </div>
    <div style="padding:16px 18px; background:#fff; border-radius:16px; flex:1; min-width:130px; text-align:center; box-shadow:0 12px 30px -22px rgba(15,38,34,.45);">
        <div style="font-size:28px; font-weight:800; letter-spacing:-.03em; color:#10b981;"><?php echo $enqCounts['converted']; ?></div>
        <div style="font-size:12.5px; font-weight:700; color:var(--text-muted);">Converted</div>
    </div>
    <div style="padding:16px 18px; background:#fff; border-radius:16px; flex:1; min-width:130px; text-align:center; box-shadow:0 12px 30px -22px rgba(15,38,34,.45);">
        <div style="font-size:28px; font-weight:800; letter-spacing:-.03em; color:#6b7280;"><?php echo $enqCounts['closed']; ?></div>
        <div style="font-size:12.5px; font-weight:700; color:var(--text-muted);">Closed</div>
    </div>
</div>

<?php if ($enquiries->num_rows > 0): ?>
<div style="display:flex; flex-direction:column; gap:12px;">
    <?php while ($enq = $enquiries->fetch_assoc()):
        $statusColors = [
            'new' => ['bg' => '#FEF3DC', 'text' => '#8a5a00', 'border' => '#f59e0b'],
            'contacted' => ['bg' => '#E4EFFD', 'text' => '#1d4f9c', 'border' => '#3b82f6'],
            'converted' => ['bg' => '#E3F7EE', 'text' => '#0B6B5E', 'border' => '#128C7E'],
            'closed' => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'],
        ];
        $st = $enq['status'] ?? 'new';
        $sc = $statusColors[$st] ?? $statusColors['new'];
    ?>
        <div class="form-card" style="padding:18px 20px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                <div style="flex:1; min-width:200px;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                        <strong style="font-size:14px; color:var(--text-primary);"><?php echo htmlspecialchars($enq['product_name']); ?></strong>
                        <span style="font-size:11px; padding:2px 8px; border-radius:12px; background:<?php echo $sc['bg']; ?>; color:<?php echo $sc['text']; ?>; font-weight:600; text-transform:uppercase;"><?php echo $st; ?></span>
                    </div>
                    <div style="font-size:12px; color:var(--text-secondary); margin-bottom:4px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?php echo htmlspecialchars($enq['customer_phone']); ?>
                        <?php if (!empty($enq['customer_name'])): ?>
                            &nbsp;·&nbsp; <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?php echo htmlspecialchars($enq['customer_name']); ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($enq['message'])): ?>
                        <div style="font-size:13px; color:var(--text-primary); background:var(--bg-input); padding:8px 12px; border-radius:6px; margin-top:6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> <?php echo htmlspecialchars($enq['message']); ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:6px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <?php echo date('d M Y, h:i A', strtotime($enq['created_at'])); ?>
                    </div>
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <form method="POST" action="catalog.php?tab=enquiries" style="display:flex; gap:4px; align-items:center;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="update_enquiry_status" value="1">
                        <input type="hidden" name="enquiry_id" value="<?php echo (int)$enq['id']; ?>">
                        <select name="new_status" style="font-size:11px; padding:4px 8px; border:1px solid var(--border); border-radius:6px; background:var(--bg-input); color:var(--text-primary);" onchange="this.form.submit()">
                            <option value="new" <?php echo $st === 'new' ? 'selected' : ''; ?>>New</option>
                            <option value="contacted" <?php echo $st === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                            <option value="converted" <?php echo $st === 'converted' ? 'selected' : ''; ?>>Converted</option>
                            <option value="closed" <?php echo $st === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
</div>
<?php else: ?>
    <div style="text-align:center; padding:60px; color:var(--text-muted);">
        <div style="margin-bottom:14px;color:#128C7E;"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg></div>
        <h3>No enquiries yet</h3>
        <p>Customer enquiries from WhatsApp and the catalog will appear here.</p>
    </div>
<?php endif; ?>

<?php endif; ?>

<!-- Product Add/Edit Modal -->
<div id="productModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000; justify-content:center; align-items:center;">
    <div class="form-card" style="width:100%; max-width:500px; max-height:90vh; overflow-y:auto;">
        <h3 id="modalTitle" style="margin-bottom:24px;">Add Product</h3>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="productId">
            <input type="hidden" name="existing_image" id="existingImage">
            
            <div class="form-group">
                <label>Product Name</label>
                <input type="text" name="name" id="productName" class="form-control" required>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>Price (₹)</label>
                    <input type="number" step="0.01" name="price" id="productPrice" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Product Image</label>
                    <input type="file" name="product_image" id="productImage" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small id="productImageHint" style="display:block; margin-top:6px; font-size:11.5px; color:var(--text-muted);">JPG, PNG or WebP. Large photos are resized automatically.</small>
                </div>
            </div>

            <div id="imagePreviewContainer" style="display:none; margin-bottom:15px; text-align:center;">
                <div style="font-size:11px; color:var(--text-muted); margin-bottom:5px;">Current Image:</div>
                <img id="imagePreview" src="" style="max-height:80px; border-radius:8px; border:1px solid var(--border);">
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="productDesc" class="form-control" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label>Buy URL <span style="font-weight:400; font-size:11px; color:var(--text-muted);">(external purchase link — shown as "Buy Now" button)</span></label>
                <input type="url" name="buy_url" id="productBuyUrl" class="form-control" placeholder="https://example.com/buy/product-name">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px;">
                <button type="button" onclick="closeProductModal()" class="btn btn-sm btn-edit">Cancel</button>
                <button type="submit" name="save_product" class="btn btn-sm btn-primary">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Enquiry Modal -->
<div id="enquiryModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000; justify-content:center; align-items:center;">
    <div class="form-card" style="width:100%; max-width:480px;">
        <h3 style="margin-bottom:8px;">Product Enquiry</h3>
        <p id="enquiryProductName" style="font-size:14px; color:var(--accent); font-weight:600; margin-bottom:20px;"></p>

        <!-- If buy_url exists, show Buy Now link -->
        <div id="enquiryBuySection" style="display:none; margin-bottom:16px;">
            <a id="enquiryBuyLink" href="#" target="_blank" rel="noopener" style="display:flex; align-items:center; justify-content:center; gap:8px; background:linear-gradient(135deg, #10b981, #059669); color:#fff; padding:12px 20px; border-radius:10px; font-size:15px; font-weight:600; text-decoration:none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg> Buy Now — Complete Purchase
            </a>
        </div>

        <div id="enquiryFormSection">
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">Or send an enquiry to the business:</div>
            <form id="enquiryForm" onsubmit="submitEnquiry(event)">
                <input type="hidden" name="product_id" id="enqProductId">
                <input type="hidden" name="product_name" id="enqProductName">
                <?php echo csrf_field(); ?>
                
                <div class="form-group">
                    <label>Your Name</label>
                    <input type="text" name="customer_name" id="enqCustomerName" class="form-control" required placeholder="e.g. Ravi Kumar">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="customer_phone" id="enqCustomerPhone" class="form-control" required placeholder="e.g. 9876543210">
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" id="enqMessage" class="form-control" rows="3" placeholder="I'm interested in this product. Please share more details."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                    <button type="button" onclick="closeEnquiryModal()" class="btn btn-sm btn-edit">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary" style="background:linear-gradient(135deg, #8b5cf6, #7c3aed);">Send Enquiry</button>
                </div>
            </form>
        </div>

        <div id="enquirySuccess" style="display:none; text-align:center; padding:30px;">
            <div style="margin-bottom:12px;color:#128C7E;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0" aria-hidden="true"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg></div>
            <h3 style="margin-bottom:8px;">Enquiry Sent!</h3>
            <p style="font-size:13px; color:var(--text-secondary);">The business will get back to you shortly.</p>
            <button onclick="closeEnquiryModal()" class="btn btn-sm btn-primary" style="margin-top:16px;">Close</button>
        </div>
    </div>
</div>

<script>
/* ─── Product image: shrink large photos in the browser before upload ─── */
(function () {
  var input = document.getElementById('productImage');
  var hint = document.getElementById('productImageHint');
  var MAX_BYTES = <?php
      $lim = trim((string)ini_get('upload_max_filesize'));
      $n = (float)$lim; $u = strtolower(substr($lim, -1));
      $bytes = $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
      echo (int)max(500000, min($bytes * 0.9, 8 * 1048576));
  ?>;
  var MAX_SIDE = 1600;
  if (!input || !window.FileReader || !window.DataTransfer) return;
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;
    if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) { hint.textContent = 'Please choose a JPG, PNG or WebP photo.'; hint.style.color = '#c0372b'; return; }
    var preview = function (src) {
      document.getElementById('imagePreview').src = src;
      document.getElementById('imagePreviewContainer').style.display = 'block';
    };
    if (file.size <= MAX_BYTES || file.type === 'image/gif') {
      preview(URL.createObjectURL(file));
      hint.textContent = 'Ready: ' + Math.round(file.size / 1024) + ' KB'; hint.style.color = '';
      return;
    }
    hint.textContent = 'Resizing photo...'; hint.style.color = '';
    var img = new Image();
    img.onload = function () {
      var scale = Math.min(1, MAX_SIDE / Math.max(img.width, img.height));
      var c = document.createElement('canvas');
      c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); // flatten transparency for JPEG
      ctx.drawImage(img, 0, 0, c.width, c.height);
      var tryQuality = function (q) {
        c.toBlob(function (blob) {
          if (!blob) { hint.textContent = 'Could not resize this photo. Please choose a smaller one.'; hint.style.color = '#c0372b'; return; }
          if (blob.size > MAX_BYTES && q > 0.5) { tryQuality(q - 0.1); return; }
          var out = new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
          var dt = new DataTransfer(); dt.items.add(out); input.files = dt.files;
          preview(URL.createObjectURL(out));
          hint.textContent = 'Resized from ' + (file.size / 1048576).toFixed(1) + ' MB to ' + Math.round(out.size / 1024) + ' KB';
          hint.style.color = blob.size > MAX_BYTES ? '#c0372b' : '';
        }, 'image/jpeg', q);
      };
      tryQuality(0.85);
    };
    img.onerror = function () { hint.textContent = 'This file could not be read as an image.'; hint.style.color = '#c0372b'; };
    img.src = URL.createObjectURL(file);
  });
})();

/* ─── Product Modal ─── */
const productModal = document.getElementById('productModal');
function openProductModal() {
    document.getElementById('modalTitle').innerText = 'Add Product';
    document.getElementById('productId').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('productPrice').value = '';
    document.getElementById('productImage').value = '';
    document.getElementById('productDesc').value = '';
    document.getElementById('productBuyUrl').value = '';
    document.getElementById('existingImage').value = '';
    document.getElementById('imagePreviewContainer').style.display = 'none';
    productModal.style.display = 'flex';
}
function closeProductModal() {
    productModal.style.display = 'none';
}
function editProduct(data) {
    document.getElementById('modalTitle').innerText = 'Edit Product';
    document.getElementById('productId').value = data.id;
    document.getElementById('productName').value = data.name;
    document.getElementById('productPrice').value = data.price;
    document.getElementById('productImage').value = '';
    document.getElementById('productDesc').value = data.description;
    document.getElementById('productBuyUrl').value = data.buy_url || '';
    document.getElementById('existingImage').value = data.image_url;
    if (data.image_url) {
        document.getElementById('imagePreview').src = data.image_src || data.image_url;
        document.getElementById('imagePreviewContainer').style.display = 'block';
    } else {
        document.getElementById('imagePreviewContainer').style.display = 'none';
    }
    productModal.style.display = 'flex';
}
window.onclick = function(event) {
    if (event.target == productModal) closeProductModal();
    if (event.target == enquiryModal) closeEnquiryModal();
}

/* ─── Enquiry Modal ─── */
const enquiryModal = document.getElementById('enquiryModal');
function openEnquiryModal(product) {
    document.getElementById('enquiryProductName').textContent = product.name + ' — ₹' + parseFloat(product.price).toLocaleString('en-IN', {minimumFractionDigits:2});
    document.getElementById('enqProductId').value = product.id;
    document.getElementById('enqProductName').value = product.name;
    document.getElementById('enqCustomerName').value = '';
    document.getElementById('enqCustomerPhone').value = '';
    document.getElementById('enqMessage').value = 'I\'m interested in this product. Please share more details.';

    // Show/hide buy section
    var buySection = document.getElementById('enquiryBuySection');
    var buyLink = document.getElementById('enquiryBuyLink');
    if (product.buy_url && product.buy_url.trim() !== '') {
        buyLink.href = product.buy_url;
        buySection.style.display = 'block';
    } else {
        buySection.style.display = 'none';
    }

    // Reset to form view
    document.getElementById('enquiryFormSection').style.display = 'block';
    document.getElementById('enquirySuccess').style.display = 'none';

    enquiryModal.style.display = 'flex';
}
function closeEnquiryModal() {
    enquiryModal.style.display = 'none';
}
function submitEnquiry(e) {
    e.preventDefault();
    var form = document.getElementById('enquiryForm');
    var data = new FormData(form);

    fetch('/api/product_enquiry.php', {
        method: 'POST',
        body: data
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.status === 'success') {
            document.getElementById('enquiryFormSection').style.display = 'none';
            document.getElementById('enquirySuccess').style.display = 'block';
        } else {
            alert(res.message || 'Failed to send enquiry. Please try again.');
        }
    })
    .catch(function() {
        alert('Network error. Please try again.');
    });
}
</script>

<?php include '../includes/client_footer.php'; ?>

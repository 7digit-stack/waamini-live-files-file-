<?php
require_once __DIR__ . '/../config/bootstrap.php';
include '../db.php';

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
if ($token === '' || preg_match('/^[a-f0-9]{32}$/i', $token) !== 1)
    die("Invalid Token");

// 1. Fetch Quotation + Detailed Contact Info
$stmt = $conn->prepare("SELECT q.*, c.name as contact_name, c.phone as contact_phone, c.email as contact_email, c.address as contact_address, c.client_company 
                       FROM quotations q 
                       JOIN contacts c ON q.contact_id = c.id
                       WHERE q.token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$quote = $stmt->get_result()->fetch_assoc();

if (!$quote)
    die("Quotation not found");

$companyId = $quote['company_id'];

// 2. Fetch Company PDF Settings
$stmtSettings = $conn->prepare("SELECT * FROM company_pdf_settings WHERE company_id = ? LIMIT 1");
$stmtSettings->bind_param("i", $companyId);
$stmtSettings->execute();
$settings = $stmtSettings->get_result()->fetch_assoc();

if (!$settings) {
    $settings = [
        'company_name' => 'Business Solutions',
        'address' => 'Company Address...',
        'phone' => '',
        'email' => '',
        'logo_url' => '',
        'terms' => 'Standard terms apply.'
    ];
}

function waamini_logo_src_for_ui(string $stored): string {
    if ($stored === '') return '';
    if (stripos($stored, 'http://') === 0 || stripos($stored, 'https://') === 0 || strpos($stored, '/') === 0) {
        return $stored;
    }
    return '/' . ltrim($stored, '/');
}
$logoSrc = waamini_logo_src_for_ui((string)($settings['logo_url'] ?? ''));

// 3. Parse Items
$items = [];
if (!empty($quote['items_json'])) {
    $items = json_decode($quote['items_json'], true);
}
if (empty($items)) {
    // Fallback for legacy quotes
    $items = [[
        'name' => $quote['item_name'],
        'price' => $quote['price'],
        'qty' => 1
    ]];
}

$subtotal = 0;
foreach($items as $it) {
    $subtotal += (float)($it['price'] ?? 0) * (int)($it['qty'] ?? 1);
}
$discount = (float)($quote['discount'] ?? 0);
$total = (float)($quote['total'] ?? $subtotal);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation #<?php echo $quote['id']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #3c44b1; --text: #1e293b; --text-muted: #64748b; --border: #e2e8f0; }
        body { background: #f1f5f9; font-family: 'Inter', -apple-system, sans-serif; margin: 0; padding: 40px 20px; color: var(--text); line-height: 1.5; }
        .quote-container { background: #fff; max-width: 850px; margin: 0 auto; padding: 50px; border-radius: 4px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-top: 8px solid var(--primary); position: relative; }
        
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
        .logo-box img { max-height: 75px; max-width: 250px; object-fit: contain; }
        .company-details { text-align: right; }
        .company-details h1 { margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #000; text-transform: uppercase; }
        .company-details p { margin: 2px 0; font-size: 12px; color: var(--text-muted); line-height: 1.4; max-width: 250px; margin-left: auto; }

        .doc-title { margin-bottom: 40px; border-bottom: 2px solid var(--border); padding-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; }
        .doc-title h2 { margin: 0; font-size: 32px; font-weight: 800; color: var(--primary); letter-spacing: -1px; }
        .doc-meta { text-align: right; }
        .doc-meta p { margin: 3px 0; font-size: 14px; font-weight: 700; color: #000; }
        .doc-meta span { color: var(--text-muted); font-weight: 400; margin-right: 12px; }

        .billing-grid { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 40px; margin-bottom: 40px; }
        .bill-to h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); margin: 0 0 12px 0; border-bottom: 1px solid var(--border); padding-bottom: 6px; font-weight: 800; }
        .bill-to h4 { margin: 0 0 4px 0; font-size: 17px; font-weight: 800; color: var(--primary); }
        .bill-to p { margin: 3px 0; font-size: 13px; color: var(--text); line-height: 1.4; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        th { background: #374151; color: #fff; text-align: left; padding: 14px 18px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 18px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: top; }
        .col-qty { text-align: center; width: 60px; font-weight: 600; }
        .col-price { text-align: right; width: 130px; }
        .col-total { text-align: right; width: 130px; font-weight: 700; color: #000; }

        .totals-area { display: flex; justify-content: space-between; align-items: flex-start; }
        .payment-info { flex: 1; padding-right: 50px; }
        .payment-info h4 { font-size: 11px; text-transform: uppercase; color: var(--text-muted); margin: 0 0 8px 0; letter-spacing: 1px; font-weight: 800; }
        .payment-info p { font-size: 12px; color: var(--text-muted); line-height: 1.6; white-space: pre-line; }

        .totals-box { width: 260px; }
        .totals-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 14px; }
        .totals-row span:first-child { color: var(--text-muted); font-weight: 500; }
        .totals-row span:last-child { font-weight: 700; color: #000; }
        .totals-row.grand-total { border-bottom: none; font-size: 20px; font-weight: 800; color: var(--primary); padding-top: 20px; background: rgba(60,68,177,0.03); margin-top: 5px; padding: 15px 10px; border-radius: 8px; }
        .totals-row.grand-total span { color: var(--primary) !important; }

        .footer { margin-top: 80px; border-top: 2px solid var(--border); padding-top: 25px; }
        .footer h4 { font-size: 11px; text-transform: uppercase; color: var(--text-muted); margin: 0 0 10px 0; font-weight: 800; }
        .footer p { font-size: 12px; color: var(--text-muted); margin: 0; white-space: pre-line; line-height: 1.6; }

        .generated-by { margin-top: 50px; text-align: center; font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 3px; font-weight: 600; opacity: 0.6; }

        .actions { max-width: 850px; margin: 30px auto; text-align: center; }
        .btn { display: inline-block; padding: 14px 40px; border-radius: 40px; text-decoration: none; font-weight: 800; cursor: pointer; border: none; font-size: 15px; transition: 0.3s; }
        .btn-print { background: var(--primary); color: #fff; box-shadow: 0 10px 25px rgba(60, 68, 177, 0.3); }
        .btn-print:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(60, 68, 177, 0.4); }

        @media print {
            body { background: #fff; padding: 0; }
            .quote-container { box-shadow: none; padding: 0; border-top: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>

    <div class="quote-container">
        <div class="header">
            <div class="logo-box">
                <?php if ($logoSrc): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo">
                <?php else: ?>
                    <div style="font-size: 24px; font-weight: 900; color: var(--primary);">LOGO</div>
                <?php endif; ?>
            </div>
            <div class="company-details">
                <h1><?php echo htmlspecialchars($settings['company_name']); ?></h1>
                <p><?php echo nl2br(htmlspecialchars($settings['address'])); ?></p>
                <?php if (!empty($settings['phone'])): ?><p>📞 <?php echo htmlspecialchars($settings['phone']); ?></p><?php endif; ?>
                <?php if (!empty($settings['email'])): ?><p>✉️ <?php echo htmlspecialchars($settings['email']); ?></p><?php endif; ?>
                <?php if (!empty($settings['gstin'])): ?><p><strong>GSTIN:</strong> <?php echo htmlspecialchars($settings['gstin']); ?></p><?php endif; ?>
            </div>
        </div>

        <div class="doc-title">
            <h2>QUOTATION</h2>
            <div class="doc-meta">
                <p><span>Quotation#</span> QT-<?php echo str_pad($quote['id'], 5, '0', STR_PAD_LEFT); ?></p>
                <p><span>Date</span> <?php echo date('d M Y', strtotime($quote['created_at'])); ?></p>
            </div>
        </div>

        <div class="billing-grid">
            <div class="bill-to">
                <h3>Bill To</h3>
                <h4><?php echo htmlspecialchars($quote['contact_name']); ?></h4>
                <?php if (!empty($quote['client_company'])): ?><p class="client-org"><?php echo htmlspecialchars($quote['client_company']); ?></p><?php endif; ?>
                <p><?php echo nl2br(htmlspecialchars((string)$quote['contact_address'])); ?></p>
                <p><?php echo htmlspecialchars($quote['contact_phone']); ?></p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th class="col-qty">Qty</th>
                    <th class="col-price">Rate</th>
                    <th class="col-total">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): 
                    $rowTotal = (float)($it['price'] ?? 0) * (int)($it['qty'] ?? 1);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($it['name']); ?></strong>
                            <?php if (!empty($it['desc'])): ?><div style="font-size:12px; color:var(--text-muted); margin-top:4px;"><?php echo htmlspecialchars($it['desc']); ?></div><?php endif; ?>
                        </td>
                        <td class="col-qty"><?php echo (int)($it['qty'] ?? 1); ?></td>
                        <td class="col-price">₹<?php echo number_format((float)($it['price'] ?? 0), 2); ?></td>
                        <td class="col-total">₹<?php echo number_format($rowTotal, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals-area">
            <div class="payment-info">
                <?php if (!empty($settings['bank_details'])): ?>
                    <h4>Bank Transfer Details</h4>
                    <p><?php echo htmlspecialchars($settings['bank_details']); ?></p>
                <?php endif; ?>
            </div>
            <div class="totals-box">
                <div class="totals-row">
                    <span>Subtotal</span>
                    <span>₹<?php echo number_format($subtotal, 2); ?></span>
                </div>
                <?php if ($discount > 0): ?>
                    <div class="totals-row">
                        <span>Discount</span>
                        <span style="color: #e74c3c;">-₹<?php echo number_format($discount, 2); ?></span>
                    </div>
                <?php endif; ?>
                <div class="totals-row grand-total">
                    <span>Total</span>
                    <span>₹<?php echo number_format($total, 2); ?></span>
                </div>
            </div>
        </div>

        <div class="footer">
            <h4>Terms & Conditions</h4>
            <p><?php echo htmlspecialchars($settings['terms']); ?></p>
        </div>

        <div class="generated-by">
            Generated via WAAMINI Web Portal
        </div>
    </div>

    <div class="actions">
        <button onclick="window.print()" class="btn btn-print">🖨️ Print or Download PDF</button>
    </div>

</body>
</html>

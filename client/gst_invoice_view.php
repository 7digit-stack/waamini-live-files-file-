<?php
include '../includes/client_auth_check.php';

function amountToWords($number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $hundred = null;
    $digits_length = strlen($no);
    $i = 0;
    $str = array();
    $words = array(0 => '', 1 => 'One', 2 => 'Two',
        3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six',
        7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve',
        13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty',
        30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty',
        90 => 'Ninety');
    $digits = array('', 'Hundred','Thousand','Lakh', 'Crore');
    while( $i < $digits_length ) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += $divider == 10 ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number].' '. $digits[$counter]. $plural.' '.$hundred:$words[floor($number / 10) * 10].' '.$words[$number % 10]. ' '.$digits[$counter].$plural.' '.$hundred;
        } else $str[] = null;
    }
    $Rupees = implode('', array_reverse($str));
    $paise = ($decimal > 0) ? "and " . ($words[$decimal / 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
    return ($Rupees ? 'INR ' . $Rupees : '') . ' Rupees ' . $paise . ' Only';
}

$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT gi.*, c.name as contact_name, c.phone, c.email as contact_email, c.address as contact_address FROM gst_invoices gi JOIN contacts c ON gi.contact_id=c.id WHERE gi.id=? AND gi.company_id=?");
$stmt->bind_param("ii", $id, $companyId); $stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
if (!$inv) { header("Location: gst_invoices.php"); exit(); }

$stmt_gs = $conn->prepare("SELECT * FROM gst_settings WHERE company_id=?");
$stmt_gs->bind_param("i", $companyId); $stmt_gs->execute();
$gs = $stmt_gs->get_result()->fetch_assoc();

$items = json_decode($inv['items_json'], true) ?: [];
$pageTitle = 'Invoice ' . $inv['invoice_number'];
$currentPage = 'gst_invoices';
include '../includes/client_header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

body { font-family: 'Inter', sans-serif; background: #f4f7f6; color: #333; }
.invoice-container { background: white; max-width: 900px; margin: 40px auto; padding: 50px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-radius: 8px; border: 1px solid #eee; position: relative; }
.btn-row { max-width: 900px; margin: 20px auto; display: flex; gap: 10px; justify-content: flex-end; }

@media print {
    .btn-row, .sidebar, .top-nav, footer, .page-header { display: none !important; }
    body { background: white; margin: 0; }
    .invoice-container { box-shadow: none; border: none; margin: 0; width: 100%; max-width: 100%; padding: 0; }
    @page { margin: 1cm; }
}

.inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
.tax-invoice-label { color: #1a73e8; font-weight: 800; font-size: 14px; text-transform: uppercase; margin-bottom: 8px; }
.business-name { font-size: 24px; font-weight: 800; margin-bottom: 4px; color: #000; }
.meta-text { font-size: 12px; color: #555; line-height: 1.5; }
.logo-container { text-align: right; }
.logo-container img { max-height: 60px; margin-bottom: 5px; }
.original-copy { font-size: 10px; color: #888; font-weight: 700; text-transform: uppercase; }

.details-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-bottom: 30px; border-top: 1px solid #eee; padding-top: 20px; }
.detail-box h4 { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; margin: 0 0 8px 0; }
.detail-box p { font-size: 13px; margin: 0; line-height: 1.5; font-weight: 600; }
.detail-box .val { color: #000; }

.items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.items-table th { background: #f8f9fa; border-bottom: 2px solid #eee; padding: 12px; text-align: left; font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
.items-table td { padding: 12px; border-bottom: 1px solid #eee; font-size: 13px; vertical-align: top; }
.item-name { font-weight: 700; color: #000; }
.item-desc { font-size: 11px; color: #777; margin-top: 4px; }

.totals-section { display: flex; justify-content: flex-end; margin-bottom: 30px; }
.totals-table { width: 300px; }
.totals-table tr td { padding: 5px 0; font-size: 13px; }
.totals-table tr.total-row td { border-top: 2px solid #000; padding-top: 10px; font-size: 18px; font-weight: 800; color: #000; }

.words-row { border-top: 1px solid #eee; border-bottom: 1px solid #eee; padding: 10px 0; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 30px; display: flex; justify-content: space-between; }

.footer-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 20px; }
.qr-box img { width: 100px; height: 100px; border: 1px solid #eee; padding: 5px; }
.bank-details { font-size: 12px; line-height: 1.8; }
.sign-box { text-align: right; font-size: 12px; }
.seal-img { width: 100px; margin-bottom: 5px; opacity: 0.8; }

.terms-box { border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px; }
.terms-box h4 { font-size: 11px; font-weight: 700; margin: 0 0 8px 0; text-transform: uppercase; }
.terms-box p { font-size: 10px; color: #777; margin: 0 0 4px 0; }

[contenteditable="true"]:hover { background: rgba(26, 115, 232, 0.05); cursor: edit; border-radius: 4px; }
[contenteditable="true"]:focus { background: #fff; outline: 2px solid #1a73e8; padding: 2px 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
.editable-hint { font-size: 9px; color: #1a73e8; font-weight: normal; margin-left: 10px; vertical-align: middle; }
</media>
</style>

<div class="btn-row">
    <button onclick="window.print()" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg> Download / Print PDF</button>
    <a href="gst_invoices.php" class="btn btn-edit">← Back</a>
</div>

<div class="invoice-container" id="invoiceArea">
    <!-- Header -->
    <div class="inv-header">
        <div>
            <div class="tax-invoice-label">Tax Invoice</div>
            <div class="business-name"><?php echo htmlspecialchars($gs['business_name'] ?? 'Your Business'); ?></div>
            <div class="meta-text">
                <strong>GSTIN: <?php echo htmlspecialchars($gs['gstin'] ?? ''); ?></strong><br>
                <?php echo htmlspecialchars($gs['address'] ?? ''); ?>, <?php echo htmlspecialchars($gs['city'] ?? ''); ?>, <?php echo htmlspecialchars($gs['state'] ?? ''); ?> - <?php echo htmlspecialchars($gs['pincode'] ?? ''); ?><br>
                Mobile: <?php echo htmlspecialchars($gs['phone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($gs['email'] ?? ''); ?>
            </div>
        </div>
        <div class="logo-container">
            <?php if (!empty($gs['logo_url'])): ?>
                <img src="<?php echo htmlspecialchars($gs['logo_url']); ?>"><br>
            <?php else: ?>
                <div style="font-size:24px;font-weight:900;color:#ddd;">LOGO</div>
            <?php endif; ?>
            <div class="original-copy">Original for Recipient</div>
        </div>
    </div>

    <!-- Details Grid -->
    <div class="details-grid">
        <div class="detail-box">
            <h4>Invoice Details</h4>
            <p>Invoice #: <span class="val"><?php echo htmlspecialchars($inv['invoice_number']); ?></span></p>
            <p>Invoice Date: <span class="val"><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></span></p>
            <?php if ($inv['due_date']): ?><p>Due Date: <span class="val"><?php echo date('d M Y', strtotime($inv['due_date'])); ?></span></p><?php endif; ?>
            <p>Place of Supply: <span class="val"><?php echo htmlspecialchars($gs['state'] ?? 'N/A'); ?></span></p>
        </div>
        <div class="detail-box">
            <h4>Billing Address</h4>
            <p class="val"><?php echo htmlspecialchars($inv['contact_name']); ?></p>
            <p><?php echo nl2br(htmlspecialchars($inv['contact_address'] ?? '')); ?></p>
            <p>Ph: <?php echo htmlspecialchars($inv['phone']); ?></p>
        </div>
        <div class="detail-box">
            <h4>Shipping Address</h4>
            <p class="val"><?php echo htmlspecialchars($inv['contact_name']); ?></p>
            <p><?php echo nl2br(htmlspecialchars($inv['contact_address'] ?? '')); ?></p>
        </div>
    </div>

    <!-- Subject -->
    <div style="margin-bottom: 20px;">
        <h4 style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; margin:0 0 5px 0;">Subject:</h4>
        <div id="editableSubject" contenteditable="true" onblur="saveMeta('subject')" style="font-size:14px; font-weight:700; color:#000;">
            <?php echo htmlspecialchars($inv['subject'] ?? 'Invoice for Services'); ?>
        </div>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Item / Description</th>
                <th style="text-align:right;">Rate</th>
                <th style="text-align:center;">Qty</th>
                <th style="text-align:right;">Taxable Value</th>
                <th style="text-align:right;">Tax Amount</th>
                <th style="text-align:right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td>
                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="item-desc">HSN: <?php echo htmlspecialchars($item['hsn'] ?? 'N/A'); ?></div>
                </td>
                <td style="text-align:right;"><?php echo number_format($item['rate'], 2); ?></td>
                <td style="text-align:center;"><?php echo $item['qty']; ?></td>
                <td style="text-align:right;"><?php echo number_format($item['amount'], 2); ?></td>
                <td style="text-align:right;">
                    <?php 
                    $taxPct = $item['gst_rate'] ?? ($gs['cgst_pct'] + $gs['sgst_pct']);
                    $taxAmt = ($item['amount'] * $taxPct) / 100;
                    echo number_format($taxAmt, 2) . " (" . $taxPct . "%)";
                    ?>
                </td>
                <td style="text-align:right; font-weight:700;"><?php echo number_format($item['amount'] + $taxAmt, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-section">
        <table class="totals-table">
            <tr>
                <td style="color:#666;">Taxable Amount</td>
                <td style="text-align:right; font-weight:600;">₹<?php echo number_format($inv['subtotal'], 2); ?></td>
            </tr>
            <?php if ($inv['cgst_amount'] > 0): ?>
            <tr>
                <td style="color:#666;">CGST (<?php echo $gs['cgst_pct']; ?>%)</td>
                <td style="text-align:right; font-weight:600;">₹<?php echo number_format($inv['cgst_amount'], 2); ?></td>
            </tr>
            <tr>
                <td style="color:#666;">SGST (<?php echo $gs['sgst_pct']; ?>%)</td>
                <td style="text-align:right; font-weight:600;">₹<?php echo number_format($inv['sgst_amount'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($inv['igst_amount'] > 0): ?>
            <tr>
                <td style="color:#666;">IGST (<?php echo $gs['igst_pct']; ?>%)</td>
                <td style="text-align:right; font-weight:600;">₹<?php echo number_format($inv['igst_amount'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="total-row">
                <td>Total</td>
                <td style="text-align:right;">₹<?php echo number_format($inv['total_amount'], 2); ?></td>
            </tr>
        </table>
    </div>

    <!-- Words Row -->
    <div class="words-row">
        <div>Total amount (in words): <strong><?php echo amountToWords($inv['total_amount']); ?></strong></div>
        <div style="text-align:right;">Amount Payable: <strong>₹<?php echo number_format($inv['total_amount'], 2); ?></strong></div>
    </div>

    <!-- Footer Grid -->
    <div class="footer-grid">
        <div class="qr-box">
            <h4 style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; margin:0 0 10px 0;">Pay using UPI:</h4>
            <?php if (!empty($gs['upi_id'])): ?>
                <img src="https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=<?php echo urlencode("upi://pay?pa=".$gs['upi_id']."&pn=".urlencode($gs['business_name'])."&am=".$inv['total_amount']."&cu=INR"); ?>&choe=UTF-8">
                <div style="font-size:10px; color:#888; margin-top:5px;">UPI ID: <?php echo htmlspecialchars($gs['upi_id']); ?></div>
            <?php else: ?>
                <div style="font-size:10px; color:#ccc;">UPI ID not set</div>
            <?php endif; ?>
        </div>
        <div class="bank-details">
            <h4 style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; margin:0 0 10px 0;">Bank Details:</h4>
            <div id="editableBank" contenteditable="true" onblur="saveBank()">
                <?php if (!empty($inv['bank_details_custom'])): ?>
                    <?php echo nl2br(htmlspecialchars($inv['bank_details_custom'])); ?>
                <?php else: ?>
                    <strong>Bank:</strong> <?php echo htmlspecialchars($gs['bank_name'] ?? 'N/A'); ?><br>
                    <strong>Account #:</strong> <?php echo htmlspecialchars($gs['account_number'] ?? 'N/A'); ?><br>
                    <strong>IFSC:</strong> <?php echo htmlspecialchars($gs['ifsc_code'] ?? 'N/A'); ?><br>
                    <strong>Branch:</strong> <?php echo htmlspecialchars($gs['city'] ?? 'N/A'); ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="sign-box">
            <div style="font-size:11px; color:#888; margin-bottom:40px;">For <?php echo htmlspecialchars($gs['business_name'] ?? 'Your Business'); ?></div>
            <?php if (!empty($gs['logo_url'])): ?>
                <img src="<?php echo htmlspecialchars($gs['logo_url']); ?>" class="seal-img" style="filter: grayscale(1) sepia(1) hue-rotate(200deg) saturate(2);">
            <?php endif; ?>
            <div style="font-size:11px; font-weight:800; border-top:1px solid #eee; display:inline-block; padding-top:5px;">Authorized Signatory</div>
        </div>
    </div>

    <!-- Notes & Terms -->
    <div class="terms-box">
        <h4>Notes:</h4>
        <div id="editableNotes" contenteditable="true" onblur="saveMeta('notes')" style="font-size:11px; color:#555; margin-bottom:15px;">
            <?php echo !empty($inv['notes']) ? htmlspecialchars($inv['notes']) : 'Thank you for your business!'; ?>
        </div>

        <h4>Terms and Conditions:</h4>
        <div id="editableTerms" contenteditable="true" onblur="saveMeta('terms')" style="font-size:10px; color:#888; line-height:1.6;">
            1. Goods once sold cannot be taken back or exchanged.<br>
            2. We are not the manufacturers, company will stand for warranty as per their terms and conditions.<br>
            3. Interest @24% p.a. will be charged for uncleared bills beyond 15 days.<br>
            4. Subject to local Jurisdiction.
        </div>
    </div>

    <!-- Bottom Footer -->
    <div style="margin-top:40px; padding-top:10px; border-top:1px solid #eee; display:flex; justify-content:space-between; align-items:center; font-size:10px; color:#aaa;">
        <div>Page 1/1</div>
        <div>This is a digitally signed document.</div>
    </div>
</div>

<script>
function saveMeta(field) {
    let el;
    if (field === 'subject') el = document.getElementById('editableSubject');
    else if (field === 'notes') el = document.getElementById('editableNotes');
    else if (field === 'terms') el = document.getElementById('editableTerms');
    
    if (!el) return;
    const val = el.innerText.trim();
    const data = new FormData();
    data.append('action', 'update_meta');
    data.append('id', '<?php echo $id; ?>');
    data.append(field, val);
    data.append('csrf', '<?php echo csrf_token(); ?>');

    fetch('ajax_update_gst_invoice.php', { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            el.style.background = '#e6fffa';
            setTimeout(() => el.style.background = '', 500);
        } else {
            alert('Failed to save: ' + res.error);
        }
    });
}

function saveBank() {
    const el = document.getElementById('editableBank');
    const text = el.innerText.trim();
    // Simple parser for demonstration - ideally user should edit in settings, 
    // but here we can try to extract or just save the custom blob
    const data = new FormData();
    data.append('action', 'update_meta');
    data.append('id', '<?php echo $id; ?>');
    data.append('bank_details_custom', text);
    data.append('csrf', '<?php echo csrf_token(); ?>');

    fetch('ajax_update_gst_invoice.php', { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            el.style.background = '#e6fffa';
            setTimeout(() => el.style.background = '', 500);
        }
    });
}
</script>

<?php include '../includes/client_footer.php'; ?>


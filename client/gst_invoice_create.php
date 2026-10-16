<?php
include '../includes/client_auth_check.php';

$pageTitle = 'GST Invoice Create';
$currentPage = 'gst_invoices';

$contacts = [];
$st = $conn->prepare("SELECT id, name, phone FROM contacts WHERE company_id=? ORDER BY id DESC LIMIT 200");
if ($st) {
    $st->bind_param("i", $companyId);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $contacts[] = $row;
    }
}

include '../includes/client_header.php';
?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/></svg> Create GST Invoice</h2>
    <a href="gst_invoices.php" class="btn btn-edit">↩ Back to Invoices</a>
</div>

<div class="form-card" style="max-width:none;">
    <form method="post" action="gst_invoices.php">
        <?php echo csrf_field(); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
                <label>Contact</label>
                <?php if (!empty($contacts)): ?>
                    <select name="contact_id" class="form-control">
                        <option value="">Select contact</option>
                        <?php foreach ($contacts as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>">
                                <?php echo htmlspecialchars(($c['name'] ?: 'Contact') . ' (' . ($c['phone'] ?? '') . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="contact" class="form-control" placeholder="Contact name or phone">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Invoice Date</label>
                <input type="date" name="invoice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>

        <div style="margin-top:14px;">
            <h3 style="font-size:14px;margin:0 0 10px 0;">Items</h3>
            <div style="display:grid;grid-template-columns:2fr 0.6fr 0.6fr 0.8fr;gap:10px;">
                <div class="form-group">
                    <label>Item</label>
                    <input type="text" name="item_name[]" class="form-control" placeholder="Item name">
                </div>
                <div class="form-group">
                    <label>Qty</label>
                    <input type="number" name="qty[]" class="form-control" value="1" min="1">
                </div>
                <div class="form-group">
                    <label>Rate</label>
                    <input type="number" name="rate[]" class="form-control" step="0.01" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>GST Rate</label>
                    <select name="gst_rate[]" class="form-control">
                        <option value="5">5%</option>
                        <option value="12">12%</option>
                        <option value="18" selected>18%</option>
                        <option value="28">28%</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:10px;color:var(--text-muted);font-size:12px;">
                Tip: you can add more items after saving.
            </div>
        </div>

        <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">
            <button type="submit" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Invoice</button>
        </div>
    </form>
</div>

<?php include '../includes/client_footer.php'; ?>


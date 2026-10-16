<?php
include '../includes/client_auth_check.php';
$formId = intval($_GET['form_id'] ?? 0);
$stmt_f = $conn->prepare("SELECT * FROM wa_forms WHERE id=? AND company_id=?");
$stmt_f->bind_param("ii",$formId,$companyId); $stmt_f->execute();
$form = $stmt_f->get_result()->fetch_assoc();
if (!$form) { header("Location: form_builder.php"); exit(); }
$pageTitle = 'Form Responses — '.$form['name'];
$currentPage = 'form_builder';
$fields = json_decode($form['fields']??'[]',true);

$stmt_r = $conn->prepare("SELECT fs.*, c.name as contact_name FROM wa_form_submissions fs LEFT JOIN contacts c ON fs.contact_id=c.id WHERE fs.form_id=? ORDER BY fs.id DESC LIMIT 100");
$stmt_r->bind_param("i",$formId); $stmt_r->execute(); $responses = $stmt_r->get_result();

include '../includes/client_header.php';
?>
<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg> <?php echo htmlspecialchars($form['name']); ?> — Responses</h2>
    <a href="form_builder.php" class="btn btn-edit">← Back</a>
</div>
<div class="data-table-wrapper">
<table class="data-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Contact</th>
            <?php foreach ($fields as $field): ?><th><?php echo htmlspecialchars($field['label']); ?></th><?php endforeach; ?>
            <th>Submitted</th>
        </tr>
    </thead>
    <tbody>
    <?php $i=1; while ($r=$responses->fetch_assoc()):
        $data = json_decode($r['data']??'{}',true);
    ?>
    <tr>
        <td><?php echo $i++; ?></td>
        <td><?php echo htmlspecialchars($r['contact_name']??$r['phone']); ?></td>
        <?php foreach ($fields as $field): ?>
        <td style="font-size:12px;"><?php echo htmlspecialchars($data[$field['label']]??'—'); ?></td>
        <?php endforeach; ?>
        <td style="font-size:11px;color:var(--text-muted);"><?php echo date('d M, h:i A',strtotime($r['submitted_at'])); ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>
<?php include '../includes/client_footer.php'; ?>

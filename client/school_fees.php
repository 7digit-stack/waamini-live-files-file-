<?php
include '../includes/client_auth_check.php';

$lI = static function (string $p, int $s = 20): string {
    return '<svg class="lI" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};

if (!has_feature('school_fee_reminder')) {
    http_response_code(403);
    $pageTitle = 'School Fee Manager';
    $currentPage = 'school_fees';
    include '../includes/client_header.php';
    ?>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=2'); ?>">
    <div class="su"><div class="su-card" style="align-items:center;text-align:center;padding:48px 24px;">
        <div class="su-ico" style="width:64px;height:64px;"><?php echo $lI('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 28); ?></div>
        <h2 style="justify-content:center;">School fee reminders are not in your plan</h2>
        <p class="sub" style="margin:0">Upgrade to track fees and remind parents on WhatsApp.</p>
        <a href="subscription.php" class="su-btn">View plans</a>
    </div></div>
    <?php
    include '../includes/client_footer.php';
    exit();
}
$pageTitle = 'School Fee Manager';
$currentPage = 'school_fees';

// Ensure required tables exist (older installs may not have run latest migrations).
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS school_students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            student_name VARCHAR(150) NOT NULL,
            class_name VARCHAR(50) DEFAULT NULL,
            section VARCHAR(10) DEFAULT NULL,
            roll_number VARCHAR(20) DEFAULT NULL,
            parent_name VARCHAR(150) DEFAULT NULL,
            parent_phone VARCHAR(20) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            admission_number VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS school_fees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            student_id INT NOT NULL,
            fee_type VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            due_date DATE NOT NULL,
            paid_date DATE DEFAULT NULL,
            paid_amount DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            reminder_sent INT DEFAULT 0,
            last_reminder_at DATETIME DEFAULT NULL,
            receipt_number VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_student (student_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS school_fee_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL UNIQUE,
            school_name VARCHAR(200) DEFAULT NULL,
            reminder_days_before INT DEFAULT 3,
            reminder_message TEXT DEFAULT NULL,
            overdue_message TEXT DEFAULT NULL,
            receipt_message TEXT DEFAULT NULL,
            auto_remind TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (\Throwable $e) {
    // best-effort
}

require_once __DIR__ . '/../includes/school_fee_engine.php';
sf_ensure_schema($conn);

// Get settings
$settings = [];
$stmt_ss = $conn->prepare("SELECT * FROM school_fee_settings WHERE company_id=?");
if ($stmt_ss) { $stmt_ss->bind_param("i", $companyId); $stmt_ss->execute(); $settings = $stmt_ss->get_result()->fetch_assoc() ?: []; }

$feeDetails = static function (int $fid) use ($conn, $companyId) {
    $stmt_fd = $conn->prepare("SELECT sf.*, ss.student_name, ss.parent_name, ss.parent_phone, ss.class_name FROM school_fees sf JOIN school_students ss ON sf.student_id=ss.id WHERE sf.id=? AND sf.company_id=?");
    if (!$stmt_fd) return null;
    $stmt_fd->bind_param("ii", $fid, $companyId); $stmt_fd->execute();
    return $stmt_fd->get_result()->fetch_assoc() ?: null;
};

// Handle Add Student
if (isset($_POST['add_student'])) {
    csrf_verify();
    $sname = sanitize($_POST['student_name'] ?? ''); $class = sanitize($_POST['class_name'] ?? '');
    $sec   = sanitize($_POST['section'] ?? '');  $roll  = sanitize($_POST['roll_number'] ?? '');
    $pname = sanitize($_POST['parent_name'] ?? ''); $phone = sanitize($_POST['parent_phone'] ?? '');
    $adm   = sanitize($_POST['admission_number'] ?? '');
    $stmt_as = $conn->prepare("INSERT INTO school_students (company_id,student_name,class_name,section,roll_number,parent_name,parent_phone,admission_number) VALUES (?,?,?,?,?,?,?,?)");
    if ($stmt_as) { $stmt_as->bind_param("isssssss", $companyId, $sname, $class, $sec, $roll, $pname, $phone, $adm); $stmt_as->execute(); }
    header("Location: school_fees.php?msg=student_added"); exit();
}

// Handle Add Fee
if (isset($_POST['add_fee'])) {
    csrf_verify();
    $sid   = intval($_POST['student_id'] ?? 0); $ftype = sanitize($_POST['fee_type'] ?? '');
    $amt   = floatval($_POST['amount'] ?? 0);   $due   = (string)($_POST['due_date'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $stmt_af = $conn->prepare("INSERT INTO school_fees (company_id,student_id,fee_type,amount,due_date,notes) VALUES (?,?,?,?,?,?)");
    if ($stmt_af && $sid > 0) { $stmt_af->bind_param("iisdss", $companyId, $sid, $ftype, $amt, $due, $notes); $stmt_af->execute(); }
    header("Location: school_fees.php?msg=fee_added"); exit();
}

// Handle Mark Paid + Send Receipt
if (isset($_POST['mark_paid'])) {
    csrf_verify();
    $fid = intval($_POST['fee_id'] ?? 0); $paidAmt = floatval($_POST['paid_amount'] ?? 0);
    $receipt = 'RCP-' . date('Ym') . '-' . str_pad((string)$fid, 4, '0', STR_PAD_LEFT);
    $stmt_mp = $conn->prepare("UPDATE school_fees SET status='paid',paid_amount=?,paid_date=CURDATE(),receipt_number=? WHERE id=? AND company_id=?");
    if ($stmt_mp) { $stmt_mp->bind_param("dsii", $paidAmt, $receipt, $fid, $companyId); $stmt_mp->execute(); }

    $qs = 'msg=paid';
    if (isset($_POST['send_receipt'])) {
        $fee = $feeDetails($fid);
        if ($fee) {
            $r = sf_queue($conn, $companyId, $fee, $settings, 'receipt', ['paid' => $paidAmt, 'receipt' => $receipt]);
            if (!$r['queued']) { $_SESSION['sf_note'] = $r['reason']; $qs = 'msg=paid_noreceipt'; }
        }
    }
    header("Location: school_fees.php?" . $qs); exit();
}

// Handle Send Reminder
if (isset($_POST['send_reminder'])) {
    csrf_verify();
    $fid = intval($_POST['fee_id'] ?? 0);
    $fee = $feeDetails($fid);
    if ($fee) {
        $r = sf_queue($conn, $companyId, $fee, $settings, 'reminder');
        if ($r['queued']) {
            $stmt_ru = $conn->prepare("UPDATE school_fees SET reminder_sent=reminder_sent+1, last_reminder_at=NOW() WHERE id=? AND company_id=?");
            if ($stmt_ru) { $stmt_ru->bind_param("ii", $fid, $companyId); $stmt_ru->execute(); }
            header("Location: school_fees.php?msg=" . ($r['mode'] === 'template' ? 'reminder_template' : 'reminder_sent')); exit();
        }
        $_SESSION['sf_note'] = $r['reason'];
        header("Location: school_fees.php?msg=reminder_failed"); exit();
    }
    header("Location: school_fees.php"); exit();
}

// Mark overdue first so the numbers below are current
$stmt_ov = $conn->prepare("UPDATE school_fees SET status='overdue' WHERE company_id=? AND status='pending' AND due_date < CURDATE()");
if ($stmt_ov) { $stmt_ov->bind_param("i", $companyId); $stmt_ov->execute(); }

// Stats
$fstats = [];
$stmt_fst = $conn->prepare("SELECT COUNT(*) as total, SUM(amount) as total_amt, SUM(CASE WHEN status='paid' THEN paid_amount ELSE 0 END) as collected, SUM(CASE WHEN status IN ('pending','overdue') THEN amount ELSE 0 END) as pending FROM school_fees WHERE company_id=?");
if ($stmt_fst) { $stmt_fst->bind_param("i", $companyId); $stmt_fst->execute(); $fstats = $stmt_fst->get_result()->fetch_assoc() ?: []; }

// Status counts for the filter chips
$statusCounts = ['pending' => 0, 'overdue' => 0, 'paid' => 0, 'partial' => 0];
$stmt_sc = $conn->prepare("SELECT status, COUNT(*) c FROM school_fees WHERE company_id=? GROUP BY status");
if ($stmt_sc) {
    $stmt_sc->bind_param("i", $companyId); $stmt_sc->execute();
    $rsc = $stmt_sc->get_result();
    while ($rsc && ($r = $rsc->fetch_assoc())) { if (isset($statusCounts[$r['status']])) $statusCounts[$r['status']] = (int)$r['c']; }
}
$allCount = array_sum($statusCounts);

// Fees list — validate status filter against allowed values
$filterStatus = (string)($_GET['status'] ?? '');
if ($filterStatus !== '' && !array_key_exists($filterStatus, $statusCounts)) $filterStatus = '';

$fees = [];
if ($filterStatus !== '') {
    $stmt_fees = $conn->prepare("SELECT sf.*, ss.student_name, ss.class_name, ss.parent_phone FROM school_fees sf JOIN school_students ss ON sf.student_id=ss.id WHERE sf.company_id=? AND sf.status=? ORDER BY sf.due_date ASC LIMIT 100");
    if ($stmt_fees) $stmt_fees->bind_param("is", $companyId, $filterStatus);
} else {
    $stmt_fees = $conn->prepare("SELECT sf.*, ss.student_name, ss.class_name, ss.parent_phone FROM school_fees sf JOIN school_students ss ON sf.student_id=ss.id WHERE sf.company_id=? ORDER BY sf.due_date ASC LIMIT 100");
    if ($stmt_fees) $stmt_fees->bind_param("i", $companyId);
}
if ($stmt_fees) { $stmt_fees->execute(); $fees = $stmt_fees->get_result()->fetch_all(MYSQLI_ASSOC); }

// Students for dropdown
$students = [];
$stmt_stu = $conn->prepare("SELECT id, student_name, class_name FROM school_students WHERE company_id=? AND is_active=1 ORDER BY student_name");
if ($stmt_stu) { $stmt_stu->bind_param("i", $companyId); $stmt_stu->execute(); $students = $stmt_stu->get_result()->fetch_all(MYSQLI_ASSOC); }

$flash = ['student_added' => 'Student added.', 'fee_added' => 'Fee added.', 'paid' => 'Marked as paid. The receipt is queued on WhatsApp.',
          'reminder_sent' => 'Reminder queued on WhatsApp.', 'reminder_template' => 'Reminder queued on WhatsApp using your approved template.'][$_GET['msg'] ?? ''] ?? '';
$sfNote = '';
if (in_array($_GET['msg'] ?? '', ['reminder_failed', 'paid_noreceipt'], true)) {
    $sfNote = ($_GET['msg'] === 'paid_noreceipt' ? 'Marked as paid, but the receipt was not sent. ' : 'Reminder not sent. ') . (string)($_SESSION['sf_note'] ?? '');
    unset($_SESSION['sf_note']);
}
$tagFor = ['pending' => 'amber', 'overdue' => 'red', 'paid' => 'green', 'partial' => 'purple'];

include '../includes/client_header.php';
?>
<link rel="stylesheet" href="<?php echo app_url('assets/css/section-ui.css?v=2'); ?>">

<div class="su">

<div class="su-head">
    <div class="su-head-l">
        <div class="su-ico"><?php echo $lI('<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>', 25); ?></div>
        <div>
            <h1>School Fee Manager</h1>
            <p>Keep track of every student's fees and remind parents on WhatsApp in one tap.</p>
        </div>
    </div>
    <div class="su-actions">
        <button type="button" class="su-btn soft" data-open="addStudentModal"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>'); ?> Add student</button>
        <button type="button" class="su-btn soft" data-open="addFeeModal"><?php echo $lI('<path d="M5 12h14"/><path d="M12 5v14"/>'); ?> Add fee</button>
        <a href="school_fee_settings.php" class="su-btn"><?php echo $lI('<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>'); ?> Settings</a>
    </div>
</div>

<?php if ($sfNote): ?><div class="su-alert" style="margin-bottom:18px;background:#FFF4DE;color:#8a5300"><?php echo $lI('<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>', 18); ?> <?php echo htmlspecialchars($sfNote); ?> <a href="school_fee_settings.php" style="color:#0B6B5E;font-weight:700">Open settings</a></div><?php endif; ?>
<?php if ($flash): ?><div class="su-alert ok" style="margin-bottom:18px"><?php echo $lI('<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>', 18); ?> <?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<div class="su-kpis">
    <div class="su-kpi"><div class="su-kpi-i"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></div><div><b><?php echo count($students); ?></b><span>Students</span></div></div>
    <div class="su-kpi blue"><div class="su-kpi-i"><?php echo $lI('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></div><div><b>₹<?php echo number_format((float)($fstats['total_amt'] ?? 0)); ?></b><span>Total fees</span></div></div>
    <div class="su-kpi"><div class="su-kpi-i"><?php echo $lI('<path d="M20 6 9 17l-5-5"/>'); ?></div><div><b>₹<?php echo number_format((float)($fstats['collected'] ?? 0)); ?></b><span>Collected</span></div></div>
    <div class="su-kpi red"><div class="su-kpi-i"><?php echo $lI('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'); ?></div><div><b>₹<?php echo number_format((float)($fstats['pending'] ?? 0)); ?></b><span>Still to collect</span></div></div>
</div>

<div class="su-chips" style="margin-bottom:18px">
    <a class="su-chip <?php echo $filterStatus === '' ? 'on' : ''; ?>" href="school_fees.php">All <em><?php echo (int)$allCount; ?></em></a>
    <?php foreach (['pending' => 'Pending', 'overdue' => 'Overdue', 'partial' => 'Partial', 'paid' => 'Paid'] as $k => $lbl): ?>
    <a class="su-chip <?php echo $filterStatus === $k ? 'on' : ''; ?>" href="school_fees.php?status=<?php echo $k; ?>"><?php echo $lbl; ?> <em><?php echo (int)$statusCounts[$k]; ?></em></a>
    <?php endforeach; ?>
</div>

<section class="su-card">
    <div class="su-tbl-wrap">
    <table class="su-tbl">
        <thead><tr><th>Student</th><th>Class</th><th>Fee type</th><th>Amount</th><th>Due date</th><th>Status</th><th>Reminders</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$fees): ?>
        <tr><td colspan="8" class="su-tbl-empty">No fees found. Add a student and a fee to get started.</td></tr>
        <?php else: foreach ($fees as $fee):
            $tc = $tagFor[$fee['status']] ?? '';
            $isOverdue = $fee['status'] === 'overdue';
        ?>
        <tr>
            <td><span class="nm"><?php echo htmlspecialchars($fee['student_name']); ?></span><br><small><?php echo htmlspecialchars((string)$fee['parent_phone']); ?></small></td>
            <td><?php echo htmlspecialchars($fee['class_name'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
            <td><strong>₹<?php echo number_format((float)$fee['amount'], 2); ?></strong></td>
            <td style="<?php echo $isOverdue ? 'color:#b3261e;font-weight:800;' : ''; ?>"><?php echo date('d M Y', strtotime($fee['due_date'])); ?></td>
            <td><span class="su-tag <?php echo $tc; ?>"><?php echo htmlspecialchars(ucfirst($fee['status'])); ?></span></td>
            <td><small><?php echo (int)$fee['reminder_sent']; ?> sent</small></td>
            <td style="white-space:nowrap;text-align:right">
                <?php if ($fee['status'] !== 'paid'): ?>
                <form method="POST" class="su-inline" onsubmit="return confirm('Mark as paid and send the receipt?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="fee_id" value="<?php echo (int)$fee['id']; ?>">
                    <input type="hidden" name="paid_amount" value="<?php echo htmlspecialchars((string)$fee['amount']); ?>">
                    <input type="hidden" name="send_receipt" value="1">
                    <button type="submit" name="mark_paid" class="su-btn xs"><?php echo $lI('<path d="M20 6 9 17l-5-5"/>'); ?> Paid</button>
                </form>
                <form method="POST" class="su-inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="fee_id" value="<?php echo (int)$fee['id']; ?>">
                    <button type="submit" name="send_reminder" class="su-btn xs soft"><?php echo $lI('<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>'); ?> Remind</button>
                </form>
                <?php else: ?>
                <span class="su-tag green"><?php echo $lI('<path d="M20 6 9 17l-5-5"/>', 13); ?> <?php echo htmlspecialchars((string)($fee['receipt_number'] ?? 'Paid')); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>
</div>

<!-- Add student -->
<div class="su-modal" id="addStudentModal" role="dialog" aria-modal="true" aria-label="Add student">
  <div class="su-modal-box su">
    <h3><span class="ch"><?php echo $lI('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>'); ?></span> Add student</h3>
    <p class="sub">Add the student and the parent's WhatsApp number.</p>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <div class="su-fg su-f">
        <div class="su-f"><label class="l">Student name *</label><input type="text" name="student_name" required></div>
        <div class="su-f"><label class="l">Admission number</label><input type="text" name="admission_number"></div>
        <div class="su-f"><label class="l">Class</label><input type="text" name="class_name" placeholder="e.g. Class 5"></div>
        <div class="su-f"><label class="l">Section</label><input type="text" name="section" placeholder="A / B / C"></div>
        <div class="su-f"><label class="l">Roll number</label><input type="text" name="roll_number"></div>
        <div class="su-f"><label class="l">Parent name</label><input type="text" name="parent_name"></div>
        <div class="su-f full"><label class="l">Parent WhatsApp number *</label><input type="text" name="parent_phone" placeholder="919876543210" required></div>
      </div>
      <div class="su-modal-foot"><button type="button" class="su-btn soft" data-close>Cancel</button><button type="submit" name="add_student" class="su-btn">Add student</button></div>
    </form>
  </div>
</div>

<!-- Add fee -->
<div class="su-modal" id="addFeeModal" role="dialog" aria-modal="true" aria-label="Add fee">
  <div class="su-modal-box su">
    <h3><span class="ch"><?php echo $lI('<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></span> Add fee</h3>
    <p class="sub">Create a fee for a student. Parents are reminded before it's due.</p>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <div class="su-fg su-f">
        <div class="su-f full"><label class="l">Student *</label>
          <select name="student_id" required><option value="">Select student</option>
            <?php foreach ($students as $stu): ?><option value="<?php echo (int)$stu['id']; ?>"><?php echo htmlspecialchars($stu['student_name']); ?><?php echo $stu['class_name'] ? ' — ' . htmlspecialchars($stu['class_name']) : ''; ?></option><?php endforeach; ?>
          </select></div>
        <div class="su-f full"><label class="l">Fee type *</label>
          <select name="fee_type"><option>Tuition Fee</option><option>Transport Fee</option><option>Exam Fee</option><option>Annual Fee</option><option>Lab Fee</option><option>Sports Fee</option><option>Other</option></select></div>
        <div class="su-f"><label class="l">Amount (₹) *</label><input type="number" name="amount" min="1" step="0.01" required></div>
        <div class="su-f"><label class="l">Due date *</label><input type="date" name="due_date" required></div>
        <div class="su-f full"><label class="l">Notes</label><input type="text" name="notes" placeholder="Optional"></div>
      </div>
      <div class="su-modal-foot"><button type="button" class="su-btn soft" data-close>Cancel</button><button type="submit" name="add_fee" class="su-btn">Add fee</button></div>
    </form>
  </div>
</div>
<script>
(function () {
    function bind(id) {
        var m = document.getElementById(id);
        m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
        m.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { m.classList.remove('open'); }); });
    }
    ['addStudentModal', 'addFeeModal'].forEach(bind);
    document.querySelectorAll('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () { document.getElementById(b.dataset.open).classList.add('open'); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.su-modal.open').forEach(function (m) { m.classList.remove('open'); }); });
})();
</script>
<?php include '../includes/client_footer.php'; ?>

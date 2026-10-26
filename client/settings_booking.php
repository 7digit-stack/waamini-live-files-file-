<?php
include '../includes/client_auth_check.php';
$pageTitle = 'Booking Bot Settings';
$currentPage = 'settings_booking';

// Migration handled schema changeDEFAULT 'restaurant', business_name VARCHAR(200), slots_per_day INT DEFAULT 10, slot_duration_mins INT DEFAULT 30, open_time TIME DEFAULT '09:00:00', close_time TIME DEFAULT '21:00:00', booking_message TEXT, confirmation_message TEXT, is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Migration handled schema changeDEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_co(company_id), INDEX idx_date(booking_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();
    $vertical = in_array($_POST['vertical'], ['restaurant','healthcare','coaching','realestate']) ? $_POST['vertical'] : 'restaurant';
    $bizName  = sanitize($_POST['business_name']);
    $slots    = intval($_POST['slots_per_day'] ?? 10);
    $duration = intval($_POST['slot_duration_mins'] ?? 30);
    $open     = sanitize($_POST['open_time'] ?? '09:00');
    $close    = sanitize($_POST['close_time'] ?? '21:00');
    $bookMsg  = trim($_POST['booking_message']);
    $confMsg  = trim($_POST['confirmation_message']);
    $active   = isset($_POST['is_active']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO booking_settings (company_id, vertical, business_name, slots_per_day, slot_duration_mins, open_time, close_time, booking_message, confirmation_message, is_active) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vertical=VALUES(vertical), business_name=VALUES(business_name), slots_per_day=VALUES(slots_per_day), slot_duration_mins=VALUES(slot_duration_mins), open_time=VALUES(open_time), close_time=VALUES(close_time), booking_message=VALUES(booking_message), confirmation_message=VALUES(confirmation_message), is_active=VALUES(is_active)");
    $stmt->bind_param("issiisssssi", $companyId, $vertical, $bizName, $slots, $duration, $open, $close, $bookMsg, $confMsg, $active);
    // Fix param count
    $stmt = $conn->prepare("INSERT INTO booking_settings (company_id, vertical, business_name, slots_per_day, slot_duration_mins, open_time, close_time, booking_message, confirmation_message, is_active) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vertical=VALUES(vertical), business_name=VALUES(business_name), slots_per_day=VALUES(slots_per_day), slot_duration_mins=VALUES(slot_duration_mins), open_time=VALUES(open_time), close_time=VALUES(close_time), booking_message=VALUES(booking_message), confirmation_message=VALUES(confirmation_message), is_active=VALUES(is_active)");
    $stmt->bind_param("issiissssi", $companyId, $vertical, $bizName, $slots, $duration, $open, $close, $bookMsg, $confMsg, $active);
    $stmt->execute();
    header("Location: settings_booking.php?msg=saved");
    exit();
}

$stmt_s = $conn->prepare("SELECT * FROM booking_settings WHERE company_id=?");
$stmt_s->bind_param("i", $companyId);
$stmt_s->execute();
$s = $stmt_s->get_result()->fetch_assoc();

$verticalDefaults = [
    'restaurant' => ['trigger' => 'book table', 'book_msg' => 'Hi {{first_name}}! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg> To book a table, please reply with:' . "\nDate (DD/MM/YYYY) and Time (e.g. 7:00 PM)\n\nExample: 25/12 7PM for 2 people", 'conf_msg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Table booked for {{date}} at {{time}}! See you soon <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>'],
    'healthcare' => ['trigger' => 'appointment', 'book_msg' => 'Hi {{first_name}}! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> To book an appointment, reply with your preferred date and time.' . "\n\nExample: 20/12 10AM", 'conf_msg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Appointment confirmed for {{date}} at {{time}}. Please arrive 10 mins early <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>'],
    'coaching'   => ['trigger' => 'enroll', 'book_msg' => 'Hi {{first_name}}! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> Reply with your preferred batch date and we\'ll confirm your enrollment.', 'conf_msg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Enrollment confirmed! {{date}} at {{time}}. Welcome aboard <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/></svg>'],
    'realestate' => ['trigger' => 'visit', 'book_msg' => 'Hi {{first_name}}! <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> To schedule a property visit, reply with your preferred date and time.', 'conf_msg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Site visit booked for {{date}} at {{time}}. Our agent will meet you <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>'],
];

include '../includes/client_header.php';
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Booking bot saved!</div><?php endif; ?>

<div class="page-header">
    <h2><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Booking Bot</h2>
    <a href="bookings.php" class="btn btn-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> View Bookings</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div class="form-card" style="max-width:none;">
<form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-group">
        <label>Business Type</label>
        <select name="vertical" class="form-control" id="verticalSel" onchange="updateDefaults()">
            <?php foreach (['restaurant'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg> Restaurant','healthcare'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg> Healthcare/Clinic','coaching'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg> Coaching/Education','realestate'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> Real Estate'] as $v => $l): ?>
            <option value="<?php echo $v; ?>" <?php echo ($s['vertical']??'restaurant')===$v?'selected':''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label>Business Name</label><input type="text" name="business_name" class="form-control" value="<?php echo htmlspecialchars($s['business_name']??''); ?>" required></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Open Time</label><input type="time" name="open_time" class="form-control" value="<?php echo $s['open_time']??'09:00'; ?>"></div>
        <div class="form-group"><label>Close Time</label><input type="time" name="close_time" class="form-control" value="<?php echo $s['close_time']??'21:00'; ?>"></div>
        <div class="form-group"><label>Slots Per Day</label><input type="number" name="slots_per_day" class="form-control" value="<?php echo $s['slots_per_day']??10; ?>" min="1" max="100"></div>
        <div class="form-group"><label>Slot Duration (mins)</label><input type="number" name="slot_duration_mins" class="form-control" value="<?php echo $s['slot_duration_mins']??30; ?>" min="15" max="240"></div>
    </div>
    <div class="form-group"><label>Booking Request Message</label><textarea name="booking_message" class="form-control" rows="4" id="bookMsgTA"><?php echo htmlspecialchars($s['booking_message']??''); ?></textarea><small style="color:var(--text-muted);">Sent when customer sends trigger keyword. Use {{first_name}}</small></div>
    <div class="form-group"><label>Confirmation Message</label><textarea name="confirmation_message" class="form-control" rows="3" id="confMsgTA"><?php echo htmlspecialchars($s['confirmation_message']??''); ?></textarea><small style="color:var(--text-muted);">Use {{date}}, {{time}}, {{first_name}}</small></div>
    <div class="form-group" style="display:flex;gap:10px;align-items:center;"><input type="checkbox" name="is_active" id="ba" <?php echo ($s['is_active']??1)?'checked':''; ?>><label for="ba" style="margin:0;font-size:13px;">Enable Booking Bot</label></div>
    <button type="submit" name="save" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg> Save Settings</button>
</form>
</div>
<div>
    <div class="form-card" style="max-width:none;margin-bottom:16px;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg> How it Works</h3>
        <ul style="font-size:13px;color:var(--text-secondary);line-height:2;list-style:none;padding:0;">
            <li id="triggerLine"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg> Trigger keyword shown after save</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg> Bot asks for date & time</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Confirmation auto-sent to customer</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg> View all bookings in Bookings page</li>
        </ul>
    </div>
    <?php
    $stmt_bk = $conn->prepare("SELECT COUNT(*) as c FROM bookings WHERE company_id=? AND booking_date >= CURDATE()");
    $stmt_bk->bind_param("i", $companyId);
    $stmt_bk->execute();
    $upcoming = $stmt_bk->get_result()->fetch_assoc()['c'] ?? 0;
    ?>
    <div class="form-card" style="max-width:none;text-align:center;">
        <div style="font-size:32px;font-weight:800;color:var(--accent);"><?php echo $upcoming; ?></div>
        <div style="font-size:13px;color:var(--text-muted);">Upcoming Bookings</div>
    </div>
</div>
</div>

<script>
var defaults = <?php echo json_encode($verticalDefaults); ?>;
function updateDefaults() {
    var v = document.getElementById('verticalSel').value;
    var d = defaults[v];
    if (!document.getElementById('bookMsgTA').value) document.getElementById('bookMsgTA').value = d.book_msg;
    if (!document.getElementById('confMsgTA').value) document.getElementById('confMsgTA').value = d.conf_msg;
    (function (el) { el.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;flex-shrink:0"><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>'; el.appendChild(document.createTextNode(' Trigger: Customer sends "' + d.trigger + '"')); })(document.getElementById('triggerLine'));
}
updateDefaults();
</script>
<?php include '../includes/client_footer.php'; ?>

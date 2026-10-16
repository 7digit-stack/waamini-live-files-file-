<?php
require 'config/bootstrap.php';
require 'db.php';
require 'includes/google_meet_helper.php';

$email = gmeet_connected($conn, 2);
echo "Google Calendar connection: " . ($email ? "✅ CONNECTED ($email)" : "❌ NOT CONNECTED") . "\n";

if ($email) {
    $tok = gmeet_access_token($conn, 2);
    echo "Access token refresh: " . ($tok ? "✅ working" : "❌ FAILED (needs reconnect)") . "\n";
}

echo "\nBookings with Google Meet links:\n";
$r = $conn->query("SELECT id, customer_name, demo_datetime, status, meet_link FROM demo_bookings WHERE company_id=2 AND meet_link IS NOT NULL AND meet_link != '' ORDER BY id DESC LIMIT 5");
if ($r->num_rows === 0) echo "  (none yet)\n";
while ($b = $r->fetch_assoc()) {
    echo "  #{$b['id']} {$b['customer_name']} | {$b['demo_datetime']} | {$b['status']} | {$b['meet_link']}\n";
}

echo "\nUpcoming demos (reminder-armed):\n";
$r = $conn->query("SELECT id, customer_name, demo_datetime, status, reminder_1h_sent, reminder_5m_sent FROM demo_bookings WHERE company_id=2 AND demo_datetime > NOW() ORDER BY demo_datetime LIMIT 5");
if ($r->num_rows === 0) echo "  (no upcoming demos right now)\n";
while ($b = $r->fetch_assoc()) {
    echo "  #{$b['id']} {$b['customer_name']} | {$b['demo_datetime']} | {$b['status']} | 1h=" . ($b['reminder_1h_sent'] ? 'sent' : 'armed') . " 5m=" . ($b['reminder_5m_sent'] ? 'sent' : 'armed') . "\n";
}
?>

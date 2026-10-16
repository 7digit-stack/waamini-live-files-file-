<?php
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/db.php';
try { $conn->query("ALTER TABLE demo_bookings ADD COLUMN meet_link VARCHAR(255) DEFAULT NULL"); echo "meet_link added\n"; } catch (Throwable $e) { echo "meet_link: " . $e->getMessage() . "\n"; }
try { $conn->query("ALTER TABLE demo_bookings ADD COLUMN calendar_event_id VARCHAR(255) DEFAULT NULL"); echo "calendar_event_id added\n"; } catch (Throwable $e) { echo "calendar_event_id: " . $e->getMessage() . "\n"; }
?>

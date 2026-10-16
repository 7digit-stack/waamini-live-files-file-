// STEP 2: Find missed demos
dr_log('MCP ANALYSIS: Finding missed demos...');
$missedStmt = $conn->prepare("
    SELECT id, company_id, customer_name, customer_phone, preferred_time,
           demo_datetime, topic, meet_link, status, reschedule_count
    FROM demo_bookings
    WHERE status IN ('scheduled', 'confirmed')
      AND demo_datetime IS NOT NULL
      AND demo_datetime < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
      AND reminder_5m_sent = 1
      AND (reschedule_count IS NULL OR reschedule_count < 3)
      AND (missed_at IS NULL)
    ORDER BY demo_datetime ASC
    LIMIT 20
");
$missedStmt->execute();
$missedRows = $missedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$missedStmt->close();
$report['analyzed'] = count($missedRows);
dr_log('   Found ' . $report['analyzed'] . ' missed demo(s).');
if (empty($missedRows)) { dr_log('No missed demos found.'); goto cleanup; }

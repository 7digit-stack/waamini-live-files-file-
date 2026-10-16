<?php
/**
 * Booking Bot Handler — triggered from webhook.php
 * Parses customer date/time reply and creates booking
 */
function booking_bot_check($conn, $companyId, $contactId, $contactData, $text)
{
    $stmt_s = $conn->prepare("SELECT * FROM booking_settings WHERE company_id=? AND is_active=1 LIMIT 1");
    $stmt_s->bind_param("i", $companyId);
    $stmt_s->execute();
    $settings = $stmt_s->get_result()->fetch_assoc();
    if (!$settings) return false;

    $triggers = ['restaurant'=>'book table','healthcare'=>'appointment','coaching'=>'enroll','realestate'=>'visit'];
    $trigger  = $triggers[$settings['vertical']] ?? 'book';
    $textLower = mb_strtolower(trim($text));

    // Check for booking session
    $stmt_ses = $conn->prepare("SELECT * FROM chatbot_sessions WHERE company_id=? AND contact_id=? AND flow_id=-1 AND status='active' LIMIT 1");
    $stmt_ses->bind_param("ii", $companyId, $contactId);
    $stmt_ses->execute();
    $session = $stmt_ses->get_result()->fetch_assoc();

    if (!$session && str_contains($textLower, $trigger)) {
        // Start booking flow — ask for date/time
        $msg = $settings['booking_message'];
        $name = $contactData['name'] ?? '';
        $msg = str_replace(['{{name}}','{{first_name}}'], [$name, explode(' ', $name)[0]], $msg);

        // Migration handled schema changeDEFAULT 'active', started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY idx_active (company_id, contact_id, flow_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt_cs = $conn->prepare("INSERT INTO chatbot_sessions (company_id, contact_id, flow_id, context_data, status) VALUES (?,?,-1,'{\"step\":\"awaiting_datetime\"}','active') ON DUPLICATE KEY UPDATE flow_id=-1, context_data='{\"step\":\"awaiting_datetime\"}', status='active', updated_at=NOW()");
        $stmt_cs->bind_param("ii", $companyId, $contactId);
        $stmt_cs->execute();

        $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?,?,'instant',?,NOW())");
        $stmt_q->bind_param("iis", $companyId, $contactId, $msg);
        $stmt_q->execute();
        return true;
    }

    if ($session) {
        // Try to parse date/time from text
        $parsed = parse_booking_datetime($text);
        if ($parsed) {
            [$bookDate, $bookTime] = $parsed;

            // Check slot availability
            $stmt_cnt = $conn->prepare("SELECT COUNT(*) as c FROM bookings WHERE company_id=? AND booking_date=? AND booking_time=? AND status != 'cancelled'");
            $stmt_cnt->bind_param("iss", $companyId, $bookDate, $bookTime);
            $stmt_cnt->execute();
            $taken = intval($stmt_cnt->get_result()->fetch_assoc()['c']);

            if ($taken >= $settings['slots_per_day']) {
                $reply = "Sorry, that slot is fully booked 😔 Please try a different time.";
            } else {
                // Create booking
                $stmt_bk = $conn->prepare("INSERT INTO bookings (company_id, contact_id, booking_date, booking_time, status) VALUES (?,?,?,?,'confirmed')");
                $stmt_bk->bind_param("iiss", $companyId, $contactId, $bookDate, $bookTime);
                $stmt_bk->execute();

                $displayDate = date('d M Y', strtotime($bookDate));
                $displayTime = date('g:i A', strtotime($bookTime));
                $reply = $settings['confirmation_message'];
                $name  = $contactData['name'] ?? '';
                $reply = str_replace(['{{date}}','{{time}}','{{name}}','{{first_name}}'], [$displayDate, $displayTime, $name, explode(' ', $name)[0]], $reply);

                // End session
                $stmt_end = $conn->prepare("UPDATE chatbot_sessions SET status='completed' WHERE company_id=? AND contact_id=? AND flow_id=-1");
                $stmt_end->bind_param("ii", $companyId, $contactId);
                $stmt_end->execute();
            }
        } else {
            $reply = "I couldn't understand that date/time 🙏 Please try: DD/MM HHAM\nExample: 25/12 7PM";
        }

        $stmt_q = $conn->prepare("INSERT INTO automation_queue (company_id, contact_id, type, message, scheduled_at) VALUES (?,?,'instant',?,NOW())");
        $stmt_q->bind_param("iis", $companyId, $contactId, $reply);
        $stmt_q->execute();
        return true;
    }

    return false;
}

function parse_booking_datetime($text)
{
    // Try multiple formats: "25/12 7PM", "25 Dec 10AM", "tomorrow 3pm", "20/01/2025 14:00"
    $text = trim($text);

    // Pattern: DD/MM HH:MM or DD/MM HHAM/PM
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-]\d{2,4})?\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $text, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mon = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $yr  = date('Y');
        $hr  = intval($m[3]);
        $min = $m[4] ?? '00';
        $ampm = strtolower($m[5] ?? '');
        if ($ampm === 'pm' && $hr < 12) $hr += 12;
        if ($ampm === 'am' && $hr === 12) $hr = 0;
        $date = "$yr-$mon-$day";
        $time = str_pad($hr, 2, '0', STR_PAD_LEFT) . ":$min:00";
        if (strtotime($date) !== false && $hr >= 0 && $hr <= 23) return [$date, $time];
    }

    // "tomorrow 3pm"
    if (preg_match('/tomorrow\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $text, $m)) {
        $date = date('Y-m-d', strtotime('+1 day'));
        $hr = intval($m[1]);
        $min = $m[2] ?? '00';
        $ampm = strtolower($m[3] ?? '');
        if ($ampm === 'pm' && $hr < 12) $hr += 12;
        $time = str_pad($hr, 2, '0', STR_PAD_LEFT) . ":$min:00";
        return [$date, $time];
    }

    return null;
}

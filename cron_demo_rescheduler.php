<?php

/**
 * WAAMINI Demo Auto-Rescheduler v1.0
 * MCP-Powered: Analyzes demo schedule, detects missed demos,
 * auto-reschedules to nearest slot (+1hr or next day).
 * Run: php cron_demo_rescheduler.php
 */

require_once __DIR__ . "/config/bootstrap.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/includes/whatsapp_api_helper.php";
require_once __DIR__ . "/includes/demo_wa.php";
require_once __DIR__ . "/includes/google_meet_helper.php";

// Align the MySQL session timezone with IST so missed/NOW() comparisons are exact.
try { $conn->query("SET time_zone = '+05:30'"); } catch (Throwable $e) {}

$isCli = (php_sapi_name() === "cli");
if (!$isCli) header("Content-Type: text/plain; charset=utf-8");

function dr_log(string $m): void {
    error_log("[DEMO RESCHEDULER] " . $m);
    echo $m . "\n";
}

$lock = fopen(__DIR__ . "/storage/demo_rescheduler.lock", "c");
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another rescheduler is running.\n";
    exit(0);
}

date_default_timezone_set("Asia/Kolkata");
$now = new DateTime();
$report = ["analyzed"=>0,"missed"=>0,"rescheduled"=>0,"notified"=>0,"skipped"=>0,"errors"=>[]];
dr_log("=== DEMO RESCHEDULER v1.0 STARTED ===");

// MCP ANALYSIS: Schema
dr_log("MCP ANALYSIS: Scanning demo_bookings schema...");
try {
    $colRes = $conn->query("SHOW COLUMNS FROM demo_bookings");
    if ($colRes) {
        $cols = [];
        while ($c = $colRes->fetch_assoc()) {
            $cols[] = $c["Field"];
        }
        dr_log("   Schema has " . count($cols) . " columns: " . implode(", ", $cols));
    }
} catch (Throwable $e) {
    dr_log("   Schema error: " . $e->getMessage());
}

dr_log("MCP: Adding rescheduler columns if missing...");
foreach ([
    "ALTER TABLE demo_bookings ADD COLUMN rescheduled_from DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN rescheduled_to DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN reschedule_reason VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD COLUMN reschedule_count TINYINT UNSIGNED DEFAULT 0",
    "ALTER TABLE demo_bookings ADD COLUMN missed_at DATETIME DEFAULT NULL",
    "ALTER TABLE demo_bookings ADD INDEX idx_reschedule (status, demo_datetime, reschedule_count)",
] as $sql) {
    try { $conn->query($sql); } catch (Throwable $e) {}
}
dr_log("   Schema evolution complete.");

// MCP ANALYSIS: Find missed demos
dr_log("MCP ANALYSIS: Finding missed demos...");
$missedStmt = $conn->prepare("SELECT id, company_id, customer_name, customer_phone, preferred_time, demo_datetime, topic, meet_link, status, reschedule_count FROM demo_bookings WHERE status IN ('scheduled', 'confirmed') AND demo_datetime IS NOT NULL AND demo_datetime < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND reminder_5m_sent = 1 AND (reschedule_count IS NULL OR reschedule_count < 3) AND (missed_at IS NULL) ORDER BY demo_datetime ASC LIMIT 20");
$missedStmt->execute();
$missedRows = $missedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$missedStmt->close();
$report["analyzed"] = count($missedRows);
dr_log("   Found " . $report["analyzed"] . " missed demo(s).");
if (empty($missedRows)) {
    dr_log("No missed demos found.");
    goto cleanup;
}

dr_log("MCP: Loading future bookings for conflict avoidance...");
$existingSlots = [];
try {
    $slotRes = $conn->query("SELECT demo_datetime FROM demo_bookings WHERE demo_datetime IS NOT NULL AND status IN ('scheduled','confirmed') AND demo_datetime >= NOW() ORDER BY demo_datetime ASC");
    if ($slotRes) {
        while ($s = $slotRes->fetch_assoc()) {
            $existingSlots[] = $s["demo_datetime"];
        }
    }
    dr_log("   " . count($existingSlots) . " future bookings exist.");
} catch (Throwable $e) { dr_log("   Warning: " . $e->getMessage()); }

function dr_is_slot_available(string $datetime, array $existingSlots): bool {
    $proposed = strtotime($datetime);
    $proposedEnd = $proposed + 1800;
    foreach ($existingSlots as $slot) {
        $slotTs = strtotime($slot);
        if ($slotTs === false) continue;
        $slotStart = $slotTs - 900;
        $slotEnd = $slotTs + 1800;
        if ($proposed < $slotEnd && $proposedEnd > $slotStart) return false;
    }
    return true;
}

function dr_find_next_slot(DateTime $fromTime, array $existingSlots, int $maxDays = 3): ?DateTime {
    $bStart = 10; $bEnd = 19;
    $check = clone $fromTime;
    for ($day = 0; $day < $maxDays; $day++) {
        if ($day > 0) { $check->modify("+1 day"); $check->setTime($bStart, 0, 0); }
        $h = (int)$check->format("G");
        if ($h < $bStart) $check->setTime($bStart, 0, 0);
        if ($h >= $bEnd) continue;
        for ($hour = $h; $hour < $bEnd; $hour++) {
            $mins = ($hour === $h) ? [(int)$check->format("i")] : [0, 30];
            foreach ($mins as $min) {
                $slotStr = $check->format("Y-m-d") . sprintf(" %02d:%02d:00", $hour, $min);
                if (dr_is_slot_available($slotStr, $existingSlots)) return new DateTime($slotStr);
            }
        }
        $check->setTime($bStart, 0, 0);
    }
    return null;
}

dr_log("");
dr_log("PROCESSING MISSED DEMOS...");
foreach ($missedRows as $demo) {
    $bookingId = (int)$demo["id"];
    $companyId = (int)$demo["company_id"];
    $customerName = $demo["customer_name"];
    $customerPhone = $demo["customer_phone"];
    $oldDatetime = $demo["demo_datetime"];
    $reschedCount = (int)($demo["reschedule_count"] ?? 0);
    dr_log(str_repeat("-", 60));
    dr_log("Demo #{$bookingId}: {$customerName} ({$customerPhone})");
    dr_log("   Original: {$oldDatetime} | Reschedule# {$reschedCount}");
    try {
        $report["missed"]++;
        $nowDt = new DateTime();
        $baseSlot = clone $nowDt;
        $baseSlot->modify("+1 hour");
        $m = (int)$baseSlot->format("i");
        if ($m > 0) { $baseSlot->setTime((int)$baseSlot->format("G"), $m <= 30 ? 30 : 0, 0); if ($m > 30) $baseSlot->modify("+1 hour"); }
        $newSlot = null; $reason = "";
        $bh = (int)$baseSlot->format("G");
        if ($bh >= 10 && $bh < 19) {
            dr_log("   Trying same-day +1h to +5h...");
            for ($extra = 0; $extra < 5; $extra++) {
                $cs = clone $baseSlot; $cs->modify("+{$extra} hour");
                $ch = (int)$cs->format("G"); $cm = (int)$cs->format("i");
                if ($cm > 0) { $cs->setTime($ch, $cm <= 30 ? 30 : 0, 0); if ($cm > 30) $cs->modify("+1 hour"); }
                if ((int)$cs->format("G") >= 19) break;
                $ss = $cs->format("Y-m-d H:i:s");
                if (dr_is_slot_available($ss, $existingSlots)) {
                    $newSlot = $cs;
                    $reason = "Auto: missed at {$oldDatetime}, same day +" . ($extra+1) . "h";
                    dr_log("   Found: {$ss}");
                    break;
                }
            }
        }
        if (!$newSlot) {
            dr_log("   Checking next day 10:00-19:00...");
            $nextDay = clone $nowDt; $nextDay->modify("+1 day"); $nextDay->setTime(10, 0, 0);
            for ($h = 10; $h < 19; $h++) {
                foreach ([0,30] as $mi) {
                    $ss = $nextDay->format("Y-m-d") . sprintf(" %02d:%02d:00", $h, $mi);
                    if (dr_is_slot_available($ss, $existingSlots)) {
                        $newSlot = new DateTime($ss);
                        $reason = "Auto: missed at {$oldDatetime}, next day morning";
                        dr_log("   Found: {$ss}");
                        break 2;
                    }
                }
            }
        }
        if (!$newSlot) {
            dr_log("   Broad search up to 5 days...");
            $newSlot = dr_find_next_slot(clone $nowDt, $existingSlots, 5);
            if ($newSlot) { $reason = "Auto: missed at {$oldDatetime}, nearest available"; dr_log("   Found: {$newSlot->format("Y-m-d H:i:s")}"); }
        }
        if (!$newSlot) {
            $fr = "No slots in 5 days. Manual intervention needed.";
            $upd = $conn->prepare("UPDATE demo_bookings SET status='missed', missed_at=NOW(), reschedule_reason=?, reschedule_count=? WHERE id=? AND company_id=?");
            $upd->bind_param("siii", $fr, $reschedCount, $bookingId, $companyId);
            $upd->execute(); $upd->close();
            $report["skipped"]++;
            dr_log("   No slots. Marked missed.");
            continue;
        }
        $newDatetime = $newSlot->format("Y-m-d H:i:s");
        $nrc = $reschedCount + 1;
        $npt = $newSlot->format("l, j F Y \a\t h:i A");
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE demo_bookings SET status='scheduled', demo_datetime=?, preferred_time=?, rescheduled_from=?, rescheduled_to=?, reschedule_reason=?, reschedule_count=?, missed_at=NULL, reminder_1h_sent=0, reminder_5m_sent=0 WHERE id=? AND company_id=?");
            $upd->bind_param("sssssiii", $newDatetime, $npt, $oldDatetime, $newDatetime, $reason, $nrc, $bookingId, $companyId);
            $upd->execute(); $upd->close();
            $existingSlots[] = $newDatetime;
            $conn->commit();
            $report["rescheduled"]++;
            dr_log("   RESCHEDULED: " . $oldDatetime . " -> " . $newDatetime);
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
        try {
            $brand = demo_brand($conn, $companyId);
            // A fresh Meet for the new time (when this client connected Google Calendar), else keep the old link
            $joinLink = trim((string)($demo["meet_link"] ?? ""));
            $gmNew = gmeet_create_demo_event($conn, $companyId, (string)$customerName, (string)$customerPhone, $newDatetime, "Rescheduled demo", $brand);
            if (!empty($gmNew["meet_link"])) {
                $joinLink = $gmNew["meet_link"];
                $uj = $conn->prepare("UPDATE demo_bookings SET meet_link=?, calendar_event_id=? WHERE id=? AND company_id=?");
                if ($uj) { $evId = (string)($gmNew["event_id"] ?? ""); $uj->bind_param("ssii", $joinLink, $evId, $bookingId, $companyId); $uj->execute(); $uj->close(); }
            }
            $joinLine = $joinLink !== "" ? "\n\n🔗 Join: {$joinLink}" : "";
            {
                $msg = "⏰ *Demo Rescheduled* 🙏\n\nHello {$customerName},\n\nWe noticed you missed your demo scheduled for *" . date("d M Y", strtotime($oldDatetime)) . " at " . date("h:i A", strtotime($oldDatetime)) . "*.\n\nWe have rescheduled it for you:\n📅 *{$newSlot->format("d M Y")}*\n🕐 *{$newSlot->format("h:i A")}*{$joinLine}\n\nIf this time doesn't suit you, just reply with a time that works for you and we'll fix it. 😊\n\n— Team {$brand}";
                [$sent] = demo_wa_send($conn, $companyId, (string)$customerPhone, $msg);
                if ($sent) {
                    $report["notified"]++;
                    $like10 = "%" . substr(preg_replace("/[^0-9]/", "", $customerPhone), -10);
                    $ms = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, status, is_read, created_at) SELECT ?, id, 'outgoing', 'text', ?, 'sent', 1, NOW() FROM contacts WHERE company_id=? AND phone LIKE ? LIMIT 1");
                    if ($ms) { $ms->bind_param("isis", $companyId, $msg, $companyId, $like10); $ms->execute(); $ms->close(); }
                }
            }
        } catch (Throwable $e) { dr_log("   Notif error: " . $e->getMessage()); }
    } catch (Throwable $e) {
        dr_log("   ERROR: " . $e->getMessage());
        $report["errors"][] = "Demo #{$bookingId}: " . $e->getMessage();
    }
}

dr_log("");
dr_log(str_repeat("=", 60));
dr_log("MCP ANALYSIS - FINAL REPORT");
dr_log(str_repeat("=", 60));
dr_log("   Analyzed:  " . $report["analyzed"]);
dr_log("   Missed:    " . $report["missed"]);
dr_log("   Resched:   " . $report["rescheduled"]);
dr_log("   Notified:  " . $report["notified"]);
dr_log("   Skipped:   " . $report["skipped"]);
if (!empty($report["errors"])) { foreach ($report["errors"] as $e) dr_log("   Error: " . $e); }
dr_log(str_repeat("=", 60));

try {
    $rd = json_encode(["time"=>$now->format("Y-m-d H:i:s"),"rescheduled"=>$report["rescheduled"],"notified"=>$report["notified"]], JSON_UNESCAPED_UNICODE);
    $conn->query("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('cron_demo_rescheduler_last_run', '" . $conn->real_escape_string($rd) . "', 'cron') ON DUPLICATE KEY UPDATE setting_value='" . $conn->real_escape_string($rd) . "'");
} catch (Throwable $e) {}

cleanup:
try { flock($lock, LOCK_UN); } catch (Throwable $e) {}
try { fclose($lock); } catch (Throwable $e) {}
dr_log("");
dr_log("=== DEMO RESCHEDULER COMPLETED at " . date("Y-m-d H:i:s") . " ===");
exit(0);

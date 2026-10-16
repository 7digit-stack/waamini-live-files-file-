<?php
/**
 * Queue Worker Service
 */
require_once __DIR__ . '/WhatsAppService.php';

class QueueWorker {
    private $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        self::ensureSchema($conn);
    }

    /** The worker uses 'retrying' and processing_started_at; older installs lacked both, so claims and retries silently failed. */
    private static function ensureSchema(mysqli $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        foreach ([
            "ALTER TABLE automation_queue MODIFY COLUMN status ENUM('pending','processing','retrying','sent','failed') DEFAULT 'pending'",
            "ALTER TABLE automation_queue ADD COLUMN processing_started_at DATETIME DEFAULT NULL",
        ] as $sql) {
            try { $conn->query($sql); } catch (\Throwable $e) { /* already applied */ }
        }
    }

    public function process(int $limit = 50) {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT *
                FROM automation_queue
                WHERE status IN ('pending','retrying')
                  AND (scheduled_at IS NULL OR scheduled_at <= ?)
                ORDER BY created_at ASC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('[QueueWorker] prepare failed: ' . $this->conn->error);
            return;
        }
        $stmt->bind_param('si', $now, $limit);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$res) return;

        while ($row = $res->fetch_assoc()) {
            $this->processItem($row);
        }
    }

    private function processItem(array $row) {
        $qid = (int)($row['id'] ?? 0);
        $companyId = (int)($row['company_id'] ?? 0);
        $phone = trim((string)($row['phone'] ?? ''));
        // CRM/lead-automation jobs store only contact_id; look the number up so we never send to an empty 'to'
        if ($phone === '' && (int)($row['contact_id'] ?? 0) > 0) {
            $stP = $this->conn->prepare("SELECT phone FROM contacts WHERE id=? AND company_id=? LIMIT 1");
            if ($stP) {
                $cidP = (int)$row['contact_id'];
                $stP->bind_param('ii', $cidP, $companyId);
                $stP->execute();
                $phone = trim((string)($stP->get_result()->fetch_assoc()['phone'] ?? ''));
                $stP->close();
            }
        }
        $type = (string)($row['type'] ?? '');
        $message = (string)($row['message'] ?? '');
        $payload = json_decode($row['payload_json'] ?? '{}', true);
        if (!is_array($payload)) $payload = [];

        // Mark as processing (atomic lock to avoid double-execution)
        if ($qid > 0) {
            $st = $this->conn->prepare("UPDATE automation_queue SET status='processing', processing_started_at=NOW() WHERE id=? AND status IN ('pending','retrying')");
            if ($st) {
                $st->bind_param('i', $qid);
                $st->execute();
                if ((int)$this->conn->affected_rows !== 1) {
                    return; // someone else is processing
                }
            }
        }

        $waMsgId = null;
        $error = null;
        $retryAfterSeconds = null;

        if ($phone === '') {
            $st = $this->conn->prepare("UPDATE automation_queue SET status='failed', error_msg='no_phone', last_error='Contact has no phone number', processed_at=NOW() WHERE id=?");
            if ($st) { $st->bind_param('i', $qid); $st->execute(); }
            return;
        }

        try {
            switch ($type) {
                case 'text':
                case 'instant':
                case 'bot_reply':
                case 'followup_1':
                case 'followup_2':
                case 'delayed':
                    // If the contact has no inbound within 24h, WhatsApp requires a template.
                    // For production safety, we auto-fallback to a configured template (e.g. "hello_world")
                    // to open the window, then normal free-form messages can be sent.
                    $contactId = (int)($row['contact_id'] ?? 0);
                    $needsTemplate = false;
                    if ($contactId > 0) {
                        $stWin = $this->conn->prepare("SELECT MAX(created_at) AS last_in FROM messages WHERE company_id=? AND contact_id=? AND direction='incoming' LIMIT 1");
                        if ($stWin) {
                            $stWin->bind_param('ii', $companyId, $contactId);
                            $stWin->execute();
                            $lastIn = $stWin->get_result()->fetch_assoc()['last_in'] ?? null;
                            $stWin->close();
                            if (!$lastIn) {
                                $needsTemplate = true;
                            } else {
                                $ts = strtotime((string)$lastIn);
                                if (!$ts || (time() - $ts) > (24 * 3600)) {
                                    $needsTemplate = true;
                                }
                            }
                        }
                    }
                    if ($needsTemplate) {
                        $error = 'outside_24h_window_template_required';
                    } else {
                        $waMsgId = WhatsAppService::sendText($this->conn, $companyId, $phone, $message, $qid);
                    }
                    break;
                case 'template':
                    $waMsgId = WhatsAppService::sendTemplate($this->conn, $companyId, $phone, (string)($payload['template_name'] ?? ''), (string)($payload['language_code'] ?? 'en'), (array)($payload['components'] ?? []), $qid);
                    break;
                case 'media':
                    $waMsgId = WhatsAppService::sendMedia($this->conn, $companyId, $phone, (string)($row['media_url'] ?? ''), (string)($payload['media_type'] ?? 'image'), $message, $qid);
                    break;
                case 'ai_reply':
                    // Centralized AI logic could go here or keep it in bot handler
                    // For now, let's assume message is already generated or handled elsewhere
                    $waMsgId = WhatsAppService::sendText($this->conn, $companyId, $phone, $message, $qid);
                    break;
                default:
                    $error = "Unknown message type: $type";
            }
        } catch (WaaminiRateLimitedException $e) {
            $error = 'rate_limited';
            $retryAfterSeconds = max(1, (int)ceil($e->retryAfterMs / 1000));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        $linkedMessageId = isset($payload['message_id']) ? (int)$payload['message_id'] : 0;

        if ($waMsgId) {
            $st = $this->conn->prepare("UPDATE automation_queue SET status='sent', wa_message_id=?, processed_at=NOW() WHERE id=?");
            if ($st) {
                $st->bind_param('si', $waMsgId, $qid);
                $st->execute();
            }
            
            // Update existing message row when available; otherwise create a minimal one
            if ($linkedMessageId > 0) {
                $up = $this->conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=?, updated_at=NOW() WHERE id=? AND company_id=?");
                if ($up) {
                    $up->bind_param('sii', $waMsgId, $linkedMessageId, $companyId);
                    $up->execute();
                }
            } else {
                $contactId = (int)($row['contact_id'] ?? 0);
                $msgType = ($type === 'media') ? 'image' : 'text';
                $stmt = $this->conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, whatsapp_message_id, status) VALUES (?, ?, 'outgoing', ?, ?, ?, ?, 'sent')");
                if ($stmt) {
                    $stmt->bind_param("iissss", $companyId, $contactId, $msgType, $message, $message, $waMsgId);
                    $stmt->execute();
                }
            }
        } else {
            $retryCount = (int)($row['retry_count'] ?? 0) + 1;
            $status = ($error === 'outside_24h_window_template_required' || $retryCount >= 3) ? 'failed' : 'retrying';
            $error = $error ?? 'WhatsApp API Error';
            // When rate limited, schedule next run slightly later
            $scheduledSql = ($status === 'retrying' && $retryAfterSeconds !== null)
                ? "UPDATE automation_queue SET status=?, retry_count=?, last_error=?, processed_at=NOW(), scheduled_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?"
                : "UPDATE automation_queue SET status=?, retry_count=?, last_error=?, processed_at=NOW() WHERE id=?";
            $stmt = $this->conn->prepare($scheduledSql);
            if ($stmt) {
                if ($status === 'retrying' && $retryAfterSeconds !== null) {
                    $stmt->bind_param("sisii", $status, $retryCount, $error, $retryAfterSeconds, $qid);
                } else {
                    $stmt->bind_param("sisi", $status, $retryCount, $error, $qid);
                }
                $stmt->execute();
            }

            if ($linkedMessageId > 0) {
                $stMsg = ($status === 'failed')
                    ? $this->conn->prepare("UPDATE messages SET status='failed', error_message=?, updated_at=NOW() WHERE id=? AND company_id=?")
                    : $this->conn->prepare("UPDATE messages SET status='queued', error_message=?, updated_at=NOW() WHERE id=? AND company_id=?");
                if ($stMsg) {
                    $errStr = (string)$error;
                    if (function_exists('mb_substr')) {
                        $errStr = mb_substr($errStr, 0, 800, 'UTF-8');
                    } else {
                        $errStr = substr($errStr, 0, 800);
                    }
                    $stMsg->bind_param('sii', $errStr, $linkedMessageId, $companyId);
                    $stMsg->execute();
                }
            }

            // Monitoring log for failed/ratelimited jobs
            try {
                $root = defined('APP_ROOT') ? (string)APP_ROOT : dirname(__DIR__);
                $path = $root . '/storage/logs/queue-failures.log';
                $line = json_encode([
                    'ts' => date('c'),
                    'queue' => 'automation_queue',
                    'id' => $qid,
                    'company_id' => $companyId,
                    'type' => $type,
                    'status' => $status,
                    'retry_count' => $retryCount,
                    'retry_after_sec' => $retryAfterSeconds,
                    'error' => (string)$error,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_string($line)) {
                    if (file_exists(dirname(__DIR__) . '/includes/logging.php')) {
                        require_once dirname(__DIR__) . '/includes/logging.php';
                        if (function_exists('wa_log_append')) {
                            wa_log_append($path, $line, 5 * 1024 * 1024, 7);
                        } else {
                            @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                        }
                    } else {
                        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                    }
                }
            } catch (\Throwable $e) {}
        }
    }
}

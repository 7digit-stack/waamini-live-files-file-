<?php
/**
 * WAAMINI - Smart Message Router
 * Production-level 3-tier message routing system:
 *
 *   PRIORITY 1: Bot Replies    → Exact keyword match (case-insensitive, upper/lowercase)
 *   PRIORITY 2: Flow Builder   → Keyword triggers multi-step flows (case-insensitive)
 *   PRIORITY 3: AI Sales Bot   → GPT / Gemini fallback when no keyword matches
 *
 * All replies are professional and language-aware (responds in client's language).
 */

require_once __DIR__ . '/ai_language_reply.php';
require_once __DIR__ . '/bot_triggers.php';

class SmartRouter
{
    private $conn;
    private int $companyId;
    private int $contactId;
    private string $contactName;
    private string $contactPhone;
    private string $fromDigits;
    private array $currentMessage = [];

    // Timing
    private float $startTime;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->startTime = microtime(true);
        $this->ensureSchema();
    }

    /**
     * Auto-create required columns/tables if missing.
     * Prevents silent query failures that bypass all bot_replies.
     */
    private function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        // Trigger Types need match_type to be a free string and the blacklist table to exist
        bt_ensure_schema($this->conn);

        // bot_replies.priority — required for ORDER BY
        try {
            $r = $this->conn->query("SHOW COLUMNS FROM bot_replies LIKE 'priority'");
            if ($r && $r->num_rows === 0) {
                $this->conn->query("ALTER TABLE bot_replies ADD COLUMN priority INT DEFAULT 0");
            }
        } catch (\Throwable $e) {}

        // message_routing_log — required for logRouting()
        try {
            $this->conn->query("CREATE TABLE IF NOT EXISTS message_routing_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                contact_id INT DEFAULT 0,
                incoming_message TEXT,
                routed_to VARCHAR(50),
                matched_rule_id INT DEFAULT NULL,
                matched_keyword VARCHAR(255) DEFAULT NULL,
                response_sent TEXT,
                response_time_ms INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_company_created (company_id, created_at)
            )");
        } catch (\Throwable $e) {}
    }

    /**
     * Main entry point: route an incoming WhatsApp message.
     *
     * @return array{handled: bool, routed_to: string, response: string|null}
     */
    public function route(array $message, array $value, int $companyId, int $contactId = 0): array
    {
        $this->companyId = $companyId;
        $this->startTime = microtime(true);
        $this->currentMessage = $message;

        $from = (string)($message['from'] ?? '');
        $type = (string)($message['type'] ?? 'text');

        // Non-actionable types: stickers, reactions, location pins, vCard shares, orders.
        // These carry no text body and must NEVER trigger keyword bots or AI replies.
        // Store them in the messages table (done in webhook.php) but stop routing here.
        if (in_array($type, ['sticker', 'reaction', 'location', 'contacts', 'order', 'unsupported'], true)) {
            return ['handled' => false, 'routed_to' => 'none', 'response' => null];
        }

        // Extract text based on message type
        $rawText = '';
        if ($type === 'text') {
            $rawText = trim((string)($message['text']['body'] ?? ''));
        } elseif ($type === 'interactive') {
            $intType = $message['interactive']['type'] ?? '';
            if ($intType === 'button_reply') {
                $rawText = trim((string)($message['interactive']['button_reply']['title'] ?? ''));
            } elseif ($intType === 'list_reply') {
                $rawText = trim((string)($message['interactive']['list_reply']['title'] ?? ''));
            } else {
                $rawText = trim((string)($message['interactive'][$intType]['title'] ?? ''));
            }
        }

        // Handle media message types (audio/voice/image/video/document).
        // These have no text body so keyword matching cannot run.
        // Send a configurable bot reply (keyword = "[audio]") or a default acknowledgment.
        if (in_array($type, ['audio', 'voice', 'image', 'video', 'document'], true)) {
            $this->fromDigits = preg_replace('/[^0-9]/', '', $from);
            $this->resolveContact($contactId, $from);

            if ($this->companyId <= 0) {
                return ['handled' => false, 'routed_to' => 'none', 'response' => null];
            }

            $welcome = $this->sendWelcomeIfFirst();

            // Check if admin has set up a bot reply keyed on "[audio]", "[image]", etc.
            $mediaResult = $this->checkBotRepliesByMediaType($type);
            if ($mediaResult['handled']) {
                $this->logRouting('[' . $type . ']', 'bot_reply', $mediaResult);
                return $mediaResult;
            }

            // Voice note + live WAAMINI Bot + an AI that can transcribe: the bot answers the transcript
            // (webhook queued it in ai_voice_jobs), so no generic acknowledgment.
            if (($type === 'audio' || $type === 'voice') && $this->contactId > 0) {
                try {
                    require_once __DIR__ . '/voice_ai.php';
                    if (WB_Guard::isLive($this->conn, $this->companyId) && voice_ai_available($this->conn)) {
                        return ['handled' => true, 'routed_to' => 'waamini_bot_voice', 'response' => null];
                    }
                } catch (\Throwable $e) {
                    error_log('[SmartRouter] voice bot check: ' . $e->getMessage());
                }
            }

            // Default acknowledgment for voice/audio
            if ($type === 'audio' || $type === 'voice') {
                $ackMsg = "🎤 We have received your voice note. Our team will listen and respond to you shortly.";
                $this->sendReply($ackMsg, 'text');
                return ['handled' => true, 'routed_to' => 'voice_ack', 'response' => $ackMsg];
            }

            return $welcome ?? ['handled' => false, 'routed_to' => 'none', 'response' => null];
        }

        if ($rawText === '') {
            return ['handled' => false, 'routed_to' => 'none', 'response' => null];
        }

        $this->fromDigits = preg_replace('/[^0-9]/', '', $from);

        // Resolve contact info
        $this->resolveContact($contactId, $from);

        if ($this->companyId <= 0) {
            return ['handled' => false, 'routed_to' => 'none', 'response' => null];
        }

        // Normalize text for matching (case-insensitive)
        $textLower = mb_strtolower($rawText, 'UTF-8');

        // First message from a new customer? Send the Welcome rule (other rules still run after it)
        $welcome = $this->sendWelcomeIfFirst();

        // ══════════════════════════════════════════════════════════════
        // STEP 0: Check for active Flow sessions (continuation takes
        //         highest priority — user is mid-conversation in a flow)
        // ══════════════════════════════════════════════════════════════
        if ($this->contactId > 0) {
            $result = $this->checkActiveFlowSession($textLower, $rawText);
            if ($result['handled']) {
                return $result;
            }
        }

        // ══════════════════════════════════════════════════════════════
        // PRIORITY 1: Bot Replies — Exact keyword match (case-insensitive)
        // ══════════════════════════════════════════════════════════════
        $result = $this->checkBotReplies($textLower, $rawText);
        if ($result['handled']) {
            $this->logRouting($rawText, 'bot_reply', $result);
            return $result;
        }

        // ══════════════════════════════════════════════════════════════
        // PRIORITY 2: Flow Builder — published flows whose trigger matches (priority order)
        // ══════════════════════════════════════════════════════════════
        $result = $this->checkFlowBuilder($textLower, $rawText);
        if ($result['handled']) {
            $this->logRouting($rawText, 'flow_builder', $result);
            return $result;
        }

        // PRIORITY 2b: a published "default fallback" flow answers anything nothing else handled
        $result = $this->runFlowEngine('fallback');
        if ($result['handled']) {
            $this->logRouting($rawText, 'flow_builder', $result);
            return $result;
        }

        // "New contact" and "Any message" triggers are evaluated by FlowEngine (runFlowEngine above).

        // ══════════════════════════════════════════════
        // PRIORITY 3: WAAMINI Bot (one AI bot per client, shared OpenAI key).
        // Only runs when the platform switch, the admin switch and the client's
        // switch are all on. Otherwise the chat follows the existing path unchanged.
        // ══════════════════════════════════════════════
        try {
            require_once __DIR__ . '/waamini_bot/bootstrap.php';
            if ($this->contactId > 0 && WB_Guard::isLive($this->conn, $this->companyId)) {
                $wamid = (string)($message['id'] ?? '');
                if (WB_Guard::enqueueIfEligible($this->conn, $this->companyId, $this->contactId, $wamid)) {
                    $botResult = ['handled' => true, 'routed_to' => 'waamini_bot', 'response' => '[WAAMINI Bot reply queued]'];
                    $this->logRouting($rawText, 'waamini_bot', $botResult);
                    return $botResult;
                }
                // Live bot but not allowed to answer this chat now (human has it, paused, limit reached):
                // the old AI Sales Bot must stay silent too, so a person or nobody answers, never two bots.
                $this->logRouting($rawText, 'none', ['handled' => false, 'response' => null]);
                return $welcome ?? ['handled' => false, 'routed_to' => 'none', 'response' => null];
            }
        } catch (\Throwable $e) {
            error_log('[SMART ROUTER] WAAMINI Bot error: ' . $e->getMessage());
        }

        // ══════════════════════════════════════════════════════════════
        // PRIORITY 3: AI Sales Bot — GPT / Gemini fallback
        // ══════════════════════════════════════════════════════════════
        $result = $this->enqueueAiSalesBot($rawText);
        if ($result['handled']) {
            $this->logRouting($rawText, 'ai_sales_bot', $result);
            return $result;
        }

        $this->logRouting($rawText, 'none', ['handled' => false, 'response' => null]);
        return $welcome ?? ['handled' => false, 'routed_to' => 'none', 'response' => null];
    }

    // ══════════════════════════════════════════════════════════════════
    // MEDIA TYPE REPLIES (audio, image, video, document)
    // Looks for a bot_reply whose keyword is "[audio]", "[image]", etc.
    // ══════════════════════════════════════════════════════════════════
    private function checkBotRepliesByMediaType(string $msgType): array
    {
        $bracketKey = '[' . $msgType . ']';
        $stmt = $this->conn->prepare(
            "SELECT * FROM bot_replies
             WHERE company_id = ? AND is_active = 1
             AND (LOWER(COALESCE(keyword,'')) = ? OR LOWER(COALESCE(trigger_keyword,'')) = ?)
             ORDER BY priority DESC, id ASC LIMIT 1"
        );
        if (!$stmt) {
            return ['handled' => false, 'routed_to' => 'bot_reply', 'response' => null];
        }
        $k = mb_strtolower($bracketKey, 'UTF-8');
        $stmt->bind_param('iss', $this->companyId, $k, $k);
        $stmt->execute();
        $rule = $stmt->get_result()->fetch_assoc();

        if (!$rule) {
            return ['handled' => false, 'routed_to' => 'bot_reply', 'response' => null];
        }

        $msg = (string)($rule['reply_content'] ?? $rule['reply_message'] ?? '');
        $msg = $this->substituteVariables($msg);
        $this->sendBotReply($rule, $msg);

        return [
            'handled'         => true,
            'routed_to'       => 'bot_reply',
            'response'        => $msg,
            'rule_id'         => (int)$rule['id'],
            'matched_keyword' => $bracketKey,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // PRIORITY 1: Bot Replies
    // ══════════════════════════════════════════════════════════════════
    private function checkBotReplies(string $textLower, string $rawText): array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM bot_replies
             WHERE company_id = ? AND is_active = 1
             ORDER BY priority DESC, id ASC
             LIMIT 200"
        );
        if (!$stmt) {
            error_log('[SMART ROUTER] bot_replies query failed: ' . $this->conn->error);
            return ['handled' => false, 'routed_to' => 'bot_reply', 'response' => null];
        }

        $stmt->bind_param('i', $this->companyId);
        $stmt->execute();
        $rules = $stmt->get_result();

        while ($rules && ($rule = $rules->fetch_assoc())) {
            $matchType = (string)($rule['match_type'] ?? 'exact');
            // Welcome rules are sent once to brand-new customers (see sendWelcomeIfFirst), never by keyword
            if ($matchType === 'welcome') continue;

            $rawKeywords = mb_strtolower(trim((string)($rule['keyword'] ?? $rule['trigger_keyword'] ?? '')), 'UTF-8');
            if ($rawKeywords === '') continue;

            $keywords = array_map('trim', explode(',', $rawKeywords));
            $matched = false;

            foreach ($keywords as $kw) {
                if ($kw === '') continue;
                if (bt_text_matches($matchType, $kw, $textLower)) { $matched = true; break; }
            }

            if (!$matched) continue;

            // Action triggers change the customer's settings first, then (optionally) confirm with a reply
            if (bt_is_action_type($matchType)) {
                bt_apply_action($this->conn, $this->companyId, $this->contactId, $this->fromDigits, $matchType);
            }

            // Check 24h conversation window
            if (!$this->isWindowOpen() && strtolower((string)($rule['reply_type'] ?? '')) !== 'template') {
                continue;
            }

            // Book Demo / Meeting: with a time in the message, book it and send the real Google Meet confirmation
            if (bt_is_demo_type($matchType) && $this->contactId > 0) {
                $demoReply = $this->tryBookDemo($rawText);
                if ($demoReply !== null) {
                    $sendResult = $this->sendBotReply($rule, $demoReply);
                    return [
                        'handled' => true,
                        'routed_to' => 'bot_reply',
                        'response' => $demoReply,
                        'rule_id' => (int)$rule['id'],
                        'matched_keyword' => $kw ?? '',
                    ];
                }
            }

            // Build response message with variable substitution
            $msg = (string)($rule['reply_content'] ?? $rule['reply_message'] ?? '');
            $msg = $this->substituteVariables($msg);

            // Send via WhatsApp
            $sendResult = $this->sendBotReply($rule, $msg);

            return [
                'handled' => true,
                'routed_to' => 'bot_reply',
                'response' => $msg,
                'rule_id' => (int)$rule['id'],
                'matched_keyword' => $kw ?? '',
            ];
        }

        return ['handled' => false, 'routed_to' => 'bot_reply', 'response' => null];
    }

    /** Books a demo + Google Meet from a customer's message; null when it has no time or booking fails. */
    private function tryBookDemo(string $rawText): ?string
    {
        try {
            $agentFile = dirname(__DIR__) . '/waamini_ai_agent.php';
            if (!file_exists($agentFile)) return null;
            require_once $agentFile;
            $agent = new WaaminiAiAgent($this->conn, $this->companyId, 'deterministic-demo');
            $phone = $this->fromDigits !== '' ? $this->fromDigits : '';
            if ($phone === '') return null;
            $reply = $agent->bookDemoFromMessage($phone, $rawText);
            return ($reply !== null && trim($reply) !== '') ? $reply : null;
        } catch (\Throwable $e) {
            error_log('[SMART ROUTER] demo booking failed: ' . $e->getMessage());
            return null;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // WELCOME: the active "Welcome" rule is sent once, on a customer's first message
    // ══════════════════════════════════════════════════════════════════
    private function sendWelcomeIfFirst(): ?array
    {
        if ($this->contactId <= 0 || !bt_is_first_inbound($this->conn, $this->companyId, $this->contactId)) {
            return null;
        }
        $stmt = $this->conn->prepare(
            "SELECT * FROM bot_replies
             WHERE company_id = ? AND is_active = 1 AND match_type = 'welcome'
             ORDER BY priority DESC, id ASC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $this->companyId);
        $stmt->execute();
        $rule = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rule) return null;

        $msg = $this->substituteVariables((string)($rule['reply_content'] ?? $rule['reply_message'] ?? ''));
        $this->sendBotReply($rule, $msg);
        $res = ['handled' => true, 'routed_to' => 'bot_reply', 'response' => $msg, 'rule_id' => (int)$rule['id'], 'matched_keyword' => 'welcome'];
        $this->logRouting('[first message]', 'bot_reply', $res);
        return $res;
    }

    // ══════════════════════════════════════════════════════════════════
    // PRIORITY 2: Flow Builder
    // ══════════════════════════════════════════════════════════════════
    private function checkFlowBuilder(string $textLower, string $rawText): array
    {
        return $this->runFlowEngine('main');
    }

    /**
     * Check if a keyword exists in bot_replies (to prevent flow builder from hijacking it)
     */
    private function isKeywordInBotReplies(string $kw): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT id FROM bot_replies
             WHERE company_id = ? AND is_active = 1
             AND LOWER(keyword) LIKE ?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $pattern = '%' . $kw . '%';
        $stmt->bind_param('is', $this->companyId, $pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            // Also check trigger_keyword column if it exists
            $stmt2 = $this->conn->prepare(
                "SELECT id FROM bot_replies
                 WHERE company_id = ? AND is_active = 1
                 AND LOWER(trigger_keyword) LIKE ?
                 LIMIT 1"
            );
            if ($stmt2) {
                $stmt2->bind_param('is', $this->companyId, $pattern);
                $stmt2->execute();
                $row = $stmt2->get_result()->fetch_assoc();
            }
        }

        return (bool)$row;
    }

    // ══════════════════════════════════════════════════════════════════
    // FLOW SESSION MANAGEMENT
    // ══════════════════════════════════════════════════════════════════
    private function checkActiveFlowSession(string $textLower, string $rawText): array
    {
        return $this->runFlowEngine('resume');
    }

    /**
     * Flow Builder entry point. The canvas graph (flow_nodes / flow_edges) is executed by FlowEngine:
     * 'resume' continues an open session, 'main' also starts a flow whose trigger matches, 'fallback' runs the default flow.
     */
    private function runFlowEngine(string $phase): array
    {
        $none = ['handled' => false, 'routed_to' => 'flow_builder', 'response' => null];
        if ($this->contactId <= 0 || !$this->currentMessage) return $none;
        try {
            require_once __DIR__ . '/flow/engine.php';
            require_once __DIR__ . '/flow/worker.php';
            flow_kick_worker();   // throttled background tick: overdue delays / follow-ups
            $first = bt_is_first_inbound($this->conn, $this->companyId, $this->contactId);
            $in = flow_inbound_from_message($this->currentMessage, $first);
            $sender = new FlowWaSender($this->conn, $this->companyId, $this->contactId, $this->fromDigits);
            $r = (new FlowEngine($this->conn, $this->companyId, $this->contactId, $sender))->handleInbound($in, $phase);
            if (!empty($r['handled'])) {
                return ['handled' => true, 'routed_to' => 'flow_builder', 'response' => 'Flow ' . $r['reason'], 'rule_id' => (int)$r['flow_id']];
            }
        } catch (Throwable $e) {
            error_log('[SmartRouter] flow engine: ' . $e->getMessage());
        }
        return $none;
    }

    private function startFlow(array $flow): bool
    {
        $flowId = (int)$flow['id'];

        // Find start node
        $stmt = $this->conn->prepare(
            "SELECT id FROM chatbot_nodes
             WHERE flow_id = ?
             ORDER BY (node_type = 'start') DESC, id ASC
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('i', $flowId);
        $stmt->execute();
        $firstNode = $stmt->get_result()->fetch_assoc();

        if (!$firstNode) return false;

        $nodeId = (int)$firstNode['id'];

        // Create or update session
        $stmt2 = $this->conn->prepare(
            "INSERT INTO chatbot_sessions (company_id, contact_id, flow_id, current_node_id, status)
             VALUES (?, ?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE flow_id = ?, current_node_id = ?, status = 'active', updated_at = NOW()"
        );
        if (!$stmt2) return false;

        $stmt2->bind_param('iiiiii', $this->companyId, $this->contactId, $flowId, $nodeId, $flowId, $nodeId);
        $stmt2->execute();

        // Execute first node
        $this->executeNode($nodeId);
        return true;
    }

    private function continueFlow(array $session, string $textLower, string $rawText): array
    {
        $currentNodeId = (int)$session['current_node_id'];
        $sessionId = (int)$session['id'];

        $stmt = $this->conn->prepare("SELECT * FROM chatbot_nodes WHERE id = ?");
        if (!$stmt) {
            $this->endSession($sessionId);
            return ['handled' => false];
        }

        $stmt->bind_param('i', $currentNodeId);
        $stmt->execute();
        $node = $stmt->get_result()->fetch_assoc();

        if (!$node) {
            $this->endSession($sessionId);
            return ['handled' => false];
        }

        $nextNodeId = null;

        if ($node['node_type'] === 'button') {
            // Match button text (case-insensitive)
            $stmtC = $this->conn->prepare(
                "SELECT target_node_id FROM chatbot_node_connections
                 WHERE node_id = ? AND LOWER(trigger_value) = ?"
            );
            if ($stmtC) {
                $stmtC->bind_param('is', $currentNodeId, $textLower);
                $stmtC->execute();
                $conn = $stmtC->get_result()->fetch_assoc();
                $nextNodeId = $conn['target_node_id'] ?? null;
            }
        } else {
            $nextNodeId = $node['next_node_id'] ?? null;
        }

        if ($nextNodeId) {
            $updStmt = $this->conn->prepare(
                "UPDATE chatbot_sessions SET current_node_id = ?, updated_at = NOW() WHERE id = ?"
            );
            if ($updStmt) {
                $updStmt->bind_param('ii', $nextNodeId, $sessionId);
                $updStmt->execute();
            }

            $this->executeNode((int)$nextNodeId);

            return [
                'handled' => true,
                'routed_to' => 'flow_builder',
                'response' => 'Flow continued',
            ];
        }

        $this->endSession($sessionId);
        return ['handled' => false];
    }

    private function executeNode(int $nodeId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT n.*, t.name as template_name, t.language as template_lang
             FROM chatbot_nodes n
             LEFT JOIN message_templates t ON n.template_id = t.id
             WHERE n.id = ?"
        );
        if (!$stmt) return;

        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $node = $stmt->get_result()->fetch_assoc();

        if (!$node) return;

        $msg = $this->substituteVariables((string)($node['message_text'] ?? ''));
        $type = (string)($node['node_type'] ?? 'message');

        if ($type === 'template' && !empty($node['template_name'])) {
            $this->sendReply($node['template_name'] . ':' . ($node['template_lang'] ?: 'en'), 'template');
        } elseif ($type === 'button') {
            $btns = json_decode((string)($node['button_options'] ?? ''), true);
            if ($btns && !empty($btns)) {
                $this->sendButtonMessage($msg, $btns);
            } else {
                $this->sendReply($msg, 'text');
            }
        } elseif ($type === 'end') {
            if (!empty($msg)) {
                $this->sendReply($msg, 'text');
            }
            $this->endSessionByContact();
        } else {
            if (!empty($msg)) {
                $this->sendReply($msg, 'text');
            }
        }

        // Auto-advance for non-blocking nodes
        if (in_array($type, ['start', 'message', 'template']) && !empty($node['next_node_id'])) {
            $nextNodeId = (int)$node['next_node_id'];
            $updStmt = $this->conn->prepare(
                "UPDATE chatbot_sessions SET current_node_id = ?, updated_at = NOW()
                 WHERE company_id = ? AND contact_id = ? AND status = 'active'"
            );
            if ($updStmt) {
                $updStmt->bind_param('iii', $nextNodeId, $this->companyId, $this->contactId);
                $updStmt->execute();
            }
            $this->executeNode($nextNodeId);
        }
    }

    private function endSession(int $sessionId): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE chatbot_sessions SET status = 'completed', updated_at = NOW() WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
        }
    }

    private function endSessionByContact(): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE chatbot_sessions SET status = 'completed', updated_at = NOW()
             WHERE company_id = ? AND contact_id = ? AND status = 'active'"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $this->companyId, $this->contactId);
            $stmt->execute();
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // PRIORITY 3: AI Sales Bot
    // ══════════════════════════════════════════════════════════════════
    private function enqueueAiSalesBot(string $rawText): array
    {
        // Check if AI bot is enabled for this company
        $aiEnabled = false;
        $aiProvider = 'gemini';

        // Check ai_bot_settings first
        $stmt = $this->conn->prepare("SELECT * FROM ai_bot_settings WHERE company_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $this->companyId);
            $stmt->execute();
            $settings = $stmt->get_result()->fetch_assoc();

            if ($settings) {
                $isActive = array_key_exists('is_active', $settings) ? ((int)$settings['is_active'] === 1) : true;
                $hasKey = !empty($settings['openai_api_key']) || !empty($settings['gemini_api_key']);

                if ($isActive && $hasKey) {
                    $aiEnabled = true;
                    $aiProvider = (string)($settings['ai_provider'] ?? 'openai');
                }
            }
        }

        // Also check bot_settings table
        if (!$aiEnabled) {
            $stmt2 = $this->conn->prepare("SELECT ai_enabled FROM bot_settings WHERE company_id = ? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param('i', $this->companyId);
                $stmt2->execute();
                $bs = $stmt2->get_result()->fetch_assoc();
                if ((int)($bs['ai_enabled'] ?? 0) === 1) {
                    $aiEnabled = true;
                }
            }
        }

        if (!$aiEnabled) {
            return ['handled' => false, 'routed_to' => 'ai_sales_bot', 'response' => null];
        }

        // Detect language for the AI prompt
        $resolvedLang = waamini_resolve_reply_language_iso($rawText, null);
        $payloadArr = [
            'input_lang' => $resolvedLang,
            'routed_by' => 'smart_router',
        ];
        $payloadJson = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);

        // De-duplicate: skip if identical pending job exists within 30 seconds
        $chk = $this->conn->prepare(
            "SELECT id FROM automation_queue
             WHERE company_id = ? AND contact_id = ? AND type = 'ai_reply'
             AND status = 'pending' AND message = ?
             AND created_at >= (NOW() - INTERVAL 30 SECOND)
             LIMIT 1"
        );
        if ($chk) {
            $chk->bind_param('iis', $this->companyId, $this->contactId, $rawText);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                // Already queued
                return [
                    'handled' => true,
                    'routed_to' => 'ai_sales_bot',
                    'response' => '[AI reply already queued]',
                ];
            }
        }

        // Enqueue AI reply
        $typeAi = 'ai_reply';
        $pending = 'pending';
        $q = $this->conn->prepare(
            "INSERT INTO automation_queue (company_id, contact_id, type, message, status, scheduled_at, created_at, payload_json)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)"
        );

        $queued = false;
        if ($q) {
            $q->bind_param('iissss', $this->companyId, $this->contactId, $typeAi, $rawText, $pending, $payloadJson);
            if ($q->execute()) {
                $queued = true;
            }
        }

        // Fallback without payload_json
        if (!$queued) {
            $q2 = $this->conn->prepare(
                "INSERT INTO automation_queue (company_id, contact_id, type, message, status, scheduled_at, created_at)
                 VALUES (?, ?, 'ai_reply', ?, 'pending', NOW(), NOW())"
            );
            if ($q2) {
                $q2->bind_param('iis', $this->companyId, $this->contactId, $rawText);
                $queued = $q2->execute();
            }
        }

        if ($queued) {
            // Auto-process on localhost
            $this->autoProcessOnLocal();

            return [
                'handled' => true,
                'routed_to' => 'ai_sales_bot',
                'response' => '[AI reply queued - provider: ' . $aiProvider . ']',
            ];
        }

        return ['handled' => false, 'routed_to' => 'ai_sales_bot', 'response' => null];
    }

    // ══════════════════════════════════════════════════════════════════
    // SENDING HELPERS
    // ══════════════════════════════════════════════════════════════════
    private function sendBotReply(array $rule, string $msg): array
    {
        $replyType = strtolower((string)($rule['reply_type'] ?? 'text'));

        if (file_exists(__DIR__ . '/whatsapp_settings.php')) {
            require_once __DIR__ . '/whatsapp_settings.php';
        }
        if (file_exists(__DIR__ . '/whatsappapi.php')) {
            require_once __DIR__ . '/whatsappapi.php';
        }

        $result = ['success' => false];

        if (class_exists('WhatsAppAPI')) {
            $wa = new WhatsAppAPI($this->companyId);

            if ($replyType === 'text') {
                $result = $wa->sendTextMessage($this->fromDigits, $msg);
            } elseif ($replyType === 'media') {
                $result = $wa->sendMediaMessage(
                    $this->fromDigits,
                    $rule['header_type'] ?? 'image',
                    $rule['media_url'] ?? '',
                    $msg
                );
            } elseif ($replyType === 'interactive') {
                $iData = json_decode((string)($rule['interactive_data'] ?? ''), true);
                $result = $wa->sendAdvancedInteractive(
                    $this->fromDigits,
                    $msg,
                    $iData['buttons'] ?? [],
                    $rule['footer_text'] ?? '',
                    $rule['header_type'] ?? '',
                    $rule['media_url'] ?? ''
                );
            } elseif ($replyType === 'template') {
                $tplId = (int)($rule['template_id'] ?? 0);
                $tpl = null;
                $stT = $this->conn->prepare("SELECT name, language, body FROM message_templates WHERE id = ?");
                if ($stT) {
                    $stT->bind_param('i', $tplId);
                    $stT->execute();
                    $tpl = $stT->get_result()->fetch_assoc();
                }
                if ($tpl) {
                    $components = [];
                    if (strpos($tpl['body'] ?? '', '{{1}}') !== false) {
                        $components[] = [
                            'type' => 'body',
                            'parameters' => [['type' => 'text', 'text' => $this->contactName ?: 'Customer']],
                        ];
                    }
                    $result = $wa->sendTemplateMessage($this->fromDigits, $tpl['name'], $tpl['language'] ?: 'en', $components);
                    if (is_array($result)) {
                        $result['rule_type'] = 'bot_reply';
                        $result['template_name'] = $tpl['name'] ?? '';
                    }
                }
            }
        } else {
            // Fallback: direct cURL
            $result = $this->directSend($msg);
            if (is_array($result)) {
                $result['rule_type'] = 'bot_reply';
            }
        }

        // Log outgoing message
        $this->logOutgoingMessage($msg, $result);

        return $result;
    }

    private function sendReply(string $message, string $replyType = 'text'): void
    {
        if (file_exists(__DIR__ . '/whatsappapi.php')) {
            require_once __DIR__ . '/whatsappapi.php';
        }

        $newMsgId = 0;
        if ($this->contactId > 0) {
            $ins = $this->conn->prepare(
                "INSERT INTO messages (company_id, contact_id, direction, type, content, message, is_read, status, created_at)
                 VALUES (?, ?, 'outgoing', 'text', ?, ?, 1, 'sending', NOW())"
            );
            if ($ins) {
                $ins->bind_param('iiss', $this->companyId, $this->contactId, $message, $message);
                $ins->execute();
                $newMsgId = (int)$this->conn->insert_id;
            }
        }

        if ($replyType === 'template') {
            $parts = explode(':', $message, 2);
            $tplName = trim($parts[0] ?? '');
            $lang = trim($parts[1] ?? 'en');
            $res = $this->directSendTemplate($tplName, $lang);
        } else {
            $res = $this->directSend($message);
        }

        if ($newMsgId > 0) {
            if (!empty($res['id'])) {
                $upd = $this->conn->prepare("UPDATE messages SET status = 'sent', whatsapp_message_id = ? WHERE id = ?");
                if ($upd) {
                    $upd->bind_param('si', $res['id'], $newMsgId);
                    $upd->execute();
                }
            } else {
                $err = (string)($res['error'] ?? 'send_failed');
                $upd = $this->conn->prepare("UPDATE messages SET status = 'failed', error_message = ? WHERE id = ?");
                if ($upd) {
                    $upd->bind_param('si', $err, $newMsgId);
                    $upd->execute();
                }
            }
        }
    }

    private function sendButtonMessage(string $message, array $buttons): void
    {
        if (file_exists(__DIR__ . '/whatsappapi.php')) {
            require_once __DIR__ . '/whatsappapi.php';
        }

        if (class_exists('WhatsAppAPI') && method_exists('WhatsAppAPI', 'sendButtonMessage')) {
            $wa = new WhatsAppAPI($this->companyId);
            $wa->sendButtonMessage($this->fromDigits, $message, $buttons);
        } else {
            // Fallback: send as text with buttons listed
            $text = $message . "\n";
            foreach ($buttons as $btn) {
                $text .= "\n• " . ((string)($btn['text'] ?? $btn['title'] ?? ''));
            }
            $this->sendReply($text, 'text');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // WHATSAPP API HELPERS
    // ══════════════════════════════════════════════════════════════════
    private function getWhatsAppConfig(): array
    {
        $token = '';
        $phoneId = '';
        $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';

        $cfg = $this->conn->prepare(
            "SELECT access_token, phone_number_id, api_version
             FROM whatsapp_config
             WHERE company_id = ?
             ORDER BY updated_at DESC, id DESC LIMIT 1"
        );
        if ($cfg) {
            $cfg->bind_param('i', $this->companyId);
            $cfg->execute();
            $row = $cfg->get_result()->fetch_assoc();
            $token = (string)($row['access_token'] ?? '');
            $phoneId = (string)($row['phone_number_id'] ?? '');
            $apiVer = (string)($row['api_version'] ?? $apiVer);
        }

        if ($token !== '' && function_exists('decrypt_secret')) {
            $dec = decrypt_secret($token);
            if ($dec !== null && $dec !== '') {
                $token = $dec;
            }
        }

        if ($token === '' || $phoneId === '') {
            $token = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
            $phoneId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
        }

        return ['token' => $token, 'phone_id' => $phoneId, 'api_version' => $apiVer];
    }

    private function directSend(string $message): array
    {
        $config = $this->getWhatsAppConfig();
        if ($config['token'] === '' || $config['phone_id'] === '') {
            return ['id' => null, 'error' => 'missing_whatsapp_config'];
        }

        $url = "https://graph.facebook.com/{$config['api_version']}/{$config['phone_id']}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->fromDigits,
            'type' => 'text',
            'text' => ['body' => substr($message, 0, 4096)],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['token'],
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        $waId = $data['messages'][0]['id'] ?? null;
        if ($http >= 200 && $http < 300 && $waId) {
            return ['id' => (string)$waId, 'error' => null, 'success' => true];
        }
        return ['id' => null, 'error' => $data['error']['message'] ?? 'HTTP ' . $http, 'success' => false];
    }

    private function directSendTemplate(string $templateName, string $lang): array
    {
        $config = $this->getWhatsAppConfig();
        if ($config['token'] === '' || $config['phone_id'] === '') {
            return ['id' => null, 'error' => 'missing_whatsapp_config'];
        }

        $url = "https://graph.facebook.com/{$config['api_version']}/{$config['phone_id']}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->fromDigits,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => ($lang !== '' ? $lang : 'en')],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['token'],
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        $waId = $data['messages'][0]['id'] ?? null;
        if ($http >= 200 && $http < 300 && $waId) {
            return ['id' => (string)$waId, 'error' => null, 'success' => true];
        }
        return ['id' => null, 'error' => $data['error']['message'] ?? 'HTTP ' . $http, 'success' => false];
    }

    // ══════════════════════════════════════════════════════════════════
    // UTILITY METHODS
    // ══════════════════════════════════════════════════════════════════
    private function resolveContact(int $passedContactId, string $from): void
    {
        $this->contactId = $passedContactId;
        $this->contactName = '';
        $this->contactPhone = '+' . preg_replace('/[^0-9]/', '', $from);

        if ($this->contactId > 0) {
            $st = $this->conn->prepare("SELECT company_id, name, phone FROM contacts WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $this->contactId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                if ($row) {
                    $this->contactName = (string)($row['name'] ?? '');
                    $this->contactPhone = (string)($row['phone'] ?? $this->contactPhone);
                    if ($this->companyId <= 0) {
                        $this->companyId = (int)$row['company_id'];
                    }
                }
            }
        } else {
            $fromDigits = preg_replace('/[^0-9]/', '', $from);
            $fromPlus = '+' . $fromDigits;
            $st = $this->conn->prepare("SELECT id, company_id, name, phone FROM contacts WHERE phone = ? OR phone = ? LIMIT 1");
            if ($st) {
                $st->bind_param('ss', $fromDigits, $fromPlus);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                if ($row) {
                    $this->contactId = (int)$row['id'];
                    $this->contactName = (string)($row['name'] ?? '');
                    $this->contactPhone = (string)($row['phone'] ?? $this->contactPhone);
                    if ($this->companyId <= 0) {
                        $this->companyId = (int)$row['company_id'];
                    }
                }
            }
        }
    }

    private function substituteVariables(string $msg): string
    {
        return str_replace(
            ['{{name}}', '{{phone}}', '{full_name}', '{phone_number}', '{{contact_name}}'],
            [$this->contactName ?: 'there', $this->contactPhone, $this->contactName, $this->contactPhone, $this->contactName ?: 'Customer'],
            $msg
        );
    }

    private function isWindowOpen(): bool
    {
        if ($this->contactId <= 0) return false;

        $stmt = $this->conn->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) as sec_ago
             FROM messages
             WHERE company_id = ? AND contact_id = ? AND direction = 'incoming'
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('ii', $this->companyId, $this->contactId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $secAgo = (int)($row['sec_ago'] ?? 999999);

        return ($secAgo >= 0 && $secAgo < 86400);
    }

    private function logOutgoingMessage(string $msg, array $result): void
    {
        if ($this->contactId <= 0) return;

        $status = ($result['success'] ?? false) ? 'sent' : 'failed';
        $waMid = $result['message_id'] ?? $result['id'] ?? null;
        $err = $result['error']['message'] ?? $result['error'] ?? null;

        $displayMsg = trim($msg);
        if ($displayMsg === '') {
            // For templates or empty rules, try to provide context
            $ruleType = $result['rule_type'] ?? 'bot_reply';
            if (($result['template_name'] ?? '') !== '') {
                $displayMsg = "[Template: {$result['template_name']}]";
            } else {
                $displayMsg = "[{$ruleType}]";
            }
        }

        $ins = $this->conn->prepare(
            "INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, whatsapp_message_id, error_message, created_at)
             VALUES (?, ?, 'outgoing', 'text', ?, ?, ?, ?, ?, NOW())"
        );
        if ($ins) {
            // Fixed: matched 7 placeholders with 7 types 'iisssss' and 7 variables
            $ins->bind_param('iisssss', $this->companyId, $this->contactId, $displayMsg, $displayMsg, $status, $waMid, $err);
            $ins->execute();
        }
    }

    private function logRouting(string $incomingMsg, string $routedTo, array $result): void
    {
        $elapsedMs = (int)((microtime(true) - $this->startTime) * 1000);

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO message_routing_log (company_id, contact_id, incoming_message, routed_to, matched_rule_id, matched_keyword, response_sent, response_time_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $ruleId = $result['rule_id'] ?? null;
                $matchedKw = $result['matched_keyword'] ?? null;
                $response = $result['response'] ?? null;
                $stmt->bind_param(
                    'iississi',
                    $this->companyId,
                    $this->contactId,
                    $incomingMsg,
                    $routedTo,
                    $ruleId,
                    $matchedKw,
                    $response,
                    $elapsedMs
                );
                $stmt->execute();
            }
        } catch (\Throwable $e) {
            // Best-effort logging
            error_log('[SMART ROUTER LOG] ' . $e->getMessage());
        }
    }

    private function autoProcessOnLocal(): void
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $appEnv = strtolower((string)(getenv('APP_ENV') ?: ''));
        $isLocal = ($host === '127.0.0.1' || $host === 'localhost' || str_starts_with($host, '127.0.0.1:') || str_starts_with($host, 'localhost:'));

        if ($appEnv === 'local' || $isLocal) {
            register_shutdown_function(static function () {
                $script = dirname(__DIR__) . '/cron_automation_sender.php';
                if (!file_exists($script)) return;
                try {
                    if (ob_get_level()) @ob_end_flush();
                } catch (\Throwable $e) {}
                @ob_start();
                try {
                    if (!defined('WAAMINI_QUEUE_WORKER')) define('WAAMINI_QUEUE_WORKER', 1);
                    
                    // Run AI / Automation Sender
                    if (file_exists($script)) {
                        require $script;
                    }
                    
                    // Run Drip Campaign Processor
                    $dripScript = dirname(__DIR__) . '/api/cron_drip.php';
                    if (file_exists($dripScript)) {
                        require $dripScript;
                    }

                    $tickScript = dirname(__DIR__) . '/api/drip_tick.php';
                    if (file_exists($tickScript)) {
                        require $tickScript;
                    }
                } catch (\Throwable $e) {
                    error_log('[SMART ROUTER] inline automation processor failed: ' . $e->getMessage());
                }
                @ob_end_clean();
            });
        }
    }
}

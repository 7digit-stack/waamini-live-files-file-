<?php
/**
 * WAAMINI - Chatbot Engine (Standalone)
 * Works with legacy $conn (mysqli) from db.php
 */

class ChatbotEngine {
    
    /**
     * Process incoming webhook message
     *
     * @return bool true when an active keyword rule matched and an auto-reply was sent (or attempted via sendReply)
     */
    public function processIncoming(array $message, array $value): bool {
        // Backwards-compatible entrypoint (company inferred from phone)
        return $this->processIncomingScoped($message, $value, 0);
    }

    /**
     * Preferred entrypoint: caller provides tenant scope.
     *
     * @return bool true when a keyword rule matched and sendReply ran (stops Premium AI enqueue for same inbound)
     */
    public function processIncomingScoped(array $message, array $value, int $companyId, int $passedContactId = 0): bool {
        global $conn; // Use legacy mysqli connection
        
        $from = $message['from'] ?? '';
        $type = $message['type'] ?? 'text';
        
        // Extract text based on type
        $text = '';
        if ($type === 'text') {
            $text = strtolower(trim($message['text']['body'] ?? ''));
        } elseif ($type === 'interactive') {
            $intType = $message['interactive']['type'] ?? '';
            $text = strtolower(trim($message['interactive'][$intType]['title'] ?? ''));
        }
        
        if (empty($text)) {
            return false;
        }
        
        $fromDigits = preg_replace('/[^0-9]/', '', (string)$from);
        $fromPlus = '+' . $fromDigits;

        // 1. Resolve Contact and Company
        $contactId = $passedContactId;
        $contactName = '';
        $contactPhone = $fromPlus;

        if ($contactId <= 0) {
            $cInfo = $conn->prepare("SELECT id, company_id, name, phone FROM contacts WHERE phone=? OR phone=? LIMIT 1");
            if ($cInfo) {
                $cInfo->bind_param("ss", $fromDigits, $fromPlus);
                $cInfo->execute();
                $row = $cInfo->get_result()->fetch_assoc();
                if ($row) {
                    $contactId = (int)$row['id'];
                    $contactName = (string)($row['name'] ?? '');
                    $contactPhone = (string)($row['phone'] ?? $contactPhone);
                    if ($companyId <= 0) $companyId = (int)$row['company_id'];
                }
            }
        } else {
            // Fetch basic details for the passed contact
            $st = $conn->prepare("SELECT company_id, name, phone FROM contacts WHERE id=? LIMIT 1");
            if ($st) {
                $st->bind_param("i", $contactId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                if ($row) {
                    $contactName = (string)($row['name'] ?? '');
                    $contactPhone = (string)($row['phone'] ?? $contactPhone);
                    if ($companyId <= 0) $companyId = (int)$row['company_id'];
                }
            }
        }

        if ($companyId <= 0) {
            return false;
        }

        // 2. Check for active Chatbot Flow sessions
        if ($contactId > 0) {
            $sessionStmt = $conn->prepare("SELECT * FROM chatbot_sessions WHERE company_id=? AND contact_id=? AND status='active' LIMIT 1");
            $sessionStmt->bind_param("ii", $companyId, $contactId);
            $sessionStmt->execute();
            $session = $sessionStmt->get_result()->fetch_assoc();

            if ($session) {
                return $this->continueFlow($session, $text, $fromDigits);
            }
        }

        // 3. Check for new Flow triggers
        $allFlowsStmt = $conn->prepare("SELECT * FROM chatbot_flows WHERE company_id=? AND is_active=1 AND trigger_type='keyword'");
        $allFlowsStmt->bind_param("i", $companyId);
        $allFlowsStmt->execute();
        $flows = $allFlowsStmt->get_result();

        while ($flows && ($flowTrigger = $flows->fetch_assoc())) {
            $keywords = explode(',', mb_strtolower($flowTrigger['trigger_keyword']));
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if (empty($kw)) continue;
                if (str_contains($text, $kw)) {
                    return $this->startFlow($flowTrigger, $contactId, $fromDigits);
                }
            }
        }

        // 4. Load active rules from bot_replies and match in PHP
        $rulesStmt = $conn->prepare("SELECT * FROM bot_replies WHERE company_id=? AND is_active=1 ORDER BY id DESC LIMIT 200");
        if (!$rulesStmt) {
            error_log('[CHATBOT ENGINE ERROR] bot_replies prepare failed: ' . $conn->error);
            return false;
        }
        $rulesStmt->bind_param("i", $companyId);
        $rulesStmt->execute();
        $rulesRes = $rulesStmt->get_result();

        // Enforce 24h conversation window
        $windowOpen = false;
        if ($contactId > 0) {
            $w = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) as secAgo FROM messages WHERE company_id=? AND contact_id=? AND direction='incoming' ORDER BY id DESC LIMIT 1");
            if ($w) {
                $w->bind_param('ii', $companyId, $contactId);
                $w->execute();
                $row = $w->get_result()->fetch_assoc();
                $secAgo = (int)($row['secAgo'] ?? 999999);
                $windowOpen = ($secAgo >= 0 && $secAgo < 86400);
            }
        }

        while ($rulesRes && ($r = $rulesRes->fetch_assoc())) {
            $rawKw = strtolower(trim((string)($r['keyword'] ?: $r['trigger_keyword'] ?: '')));
            if ($rawKw === '') continue;

            $keywords = explode(',', $rawKw);
            $mt = (string)($r['match_type'] ?? 'contains');
            $ok = false;

            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if ($kw === '') continue;

                if ($mt === 'exact') { 
                    if ($text === $kw) { $ok = true; break; }
                } elseif ($mt === 'starts_with') { 
                    if (str_starts_with($text, $kw)) { $ok = true; break; }
                } else { // contains
                    // Word boundary matching for Latin keywords (prevents 'hi' matching 'pathi')
                    $isLatinWord = (bool)preg_match('/^[a-z0-9_\\-]+$/', $kw);
                    if ($isLatinWord && strlen($kw) >= 2) {
                        if (preg_match('/\\b' . preg_quote($kw, '/') . '\\b/u', $text)) { $ok = true; break; }
                    } else {
                        if (strpos($text, $kw) !== false) { $ok = true; break; }
                    }
                }
            }

            if ($ok) {
                // If 24h window is closed, only allow template replies
                if (!$windowOpen && strtolower($r['reply_type']) !== 'template') {
                    continue; 
                }

                $msg = (string)($r['reply_content'] ?: $r['reply_message'] ?: '');
                $msg = str_replace(['{{name}}', '{{phone}}', '{full_name}', '{phone_number}'], [$contactName ?: 'there', $contactPhone, $contactName, $contactPhone], $msg);
                
                if (file_exists(__DIR__ . '/whatsapp_settings.php')) {
                    require_once __DIR__ . '/whatsapp_settings.php';
                }

                $wa = new WhatsAppAPI($companyId);
                $result = ['success' => false];

                if ($r['reply_type'] === 'text') {
                    $result = $wa->sendTextMessage($fromDigits, $msg);
                } elseif ($r['reply_type'] === 'media') {
                    $msg = "🖼️ Media (" . $r['header_type'] . "): " . $msg;
                    $result = $wa->sendMediaMessage($fromDigits, $r['header_type'], $r['media_url'], $msg);
                } elseif ($r['reply_type'] === 'interactive') {
                    $msg = "🔘 Interactive: " . $msg;
                    $idata = json_decode($r['interactive_data'], true);
                    $result = $wa->sendAdvancedInteractive($fromDigits, $msg, $idata['buttons'] ?? [], $r['footer_text'], $r['header_type'], $r['media_url']);
                } elseif ($r['reply_type'] === 'template') {
                    $tplId = intval($r['template_id']);
                    $stT = $conn->prepare("SELECT name, language, body, header_type, header_text FROM message_templates WHERE id=?");
                    $stT->bind_param("i", $tplId);
                    $stT->execute();
                    $tpl = $stT->get_result()->fetch_assoc();
                    if ($tpl) {
                        $msg = "📝 Template: " . $tpl['name'];
                        $components = [];
                        
                        // Handle Body Variable (e.g. {{1}} for name)
                        if (strpos($tpl['body'] ?? '', '{{1}}') !== false) {
                            $components[] = [
                                'type' => 'body',
                                'parameters' => [['type' => 'text', 'text' => $contactName ?: 'Customer']]
                            ];
                        }

                        $result = $wa->sendTemplateMessage($fromDigits, $tpl['name'], $tpl['language'] ?: 'en', $components);
                    }
                }

                // Log outgoing message
                if ($contactId > 0) {
                    $status = $result['success'] ? 'sent' : 'failed';
                    $waMid = $result['message_id'] ?? null;
                    $err = $result['error']['message'] ?? null;
                    $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, status, whatsapp_message_id, error_message, created_at) VALUES (?, ?, 'outgoing', 'text', ?, ?, ?, ?, ?, NOW())");
                    $ins->bind_param("iisssss", $companyId, $contactId, $msg, $msg, $status, $waMid, $err);
                    $ins->execute();
                }

                return true;
            }
        }

        return false;
    }

    private function startFlow($flow, $contactId, $toDigits): bool {
        global $conn;
        $companyId = (int)$flow['company_id'];
        $flowId = (int)$flow['id'];

        // Find the designated start node, or fallback to the first created node
        $stmt = $conn->prepare("SELECT id FROM chatbot_nodes WHERE flow_id=? ORDER BY (node_type='start') DESC, id ASC LIMIT 1");
        $stmt->bind_param("i", $flowId);
        $stmt->execute();
        $firstNode = $stmt->get_result()->fetch_assoc();

        if (!$firstNode) return false;

        $nodeId = (int)$firstNode['id'];

        // Create session
        $stmt_s = $conn->prepare("INSERT INTO chatbot_sessions (company_id, contact_id, flow_id, current_node_id, status) VALUES (?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE flow_id=?, current_node_id=?, status='active', updated_at=NOW()");
        $stmt_s->bind_param("iiiiii", $companyId, $contactId, $flowId, $nodeId, $flowId, $nodeId);
        $stmt_s->execute();

        $this->executeNode($companyId, $contactId, $nodeId, $toDigits);
        return true;
    }

    private function continueFlow($session, $text, $toDigits): bool {
        global $conn;
        $companyId = (int)$session['company_id'];
        $contactId = (int)$session['contact_id'];
        $currentNodeId = (int)$session['current_node_id'];

        // Check if current node is a 'question' or 'button' that expects input
        $stmt_n = $conn->prepare("SELECT * FROM chatbot_nodes WHERE id=?");
        $stmt_n->bind_param("i", $currentNodeId);
        $stmt_n->execute();
        $node = $stmt_n->get_result()->fetch_assoc();

        if (!$node) {
            $this->endSession($session['id']);
            return false;
        }

        $nextNodeId = null;

        if ($node['node_type'] === 'button') {
            // Match button text
            $stmt_c = $conn->prepare("SELECT target_node_id FROM chatbot_node_connections WHERE node_id=? AND trigger_value=?");
            $stmt_c->bind_param("is", $currentNodeId, $text);
            $stmt_c->execute();
            $connRow = $stmt_c->get_result()->fetch_assoc();
            $nextNodeId = $connRow['target_node_id'] ?? null;
        } else {
            // Linear flow
            $nextNodeId = $node['next_node_id'] ?? null;
        }

        if ($nextNodeId) {
            $stmt_upd = $conn->prepare("UPDATE chatbot_sessions SET current_node_id=?, updated_at=NOW() WHERE id=?");
            $stmt_upd->bind_param("ii", $nextNodeId, $session['id']);
            $stmt_upd->execute();

            $this->executeNode($companyId, $contactId, (int)$nextNodeId, $toDigits);
            return true;
        } else {
            $this->endSession((int)$session['id']);
            return false;
        }
    }

    private function executeNode($companyId, $contactId, $nodeId, $toDigits): void {
        global $conn;
        $stmt = $conn->prepare("SELECT n.*, t.name as template_name, t.language as template_lang 
                                FROM chatbot_nodes n 
                                LEFT JOIN message_templates t ON n.template_id = t.id 
                                WHERE n.id=?");
        $stmt->bind_param("i", $nodeId);
        $stmt->execute();
        $node = $stmt->get_result()->fetch_assoc();

        if (!$node) return;

        $msg = $node['message_text'];
        $type = $node['node_type'];

        if ($type === 'template' && !empty($node['template_name'])) {
            $msg = $node['template_name'] . ":" . ($node['template_lang'] ?: 'en');
            $this->sendReply($companyId, $contactId, $toDigits, $msg, 'template');
        } else if ($type === 'button') {
            // Interactive buttons
            $btns = json_decode($node['button_options'], true);
            if ($btns) {
                $this->sendButtonReply($companyId, $contactId, $toDigits, $msg, $btns);
            } else {
                $this->sendReply($companyId, $contactId, $toDigits, $msg, 'text');
            }
        } else if ($type === 'end') {
            $this->sendReply($companyId, $contactId, $toDigits, $msg, 'text');
            $this->endSessionByContact($companyId, $contactId);
        } else {
            // message, question, etc.
            if (!empty($msg)) {
                $this->sendReply($companyId, $contactId, $toDigits, $msg, 'text');
            }
        }

        // If it's a 'start', 'message' or 'template' (non-blocking), auto-advance if next_node exists
        if (in_array($type, ['start', 'message', 'template']) && !empty($node['next_node_id'])) {
            $nextNodeId = (int)$node['next_node_id'];
            $stmt_upd = $conn->prepare("UPDATE chatbot_sessions SET current_node_id=?, updated_at=NOW() WHERE company_id=? AND contact_id=? AND status='active'");
            $stmt_upd->bind_param("iii", $nextNodeId, $companyId, $contactId);
            $stmt_upd->execute();
            $this->executeNode($companyId, $contactId, $nextNodeId, $toDigits);
        }
    }

    private function endSession($sessionId): void {
        global $conn;
        $stmt = $conn->prepare("UPDATE chatbot_sessions SET status='completed', updated_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
    }

    private function endSessionByContact($companyId, $contactId): void {
        global $conn;
        $stmt = $conn->prepare("UPDATE chatbot_sessions SET status='completed', updated_at=NOW() WHERE company_id=? AND contact_id=? AND status='active'");
        $stmt->bind_param("ii", $companyId, $contactId);
        $stmt->execute();
    }

    private function sendButtonReply($companyId, $contactId, $toDigits, $message, $buttons): void {
        // Implementation for sending interactive buttons via WhatsApp API
        // For now, fall back to text if not fully implemented in WhatsAppAPI class
        $wa = new WhatsAppAPI();
        if (method_exists($wa, 'sendButtonMessage')) {
            $wa->sendButtonMessage($toDigits, $message, $buttons);
        } else {
            // Fallback: list buttons as text
            $text = $message . "\n";
            foreach ($buttons as $btn) {
                $text .= "\n• " . $btn['text'];
            }
            $this->sendReply($companyId, $contactId, $toDigits, $text, 'text');
        }
    }
    
    /**
     * Send reply via WhatsApp API
     */
    private function sendReply(int $companyId, int $contactId, string $toDigits, string $message, string $replyType = 'text'): void {
        global $conn;

        // Log outgoing message in DB (so it appears in inbox even if API fails)
        $newMsgId = 0;
        if ($contactId > 0) {
            $ins = $conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, message, is_read, status, created_at) VALUES (?, ?, 'outgoing', 'text', ?, 1, 'sending', NOW())");
            if ($ins) {
                $ins->bind_param("iis", $companyId, $contactId, $message);
                $ins->execute();
                $newMsgId = (int)$conn->insert_id;
            }
        }

        // Check if the WhatsAppAPI class exists and is safe to use
        if (class_exists('WhatsAppAPI') && method_exists('WhatsAppAPI', 'sendTextMessage')) {
            try {
                $wa = new WhatsAppAPI();
                $wa->sendTextMessage($toDigits, $message);
                if ($newMsgId > 0) {
                    $upd = $conn->prepare("UPDATE messages SET status='sent' WHERE id=? AND company_id=?");
                    if ($upd) { $upd->bind_param("ii", $newMsgId, $companyId); $upd->execute(); }
                }
                return;
            } catch (Exception $e) {
                error_log('[CHATBOT ENGINE ERROR] WhatsAppAPI failed: ' . $e->getMessage());
            }
        }
        
        // Fallback: Direct cURL call if class is broken/missing
        $rt = strtolower(trim($replyType));
        if ($rt === 'template') {
            // reply_content format: "template_name:lang" (lang optional, default en)
            $parts = explode(':', $message, 2);
            $tplName = trim($parts[0] ?? '');
            $lang = trim($parts[1] ?? 'en');
            $res = $this->directSendTemplate($companyId, $toDigits, $tplName, $lang);
        } else {
            $res = $this->directSend($companyId, $toDigits, $message);
        }
        if ($newMsgId > 0) {
            if (!empty($res['id'])) {
                $upd = $conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=? AND company_id=?");
                if ($upd) { $upd->bind_param("sii", $res['id'], $newMsgId, $companyId); $upd->execute(); }
            } else {
                $err = (string)($res['error'] ?? 'send_failed');
                $upd = $conn->prepare("UPDATE messages SET status='failed', error_message=? WHERE id=? AND company_id=?");
                if ($upd) { $upd->bind_param("sii", $err, $newMsgId, $companyId); $upd->execute(); }
            }
        }
    }
    
    /**
     * Fallback direct cURL sender (bypasses class dependencies)
     */
    private function directSend(int $companyId, string $toDigits, string $message): array {
        global $conn;

        // Prefer per-company config from DB
        $token = '';
        $phoneId = '';
        $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
        $cfg = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
        if ($cfg) {
            $cfg->bind_param("i", $companyId);
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
            // final fallback to env
            $token = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: '';
            $phoneId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '';
        }
        if ($token === '' || $phoneId === '') return ['id' => null, 'error' => 'missing_whatsapp_config'];
        
        $url = "https://graph.facebook.com/" . $apiVer . "/{$phoneId}/messages";
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $toDigits,
            'type' => 'text',
            'text' => ['body' => substr($message, 0, 4096)]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        $waId = $data['messages'][0]['id'] ?? null;
        if ($http >= 200 && $http < 300 && $waId) {
            return ['id' => (string)$waId, 'error' => null];
        }
        $errMsg = $data['error']['message'] ?? ('HTTP ' . $http);
        return ['id' => null, 'error' => (string)$errMsg];
    }

    /**
     * Direct template sender (simple templates without parameters).
     */
    private function directSendTemplate(int $companyId, string $toDigits, string $templateName, string $lang): array {
        global $conn;
        $templateName = trim($templateName);
        if ($templateName === '') return ['id' => null, 'error' => 'missing_template_name'];

        $token = '';
        $phoneId = '';
        $apiVer = $_ENV['WHATSAPP_API_VERSION'] ?? getenv('WHATSAPP_API_VERSION') ?: 'v18.0';
        $cfg = $conn->prepare("SELECT access_token, phone_number_id, api_version FROM whatsapp_config WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
        if ($cfg) {
            $cfg->bind_param("i", $companyId);
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
        if ($token === '' || $phoneId === '') return ['id' => null, 'error' => 'missing_whatsapp_config'];

        $url = "https://graph.facebook.com/" . $apiVer . "/{$phoneId}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $toDigits,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => ($lang !== '' ? $lang : 'en')],
            ]
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        $waId = $data['messages'][0]['id'] ?? null;
        if ($http >= 200 && $http < 300 && $waId) {
            return ['id' => (string)$waId, 'error' => null];
        }
        $errMsg = $data['error']['message'] ?? ('HTTP ' . $http);
        return ['id' => null, 'error' => (string)$errMsg];
    }
}
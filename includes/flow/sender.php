<?php
/**
 * Flow Builder: how the engine talks to WhatsApp.
 *
 * FlowSender is the small interface the engine needs. FlowWaSender is the production implementation: it reuses the
 * existing WhatsAppAPI class (credentials, retries, interactive payloads) and records every message in `messages`
 * the same way SmartRouter does, so flow messages appear in the inbox. FlowMockSender records calls for tests.
 *
 * Every method returns ['ok' => bool, 'id' => ?string, 'error' => ?string].
 */

interface FlowSender
{
    public function text(string $body): array;
    public function media(string $type, string $url, string $caption = '', string $filename = ''): array;
    public function buttons(string $body, array $buttons, string $footer = ''): array;
    public function lists(string $body, string $buttonText, array $rows, string $footer = ''): array;
    public function template(string $name, string $language, array $variables = []): array;
}

final class FlowWaSender implements FlowSender
{
    private mysqli $conn;
    private int $companyId;
    private int $contactId;
    private string $to;
    private ?WhatsAppAPI $api = null;

    public function __construct(mysqli $conn, int $companyId, int $contactId, string $toDigits)
    {
        $this->conn = $conn;
        $this->companyId = $companyId;
        $this->contactId = $contactId;
        $this->to = preg_replace('/\D/', '', $toDigits);
        require_once __DIR__ . '/../whatsappapi.php';
        require_once __DIR__ . '/../chat_message_render.php';
        wa_chat_ensure_meta_column($conn);
    }

    private function api(): WhatsAppAPI
    {
        if (!$this->api) $this->api = new WhatsAppAPI($this->companyId);
        return $this->api;
    }

    /** Insert the outgoing row first, update it with the Meta result afterwards. */
    private function record(string $shown, callable $send, string $type = 'text', ?string $mediaUrl = null, ?array $meta = null): array
    {
        $msgId = 0;
        if ($this->contactId > 0) {
            $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
            $st = $this->conn->prepare("INSERT INTO messages (company_id, contact_id, direction, type, content, message, media_url, meta_json, is_read, status, created_at) VALUES (?, ?, 'outgoing', ?, ?, ?, ?, ?, 1, 'sending', NOW())");
            if ($st) {
                $st->bind_param('iisssss', $this->companyId, $this->contactId, $type, $shown, $shown, $mediaUrl, $metaJson);
                $st->execute();
                $msgId = (int)$this->conn->insert_id;
                $st->close();
            }
        }
        $res = $send();
        $ok = !empty($res['success']);
        $waId = $ok ? (string)($res['message_id'] ?? '') : null;
        $err = $ok ? null : (string)($res['error']['message'] ?? 'send_failed');
        if ($msgId > 0) {
            if ($ok) {
                $u = $this->conn->prepare("UPDATE messages SET status='sent', whatsapp_message_id=? WHERE id=? AND company_id=?");
                if ($u) { $u->bind_param('sii', $waId, $msgId, $this->companyId); $u->execute(); $u->close(); }
            } else {
                $u = $this->conn->prepare("UPDATE messages SET status='failed', error_message=? WHERE id=? AND company_id=?");
                if ($u) { $u->bind_param('sii', $err, $msgId, $this->companyId); $u->execute(); $u->close(); }
            }
        }
        return ['ok' => $ok, 'id' => $waId, 'error' => $err];
    }

    public function text(string $body): array
    {
        return $this->record($body, fn() => $this->api()->sendTextMessage($this->to, $body));
    }

    public function media(string $type, string $url, string $caption = '', string $filename = ''): array
    {
        return $this->record($caption, fn() => $this->api()->sendMediaMessage($this->to, $type, $url, $caption, $filename), $type, $url, $filename !== '' ? ['filename' => $filename] : null);
    }

    public function buttons(string $body, array $buttons, string $footer = ''): array
    {
        return $this->record($body, fn() => $this->api()->sendAdvancedInteractive($this->to, $body, $buttons, $footer), 'interactive', null,
            ['kind' => 'buttons', 'buttons' => array_map(static fn($b) => ['id' => $b['id'] ?? '', 'title' => $b['title'] ?? ''], $buttons), 'footer' => $footer]);
    }

    public function lists(string $body, string $buttonText, array $rows, string $footer = ''): array
    {
        return $this->record($body, fn() => $this->api()->sendListMessage($this->to, $body, $buttonText, $rows, $footer), 'interactive', null,
            ['kind' => 'list', 'button' => $buttonText, 'rows' => array_map(static fn($r) => ['id' => $r['id'] ?? '', 'title' => $r['title'] ?? '', 'description' => $r['description'] ?? ''], $rows), 'footer' => $footer]);
    }

    public function template(string $name, string $language, array $variables = []): array
    {
        $components = [];
        if ($variables) {
            $components[] = ['type' => 'body', 'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => (string)$v], $variables)];
        }
        return $this->record('[template: ' . $name . ']', fn() => $this->api()->sendTemplateMessage($this->to, $name, $language ?: 'en', $components));
    }
}

/** Test double: records what would be sent. */
final class FlowMockSender implements FlowSender
{
    /** @var array<int,array> */
    public array $sent = [];
    public bool $fail = false;

    private function rec(string $kind, array $data): array
    {
        $this->sent[] = ['kind' => $kind] + $data;
        return $this->fail ? ['ok' => false, 'id' => null, 'error' => 'mock_failure'] : ['ok' => true, 'id' => 'mock_' . count($this->sent), 'error' => null];
    }
    public function text(string $body): array { return $this->rec('text', ['body' => $body]); }
    public function media(string $type, string $url, string $caption = '', string $filename = ''): array { return $this->rec($type, ['url' => $url, 'caption' => $caption]); }
    public function buttons(string $body, array $buttons, string $footer = ''): array { return $this->rec('buttons', ['body' => $body, 'buttons' => $buttons]); }
    public function lists(string $body, string $buttonText, array $rows, string $footer = ''): array { return $this->rec('list', ['body' => $body, 'rows' => $rows]); }
    public function template(string $name, string $language, array $variables = []): array { return $this->rec('template', ['name' => $name, 'language' => $language, 'variables' => $variables]); }
}

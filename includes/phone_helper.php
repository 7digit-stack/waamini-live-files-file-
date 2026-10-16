<?php
/**
 * Phone numbers in the same format WhatsApp uses (+<country code><number>), so imported
 * contacts land on the same contact row as their WhatsApp chat.
 */

if (!function_exists('wa_phone_normalize')) {
    /**
     * "09092567609", "9092567609", "91 90925-67609", "+919092567609", "00919092567609" -> "+919092567609"
     * Numbers without a country code get $defaultCc. Returns '' when it is not a plausible number.
     */
    function wa_phone_normalize(string $raw, string $defaultCc = '91'): string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (!is_string($d) || $d === '') { return ''; }
        $cc = preg_replace('/\D+/', '', $defaultCc) ?: '91';
        if (strlen($d) > 11 && strncmp($d, '00', 2) === 0) { $d = substr($d, 2); }   // international prefix 00 (00 + code + number)
        if (strlen($d) === 11 && $d[0] === '0') { $d = $cc . substr($d, 1); } // trunk 0 + 10 digits
        elseif (strlen($d) === 10) { $d = $cc . $d; }                        // local 10-digit number
        $d = ltrim($d, '0');
        $len = strlen($d);
        if ($len < 8 || $len > 15) { return ''; }
        return '+' . $d;
    }

    /** Last 10 digits, used to find the same person stored in another format. */
    function wa_phone_last10(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        return is_string($d) ? substr($d, -10) : '';
    }

    /**
     * Find the existing contact for this number in any stored format. Prefers the contact that has a chat.
     * @return array|null contact row (id, name, phone, email, group_id, tags) plus has_chat
     */
    function wa_find_contact_by_phone(mysqli $conn, int $companyId, string $normalized): ?array
    {
        $digits = ltrim($normalized, '+');
        $last10 = wa_phone_last10($normalized);
        if ($digits === '' || strlen($last10) < 10) { return null; }
        $st = $conn->prepare(
            "SELECT c.id, c.name, c.phone, c.email, c.group_id, c.tags,
                    EXISTS(SELECT 1 FROM messages m WHERE m.company_id = c.company_id AND m.contact_id = c.id) AS has_chat
             FROM contacts c
             WHERE c.company_id = ?
               AND (c.phone = ? OR c.phone = ?
                    OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, '+', ''), ' ', ''), '-', ''), '(', ''), 10) = ?)
             ORDER BY has_chat DESC, c.last_message_at DESC, c.id ASC
             LIMIT 1"
        );
        if (!$st) { return null; }
        $st->bind_param('isss', $companyId, $normalized, $digits, $last10);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }
}

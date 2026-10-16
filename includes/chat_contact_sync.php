<?php
/**
 * Sync WhatsApp chat contacts with Contacts and Groups (used by client/contact_import.php).
 *  - analysis: who is chatting, who is in no group, numbers in the wrong format, duplicates
 *  - sync: fix number formats and add chatting customers to a group
 * A "chat contact" is a contact who has sent the business at least one WhatsApp message.
 */
require_once __DIR__ . '/phone_helper.php';

if (!function_exists('ccs_analysis')) {

    /** Chat contacts with their last customer message time (subquery reused by analysis and sync). */
    function ccs_chat_subquery(): string
    {
        return "SELECT contact_id, MAX(created_at) AS last_in FROM messages WHERE company_id = ? AND direction = 'incoming' GROUP BY contact_id";
    }

    function ccs_analysis(mysqli $conn, int $companyId, string $cc = '91'): array
    {
        $out = ['total' => 0, 'chat' => 0, 'chat_30' => 0, 'chat_no_group' => 0, 'not_chatting' => 0,
                'format_fix' => 0, 'missing_cc' => 0, 'invalid' => 0, 'dups' => [], 'dup_count' => 0, 'groups' => []];

        // group_id of a deleted group counts as no group
        $st = $conn->prepare("SELECT c.id, c.name, c.phone, IF(g.id IS NULL, 0, c.group_id) AS group_id, ch.last_in
            FROM contacts c LEFT JOIN (" . ccs_chat_subquery() . ") ch ON ch.contact_id = c.id
            LEFT JOIN contact_groups g ON g.id = c.group_id AND g.company_id = c.company_id
            WHERE c.company_id = ?");
        $st->bind_param('ii', $companyId, $companyId);
        $st->execute();
        $res = $st->get_result();
        $byLast10 = [];
        $groupCount = [];
        $cut30 = time() - 30 * 86400;
        while ($c = $res->fetch_assoc()) {
            $out['total']++;
            $isChat = $c['last_in'] !== null;
            if ($isChat) {
                $out['chat']++;
                if (strtotime((string)$c['last_in']) >= $cut30) { $out['chat_30']++; }
                if ((int)$c['group_id'] <= 0) { $out['chat_no_group']++; }
                $g = (int)$c['group_id'];
                $groupCount[$g] = ($groupCount[$g] ?? 0) + 1;
            } else {
                $out['not_chatting']++;
            }
            $raw = (string)$c['phone'];
            $digits = preg_replace('/\D+/', '', $raw);
            if ($digits === '' || strlen($digits) < 8) { $out['invalid']++; }
            elseif (ccs_missing_cc($raw)) { $out['missing_cc']++; }
            elseif ($raw !== '+' . $digits) { $out['format_fix']++; }
            $l10 = wa_phone_last10($raw);
            if (strlen($l10) === 10) { $byLast10[$l10][] = $c; }
        }
        $st->close();

        foreach ($byLast10 as $l10 => $rows) {
            if (count($rows) < 2) { continue; }
            $out['dup_count'] += count($rows) - 1;
            if (count($out['dups']) < 10) { $out['dups'][] = ['last10' => $l10, 'rows' => $rows]; }
        }

        // chat contacts per group (biggest first), "No group" included
        $names = [0 => ['No group', '#9AA9A4']];
        $gq = $conn->prepare("SELECT id, name, color FROM contact_groups WHERE company_id = ?");
        $gq->bind_param('i', $companyId);
        $gq->execute();
        foreach ($gq->get_result()->fetch_all(MYSQLI_ASSOC) as $g) { $names[(int)$g['id']] = [(string)$g['name'], (string)($g['color'] ?: '#128C7E')]; }
        $gq->close();
        arsort($groupCount);
        foreach ($groupCount as $gid => $n) {
            $nm = $names[$gid] ?? ['Deleted group', '#9AA9A4'];
            $out['groups'][] = ['id' => $gid, 'name' => $nm[0], 'color' => $nm[1], 'count' => $n];
        }
        return $out;
    }

    /**
     * "+9092567609": a + followed by a bare 10-digit Indian mobile (6-9...), i.e. the country code is missing.
     * Written by older imports/registration. Never applied to numbers that already have a country code.
     */
    function ccs_missing_cc(string $raw): bool
    {
        $digits = preg_replace('/\D+/', '', $raw);
        return strlen($digits) === 10 && preg_match('/^[6-9]/', $digits) === 1;
    }

    /**
     * Run the sync. $o: scope ('all'|'30'|'90'), only_ungrouped (bool), group_id (int, 0 = none), fix_format (bool),
     * add_cc (bool), cc (string). Returns counts.
     */
    function ccs_sync(mysqli $conn, int $companyId, array $o): array
    {
        $r = ['formatted' => 0, 'cc_added' => 0, 'conflicts' => 0, 'grouped' => 0];
        $cc = preg_replace('/\D+/', '', (string)($o['cc'] ?? '91')) ?: '91';

        // 1) number formats (so imports, campaigns and chats all match one contact)
        if (!empty($o['fix_format']) || !empty($o['add_cc'])) {
            $st = $conn->prepare("SELECT id, phone FROM contacts WHERE company_id = ?");
            $st->bind_param('i', $companyId);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
            $exists = $conn->prepare("SELECT id FROM contacts WHERE company_id = ? AND phone = ? AND id <> ? LIMIT 1");
            $upd = $conn->prepare("UPDATE contacts SET phone = ? WHERE id = ? AND company_id = ?");
            foreach ($rows as $c) {
                $raw = (string)$c['phone'];
                $digits = preg_replace('/\D+/', '', $raw);
                if ($digits === '' || strlen($digits) < 8) { continue; }
                $new = null; $kind = '';
                if (ccs_missing_cc($raw)) {
                    if (!empty($o['add_cc'])) { $new = '+' . $cc . $digits; $kind = 'cc_added'; }
                } elseif ($raw !== '+' . $digits && !empty($o['fix_format'])) {
                    $new = '+' . ltrim($digits, '0'); $kind = 'formatted';
                }
                if ($new === null || $new === $raw) { continue; }
                $id = (int)$c['id'];
                $exists->bind_param('isi', $companyId, $new, $id);
                $exists->execute();
                if ($exists->get_result()->fetch_row()) { $r['conflicts']++; continue; }   // a duplicate; left for review
                $upd->bind_param('sii', $new, $id, $companyId);
                $upd->execute();
                $r[$kind]++;
            }
            $exists->close();
            $upd->close();
        }

        // 2) add chatting customers to the group
        $gid = (int)($o['group_id'] ?? 0);
        if ($gid > 0) {
            $days = ['30' => 30, '90' => 90][(string)($o['scope'] ?? 'all')] ?? 0;
            $sql = "UPDATE contacts c JOIN (" . ccs_chat_subquery() . ") ch ON ch.contact_id = c.id
                    SET c.group_id = ?
                    WHERE c.company_id = ? AND (c.group_id IS NULL OR c.group_id <> ?)"
                 . (!empty($o['only_ungrouped']) ? " AND (c.group_id IS NULL OR c.group_id = 0 OR NOT EXISTS (SELECT 1 FROM contact_groups gx WHERE gx.id = c.group_id AND gx.company_id = c.company_id))" : '')
                 . ($days > 0 ? " AND ch.last_in >= DATE_SUB(NOW(), INTERVAL $days DAY)" : '');
            $u = $conn->prepare($sql);
            $u->bind_param('iiii', $companyId, $gid, $companyId, $gid);
            $u->execute();
            $r['grouped'] = $u->affected_rows;
            $u->close();
        }
        return $r;
    }
}

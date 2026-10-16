<?php
/**
 * AttributionService — Click-to-WhatsApp (CTWA) referral capture.
 *
 * When a customer taps an ad and messages the business, the FIRST message of that session carries a
 * `referral` object (any message type). capture() stores it once (unique company_id + message_id, so
 * webhook retries never duplicate), records the ad in ad_catalog, and marks the contact as an ad lead
 * (first / last touch, latest ctwa_clid for the Conversions API in Phase 2).
 *
 * It runs inside the webhook but only does a few small indexed queries and never calls the network:
 * the thumbnail download is left as media_status = 'pending' and handled by processPendingMedia()
 * (every-minute worker, and when Ad Leads / WhatsApp Chat is opened).
 */
require_once __DIR__ . '/../includes/ads/schema.php';
require_once __DIR__ . '/MediaVaultService.php';

class AttributionService
{
    /** Normalised referral from a Cloud API message, or null when the message has none. */
    public static function referral(array $message): ?array
    {
        $r = $message['referral'] ?? null;
        if (!is_array($r) || !$r) return null;
        $s = static fn($k, int $max) => mb_substr(trim((string)($r[$k] ?? '')), 0, $max);
        $type = strtolower(preg_replace('/[^a-z_]/i', '', $s('source_type', 20)));
        $mediaType = strtolower(preg_replace('/[^a-z_]/i', '', $s('media_type', 20)));
        $ref = [
            'source_type'   => $type !== '' ? $type : 'unknown',      // ad | post | anything new Meta adds
            'source_id'     => $s('source_id', 64),
            'source_url'    => $s('source_url', 512),
            'headline'      => $s('headline', 255),
            'body'          => trim((string)($r['body'] ?? '')),
            'media_type'    => $mediaType,
            'media_url'     => trim((string)($r['image_url'] ?? $r['video_url'] ?? '')),
            'thumbnail_url' => trim((string)($r['thumbnail_url'] ?? '')),
            'ctwa_clid'     => $s('ctwa_clid', 512),
        ];
        // A referral with nothing identifying is ignored rather than stored as noise.
        if ($ref['source_id'] === '' && $ref['source_url'] === '' && $ref['ctwa_clid'] === '' && $ref['headline'] === '') return null;
        return $ref;
    }

    /**
     * Store the referral (once) and update the contact. Returns the ad_referrals id, or null when the
     * message has no referral. Never throws: attribution must not break message handling.
     */
    public static function capture(mysqli $conn, int $companyId, int $contactId, array $message): ?int
    {
        try {
            $ref = self::referral($message);
            if (!$ref || $companyId <= 0 || $contactId <= 0) return null;
            ads_ensure_schema($conn);

            $messageId = mb_substr(trim((string)($message['id'] ?? '')), 0, 128);
            if ($messageId === '') $messageId = 'noid_' . sha1(($message['from'] ?? '') . '|' . ($message['timestamp'] ?? '') . '|' . json_encode($message['referral']));
            $ts = (int)($message['timestamp'] ?? 0);
            $clickedAt = date('Y-m-d H:i:s', $ts > 0 ? $ts : time());   // PHP clock (Asia/Kolkata), not MySQL NOW()
            $imageOrThumb = $ref['thumbnail_url'] !== '' ? $ref['thumbnail_url'] : ($ref['media_type'] === 'image' ? $ref['media_url'] : '');
            $mediaStatus = $imageOrThumb !== '' ? 'pending' : 'none';
            $raw = json_encode($message['referral'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $null = static fn(string $v) => $v === '' ? null : $v;

            $ins = $conn->prepare("INSERT IGNORE INTO ad_referrals
                (company_id, contact_id, message_id, source_type, source_id, source_url, headline, body, media_type, media_url, thumbnail_url, media_status, ctwa_clid, raw_json, clicked_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$ins) { error_log('[ADS capture] prepare: ' . $conn->error); return null; }
            $vals = [$companyId, $contactId, $messageId, $ref['source_type'], $null($ref['source_id']), $null($ref['source_url']),
                $null($ref['headline']), $null($ref['body']), $null($ref['media_type']), $null($ref['media_url']), $null($ref['thumbnail_url']),
                $mediaStatus, $null($ref['ctwa_clid']), $raw, $clickedAt];
            $ins->bind_param('iisssssssssssss', ...$vals);
            $ins->execute();
            if ($ins->affected_rows !== 1) {
                // Webhook retry: the referral is already stored. Nothing else to change.
                $st = $conn->prepare("SELECT id FROM ad_referrals WHERE company_id=? AND message_id=? LIMIT 1");
                $st->bind_param('is', $companyId, $messageId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                return $row ? (int)$row['id'] : null;
            }
            $refId = (int)$conn->insert_id;

            if ($ref['source_id'] !== '') {
                $cat = $conn->prepare("INSERT INTO ad_catalog (company_id, source_id, source_type, headline, media_type, first_seen_at, last_seen_at)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE headline = COALESCE(VALUES(headline), headline), media_type = COALESCE(VALUES(media_type), media_type),
                        last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))");
                if ($cat) {
                    $h = $null($ref['headline']); $mt = $null($ref['media_type']);
                    $cat->bind_param('issssss', $companyId, $ref['source_id'], $ref['source_type'], $h, $mt, $clickedAt, $clickedAt);
                    $cat->execute();
                }
            }

            // Contact: ad lead (keep 'import' / 'manual' — those were set on purpose), first + last touch, latest click id.
            $src = $ref['source_type'] === 'post' ? 'post' : 'ad';
            $clid = $null($ref['ctwa_clid']);
            $up = $conn->prepare("UPDATE contacts SET
                    lead_source = IF(lead_source IN ('organic','ad','post'), ?, lead_source),
                    first_ad_referral_id = COALESCE(first_ad_referral_id, ?),
                    last_ad_referral_id = ?,
                    latest_ctwa_clid = COALESCE(?, latest_ctwa_clid)
                WHERE id = ? AND company_id = ?");
            if ($up) { $up->bind_param('siisii', $src, $refId, $refId, $clid, $contactId, $companyId); $up->execute(); }
            return $refId;
        } catch (\Throwable $e) {
            error_log('[ADS capture] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Download pending referral thumbnails. $companyId = 0 handles every company (cron).
     * Rows are claimed (pending → processing) so parallel runners never download the same file twice.
     * @param callable|null $fetch passed to MediaVaultService (tests)
     */
    public static function processPendingMedia(mysqli $conn, int $companyId = 0, int $limit = 10, ?callable $fetch = null): int
    {
        try {
            ads_ensure_schema($conn);
            // Runners that died mid-download leave rows in 'processing'; retry them after 10 minutes.
            $stale = date('Y-m-d H:i:s', time() - 600);
            $st = $conn->prepare("UPDATE ad_referrals SET media_status='pending' WHERE media_status='processing' AND created_at < ?");
            if ($st) { $st->bind_param('s', $stale); $st->execute(); }

            $sql = "SELECT id, company_id, source_id, media_type, media_url, thumbnail_url, media_attempts FROM ad_referrals
                    WHERE media_status='pending'" . ($companyId > 0 ? " AND company_id=?" : "") . " ORDER BY id ASC LIMIT ?";
            $st = $conn->prepare($sql);
            if (!$st) return 0;
            if ($companyId > 0) $st->bind_param('ii', $companyId, $limit); else $st->bind_param('i', $limit);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

            $claim = $conn->prepare("UPDATE ad_referrals SET media_status='processing' WHERE id=? AND media_status='pending'");
            $done = 0;
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $claim->bind_param('i', $id);
                $claim->execute();
                if ($claim->affected_rows !== 1) continue;

                $url = (string)($r['thumbnail_url'] ?: (($r['media_type'] ?? '') === 'image' ? $r['media_url'] : ''));
                $res = $url !== '' ? MediaVaultService::saveFromUrl((int)$r['company_id'], $url, 'ad_media', $fetch)
                                   : ['ok' => false, 'error' => 'No image to download.', 'path' => ''];
                if ($res['ok']) {
                    $u = $conn->prepare("UPDATE ad_referrals SET media_status='done', local_media_path=?, media_attempts=media_attempts+1 WHERE id=?");
                    $u->bind_param('si', $res['path'], $id);
                    $u->execute();
                    if (!empty($r['source_id'])) {
                        $c = $conn->prepare("UPDATE ad_catalog SET local_thumbnail_path = COALESCE(local_thumbnail_path, ?) WHERE company_id=? AND source_id=?");
                        $cid = (int)$r['company_id']; $sid = (string)$r['source_id'];
                        $c->bind_param('sis', $res['path'], $cid, $sid);
                        $c->execute();
                    }
                    $done++;
                } else {
                    // Expired links never come back: give up after 3 attempts.
                    $next = ((int)$r['media_attempts'] + 1) >= 3 ? 'failed' : 'pending';
                    $u = $conn->prepare("UPDATE ad_referrals SET media_status=?, media_attempts=media_attempts+1 WHERE id=?");
                    $u->bind_param('si', $next, $id);
                    $u->execute();
                    error_log('[ADS media] referral ' . $id . ': ' . ($res['error'] ?? 'failed'));
                }
            }
            return $done;
        } catch (\Throwable $e) {
            error_log('[ADS media] ' . $e->getMessage());
            return 0;
        }
    }

    /** All ad touches for a contact, newest first (Lead source card in WhatsApp Chat). */
    public static function touches(mysqli $conn, int $companyId, int $contactId, int $limit = 10): array
    {
        try {
            ads_ensure_schema($conn);
            $st = $conn->prepare("SELECT r.id, r.source_type, r.source_id, r.source_url, r.headline, r.body, r.media_type, r.media_status,
                    r.local_media_path, r.clicked_at, c.ad_name, c.campaign_name
                FROM ad_referrals r LEFT JOIN ad_catalog c ON c.company_id = r.company_id AND c.source_id = r.source_id
                WHERE r.company_id = ? AND r.contact_id = ? ORDER BY r.clicked_at DESC, r.id DESC LIMIT ?");
            if (!$st) return [];
            $st->bind_param('iii', $companyId, $contactId, $limit);
            $st->execute();
            return $st->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

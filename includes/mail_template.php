<?php
/**
 * Corporate HTML email layout (table based, inline styles: safe for Gmail/Outlook/Apple Mail).
 *
 * waamini_mail_html([
 *   'preheader' => 'Hidden inbox preview text',
 *   'category'  => 'Security notification',          // small label above the card
 *   'eyebrow'   => 'Password reset',
 *   'title'     => 'Reset your password',
 *   'greeting'  => 'Hello,',
 *   'intro'     => 'Paragraphs separated by a blank line',
 *   'cta'       => ['Reset password', 'https://...'],
 *   'link_note' => true,                              // raw-URL fallback box
 *   'details'   => ['Account' => '...', ...],         // "request summary" rows
 *   'tips'      => ['Short security tip', ...],
 *   'recipient' => 'person@example.com',              // shown in the footer
 * ]);
 */
if (!function_exists('waamini_mail_html')) {
    function waamini_mail_html(array $o): string
    {
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $navy = '#0B2E2B';
        $accent = '#11887A';
        $ink = '#0E1726';
        $mut = '#566474';
        $font = "Arial,Helvetica,sans-serif";

        // Site origin for footer links: taken from the button URL, else APP_URL, else production.
        $base = '';
        if (!empty($o['cta'][1])) {
            $p = parse_url((string)$o['cta'][1]);
            if (!empty($p['scheme']) && !empty($p['host'])) { $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''); }
        }
        if ($base === '') { $base = rtrim((string)\Waamini\Config\Env::get('APP_URL', 'https://waamini.com'), '/'); }

        $paras = '';
        foreach (preg_split("/\n{2,}/", trim((string)($o['intro'] ?? ''))) as $p) {
            if ($p !== '') {
                $paras .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:' . $mut . ';">' . nl2br($e($p)) . '</p>';
            }
        }
        $greeting = !empty($o['greeting']) ? '<p style="margin:0 0 14px;font-size:15px;font-weight:700;color:' . $ink . ';">' . $e($o['greeting']) . '</p>' : '';

        $cta = '';
        if (!empty($o['cta'])) {
            [$label, $url] = $o['cta'];
            $cta = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 22px;"><tr>'
                 . '<td align="center" bgcolor="' . $accent . '" style="border-radius:8px;">'
                 . '<a href="' . $e($url) . '" target="_blank" style="display:inline-block;padding:15px 38px;font-family:' . $font . ';font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">' . $e($label) . '</a>'
                 . '</td></tr></table>';
            if (!empty($o['link_note'])) {
                $cta .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;"><tr><td style="padding:12px 14px;background:#F4F7F9;border-radius:8px;font-size:11.5px;line-height:1.6;color:' . $mut . ';">'
                      . 'If the button does not open, copy this address into your browser:<br>'
                      . '<span style="color:' . $accent . ';word-break:break-all;">' . $e($url) . '</span></td></tr></table>';
            }
        }

        $details = '';
        if (!empty($o['details'])) {
            $rows = '';
            foreach ($o['details'] as $k => $v) {
                $rows .= '<tr><td style="padding:8px 0;font-size:13px;color:' . $mut . ';width:36%;vertical-align:top;">' . $e($k) . '</td>'
                       . '<td style="padding:8px 0;font-size:13px;font-weight:700;color:' . $ink . ';vertical-align:top;">' . $e($v) . '</td></tr>';
            }
            $details = '<p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:' . $ink . ';">Request summary</p>'
                     . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:#F4F7F9;border-radius:10px;"><tr><td style="padding:10px 18px;">'
                     . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr></table>';
        }

        $tips = '';
        if (!empty($o['tips'])) {
            $li = '';
            foreach ($o['tips'] as $t) {
                $li .= '<tr><td style="width:20px;vertical-align:top;padding:4px 0;font-size:13px;color:' . $accent . ';font-weight:700;">&#10003;</td>'
                     . '<td style="padding:4px 0;font-size:13px;line-height:1.6;color:' . $mut . ';">' . $e($t) . '</td></tr>';
            }
            $tips = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;background:#EEF8F6;border-radius:10px;"><tr><td style="padding:16px 18px;">'
                  . '<p style="margin:0 0 6px;font-size:13px;font-weight:700;color:' . $ink . ';">Security tips</p>'
                  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $li . '</table></td></tr></table>';
        }

        $pre = $e($o['preheader'] ?? '');
        $year = date('Y');
        $cat = $e($o['category'] ?? 'Notification');
        $recipient = !empty($o['recipient'])
            ? '<p style="margin:0 0 10px;font-size:12px;line-height:1.6;color:#9FB3B0;">This email was sent to <span style="color:#ffffff;">' . $e($o['recipient']) . '</span> because of activity on your WAAMINI account.</p>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . $e($o['title'] ?? 'WAAMINI') . '</title></head>'
            . '<body style="margin:0;padding:0;background:#EDF1F4;font-family:' . $font . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#EDF1F4;">' . $pre . str_repeat('&nbsp;&zwnj;', 40) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#EDF1F4;"><tr><td align="center" style="padding:26px 12px 34px;">'
            // top label
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;"><tr>'
            . '<td style="padding:0 4px 12px;font-size:12px;color:#7A8896;">WAAMINI &middot; ' . $cat . '</td>'
            . '<td align="right" style="padding:0 4px 12px;font-size:12px;color:#7A8896;">' . date('d M Y') . '</td></tr></table>'
            // card
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">'
            // brand header
            . '<tr><td style="background:' . $navy . ';padding:24px 36px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="width:34px;height:34px;background:' . $accent . ';border-radius:50%;text-align:center;font-family:' . $font . ';font-size:17px;font-weight:700;color:#ffffff;line-height:34px;">W</td>'
            . '<td style="padding-left:12px;"><div style="font-family:' . $font . ';font-size:19px;font-weight:700;letter-spacing:.08em;color:#ffffff;">WAAMINI</div>'
            . '<div style="font-family:' . $font . ';font-size:11.5px;color:#9FC9C3;margin-top:2px;">WhatsApp CRM &amp; Automation Platform</div></td>'
            . '</tr></table></td></tr>'
            // title band
            . '<tr><td style="background:#F4FAF8;padding:30px 36px 26px;">'
            . '<p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:' . $accent . ';">' . $e($o['eyebrow'] ?? '') . '</p>'
            . '<h1 style="margin:0;font-size:26px;line-height:1.25;color:' . $ink . ';font-family:' . $font . ';">' . $e($o['title'] ?? '') . '</h1>'
            . '</td></tr>'
            // body
            . '<tr><td style="padding:30px 36px 14px;">' . $greeting . $paras . $cta . $details . $tips . '</td></tr>'
            . '<tr><td style="padding:10px 36px 32px;"><p style="margin:0;font-size:14px;line-height:1.7;color:' . $mut . ';">Regards,<br><b style="color:' . $ink . ';">WAAMINI Support Team</b></p></td></tr>'
            // footer
            . '<tr><td style="background:' . $navy . ';padding:26px 36px;">'
            . $recipient
            . '<p style="margin:0 0 12px;font-size:12.5px;line-height:1.9;"><a href="' . $e($base) . '/support.php" style="color:#ffffff;text-decoration:underline;">Help Center</a>'
            . '&nbsp;&nbsp;|&nbsp;&nbsp;<a href="' . $e($base) . '/privacy-policy.php" style="color:#ffffff;text-decoration:underline;">Privacy Policy</a>'
            . '&nbsp;&nbsp;|&nbsp;&nbsp;<a href="' . $e($base) . '/terms-of-service.php" style="color:#ffffff;text-decoration:underline;">Terms of Service</a>'
            . '&nbsp;&nbsp;|&nbsp;&nbsp;<a href="mailto:support@waamini.com" style="color:#ffffff;text-decoration:underline;">support@waamini.com</a></p>'
            . '<p style="margin:0;font-size:11.5px;line-height:1.7;color:#9FB3B0;">WAAMINI never asks for your password by email or chat. This is an automated message, so please do not forward it.<br>&copy; ' . $year . ' WAAMINI &middot; Made in India. All rights reserved.</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table></body></html>';
    }
}

/** Plain-text twin of the same content (improves deliverability, used as AltBody). */
if (!function_exists('waamini_mail_text')) {
    function waamini_mail_text(array $o): string
    {
        $t = 'WAAMINI - ' . ($o['title'] ?? '') . "\n\n";
        if (!empty($o['greeting'])) { $t .= $o['greeting'] . "\n\n"; }
        $t .= trim((string)($o['intro'] ?? '')) . "\n\n";
        if (!empty($o['cta'])) { $t .= $o['cta'][0] . ': ' . $o['cta'][1] . "\n\n"; }
        foreach (($o['details'] ?? []) as $k => $v) { $t .= $k . ': ' . $v . "\n"; }
        foreach (($o['tips'] ?? []) as $tip) { $t .= "- " . $tip . "\n"; }
        return $t . "\nRegards,\nWAAMINI Support Team\nsupport@waamini.com";
    }
}

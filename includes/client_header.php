<?php
/* ─── SVG icon helper for client nav ─── */
if (!function_exists('cn_svg')) {
    function cn_svg(string $n, string $cls = ''): string {
        $s = 'width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' . ($cls ? ' class="' . $cls . '"' : '');
        $icons = [
            'dashboard'   => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
            'activity'    => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
            'growth'      => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
            'ads'         => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'adleads'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'qr'          => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
            'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'campaigns'   => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
            'automations' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'integrations'=> '<path d="M12 22V8"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/><circle cx="12" cy="5" r="3"/>',
            'contacts'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'groups'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'templates'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'bot'         => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>',
            'router'      => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M13 6h3a2 2 0 0 1 2 2v7"/><line x1="6" x2="6" y1="9" y2="21"/>',
            'team'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'subscription'=> '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
            'log'         => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
            'pulse'       => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
            'ai'          => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
            'zapier'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'flows'       => '<rect width="18" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/>',
            'ocr'         => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
            'drip'        => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
            'analytics'   => '<line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>',
            'voice'       => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/>',
            'livechat'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M13 8H7"/><path d="M17 12H7"/>',
            'orders_chart'=> '<path d="M6 5v11"/><path d="M12 5v6"/><path d="M18 5v14"/>',
            'package'     => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/>',
            'catalog'     => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
            'cart'        => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
            'settings'    => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'pdf'         => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
            'loyalty'     => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
            'invoice'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'blacklist'   => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
            'health'      => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
            'flowbuilder' => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 21V9a9 9 0 0 0 9 9"/>',
            'scheduled'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
            'blast'       => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
            'reports'     => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
            'bulk'        => '<path d="M12 13v8l-4-4"/><path d="m12 21 4-4"/><path d="M4.393 15.269A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.436 8.284"/>',
            'referral'    => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>',
            'bell'        => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
            'shield'      => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'festival'    => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
            'school'      => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
            'vehicle'     => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
            'medicine'    => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
            'billing'     => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
            'discover'    => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
            'manual'      => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
            'general'     => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'wa_setup'    => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
            'gsheets'     => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/>',
            'sales'       => '<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'api'         => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'support'     => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
            'profile'     => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        ];
        $path = $icons[$n] ?? '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1"/>';
        return "<svg {$s}>{$path}</svg>";
    }
}

/* ─── Lock icon + attrs for a sidebar item the current plan doesn't include ───
   The item still renders (visible, dimmed, non-clickable) instead of disappearing. */
if (!function_exists('cn_lock_icon')) {
    function cn_lock_icon(): string {
        return '<svg class="lock-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    }
}
if (!function_exists('cn_locked_attrs')) {
    function cn_locked_attrs(bool $locked): string {
        return $locked ? ' onclick="return false;" title="Upgrade your plan to unlock this feature" aria-disabled="true"' : '';
    }
}
if (!function_exists('cn_on')) {
    /** Per-page plan switch; stays on if the server still has a feature_check.php without these switches. */
    function cn_on(string $key): bool { return !function_exists('waamini_plan_switches') || has_feature($key); }
}
if (!function_exists('cn_plan_menu_only')) {
    /** The client menu shows only what the plan includes: drop locked items, then any section heading left empty. */
    function cn_plan_menu_only(string $html): string {
        $out = preg_replace('#[ \t]*<a\b[^>]*\bclass="nav-item\b[^"]*\blocked\b[^"]*"[^>]*>.*?</a>[ \t]*\R?#s', '', $html);
        if (!is_string($out)) return $html;   // regex failure: keep the menu as it was
        $title = '<div class="nav-section-title">(?:(?!</div>).)*</div>';
        do {   // a heading followed only by space/comments and then another heading or the end of the menu is empty
            $prev = $out;
            $out = preg_replace('#[ \t]*' . $title . '(?=(?:\s|<!--.*?-->)*(?:<div class="nav-section-title">|$))\s*#s', '', $out);
            if (!is_string($out)) return $html;
        } while ($out !== $prev);
        return $out;
    }
}

/* ─── Load company name (session-cached) ─── */
$_companyName = (string)($_SESSION['_company_name'] ?? '—');
if ($companyId > 0 && ($_companyName === '—' || empty($_SESSION['_company_name_id']) || (int)$_SESSION['_company_name_id'] !== (int)$companyId)) {
    $_cStmt = $conn->prepare("SELECT name FROM companies WHERE id=?");
    if ($_cStmt) {
        $_cStmt->bind_param("i", $companyId);
        $_cStmt->execute();
        $_cRes = $_cStmt->get_result();
        if ($_cRes && $_c = $_cRes->fetch_assoc()) {
            $_companyName = (string)($_c['name'] ?? '—');
            $_SESSION['_company_name'] = $_companyName;
            $_SESSION['_company_name_id'] = (int)$companyId;
        }
    }
}
$_userName = $_SESSION['user_name'] ?? 'User';
$_planName = get_plan_name();

/* ─── Active announcements ─── */
$announcements = [];
if (isset($conn) && isset($companyId) && $companyId > 0) {
    try {
        $planId = 0;
        $_subStmt = $conn->prepare("SELECT plan_id FROM subscriptions WHERE company_id=? AND status IN ('active','trial') ORDER BY id DESC LIMIT 1");
        if ($_subStmt) {
            $_subStmt->bind_param("i", $companyId);
            $_subStmt->execute();
            $_subRes = $_subStmt->get_result();
            if ($row = $_subRes->fetch_assoc()) $planId = (int)$row['plan_id'];
            $_subStmt->close();
        }
        $annStmt = $conn->prepare("SELECT * FROM announcements WHERE is_active=1 AND (target_plan_id=0 OR target_plan_id=?) ORDER BY id DESC");
        if ($annStmt) {
            $annStmt->bind_param("i", $planId);
            $annStmt->execute();
            $annRes = $annStmt->get_result();
            while ($row = $annRes->fetch_assoc()) $announcements[] = $row;
            $annStmt->close();
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(strip_tags($pageTitle ?? 'Dashboard'), ENT_QUOTES); ?> – WAAMINI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/client.css?v=4.5'); ?>">
    <link rel="stylesheet" href="<?php echo app_url('assets/css/sidebar-pro.css?v=4'); ?>">
    <?php
    // E-Commerce section pages share one dashboard skin
    $__ecomPages = ['order_dashboard', 'orders', 'catalog', 'catalog_reports', 'premium_abandoned_cart', 'ecommerce', 'pdf_settings'];
    $__isEcom = in_array((string)($currentPage ?? ''), $__ecomPages, true);
    // Settings pages (WhatsApp Setup, Google Sheets, Sales Tracker, API & Webhook) reuse the same skin
    $__setPages = ['settings_whatsapp', 'settings_gsheets', 'settings_sales_tracker', 'settings_api'];
    $__isSet = in_array((string)($currentPage ?? ''), $__setPages, true);
    if ($__isEcom || $__isSet): ?>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/ecom-ui.css?v=1'); ?>">
    <?php endif; ?>
    <?php if ($__isSet): ?>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/settings-ui.css?v=1'); ?>">
    <?php endif; ?>
    <style>
    /* No-flicker page loads: no transitions while the page is being built, and a short cross-fade between pages that keeps the sidebar in place. */
    html.wm-preload *,html.wm-preload *::before,html.wm-preload *::after{transition:none!important}
    @view-transition{navigation:auto}
    ::view-transition-old(root),::view-transition-new(root){animation-duration:.18s}
    #sidebar{view-transition-name:wm-sidebar}
    @media (prefers-reduced-motion: reduce){@view-transition{navigation:none}}
    </style>
    <link rel="stylesheet" href="<?php echo app_url('assets/css/mobile.css?v=5'); ?>">
</head>
<body<?php echo ($__isEcom || $__isSet) ? ' class="ecom-ui' . ($__isSet ? ' set-ui' : '') . '"' : ''; ?>>
<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var d = document.documentElement;
  d.classList.add('wm-preload');
  // Remembered collapsed sidebar is applied before the first paint (no expand-then-collapse jump)
  try { if (localStorage.getItem('wmn_sidebar_collapsed') === '1' && window.matchMedia('(min-width: 1025px)').matches) document.body.classList.add('sb-collapsed'); } catch (e) {}
  window.addEventListener('load', function () {
    requestAnimationFrame(function () { requestAnimationFrame(function () { d.classList.remove('wm-preload'); }); });
  });
})();
</script>

<?php if (!empty($_SESSION['impersonating'])): ?>
<div class="impersonation-bar">
    <div style="display:flex;align-items:center;gap:8px;">
        <?php echo cn_svg('profile'); ?>
        Viewing as <strong><?php echo htmlspecialchars($_userName); ?></strong> &mdash; Admin Impersonation Active
    </div>
        <a href="/admin/stop_impersonate.php" class="impersonation-return">← Return to Admin</a>
</div>
<?php endif; ?>

<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar client-sidebar" id="sidebar">

    <div class="sidebar-header">
        <a href="<?php echo app_url('client/dashboard.php'); ?>" class="logo">
            <img class="sb-logo-full" src="/assets/img/waamini-logo.png" alt="Waamini" width="174" height="34"><img class="sb-logo-mini" src="/assets/img/waamini-icon.png" alt="Waamini" width="42" height="42">
        </a>
    </div>

    <div class="company-badge">
        <div class="company-avatar"><?php echo strtoupper(substr($_companyName, 0, 1)); ?></div>
        <div class="company-info">
            <div class="company-name"><?php echo htmlspecialchars($_companyName); ?></div>
            <div class="company-plan">
                <?php echo cn_svg('subscription'); ?>
                <?php echo htmlspecialchars($_planName); ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
<?php ob_start(); /* menu items for features outside the plan are removed below (cn_plan_menu_only) */ ?>

        <?php $cp = $currentPage ?? ''; ?>

        <a href="<?php echo app_url('client/dashboard.php'); ?>" class="nav-item <?php echo $cp==='dashboard'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('dashboard'); ?></span> Dashboard
        </a>

        <?php if (!$isRestricted): ?>

        <a href="<?php echo app_url('client/qr_code.php'); ?>" class="nav-item <?php echo $cp==='qr_code'?'active':''; ?> <?php echo has_feature('qr_code')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('qr_code')); ?>>
            <span class="icon"><?php echo cn_svg('qr'); ?></span> QR Code
            <?php if (!has_feature('qr_code')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('whatsapp_chat') ? app_url('client/whatsapp.php') : '#'; ?>" class="nav-item <?php echo $cp==='whatsapp'?'active':''; ?> <?php echo has_feature('whatsapp_chat')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('whatsapp_chat')); ?>>
            <span class="icon" style="position:relative;">
                <?php echo cn_svg('chat'); ?>
                <?php
                /* Unread badge — session-cached 60s */
                if (isset($conn,$companyId) && $companyId > 0) {
                    try {
                        $_uck = '_unread_count_'.$companyId; $_uct = '_unread_count_at_'.$companyId;
                        $unreadCount = null;
                        if (isset($_SESSION[$_uck],$_SESSION[$_uct]) && (time()-(int)$_SESSION[$_uct])<60) {
                            $unreadCount = (int)$_SESSION[$_uck];
                        } else {
                            $stU = $conn->prepare("SELECT COUNT(id) as c FROM messages WHERE company_id=? AND direction='incoming' AND is_read=0 LIMIT 1");
                            if ($stU) {
                                $stU->bind_param("i",$companyId);
                                $stU->execute();
                                $unreadCount = (int)($stU->get_result()->fetch_assoc()['c']??0);
                                $_SESSION[$_uck]=$unreadCount; $_SESSION[$_uct]=time();
                                $stU->close();
                            }
                        }
                        if ($unreadCount>0) echo '<span style="position:absolute;top:-4px;right:-8px;background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:10px;line-height:1;">'.$unreadCount.'</span>';
                    } catch (Exception $e) {}
                }
                ?>
            </span> WhatsApp Chat
            <?php if (!has_feature('whatsapp_chat')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('campaigns') ? app_url('client/campaigns.php') : '#'; ?>" class="nav-item <?php echo $cp==='campaigns'?'active':''; ?> <?php echo has_feature('campaigns')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('campaigns')); ?>>
            <span class="icon"><?php echo cn_svg('campaigns'); ?></span> Campaigns
            <?php if (!has_feature('campaigns')) echo cn_lock_icon(); ?>
        </a>

        <?php $_hasIntegrations = has_feature('indiamart')||has_feature('fb_lead_ads')||has_feature('google_sheets')||has_feature('api_webhook')||has_feature('api_webhook_access')||has_feature('shopify'); ?>
        <?php if (cn_on('menu_integrations')): ?>
        <a href="<?php echo $_hasIntegrations ? app_url('client/integrations.php') : '#'; ?>" class="nav-item <?php echo $cp==='integrations'?'active':''; ?> <?php echo $_hasIntegrations?'':'locked'; ?>"<?php echo cn_locked_attrs(!$_hasIntegrations); ?>>
            <span class="icon"><?php echo cn_svg('integrations'); ?></span> Integrations
            <?php if (!$_hasIntegrations) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>
        <?php if (has_feature('shopify')): ?>
        <a href="<?php echo app_url('client/shopify_connect.php'); ?>" class="nav-item <?php echo $cp==='shopify'?'active':''; ?>" style="padding-left:32px;font-size:12.5px;">
            <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span> Shopify
        </a>
        <?php endif; ?>

        <a href="<?php echo has_feature('contacts') ? app_url('client/contacts.php') : '#'; ?>" class="nav-item <?php echo $cp==='contacts'?'active':''; ?> <?php echo has_feature('contacts')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('contacts')); ?>>
            <span class="icon"><?php echo cn_svg('contacts'); ?></span> Contacts
            <?php if (!has_feature('contacts')) echo cn_lock_icon(); ?>
        </a>
        <?php if (cn_on('contact_groups')): ?>
        <a href="<?php echo has_feature('contacts') ? app_url('client/contact_groups.php') : '#'; ?>" class="nav-item <?php echo $cp==='contact_groups'?'active':''; ?> <?php echo has_feature('contacts')?'':'locked'; ?>" style="padding-left:32px;font-size:12.5px;"<?php echo cn_locked_attrs(!has_feature('contacts')); ?>>
            <span class="icon"><?php echo cn_svg('groups'); ?></span> Groups
            <?php if (!has_feature('contacts')) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>

        <a href="<?php echo has_feature('templates') ? app_url('client/templates.php') : '#'; ?>" class="nav-item <?php echo $cp==='templates'?'active':''; ?> <?php echo has_feature('templates')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('templates')); ?>>
            <span class="icon"><?php echo cn_svg('templates'); ?></span> Templates
            <?php if (!has_feature('templates')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('bot_replies') ? app_url('client/bot_replies.php') : '#'; ?>" class="nav-item <?php echo $cp==='bot_replies'?'active':''; ?> <?php echo has_feature('bot_replies')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('bot_replies')); ?>>
            <span class="icon"><?php echo cn_svg('bot'); ?></span> Bot Replies
            <?php if (!has_feature('bot_replies')) echo cn_lock_icon(); ?>
        </a>
        <?php if (cn_on('demo_bookings')): ?>
        <a href="<?php echo has_feature('bot_replies') ? app_url('client/demo_bookings.php') : '#'; ?>" class="nav-item <?php echo $cp==='demo_bookings'?'active':''; ?> <?php echo has_feature('bot_replies')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('bot_replies')); ?>>
            <span class="icon"><?php echo cn_svg('scheduled'); ?></span>
            Demo Bookings
            <?php if (!has_feature('bot_replies')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge badge-admin" style="font-size:9px;padding:2px 6px;margin-left:auto;">AI</span><?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="<?php echo has_feature('ai_chatbot') ? app_url('client/waamini_bot.php') : '#'; ?>" class="nav-item <?php echo $cp==='waamini_bot'?'active':''; ?> <?php echo has_feature('ai_chatbot')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('ai_chatbot')); ?>>
            <span class="icon"><?php echo cn_svg('bot'); ?></span>
            WAAMINI Bot
            <?php if (!has_feature('ai_chatbot')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge badge-admin" style="font-size:9px;padding:2px 6px;margin-left:auto;">AI</span><?php endif; ?>
        </a>
        <?php if (has_feature('ai_chatbot')): ?>
        <?php if (cn_on('bot_knowledge')): ?>
        <a href="<?php echo app_url('client/waamini_bot_knowledge.php'); ?>" class="nav-item nav-sub <?php echo $cp==='waamini_bot_knowledge'?'active':''; ?>">
            Knowledge
        </a>
        <?php endif; ?>
        <?php if (cn_on('bot_settings')): ?>
        <a href="<?php echo app_url('client/waamini_bot_settings.php'); ?>" class="nav-item nav-sub <?php echo $cp==='waamini_bot_settings'?'active':''; ?>">
            Bot Settings
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <a href="<?php echo has_feature('team_members') ? app_url('client/team_members.php') : '#'; ?>" class="nav-item <?php echo $cp==='team_members'?'active':''; ?> <?php echo has_feature('team_members')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('team_members')); ?>>
            <span class="icon"><?php echo cn_svg('team'); ?></span> Team Members
            <?php if (!has_feature('team_members')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo app_url('client/subscription.php'); ?>" class="nav-item <?php echo $cp==='subscription'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('subscription'); ?></span> My Subscription
        </a>

        <!-- Ads & Attribution (add-on) -->
        <div class="nav-section-title"><?php echo cn_svg('ads'); ?> Ads</div>

        <a href="<?php echo has_feature('ads_attribution') ? app_url('client/ads_leads.php') : '#'; ?>" class="nav-item <?php echo $cp==='ads_leads'?'active':''; ?> <?php echo has_feature('ads_attribution')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('ads_attribution')); ?>>
            <span class="icon"><?php echo cn_svg('adleads'); ?></span>
            Ad Leads
            <?php if (!has_feature('ads_attribution')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(18,140,126,.15);color:#0B6B5E;font-size:9px;padding:2px 6px;margin-left:auto;">NEW</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('ads_capi') ? app_url('client/ads_capi.php') : '#'; ?>" class="nav-item <?php echo $cp==='ads_capi'?'active':''; ?> <?php echo has_feature('ads_capi')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('ads_capi')); ?>>
            <span class="icon"><?php echo cn_svg('activity'); ?></span>
            Conversion Events
            <?php if (!has_feature('ads_capi')) echo cn_lock_icon(); ?>
        </a>

        <!-- Premium Growth -->
        <div class="nav-section-title"><?php echo cn_svg('ai'); ?> Premium Growth</div>

        <a href="<?php echo has_feature('api_webhook_access') ? app_url('client/premium_zapier.php') : '#'; ?>" class="nav-item <?php echo $cp==='premium_zapier'?'active':''; ?> <?php echo has_feature('api_webhook_access')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('api_webhook_access')); ?>>
            <span class="icon"><?php echo cn_svg('zapier'); ?></span>
            Zapier Webhooks
            <?php if (!has_feature('api_webhook_access')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(245,158,11,.15);color:#f59e0b;font-size:9px;padding:2px 6px;margin-left:auto;">PRO</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('whatsapp_flows') ? app_url('client/premium_flows.php') : '#'; ?>" class="nav-item <?php echo $cp==='premium_flows'?'active':''; ?> <?php echo has_feature('whatsapp_flows')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('whatsapp_flows')); ?>>
            <span class="icon"><?php echo cn_svg('flows'); ?></span>
            WA Native Forms
            <?php if (!has_feature('whatsapp_flows')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(45,124,227,.15);color:#2d7ce3;font-size:9px;padding:2px 6px;margin-left:auto;">BETA</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('feat_ocr_scanner') ? app_url('client/premium_ocr.php') : '#'; ?>" class="nav-item <?php echo $cp==='premium_ocr'?'active':''; ?> <?php echo has_feature('feat_ocr_scanner')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_ocr_scanner')); ?>>
            <span class="icon"><?php echo cn_svg('ocr'); ?></span>
            AI Image Scanner
            <?php if (!has_feature('feat_ocr_scanner')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(139,92,246,.15);color:#8b5cf6;font-size:9px;padding:2px 6px;margin-left:auto;">PRO</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('feat_drip_campaign') ? app_url('client/premium_drip.php') : '#'; ?>" class="nav-item <?php echo $cp==='premium_drip'?'active':''; ?> <?php echo has_feature('feat_drip_campaign')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_drip_campaign')); ?>>
            <span class="icon"><?php echo cn_svg('drip'); ?></span>
            Drip Campaigns
            <?php if (!has_feature('feat_drip_campaign')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(45,124,227,.15);color:#2d7ce3;font-size:9px;padding:2px 6px;margin-left:auto;">PRO</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('feat_crm_analytics') ? app_url('client/premium_analytics.php') : '#'; ?>" class="nav-item <?php echo $cp==='premium_analytics'?'active':''; ?> <?php echo has_feature('feat_crm_analytics')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_crm_analytics')); ?>>
            <span class="icon"><?php echo cn_svg('analytics'); ?></span>
            CRM Analytics
            <?php if (!has_feature('feat_crm_analytics')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(139,92,246,.15);color:#8b5cf6;font-size:9px;padding:2px 6px;margin-left:auto;">PRO</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('feat_voice_analyzer') ? app_url('client/premium_voice.php') : '#'; ?>" class="nav-item <?php echo $cp==='premium_voice'?'active':''; ?> <?php echo has_feature('feat_voice_analyzer')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_voice_analyzer')); ?>>
            <span class="icon"><?php echo cn_svg('voice'); ?></span>
            AI Voice Note
            <?php if (!has_feature('feat_voice_analyzer')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(239,68,68,.15);color:#ef4444;font-size:9px;padding:2px 6px;margin-left:auto;">ENT</span><?php endif; ?>
        </a>

        <!-- E-Commerce -->
        <div class="nav-section-title"><?php echo cn_svg('catalog'); ?> E-Commerce</div>
        <?php if (cn_on('ec_analytics')): ?>
        <a href="<?php echo has_feature('feat_native_checkout') ? app_url('client/order_dashboard.php') : '#'; ?>" class="nav-item <?php echo $cp==='order_dashboard'?'active':''; ?> <?php echo has_feature('feat_native_checkout')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_native_checkout')); ?>>
            <span class="icon"><?php echo cn_svg('orders_chart'); ?></span> Order Analytics
            <?php if (!has_feature('feat_native_checkout')) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>
        <?php if (cn_on('ec_orders')): ?>
        <a href="<?php echo has_feature('feat_native_checkout') ? app_url('client/orders.php') : '#'; ?>" class="nav-item <?php echo $cp==='orders'?'active':''; ?> <?php echo has_feature('feat_native_checkout')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_native_checkout')); ?>>
            <span class="icon"><?php echo cn_svg('package'); ?></span> Manage Orders
            <?php if (!has_feature('feat_native_checkout')) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>
        <?php if (cn_on('ec_catalog')): ?>
        <a href="<?php echo has_feature('feat_native_checkout') ? app_url('client/catalog.php') : '#'; ?>" class="nav-item <?php echo $cp==='catalog'?'active':''; ?> <?php echo has_feature('feat_native_checkout')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_native_checkout')); ?>>
            <span class="icon"><?php echo cn_svg('catalog'); ?></span> Product Catalog
            <?php if (!has_feature('feat_native_checkout')) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>
        <?php if (cn_on('ec_reports')): ?>
        <a href="<?php echo has_feature('feat_native_checkout') ? app_url('client/catalog_reports.php') : '#'; ?>" class="nav-item <?php echo $cp==='catalog_reports'?'active':''; ?> <?php echo has_feature('feat_native_checkout')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_native_checkout')); ?>>
            <span class="icon"><?php echo cn_svg('analytics'); ?></span> Catalog Reports
            <?php if (!has_feature('feat_native_checkout')) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>
        <?php if (cn_on('ec_checkout')): ?>
        <a href="<?php echo has_feature('feat_native_checkout') ? app_url('client/ecommerce_settings.php') : '#'; ?>" class="nav-item <?php echo $cp==='ecommerce'?'active':''; ?> <?php echo has_feature('feat_native_checkout')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_native_checkout')); ?>>
            <span class="icon"><?php echo cn_svg('settings'); ?></span> Checkout Settings
            <?php if (!has_feature('feat_native_checkout')) echo cn_lock_icon(); ?>
        </a>
        <?php endif; ?>

        <a href="<?php echo has_feature('feat_pdf_quotes') ? app_url('client/pdf_settings.php') : '#'; ?>" class="nav-item <?php echo $cp==='pdf_settings'?'active':''; ?> <?php echo has_feature('feat_pdf_quotes')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_pdf_quotes')); ?>>
            <span class="icon"><?php echo cn_svg('pdf'); ?></span>
            PDF Quotations
            <?php if (!has_feature('feat_pdf_quotes')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(99,102,241,.15);color:#6366f1;font-size:9px;padding:2px 6px;margin-left:auto;">ENT</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('feat_loyalty_points') ? app_url('client/premium_loyalty.php') : '#'; ?>" class="nav-item <?php echo $cp==='loyalty'?'active':''; ?> <?php echo has_feature('feat_loyalty_points')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_loyalty_points')); ?>>
            <span class="icon"><?php echo cn_svg('loyalty'); ?></span>
            Loyalty Program
            <?php if (!has_feature('feat_loyalty_points')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(245,158,11,.15);color:#f59e0b;font-size:9px;padding:2px 6px;margin-left:auto;">ENT</span><?php endif; ?>
        </a>

        <!-- Finance & Trust -->
        <div class="nav-section-title"><?php echo cn_svg('invoice'); ?> Finance &amp; Trust</div>

        <a href="<?php echo has_feature('feat_gst_invoice') ? app_url('client/gst_invoices.php') : '#'; ?>" class="nav-item <?php echo $cp==='gst_invoices'?'active':''; ?> <?php echo has_feature('feat_gst_invoice')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_gst_invoice')); ?>>
            <span class="icon"><?php echo cn_svg('invoice'); ?></span> GST Invoices
            <?php if (!has_feature('feat_gst_invoice')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('feat_blacklist') ? app_url('client/blacklist.php') : '#'; ?>" class="nav-item <?php echo $cp==='blacklist'?'active':''; ?> <?php echo has_feature('feat_blacklist')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_blacklist')); ?>>
            <span class="icon"><?php echo cn_svg('blacklist'); ?></span> Opt-out Manager
            <?php if (!has_feature('feat_blacklist')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('feat_wa_health') ? app_url('client/wa_health.php') : '#'; ?>" class="nav-item <?php echo $cp==='wa_health'?'active':''; ?> <?php echo has_feature('feat_wa_health')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_wa_health')); ?>>
            <span class="icon"><?php echo cn_svg('health'); ?></span> Number Health
            <?php if (!has_feature('feat_wa_health')) echo cn_lock_icon(); ?>
        </a>

        <!-- Pro Modules -->
        <div class="nav-section-title"><?php echo cn_svg('ai'); ?> Pro Modules</div>

        <a href="<?php echo has_feature('feat_chatbot_builder') ? app_url('client/chatbot_flows.php') : '#'; ?>" class="nav-item <?php echo $cp==='chatbot_flows'?'active':''; ?> <?php echo has_feature('feat_chatbot_builder')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_chatbot_builder')); ?>>
            <span class="icon"><?php echo cn_svg('flowbuilder'); ?></span>
            Flow Builder
            <?php if (!has_feature('feat_chatbot_builder')): ?><?php echo cn_lock_icon(); ?><?php else: ?><span class="badge" style="background:rgba(99,102,241,.15);color:#6366f1;font-size:9px;padding:2px 6px;margin-left:auto;">VIP</span><?php endif; ?>
        </a>

        <a href="<?php echo has_feature('feat_scheduled_msg') ? app_url('client/scheduled_messages.php') : '#'; ?>" class="nav-item <?php echo $cp==='scheduled'?'active':''; ?> <?php echo has_feature('feat_scheduled_msg')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_scheduled_msg')); ?>>
            <span class="icon"><?php echo cn_svg('scheduled'); ?></span> Scheduled Blasts
            <?php if (!has_feature('feat_scheduled_msg')) echo cn_lock_icon(); ?>
        </a>


        <a href="<?php echo has_feature('feat_campaign_reports') ? app_url('client/campaign_reports.php') : '#'; ?>" class="nav-item <?php echo $cp==='campaign_reports'?'active':''; ?> <?php echo has_feature('feat_campaign_reports')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_campaign_reports')); ?>>
            <span class="icon"><?php echo cn_svg('reports'); ?></span> ROI Reports
            <?php if (!has_feature('feat_campaign_reports')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('feat_contact_import') ? app_url('client/contact_bulk.php') : '#'; ?>" class="nav-item <?php echo $cp==='contact_bulk'?'active':''; ?> <?php echo has_feature('feat_contact_import')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_contact_import')); ?>>
            <span class="icon"><?php echo cn_svg('bulk'); ?></span> Bulk Import/Export
            <?php if (!has_feature('feat_contact_import')) echo cn_lock_icon(); ?>
        </a>

        <!-- Growth & Security -->
        <div class="nav-section-title"><?php echo cn_svg('shield'); ?> Growth &amp; Security</div>

        <a href="<?php echo has_feature('feat_referral') ? app_url('client/referrals.php') : '#'; ?>" class="nav-item <?php echo $cp==='referral'?'active':''; ?> <?php echo has_feature('feat_referral')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_referral')); ?>>
            <span class="icon"><?php echo cn_svg('referral'); ?></span> Referral Program
            <?php if (!has_feature('feat_referral')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('feat_notifications') ? app_url('client/notifications.php') : '#'; ?>" class="nav-item <?php echo $cp==='notifications'?'active':''; ?> <?php echo has_feature('feat_notifications')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_notifications')); ?>>
            <span class="icon"><?php echo cn_svg('bell'); ?></span> Alerts &amp; Preferences
            <?php if (!has_feature('feat_notifications')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('feat_2fa') ? app_url('client/security.php') : '#'; ?>" class="nav-item <?php echo $cp==='security'?'active':''; ?> <?php echo has_feature('feat_2fa')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('feat_2fa')); ?>>
            <span class="icon"><?php echo cn_svg('shield'); ?></span> Security Center
            <?php if (!has_feature('feat_2fa')) echo cn_lock_icon(); ?>
        </a>

        <!-- Reminder Packs -->
        <div class="nav-section-title"><?php echo cn_svg('bell'); ?> Reminder Packs</div>

        <a href="<?php echo has_feature('festival_reminder') ? app_url('client/festival_campaigns.php') : '#'; ?>" class="nav-item <?php echo $cp==='festival_campaigns'?'active':''; ?> <?php echo has_feature('festival_reminder')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('festival_reminder')); ?>>
            <span class="icon"><?php echo cn_svg('festival'); ?></span> Festival Reminders
            <?php if (!has_feature('festival_reminder')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('school_fee_reminder') ? app_url('client/school_fees.php') : '#'; ?>" class="nav-item <?php echo $cp==='school_fees'?'active':''; ?> <?php echo has_feature('school_fee_reminder')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('school_fee_reminder')); ?>>
            <span class="icon"><?php echo cn_svg('school'); ?></span> School Fee Reminders
            <?php if (!has_feature('school_fee_reminder')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('vehicle_reminder') ? app_url('client/vehicle_reminders.php') : '#'; ?>" class="nav-item <?php echo in_array($cp,['vehicle_reminders','vehicle_reminder'])?'active':''; ?> <?php echo has_feature('vehicle_reminder')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('vehicle_reminder')); ?>>
            <span class="icon"><?php echo cn_svg('vehicle'); ?></span> Vehicle Reminders
            <?php if (!has_feature('vehicle_reminder')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('medicine_reminder') ? app_url('client/medicine_reminders.php') : '#'; ?>" class="nav-item <?php echo in_array($cp,['medicine_reminders','medicine_reminder'])?'active':''; ?> <?php echo has_feature('medicine_reminder')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('medicine_reminder')); ?>>
            <span class="icon"><?php echo cn_svg('medicine'); ?></span> Medicine Reminders
            <?php if (!has_feature('medicine_reminder')) echo cn_lock_icon(); ?>
        </a>

        <!-- Plan & Billing -->
        <div class="nav-section-title"><?php echo cn_svg('billing'); ?> Plan &amp; Billing</div>

        <?php if (cn_on('billing')): ?>
        <a href="<?php echo app_url('client/billing.php'); ?>" class="nav-item <?php echo $cp==='billing'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('billing'); ?></span>
            Billing &amp; Wallet
            <span class="badge badge-admin" style="font-size:9px;padding:2px 6px;margin-left:auto;">NEW</span>
        </a>
        <?php endif; ?>

        <?php if (cn_on('discover')): ?>
        <a href="<?php echo app_url('client/premium_features.php'); ?>" class="nav-item <?php echo $cp==='premium_features'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('discover'); ?></span>
            Discover More
        </a>
        <?php endif; ?>

        <!-- Help -->
        <div class="nav-section-title"><?php echo cn_svg('support'); ?> Help &amp; Support</div>

        <?php if (cn_on('set_manual')): ?>
        <a href="<?php echo app_url('client/manual.php'); ?>" class="nav-item <?php echo $cp==='manual'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('manual'); ?></span> User Guide
        </a>
        <?php endif; ?>

        <!-- Settings -->
        <div class="nav-section-title"><?php echo cn_svg('settings'); ?> Settings</div>

        <?php if (cn_on('set_general')): ?>
        <a href="<?php echo app_url('client/settings_general.php'); ?>" class="nav-item <?php echo $cp==='settings_general'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('general'); ?></span> General
        </a>
        <?php endif; ?>
        <?php if (cn_on('set_whatsapp')): ?>
        <a href="<?php echo app_url('client/settings_whatsapp.php'); ?>" class="nav-item <?php echo $cp==='settings_whatsapp'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('wa_setup'); ?></span> WhatsApp Setup
        </a>
        <?php endif; ?>


        <a href="<?php echo has_feature('google_sheets') ? app_url('client/settings_gsheets.php') : '#'; ?>" class="nav-item <?php echo $cp==='settings_gsheets'?'active':''; ?> <?php echo has_feature('google_sheets')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('google_sheets')); ?>>
            <span class="icon"><?php echo cn_svg('gsheets'); ?></span> Google Sheets
            <?php if (!has_feature('google_sheets')) echo cn_lock_icon(); ?>
        </a>

        <?php if (cn_on('google_calendar')): ?>
        <a href="<?php echo app_url('client/google_calendar.php'); ?>" class="nav-item <?php echo $cp==='google_calendar'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('scheduled'); ?></span> Google Calendar &amp; Meet
        </a>
        <?php endif; ?>

        <?php if (cn_on('sales_tracker')): ?>
        <a href="<?php echo app_url('client/settings_sales_tracker.php'); ?>" class="nav-item <?php echo $cp==='settings_sales_tracker'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('sales'); ?></span> Sales Tracker
        </a>
        <?php endif; ?>

        <a href="<?php echo has_feature('api_webhook') ? app_url('client/settings_api.php') : '#'; ?>" class="nav-item <?php echo $cp==='settings_api'?'active':''; ?> <?php echo has_feature('api_webhook')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('api_webhook')); ?>>
            <span class="icon"><?php echo cn_svg('api'); ?></span> API &amp; Webhook
            <?php if (!has_feature('api_webhook')) echo cn_lock_icon(); ?>
        </a>

        <a href="<?php echo has_feature('pulse') ? app_url('client/pulse.php') : '#'; ?>" class="nav-item <?php echo $cp==='pulse'?'active':''; ?> <?php echo has_feature('pulse')?'':'locked'; ?>"<?php echo cn_locked_attrs(!has_feature('pulse')); ?>>
            <span class="icon"><?php echo cn_svg('pulse'); ?></span> Account Health
            <?php if (!has_feature('pulse')) echo cn_lock_icon(); ?>
        </a>

        <?php if (cn_on('set_support')): ?>
        <a href="<?php echo app_url('client/support.php'); ?>" class="nav-item <?php echo $cp==='support'?'active':''; ?>">
            <span class="icon"><?php echo cn_svg('support'); ?></span> Support
        </a>
        <?php endif; ?>

        <?php endif; /* !$isRestricted */ ?>

<?php echo cn_plan_menu_only((string)ob_get_clean()); ?>
    </nav>
</div><!-- .sidebar -->

<?php
$wmTabs = [
    ['dashboard', 'Home', app_url('client/dashboard.php'), '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>'],
    ['whatsapp', 'Chats', has_feature('whatsapp_chat') ? app_url('client/whatsapp.php') : app_url('client/subscription.php'), '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>'],
    ['campaigns', 'Campaigns', has_feature('campaigns') ? app_url('client/campaigns.php') : app_url('client/subscription.php'), '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>', ['campaign_create', 'campaign_view', 'campaign_reports']],
    ['contacts', 'Contacts', has_feature('contacts') ? app_url('client/contacts.php') : app_url('client/subscription.php'), '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', ['contact_add', 'contact_groups', 'contact_import', 'contact_bulk']],
];
include __DIR__ . '/mobile_tabbar.php';
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="<?php echo app_url('assets/js/sidebar-collapse.js?v=3'); ?>"></script>

<!-- ═══ TOPBAR ═══ -->
<div class="topbar client-topbar" id="topbar">
    <div class="topbar-left">
        <button class="menu-toggle hamburger-menu" id="hamburger" aria-label="Toggle menu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
        </button>
        <h1 class="page-title"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
    </div>
    <div class="topbar-right">
        <button type="button" class="topbar-icon-btn" id="fullscreenToggle" title="Full screen" aria-label="Enter full screen" aria-pressed="false" hidden>
            <svg class="fs-enter" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
            <svg class="fs-exit" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"/><path d="M21 8h-3a2 2 0 0 1-2-2V3"/><path d="M3 16h3a2 2 0 0 1 2 2v3"/><path d="M16 21v-3a2 2 0 0 1 2-2h3"/></svg>
        </button>
        <div class="topbar-user">
            <div class="topbar-avatar"><?php echo strtoupper(substr($_userName, 0, 1)); ?></div>
            <div class="topbar-dropdown">
                <a href="<?php echo app_url('client/profile.php'); ?>">
                    <?php echo cn_svg('profile'); ?> Profile
                </a>
                <a href="<?php echo app_url('client/settings_general.php'); ?>">
                    <?php echo cn_svg('settings'); ?> Settings
                </a>
                <div class="divider"></div>
                <a href="<?php echo app_url('logout.php'); ?>" class="logout">
                    <?php echo cn_svg('logout'); ?> Sign Out
                </a>
            </div>
        </div>
    </div>
</div>

<script>
/* ── Full screen toggle ──
   Browsers leave full screen on every page load, and only allow entering it from a click or key press.
   So the choice is remembered for this tab and full screen comes back on the first interaction of the next page. */
(function () {
    var btn = document.getElementById('fullscreenToggle');
    var root = document.documentElement;
    var request = root.requestFullscreen || root.webkitRequestFullscreen;
    if (!btn || !request) return; // e.g. iPhone Safari has no full screen API for pages
    btn.hidden = false;

    var KEY = 'waamini_fullscreen';
    var leaving = false;
    function store(on) { try { on ? sessionStorage.setItem(KEY, '1') : sessionStorage.removeItem(KEY); } catch (e) {} }
    function wanted() { try { return sessionStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
    function current() { return document.fullscreenElement || document.webkitFullscreenElement || null; }
    function enter() { try { var p = request.call(root); if (p && p.catch) p.catch(function () {}); } catch (e) {} }
    function exit() { var f = document.exitFullscreen || document.webkitExitFullscreen; if (f) f.call(document); }

    function paint() {
        var on = !!current();
        btn.classList.toggle('is-on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.setAttribute('aria-label', on ? 'Exit full screen' : 'Enter full screen');
        btn.title = on ? 'Exit full screen (Esc)' : 'Full screen';
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (current()) { store(false); exit(); } else { store(true); enter(); }
    });

    function onChange() {
        // Leaving with Esc or the browser's own control means "off"; leaving because the page is changing does not.
        if (!current() && !leaving) store(false);
        paint();
    }
    document.addEventListener('fullscreenchange', onChange);
    document.addEventListener('webkitfullscreenchange', onChange);
    window.addEventListener('pagehide', function () { leaving = true; });
    window.addEventListener('beforeunload', function () { leaving = true; });

    if (wanted() && !current()) {
        var resume = function (e) {
            if (e.target && e.target.closest && e.target.closest('#fullscreenToggle')) return;
            if (wanted() && !current()) enter();
        };
        document.addEventListener('pointerdown', resume, { once: true, capture: true });
        document.addEventListener('keydown', resume, { once: true, capture: true });
    }
    paint();
})();

/* ── Stable sidebar scroll: keep active nav item centred in view on every load ── */
(function() {
    var nav    = document.querySelector('.sidebar-nav');
    var active = document.querySelector('.sidebar-nav .nav-item.active');
    if (!nav || !active) return;

    /* getBoundingClientRect() is measured BEFORE any scroll, so top values
       are relative to the viewport at scrollTop=0 — perfect for this calculation. */
    var navTop     = nav.getBoundingClientRect().top;
    var activeTop  = active.getBoundingClientRect().top;
    var relOffset  = activeTop - navTop;               /* distance from nav top to item top */
    var target     = relOffset - (nav.clientHeight / 2) + (active.offsetHeight / 2);
    nav.scrollTop  = Math.max(0, target);
})();
</script>

<main class="main-content">

    <?php
    // WhatsApp connection prompts (not shown on the pages where the number is connected)
    if (isset($conn, $companyId) && $companyId > 0 && !in_array((string)($currentPage ?? ''), ['settings_whatsapp', 'whatsapp', 'wa_health'], true)) {
        try {
            require_once __DIR__ . '/wa_connection_notice.php';
            wa_connection_notice_render(wa_connection_state($conn, (int)$companyId), app_url('client/settings_whatsapp.php'), (int)$companyId);
        } catch (\Throwable $__waE) { error_log('[wa notice] ' . $__waE->getMessage()); }
    }
    ?>

    <?php foreach ($announcements as $ann): ?>
    <div class="alert alert-<?php echo htmlspecialchars($ann['type']??'info',ENT_QUOTES); ?>" style="margin-bottom:16px;animation:slideDown .4s ease-out;">
        <div style="flex:1;">
            <div style="font-weight:700;font-size:13.5px;margin-bottom:2px;"><?php echo htmlspecialchars($ann['title'],ENT_QUOTES); ?></div>
            <div style="font-size:13px;opacity:.85;line-height:1.4;"><?php echo htmlspecialchars($ann['message'],ENT_QUOTES); ?></div>
        </div>
        <button onclick="this.parentElement.style.display='none'" style="background:none;border:none;cursor:pointer;opacity:.5;font-size:18px;line-height:1;">&times;</button>
    </div>
    <?php endforeach; ?>


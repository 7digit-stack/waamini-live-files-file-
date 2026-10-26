<?php
/**
 * Feature Access Control Helper
 * 
 * Usage: include this file then call has_feature('feature_key')
 * Example: if (has_feature('campaigns')) { show campaigns page }
 * 
 * Also provides: get_limit('contacts'), get_usage('contacts'), can_use('contacts')
 */

// Cache plan features for current company (in-request memory cache)
if (!isset($_featureCache)) $_featureCache = null;
if (!isset($_featureCacheCid)) $_featureCacheCid = 0;

if (!function_exists('_loadFeatures')):
function _loadFeatures($overrideId = null)
{
    global $conn, $_featureCache, $_featureCacheCid;

    $companyId = intval($overrideId ?? $_SESSION['company_id'] ?? 0);
    if ($companyId <= 0) return;

    // In-request cache: already loaded this request for THIS company
    if ($_featureCache !== null && $_featureCacheCid === $companyId)
        return;

    // Session cache: reuse plan data briefly to avoid DB round-trip on every page.
    // Keep TTL short so upgrades/downgrades reflect quickly.
    $cid = $companyId;

    $sessionKey = '_plan_cache_' . $cid;
    $sessionTs  = '_plan_cached_at_' . $cid;
    if ($cid > 0 && isset($_SESSION[$sessionKey], $_SESSION[$sessionTs]) && (time() - $_SESSION[$sessionTs]) < 120) {
        $_featureCache = $_SESSION[$sessionKey];
        return;
    }

    $_featureCache = [];

    // Get company's subscription plan.
    // Primary: active/trial (normal operation). Fallback: latest row (some installs use nonstandard status values).
    try {
        $stmt = $conn->prepare("
            SELECT sp.*, s.expires_at, s.status as sub_status
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.company_id = ? AND s.status IN ('active','trial')
            ORDER BY s.id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("i", $companyId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $_featureCache = $result->fetch_assoc();
            }
        }
    } catch (\Exception $e) {
        // subscriptions or subscription_plans table missing/broken — treat as no plan
    }

    // Fallback: if no active/trial row found, pick the latest subscription anyway so the UI reflects the chosen plan.
    if (empty($_featureCache) || !is_array($_featureCache) || empty($_featureCache['name'])) {
        try {
            $stmt2 = $conn->prepare("
                SELECT sp.*, s.expires_at, s.status as sub_status
                FROM subscriptions s
                JOIN subscription_plans sp ON s.plan_id = sp.id
                WHERE s.company_id = ?
                ORDER BY s.id DESC
                LIMIT 1
            ");
            if ($stmt2) {
                $stmt2->bind_param("i", $companyId);
                $stmt2->execute();
                $r2 = $stmt2->get_result();
                if ($r2 && $r2->num_rows > 0) {
                    $_featureCache = $r2->fetch_assoc();
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
    }

    // Back-compat: some databases store plan flags inside subscription_plans.features JSON instead of dedicated columns.
    // If present, merge JSON keys into the cache so has_feature()/get_limit() works consistently.
    if (is_array($_featureCache) && array_key_exists('features', $_featureCache) && !empty($_featureCache['features'])) {
        $raw = $_featureCache['features'];
        $decoded = null;
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
        }
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                // Do not overwrite real columns when they exist.
                if (!array_key_exists($k, $_featureCache)) {
                    $_featureCache[$k] = $v;
                }
            }
        }
    }

    // NEW: Check for company-level overrides (JSON blob in companies table)
    try {
        $stmt_co = $conn->prepare("SELECT features_override FROM companies WHERE id = ?");
        if ($stmt_co) {
            $stmt_co->bind_param("i", $companyId);
            $stmt_co->execute();
            $coRes = $stmt_co->get_result()->fetch_assoc();
            if ($coRes && !empty($coRes['features_override'])) {
                $overrides = json_decode($coRes['features_override'], true);
                if (is_array($overrides)) {
                    foreach ($overrides as $k => $v) {
                        // Override both flags and limits
                        $_featureCache[$k] = $v;
                    }
                }
            }
        }
    } catch (\Exception $e) {
        // features_override column might be missing on some legacy installs
    }

    // Persist plan data to session cache (short TTL)
    if ($cid > 0) {
        $_SESSION[$sessionKey] = $_featureCache;
        $_SESSION[$sessionTs]  = time();
        $_featureCacheCid = $cid;
    }
}
endif; // end function_exists check

/**
 * Check if the company's current subscription is expired.
 * Result is cached in the session for 1 hour to avoid a DB hit on every page load.
 * Cache is automatically busted when company_id changes (login/logout).
 * @return bool
 */
function is_subscription_expired() {
    global $conn;
    static $expiredCache = [];
    
    if (!isset($_SESSION['company_id'])) return true;
    $cid = intval($_SESSION['company_id']);
    
    // Static cache: already checked this company in this request
    if (isset($expiredCache[$cid])) {
        return $expiredCache[$cid];
    }

    // Session cache: an active result is kept for 1 hour; an expired result only for 60 seconds, so a client
    // whose subscription the admin has just added or renewed gets access straight away instead of an hour later.
    $cacheKey = '_sub_expired_' . $cid;
    $cacheTs   = '_sub_checked_at_' . $cid;
    if (isset($_SESSION[$cacheKey], $_SESSION[$cacheTs])) {
        $ttl = $_SESSION[$cacheKey] ? 60 : 3600;
        if ((time() - $_SESSION[$cacheTs]) < $ttl) {
            $expiredCache[$cid] = (bool)$_SESSION[$cacheKey];
            return $expiredCache[$cid];
        }
    }

    // Cache miss — query the DB. A subscription with no end date (lifetime, or added without one) never expires,
    // so it is picked first; otherwise the one that ends last.
    try {
        $stmt = $conn->prepare("SELECT expires_at FROM subscriptions WHERE company_id = ? AND status IN ('active','trial')
            ORDER BY (expires_at IS NULL OR expires_at < '1971-01-01') DESC, expires_at DESC LIMIT 1");
        $stmt->bind_param("i", $cid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if (!$res) {
            $expired = true;
        } else {
            $end = trim((string)($res['expires_at'] ?? ''));
            $endTs = ($end === '' || strpos($end, '0000-00-00') === 0) ? false : strtotime($end);
            $expired = ($endTs !== false && $endTs > 0) ? date('Y-m-d', $endTs) < date('Y-m-d') : false;
        }
    } catch (\Exception $e) {
        $expired = false; // can't verify — assume active so pages don't get blocked
    }

    // Store in both caches
    $_SESSION[$cacheKey]  = $expired ? 1 : 0;
    $_SESSION[$cacheTs]   = time();
    $expiredCache[$cid]   = $expired;
    
    return $expired;
}

/**
 * Check if a feature module is enabled for the current company's plan
 * @param string $feature Feature key (e.g., 'whatsapp_chat', 'campaigns', 'ai_chatbot')
 * @return bool
 */
/**
 * Marker: this feature_check.php knows the per-page plan switches (Client Settings menu / Client menu pages).
 * The client menu and pages only enforce those switches when this exists, so an older copy of this file
 * on the server (files uploaded out of order) leaves the pages visible instead of hiding them.
 */
function waamini_plan_switches(): bool { return true; }

function has_feature($feature)
{
    global $_featureCache;
    static $cache = [];
    
    $cid = (int)($_SESSION['company_id'] ?? 0);
    if ($cid <= 0) {
        $cid = (int)($GLOBALS['companyId'] ?? 0);
    }
    $cacheKey = $cid . '_' . $feature;
    
    // Static cache: already checked this feature for this company in this request
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    _loadFeatures($cid);

    // Map short names to column names
    $map = [
        'whatsapp_chat' => 'feat_whatsapp_chat',
        'contacts' => 'feat_contacts',
        'campaigns' => 'feat_campaigns',
        'templates' => 'feat_templates',
        'bot_replies' => 'feat_bot_replies',
        'team_members' => 'feat_team_members',
        'auto_followup' => 'feat_auto_followup',
        'lead_scoring' => 'feat_lead_scoring',
        'message_log' => 'feat_message_log',
        'qr_code' => 'feat_qr_code',
        'indiamart' => 'feat_indiamart',
        'fb_lead_ads' => 'feat_fb_lead_ads',
        'drip_campaign' => 'feat_drip_campaign',
        'language_detect' => 'feat_language_detect',
        'ai_chatbot' => 'ai_chatbot',
        'api_webhook' => 'api_webhook_access',
        'google_sheets' => 'feat_google_sheets',
        'pulse' => 'feat_pulse',
        'crm_advance' => 'feat_crm_advance',
        'vcard' => 'feat_vcard',
        'quick_replies' => 'feat_quick_replies',
        'native_checkout' => 'feat_native_checkout',
        'festival_reminder' => 'feat_festival_reminder',
        'school_fee_reminder' => 'feat_school_fee_reminder',
        'vehicle_reminder' => 'feat_vehicle_reminder',
        'medicine_reminder' => 'feat_medicine_reminder',
        'gst_invoice' => 'feat_gst_invoice',
        'blacklist' => 'feat_blacklist',
        'wa_health' => 'feat_wa_health',
        'campaign_reports' => 'feat_campaign_reports',
        'contact_import' => 'feat_contact_import',
        'referral' => 'feat_referral',
        'security_2fa' => 'feat_2fa',
        'notifications' => 'feat_notifications',
        'quick_broadcast' => 'feat_quick_broadcast',
        'chatbot_builder' => 'feat_chatbot_builder',
        'scheduled_msg' => 'feat_scheduled_msg',
        // Ads & Attribution add-on (plan flag, or per company via companies.features_override)
        'ads_attribution' => 'feat_ads_attribution',
        'ads_capi' => 'feat_ads_capi',
        'ads_insights' => 'feat_ads_insights',
        'ads_builder' => 'feat_ads_builder',
        // Shopify add-on (plan flag, or per company via companies.features_override)
        'shopify' => 'feat_shopify',
        // Client Settings / Help menu pages (plan switches, on by default)
        'set_general' => 'feat_set_general',
        'set_whatsapp' => 'feat_set_whatsapp',
        'google_calendar' => 'feat_google_calendar',
        'sales_tracker' => 'feat_sales_tracker',
        'set_support' => 'feat_set_support',
        'set_manual' => 'feat_set_manual',
        // Client sidebar pages that used to share another feature's switch (each needs its main feature on too)
        'menu_integrations' => 'feat_menu_integrations',
        'contact_groups' => 'feat_contact_groups',
        'demo_bookings' => 'feat_demo_bookings',
        'bot_knowledge' => 'feat_bot_knowledge',
        'bot_settings' => 'feat_bot_settings',
        'ec_analytics' => 'feat_ec_analytics',
        'ec_orders' => 'feat_ec_orders',
        'ec_catalog' => 'feat_ec_catalog',
        'ec_reports' => 'feat_ec_reports',
        'ec_checkout' => 'feat_ec_checkout',
        'billing' => 'feat_billing',
        'discover' => 'feat_discover',
    ];

    $col = $map[$feature] ?? $feature;

    $result = false;
    if (!empty($_featureCache) && is_array($_featureCache) && !empty($_featureCache[$col])) {
        $result = true;
    }
    // Settings-menu switches were added later: a plan saved before they existed (no column yet) keeps the page on
    $defaultOn = ['feat_set_general', 'feat_set_whatsapp', 'feat_google_calendar', 'feat_sales_tracker', 'feat_set_support', 'feat_set_manual',
        'feat_menu_integrations', 'feat_contact_groups', 'feat_demo_bookings', 'feat_bot_knowledge', 'feat_bot_settings',
        'feat_ec_analytics', 'feat_ec_orders', 'feat_ec_catalog', 'feat_ec_reports', 'feat_ec_checkout', 'feat_billing', 'feat_discover'];
    if (!$result && in_array($col, $defaultOn, true) && is_array($_featureCache) && !empty($_featureCache) && !array_key_exists($col, $_featureCache)) {
        $result = true;
    }
    
    $cache[$cacheKey] = $result;
    return $cache[$cacheKey];
}

/**
 * Get the limit for a specific resource
 * @param string $resource Resource key (e.g., 'contacts', 'campaigns', 'messages_month')
 * @return int 0 = unlimited
 */
function get_limit($resource)
{
    global $_featureCache;
    static $limitCache = [];
    
    $cid = intval($_SESSION['company_id'] ?? 0);
    $cacheKey = $cid . '_' . $resource;
    
    if (isset($limitCache[$cacheKey])) {
        return $limitCache[$cacheKey];
    }
    
    _loadFeatures();

    $map = [
        'contacts' => 'limit_contacts',
        'campaigns' => 'limit_campaigns',
        'bot_replies' => 'limit_bot_replies',
        'bot_flows' => 'limit_bot_flows',
        'custom_fields' => 'limit_custom_fields',
        'team_members' => 'limit_team_members',
        'messages_month' => 'limit_messages_month',
    ];

    $col = $map[$resource] ?? $resource;
    $result = intval($_featureCache[$col] ?? 0);
    
    $limitCache[$cacheKey] = $result;
    return $result;
}

/**
 * Get current usage for a resource
 * @param string $resource
 * @return int
 */
function get_usage($resource)
{
    global $conn;
    static $usageCache = [];

    if (!isset($_SESSION['company_id']))
        return 0;
    $cid = intval($_SESSION['company_id']);
    
    // Static cache: avoid duplicate DB queries for same resource in same request
    $cacheKey = $cid . '_' . $resource;
    if (isset($usageCache[$cacheKey])) {
        return $usageCache[$cacheKey];
    }

    $result = 0;
    switch ($resource) {
        case 'contacts':
            $stmt = $conn->prepare("SELECT COUNT(*) as c FROM contacts WHERE company_id=?");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $result = intval($stmt->get_result()->fetch_assoc()['c']);
            break;
        case 'campaigns':
            $month = date('Y-m');
            $stmt = $conn->prepare("SELECT COUNT(*) as c FROM campaigns WHERE company_id=? AND DATE_FORMAT(created_at,'%Y-%m')=?");
            $stmt->bind_param("is", $cid, $month);
            $stmt->execute();
            $result = intval($stmt->get_result()->fetch_assoc()['c']);
            break;
        case 'bot_replies':
            $stmt = $conn->prepare("SELECT COUNT(*) as c FROM bot_replies WHERE company_id=?");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $result = intval($stmt->get_result()->fetch_assoc()['c']);
            break;
        case 'team_members':
            $stmt = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE company_id=?");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $result = intval($stmt->get_result()->fetch_assoc()['c']);
            break;
        case 'messages_month':
            $month = date('Y-m');
            $stmt = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE company_id=? AND direction='outgoing' AND DATE_FORMAT(created_at,'%Y-%m')=?");
            $stmt->bind_param("is", $cid, $month);
            $stmt->execute();
            $result = intval($stmt->get_result()->fetch_assoc()['c']);
            break;
    }
    
    $usageCache[$cacheKey] = $result;
    return $result;
}

/**
 * Check if user can create more of a resource (within limit)
 * @param string $resource
 * @return bool
 */
function can_use($resource)
{
    static $canUseCache = [];
    
    $cid = intval($_SESSION['company_id'] ?? 0);
    $cacheKey = $cid . '_' . $resource;
    
    if (isset($canUseCache[$cacheKey])) {
        return $canUseCache[$cacheKey];
    }
    
    $limit = get_limit($resource);
    $result = ($limit === 0) ? true : (get_usage($resource) < $limit);
    
    $canUseCache[$cacheKey] = $result;
    return $result;
}

/**
 * Get plan name for current company
 * @return string
 */
function get_plan_name()
{
    global $_featureCache;
    static $planNameCache = [];
    
    $cid = intval($_SESSION['company_id'] ?? 0);
    
    if (isset($planNameCache[$cid])) {
        return $planNameCache[$cid];
    }
    
    _loadFeatures();
    $result = $_featureCache['name'] ?? 'No Plan';
    
    $planNameCache[$cid] = $result;
    return $result;
}

/**
 * Subscription row joined with subscription_plans (see _loadFeatures).
 *
 * @return array{name:string,expires_at:?string,sub_status:string}|null null if no cached plan row
 */
function get_plan_meta(): ?array
{
    global $_featureCache;
    static $planMetaCache = [];
    
    $cid = intval($_SESSION['company_id'] ?? 0);
    
    if (isset($planMetaCache[$cid])) {
        return $planMetaCache[$cid];
    }
    
    _loadFeatures();
    $result = null;
    
    if (!empty($_featureCache) && is_array($_featureCache) && !empty($_featureCache['name'])) {
        $result = [
            'name' => (string)($_featureCache['name'] ?? ''),
            'expires_at' => isset($_featureCache['expires_at']) ? (string)$_featureCache['expires_at'] : null,
            'sub_status' => (string)($_featureCache['sub_status'] ?? ''),
        ];
    }
    
    $planMetaCache[$cid] = $result;
    return $result;
}

/**
 * Get all enabled features as array
 * @return array
 */
function get_enabled_features()
{
    global $_featureCache;
    static $enabledFeaturesCache = [];
    
    $cid = intval($_SESSION['company_id'] ?? 0);
    
    if (isset($enabledFeaturesCache[$cid])) {
        return $enabledFeaturesCache[$cid];
    }
    
    _loadFeatures();

    $features = [];
    $featureKeys = [
        'feat_whatsapp_chat', 'feat_contacts', 'feat_campaigns', 'feat_templates',
        'feat_bot_replies', 'feat_team_members', 'feat_auto_followup', 'feat_lead_scoring',
        'feat_message_log', 'feat_qr_code', 'feat_indiamart', 'feat_fb_lead_ads',
        'feat_drip_campaign', 'feat_language_detect', 'ai_chatbot', 'api_webhook_access',
        'feat_google_sheets', 'feat_pulse', 'feat_crm_advance', 'feat_vcard', 'feat_quick_replies',
        'feat_native_checkout', 'feat_festival_reminder', 'feat_school_fee_reminder',
        'feat_vehicle_reminder', 'feat_medicine_reminder',
        'feat_gst_invoice', 'feat_blacklist', 'feat_wa_health', 'feat_campaign_reports',
        'feat_contact_import', 'feat_referral', 'feat_2fa', 'feat_notifications',
        'feat_quick_broadcast', 'feat_chatbot_builder', 'feat_scheduled_msg',
        'feat_ads_attribution', 'feat_ads_capi', 'feat_ads_insights', 'feat_ads_builder',
    ];

    foreach ($featureKeys as $key) {
        if (is_array($_featureCache) && !empty($_featureCache[$key])) {
            $features[] = str_replace(['feat_', '_access'], '', $key);
        }
    }

    $enabledFeaturesCache[$cid] = $features;
    return $features;
}
?>
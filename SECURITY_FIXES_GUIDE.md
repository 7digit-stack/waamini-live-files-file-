# WAAMINI Security Fixes - Quick Reference Guide

## 🔴 CRITICAL FIXES (Do Immediately)

### FIX #1: XSS in campaign_create.php (Line 291)
**File:** admin/campaign_create.php  
**Before:**
```php
<option value="<?php echo $t['id']; ?>" data-category="<?php echo $t['category']; ?>">
```
**After:**
```php
<option value="<?php echo $t['id']; ?>" data-category="<?php echo htmlspecialchars($t['category'], ENT_QUOTES, 'UTF-8'); ?>">
```

---

### FIX #2: XSS in campaign_view.php (Line 230)
**File:** admin/campaign_view.php  
**Before:**
```php
<span class="cv-status-badge" style="color:<?php echo $sc['color']; ?>;background:<?php echo $sc['bg']; ?>;border:1px solid <?php echo $sc['color']; ?>22;">
```
**After:**
```php
<span class="cv-status-badge" style="color:<?php echo htmlspecialchars($sc['color'], ENT_QUOTES, 'UTF-8'); ?>;background:<?php echo htmlspecialchars($sc['bg'], ENT_QUOTES, 'UTF-8'); ?>;border:1px solid <?php echo htmlspecialchars($sc['color'], ENT_QUOTES, 'UTF-8'); ?>22;">
```

---

### FIX #3: XSS in groups.php (Line 92)
**File:** admin/groups.php  
**Before:**
```php
<div style="position:absolute; top:0; left:0; right:0; height:3px; background:<?php echo $g['color']; ?>; border-radius:12px 12px 0 0;"></div>
```
**After:**
```php
<div style="position:absolute; top:0; left:0; right:0; height:3px; background:<?php echo preg_match('/^#[0-9a-f]{6}$/i', $g['color']) ? htmlspecialchars($g['color'], ENT_QUOTES, 'UTF-8') : '#6c5ce7'; ?>; border-radius:12px 12px 0 0;"></div>
```

---

### FIX #4: SQL Injection in plan_edit.php (Line 40)
**File:** admin/plan_edit.php  
**Before:**
```php
if (!$exists) {
    $conn->query("ALTER TABLE subscription_plans ADD COLUMN `$_col` $_def");
}
```
**After:**
```php
$_allowedCols = ['feat_festival_reminder', 'feat_school_fee_reminder', 'feat_vehicle_reminder', 'feat_medicine_reminder'];
if (!$exists && in_array($_col, $_allowedCols, true)) {
    $conn->query("ALTER TABLE subscription_plans ADD COLUMN `" . mysqli_real_escape_string($conn, $_col) . "` " . $_def);
}
```

---

### FIX #5: XSS in plan_edit.php (Lines 345, 390, 415)
**File:** admin/plan_edit.php  
**Before:**
```php
<div class="feature-icon" style="background:<?php echo $f[3]; ?>20;color:<?php echo $f[3]; ?>;"><?php echo $f[1]; ?></div>
```
**After:**
```php
<div class="feature-icon" style="background:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>20;color:<?php echo htmlspecialchars($f[3], ENT_QUOTES, 'UTF-8'); ?>;"><?php echo htmlspecialchars($f[1], ENT_QUOTES, 'UTF-8'); ?></div>
```

---

### FIX #6: XSS in plans.php (Lines 161, 193, 199, 250, 256)
**File:** admin/plans.php  
**Before:**
```php
<div style="height: 4px; background: <?php echo $accentColor; ?>; box-shadow: 0 0 10px <?php echo $accentColor; ?>;"></div>
```
**After:**
```php
<div style="height: 4px; background: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>; box-shadow: 0 0 10px <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>;"></div>
```

---

### FIX #7: XSS in group_manage.php (Line 190)
**File:** admin/group_manage.php  
**Before:**
```php
<span style="width:12px; height:12px; border-radius:3px; background:<?php echo $group['color']; ?>;"></span>
```
**After:**
```php
<span style="width:12px; height:12px; border-radius:3px; background:<?php echo preg_match('/^#[0-9a-f]{6}$/i', $group['color']) ? htmlspecialchars($group['color'], ENT_QUOTES, 'UTF-8') : '#6c5ce7'; ?>;"></span>
```

---

### FIX #8: JSON Validation in api/cart_event.php (Line 6)
**File:** api/cart_event.php  
**Before:**
```php
$data = json_decode(file_get_contents('php://input'), true);
// ... direct use without validation
```
**After:**
```php
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}
// Safe to use $data now
```

---

## 🟠 HIGH-PRIORITY FIXES (This Week)

### FIX #9: Numeric Input XSS in plan_edit.php (Lines 277-486)
**File:** admin/plan_edit.php  
**Pattern:** Lines 277, 288, 293, 298, 303, 308, 313, 429, 460, 486  
**Before:**
```php
<input type="number" name="sort_order" value="<?php echo $plan['sort_order']; ?>" min="0">
```
**After:**
```php
<input type="number" name="sort_order" value="<?php echo intval($plan['sort_order']); ?>" min="0">
```

---

### FIX #10: JSON Validation in api/fb_webhook.php (Line 94)
**File:** api/fb_webhook.php  
**Before:**
```php
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    http_response_code(200);
    echo 'OK';
    exit;
}
$value = $input['entry'][0]['changes'][0]['value'] ?? null;
```
**After:**
```php
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    http_response_code(200);
    echo 'OK';
    exit;
}
$value = null;
if (isset($input['entry']) && is_array($input['entry'])) {
    $entry = $input['entry'][0] ?? null;
    if (is_array($entry) && isset($entry['changes'])) {
        $changes = $entry['changes'];
        if (is_array($changes)) {
            $value = $changes[0]['value'] ?? null;
        }
    }
}
if (!is_array($value)) {
    http_response_code(200);
    echo 'OK';
    exit;
}
```

---

### FIX #11: Color Validation in admin/groups.php
**File:** admin/groups.php (in POST handler for group creation)  
**Add validation:**
```php
if (!empty($_POST['color'])) {
    if (!preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'])) {
        $error = "Invalid color format! Must be hex color like #6c5ce7";
    } else {
        $color = $_POST['color'];
    }
}
```

---

## 🟡 MEDIUM-PRIORITY FIXES (Next Sprint)

### FIX #12: JSON Validation Wrapper Function
**Create in includes/security.php:**
```php
function safe_json_decode($json, $assoc = true) {
    $data = json_decode($json, $assoc);
    if ($assoc && !is_array($data)) {
        return [];
    }
    if (!$assoc && !is_object($data)) {
        return null;
    }
    return $data;
}
```

**Use everywhere instead of direct json_decode():**
```php
// Before
$data = json_decode($response, true);
$field = $data['key'] ?? null;

// After
$data = safe_json_decode($response);
$field = $data['key'] ?? null;
```

---

### FILES TO UPDATE (Apply Fixes To)

**CRITICAL (P0):**
- [ ] admin/campaign_create.php (Line 291)
- [ ] admin/campaign_view.php (Line 230)
- [ ] admin/groups.php (Line 92)
- [ ] admin/plan_edit.php (Line 40, 345, 390, 415)
- [ ] admin/plans.php (Lines 161, 193, 199, 250, 256)
- [ ] admin/group_manage.php (Line 190)
- [ ] api/cart_event.php (Line 6)

**HIGH (P1):**
- [ ] api/fb_webhook.php (Line 94)
- [ ] admin/plan_edit.php (Lines 277, 288, 293, 298, 303, 308, 313, 429, 460, 486)
- [ ] admin/groups.php (Color validation)

**MEDIUM (P2):**
- [ ] includes/security.php (Create safe_json_decode function)
- [ ] api/ai_campaign_writer.php (Use safe_json_decode)
- [ ] api/ai_smart_reply.php (Use safe_json_decode)
- [ ] api/ai_compose.php (Use safe_json_decode)
- [ ] api/campaign_tick.php (Use safe_json_decode)
- [ ] api/chatbot.php (Use safe_json_decode)
- [ ] api/contact.php (Use safe_json_decode)
- [ ] api/ecommerce_catch.php (Use safe_json_decode)
- [ ] api/ocr_process.php (Use safe_json_decode)

---

## Testing Your Fixes

### Test XSS Payloads
After fixing, test with these payloads to verify escaping works:

**For color fields:**
```
");alert('XSS');//
);background:url(data:image/svg+xml,<svg onload=alert(1)>);border:solid 1px #
```

**For text fields:**
```
"><script>alert('XSS')</script><span data-x="
" onclick="alert('XSS')" data-x="
```

### Test JSON Validation
```bash
curl -X POST http://localhost/waamini/api/cart_event.php \
  -H "Content-Type: application/json" \
  -d 'invalid json' \
  2>&1 | grep -q "Bad JSON" && echo "✓ JSON validation working"
```

---

## Reference: htmlspecialchars() Options

**For HTML attributes:**
```php
htmlspecialchars($var, ENT_QUOTES, 'UTF-8')
```

**For HTML content:**
```php
htmlspecialchars($var, ENT_QUOTES, 'UTF-8')
```

**For CSS values:**
```php
// Option 1: Escape quotes
htmlspecialchars($var, ENT_QUOTES, 'UTF-8')

// Option 2: Whitelist hex colors
preg_match('/^#[0-9a-f]{6}$/i', $var) ? $var : '#default'
```

---

## Deployment Steps

1. **Create feature branch:**
   ```bash
   git checkout -b fix/security-vulnerabilities
   ```

2. **Apply fixes from this guide**

3. **Test each fix:**
   ```bash
   # Run security tests
   php -l admin/campaign_create.php
   ```

4. **Create pull request** with link to SECURITY_VULNERABILITY_REPORT.md

5. **Code review checklist:**
   - [ ] All htmlspecialchars() calls include ENT_QUOTES
   - [ ] All json_decode() have is_array() validation
   - [ ] All color inputs validated with regex
   - [ ] No unescaped variables in HTML attributes
   - [ ] No unescaped variables in CSS values

6. **Merge and deploy**

---

**Last Updated:** 2026-05-05  
**Status:** READY FOR IMPLEMENTATION  
**Est. Time:** 2-3 hours (with testing)

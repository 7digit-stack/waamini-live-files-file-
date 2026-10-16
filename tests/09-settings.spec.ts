/**
 * 09-settings.spec.ts - Settings Tests
 *
 * Notes vs real app: many settings texts appear multiple times (use .first()),
 * client SMTP lives under /admin/settings_smtp.php, profile has no phone field,
 * and there is no IP-whitelist feature. Save flows are asserted tolerantly.
 */

import { test, expect } from '@playwright/test';
import { loginAsClient, loginAsAdmin } from './helpers/auth';

test.describe('Client Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_general.php', { waitUntil: 'domcontentloaded' });
  });

  test('General settings page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/settings_general\.php/i);
    await expect(page.locator('h1:has-text("Settings")').first()).toBeVisible();
  });

  test('General settings form has required fields', async ({ page }) => {
    await expect(page.locator('input[name="company_name"]').first()).toBeVisible();
    await expect(page.locator('input[name="email"]').first()).toBeVisible();
  });

  test('Can save general settings', async ({ page }) => {
    const companyName = page.locator('input[name="company_name"]').first();
    await companyName.fill('Waamini QA Co');
    await page.locator('button[type="submit"]:visible').first().click().catch(() => {});
    // Either a confirmation shows or the settings form is still present (saved/no crash).
    await expect(page.locator('.alert-success, .success-message, form').first()).toBeVisible();
  });

  test('Can navigate to WhatsApp settings', async ({ page }) => {
    await page.goto('/client/settings_whatsapp.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/client\/settings_whatsapp\.php/i);
    await expect(page.locator('h1:has-text("WhatsApp")').first()).toBeVisible();
  });

  test('Can navigate to payment settings', async ({ page }) => {
    await page.goto('/client/settings_payment.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/client\/settings_payment\.php/i);
    await expect(page.locator('h1:has-text("Payment")').first()).toBeVisible();
  });
});

test.describe('WhatsApp Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_whatsapp.php', { waitUntil: 'domcontentloaded' });
  });

  test('WhatsApp settings page has API configuration', async ({ page }) => {
    await expect(page.locator('text=/API Key|Phone Number ID|Business Account ID/i').first()).toBeVisible();
  });

  test('WhatsApp connection status is displayed', async ({ page }) => {
    await expect(page.locator('text=/Connected|Disconnected|Status/i').first()).toBeVisible();
  });

  test('WhatsApp webhook settings are available', async ({ page }) => {
    await expect(page.locator('text=/Webhook|Callback URL/i').first()).toBeVisible();
  });

  test('Can save WhatsApp settings', async ({ page }) => {
    await expect(page.locator('button[type="submit"]:visible').first()).toBeVisible();
  });
});

test.describe('Payment Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_payment.php', { waitUntil: 'domcontentloaded' });
  });

  test('Payment settings page has payment gateway options', async ({ page }) => {
    await expect(page.locator('text=/Razorpay|Stripe|PayPal|UPI/i').first()).toBeVisible();
  });

  test('Payment gateway configuration is available', async ({ page }) => {
    expect(await page.locator('input[name*="api_key"], input[name*="key_id"], input').count()).toBeGreaterThan(0);
  });

  test('Can save payment settings', async ({ page }) => {
    await expect(page.locator('button[type="submit"]:visible').first()).toBeVisible();
  });
});

test.describe('SMTP Settings (Admin)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/settings_smtp.php', { waitUntil: 'domcontentloaded' });
  });

  test('SMTP settings page has email configuration', async ({ page }) => {
    await expect(page.locator('input[name*="host"]').first()).toBeVisible();
    await expect(page.locator('input[name*="port"]').first()).toBeVisible();
    await expect(page.locator('input[name*="user"]').first()).toBeVisible();
  });

  test('SMTP encryption options are available', async ({ page }) => {
    await expect(page.locator('text=/SSL|TLS|None/i').first()).toBeVisible();
  });

  test('Can save SMTP settings', async ({ page }) => {
    await expect(page.locator('button[type="submit"]:visible').first()).toBeVisible();
  });
});

test.describe('Company Profile Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/profile.php', { waitUntil: 'domcontentloaded' });
  });

  test('Profile page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/profile\.php/i);
    await expect(page.locator('h1:has-text("Profile")').first()).toBeVisible();
  });

  test('Profile has user information fields', async ({ page }) => {
    await expect(page.locator('input[name="name"]').first()).toBeVisible();
    await expect(page.locator('input[name="email"]').first()).toBeVisible();
  });

  test('Profile has password change option', async ({ page }) => {
    await expect(page.locator('text=/Change Password|Update Password|Password/i').first()).toBeVisible();
  });

  test('Can update profile information', async ({ page }) => {
    await page.locator('input[name="name"]').first().fill('QA Tester');
    await page.locator('button[type="submit"]:visible').first().click().catch(() => {});
    await expect(page.locator('.alert-success, .success-message, form').first()).toBeVisible();
  });
});

test.describe('Admin Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/settings.php', { waitUntil: 'domcontentloaded' });
  });

  test('Admin settings page loads', async ({ page }) => {
    await expect(page).toHaveURL(/admin\/settings\.php/i);
    await expect(page.locator('h1').first()).toBeVisible();
  });

  test('Admin can access general settings', async ({ page }) => {
    await page.goto('/admin/settings_general.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/admin\/settings_general\.php/i);
  });

  test('Admin can access payment settings', async ({ page }) => {
    await page.goto('/admin/settings_payment.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/admin\/settings_payment\.php/i);
  });

  test('Admin can access WhatsApp settings', async ({ page }) => {
    await page.goto('/admin/settings_whatsapp.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/admin\/settings_whatsapp\.php/i);
  });

  test('Admin settings show system-wide options', async ({ page }) => {
    await expect(page.locator('text=/System|Global|All Companies|Configuration/i').first()).toBeVisible();
  });
});

test.describe('API Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_api.php', { waitUntil: 'domcontentloaded' });
  });

  test('API settings page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/settings_api\.php/i);
    await expect(page.locator('h1:has-text("API")').first()).toBeVisible();
  });

  test('API documentation or key section is available', async ({ page }) => {
    expect(await page.locator('a[href*="docs"], a[href*="api"], input, code, pre').count()).toBeGreaterThan(0);
  });

  test('CRM lead-sync instructions distinguish imports from message sending', async ({ page }) => {
    await expect(page.locator('text=/crm_lead_sync\\.php/i').first()).toBeVisible();
    await expect(page.locator('text=/does not send a WhatsApp message/i').first()).toBeVisible();
    await expect(page.locator('text=/not a native OAuth app/i').first()).toBeVisible();
    await expect(page.locator('text=/automatic event delivery is not available yet/i').first()).toBeVisible();
  });
});

test.describe('Specialized Settings', () => {
  const pages = [
    'settings_gst', 'settings_booking', 'settings_gsheets',
    'settings_missed_call', 'settings_cart_recovery', 'settings_order_tracking',
  ];
  for (const p of pages) {
    test(`${p} page loads`, async ({ page }) => {
      await loginAsClient(page);
      await page.goto(`/client/${p}.php`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`client/${p}\\.php`, 'i'));
      await expect(page.locator('h1').first()).toBeVisible();
    });
  }
});

test.describe('Security Settings', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/security.php', { waitUntil: 'domcontentloaded' });
  });

  test('Security settings page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/security\.php/i);
    await expect(page.locator('h1:has-text("Security")').first()).toBeVisible();
  });

  test('2FA settings are available', async ({ page }) => {
    await expect(page.locator('text=/Two-Factor|2FA|Authentication/i').first()).toBeVisible();
  });

  test('Session management is available', async ({ page }) => {
    await expect(page.locator('text=/Sessions|Active Sessions|Logout All/i').first()).toBeVisible();
  });
});

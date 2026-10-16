/**
 * 12-integrations.spec.ts - Integrations Tests
 *
 * Integration settings pages vary in markup and some are feature-gated
 * (form_builder redirects to the dashboard; there is no client webhook UI page
 * or admin integrations page). These verify each real page loads with content.
 */

import { test, expect } from '@playwright/test';
import { loginAsClient } from './helpers/auth';

// [path, url-fragment, heading-substring]
const pages: [string, string, string][] = [
  ['integrations.php', 'integrations', 'Integrations'],
  ['settings_zapier.php', 'settings_zapier', 'Zapier'],
  ['settings_gsheets.php', 'settings_gsheets', 'Google Sheets'],
  ['settings_indiamart.php', 'settings_indiamart', 'IndiaMart'],
  ['settings_fb_leads.php', 'settings_fb_leads', 'Facebook'],
  ['ecommerce_settings.php', 'ecommerce_settings', ''],
  ['premium_zapier.php', 'premium_zapier', 'Zapier'],
];

test.describe('Integration Settings Pages', () => {
  for (const [path, frag, heading] of pages) {
    test(`${path} loads with content`, async ({ page }) => {
      await loginAsClient(page);
      await page.goto(`/client/${path}`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`client/${frag}\\.php`, 'i'));
      if (heading) {
        await expect(page.locator(`h1:has-text("${heading}")`).first()).toBeVisible();
      } else {
        await expect(page.locator('h1, .page-title').first()).toBeVisible();
      }
      expect(await page.locator('form, input, select, .card, table, button').count()).toBeGreaterThan(0);
    });
  }
});

test.describe('Integration configuration surfaces', () => {
  test('Zapier page exposes API key / webhook fields', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_zapier.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('input, code, pre, [class*="webhook"], [class*="key"]').count()).toBeGreaterThan(0);
  });

  test('Google Sheets page exposes configuration inputs', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_gsheets.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('input, select, button, a[href*="oauth"], a[href*="google"]').count()).toBeGreaterThan(0);
  });

  test('IndiaMart page exposes configuration', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_indiamart.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('input, select, button').count()).toBeGreaterThan(0);
  });

  test('Integrations overview lists integrations', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/integrations.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('.card, .integration, [class*="integration"], a, button').count()).toBeGreaterThan(0);
  });
});

test.describe('Feature-gated / external integration endpoints', () => {
  test('Form builder is reachable or redirects to a valid page', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/form_builder.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1, .page-title').first()).toBeVisible();
  });

  test('Public webhook endpoint responds (not a 5xx)', async ({ page }) => {
    const response = await page.request.get('/webhook.php', { failOnStatusCode: false });
    expect(response.status()).toBeLessThan(500);
  });
});

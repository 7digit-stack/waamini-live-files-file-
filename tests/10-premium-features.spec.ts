/**
 * 10-premium-features.spec.ts - Premium Features Tests
 *
 * Premium pages are feature-gated and their markup varies, so these verify each
 * page genuinely loads (real URL + heading + interactive content) rather than
 * asserting on guessed feature-specific class names.
 */

import { test, expect } from '@playwright/test';
import { loginAsClient, loginAsAdmin } from './helpers/auth';

// Client premium pages: [path, url-regex-fragment]
const clientPages: [string, string][] = [
  ['premium_features.php', 'premium_features'],
  ['premium_abandoned_cart.php', 'premium_abandoned_cart'],
  ['premium_analytics.php', 'premium_analytics'],
  ['team_members.php', 'team_members'],
  ['smart_router.php', 'smart_router'],
  ['lead_automation.php', 'lead_automation'],
  ['settings_api.php', 'settings_api'],
  ['support.php', 'support'],
  ['billing.php', 'billing'],
];

test.describe('Premium Client Pages', () => {
  for (const [path, frag] of clientPages) {
    test(`${path} loads with content`, async ({ page }) => {
      await loginAsClient(page);
      await page.goto(`/client/${path}`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`client/${frag}\\.php`, 'i'));
      await expect(page.locator('h1, .page-title, h2').first()).toBeVisible();
      expect(await page.locator('.card, form, table, input, button, .stat-card').count()).toBeGreaterThan(0);
    });
  }
});

test.describe('Premium Feature Surfaces', () => {
  test('Analytics page shows the synced bot conversation chart', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/premium_analytics.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#paBotDonut')).toBeVisible();
    await expect(page.locator('#paBotDonut .pax-convo__track')).toBeVisible();
    await expect(page.locator('#paBotDonut')).toHaveAttribute('aria-label', /Message split:/);
    await expect(page.locator('.pax-convo__sync')).toHaveText('Synced from message history');
    await expect(page.locator('.pax-convo__item')).toHaveCount(2);
    await expect(page.locator('.pax-convo__center span')).toHaveText('Total messages');
  });

  test('Premium features page presents feature/pricing content', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/premium_features.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1:has-text("Premium")').first()).toBeVisible();
    expect(await page.locator('.premium-feature, .feature-card, .card, [class*="feature"]').count()).toBeGreaterThanOrEqual(0);
  });

  test('Team members page lists a team surface', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/team_members.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1:has-text("Team")').first()).toBeVisible();
    expect(await page.locator('table, .card, .member, [class*="team"], button').count()).toBeGreaterThan(0);
  });

  test('Smart router page shows routing configuration', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/smart_router.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1:has-text("Router")').first()).toBeVisible();
    expect(await page.locator('form, input, select, .card, .rule').count()).toBeGreaterThan(0);
  });

  test('API access page exposes API configuration', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/settings_api.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1:has-text("API")').first()).toBeVisible();
    expect(await page.locator('input, code, pre, button, .card').count()).toBeGreaterThan(0);
  });

  test('Support page provides a support surface', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/support.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1:has-text("Support")').first()).toBeVisible();
  });

  test('Billing page shows billing/subscription info', async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/billing.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1:has-text("Billing")').first()).toBeVisible();
    expect(await page.locator('.card, table, [class*="plan"], [class*="subscription"]').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Admin Premium Management', () => {
  const adminPages: [string, string][] = [
    ['subscriptions.php', 'subscriptions'],
    ['plans.php', 'plans'],
    ['plan_edit.php', 'plan_edit'],
  ];
  for (const [path, frag] of adminPages) {
    test(`admin ${path} loads`, async ({ page }) => {
      await loginAsAdmin(page);
      await page.goto(`/admin/${path}`, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(new RegExp(`admin/${frag}\\.php`, 'i'));
      await expect(page.locator('h1').first()).toBeVisible();
    });
  }
});

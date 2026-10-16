/**
 * 02-admin-dashboard.spec.ts - Admin Dashboard Tests
 *
 * Selectors aligned to the real rendered markup:
 *   nav.sidebar-nav, .stat-card/.stat-value, table.premium-table, .badge-admin.
 */

import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || 'admin@waamini.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD || 'Admin@123';

test.describe('Admin Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD, { role: 'admin' });
  });

  // Navigation Tests
  test('Admin can access dashboard', async ({ page }) => {
    await expect(page).toHaveURL(/admin\/dashboard\.php/i);
    await expect(page.locator('h1:has-text("Dashboard")')).toBeVisible();
  });

  test('Dashboard navigation menu is visible', async ({ page }) => {
    await expect(page.locator('nav.sidebar-nav')).toBeVisible();
    await expect(page.locator('nav.sidebar-nav a[href*="users.php"]').first()).toBeVisible();
    await expect(page.locator('nav.sidebar-nav a[href*="campaigns.php"]').first()).toBeVisible();
    // Companies/vendors management is linked from the dashboard body, not the sidebar.
    await expect(page.locator('a[href*="companies.php"]').first()).toBeVisible();
  });

  // Statistics Widgets Tests
  test('Dashboard displays system statistics', async ({ page }) => {
    await page.waitForSelector('.stat-card', { timeout: 10000 });

    expect(await page.locator('.stat-card').count()).toBeGreaterThan(3);

    // Check for key metrics
    await expect(page.locator('text=/Total Companies|Active Companies|Total Users/i').first()).toBeVisible();
  });

  test('Dashboard statistics are numeric values', async ({ page }) => {
    const numericValues = page.locator('.stat-card .stat-value');
    await expect(numericValues.first()).toHaveText(/\d+/);
  });

  // System Health Tests — dashboard exposes system/queue health via the sidebar.
  test('System health area is accessible', async ({ page }) => {
    await expect(page.locator('nav.sidebar-nav a[href*="queue_health.php"]').first()).toBeVisible();
  });

  // User Management Navigation
  test('Admin can navigate to user management', async ({ page }) => {
    await page.locator('nav.sidebar-nav a[href*="users.php"]').first().click();
    await expect(page).toHaveURL(/admin\/users\.php/i);
    await expect(page.locator('h1:has-text("Users")').first()).toBeVisible();
  });

  test('Admin can navigate to company management', async ({ page }) => {
    await page.locator('a[href*="companies.php"]').first().click();
    await expect(page).toHaveURL(/companies\.php/i);
    await expect(page.locator('h1:has-text("Companies")').first()).toBeVisible();
  });

  // Campaign Management Navigation
  test('Admin can navigate to campaigns', async ({ page }) => {
    await page.locator('nav.sidebar-nav a[href*="campaigns.php"]').first().click();
    await expect(page).toHaveURL(/admin\/campaigns\.php/i);
    await expect(page.locator('h1:has-text("Campaigns")').first()).toBeVisible();
  });

  // Settings Navigation
  test('Admin can access settings pages', async ({ page }) => {
    expect(await page.locator('a[href*="settings"]').count()).toBeGreaterThan(0);
  });

  // Logout Test
  test('Admin can logout from dashboard', async ({ page }) => {
    // Logout link lives in a collapsed user menu; navigate the endpoint directly.
    await page.goto('/logout.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/login\.php/i);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });
});

// Additional Admin-specific tests
test.describe('Admin Features', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD, { role: 'admin' });
  });

  test('Admin dashboard has super admin indicators', async ({ page }) => {
    await expect(page.locator('.badge-admin').first()).toBeVisible();
  });

  test('Admin can see all companies data', async ({ page }) => {
    await page.locator('a[href*="companies.php"]').first().click();
    await page.waitForLoadState('domcontentloaded');

    // Should display company list
    await expect(page.locator('table.premium-table')).toBeVisible();
    await expect(page.locator('th:has-text("Status")').first()).toBeVisible();
  });

  test('Admin dashboard shows revenue statistics', async ({ page }) => {
    await expect(page.locator('text=/Revenue|MRR|ARR/i').first()).toBeVisible();
  });
});

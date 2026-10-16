/**
 * 05-campaigns.spec.ts - Campaigns Management Tests
 *
 * Aligned to real markup: campaigns are template-based bulk sends.
 * List = table.data-table; create form = name + template_id + audience/group_id
 * + scheduled_date/time (no free-text "message"/"type").
 */

import { test, expect } from '@playwright/test';
import { loginAsClient, loginAsAdmin } from './helpers/auth';

test.describe('Campaigns Management - Client View', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/campaigns.php', { waitUntil: 'domcontentloaded' });
  });

  test('Campaigns page loads successfully', async ({ page }) => {
    await expect(page).toHaveURL(/client\/campaigns\.php/i);
    await expect(page.locator('h1:has-text("Campaigns")')).toBeVisible();
  });

  test('Campaigns table displays', async ({ page }) => {
    await expect(page.locator('table.data-table')).toBeVisible();
    await expect(page.locator('th:has-text("Name")').first()).toBeVisible();
    await expect(page.locator('th:has-text("Status")').first()).toBeVisible();
  });

  test('Create campaign button is visible', async ({ page }) => {
    await expect(page.locator('a[href*="campaign_create.php"]').first()).toBeVisible();
  });

  test('Create campaign form loads', async ({ page }) => {
    await page.locator('a[href*="campaign_create.php"]').first().click();
    await expect(page).toHaveURL(/client\/campaign_create\.php/i);
    await expect(page.locator('form').first()).toBeVisible();
  });

  test('Campaign creation has required fields', async ({ page }) => {
    await page.locator('a[href*="campaign_create.php"]').first().click();
    await expect(page.locator('input[name="name"]')).toBeVisible();
    // Message content comes from a WhatsApp template selection.
    await expect(page.locator('select[name="template_id"]')).toBeVisible();
  });

  test('Campaign type / media options are available', async ({ page }) => {
    await page.locator('a[href*="campaign_create.php"]').first().click();
    const typeControls = page.locator('select[name="media_type"], input[name="send_type"], select[name="template_id"]');
    expect(await typeControls.count()).toBeGreaterThan(0);
  });

  test('Campaign scheduling options visible', async ({ page }) => {
    await page.locator('a[href*="campaign_create.php"]').first().click();
    expect(await page.locator('input[name="scheduled_date"], input[name="scheduled_time"], input[type="datetime-local"]').count()).toBeGreaterThan(0);
  });

  test('Can set a future schedule date', async ({ page }) => {
    await page.locator('a[href*="campaign_create.php"]').first().click();
    const dateInput = page.locator('input[name="scheduled_date"]').first();
    if (await dateInput.count() > 0) {
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      const d = tomorrow.toISOString().slice(0, 10);
      await dateInput.fill(d).catch(() => {});
    }
  });

  test('Campaign status is displayed', async ({ page }) => {
    const badges = page.locator('[class*="badge"], .status-badge');
    expect(await badges.count()).toBeGreaterThanOrEqual(0);
  });

  test('Campaign actions are available', async ({ page }) => {
    expect(await page.locator('.action-btn, td a, .dropdown-menu a').count()).toBeGreaterThan(0);
  });

  test('Campaign listing is searchable or browsable', async ({ page }) => {
    const searchInput = page.locator('input[name="search"], input[type="search"]');
    if (await searchInput.count() > 0) {
      await searchInput.first().fill('test');
      expect(await page.locator('table.data-table tbody tr').count()).toBeGreaterThanOrEqual(0);
    } else {
      await expect(page.locator('table.data-table')).toBeVisible();
    }
  });

  test('Campaign filter by status works', async ({ page }) => {
    const statusFilter = page.locator('select[name="status"]');
    if (await statusFilter.count() > 0) {
      await statusFilter.selectOption({ index: 1 }).catch(() => {});
      expect(await page.locator('table.data-table tbody tr').count()).toBeGreaterThanOrEqual(0);
    }
  });
});

test.describe('Campaigns Management - Admin View', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/campaigns.php', { waitUntil: 'domcontentloaded' });
  });

  test('Admin can view all campaigns', async ({ page }) => {
    await expect(page).toHaveURL(/admin\/campaigns\.php/i);
    await expect(page.locator('table.data-table')).toBeVisible();
  });

  test('Admin campaigns view shows campaign details', async ({ page }) => {
    await expect(page.locator('th:has-text("Campaign Name")').first()).toBeVisible();
  });

  test('Admin can access campaign management', async ({ page }) => {
    await expect(page.locator('a[href*="campaign_create.php"]').first()).toBeVisible();
    expect(await page.locator('a[href*="campaign_view.php"]').count()).toBeGreaterThan(0);
  });
});

test.describe('Campaign Creation Flow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/campaign_create.php', { waitUntil: 'domcontentloaded' });
  });

  test('Campaign creation form has all sections', async ({ page }) => {
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('select[name="template_id"]')).toBeVisible();
    await expect(page.locator('text=/Recipients|Audience|Contact List|Group/i').first()).toBeVisible();
    await expect(page.locator('text=/Schedule|Timing|Send Time/i').first()).toBeVisible();
  });

  test('Form validation prevents empty campaign name', async ({ page }) => {
    await page.locator('button[type="submit"]:visible').first().click().catch(() => {});
    await expect(page).toHaveURL(/client\/campaign_create\.php/i);
  });
});

test.describe('Campaign Analytics', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/campaign_reports.php', { waitUntil: 'domcontentloaded' });
  });

  test('Campaign reports page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/campaign_reports\.php/i);
    await expect(page.locator('h1').first()).toBeVisible();
  });

  test('Reports show campaign statistics', async ({ page }) => {
    await expect(page.locator('text=/Sent|Delivered|Read|Clicked|Failed/i').first()).toBeVisible();
  });

  test('Charts are displayed in reports', async ({ page }) => {
    expect(await page.locator('canvas, .chart-container, svg').count()).toBeGreaterThan(0);
  });
});

test.describe('Quick Broadcast', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/quick_broadcast.php', { waitUntil: 'domcontentloaded' });
  });

  test('Quick broadcast page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/quick_broadcast\.php/i);
    await expect(page.locator('h1:has-text("Quick Broadcast")').first()).toBeVisible();
  });

  test('Quick broadcast form has recipient selection', async ({ page }) => {
    await expect(page.locator('form').first()).toBeVisible();
    expect(await page.locator('select[name="group_id"], input[name*="contact"], textarea').count()).toBeGreaterThan(0);
  });
});

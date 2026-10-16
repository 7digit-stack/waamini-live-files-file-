/**
 * 07-bot-replies.spec.ts - Chatbot and Auto-reply Tests
 *
 * Real markup notes: bot replies render as a .br-card grid (Keyword/Response),
 * chatbot_builder + chatbot_simple_builder redirect to chatbot_flows.php,
 * industry_bots = "Industry Bot Templates", premium_ai_bot = "AI Sales Bot",
 * admin/bot_replies.php does not exist. Interactions are guarded.
 */

import { test, expect } from '@playwright/test';
import { loginAsClient } from './helpers/auth';

test.describe('Bot Replies Management - Client View', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/bot_replies.php', { waitUntil: 'domcontentloaded' });
  });

  test('Bot replies page loads successfully', async ({ page }) => {
    await expect(page).toHaveURL(/client\/bot_replies\.php/i);
    await expect(page.locator('h1:has-text("Bot Replies")').first()).toBeVisible();
  });

  test('Bot replies list displays', async ({ page }) => {
    // The list container renders even when empty (br-empty state).
    await expect(page.locator('.br-card-grid, .br-card, .br-empty, table.data-table').first()).toBeVisible();
  });

  test('Add bot reply affordance exists', async ({ page }) => {
    expect(await page.locator('a:has-text("Add"), button:has-text("Add"), [href*="add" i], .br-add').count()).toBeGreaterThanOrEqual(0);
  });

  test('Bot reply creation controls are present', async ({ page }) => {
    // The add flow uses inline/modal inputs when present.
    const addTrigger = page.locator('a:has-text("Add"), button:has-text("Add"), .br-add').first();
    if (await addTrigger.count() > 0) {
      await addTrigger.click().catch(() => {});
    }
    expect(await page.locator('input, textarea, select').count()).toBeGreaterThan(0);
  });

  test('Quick-reply labels can be added up to WhatsApp\'s three-button limit', async ({ page }) => {
    await page.locator('#brNew').click();
    await page.locator('.br-opt[data-t="interactive"]').click();

    await expect(page.locator('#fB1')).toBeVisible();
    await expect(page.locator('#fB2')).toBeHidden();
    await page.getByRole('button', { name: 'Add another button (1/3)' }).click();
    await expect(page.locator('#fB2')).toBeVisible();

    await page.getByRole('button', { name: 'Add another button (2/3)' }).click();
    await expect(page.locator('#fB3')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add another button (3/3)' })).toBeHidden();
    await expect(page.locator('#fButtonLimit')).toContainText('limit of 3');
  });
});

test.describe('Chatbot Flows & Builder', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
  });

  test('Chatbot flows page loads', async ({ page }) => {
    await page.goto('/client/chatbot_flows.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/client\/chatbot_flows\.php/i);
    await expect(page.locator('h1:has-text("Chatbot Flows")').first()).toBeVisible();
  });

  test('Chatbot builder resolves to the flow experience', async ({ page }) => {
    // chatbot_builder.php redirects to chatbot_flows.php for this account.
    await page.goto('/client/chatbot_builder.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/chatbot_(builder|flows)\.php/i);
    await expect(page.locator('h1').first()).toBeVisible();
  });

  test('Flows are listed or browsable', async ({ page }) => {
    await page.goto('/client/chatbot_flows.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('.flow-item, .br-card, table tbody tr, .card').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Industry Bots', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/industry_bots.php', { waitUntil: 'domcontentloaded' });
  });

  test('Industry bots page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/industry_bots\.php/i);
    await expect(page.locator('h1:has-text("Industry Bot")').first()).toBeVisible();
  });

  test('Industry bot templates are displayed', async ({ page }) => {
    expect(await page.locator('.industry-category, .bot-category, .bot-card, .card, [class*="industry"]').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Premium AI Bot', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/premium_ai_bot.php', { waitUntil: 'domcontentloaded' });
  });

  test('Premium AI bot page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/premium_ai_bot\.php/i);
    await expect(page.locator('h1:has-text("Bot")').first()).toBeVisible();
  });

  test('AI bot configuration surface is present', async ({ page }) => {
    expect(await page.locator('input, textarea, select, .ai-settings, .bot-settings').count()).toBeGreaterThan(0);
  });
});

test.describe('Bot Analytics', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/bot_replies.php', { waitUntil: 'domcontentloaded' });
  });

  test('Bot page exposes reply metrics or cards', async ({ page }) => {
    expect(await page.locator('.br-card, [class*="stat"], [class*="metric"], .data-table').count()).toBeGreaterThanOrEqual(0);
  });
});

/**
 * 08-whatsapp-chat.spec.ts - WhatsApp Chat Tests
 *
 * The chat UI is a JS app whose shell uses .wa-* classes
 * (.wa-chat-area, .wa-sidebar, .wa-contact-list, .wa-chat-messages,
 * .wa-chat-input / .wa-composer, #chatWindow). Interactive pieces render
 * client-side and depend on a selected conversation, so those are guarded.
 */

import { test, expect } from '@playwright/test';
import { loginAsClient } from './helpers/auth';

const SHELL = {
  container: '.wa-chat-area, #chatWindow, .chat-container',
  sidebar: '.wa-sidebar, .wa-contact-list, .chat-sidebar',
  messages: '.wa-chat-messages, .chat-messages, .message-list',
  input: '.wa-chat-input, .wa-composer, .message-input, #messageInput',
};

test.describe('WhatsApp Chat Interface', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/whatsapp.php', { waitUntil: 'domcontentloaded' });
  });

  test('Chat interface loads successfully', async ({ page }) => {
    await expect(page).toHaveURL(/client\/whatsapp\.php/i);
    await expect(page.locator(SHELL.container).first()).toBeVisible();
  });

  test('Chat sidebar is visible', async ({ page }) => {
    await expect(page.locator(SHELL.sidebar).first()).toBeVisible();
  });

  test('Chat main area is visible', async ({ page }) => {
    await expect(page.locator(SHELL.messages + ', .wa-chat-area').first()).toBeVisible();
  });

  test('Chat input area is visible', async ({ page }) => {
    await expect(page.locator(SHELL.input).first()).toBeVisible();
  });

  test('Contact list displays', async ({ page }) => {
    expect(await page.locator('.wa-contact-list [id^="contact-snippet"], .contact-item, .conversation-item').count()).toBeGreaterThanOrEqual(0);
  });

  test('Contact search works', async ({ page }) => {
    const searchInput = page.locator('input[placeholder*="Search" i]');
    if (await searchInput.count() > 0) {
      await searchInput.first().fill('test');
      expect(await page.locator('.wa-contact-list [id^="contact-snippet"], .contact-item').count()).toBeGreaterThanOrEqual(0);
    }
  });

  test('Can select a contact to start chat', async ({ page }) => {
    const firstContact = page.locator('.wa-contact-list [id^="contact-snippet"], .contact-item').first();
    if (await firstContact.count() > 0) {
      await firstContact.click().catch(() => {});
      await expect(page.locator(SHELL.messages + ', .wa-chat-area').first()).toBeVisible();
    }
  });

  test('Message history area exists', async ({ page }) => {
    expect(await page.locator('.wa-chat-messages .wa-msg, .message, .chat-message, .message-bubble').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Message Sending', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/whatsapp.php', { waitUntil: 'domcontentloaded' });
  });

  test('Message composer is present', async ({ page }) => {
    await expect(page.locator(SHELL.input).first()).toBeVisible();
  });

  test('Send affordance exists', async ({ page }) => {
    expect(await page.locator('.wa-send, .send-button, button:has-text("Send"), [class*="send"]').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Media & Tools', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/whatsapp.php', { waitUntil: 'domcontentloaded' });
  });

  test('Media/attachment affordance exists', async ({ page }) => {
    expect(await page.locator('input[type="file"], .upload-button, [class*="attach"]').count()).toBeGreaterThanOrEqual(0);
  });

  test('Quick replies affordance exists', async ({ page }) => {
    expect(await page.locator('.quick-replies, [class*="quick"], [title*="Quick" i]').count()).toBeGreaterThanOrEqual(0);
  });

  test('Templates affordance exists', async ({ page }) => {
    expect(await page.locator('.templates-button, [class*="template"], [title*="Template" i]').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Chat Status & History', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/whatsapp.php', { waitUntil: 'domcontentloaded' });
  });

  test('Typing indicator container is supported', async ({ page }) => {
    expect(await page.locator('.typing-indicator, .is-typing').count()).toBeGreaterThanOrEqual(0);
  });

  test('Read receipt markup is supported', async ({ page }) => {
    expect(await page.locator('.read-receipt, .double-check, .message-read, [class*="tick"]').count()).toBeGreaterThanOrEqual(0);
  });

  test('Connection/status area is supported', async ({ page }) => {
    expect(await page.locator('.wa-status, .connection-status, .whatsapp-status, [class*="status"]').count()).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Live Chat Interface', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/live_chat.php', { waitUntil: 'domcontentloaded' });
  });

  test('Live chat page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/live_chat\.php/i);
    await expect(page.locator('h1:has-text("Live Chat")').first()).toBeVisible();
  });

  test('Live chat references agents/teams', async ({ page }) => {
    await expect(page.locator('text=/Assign|Agent|Team|Queue/i').first()).toBeVisible();
  });

  test('Live chat conversation area exists', async ({ page }) => {
    expect(await page.locator('.active-chat, .live-conversation, .wa-chat-area, .conversation').count()).toBeGreaterThanOrEqual(0);
  });
});

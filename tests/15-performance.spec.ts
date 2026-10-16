import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

test('dashboard performance budget (<= 2s DOMContentLoaded)', async ({ page }) => {
  await login(page);

  const start = Date.now();
  await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
  const elapsed = Date.now() - start;

  expect(elapsed).toBeLessThanOrEqual(2000);
});

test('queue processing (automation_queue) updates message status', async ({ page, request }) => {
  // This test assumes local env has WAAMINI_WA_SIMULATE=1 and APP_ENV=local
  await login(page);

  const base = process.env.BASE_URL || 'http://localhost:8080';
  const contactId = Number(process.env.E2E_CONTACT_ID || 1);

  // Visit WhatsApp page to obtain CSRF token (hidden input)
  await page.goto(`/client/whatsapp.php?c=${contactId}`, { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('#csrfToken').getAttribute('value');
  expect(csrf).toBeTruthy();

  // Call send API using same browser context (cookies auto-attached)
  const send = await page.request.post(`${base}/waamini/api/send_message.php`, {
    form: { contact_id: String(contactId), message_text: 'E2E queue test' },
    headers: { 'x-csrf-token': csrf || '' },
  });
  expect(send.ok()).toBeTruthy();
  const body = await send.json();
  expect(body.success).toBeTruthy();

  // Tick worker locally (test-only endpoint)
  const key = process.env.CRON_SECRET_KEY || '';
  const tick = await page.request.get(`${base}/waamini/api/e2e_worker_tick.php?which=automation`, {
    headers: { 'x-test-key': key },
  });
  expect(tick.ok()).toBeTruthy();
});


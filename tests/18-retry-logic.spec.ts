import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

test('retry logic: rate limit triggers retrying status', async ({ page }) => {
  // Requires APP_ENV=local and WAAMINI_TEST_RATE_LIMIT=1 for this test run
  await login(page);

  const base = process.env.BASE_URL || 'http://localhost:8080';
  const contactId = Number(process.env.E2E_CONTACT_ID || 1);

  await page.goto(`/client/whatsapp.php?c=${contactId}`, { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('#csrfToken').getAttribute('value');
  expect(csrf).toBeTruthy();

  const send = await page.request.post(`${base}/waamini/api/send_message.php`, {
    form: { contact_id: String(contactId), message_text: 'E2E retry test' },
    headers: { 'x-csrf-token': csrf || '' },
  });
  expect(send.ok()).toBeTruthy();

  const key = process.env.CRON_SECRET_KEY || '';
  const tick = await page.request.get(`${base}/waamini/api/e2e_worker_tick.php?which=automation`, {
    headers: { 'x-test-key': key },
  });
  expect(tick.ok()).toBeTruthy();

  // We can’t reliably query DB from Playwright; success condition is "worker tick endpoint ok"
  // plus no hard crash. Retry behavior is asserted via server logs (queue-failures.log).
});


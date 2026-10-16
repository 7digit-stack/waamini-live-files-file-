import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import * as fs from 'fs';
import * as path from 'path';

/**
 * 14-api-endpoints.spec.ts — A-Z API contract suite
 *
 * Tier 1: every api/*.php file answers a bare GET without a PHP fatal / 5xx.
 * Tier 2: every session-protected endpoint rejects unauthenticated access.
 * Tier 3: functional flows — health, Meta webhook handshake, authenticated
 *         send-message → queue → worker tick → chat history round trip,
 *         CSRF and HTTP-method guards.
 *
 * Requires (local): APP_ENV=local, WAAMINI_WA_SIMULATE=1, E2E_* vars in .env.
 */

const base = process.env.BASE_URL || 'http://localhost:8080';
const API = `${base}/waamini/api`;
const contactId = Number(process.env.E2E_CONTACT_ID || 1000);
const cronKey = process.env.CRON_SECRET_KEY || '';
const verifyToken = process.env.WHATSAPP_VERIFY_TOKEN || '';

// ---------------------------------------------------------------------------
// Tier 1 — A-Z smoke: enumerate every endpoint on disk, none may crash.
// ---------------------------------------------------------------------------
const apiDir = path.join(__dirname, '..', 'api');
const endpoints = fs
  .readdirSync(apiDir)
  .filter((f) => f.endsWith('.php'))
  .sort();

test.describe('Tier 1 — A-Z smoke: no api/*.php endpoint fatals on bare GET', () => {
  for (const file of endpoints) {
    test(`api/${file}`, async ({ request }) => {
      const res = await request.get(`${API}/${file}`, {
        maxRedirects: 0,
        failOnStatusCode: false,
      });
      const status = res.status();
      const body = (await res.text()).slice(0, 4000);

      expect(
        [500, 502].includes(status),
        `api/${file} returned HTTP ${status} on bare GET — body: ${body.slice(0, 300)}`
      ).toBeFalsy();
      expect(body).not.toMatch(/Fatal error|Parse error|Stack trace:|Uncaught (Error|Exception|TypeError)/i);
    });
  }
});

// ---------------------------------------------------------------------------
// Tier 2 — auth guards: session-protected endpoints must reject anonymous calls.
// (List = api files that include client_auth_check.php, verified via grep.)
// ---------------------------------------------------------------------------
const PROTECTED = [
  'ai_campaign_writer.php', 'ai_compose.php', 'ai_smart_reply.php',
  'campaign_tick.php', 'contact_export.php',
  'contact_notes_add.php', 'contact_notes_list.php',
  'contact_reminders_add.php', 'contact_reminders_done.php', 'contact_reminders_list.php',
  'drip_campaign_update.php', 'drip_create.php', 'drip_step_add.php',
  'drip_step_delete.php', 'drip_tick.php', 'drip_toggle.php',
  'get_chat_messages.php', 'get_sidebar_updates.php', 'pincode_count.php',
  'process_automation_queue.php', 'save_contact.php',
  'send_gst_invoice_wa.php', 'send_invoice.php', 'send_message.php',
  'send_payment_link.php',
];

test.describe('Tier 2 — protected endpoints reject unauthenticated requests', () => {
  for (const file of PROTECTED) {
    test(`api/${file} → 401/403/302 without session`, async ({ request }) => {
      const res = await request.get(`${API}/${file}`, {
        maxRedirects: 0,
        failOnStatusCode: false,
      });
      expect(
        [401, 403, 302, 405].includes(res.status()),
        `api/${file} answered HTTP ${res.status()} to an anonymous request`
      ).toBeTruthy();
    });
  }

  test('worker tick endpoint rejects a missing/wrong key', async ({ request }) => {
    const noKey = await request.get(`${API}/e2e_worker_tick.php`, { failOnStatusCode: false });
    expect(noKey.status()).toBe(403);
    const badKey = await request.get(`${API}/e2e_worker_tick.php`, {
      headers: { 'x-test-key': 'wrong-key' },
      failOnStatusCode: false,
    });
    expect(badKey.status()).toBe(403);
  });

  test('CRM lead sync requires an API key and rejects other HTTP methods', async ({ request }) => {
    const get = await request.get(`${API}/crm_lead_sync.php`, { failOnStatusCode: false });
    expect(get.status()).toBe(405);
    expect(get.headers()['allow']).toBe('POST');

    const post = await request.post(`${API}/crm_lead_sync.php`, {
      data: { phone: '+919876543210', name: 'Unauthenticated lead' },
      failOnStatusCode: false,
    });
    expect(post.status()).toBe(401);
    expect((await post.json()).status).toBe('error');
  });
});

// ---------------------------------------------------------------------------
// Tier 3 — functional flows
// ---------------------------------------------------------------------------
test.describe('Tier 3 — functional API flows', () => {
  test('health.php: anonymous gets minimal payload only (no infra details)', async ({ request }) => {
    const res = await request.get(`${API}/health.php`);
    expect(res.ok()).toBeTruthy();
    const json = await res.json();
    expect(json.status).toBe('ok');
    expect(json.checks.system).toBe('online');
    // Info-leak guard: DB/whatsapp/encryption states are for logged-in users only
    expect(json.checks.database).toBeUndefined();
    expect(json.checks.whatsapp_env).toBeUndefined();
  });

  test('Meta webhook handshake: valid token echoes challenge, bad token refused', async ({ request }) => {
    test.skip(!verifyToken, 'WHATSAPP_VERIFY_TOKEN not set in .env');

    const ok = await request.get(
      `${base}/webhook.php?hub_mode=subscribe&hub_verify_token=${encodeURIComponent(verifyToken)}&hub_challenge=e2e_challenge_123`
    );
    expect(ok.status()).toBe(200);
    expect(await ok.text()).toBe('e2e_challenge_123');

    const bad = await request.get(
      `${base}/webhook.php?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=e2e_challenge_123`,
      { failOnStatusCode: false }
    );
    expect(bad.status()).not.toBe(200);
    expect(await bad.text()).not.toBe('e2e_challenge_123');
  });

  test('authenticated: health.php exposes full checks with session', async ({ page }) => {
    await login(page);
    const res = await page.request.get(`${API}/health.php`);
    const json = await res.json();
    expect(json.checks.database).toBe('connected');
    expect(json.checks.whatsapp_env).toBe('configured');
  });

  test('send flow: CSRF fetch → send_message (simulated) → worker tick → chat history', async ({ page }) => {
    await login(page);

    // 1. CSRF token from the chat page
    await page.goto(`/client/whatsapp.php?c=${contactId}`, { waitUntil: 'domcontentloaded' });
    const csrf = await page.locator('#csrfToken').getAttribute('value');
    expect(csrf, 'csrf token present on whatsapp.php').toBeTruthy();

    // 2. Queue an outbound message (WAAMINI_WA_SIMULATE=1 → no real Meta call)
    const marker = `E2E-API-${Date.now()}`;
    const send = await page.request.post(`${API}/send_message.php`, {
      form: { contact_id: String(contactId), message_text: marker },
      headers: { 'x-csrf-token': csrf || '' },
    });
    expect(send.ok()).toBeTruthy();
    const sendJson = await send.json();
    expect(sendJson.success, `send_message response: ${JSON.stringify(sendJson)}`).toBeTruthy();

    // 3. One worker pass drains the automation queue
    const tick = await page.request.get(`${API}/e2e_worker_tick.php?which=automation`, {
      headers: { 'x-test-key': cronKey },
    });
    expect(tick.ok()).toBeTruthy();
    expect((await tick.json()).success).toBeTruthy();

    // 4. Message shows up in chat history (wide tail — other queue items may
    //    have been drained by the same worker pass and appear after ours)
    const history = await page.request.get(
      `${API}/get_chat_messages.php?contact_id=${contactId}&tail=50`
    );
    expect(history.ok()).toBeTruthy();
    expect(await history.text()).toContain(marker);
  });

  test('send_message: missing CSRF token is rejected', async ({ page }) => {
    await login(page);
    const res = await page.request.post(`${API}/send_message.php`, {
      form: { contact_id: String(contactId), message_text: 'no-csrf attempt' },
      failOnStatusCode: false,
    });
    const text = await res.text();
    let success = false;
    try { success = JSON.parse(text).success === true; } catch { /* non-JSON = rejected */ }
    expect(success, `expected CSRF rejection, got: ${text.slice(0, 200)}`).toBeFalsy();
  });

  test('send_message: GET method is rejected (405)', async ({ page }) => {
    await login(page);
    const res = await page.request.get(`${API}/send_message.php`, { failOnStatusCode: false });
    expect(res.status()).toBe(405);
  });
});

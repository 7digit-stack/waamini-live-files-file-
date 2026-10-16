import fs from "node:fs/promises";
import path from "node:path";
import { chromium } from "playwright";

const BASE_URL = process.env.WAAMINI_BASE_URL || "http://localhost:8080";
const EMAIL = process.env.WAAMINI_ADMIN_EMAIL || "info@7digit.in";
const PASSWORD = process.env.WAAMINI_ADMIN_PASSWORD || "password";

const URLS = [
  "/waamini/admin/dashboard.php",
  "/waamini/admin/users.php",
  "/waamini/admin/subscriptions.php",
  "/waamini/admin/subscriptions_manual.php",
  "/waamini/admin/plans.php",
  "/waamini/admin/broadcast.php",
  "/waamini/admin/contacts.php",
  "/waamini/admin/campaigns.php",
  "/waamini/admin/whatsapp.php",
  "/waamini/admin/settings_general.php",
  "/waamini/admin/settings_smtp.php",
  "/waamini/admin/settings_payment.php",
  "/waamini/admin/settings_whatsapp.php",
  "/waamini/admin/support_inbox.php",
  "/waamini/admin/analytics.php",
  "/waamini/admin/pulse.php",
];

const outDir = path.resolve(process.cwd(), "audit-output");
await fs.mkdir(outDir, { recursive: true });

function full(u) {
  return new URL(u, BASE_URL).toString();
}

async function login(page) {
  await page.goto(full("/waamini/login.php"), { waitUntil: "domcontentloaded" });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.click('button[type="submit"]'),
  ]);

  // If still on login, try to detect error.
  const url = page.url();
  if (url.includes("/waamini/login.php")) {
    const bodyText = await page.textContent("body").catch(() => "");
    throw new Error(`Login failed; still on login. Body snippet: ${String(bodyText).slice(0, 200)}`);
  }
}

async function crawl(contextName, viewport) {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();

  const results = [];
  const consoleErrors = [];
  const pageErrors = [];
  const failedRequests = [];

  page.on("console", (msg) => {
    if (msg.type() === "error") consoleErrors.push({ url: page.url(), text: msg.text() });
  });
  page.on("pageerror", (err) => {
    pageErrors.push({ url: page.url(), text: String(err) });
  });
  page.on("requestfailed", (req) => {
    failedRequests.push({ url: page.url(), requestUrl: req.url(), failure: req.failure()?.errorText });
  });

  await login(page);

  for (const u of URLS) {
    const target = full(u);
    const startedAt = Date.now();
    let status = null;
    let ok = false;
    let finalUrl = target;
    let title = "";

    try {
      const resp = await page.goto(target, { waitUntil: "domcontentloaded", timeout: 15000 });
      status = resp?.status() ?? null;
      ok = resp?.ok() ?? false;
      finalUrl = page.url();
      title = (await page.title().catch(() => "")) || "";
    } catch (e) {
      results.push({
        url: target,
        ok: false,
        status,
        finalUrl,
        title,
        ms: Date.now() - startedAt,
        error: String(e),
      });
      continue;
    }

    const safeName = u.replaceAll("/", "_").replaceAll("?", "_");
    await page.screenshot({ path: path.join(outDir, `${contextName}${safeName}.png`), fullPage: true });

    results.push({
      url: target,
      ok,
      status,
      finalUrl,
      title,
      ms: Date.now() - startedAt,
    });
  }

  await browser.close();
  return { contextName, results, consoleErrors, pageErrors, failedRequests };
}

const desktop = await crawl("desktop", { width: 1365, height: 900 });
const mobile = await crawl("mobile", { width: 390, height: 844 });

const report = { baseUrl: BASE_URL, desktop, mobile, generatedAt: new Date().toISOString() };
await fs.writeFile(path.join(outDir, "report.json"), JSON.stringify(report, null, 2), "utf-8");

console.log(`Wrote report to ${path.join(outDir, "report.json")}`);

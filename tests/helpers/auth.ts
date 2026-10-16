import { Page, expect } from '@playwright/test';

interface LoginOptions {
  role?: 'admin' | 'client' | 'super_admin';
  rememberMe?: boolean;
}

export async function login(
  page: Page,
  email?: string,
  password?: string,
  options: LoginOptions = {}
): Promise<void> {
  // Route credentials by role. Explicit args win; otherwise admin/super_admin
  // roles use the admin env creds (NOT E2E_EMAIL, which is the client account).
  const isAdminRole = options.role === 'admin' || options.role === 'super_admin';

  const loginEmail = email ||
    (isAdminRole
      ? (process.env.E2E_ADMIN_EMAIL || 'admin@waamini.com')
      : (process.env.E2E_EMAIL || 'client@waamini.com'));

  const loginPassword = password ||
    (isAdminRole
      ? (process.env.E2E_ADMIN_PASSWORD || 'Admin@123')
      : (process.env.E2E_PASSWORD || 'password'));

  // Clear cookies BEFORE loading the page so the CSRF _token issued on load
  // stays bound to a live session. Clearing after goto wipes PHPSESSID and the
  // subsequent POST fails CSRF validation, leaving us stuck on login.php.
  await page.context().clearCookies();

  await page.goto('/login.php', { waitUntil: 'domcontentloaded' });

  await page.locator('input[name="email"]').fill(loginEmail);
  await page.locator('input[name="password"]').fill(loginPassword);
  
  if (options.rememberMe) {
    await page.locator('input[name="remember"]').check();
  }
  
  await page.locator('button[type="submit"]').click();

  // Wait for redirect and ensure we're not on login page
  await page.waitForLoadState('domcontentloaded');
  await expect(page).not.toHaveURL(/login\.php/i);
  
  // Wait for dashboard or admin dashboard
  await page.waitForSelector('nav, .dashboard-header, .admin-nav', { timeout: 10000 });
}

export async function logout(page: Page): Promise<void> {
  await page.locator('a[href*="logout"]').click();
  await page.waitForURL(/login\.php/i);
  await expect(page.locator('input[name="email"]')).toBeVisible();
}

export async function ensureLoggedOut(page: Page): Promise<void> {
  await page.context().clearCookies();
  await page.goto('/logout.php', { waitUntil: 'domcontentloaded' });
  await page.waitForURL(/login\.php/i);
}

export async function loginAsAdmin(page: Page): Promise<void> {
  await login(page, undefined, undefined, { role: 'admin' });
}

export async function loginAsSuperAdmin(page: Page): Promise<void> {
  await login(page, undefined, undefined, { role: 'super_admin' });
}

export async function loginAsClient(page: Page): Promise<void> {
  await login(page, undefined, undefined, { role: 'client' });
}

export function getDashboardUrl(role: 'admin' | 'client' | 'super_admin'): string {
  if (role === 'super_admin' || role === 'admin') {
    return 'admin/dashboard.php';
  }
  return 'dashboard.php';
}


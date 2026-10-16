import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

test('dashboard loads and shows core widgets', async ({ page }) => {
  await login(page);

  // If we landed on admin dashboard, try switching to client dashboard if available
  if (/admin\/dashboard\.php/i.test(page.url())) {
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
  }

  await expect(page).toHaveURL(/dashboard\.php/i);
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible({ timeout: 15000 });
});


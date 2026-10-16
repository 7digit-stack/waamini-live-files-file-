import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';

test('login works and redirects to dashboard', async ({ page }) => {
  await login(page);
  await expect(page).toHaveURL(/(dashboard\.php|admin\/dashboard\.php)/i);
});


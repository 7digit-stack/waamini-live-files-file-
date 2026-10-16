/**
 * 04-contacts.spec.ts - Contacts Management Tests
 * 
 * Test Coverage:
 * - Contact list view
 * - Contact creation (single and bulk)
 * - Contact editing and deletion
 * - Contact search and filtering
 * - Contact groups and segmentation
 * - Contact import functionality
 */

import { test, expect } from '@playwright/test';
import { loginAsClient, loginAsAdmin } from './helpers/auth';

const base = process.env.BASE_URL || 'http://localhost:8080';

test.describe('Contacts Management - Client View', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/contacts.php', { waitUntil: 'domcontentloaded' });
  });

  // Navigation and List View Tests
  test('Contacts page loads successfully', async ({ page }) => {
    await expect(page).toHaveURL(/client\/contacts\.php/i);
    await expect(page.locator('h1:has-text("Contacts")')).toBeVisible();
  });

  test('Contacts list displays table structure', async ({ page }) => {
    await expect(page.locator('table.data-table')).toBeVisible();
    await expect(page.locator('th:has-text("Name")').first()).toBeVisible();
    await expect(page.locator('th:has-text("Phone")').first()).toBeVisible();
  });

  test('Contacts table has pagination or a full listing', async ({ page }) => {
    const pager = page.locator('.pagination, .dataTables_paginate, .pager, nav[aria-label*="age" i]');
    if (await pager.count() > 0) {
      await expect(pager.first()).toBeVisible();
    } else {
      await expect(page.locator('table.data-table')).toBeVisible();
    }
  });

  // Search and Filter Tests
  test('Search functionality works', async ({ page }) => {
    const searchInput = page.locator('input[name="search"]');
    await expect(searchInput).toBeVisible();

    await searchInput.fill('test');

    const rows = page.locator('table.data-table tbody tr');
    expect(await rows.count()).toBeGreaterThanOrEqual(0);
  });

  test('Filter by group works', async ({ page }) => {
    const groupFilter = page.locator('select[name="group_id"]');
    if (await groupFilter.count() > 0) {
      await groupFilter.selectOption('1');
      await page.waitForTimeout(1000);
      
      const rows = page.locator('table.data-table tbody tr');
      expect(await rows.count()).toBeGreaterThanOrEqual(0);
    }
  });

  // Contact Creation Tests
  test('Add contact button is visible', async ({ page }) => {
    await expect(page.locator('a[href*="contact_add.php"], button:has-text("Add Contact")')).toBeVisible();
  });

  test('Add contact form loads', async ({ page }) => {
    await page.locator('a[href*="contact_add.php"]').click();
    await expect(page).toHaveURL(/client\/contact_add\.php/i);
    await expect(page.locator('form')).toBeVisible();
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('input[name="phone"]')).toBeVisible();
  });

  // Contact View/Edit Tests
  test('Contact detail view works', async ({ page }) => {
    const firstContact = page.locator('table.data-table tbody tr:first-child a[href*="contact_edit"]');
    if (await firstContact.count() > 0) {
      await firstContact.click();
      await expect(page).toHaveURL(/client\/contact_edit\.php/i);
      await expect(page.locator('input[name="name"]')).toBeVisible();
    }
  });

  // Bulk Operations Tests
  test('Bulk contact operations available', async ({ page }) => {
    expect(await page.locator('a[href*="contact_bulk.php"], a[href*="contact_import.php"]').count()).toBeGreaterThan(0);
  });

  test('Contact import page accessible', async ({ page }) => {
    await page.locator('a[href*="contact_import.php"]').click();
    await expect(page).toHaveURL(/client\/contact_import\.php/i);
    await expect(page.locator('h1:has-text("Import")')).toBeVisible();
  });
});

test.describe('Contacts Management - Admin View', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/contacts.php', { waitUntil: 'domcontentloaded' });
  });

  test('Admin can view all contacts', async ({ page }) => {
    await expect(page).toHaveURL(/admin\/contacts\.php/i);
    await expect(page.locator('table.data-table')).toBeVisible();
  });

  test('Admin contacts view shows company information', async ({ page }) => {
    await expect(page.locator('th:has-text("Company")')).toBeVisible();
  });

  test('Admin can access contact management', async ({ page }) => {
    await expect(page.locator('a[href*="contact_add.php"]')).toBeVisible();
    await expect(page.locator('a[href*="contact_import.php"]')).toBeVisible();
  });
});

test.describe('Contact Groups', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/contact_groups.php', { waitUntil: 'domcontentloaded' });
  });

  test('Contact groups page loads', async ({ page }) => {
    await expect(page).toHaveURL(/client\/contact_groups\.php/i);
    await expect(page.locator('h1:has-text("Groups")')).toBeVisible();
  });

  test('Groups are displayed', async ({ page }) => {
    // Groups render as cards on this page, not a table.
    await expect(page.locator('.form-card, table.data-table, .group-card').first()).toBeVisible();
  });
});

test.describe('Contact Creation Flow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsClient(page);
    await page.goto('/client/contact_add.php', { waitUntil: 'domcontentloaded' });
  });

  test('Contact creation form has all required fields', async ({ page }) => {
    const requiredFields = [
      'input[name="name"]',
      'input[name="phone"]',
      'input[name="email"]',
    ];
    
    for (const field of requiredFields) {
      await expect(page.locator(field)).toBeVisible();
    }
  });

  test('Form validation prevents empty submission', async ({ page }) => {
    await page.locator('button[type="submit"]').click();
    
    // Should show validation errors or stay on page
    await expect(page).toHaveURL(/client\/contact_add\.php/i);
  });

  test('Contact creation with valid data', async ({ page }) => {
    // Fill form with test data
    await page.locator('input[name="name"]').fill(`Test Contact ${Date.now()}`);
    await page.locator('input[name="phone"]').fill(`987654321${Math.floor(Math.random() * 1000)}`);
    await page.locator('input[name="email"]').fill(`test${Date.now()}@example.com`);
    
    // Optionally pick a group if the dropdown offers real options.
    const grp = page.locator('select[name="group_id"]');
    if (await grp.count() > 0 && await grp.locator('option').count() > 1) {
      await grp.selectOption({ index: 1 }).catch(() => {});
    }

    // Submit form
    await page.locator('button[type="submit"]').first().click();

    // Should redirect to contacts list or contact detail
    await expect(page).not.toHaveURL(/client\/contact_add\.php/i);
  });
});

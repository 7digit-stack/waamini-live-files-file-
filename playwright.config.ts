import { defineConfig } from '@playwright/test';
import * as dotenv from 'dotenv';
dotenv.config();

export default defineConfig({
  testDir: './tests',
  timeout: 60000,          // 60s per test
  retries: 1,              // retry once on flake
  workers: 1,              // sequential (single session, XAMPP)
  reporter: [
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['list'],
    ['json', { outputFile: 'test-results/results.json' }],
    ['junit', { outputFile: 'test-results/results.xml' }],
  ],
  use: {
    baseURL:             process.env.BASE_URL || 'http://localhost:8080',
    screenshot:          'on',               // always capture screenshots
    video:               'retain-on-failure',
    trace:               'retain-on-failure',
    headless:            process.env.HEADLESS !== 'false',
    viewport:            { width: 1280, height: 720 },
    ignoreHTTPSErrors:   true,
    actionTimeout:       15000,
    navigationTimeout:   30000,
  },
  outputDir: 'test-results',

  // Named projects for targeted runs
  projects: [
    { name: 'auth',        testMatch: '01-auth.spec.ts' },
    { name: 'admin',       testMatch: '02-admin-dashboard.spec.ts' },
    { name: 'client',      testMatch: '03-client-dashboard.spec.ts' },
    { name: 'contacts',    testMatch: '04-contacts.spec.ts' },
    { name: 'campaigns',   testMatch: '05-campaigns.spec.ts' },
    { name: 'templates',   testMatch: '06-templates.spec.ts' },
    { name: 'bot',         testMatch: '07-bot-replies.spec.ts' },
    { name: 'chat',        testMatch: '08-whatsapp-chat.spec.ts' },
    { name: 'settings',    testMatch: '09-settings.spec.ts' },
    { name: 'premium',     testMatch: '10-premium-features.spec.ts' },
    { name: 'gst',         testMatch: '11-gst-invoice.spec.ts' },
    { name: 'integrations',testMatch: '12-integrations.spec.ts' },
    { name: 'webhook',     testMatch: '13-webhook.spec.ts' },
    { name: 'api',         testMatch: '14-api-endpoints.spec.ts' },
    { name: 'performance', testMatch: '15-performance.spec.ts' },
    { name: 'security',    testMatch: '16-security.spec.ts' },
    { name: 'full-suite',  testMatch: '17-full-suite.spec.ts' },
    { name: 'retries',     testMatch: '18-retry-logic.spec.ts' },
    { name: 'all',         testMatch: '**/*.spec.ts' },
  ],
});

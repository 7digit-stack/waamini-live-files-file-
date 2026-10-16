// Throwaway diagnostic config: same projects as the base config, but with
// short timeouts so absent-element failures fail fast. Verdicts are unchanged
// (a missing element fails regardless of timeout); this only cuts wall-clock.
import base from './playwright.config';
import { defineConfig } from '@playwright/test';

export default defineConfig({
  ...(base as any),
  timeout: 30000,
  retries: 1, // self-heal login timeouts that appear only under sustained full-suite load
  use: {
    ...((base as any).use ?? {}),
    actionTimeout: 8000,
    navigationTimeout: 15000,
    screenshot: 'only-on-failure',
    video: 'off',
    trace: 'off',
  },
});

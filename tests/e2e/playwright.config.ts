import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 60_000,
  retries: 0,
  workers: 1,

  use: {
    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Video disabled per user request — traces still capture enough for debugging.
    video: 'off',
  },

  webServer: [
    {
      command: 'curl -sf http://localhost:8001/ > /dev/null',
      url: 'http://localhost:8001/',
      reuseExistingServer: true,
      timeout: 5_000,
    },
    {
      command: 'curl -sf http://localhost:8002/ > /dev/null',
      url: 'http://localhost:8002/',
      reuseExistingServer: true,
      timeout: 5_000,
    },
    {
      command: 'curl -sf http://localhost:8003/__state > /dev/null',
      url: 'http://localhost:8003/__state',
      reuseExistingServer: true,
      timeout: 5_000,
    },
  ],
});

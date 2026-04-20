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
      timeout: 30_000,
    },
    {
      // Generous timeout: compat-wordfence.spec.ts runs Wordfence's WAF in
      // front of every request, so a cold first hit can easily exceed 5s.
      // The common-case (vanilla WP) still comes back in well under 1s.
      command: 'curl -sf http://localhost:8002/ > /dev/null',
      url: 'http://localhost:8002/',
      reuseExistingServer: true,
      timeout: 30_000,
    },
    {
      command: 'curl -sf http://localhost:8003/__state > /dev/null',
      url: 'http://localhost:8003/__state',
      reuseExistingServer: true,
      timeout: 30_000,
    },
    {
      // caddy TLS sidecar — exposes client-wp via https://localhost:8443.
      // Hit the root, not /wp-login.php — wps-hide-login (exercised by
      // compat-wps-hide-login.spec.ts) renames wp-login.php to a custom
      // slug and 404s the vanilla path. If the plugin is left active by
      // a previous failed run, the health-check would bomb with exit 56.
      command: 'curl -sfk https://localhost:8443/ > /dev/null',
      url: 'https://localhost:8443/',
      ignoreHTTPSErrors: true,
      reuseExistingServer: true,
      timeout: 30_000,
    },
  ],
});

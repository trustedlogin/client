import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  // capture-screenshots.spec.ts is a documentation-generation tool, not a
  // regression test — its own file header documents that it should be run
  // individually (`npx playwright test capture-screenshots.spec.ts`).
  // Excluding it from the default invocation prevents the screenshot
  // pipeline\'s state assumptions from polluting regression runs.
  // Run it explicitly when generating docs.
  testIgnore: [ '**/capture-screenshots.spec.ts' ],
  timeout: 60_000,
  retries: 0,
  workers: 1,

  // Dual reporter: `list` keeps the default per-test progress on stdout
  // for CI logs and humans; `html` writes a full report to
  // playwright-report/ that you can open with `npx playwright show-report`
  // after a failed run. The HTML report links every failed test to its
  // trace.zip + screenshots — far better than digging through
  // test-results/<long-name>/ by hand.
  reporter: [
    [ 'list' ],
    [ 'html', { open: 'never', outputFolder: 'playwright-report' } ],
  ],

  // Wired up in global-setup.ts — defensively deactivates Wordfence
  // before any spec runs. compat-wordfence.spec.ts activates it in
  // its own beforeAll; if a previous run was SIGKILLed mid-flight,
  // its afterAll never ran and Wordfence stays network-active,
  // adding ~7s of WAF overhead per request and timing out every
  // browser-driven flow spec.
  globalSetup: './global-setup.ts',

  // Wired up in global-teardown.ts — captures client-wp\'s WordPress
  // debug.log into test-results/wp-debug.log when any test failed.
  // Without this, debugging a failure means manually opening a docker
  // shell after the fact and hoping subsequent tests didn't overwrite
  // the log.
  globalTeardown: './global-teardown.ts',

  use: {
    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Video disabled per user request — traces still capture enough for debugging.
    video: 'off',
  },

  webServer: process.env.TL_E2E_SKIP_HEALTHCHECK === '1' ? [] : [
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
    // caddy TLS sidecar health check — gated behind TL_E2E_SKIP_TLS=1
    // for runs that don't need the TLS path. Specs that actually require
    // TLS (tls-wire, protocol-mismatch) probe it themselves at test time.
    ...( process.env.TL_E2E_SKIP_TLS === '1' ? [] : [ {
      command: 'curl -sfk https://localhost:8443/ > /dev/null',
      url: 'https://localhost:8443/',
      ignoreHTTPSErrors: true,
      reuseExistingServer: true,
      timeout: 30_000,
    } ] ),
  ],
});

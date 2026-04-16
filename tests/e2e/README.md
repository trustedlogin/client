# End-to-end tests for TrustedLogin Client

Self-contained local stack that exercises the full popup-postMessage contract
between the client library and the vendor-side Gravity Forms TrustedLogin
field (trustedlogin-connector PR #184). Tests the behaviour introduced by
PR #138 (this branch).

## Architecture

```
┌─────────────┐      ┌─────────────┐      ┌─────────────┐
│  client-wp  │─────▶│  fake-saas  │◀─────│  vendor-wp  │
│  :8002      │      │  :8003      │      │  :8001      │
│             │      │             │      │             │
│ THIS repo,  │      │ Stores      │      │ connector   │
│ mounted as  │      │ envelopes   │      │ (PR #184)   │
│ a plugin    │      │ in memory   │      │ + Gravity   │
│             │      │             │      │ Forms       │
└──────┬──────┘      └─────────────┘      └──────┬──────┘
       │                                          │
       │                                          │
       │  ┌─── Playwright on host ────────────────┘
       │  │  opens vendor-wp GF page in one window,
       │  │  follows window.open()'d popup to client-wp,
       │  │  listens for postMessage on the opener
       │  │
       └──┴───  popup posts {granting|granted|revoking} back to opener
```

| Service | Port | What it does |
|---------|------|--------------|
| mariadb | (internal) | Two DBs: `vendor_wp`, `client_wp` |
| vendor-wp | `:8001` | WP 6.7 + connector PR #184 + Gravity Forms + TL GF field |
| client-wp | `:8002` | WP 6.7 + THIS repo bind-mounted and activated (`client.php`) |
| fake-saas | `:8003` | PHP mock of `app.trustedlogin.com/api/v1/` |

### What PR #138 adds (client repo — this branch)

`src/assets/trustedlogin.js` posts four `window.opener.postMessage()` events:

1. On return-page load with prior access AND no `?revoking=` param:
   `{ type: 'granted', key, expiration }`
2. On Grant Access click: `{ type: 'granting' }` + hide popup
3. On AJAX grant success: `{ type: 'granted', key, expiration }`
4. On Revoke Access click: `{ type: 'revoking' }` + hide popup

`src/Form.php` adds a hidden `<input id="tl-{ns}-access-expiration">` so the JS
can read the expiration from the DOM on return-page load.

### What PR #184 adds (connector repo, cloned during bootstrap)

A Gravity Forms field type `trustedlogin` that renders an input for the client
URL + a submit button that `window.open()`s to the client's grant-access URL
and listens for the postMessage events above.

## Prerequisites

- **Docker** (Desktop / OrbStack / podman)
- **Node.js** 18+ and npm
- **GitHub auth** with `repo` scope — needed to clone `gravityforms/gravityforms`
  and the connector feature branch. Provide via either:
  - `gh auth login` (the bootstrap picks up `gh auth token`), OR
  - `GITHUB_TOKEN=ghp_xxx` in the environment.

## Quick start

```bash
cd tests/e2e
npm install
npx playwright install chromium

# Bring up the stack + bootstrap both WP sites.
npm run setup

# Run the test suite.
npm test
```

You can visit:

- Vendor admin: http://localhost:8001/wp-admin (admin / admin)
- Vendor GF form page: shown after `npm run setup` completes
- Client admin: http://localhost:8002/wp-admin (admin / admin)
- Fake-saas state: `curl http://localhost:8003/__state`

## Resetting state

- **Fake-saas only**: `npm run reset` (clears envelopes + messages)
- **Full teardown + rebuild**: `npm run teardown && npm run setup`
- **Re-seed a single WP**: `REINSTALL=true ./fixtures/bootstrap-client.sh`
- **Re-clone connector + GF at a different branch**: `REFRESH_PLUGINS=true CONNECTOR_BRANCH=my-branch ./fixtures/bootstrap-vendor.sh`

## Troubleshooting

**Bootstrap fails cloning gravityforms** — `gh auth status` must show a token
with `repo` scope. The repo is private; `public_repo` is not enough.

**Bootstrap sanity check fails on `postMessage` or `gk-gravitycalendar`** —
means the client-wp volume isn't mounting the PR #138 branch. Verify:
```
git -C ../../ branch --show-current   # must be feature/137-provide-data-within-popup
git -C ../../ grep "gk-gravitycalendar" src/  # must be empty
```

**Popup doesn't open in tests** — Playwright's `context.waitForEvent('page')`
needs a popup blocker off. Chromium in headless mode allows `window.open()`
by default. If running headed, ensure "Block pop-ups" is off in browser prefs.

**`granted` message has empty `key`** — the client's AJAX response
(`response.data.key`) wasn't populated. That means `Client::grant_access()`
returned without the `key` field (check the `return_data` array in
`src/Client.php`).

## CI

See `.github/workflows/e2e.yml`. Requires a repo secret `E2E_GITHUB_TOKEN`
with `repo` scope to clone GF + the connector branch. Falls back to
`GITHUB_TOKEN` when available.

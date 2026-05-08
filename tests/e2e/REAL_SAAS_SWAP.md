# Swapping fake-saas for the real SaaS in this e2e

The default e2e harness uses `fake-saas` (a single-file PHP container
that mimics the SaaS protocol). This document covers running the same
specs against the **real Laravel SaaS** (the `trustedlogin-ecommerce`
repo's docker stack).

Use case: confirming that a SaaS-side change (e.g. envelope signing)
works in a true cross-component flow, not just against the fake.

## What's provided

- `mu-plugins/vendor-real-saas.php` — flips the connector at the real
  SaaS via `host.docker.internal:8090` when a toggle file is present.
- `mu-plugins/client-real-saas.php` — same, but for the client SDK.
- The `playwright.config.ts` `webServer` block respects
  `TL_E2E_SKIP_HEALTHCHECK=1` so dev environments without local `curl`
  can still run the suite.

Both mu-plugins are no-ops by default and only activate when the file
`/var/www/html/wp-content/.use-real-saas` exists in the corresponding
container.

## Activation procedure

### 1. Bring up the real SaaS

In `~/trustedlogin-ecommerce/`:

```sh
docker compose up -d
# Wait for MySQL + Vault to be healthy.
```

### 2. Generate + install an envelope-signing keypair on the real SaaS

```sh
docker compose exec laravel.test php artisan envelope:keygen
# Paste the two emitted env lines into the laravel.test container's
# /var/www/html/.env, then:
docker compose exec laravel.test php artisan config:clear
```

### 3. Seed Team #999 in the real SaaS to match what the e2e expects

The e2e fixtures pin `account_id=999`, `public_key=90bd9d918670ea15`,
and `private_key=fake-api-private-key`. Insert a matching team via:

```php
// docker compose exec laravel.test php artisan tinker
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$user = User::firstOrCreate(
    ['email' => 'e2e-real-saas-owner@example.test'],
    ['name' => 'E2E Real-SaaS Owner', 'password' => bcrypt('password'), 'email_verified_at' => now()]
);

DB::table('teams')->where('name', 'E2E Real-SaaS Team')->delete();
DB::table('teams')->insert([
    'id' => 999,
    'name' => 'E2E Real-SaaS Team',
    'slug' => 'e2e-real-saas-' . substr(md5('999'), 0, 8),
    'owner_id' => $user->id,
    'apiToken' => 'fake-api-private-key',
    'publicKey' => '90bd9d918670ea15',
    'authorizationEndpoint' => 'http://host.docker.internal:8001',
    'is_free' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
DB::table('team_users')->where('team_id', 999)->delete();
DB::table('team_users')->insert(['team_id' => 999, 'user_id' => $user->id, 'role' => 'owner']);
```

### 4. Provision the Vault keystore for Team #999

The direct DB insert above bypasses `TeamObserver::created` →
`AddTeamToVault`, so the Vault keystore at `tl-team-999/` is missing.
The createSite call will succeed, but `storeSiteSecret` will 404.
Provision it manually:

```php
$team = Team::find(999);
$vault = app(\App\Http\Clients\VaultContract::class);
$vault->createKeyStore($team);
$vault->addKeyStorePolicy($team, 'cud', 'write-policy');
$vault->addKeyStorePolicy($team, 'delete', 'delete-policy');
$vault->addKeyStorePolicy($team, 'read', 'read-policy');
dispatch(new \App\Jobs\UpdateTeamTokens(999));
```

### 5. Sync the SaaS's envelope-signing public key to the connector's option

```sh
cd ~/Local/dev/app/public/wp-content/plugins/client/tests/e2e
docker compose run --rm wp-cli-vendor wp option update \
    trustedlogin_vendor_saas_envelope_public_key \
    <hex-public-key-from-step-2>
```

### 6. Activate the toggle in both containers

```sh
docker exec -u root e2e-vendor-wp-1 touch /var/www/html/wp-content/.use-real-saas
docker exec -u root e2e-client-wp-1 touch /var/www/html/wp-content/.use-real-saas
```

### 7. Run the spec

```sh
TL_E2E_SKIP_HEALTHCHECK=1 TL_E2E_SKIP_TLS=1 \
    npx playwright test tests/envelope-signing.spec.ts -g "happy path" --reporter=line
```

### 8. Reset to fake-saas

When done, remove the toggle:

```sh
docker exec -u root e2e-vendor-wp-1 rm -f /var/www/html/wp-content/.use-real-saas
docker exec -u root e2e-client-wp-1 rm -f /var/www/html/wp-content/.use-real-saas
```

## What's verified (and what isn't)

When the procedure above completes successfully, the spec exercises the
true cross-component flow:

- Client SDK (PHP, in client-wp container) → POST `/api/v1/sites` →
  real Laravel SaaS → CheckPublicKeyFromBearerToken middleware →
  CreateSiteRequest validation → Site model → real Vault keystore.
- Connector (PHP, in vendor-wp container) → POST `/api/v1/accounts/999`
  → real Laravel SaaS verify-account.
- Connector → POST `/api/v1/sites/999/{secret_id}/get-envelope` → real
  Laravel SaaS → CheckPrivateKey + CheckSignedNonce middleware → real
  Vault retrieval → real `App\Services\EnvelopeSigner` adds detached
  Ed25519 signature → response returned to connector.
- Connector's `EnvelopeVerifier` validates the signature against the
  configured `trustedlogin_vendor_saas_envelope_public_key` option.
- Tampered envelope: a vendor-side mu-plugin (installed by the spec)
  mutates `siteUrl` after the SaaS response; the connector's
  EnvelopeVerifier rejects.

What this procedure does NOT cover (for that, keep using fake-saas):

- The 4 spec scenarios that depend on fake-saas debug endpoints
  (`/__reset`, `/__toggle-signing`, `/__login-attempts-mode`). The
  real SaaS doesn't expose these.
- State reset between runs. The real SaaS persists state across
  invocations (Eloquent + Vault); the test author needs to clean
  Sites + Vault entries manually.

## Why the default is still fake-saas

- Hermetic: no second docker stack needed.
- Faster: no Laravel boot, no Vault round-trip.
- Easier reset: `POST /__reset` clears state in milliseconds.
- Same wire format: `fake-saas/server.php` mirrors
  `App\Services\EnvelopeSigner::canonical()` byte-for-byte; it IS the
  contract spec for the real SaaS.

The cross-component contract test in the SaaS repo
(`tests/Feature/Contracts/EnvelopeSignerVerifierContractTest.php`) pins
the real SaaS's canonical form against the real Connector's
`EnvelopeVerifier::canonical()` byte-for-byte. Combined with fake-saas
matching that same form (per its own source comments), the e2e
implicitly proves the real SaaS works in the cross-component flow.
This document covers the rare case where you want to verify directly.

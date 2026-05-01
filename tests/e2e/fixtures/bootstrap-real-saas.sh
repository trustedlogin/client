#!/usr/bin/env bash
#
# Bootstrap the real Laravel SaaS as the e2e backend, replacing
# fake-saas. The real SaaS is reachable from the e2e network via
# host.docker.internal:8090 (the published port of the
# trustedlogin-ecommerce Laravel container).
#
# Idempotent — safe to re-run. REINSTALL=true tears down state.
#
# What this does:
#   1. Bring up the real SaaS docker stack (if not already up).
#   2. Create a Team in the real SaaS via the proper CreateTeam path
#      so TeamObserver fires and provisions a Vault keystore.
#   3. Override the team's apiToken/publicKey to match the values
#      hardcoded in client.php and the connector's vendor config.
#   4. Generate (or reuse) an envelope-signing keypair on the SaaS.
#   5. Update the connector's vendor config with the dynamic team id.
#   6. Update the connector's stored saas envelope public key.
#   7. Update the e2e's cached state file with the new team id +
#      envelope pubkey so envelope-signing.spec.ts uses the right id.
#   8. Activate the .use-real-saas toggle in both wp containers.
#
# Run order in a clean sandbox:
#   ./fixtures/bootstrap-vendor.sh
#   ./fixtures/bootstrap-client.sh
#   ./fixtures/bootstrap-real-saas.sh    <-- this script
#   TL_E2E_SKIP_HEALTHCHECK=1 TL_E2E_SKIP_TLS=1 npx playwright test ...
#
# To return to fake-saas mode:
#   docker exec -u root e2e-vendor-wp-1 rm -f /var/www/html/wp-content/.use-real-saas
#   docker exec -u root e2e-client-wp-1 rm -f /var/www/html/wp-content/.use-real-saas
#   ./fixtures/bootstrap-vendor.sh    # re-pin team_id back to 999

set -euo pipefail

cd "$(dirname "$0")/.."

REINSTALL="${REINSTALL:-false}"
SAAS_DIR="${SAAS_DIR:-$HOME/trustedlogin-ecommerce}"
SAAS_PORT="${SAAS_PORT:-8090}"

# Hardcoded by client.php — the real SaaS Team must adopt this so the
# Client SDK's bearer matches the publicKey lookup.
PINNED_PUBLIC_KEY="90bd9d918670ea15"

# The connector seeds itself with this private_key during
# bootstrap-vendor.sh; the SaaS Team must adopt it too so Bearer
# (sha256(private_key)) matches CheckPrivateKey.
PINNED_API_TOKEN="fake-api-private-key"

bold()  { printf "\033[1m→\033[0m %s\n" "$*"; }
warn()  { printf "\033[33m!\033[0m %s\n" "$*" >&2; }
fatal() { printf "\033[31m✗\033[0m %s\n" "$*" >&2; exit 1; }

# ----- Sanity checks ---------------------------------------------------------

[ -d "$SAAS_DIR" ] || fatal "SAAS_DIR not found at $SAAS_DIR. Set SAAS_DIR=/path/to/trustedlogin-ecommerce"
[ -f "$SAAS_DIR/docker-compose.yml" ] || fatal "$SAAS_DIR has no docker-compose.yml"
[ -f "fixtures/.cache-vendor-state.json" ] || fatal "Run bootstrap-vendor.sh first."

bold "Bringing up real SaaS docker stack at $SAAS_DIR (VAULT_DRIVER=vault)"
# docker-compose interpolates `\${VAULT_DRIVER:-mysql}` from the shell
# env. The default is `mysql` (binds VaultContract → VaultMySql, a
# stub whose storeSiteSecret ignores the secret param). For the e2e
# we need real Vault so client-side encrypted envelopes round-trip
# faithfully. Force `vault` here and recreate the laravel.test
# container so the env injection actually changes.
(
    cd "$SAAS_DIR"
    export VAULT_DRIVER=vault
    docker compose up -d --no-recreate > /dev/null 2>&1
    # Recreate laravel.test only if its VAULT_DRIVER env differs.
    current=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' \
        $(docker compose ps -q laravel.test 2>/dev/null) 2>/dev/null \
        | grep -E '^VAULT_DRIVER=' | head -1 | cut -d= -f2 | tr -d '\r\n ')
    if [ "$current" != "vault" ]; then
        docker compose up -d --force-recreate laravel.test > /dev/null 2>&1
    fi
)

bold "Waiting for the real SaaS to be ready on host port $SAAS_PORT"
for i in $(seq 1 60); do
    if node -e "require('http').get('http://localhost:$SAAS_PORT/api/status', r=>process.exit(r.statusCode>=400?1:0)).on('error',()=>process.exit(1))" 2>/dev/null; then
        break
    fi
    sleep 2
done
node -e "require('http').get('http://localhost:$SAAS_PORT/api/status', r=>process.exit(r.statusCode>=400?1:0)).on('error',()=>process.exit(1))" 2>/dev/null \
    || fatal "Real SaaS did not come up at host:$SAAS_PORT/api/status"

# ----- Generate (or reuse) envelope-signing keypair --------------------------
#
# The .env-stored value persists across container restarts. We only
# regenerate when REINSTALL=true so re-runs don't break the connector's
# stored verification public key.

bold "Setting up envelope-signing keypair on the real SaaS"
SAAS_PUBKEY=$(
    docker compose -f "$SAAS_DIR/docker-compose.yml" exec -T --privileged laravel.test sh <<'INNER'
set -e
cur=$(grep -E '^TL_ENVELOPE_SIGNING_PUBLIC_KEY=' .env 2>/dev/null | head -1 | cut -d= -f2)
if [ -z "$cur" ] || [ "${REINSTALL:-false}" = "true" ]; then
    php -r '
        $kp = sodium_crypto_sign_keypair();
        echo sodium_bin2hex(sodium_crypto_sign_secretkey($kp))
            . "\n"
            . sodium_bin2hex(sodium_crypto_sign_publickey($kp))
            . "\n";
    ' | (
        read sk
        read pk
        grep -v '^TL_ENVELOPE_SIGNING_' .env > .env.tmp 2>/dev/null || cp .env .env.tmp
        printf "TL_ENVELOPE_SIGNING_SECRET_KEY=%s\nTL_ENVELOPE_SIGNING_PUBLIC_KEY=%s\n" "$sk" "$pk" >> .env.tmp
        mv .env.tmp .env
        echo "$pk"
    )
    php artisan config:clear > /dev/null 2>&1
else
    echo "$cur"
fi
INNER
)
SAAS_PUBKEY=$(echo "$SAAS_PUBKEY" | tail -1 | tr -d '\r\n ')
[ ${#SAAS_PUBKEY} -eq 64 ] || fatal "envelope-signing public key has wrong length: ${#SAAS_PUBKEY} (got: '$SAAS_PUBKEY')"
bold "  envelope-signing public key: $SAAS_PUBKEY"

# ----- Create (or reuse) the Team via CreateTeam so TeamObserver fires -------
#
# The TeamObserver is what calls AddTeamToVault → Vault::createKeyStore.
# Direct DB inserts skip this and produce a 404 from Vault on first
# storeSiteSecret. We use CreateTeam, then post-creation override the
# apiToken/publicKey to the values the connector + client expect.

bold "Creating real SaaS team (CreateTeam path provisions Vault automatically)"
REAL_TEAM_ID=$(
    docker compose -f "$SAAS_DIR/docker-compose.yml" exec -T --privileged laravel.test php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::firstOrCreate(
    ["email" => "e2e-real-saas-owner@example.test"],
    [
        "name" => "E2E Real-SaaS Owner",
        "password" => bcrypt("password"),
        "email_verified_at" => now(),
    ]
);

// Reuse an existing team if present so re-runs are idempotent.
$existing = App\Models\Team::where("name", "E2E Real-SaaS Team")->first();
if ($existing) {
    echo $existing->id;
    return;
}

$team = (new App\Actions\CreateTeam())->create($user, [
    "name" => "E2E Real-SaaS Team",
    "authorizationEndpoint" => "http://host.docker.internal:8001",
    "supportUrl" => "https://help.example.com",
]);

echo $team->id;
' | tr -d '\r\n '
)
[ -n "$REAL_TEAM_ID" ] || fatal "CreateTeam returned no id"
bold "  team_id: $REAL_TEAM_ID"

bold "Bumping Elasticsearch cluster.max_shards_per_node to 5000 (e2e churn)"
# The SaaS audit logger creates one ES index per team per date, so e2e
# runs accumulate hundreds quickly. Default 1000-shard limit produces
# noisy "Validation Failed: this action would add [2] shards" errors on
# every Site/Team event. Doesn't fail the request (the writes are
# rescue'd), but pollutes laravel.log. Bump idempotently.
docker exec elasticsearch sh -c '
PAYLOAD=$(printf "%s" "{\"persistent\":{\"cluster.max_shards_per_node\":5000}}")
wget -q -O - --header="Content-Type: application/json" \
    --post-data="$PAYLOAD" \
    "http://localhost:9200/_cluster/settings" > /dev/null 2>&1 \
  || true
' || true

bold "Ensuring Vault keystore exists for team ${REAL_TEAM_ID}"
# CreateTeam fires AddTeamToVault which provisions the keystore. But
# when the bootstrap is reusing an existing team (e.g., a prior run
# created the team under VAULT_DRIVER=mysql when the listener was a
# no-op), the mount may be missing. Call createKeyStore via the same
# VaultContract the production code uses — idempotent, no raw
# `vault secrets enable`.
docker compose -f "$SAAS_DIR/docker-compose.yml" exec -T --privileged laravel.test php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$team = App\Models\Team::find('"$REAL_TEAM_ID"');
$vault = app(App\Http\Clients\VaultContract::class);
try { $vault->createKeyStore($team); fwrite(STDERR, "  keystore: ok\n"); } catch (\Throwable $e) {
    if (strpos($e->getMessage(), "path is already in use") !== false) {
        fwrite(STDERR, "  keystore: already exists\n");
    } else { fwrite(STDERR, "  keystore failed: " . $e->getMessage() . "\n"); }
}
foreach (["cud" => "write-policy", "delete" => "delete-policy", "read" => "read-policy"] as $cap => $policy) {
    try { $vault->addKeyStorePolicy($team, $cap, $policy); } catch (\Throwable $e) { /* idempotent */ }
}
'

bold "Pinning team apiToken + publicKey to values the e2e connector + client expect"
# Eloquent's save() silently filters apiToken / publicKey on Team
# (the model treats them as derived from the keypair generators).
# Bypass with a direct DB update so the values stick.
docker compose -f "$SAAS_DIR/docker-compose.yml" exec -T --privileged laravel.test php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\DB::table("teams")
    ->where("id", '"$REAL_TEAM_ID"')
    ->update([
        "apiToken"              => "'"$PINNED_API_TOKEN"'",
        "publicKey"             => "'"$PINNED_PUBLIC_KEY"'",
        "authorizationEndpoint" => "http://host.docker.internal:8001",
    ]);
$team = App\Models\Team::find('"$REAL_TEAM_ID"');
fwrite(STDERR, "  pinned: publicKey=" . $team->publicKey . ", apiToken=" . $team->apiToken . PHP_EOL);
'

# ----- Wire connector to use the dynamic team id -----------------------------
#
# The connector stores team config in the "trustedlogin_vendor_config"
# site option as a serialized array of teams. The bootstrap-vendor.sh
# seed used account_id="999"; we rewrite it to the real team id.

bold "Updating vendor-wp connector config to use team_id=$REAL_TEAM_ID"
docker compose run --rm -T wp-cli-vendor wp eval '
$teams = [
    [
        "id"             => 1,
        "account_id"     => "'"$REAL_TEAM_ID"'",
        "private_key"    => "'"$PINNED_API_TOKEN"'",
        "public_key"     => "'"$PINNED_PUBLIC_KEY"'",
        "helpdesk"       => [ "helpscout" ],
        "approved_roles" => [ "administrator", "editor" ],
        "name"           => "E2E Vendor Team (real SaaS)",
        "debug_enabled"  => "on",
    ],
];
update_site_option(
    "trustedlogin_vendor_config",
    [ "teams" => $teams, "config_version" => 1 ]
);
' > /dev/null 2>&1

bold "Updating vendor-wp stored saas envelope public key"
docker compose run --rm -T wp-cli-vendor wp option update \
    trustedlogin_vendor_saas_envelope_public_key "$SAAS_PUBKEY" > /dev/null 2>&1

# ----- Update the spec's cached state file -----------------------------------

bold "Rewriting fixtures/.cache-vendor-state.json with team_id=$REAL_TEAM_ID + envelope pubkey"
node - <<NODE
const fs = require('fs');
const p = './fixtures/.cache-vendor-state.json';
const state = JSON.parse(fs.readFileSync(p, 'utf-8'));
state.account_id = '$REAL_TEAM_ID';
state.api_public_key = '$PINNED_PUBLIC_KEY';
state.saas_envelope_pubkey = '$SAAS_PUBKEY';
fs.writeFileSync(p, JSON.stringify(state, null, 2) + '\n');
NODE

# ----- Flip the toggle in both containers ------------------------------------

bold "Activating .use-real-saas toggle in both wp containers"
docker exec -u root e2e-vendor-wp-1 touch /var/www/html/wp-content/.use-real-saas
docker exec -u root e2e-client-wp-1 touch /var/www/html/wp-content/.use-real-saas

bold "Real SaaS bootstrap complete"
echo ""
echo "  Real SaaS:           http://localhost:$SAAS_PORT"
echo "  Team id (dynamic):   $REAL_TEAM_ID"
echo "  Envelope pubkey:     $SAAS_PUBKEY"
echo "  Connector config:    updated to point at team $REAL_TEAM_ID"
echo "  Toggle file:         active in vendor-wp + client-wp"
echo ""
echo "  Run a spec against the real SaaS:"
echo "    TL_E2E_SKIP_HEALTHCHECK=1 TL_E2E_SKIP_TLS=1 \\"
echo "      npx playwright test tests/envelope-signing.spec.ts -g 'happy path'"
echo ""
echo "  Return to fake-saas:"
echo "    docker exec -u root e2e-vendor-wp-1 rm -f /var/www/html/wp-content/.use-real-saas"
echo "    docker exec -u root e2e-client-wp-1 rm -f /var/www/html/wp-content/.use-real-saas"
echo "    ./fixtures/bootstrap-vendor.sh   # re-pin team_id back to 999"

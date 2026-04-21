#!/usr/bin/env bash
#
# Bootstrap the vendor WordPress site for trustedlogin/client e2e.
#
#   - clone trustedlogin-connector at feature/183-form-plugins-login-field
#     (PR #184 — adds the Gravity Forms TrustedLogin field)
#   - clone gravityforms/gravityforms (private; needs GITHUB_TOKEN with repo scope)
#   - wp core install
#   - activate both plugins
#   - generate sodium keypair into trustedlogin_keys site option
#   - create team #999 with public_key matching client.php's $config['auth']['api_key']
#     ('90bd9d918670ea15') so the SaaS routing matches
#   - create a Gravity Form with the TrustedLogin field, configured with the
#     client's namespace and pre-filled client URL
#   - stash the form ID + URL into fixtures/.cache-vendor-state.json for tests
#
# Idempotent. Re-run safely. REINSTALL=true drops the database.

set -euo pipefail

cd "$(dirname "$0")/.."

REINSTALL="${REINSTALL:-false}"
REFRESH_PLUGINS="${REFRESH_PLUGINS:-false}"
CONNECTOR_BRANCH="${CONNECTOR_BRANCH:-feature/183-form-plugins-login-field}"
GF_BRANCH="${GF_BRANCH:-master}"

bold()  { printf "\033[1m→\033[0m %s\n" "$*"; }
warn()  { printf "\033[33m!\033[0m %s\n" "$*" >&2; }
fatal() { printf "\033[31m✗\033[0m %s\n" "$*" >&2; exit 1; }

wp_vendor() {
    docker compose run --rm -T wp-cli-vendor wp "$@"
}

# ----- Resolve a GitHub token -------------------------------------------------

TOKEN="${GITHUB_TOKEN:-}"
if [[ -z "$TOKEN" ]] && command -v gh >/dev/null 2>&1; then
    TOKEN="$(gh auth token 2>/dev/null || true)"
fi
if [[ -z "$TOKEN" ]]; then
    fatal "No GitHub token available. Either export GITHUB_TOKEN (with 'repo' scope)
or run 'gh auth login'. Gravity Forms and connector PR #184 both require auth."
fi

clone_with_token() {
    local repo="$1" branch="$2" dest="$3" local_source="$4"
    local url="https://x-access-token:${TOKEN}@github.com/${repo}.git"

    # If a local checkout path was passed and exists, prefer it — this lets
    # iterative local edits (on a branch you haven't pushed) flow into the
    # e2e stack without round-tripping through GitHub. Otherwise clone/pull
    # from the remote.
    if [[ -n "${local_source:-}" && -d "$local_source/.git" ]]; then
        if [[ -d "$dest/.git" && "$REFRESH_PLUGINS" != "true" ]]; then
            bold "  $dest already cloned — fetching from local $local_source @ $branch"
            git -C "$dest" fetch "$local_source" "$branch" --quiet
            git -C "$dest" reset --hard FETCH_HEAD --quiet
            return
        fi
        rm -rf "$dest"
        bold "  cloning $local_source @ $branch → $dest"
        git clone --branch "$branch" --quiet "$local_source" "$dest"
        return
    fi

    if [[ -d "$dest/.git" && "$REFRESH_PLUGINS" != "true" ]]; then
        bold "  $dest already cloned — fetching + resetting to origin/$branch"
        git -C "$dest" remote set-url origin "$url"
        git -C "$dest" fetch origin "$branch" --depth=1 --quiet
        git -C "$dest" reset --hard "origin/$branch" --quiet
        return
    fi
    rm -rf "$dest"
    bold "  cloning $repo @ $branch → $dest"
    git clone --branch "$branch" --depth=1 --quiet "$url" "$dest"
}

# ----- Clone plugins ----------------------------------------------------------

bold "Staging trustedlogin-connector (PR #184 branch)"
# Candidate local checkouts — if one exists and has the target branch, we'll
# fetch from it directly so unpushed local commits land in the e2e stack.
LOCAL_CONNECTOR="${LOCAL_CONNECTOR:-$HOME/Local/dev/app/public/wp-content/plugins/trustedlogin-connector}"
clone_with_token "trustedlogin/trustedlogin-connector" "$CONNECTOR_BRANCH" "fixtures/trustedlogin-connector" "$LOCAL_CONNECTOR"

if [[ ! -d "fixtures/trustedlogin-connector/vendor" || "$REFRESH_PLUGINS" == "true" ]]; then
    bold "  composer install inside trustedlogin-connector"
    docker run --rm -v "$(pwd)/fixtures/trustedlogin-connector:/app" -w /app \
        composer:2 install --no-dev --no-progress --no-interaction --prefer-dist
fi

# The connector's React settings page (admin.php?page=trustedlogin-settings)
# mounts into an empty <div> and relies on wpbuild/admin-page-trustedlogin-
# settings.js produced by `yarn build:js`. Without the build, the settings
# page renders a blank container. Also build the Tailwind CSS bundle.
CONNECTOR_BUILD_MARKER="fixtures/trustedlogin-connector/wpbuild/admin-page-trustedlogin-settings.js"
CONNECTOR_APP_MARKER="fixtures/trustedlogin-connector/build/index.html"
if [[ ! -f "$CONNECTOR_BUILD_MARKER" || ! -f "$CONNECTOR_APP_MARKER" || "$REFRESH_PLUGINS" == "true" ]]; then
    bold "  yarn install + build:js + build:css + build:app inside trustedlogin-connector (~2-3 min)"
    # `build:js`  → wpbuild/ (React settings page)
    # `build:css` → src/trustedlogin-dist.css (Tailwind bundle)
    # `build:app` → build/index.html (ReturnScreen template + static assets)
    #              Without build:app, the Access Key Login page aborts with
    #              "A required template was not found" when redirectData
    #              triggers the return-screen path.
    docker run --rm \
        -v "$(pwd)/fixtures/trustedlogin-connector:/app" -w /app \
        -e npm_config_cache=/tmp/npm-cache \
        -e CI=true \
        node:20 sh -c "yarn install --frozen-lockfile --silent && yarn build:js && yarn build:css && yarn build:app" \
        || warn "  Connector build failed — settings page may render empty"
else
    bold "  Connector admin bundle + return-screen template already built"
fi

bold "Staging gravityforms"
# Explicit empty 4th arg (local_source) for consistency with the other
# clone_with_token call site. gravityforms has no local checkout we
# can copy from, so we always fall through to git clone.
clone_with_token "gravityforms/gravityforms" "$GF_BRANCH" "fixtures/gravityforms" ""

# Gravity Forms's dev branch ships source JS/CSS that only resolves after a
# build. Without this step, assets 404 (theme-foundation.min.css, etc.) and
# the front-end renders unstyled. Run `npm run dev` inside the clone once;
# it's skipped on subsequent bootstraps unless REFRESH_PLUGINS=true.
GF_BUILD_MARKER="fixtures/gravityforms/js/layout_editor.min.js"
if [[ ! -f "$GF_BUILD_MARKER" || "$REFRESH_PLUGINS" == "true" ]]; then
    bold "  Building Gravity Forms assets (npm ci + dist + minify — can take 3-5 min)"
    # Run inside a node container so we don't need node/npm on the host.
    # `dist` produces the source webpack bundles; `minify` produces the *.min.js
    # and *.min.css files WP references by default (SCRIPT_DEBUG=false).
    # `dev` starts a browser-sync watcher that wants HTTPS keys and fails in
    # a headless container, so avoid it.
    docker run --rm \
        -v "$(pwd)/fixtures/gravityforms:/app" -w /app \
        -e npm_config_cache=/tmp/npm-cache \
        node:20 sh -c "npm ci --no-audit --no-fund && npm run dist && npm run minify" \
        || warn "  GF build failed — front-end may render with missing assets"
else
    bold "  Gravity Forms assets already built (delete $GF_BUILD_MARKER or set REFRESH_PLUGINS=true to rebuild)"
fi

# ----- WP install -------------------------------------------------------------

if [[ "$REINSTALL" == "true" ]]; then
    bold "REINSTALL=true — dropping vendor_wp database"
    docker compose exec -T mariadb mariadb -uroot -proot -e "DROP DATABASE IF EXISTS vendor_wp; CREATE DATABASE vendor_wp;"
fi

if wp_vendor core is-installed >/dev/null 2>&1; then
    bold "Vendor WP already installed — skipping core install"
else
    bold "Installing WordPress on vendor-wp"
    wp_vendor core install \
        --url=http://localhost:8001 \
        --title="TrustedLogin Vendor (e2e)" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@e2e.test \
        --skip-email
fi

bold "Activating gravityforms"
wp_vendor plugin activate gravityforms

bold "Activating trustedlogin-connector"
wp_vendor plugin activate trustedlogin-connector

bold "Enabling pretty permalinks"
wp_vendor rewrite structure '/%postname%/' --hard
wp_vendor rewrite flush --hard

# ----- Sodium keys ------------------------------------------------------------

bold "Seeding sodium encryption keypair into trustedlogin_keys option"
wp_vendor eval '
    $kp = sodium_crypto_box_keypair();
    $sign_kp = sodium_crypto_sign_keypair();
    $keys = (object) [
        "private_key"      => sodium_bin2hex( sodium_crypto_box_secretkey( $kp ) ),
        "public_key"       => sodium_bin2hex( sodium_crypto_box_publickey( $kp ) ),
        "sign_private_key" => sodium_bin2hex( sodium_crypto_sign_secretkey( $sign_kp ) ),
        "sign_public_key"  => sodium_bin2hex( sodium_crypto_sign_publickey( $sign_kp ) ),
    ];
    update_site_option( "trustedlogin_keys", wp_json_encode( $keys ) );
    echo "OK\n";
'
wp_vendor eval 'echo get_site_option( "trustedlogin_keys" );' > fixtures/.cache-vendor-keys.json

# ----- Team config ------------------------------------------------------------
#
# client.php at the client repo root has:
#     'api_key' => '90bd9d918670ea15'
# The connector stores the team's api `public_key` in trustedlogin_vendor_team_settings.
# Must match so SaaS routing lines up.

bold "Seeding team #999 into trustedlogin_vendor_config (public_key=90bd9d918670ea15)"
wp_vendor eval '
    $teams = [
        [
            "id"             => 1,
            "account_id"     => "999",
            "private_key"    => "fake-api-private-key",
            "public_key"     => "90bd9d918670ea15",
            "helpdesk"       => [ "helpscout" ],
            "approved_roles" => [ "administrator", "editor" ],
            "name"           => "E2E Vendor Team",
            "debug_enabled"  => "on",
            "helpdesk_data"  => [
                "helpscout" => [ "secret" => "fake-helpscout-secret" ],
            ],
        ],
    ];
    update_option( "trustedlogin_vendor_team_settings", wp_json_encode( $teams ) );
    update_option( "trustedlogin_vendor_other_settings", wp_json_encode( [ "error_logging" => true ] ) );
    echo "OK\n";
'

bold "Marking onboarding as complete"
wp_vendor eval '
    if ( class_exists( "\\\\TrustedLogin\\\\Vendor\\\\Status\\\\Onboarding" ) ) {
        \TrustedLogin\Vendor\Status\Onboarding::setHasOnboarded();
        echo "OK\n";
    } else {
        echo "SKIPPED (class not found on this branch)\n";
    }
'

bold "Verifying team connection against fake-saas"
wp_vendor eval '
    if ( function_exists( "trustedlogin_connector" ) ) {
        $result = trustedlogin_connector()->verifyAccount(
            \TrustedLogin\Vendor\SettingsApi::fromSaved()->getByAccountId("999")
        );
        echo $result ? "Connected\n" : "FAILED\n";
    }
'

# ----- SaaS envelope-signing public key --------------------------------------
#
# Fetch the fake-saas signing public key and push it into the vendor WP as
# option `trustedlogin_vendor_saas_envelope_public_key`. The connector's
# EnvelopeVerifier reads this option (with a filter override) so the full
# grant flow becomes MITM-resistant by default.

bold "Fetching fake-saas envelope-signing public key"
SAAS_PUBKEY=""
for attempt in 1 2 3 4 5; do
    SAAS_PUBKEY=$(curl -fsS http://localhost:8003/signing-pubkey 2>/dev/null \
        | sed -n 's/.*"publicKey"[ ]*:[ ]*"\([0-9a-f]*\)".*/\1/p' \
        || true)
    if [[ -n "$SAAS_PUBKEY" ]]; then
        break
    fi
    warn "  signing-pubkey not ready (attempt $attempt/5) — retrying"
    sleep 2
done

if [[ -n "$SAAS_PUBKEY" ]]; then
    bold "  SaaS pubkey: ${SAAS_PUBKEY:0:16}... (${#SAAS_PUBKEY} chars)"
    wp_vendor option update trustedlogin_vendor_saas_envelope_public_key "$SAAS_PUBKEY" >/dev/null
else
    warn "  Could not fetch fake-saas signing pubkey — envelope signing will be off"
    SAAS_PUBKEY=""
    # Make sure any stale value is cleared.
    wp_vendor option delete trustedlogin_vendor_saas_envelope_public_key >/dev/null 2>&1 || true
fi

# ----- Gravity Form with TL field --------------------------------------------
#
# PR #184 registers a custom GF field type. We create a form programmatically
# containing that field, configured with the client's namespace.

bold "Creating Gravity Form with TrustedLogin field"
# Always delete any existing form with this title before creating, so updates
# to the field config in this script take effect. Re-runnable.
FORM_ID=$(wp_vendor eval '
    $forms = \GFAPI::get_forms();
    foreach ( $forms as $f ) {
        if ( isset( $f["title"] ) && $f["title"] === "TL E2E Form" ) {
            \GFAPI::delete_form( $f["id"] );
        }
    }
    $form = [
        "title"        => "TL E2E Form",
        "description"  => "E2E test form: grant access to client site via TrustedLogin.",
        "labelPlacement" => "top_label",
        "button"       => [ "type" => "text", "text" => "Submit" ],
        "fields"       => [
            [
                "type"         => "trustedlogin",
                "id"           => 1,
                // Omit "label" so the field uses its own default ("Site URL")
                // set in init_default_settings(). Overriding it here would
                // duplicate the button text.
                "formId"       => 0,
                "isRequired"   => false,
                // These match the public properties on TrustedLoginGFField:
                // $tlNamespace / $tlVendor. GF persists them in the form JSON.
                "tlNamespace"  => "pro-block-builder",
                "tlVendor"     => "Pro Block Builder",
                "defaultValue" => "http://localhost:8002",
            ],
        ],
    ];
    $id = \GFAPI::add_form( $form );
    if ( is_wp_error( $id ) ) {
        fwrite( STDERR, "GF add_form failed: " . $id->get_error_message() . "\n" );
        exit( 1 );
    }
    echo (int) $id;
' | tr -dc '0-9')

if [[ -z "$FORM_ID" || "$FORM_ID" == "0" ]]; then
    fatal "Failed to create Gravity Form (got form ID: $FORM_ID)"
fi

bold "  Form ID: $FORM_ID"

# Create (or update) a page that embeds the form so Playwright has a stable URL.
# Always rewrite the shortcode to point at the current FORM_ID, since we may
# have recreated the form above.
bold "Creating/updating front-end page with gform shortcode"
PAGE_URL=$(wp_vendor eval "
    \$shortcode = '[gravityform id=\"${FORM_ID}\" title=\"false\" description=\"false\" ajax=\"false\"]';
    \$existing  = get_page_by_path( 'tl-e2e-form' );
    if ( \$existing ) {
        wp_update_post( [
            'ID'           => \$existing->ID,
            'post_content' => \$shortcode,
        ] );
        echo get_permalink( \$existing->ID );
        return;
    }
    \$page_id = wp_insert_post( [
        'post_type'    => 'page',
        'post_title'   => 'TL E2E Form',
        'post_name'    => 'tl-e2e-form',
        'post_status'  => 'publish',
        'post_content' => \$shortcode,
    ] );
    echo get_permalink( \$page_id );
" | tr -d '\r')

# Sanitize: PAGE_URL comes back with trailing newline; also strip any leading
# wp-cli warnings by grepping for http.
PAGE_URL=$(printf '%s' "$PAGE_URL" | grep -oE 'https?://[^ ]+' | head -1)

bold "  Form page URL: $PAGE_URL"

# ----- Cache state for tests --------------------------------------------------

cat > fixtures/.cache-vendor-state.json <<EOF
{
  "form_id":        "${FORM_ID}",
  "form_page_url":  "${PAGE_URL}",
  "client_url":     "http://localhost:8002",
  "client_url_tls": "https://localhost:8443",
  "client_url_docker": "http://client-wp",
  "vendor_url":     "http://localhost:8001",
  "namespace":      "pro-block-builder",
  "account_id":     "999",
  "api_public_key": "90bd9d918670ea15",
  "saas_envelope_pubkey": "${SAAS_PUBKEY}"
}
EOF

bold "Vendor bootstrap complete"
echo
echo "  Admin URL:      http://localhost:8001/wp-admin"
echo "  Admin user:     admin / admin"
echo "  GF form URL:    $PAGE_URL"
echo "  Form ID:        $FORM_ID"
echo "  Account ID:     999"
echo "  Namespace:      pro-block-builder"
echo

#!/usr/bin/env bash
#
# Bootstrap the client WordPress site.
#
# This client repo's root contains a runnable WP plugin (client.php) that
# instantiates TrustedLogin\Client with a test configuration. docker-compose
# bind-mounts the repo into wp-content/plugins/trustedlogin-client, so PR #138's
# src/ changes are live without any build step.
#
#   - wp core install (admin / admin)
#   - activate trustedlogin-client
#   - pretty permalinks (the wp-login.php?action=trustedlogin redirect works
#     without them, but pretty perms match the connector's e2e baseline)
#
# Idempotent. REINSTALL=true drops the database first.

set -euo pipefail

cd "$(dirname "$0")/.."

REINSTALL="${REINSTALL:-false}"

bold()  { printf "\033[1m→\033[0m %s\n" "$*"; }
warn()  { printf "\033[33m!\033[0m %s\n" "$*" >&2; }
fatal() { printf "\033[31m✗\033[0m %s\n" "$*" >&2; exit 1; }

wp_client() {
    docker compose run --rm -T wp-cli-client wp "$@"
}

if [[ "$REINSTALL" == "true" ]]; then
    bold "REINSTALL=true — dropping client_wp database"
    docker compose exec -T mariadb mariadb -uroot -proot -e "DROP DATABASE IF EXISTS client_wp; CREATE DATABASE client_wp;"
fi

if wp_client core is-installed >/dev/null 2>&1; then
    bold "Client WP already installed — skipping core install"
else
    bold "Installing WordPress on client-wp"
    wp_client core install \
        --url=http://localhost:8002 \
        --title="TrustedLogin Client (e2e)" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@e2e.test \
        --skip-email
fi

bold "Building client CSS (namespace must match client.php's vendor.namespace)"
# client.php uses the namespace 'pro-block-builder', so the SCSS must be
# compiled with $namespace: "pro-block-builder" — otherwise all selectors in
# the generated trustedlogin.css will be `.tl-test-*` and the grant-access
# page will render unstyled. Re-run every bootstrap to stay in sync with
# any namespace change.
CLIENT_REPO_ROOT="$(cd ../.. && pwd)"
CLIENT_NAMESPACE="${CLIENT_NAMESPACE:-pro-block-builder}"
CSS_MARKER=".tl-${CLIENT_NAMESPACE}-auth"
if ! grep -q "$CSS_MARKER" "$CLIENT_REPO_ROOT/src/assets/trustedlogin.css" 2>/dev/null; then
    bold "  composer install + build-sass --namespace=$CLIENT_NAMESPACE"
    # composer install ensures scssphp is present; then build-sass compiles
    # trustedlogin.scss into trustedlogin.css with the right namespace.
    docker run --rm -v "$CLIENT_REPO_ROOT:/app" -w /app composer:2 \
        install --no-interaction --no-progress --quiet 2>&1 | tail -3
    docker run --rm -v "$CLIENT_REPO_ROOT:/app" -w /app php:8.2-cli \
        php bin/build-sass --namespace="$CLIENT_NAMESPACE" 2>&1 | tail -3
else
    bold "  CSS already built for namespace '$CLIENT_NAMESPACE' — skipping"
fi

bold "Activating trustedlogin-client (the bind-mounted repo root)"
wp_client plugin activate trustedlogin-client

# Compat-test plugins are installed but left deactivated; the matching specs
# (compat-wps-hide-login.spec.ts, compat-wordfence.spec.ts) activate them
# per-test and clean up after so existing specs keep running on a vanilla WP.
bold "Ensuring compat-test plugins are installed (deactivated by default)"
for plugin in wps-hide-login wordfence; do
    if wp_client plugin is-installed "$plugin" >/dev/null 2>&1; then
        wp_client plugin deactivate "$plugin" >/dev/null 2>&1 || true
    else
        wp_client plugin install "$plugin" --activate=false 2>&1 | tail -3 \
            || warn "$plugin install failed (spec will retry)"
        wp_client plugin deactivate "$plugin" >/dev/null 2>&1 || true
    fi
done

bold "Enabling pretty permalinks"
wp_client rewrite structure '/%postname%/' --hard
wp_client rewrite flush --hard

bold "Sanity check: confirm PR #138 JS + textdomain fix are active"
JS_PATH="/var/www/html/wp-content/plugins/trustedlogin-client/src/assets/trustedlogin.js"
FORM_PATH="/var/www/html/wp-content/plugins/trustedlogin-client/src/Form.php"
if ! wp_client eval "echo file_exists('$JS_PATH') ? 'OK' : 'MISSING';" 2>/dev/null | grep -q OK; then
    fatal "PR #138 JS not visible inside container at $JS_PATH — check bind mount"
fi
if ! wp_client eval "echo strpos(file_get_contents('$JS_PATH'), 'postMessage') !== false ? 'OK' : 'MISSING';" 2>/dev/null | grep -q OK; then
    fatal "trustedlogin.js does not contain postMessage() — wrong branch?"
fi
if wp_client eval "echo strpos(file_get_contents('$FORM_PATH'), 'gk-gravitycalendar') === false ? 'OK' : 'BUG';" 2>/dev/null | grep -q OK; then
    bold "  textdomain fix verified"
else
    fatal "Form.php still contains the 'gk-gravitycalendar' textdomain bug"
fi

bold "Client bootstrap complete"
echo
echo "  Admin URL:        http://localhost:8002/wp-admin"
echo "  Admin user:       admin / admin"
echo "  Grant popup URL:  http://localhost:8002/wp-login.php?action=trustedlogin&ns=pro-block-builder&origin=http%3A%2F%2Flocalhost%3A8001"
echo "  Vendor host (in-network): http://vendor-wp"
echo "  Fake SaaS state:  curl http://localhost:8003/__state"
echo

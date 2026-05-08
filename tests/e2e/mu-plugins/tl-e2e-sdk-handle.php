<?php
/**
 * Plugin Name: TrustedLogin e2e — expose Client SDK handle.
 *
 * Stores the active TrustedLogin\Client instance in $GLOBALS so the
 * Playwright spec can call methods on it from `wp eval-file` (which
 * runs in WP context but doesn't see file-scoped variables from
 * client.php's load). Without this, every test would have to
 * re-instantiate the SDK with a duplicated copy of client.php's
 * config — and any drift between the two configs would cause silent
 * test bugs.
 *
 * Hooks late on `init` to ensure client.php has run + the SDK is
 * fully initialized.
 *
 * Loaded only inside the e2e docker stack — never ship.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'init',
    function () {
        // Gate: only instantiate inside wp-cli / wp eval contexts. In an
        // HTTP admin request the client.php at the repo root has already
        // instantiated TrustedLogin\Client for the same namespace —
        // creating a SECOND Client here would re-register all of the
        // SDK's admin hooks (menu page, scripts, the grant button) and
        // the admin page would render two grant buttons, breaking
        // grant-flow / popup / nonce-tampering / etc. specs with
        // strict-mode "resolved to 2 elements" failures. The handle is
        // only consumed by envelope-signing.spec.ts via wp eval — which
        // ships its own PHP process and does not see the HTTP-time
        // Client instance.
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        if ( ! class_exists( '\\TrustedLogin\\Client' ) ) {
            return;
        }

        // Re-build the same config client.php uses. The plugin entry
        // point keeps it as a local variable; we mirror that here.
        $public_key = '90bd9d918670ea15';
        $vendor_url = 'http://vendor-wp';
        $fixture    = WP_PLUGIN_DIR . '/trustedlogin-client/tests/e2e/fixtures/.cache-vendor-state.json';
        if ( is_readable( $fixture ) ) {
            $state = json_decode( (string) file_get_contents( $fixture ), true );
            if ( is_array( $state ) && ! empty( $state['vendor_url'] ) ) {
                $vendor_url = (string) $state['vendor_url'];
            }
        }

        $config = array(
            'auth'   => array( 'api_key' => $public_key ),
            'vendor' => array(
                'namespace'   => 'pro-block-builder',
                'title'       => 'Pro Block Builder',
                'email'       => 'support@example.com',
                'website'     => $vendor_url,
                'support_url' => rtrim( $vendor_url, '/' ) . '/support',
            ),
        );

        try {
            $tl_config           = new \TrustedLogin\Config( $config );
            $GLOBALS['__tl_e2e'] = array(
                'config' => $tl_config,
                'client' => new \TrustedLogin\Client( $tl_config ),
            );
        } catch ( \Throwable $e ) {
            // Surface but don't fatal — the spec's revoke check will
            // report a clean error message if the global isn't set.
            error_log( '[tl-e2e-sdk-handle] init failed: ' . $e->getMessage() );
        }
    },
    PHP_INT_MAX
);

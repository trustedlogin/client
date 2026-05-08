<?php
/**
 * Plugin Name: TrustedLogin e2e — extra referer allowlist
 * Description: Adds a test-only trusted URL via the allowed_referer_urls filter so e2e tests can exercise the extension point. Loaded only when bind-mounted in the Docker stack.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'trustedlogin/pro-block-builder/login_feedback/allowed_referer_urls', function( $urls ) {
    $urls[] = 'https://support.vendor.test/portal';
    return $urls;
} );

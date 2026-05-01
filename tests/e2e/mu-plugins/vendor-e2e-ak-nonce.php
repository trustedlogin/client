<?php
/**
 * Plugin Name: TrustedLogin e2e — vendor-side ak-nonce helper.
 *
 * Exposes an admin-ajax endpoint that mints a `wp_create_nonce(
 * AccessKeyLogin::NONCE_ACTION )` in the current admin's session.
 * The Playwright spec uses this to drive the connector's REST
 * `/wp-json/trustedlogin/v1/access_key` endpoint with a nonce that
 * actually validates (wp-cli mints in a different session token,
 * so the REST verifier rejects with 403).
 *
 * Endpoint: POST /wp-admin/admin-ajax.php?action=tl_e2e_get_ak_nonce
 *   Returns: { "nonce": "<10-char>" }
 *   Auth:    must be logged in as an admin (wp_verify_nonce uses
 *            the current user's session).
 *
 * Loaded only inside the e2e docker stack — never ship.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'wp_ajax_tl_e2e_get_ak_nonce',
    function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'must be admin' ), 403 );
        }
        if ( ! class_exists( '\\TrustedLogin\\Vendor\\AccessKeyLogin' ) ) {
            wp_send_json_error( array( 'message' => 'AccessKeyLogin missing' ), 500 );
        }
        wp_send_json_success(
            array(
                // Connector's custom AccessKeyLogin verifier:
                'ak_nonce'   => wp_create_nonce( \TrustedLogin\Vendor\AccessKeyLogin::NONCE_ACTION ),
                // WP REST cookie check (X-WP-Nonce header):
                'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            )
        );
    }
);

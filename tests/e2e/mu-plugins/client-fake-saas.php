<?php
/**
 * Plugin Name: TrustedLogin e2e — Client SaaS rewrite
 * Description: Points the trustedlogin-client library at the fake-saas docker service and at the in-stack vendor WP site for the public-key fetch.
 *
 * The client.php at the client repo root uses namespace "pro-block-builder"
 * (see $config['vendor']['namespace']), so hooks are trustedlogin/pro-block-builder/*.
 *
 * Loaded only inside the e2e docker stack — never ship this.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ns = 'pro-block-builder';

// Where the client posts envelopes (POST /sites/).
add_filter( "trustedlogin/{$ns}/api_url", function () {
	return 'http://fake-saas:8003/api/v1/';
} );

// Where the client fetches the vendor's sodium public key (overrides
// vendor.website config value in client.php).
add_filter( "trustedlogin/{$ns}/vendor/public_key/website", function () {
	return 'http://vendor-wp';
} );

// The library refuses to send envelopes over plain HTTP. The e2e stack runs on
// localhost without TLS; force the SSL gate open.
add_filter( "trustedlogin/{$ns}/meets_ssl_requirement", '__return_true' );

// Disable the remote webhook post so the grant flow doesn't try to reach
// https://example.com/webhook (configured in client.php).
add_filter( "trustedlogin/{$ns}/webhook_url", '__return_false' );

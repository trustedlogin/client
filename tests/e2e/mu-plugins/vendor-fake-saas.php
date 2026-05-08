<?php
/**
 * Plugin Name: TrustedLogin e2e — Vendor SaaS rewrite
 * Description: Local-test mu-plugin. Points the connector at the fake-saas docker service instead of app.trustedlogin.com.
 *
 * Loaded only inside the e2e docker stack — never ship this.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'trustedlogin/api-url/saas', function () {
	return 'http://fake-saas:8003/api/v1/';
} );

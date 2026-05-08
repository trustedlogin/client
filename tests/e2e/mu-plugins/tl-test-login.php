<?php
/**
 * Plugin Name: TrustedLogin Test Login Harness (e2e)
 * Description: Secret-gated endpoint that issues a WP auth cookie for a given
 *              user_id, so cap-enforcement.spec.ts can drive a real browser
 *              session as a freshly-minted support user without going through
 *              the full grant flow. Used only by the e2e stack — gated behind
 *              the TL_TEST_LOGIN_SECRET shared secret.
 *
 * GET /tl-test-login?user_id=123&k=<secret>  → sets auth cookie, 302s to /wp-admin/
 * GET /tl-test-logout?k=<secret>             → clears auth cookie, 302s to /
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	if ( ! preg_match( '#^(?:/index\.php)?/tl-test-(login|logout)/?$#', $path, $match ) ) {
		return;
	}

	$secret = defined( 'TL_TEST_LOGIN_SECRET' ) ? TL_TEST_LOGIN_SECRET : 'e2e-only';
	if ( ! isset( $_GET['k'] ) || ! hash_equals( $secret, (string) $_GET['k'] ) ) {
		status_header( 404 );
		exit;
	}

	if ( 'logout' === $match[1] ) {
		wp_clear_auth_cookie();
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
	$user    = $user_id ? get_userdata( $user_id ) : null;
	if ( ! $user ) {
		status_header( 400 );
		header( 'Content-Type: text/plain' );
		echo "tl-test-login: user_id missing or unknown\n";
		exit;
	}

	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );

	$redirect = isset( $_GET['redirect'] ) ? (string) wp_unslash( $_GET['redirect'] ) : '/wp-admin/';
	wp_safe_redirect( $redirect );
	exit;
}, 1 );

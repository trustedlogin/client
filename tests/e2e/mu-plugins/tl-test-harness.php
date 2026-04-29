<?php
/**
 * Plugin Name: TrustedLogin Test Harness (e2e)
 * Description: Runtime knobs for e2e specs. Loads BEFORE TrustedLogin so the
 *              kill-switch constants take effect at the right moment, and
 *              registers filters the specs use to flip SSL gating, override
 *              clone_role, capture webhook payloads, etc.
 *
 * Knobs are read from options so a spec can flip behavior with `wp option update`
 * without restarting the container. Each test should leave the option in its
 * default state via afterEach/afterAll.
 *
 *   tl_test_disable_global   → defines TRUSTEDLOGIN_DISABLE
 *   tl_test_disable_ns       → defines TRUSTEDLOGIN_DISABLE_<NS> (uppercase)
 *   tl_test_force_ssl_false  → forces meets_ssl_requirement=false on every namespace
 *   tl_test_webhook_capture  → if 'yes', stores every TL action payload in tl_test_webhook_log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
//  Kill-switch constants — must be defined BEFORE TrustedLogin\Client loads.
//  mu-plugins load before regular plugins, so this is the right hook point.
// ---------------------------------------------------------------------------
( static function () {
	$global_disable = get_option( 'tl_test_disable_global', '' );
	if ( 'yes' === $global_disable && ! defined( 'TRUSTEDLOGIN_DISABLE' ) ) {
		define( 'TRUSTEDLOGIN_DISABLE', true );
	}

	$ns_disable = (string) get_option( 'tl_test_disable_ns', '' );
	if ( '' !== $ns_disable ) {
		// The SDK calls defined('TRUSTEDLOGIN_DISABLE_' . strtoupper($ns)) — no
		// sanitization, so for namespace 'pro-block-builder' the constant name
		// contains literal dashes. PHP's define()/defined() accept any string,
		// so this works even though the const can't be referenced as a bareword.
		$const = 'TRUSTEDLOGIN_DISABLE_' . strtoupper( $ns_disable );
		if ( ! defined( $const ) ) {
			define( $const, true );
		}
	}
} )();

// ---------------------------------------------------------------------------
//  Force fake-saas POST /api/v1/sites to return 500 — for the
//  grant-error-banner spec. Returns BEFORE the real fake-saas is
//  reached so the SDK\'s error path runs against a deterministic
//  500 response.
// ---------------------------------------------------------------------------
add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
	if ( 'yes' !== get_option( 'tl_test_force_saas_500', '' ) ) {
		return $preempt;
	}
	if ( false === strpos( (string) $url, '/api/v1/sites' ) ) {
		return $preempt;
	}
	$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
	if ( 'POST' !== $method ) {
		return $preempt;
	}
	return array(
		'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
		'body'     => '{"error":"forced-500-by-test-harness"}',
		'headers'  => array(),
		'cookies'  => array(),
		'filename' => null,
	);
}, 9, 3 );

// ---------------------------------------------------------------------------
//  SSL gate override — applied to the pro-block-builder namespace only.
//  Two options control direction:
//    tl_test_force_ssl_false=yes → meets_ssl_requirement returns false
//                                  (test the SSL-fail path)
//    tl_test_force_ssl_true=yes  → returns true even on http://
//                                  (let the grant flow run on the local
//                                  HTTP stack without Caddy TLS sidecar)
//  When neither is set, the SDK's default require_ssl logic applies.
// ---------------------------------------------------------------------------
add_action( 'init', static function () {
	if ( 'yes' === get_option( 'tl_test_force_ssl_false', '' ) ) {
		add_filter( 'trustedlogin/pro-block-builder/meets_ssl_requirement', '__return_false' );
	}
	if ( 'yes' === get_option( 'tl_test_force_ssl_true', '' ) ) {
		add_filter( 'trustedlogin/pro-block-builder/meets_ssl_requirement', '__return_true' );
	}
}, 1 );

// ---------------------------------------------------------------------------
//  Magic-link diagnostic capture — when on, record login/refused +
//  login/error payloads to `tl_test_login_failures` so specs can
//  pinpoint exactly which gate (security_check / login_failed) the
//  endpoint flow tripped on. Only fires when explicitly enabled to
//  avoid polluting normal runs.
// ---------------------------------------------------------------------------
add_action( 'init', static function () {
	if ( 'yes' !== get_option( 'tl_test_capture_login_failures', '' ) ) {
		return;
	}

	$capture = static function ( $user_identifier, $error ) {
		$log   = (array) get_option( 'tl_test_login_failures', array() );
		$log[] = array(
			'hook'  => current_filter(),
			'ident' => is_string( $user_identifier ) ? substr( $user_identifier, 0, 20 ) . '…' : '(non-string)',
			'code'  => is_wp_error( $error ) ? $error->get_error_code() : '(no-error)',
			'msg'   => is_wp_error( $error ) ? $error->get_error_message() : '(no-error)',
			'time'  => time(),
		);
		update_option( 'tl_test_login_failures', $log, false );
	};

	add_action( 'trustedlogin/pro-block-builder/login/refused', $capture, 1, 2 );
	add_action( 'trustedlogin/pro-block-builder/login/error', $capture, 1, 2 );
}, 1 );

// ---------------------------------------------------------------------------
//  Test-only nonce minter — used by security-ajax-cap-bypass.spec.ts.
//  WP nonces are session-token-bound, so a wpCli call can\'t produce
//  a nonce that the Apache request will validate. Hitting this from
//  the same authenticated browser context (cookies + session token
//  present) returns a real tl_nonce-<uid> that will pass
//  check_ajax_referer. The test then aims that nonce at the AJAX
//  handler — the cap check is the load-bearing gate under test, the
//  nonce check is the layer this helper sidesteps. Gated on
//  is_user_logged_in() so this isn\'t a public oracle.
// ---------------------------------------------------------------------------
add_action( 'init', static function () {
	if ( ! isset( $_GET['tl_test_mint_nonce'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_die( 'tl_test_mint_nonce requires authentication', 'tl_test', array( 'response' => 401 ) );
	}
	$uid = (int) get_current_user_id();
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array(
		'user_id' => $uid,
		'nonce'   => wp_create_nonce( 'tl_nonce-' . $uid ),
	) );
	exit;
} );

// ---------------------------------------------------------------------------
//  Force-redirect the vendor pubkey fetch to an unreachable URL —
//  mirrors a real DNS failure / firewall block of vendor-wp from the
//  customer site. Used by pubkey-network-failure.spec.ts. Filter
//  chains AFTER the client-fake-saas one so this overrides it when
//  the option is set.
// ---------------------------------------------------------------------------
add_filter( 'trustedlogin/pro-block-builder/vendor/public_key/website', static function ( $url ) {
	if ( 'yes' !== get_option( 'tl_test_break_pubkey_fetch', '' ) ) {
		return $url;
	}
	// Black-hole: a routable but non-listening port on a hostname
	// that wp_remote_request reaches but gets refused/timed out by.
	// 127.0.0.1:1 is unprivileged and refuses in microseconds, so the
	// AJAX returns fast — no test timeout, just a genuine failure.
	return 'http://127.0.0.1:1';
}, 99 );

// ---------------------------------------------------------------------------
//  Webhook capture — store every TL lifecycle action payload to an option.
//  Specs read this option via wp-cli to assert delivery.
// ---------------------------------------------------------------------------
add_action( 'init', static function () {
	if ( 'yes' !== get_option( 'tl_test_webhook_capture', '' ) ) {
		return;
	}

	$record = static function ( $payload ) {
		$current_filter = current_filter();
		$log            = (array) get_option( 'tl_test_webhook_log', array() );
		$log[]          = array(
			'hook'    => $current_filter,
			'payload' => $payload,
			'time'    => time(),
		);
		update_option( 'tl_test_webhook_log', $log, false );
	};

	foreach ( array( 'created', 'extended', 'revoked', 'logged_in' ) as $action ) {
		add_action( 'trustedlogin/pro-block-builder/access/' . $action, $record, 1 );
	}
	add_action( 'trustedlogin/pro-block-builder/logged_in', $record, 1 );
}, 1 );

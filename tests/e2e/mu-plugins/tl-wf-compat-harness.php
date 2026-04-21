<?php
/**
 * Plugin Name: TrustedLogin Wordfence Compat Harness (e2e)
 * Description: Exposes two endpoints used by compat-wordfence.spec.ts to put
 *              Wordfence's WAF into enforce mode during the test run, then
 *              restore learning mode on teardown. Runs in the Apache request
 *              context (not CLI) because wfWAF's file-writing layer short-
 *              circuits under CLI — see wfWAFStorageFile::allowFileWriting().
 *
 * GET /tl-wf-harness/enable  → wafStatus=enabled, clear learning-mode allowlist
 * GET /tl-wf-harness/disable → wafStatus=disabled
 *
 * Access is gated on a query secret supplied via the TL_WF_HARNESS_SECRET
 * constant to keep accidental hits on a dev box from flipping WAF state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	// Pin the match to the path segment ONLY. A substring match on
	// REQUEST_URI would match a legitimate query like ?redirect=/tl-wf-harness/…
	// and light up this harness for any admin who pasted such a URL.
	$request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	if ( ! preg_match( '#^(?:/index\.php)?/tl-wf-harness/(enable|disable)/?$#', $request_path, $match ) ) {
		return;
	}
	$action = $match[1];

	$secret = defined( 'TL_WF_HARNESS_SECRET' ) ? TL_WF_HARNESS_SECRET : 'e2e-only';
	if ( ! isset( $_GET['k'] ) || ! hash_equals( $secret, (string) $_GET['k'] ) ) {
		status_header( 404 );
		exit;
	}

	header( 'Content-Type: text/plain' );

	if ( ! class_exists( 'wfWAF' ) || ! wfWAF::getInstance() ) {
		echo "wfWAF not loaded\n";
		exit;
	}

	$e = wfWAF::getInstance()->getStorageEngine();

	// Wordfence's storage engine can be null if WAF state is broken
	// (corrupted wflogs dir, missing config). Bail cleanly so the spec
	// sees a clear error instead of a PHP fatal.
	if ( ! $e ) {
		status_header( 503 );
		echo "storage engine unavailable\n";
		exit;
	}

	if ( 'enable' === $action ) {
		$e->setConfig( 'wafStatus', 'enabled' );
		$e->unsetConfig( 'whitelistedURLParams', 'livewaf' );
		$e->saveConfig( '' );
		$e->saveConfig( 'livewaf' );
		echo 'wafStatus=' . $e->getConfig( 'wafStatus', 'UNSET' ) . PHP_EOL;
		echo 'isDisabled=' . ( wfWAF::getInstance()->isDisabled() ? 'Y' : 'N' ) . PHP_EOL;
		echo 'OK';
		exit;
	}

	if ( 'disable' === $action ) {
		$e->setConfig( 'wafStatus', 'disabled' );
		$e->saveConfig( '' );
		echo 'wafStatus=' . $e->getConfig( 'wafStatus', 'UNSET' ) . PHP_EOL;
		echo 'OK';
		exit;
	}

	// Regex guarantees one of enable/disable matched — this is
	// a safety net only.
	status_header( 404 );
	exit;
}, 1 );

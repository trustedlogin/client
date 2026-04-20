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
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( strpos( $path, '/tl-wf-harness/' ) === false ) {
		return;
	}

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

	if ( strpos( $path, '/tl-wf-harness/enable' ) !== false ) {
		$e->setConfig( 'wafStatus', 'enabled' );
		$e->unsetConfig( 'whitelistedURLParams', 'livewaf' );
		$e->saveConfig( '' );
		$e->saveConfig( 'livewaf' );
		echo 'wafStatus=' . $e->getConfig( 'wafStatus', 'UNSET' ) . PHP_EOL;
		echo 'isDisabled=' . ( wfWAF::getInstance()->isDisabled() ? 'Y' : 'N' ) . PHP_EOL;
		echo 'OK';
		exit;
	}

	if ( strpos( $path, '/tl-wf-harness/disable' ) !== false ) {
		$e->setConfig( 'wafStatus', 'disabled' );
		$e->saveConfig( '' );
		echo 'wafStatus=' . $e->getConfig( 'wafStatus', 'UNSET' ) . PHP_EOL;
		echo 'OK';
		exit;
	}

	status_header( 404 );
	exit;
}, 1 );

<?php
/**
 * Plugin Name: TrustedLogin e2e — vendor REST route probe
 * Description: Diagnostic mu-plugin. Logs the registered REST route table
 *              and any TrustedLogin route presence to wp-content/debug.log
 *              once per HTTP request, after rest_api_init has fired.
 *
 * Loaded only inside the e2e docker stack — never ship this. Will be
 * removed once the connector REST 404 in CI is fully understood.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	$server = rest_get_server();
	$routes = $server->get_routes();
	$tl     = array_values( array_filter( array_keys( $routes ), function ( $r ) {
		return false !== stripos( $r, 'trustedlogin' );
	} ) );

	$ctx = array(
		'sapi'                  => PHP_SAPI,
		'is_cli'                => defined( 'WP_CLI' ),
		'request_uri'           => isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '(none)',
		'rest_route_get'        => isset( $_GET['rest_route'] ) ? wp_unslash( (string) $_GET['rest_route'] ) : '(none)',
		'connector_loaded'      => function_exists( 'trustedlogin_connector' ),
		'public_key_class'      => class_exists( 'TrustedLogin\\Vendor\\Endpoints\\PublicKey', false ),
		'public_key_registered' => isset( $routes['/trustedlogin/v1/public_key'] ),
		'tl_routes_count'       => count( $tl ),
		'tl_routes'             => $tl,
	);

	error_log( '[TL-PROBE] ' . wp_json_encode( $ctx ) );
}, PHP_INT_MAX );

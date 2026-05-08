<?php
/**
 * Plugin Name: TrustedLogin Response Injector (e2e)
 * Description: Lets compat-malformed-pubkey-response.spec.ts script the
 *              outgoing pubkey fetch — replace the response with a
 *              scripted HTML body, empty body, JSON missing publicKey, etc.
 *              Reads the scripted response from option `tl_inject_pubkey_response`.
 *
 * The spec sets that option via wp-cli before each test; this mu-plugin
 * reads it inside pre_http_request and returns the scripted shape.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( ! is_string( $url ) ) {
			return $preempt;
		}

		// Only intercept vendor pubkey fetches. The Client builds these
		// URLs via add_query_arg( ['rest_route' => '/trustedlogin/v1/public_key'], vendor_url ).
		// Match on the path component so we don't accidentally catch the
		// SaaS sync POSTs or webhook captures.
		if ( false === strpos( $url, '/trustedlogin/v1/public_key' )
		     && false === strpos( $url, 'rest_route=%2Ftrustedlogin%2Fv1%2Fpublic_key' ) ) {
			return $preempt;
		}

		$inject = get_option( 'tl_inject_pubkey_response' );
		if ( ! is_array( $inject ) || empty( $inject['mode'] ) ) {
			return $preempt;
		}

		// Counter for tests that need to assert the injector actually
		// intercepted. Production never reads this — it\'s test-only.
		$hits   = (int) get_option( 'tl_inject_pubkey_hits', 0 );
		update_option( 'tl_inject_pubkey_hits', $hits + 1, false );
		$urls   = (array) get_option( 'tl_inject_pubkey_urls', array() );
		$urls[] = $url;
		update_option( 'tl_inject_pubkey_urls', $urls, false );

		$mode = (string) $inject['mode'];

		switch ( $mode ) {
			case 'html_cloudflare_415':
				return array(
					'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
					'body'     => "<!DOCTYPE html>\n<html><head><title>Cloudflare | Unsupported Media Type</title></head><body><h1>415 Unsupported Media Type</h1><p>Ray ID: abc123</p></body></html>",
					'response' => array( 'code' => 415, 'message' => 'Unsupported Media Type' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'html_wordfence_403':
				return array(
					'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
					'body'     => "<!DOCTYPE html>\n<html><head><title>Forbidden - Wordfence</title></head><body><h1>Your access to this site has been limited</h1><p>Human verification failed. (Wordfence)</p></body></html>",
					'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'empty_body_200':
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => '',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'json_missing_publickey':
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'somethingElse' => 'nope' ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'json_empty_publickey':
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'publicKey' => '' ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'json_non_hex_publickey':
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'publicKey' => 'this-is-not-64-hex-chars' ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'http_501':
				// Connector's getPublicKey() returned WP_Error — the REST
				// endpoint sets status 501 and DOES NOT set body data.
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => '',
					'response' => array( 'code' => 501, 'message' => 'Not Implemented' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'http_502_nginx_html':
				return array(
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => "<html>\n<head><title>502 Bad Gateway</title></head>\n<body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body></html>",
					'response' => array( 'code' => 502, 'message' => 'Bad Gateway' ),
					'cookies'  => array(),
					'filename' => null,
				);

			case 'request_failed':
				return new WP_Error( 'http_request_failed', 'simulated DNS failure' );

			case 'unexpected_415_json':
				// Rare but seen: hosting firewall rewrites the body to JSON
				// but still issues 415. We should still surface the status.
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'blocked_by' => 'imunify360' ) ),
					'response' => array( 'code' => 415, 'message' => 'Unsupported Media Type' ),
					'cookies'  => array(),
					'filename' => null,
				);
		}

		return $preempt;
	},
	10,
	3
);

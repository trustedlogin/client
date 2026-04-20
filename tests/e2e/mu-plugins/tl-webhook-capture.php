<?php
/**
 * Plugin Name: TrustedLogin Webhook Capture (e2e)
 * Description: Intercepts outgoing webhook POSTs on the client site so e2e
 *              tests can inspect the exact request args (body + headers)
 *              without needing a reachable receiver. Used by
 *              compat-wordfence.spec.ts to verify the JSON-encoding fix.
 *
 * Stores the captured args in the option `tl_captured_webhook_args`. Tests
 * read it via wp-cli, then assert shape. A fake 200 response is returned so
 * the TL code path treats the webhook as successful.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		// Only intercept requests that look like TL webhooks. We can't hard-match
		// on a single URL because client.php uses example.com/webhook by default
		// and tests may re-point it elsewhere.
		if ( ! is_string( $url ) ) {
			return $preempt;
		}

		$is_webhook =
			false !== strpos( $url, '/webhook' )
			|| false !== strpos( $url, 'trustedlogin/v1/webhook' )
			|| false !== strpos( $url, 'example.com' );

		if ( ! $is_webhook ) {
			return $preempt;
		}

		// Record what we would have sent. Normalize the body into two
		// shapes the tests can reason about:
		//   - `body`         — raw value passed to wp_remote_post (string OR array)
		//   - `body_on_wire` — what actually hits the network:
		//       array  → http_build_query() (i.e. application/x-www-form-urlencoded)
		//       string → unchanged
		$body_raw = isset( $args['body'] ) ? $args['body'] : '';
		$body_on_wire = is_array( $body_raw )
			? http_build_query( $body_raw )
			: (string) $body_raw;

		$capture = array(
			'url'            => $url,
			'method'         => isset( $args['method'] ) ? $args['method'] : 'GET',
			'headers'        => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'           => $body_raw,
			'body_is_string' => is_string( $body_raw ),
			'body_on_wire'   => $body_on_wire,
			'captured_at'    => microtime( true ),
		);
		update_option( 'tl_captured_webhook_args', $capture, false );

		// Return a fake success response so TL's code treats it as delivered.
		return array(
			'headers'  => array(),
			'body'     => 'captured',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

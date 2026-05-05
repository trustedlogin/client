<?php
/**
 * Class Remote
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2021 Katz Web Services, Inc.
 */

namespace TrustedLogin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use WP_Error;
use WP_User;
use WP_Admin_Bar;

/**
 * The TrustedLogin all-in-one drop-in class.
 */
final class Remote {

	/**
	 * The API url for the TrustedLogin SaaS Platform (with trailing slash).
	 *
	 * @var string
	 * @since 1.0.0
	 */
	const API_URL = 'https://app.trustedlogin.com/api/v1/';

	/**
	 * Config object.
	 *
	 * @var Config $config
	 */
	private $config;

	/**
	 * Logging object.
	 *
	 * @var Logging $logging
	 */
	private $logging;

	/**
	 * Remote constructor.
	 *
	 * @param Config  $config Config object.
	 * @param Logging $logging Logging object.
	 */
	public function __construct( Config $config, Logging $logging ) {
		$this->config  = $config;
		$this->logging = $logging;
	}

	/**
	 * Add hooks for the class to send webhooks when access is created, extended, or revoked, or the user has logged-in.
	 *
	 * @since 1.0.0
	 */
	public function init() {

		// If the webhook URL is not set, don't add the actions to speed up initialization.
		if ( ! $this->config->get_setting( 'webhook/url' ) && ! $this->config->get_setting( 'webhook_url' ) ) {
			return;
		}

		add_action( 'trustedlogin/' . $this->config->ns() . '/access/created', array( $this, 'maybe_send_webhook' ) ); // @phpstan-ignore-line
		add_action( 'trustedlogin/' . $this->config->ns() . '/access/extended', array( $this, 'maybe_send_webhook' ) ); // @phpstan-ignore-line
		add_action( 'trustedlogin/' . $this->config->ns() . '/access/revoked', array( $this, 'maybe_send_webhook' ) ); // @phpstan-ignore-line
		add_action( 'trustedlogin/' . $this->config->ns() . '/logged_in', array( $this, 'maybe_send_webhook' ) ); // @phpstan-ignore-line
	}

	/**
	 * POSTs to `webhook/url`, if defined in the configuration array.
	 *
	 * @since 1.0.0
	 * @since 1.4.0 $data now includes the `$access_key` and `$debug_data` keys.
	 * @since 1.5.0 $data now includes the `$ticket` key.
	 *
	 * @param array $data {
	 *   The data to send to the webhook.
	 *   @type string $url The site URL as returned by get_site_url().
	 *   @type string $ns Namespace of the plugin.
	 *   @type string $action "created", "extended", "logged_in", or "revoked".
	 *   @type string $access_key The access key.
	 *   @type string $debug_data (Optional) Site debug data from {@see WP_Debug_Data::debug_data()}, sent if `webhook/debug_data` is true.
	 *   @type string $ref (Optional) Support ticket Reference ID.
	 *   @type array $ticket (Optional) Support ticket provided by customer with `message` key.
	 * }
	 *
	 * @return bool|WP_Error False: webhook setting not defined; True: success; WP_Error: error!
	 */
	public function maybe_send_webhook( $data ) {

		$webhook_url = $this->config->get_setting( 'webhook/url' );

		if ( ! $webhook_url ) {
			// Back compatibility with v1–v1.3.4.
			$webhook_url = $this->config->get_setting( 'webhook_url' );
		}

		if ( ! $webhook_url ) {
			return false;
		}

		if ( ! wp_http_validate_url( $webhook_url ) ) {
			$error = new \WP_Error( 'invalid_webhook_url', 'An invalid `webhook/url` setting was passed to the TrustedLogin Client: ' . esc_attr( $webhook_url ) );

			$this->logging->log( $error, __METHOD__, 'error' );

			return $error;
		}

		try {
			// JSON-encode the webhook payload. A form-encoded body made up of
			// `debug_data=<wp-core debug dump>` trips Wordfence's XSS rule
			// because the dump contains `###` headings and %0A newlines that
			// match the rule's signature. JSON with an explicit Content-Type
			// avoids the false positive and is cleaner for any downstream
			// receiver to parse. Both form-encoded and JSON requests are
			// accepted by the Connector's REST endpoint (and by WordPress
			// core REST endpoints in general), so this is drop-in compatible
			// with the shipped Connector plugin.
			//
			// Integrators whose custom webhook receiver requires form
			// encoding can revert to the legacy shape from the filter below:
			//
			// add_filter( 'trustedlogin/{ns}/webhook/request_args',
			// function ( $args, $url, $data ) {
			// return array( 'body' => $data );
			// }, 10, 3 ); .
			$encoded = wp_json_encode( $data );
			if ( false === $encoded ) {
				// Falls through to form encoding only when JSON encoding
				// can't represent the payload (e.g. non-UTF-8 bytes in
				// $data). Preserves pre-1.9.1 behavior for those edge cases.
				$args = array( 'body' => $data );
			} else {
				$args = array(
					'body'    => $encoded,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				);
			}

			/**
			 * Filter: request arguments passed to `wp_remote_post()` when
			 * sending a webhook.
			 *
			 * Lets integrators adjust headers, swap the body format, attach
			 * bearer tokens, etc. — without forking the Client SDK.
			 *
			 * @since 1.9.1
			 *
			 * @param array  $args        Request args. Default body = JSON-encoded $data
			 *                            with `Content-Type: application/json`.
			 * @param string $webhook_url The URL being posted to.
			 * @param array  $data        The original body payload.
			 */
			$args = apply_filters(
				'trustedlogin/' . $this->config->ns() . '/webhook/request_args',
				$args,
				$webhook_url,
				$data
			);

			$posted = wp_remote_post( $webhook_url, $args );

			if ( is_wp_error( $posted ) ) {
				$this->logging->log( 'An error encountered while sending a webhook to ' . esc_attr( $webhook_url ), __METHOD__, 'error', $posted );

				return $posted;
			}

			$this->logging->log( 'Webhook was sent to ' . esc_attr( $webhook_url ), __METHOD__, 'debug', $data );

			return true;
		} catch ( Exception $exception ) {
			$this->logging->log( 'A fatal error was triggered while sending a webhook to ' . esc_attr( $webhook_url ) . ': ' . $exception->getMessage(), __METHOD__, 'error' );

			return new \WP_Error( $exception->getCode(), $exception->getMessage() );
		}
	}

	/**
	 * API Function: send the API request
	 *
	 * @since 1.0.0
	 *
	 * @param string   $path               Path for the REST API request (no initial or trailing slash needed).
	 * @param array    $data               Data sent with POST, PUT, or DELETE requests as JSON-encoded body.
	 * @param string   $method             HTTP method to use for the request.
	 * @param array    $additional_headers Any additional headers to be set with the request. Merged with default headers.
	 * @param int|null $timeout            Per-call timeout (seconds). null falls through to the class default.
	 *
	 * @return array|WP_Error wp_remote_request() response or WP_Error if something went wrong
	 */
	public function send( $path, $data, $method = 'POST', $additional_headers = array(), $timeout = null ) {

		if ( ! is_string( $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->logging->log( sprintf( 'Error: Path not a string (%s)', print_r( $path, true ) ), __METHOD__, 'critical' );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return new \WP_Error( 'invalid_path', sprintf( 'Error: Path "%s" is not a string', print_r( $path, true ) ) );
		}

		if ( ! is_string( $method ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->logging->log( sprintf( 'Error: Method not a string (%s)', print_r( $method, true ) ), __METHOD__, 'critical' );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return new \WP_Error( 'invalid_method', sprintf( 'Error: HTTP method "%s" is not a string', print_r( $method, true ) ) );
		}

		$method = strtoupper( $method );

		if ( ! in_array(
			$method,
			array(
				'POST',
				'PUT',
				'GET',
				'HEAD',
				'DELETE',
			),
			true
		) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->logging->log( sprintf( 'Error: Method not in allowed array list (%s)', print_r( $method, true ) ), __METHOD__, 'critical' );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return new \WP_Error( 'invalid_method', sprintf( 'Error: HTTP method "%s" is not in the list of allowed methods', print_r( $method, true ) ) );
		}

		$headers = array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->config->get_setting( 'auth/api_key' ),
		);

		if ( ! empty( $additional_headers ) ) {
			$headers = array_merge( $headers, $additional_headers );
		}

		$request_options = array(
			'method'      => $method,
			'timeout'     => null === $timeout ? 15 : (int) $timeout,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);

		if ( ! empty( $data ) && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$request_options['body'] = wp_json_encode( $data );
		}

		try {
			$api_url = $this->build_api_url( $path );

			// Deep-copy $request_options and scrub secrets before logging.
			// The Authorization header carries the vendor Bearer token; any
			// write of its raw value to a log file is a credential-leak
			// risk (debug logs can be web-reachable in some deployments).
			$loggable = self::scrub_sensitive_headers( $request_options );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->logging->log( sprintf( 'Sending to %s: %s', $api_url, print_r( $loggable, true ) ), __METHOD__, 'debug' );

			$response = wp_remote_request( $api_url, $request_options );
		} catch ( Exception $exception ) {
			$error = new \WP_Error( 'wp_remote_request_exception', sprintf( 'There was an exception during the remote request: %s (%s)', $exception->getMessage(), $exception->getCode() ) );

			$this->logging->log( $error, __METHOD__, 'error' );

			return $error;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$this->logging->log( sprintf( 'Response: %s', print_r( $response, true ) ), __METHOD__, 'debug' );

		return $response;
	}

	/**
	 * Deep-copy a wp_remote_request options array and redact sensitive
	 * header/body values so it's safe to emit to a log file.
	 *
	 * Scrub list: Authorization, auth, api_key (case-insensitive) wherever
	 * they appear at the top level or inside a `headers` array.
	 *
	 * @param array $request_options The raw options about to go to wp_remote_request().
	 *
	 * @return array Deep-copied options with sensitive fields replaced by '[redacted]'.
	 */
	private static function scrub_sensitive_headers( $request_options ) {
		$sensitive = array( 'authorization', 'auth', 'api_key' );

		// Deep copy via recursive walk — arrays in PHP are copy-on-write for
		// the top level, but headers is a nested array we'll mutate.
		$copy = is_array( $request_options ) ? $request_options : array();

		foreach ( $copy as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $sensitive, true ) ) {
				$copy[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$copy[ $key ] = self::scrub_sensitive_headers( $value );
			}
		}

		return $copy;
	}

	/**
	 * Builds URL to API endpoints
	 *
	 * @since 1.0.0
	 * @since 1.9.0 Throws an exception if the endpoint is not a string.
	 *
	 * @param string $endpoint Endpoint to hit on the API; example "sites" or "sites/{$site_identifier}".
	 *
	 * @throws Exception If the endpoint is not a string.
	 *
	 * @return string
	 */
	private function build_api_url( $endpoint = '' ) {

		if ( ! is_string( $endpoint ) ) {
			// Code for endpoint not being a string.
			throw new Exception( 'Endpoint must be a string.', 400 );
		}

		/**
		 * Modifies the endpoint URL for the TrustedLogin service.
		 *
		 * @internal This allows pointing requests to testing servers.
		 *
		 * @param string $url URL to TrustedLogin API.
		 */
		$base_url = apply_filters( 'trustedlogin/' . $this->config->ns() . '/api_url', self::API_URL );

		return trailingslashit( $base_url ) . $endpoint;
	}

	/**
	 * Translates response codes to more nuanced error descriptions specific to TrustedLogin.
	 *
	 * @param array|WP_Error $api_response Response from HTTP API.
	 *
	 * @return int|WP_Error|null If valid response, the response code ID or null. If error, a WP_Error with a message description.
	 */
	public static function check_response_code( $api_response ) {

		if ( is_wp_error( $api_response ) ) {
			$response_code = $api_response->get_error_code();
		} else {
			$response_code = wp_remote_retrieve_response_code( $api_response );
		}

		switch ( $response_code ) {

			// Successful response, but no sites found.
			case 204:
				return null;

			// All customer-facing messages in this switch deliberately avoid
			// naming "TrustedLogin" or the internal "vendor" concept. The
			// person seeing this error just wants support for the plugin
			// they installed. Every response tells them what to do next,
			// not what broke internally.

			case 400:
			case 423:
				return new \WP_Error( 'unable_to_verify', esc_html__( 'Support access could not be set up right now. Please try again in a few minutes, or contact the plugin\'s support team.', 'trustedlogin' ), $api_response );

			case 401:
				return new \WP_Error( 'unauthenticated', esc_html__( 'Support access could not be verified. Please contact the plugin\'s support team.', 'trustedlogin' ), $api_response );

			case 402:
				return new \WP_Error( 'account_error', esc_html__( 'The support team\'s account has an issue that\'s preventing access. Please contact them directly.', 'trustedlogin' ), $api_response );

			case 403:
				return new \WP_Error( 'invalid_token', esc_html__( 'Support access was refused. Please contact the plugin\'s support team.', 'trustedlogin' ), $api_response );

			// The vendor-side endpoint returned 404. Most often: Connector
			// not installed on the vendor site, or the REST route is
			// disabled by a security plugin on the vendor.
			case 404:
				return new \WP_Error( 'not_found', esc_html__( 'The support team\'s site is not ready to receive access requests. Please contact their support team and let them know.', 'trustedlogin' ), $api_response );

			case 418:
				return new \WP_Error( 'teapot', '🫖', $api_response );

			// Server offline: connection refused, DNS failure, timeout, or
			// the vendor's site returned 500/503.
			case 500:
			case 503:
			case 'http_request_failed':
				return new \WP_Error( 'unavailable', esc_html__( 'The support team\'s site is temporarily unreachable. Please try again in a few minutes.', 'trustedlogin' ), $api_response );

			// Vendor returned a 501/502/522 — server-side error on their
			// end. Retrying likely won't help until they fix it.
			case 501:
			case 502:
			case 522:
				return new \WP_Error( 'server_error', esc_html__( 'The support team\'s site returned an error. Please contact their support team directly.', 'trustedlogin' ), $api_response );

			// wp_remote_retrieve_response_code() couldn't parse the
			// response at all — network layer failure.
			case '':
				return new \WP_Error( 'invalid_response', esc_html__( 'Could not reach the support team\'s site. Please check your internet connection and try again.', 'trustedlogin' ), $api_response );

			// Any response code we don't explicitly map. Preserve the
			// HTTP status in the returned WP_Error so the UI / logs can
			// surface it — silently returning an int here used to drop
			// the context on the floor (see Cloudflare 415 tickets).
			default:
				$status = (int) $response_code;

				if ( $status >= 200 && $status < 300 ) {
					// Preserve legacy behavior: a 2xx without specific
					// handling is a success path for upstream callers.
					return $status;
				}

				return new \WP_Error(
					'unexpected_response_code',
					sprintf(
						/* translators: %d: the HTTP status code returned by the vendor site */
						esc_html__( 'Support access could not be set up (HTTP %d). Please contact the plugin\'s support team and share this number.', 'trustedlogin' ),
						$status
					),
					array(
						'status'       => $status,
						'api_response' => $api_response,
					)
				);
		}
	}

	/**
	 * Returns true when the body of a successful-looking HTTP response is
	 * an HTML document rather than JSON — an extremely common shape when
	 * a hosting firewall (Wordfence, Cloudflare, Imunify360, Sucuri) or
	 * CDN has intercepted the request and returned its own branded error
	 * page in place of the expected JSON body.
	 *
	 * Only matches on document-level tags at the very start of the body
	 * (after leading whitespace). A JSON string value that happens to
	 * contain `<html>` inside one of its fields won't trigger this.
	 *
	 * @since 1.10.0
	 *
	 * @param string $response_body Raw HTTP response body.
	 *
	 * @return bool True if the body is document-shaped HTML.
	 */
	public static function body_looks_like_html( $response_body ) {
		$leading = ltrim( (string) $response_body );

		if ( '' === $leading ) {
			return false;
		}

		return 1 === preg_match(
			'/^<!?(?:DOCTYPE\s+html|html[\s>]|head[\s>]|body[\s>])/i',
			$leading
		);
	}

	/**
	 * Detects firewall / CDN HTML intercepts in an HTTP response and
	 * converts them into a customer-friendly WP_Error naming the
	 * likely cause (firewall) and preserving the HTTP status.
	 *
	 * Called from {@see self::handle_response()} BEFORE the status is
	 * mapped — we want "firewall blocked this (HTTP 502)" instead of
	 * the generic "server error" copy, because the actionable next
	 * step differs.
	 *
	 * @since 1.10.0
	 *
	 * @param array $api_response The raw array returned by wp_remote_request().
	 *
	 * @return null|\WP_Error WP_Error when HTML body detected; null when the
	 *                       response is not HTML-shaped (continue normal handling).
	 */
	private function detect_firewall_intercept( $api_response ) {
		$body = (string) wp_remote_retrieve_body( $api_response );

		if ( ! self::body_looks_like_html( $body ) ) {
			return null;
		}

		$status       = (int) wp_remote_retrieve_response_code( $api_response );
		$body_preview = mb_substr( wp_strip_all_tags( ltrim( $body ) ), 0, 200, 'UTF-8' );

		$this->logging->log(
			sprintf(
				'Vendor returned HTML (HTTP %d) instead of JSON. Likely a firewall / CDN intercept. Body preview: %s',
				$status,
				$body_preview
			),
			__METHOD__,
			'error'
		);

		return new \WP_Error(
			'vendor_response_not_json',
			sprintf(
				/* translators: %d: the HTTP status code returned by the support team's site */
				esc_html__( 'Support access could not be set up. A firewall on the plugin\'s support team\'s site blocked the request (HTTP %d). Please contact them and let them know — they\'ll need to allowlist this site or check their firewall logs.', 'trustedlogin' ),
				$status
			),
			array(
				'status'       => $status,
				'body_preview' => $body_preview,
			)
		);
	}

	/**
	 * API Response Handler
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $api_response The response from HTTP API.
	 * @param array          $required_keys If the response JSON must have specific keys in it, pass them here.
	 *
	 * @return array|WP_Error|null If successful response, returns array of JSON data. If failed, returns WP_Error. If
	 */
	public function handle_response( $api_response, $required_keys = array() ) {

		// Short-circuit on WAF-shaped responses BEFORE
		// {@see self::check_response_code()} maps the status to a generic
		// error. A firewall-intercepted HTML page at any status is more
		// actionable to the customer ("your firewall is blocking this")
		// than a generic "Server error (HTTP 502)".
		if ( ! is_wp_error( $api_response ) ) {
			$waf_error = $this->detect_firewall_intercept( $api_response );
			if ( is_wp_error( $waf_error ) ) {
				return $waf_error;
			}
		}

		$response_code = self::check_response_code( $api_response );

		// Null means a successful response, but does not return any body content (204). We can return early.
		if ( null === $response_code ) {
			return null;
		}

		if ( is_wp_error( $response_code ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->logging->log( 'Response code check failed: ' . print_r( $response_code, true ), __METHOD__, 'error' );

			return $response_code;
		}

		$response_body = wp_remote_retrieve_body( $api_response );
		$response_http = (int) wp_remote_retrieve_response_code( $api_response );

		if ( empty( $response_body ) ) {
			$this->logging->log(
				sprintf( 'Vendor response body was empty (HTTP %d).', $response_http ),
				__METHOD__,
				'error'
			);

			return new \WP_Error(
				'missing_response_body',
				esc_html__( 'Support access could not be set up. The plugin\'s support team\'s site returned nothing — a firewall on their side may have blocked the request. Please contact them and share this error.', 'trustedlogin' ),
				$api_response
			);
		}

		// HTML body check already ran at the top of this method before
		// check_response_code() had a chance to map the status — see
		// the early return above.

		$response_json = json_decode( $response_body, true );

		if ( empty( $response_json ) ) {
			$this->logging->log(
				sprintf(
					'Vendor response (HTTP %d) was not valid JSON. Body preview: %s',
					$response_http,
					mb_substr( (string) $response_body, 0, 200, 'UTF-8' )
				),
				__METHOD__,
				'error'
			);

			return new \WP_Error(
				'invalid_response',
				sprintf(
					/* translators: %d: the HTTP status code returned by the support team's site */
					esc_html__( 'Support access could not be set up — the plugin\'s support team\'s site returned an unexpected response (HTTP %d). Please contact them and share this error.', 'trustedlogin' ),
					$response_http
				),
				$api_response
			);
		}

		if ( isset( $response_json['errors'] ) ) {
			$errors = '';

			// Multi-dimensional; we flatten.
			foreach ( $response_json['errors'] as $key => $error ) {
				$error   = is_array( $error ) ? reset( $error ) : $error;
				$errors .= $error;
			}

			return new \WP_Error( 'errors_in_response', esc_html( $errors ), $response_body );
		}

		foreach ( (array) $required_keys as $required_key ) {
			if ( ! isset( $response_json[ $required_key ] ) || '' === $response_json[ $required_key ] ) {
				// The publicKey case has a specific customer-friendly surface: it
				// almost always means the vendor's TrustedLogin install doesn't
				// have encryption keys generated, or a security plugin on the
				// vendor site is stripping the response. Either way, the customer
				// can't fix it themselves — the vendor must.
				if ( 'publicKey' === $required_key ) {
					$this->logging->log(
						sprintf(
							'Vendor response (HTTP %d) did not include publicKey. Body: %s',
							$response_http,
							mb_substr( (string) $response_body, 0, 200, 'UTF-8' )
						),
						__METHOD__,
						'error'
					);

					return new \WP_Error(
						'missing_public_key',
						esc_html__( 'Support access could not be set up. The plugin\'s support team needs to finish configuring their end — please contact them and let them know.', 'trustedlogin' ),
						$response_body
					);
				}

				return new \WP_Error(
					'missing_required_key',
					esc_html__( 'Support access could not be set up. The plugin\'s support team\'s response was incomplete — please contact them and share this error.', 'trustedlogin' ),
					$response_body
				);
			}
		}

		return $response_json;
	}
}

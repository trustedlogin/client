<?php
/**
 * @package TrustedLogin
 *
 * @copyright 2026 TrustedLogin LLC
 */

namespace TrustedLogin;

/**
 * Reports a failed support-login attempt to the SaaS.
 *
 * Single-shot, opt-out-gated, fail-open. The customer-side
 * Endpoint::fail_login() delegates here; this class never blocks
 * a redirect or wp_die() from happening — on any error it returns
 * a WP_Error and the caller renders the standalone fallback page.
 *
 * Opt-out is per-namespace via TRUSTEDLOGIN_DISABLE_AUDIT_{NS}
 * (uppercase, matching the existing TRUSTEDLOGIN_DISABLE_{NS}
 * pattern). The team-level `enable_audit_log` toggle is NOT yet
 * plumbed through to the customer side — that's a deliberate
 * follow-up: SaaS POST /sites needs to expose the flag before
 * the client can ingest it.
 *
 * Spec: docs/superpowers/specs/2026-04-27-login-attempt-feedback-design.md
 */
final class LoginAttempts {

	const ENDPOINT_PATH = 'sites/%s/login-attempts';

	/**
	 * Hard timeout (seconds) on the SaaS POST. Sits on the critical
	 * path between fail_login() and the redirect — short to keep the
	 * agent UX snappy on a slow SaaS.
	 */
	const HTTP_TIMEOUT_SECONDS = 3;

	/** Max stored bytes for detailed_reason — matches SaaS validation. */
	const MAX_DETAILED_REASON_LENGTH = 4096;

	/** Max stored bytes for client_user_agent — matches SaaS validation. */
	const MAX_USER_AGENT_LENGTH = 512;

	/** SHA-256 produces exactly this many hex chars. */
	const IDENTIFIER_HASH_HEX_LENGTH = 64;

	/** Lower bound (inclusive) of the 2xx success range. */
	const HTTP_OK_MIN = 200;

	/** Upper bound (exclusive) of the 2xx success range. */
	const HTTP_OK_MAX = 300;

	/** SaaS returns this on validation failure — no retry. */
	const HTTP_UNPROCESSABLE = 422;

	/** SaaS returns this when rate-limit hit — fall through to standalone. */
	const HTTP_TOO_MANY = 429;

	/** Lower bound of upstream-error range. */
	const HTTP_SERVER_ERROR_MIN = 500;

	/** @var Config */
	private $config;

	/** @var Remote */
	private $remote;

	/** @var Logging */
	private $logging;

	public function __construct( Config $config, Remote $remote, Logging $logging ) {
		$this->config  = $config;
		$this->remote  = $remote;
		$this->logging = $logging;
	}

	/**
	 * @param array $payload See spec for shape; secret_id is REQUIRED in the
	 *                       payload and gets pulled out into the URL path.
	 *
	 * @return array|\WP_Error Array with 'id' on success; WP_Error otherwise.
	 */
	public function report( array $payload ) {
		if ( ! $this->is_audit_log_enabled() ) {
			return new \WP_Error( 'audit_log_disabled', 'Audit log is disabled for this namespace.' );
		}

		if ( empty( $payload['secret_id'] ) || ! is_string( $payload['secret_id'] ) ) {
			return new \WP_Error( 'missing_secret_id', 'secret_id is required.' );
		}

		$secret_id = $payload['secret_id'];
		unset( $payload['secret_id'] ); // Goes in the URL, not the body.

		$body = $this->validate_and_normalize( $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$path = sprintf( self::ENDPOINT_PATH, rawurlencode( $secret_id ) );

		// 3-second hard timeout matches the spec — the SaaS POST sits on
		// the critical path between fail_login() and the redirect, so we
		// trade reporting accuracy for agent-experience latency.
		$response = $this->remote->send( $path, $body, 'POST', array(), self::HTTP_TIMEOUT_SECONDS );

		if ( is_wp_error( $response ) ) {
			$this->logging->log( 'login-attempt POST network error: ' . $response->get_error_message(), __METHOD__, 'warning' );

			return new \WP_Error( 'saas_unreachable', 'SaaS unreachable: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( self::HTTP_TOO_MANY === $status ) {
			$this->logging->log( 'login-attempt POST rate-limited; falling through', __METHOD__, 'notice' );

			return new \WP_Error( 'saas_rate_limited', 'Rate limit hit on /login-attempts.' );
		}

		if ( self::HTTP_UNPROCESSABLE === $status ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->logging->log( 'login-attempt POST validation rejected: ' . wp_remote_retrieve_body( $response ), __METHOD__, 'error' );

			return new \WP_Error( 'saas_validation', 'SaaS rejected the payload.' );
		}

		if ( $status >= self::HTTP_SERVER_ERROR_MIN ) {
			$this->logging->log( 'login-attempt POST upstream ' . $status, __METHOD__, 'error' );

			return new \WP_Error( 'saas_5xx', 'SaaS returned ' . $status . '.' );
		}

		if ( $status < self::HTTP_OK_MIN || $status >= self::HTTP_OK_MAX ) {
			return new \WP_Error( 'saas_unexpected', 'Unexpected status ' . $status . '.' );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['id'] ) ) {
			return new \WP_Error( 'saas_malformed_response', 'SaaS response missing id.' );
		}

		return array( 'id' => (string) $decoded['id'] );
	}

	/**
	 * Default-ON. Hard-off via per-namespace constant
	 * `TRUSTEDLOGIN_DISABLE_AUDIT_{NS}` (uppercase). The team-level
	 * `enable_audit_log` toggle is NOT consulted in this version —
	 * see the class docblock.
	 *
	 * @return bool
	 */
	public function is_audit_log_enabled() {
		$constant = 'TRUSTEDLOGIN_DISABLE_AUDIT_' . strtoupper( $this->config->ns() );

		if ( defined( $constant ) && constant( $constant ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Walks the canonical proxy headers in priority order and returns
	 * the first valid IP. Returns null if no header yields a valid IP.
	 *
	 * Public so Endpoint::fail_login() can read the IP at call time.
	 *
	 * Note: this is the FIRST IP-resolution helper in the SDK. If a
	 * future audit surface needs the same, promote to Utils:: and
	 * delegate from here.
	 *
	 * @return string|null
	 */
	public function resolve_client_ip() {
		$candidates = array();

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// XFF is a comma-separated list with the originating client first.
			$xff_list     = explode( ',', wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidates[] = trim( (string) $xff_list[0] );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = wp_unslash( (string) $_SERVER['REMOTE_ADDR'] );
		}

		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @param array $payload
	 *
	 * @return array|\WP_Error Validated body for POST, or WP_Error.
	 */
	private function validate_and_normalize( array $payload ) {
		$required = array( 'code', 'client_site_url', 'attempted_at' );
		foreach ( $required as $key ) {
			if ( empty( $payload[ $key ] ) ) {
				return new \WP_Error( 'missing_field', 'Field "' . $key . '" is required.' );
			}
		}

		// detailed_reason is freeform but capped — matches SaaS-side
		// validation. Truncate rather than reject; the local log
		// already has the full text.
		if ( isset( $payload['detailed_reason'] ) && is_string( $payload['detailed_reason'] ) ) {
			$payload['detailed_reason'] = substr( $payload['detailed_reason'], 0, self::MAX_DETAILED_REASON_LENGTH );
		}

		// identifier_hash, when present, must be SHA-256 hex.
		if ( isset( $payload['identifier_hash'] ) ) {
			$valid = is_string( $payload['identifier_hash'] )
				&& preg_match( '/^[a-f0-9]{' . self::IDENTIFIER_HASH_HEX_LENGTH . '}$/', $payload['identifier_hash'] );
			if ( ! $valid ) {
				unset( $payload['identifier_hash'] );
			}
		}

		if ( isset( $payload['client_user_agent'] ) && is_string( $payload['client_user_agent'] ) ) {
			$payload['client_user_agent'] = substr( $payload['client_user_agent'], 0, self::MAX_USER_AGENT_LENGTH );
		}

		if ( isset( $payload['client_ip'] ) && ! filter_var( $payload['client_ip'], FILTER_VALIDATE_IP ) ) {
			unset( $payload['client_ip'] );
		}

		return $payload;
	}
}

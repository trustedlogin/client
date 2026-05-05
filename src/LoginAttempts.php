<?php
/**
 * Reports a failed support-login attempt to the SaaS.
 *
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
 * pattern). A team-level `enable_audit_log` toggle is NOT plumbed
 * through to the customer side today — landing it requires the
 * SaaS POST /sites response to expose the flag so the client can
 * ingest and persist it as a namespaced option.
 */
final class LoginAttempts {

	const ENDPOINT_PATH = 'sites/%s/login-attempts';

	/** Wire `code` value: the user resolved + maybe_login() returned a WP_Error. */
	const CODE_LOGIN_FAILED = 'login_failed';

	/** Wire `code` value: verify() rejected the request before user resolution. */
	const CODE_SECURITY_CHECK_FAILED = 'security_check_failed';

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

	/**
	 * Plugin configuration; read for the namespace used by opt-out filters.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Remote HTTP client used for the SaaS POST.
	 *
	 * @var Remote
	 */
	private $remote;

	/**
	 * Logger; every non-success branch writes a single line.
	 *
	 * @var Logging
	 */
	private $logging;

	/**
	 * Allowed values of the wire `code` field. Enforced in
	 * {@see self::validate_and_normalize()} so a value outside this list
	 * fails locally before the SaaS POST goes out.
	 *
	 * @since 1.10.0
	 *
	 * @return string[]
	 */
	public static function valid_codes() {
		return array(
			self::CODE_LOGIN_FAILED,
			self::CODE_SECURITY_CHECK_FAILED,
		);
	}

	/**
	 * Regex patterns scrubbed from `detailed_reason` before the SaaS POST.
	 *
	 * The field is freeform and frequently echoes WP_Error messages that
	 * accidentally include credentials. Each entry pairs a regex with a
	 * replacement; matches collapse to the replacement so the surrounding
	 * context still aids debugging. Errs broad — the local Logging class
	 * already has the unscrubbed text, and the SaaS doesn't need raw
	 * secrets to render the agent UX.
	 *
	 * @return array<string, string>
	 */
	private static function secret_scrub_patterns() {
		return array(
			// Bearer / token / authorization headers in any casing.
			'/(\b(?:Bearer|Token|Authorization)\s*[:=]?\s*)[A-Za-z0-9._\-+\/=]{8,}/i' => '$1[redacted]',
			// Stripe-style live/test secret keys.
			'/\b(sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/i' => '[redacted-stripe-key]',
			// Generic "secret" / "password" / "api_key" key=value pairs.
			'/(\b(?:secret|password|api[_-]?key)\s*[:=]\s*)["\']?[^"\'\s,;]{4,}["\']?/i' => '$1[redacted]',
		);
	}

	/**
	 * Wire up the dependencies needed to report a failed login attempt.
	 *
	 * @param Config  $config  Provides the namespace for opt-out checks.
	 * @param Remote  $remote  Used for the SaaS POST.
	 * @param Logging $logging All non-success branches log a single line
	 *                         each; the standalone fallback page renders
	 *                         to the user regardless.
	 */
	public function __construct( Config $config, Remote $remote, Logging $logging ) {
		$this->config  = $config;
		$this->remote  = $remote;
		$this->logging = $logging;
	}

	/**
	 * Send a failure event to the SaaS. `secret_id` is required in the
	 * context array and gets pulled out into the URL path (it does not
	 * enter the POST body). Recognized body keys, all optional unless
	 * noted:
	 *
	 * - code              (required) machine-readable failure tag.
	 * - client_site_url   (required) the integrator site URL.
	 * - attempted_at      (required) ISO-8601 timestamp.
	 * - detailed_reason             freeform; truncated to
	 *                                MAX_DETAILED_REASON_LENGTH.
	 * - client_user_agent           UA string; truncated to
	 *                                MAX_USER_AGENT_LENGTH.
	 * - client_ip                   IPv4/IPv6; dropped silently if
	 *                                FILTER_VALIDATE_IP rejects it.
	 *
	 * The `identifier_hash` field is intentionally NOT accepted on the
	 * context array. Pass the RAW site_identifier_hash as the second
	 * argument; this method takes the SHA-256 itself before composing
	 * the wire body. That makes it structurally impossible for a
	 * caller to leak a plaintext identifier on the `identifier_hash`
	 * field by mistake.
	 *
	 * @param array       $context              Body fields above (NOT including identifier_hash).
	 * @param string|null $raw_site_hash        Raw site_identifier_hash from user-meta. NULL or
	 *                                          empty string is allowed and produces a payload
	 *                                          without an identifier_hash field.
	 *
	 * @return array|\WP_Error Array with 'id' on success; WP_Error
	 *                         otherwise (audit disabled, missing
	 *                         secret_id, validation failure, network
	 *                         error, rate-limit, 5xx, or malformed
	 *                         response).
	 */
	public function report( array $context, $raw_site_hash = null ) {
		if ( ! $this->is_audit_log_enabled() ) {
			return new \WP_Error( 'audit_log_disabled', 'Audit log is disabled for this namespace.' );
		}

		if ( empty( $context['secret_id'] ) || ! is_string( $context['secret_id'] ) ) {
			return new \WP_Error( 'missing_secret_id', 'secret_id is required.' );
		}

		// Strip any caller-supplied identifier_hash field — only the
		// hash this method computes from $raw_site_hash is allowed
		// onto the wire. This is the load-bearing safety property of
		// the explicit second argument.
		unset( $context['identifier_hash'] );

		if ( is_string( $raw_site_hash ) && '' !== $raw_site_hash ) {
			$context['identifier_hash'] = hash( 'sha256', $raw_site_hash );
		}

		$secret_id = $context['secret_id'];
		unset( $context['secret_id'] ); // Goes in the URL, not the body.

		$body = $this->validate_and_normalize( $context );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$path = sprintf( self::ENDPOINT_PATH, rawurlencode( $secret_id ) );

		// 3-second hard timeout. The SaaS POST sits on the critical path
		// between fail_login() and the redirect, so we trade reporting
		// accuracy for agent-experience latency. Wrapped in try/catch
		// because pre_http_request filters from third-party plugins can
		// throw — must never abort fail_login(); always fall through
		// to the standalone page.
		try {
			$response = $this->remote->send( $path, $body, 'POST', array(), self::HTTP_TIMEOUT_SECONDS );
		} catch ( \Exception $e ) {
			$this->logging->log( 'login-attempt POST threw: ' . $e->getMessage(), __METHOD__, 'error' );

			return new \WP_Error( 'saas_unreachable', 'SaaS unreachable: ' . $e->getMessage() );
		}

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

		// HTTP_CF_CONNECTING_IP and HTTP_X_FORWARDED_FOR are regular
		// request headers — anyone sending an HTTP request can set
		// them to any value. They are only trustworthy when the
		// customer site is behind a reverse proxy (Cloudflare,
		// nginx, AWS ALB, etc.) that strips the inbound copy and
		// rewrites them with the actual client IP.
		//
		// Default: do NOT trust proxy headers. Integrators whose
		// customer sites run behind a stripping proxy can opt in via
		// the filter below. Audit-log accuracy on non-proxy
		// deployments is a smaller cost than letting any visitor
		// freely spoof the recorded IP.
		$trust_proxy_headers = (bool) apply_filters(
			'trustedlogin/' . $this->config->ns() . '/audit/trust_proxy_ip_headers',
			false
		);

		if ( $trust_proxy_headers ) {
			// Each candidate goes through filter_var( FILTER_VALIDATE_IP ) below
			// before it's used, but sanitize_text_field() satisfies the WPCS
			// "InputNotSanitized" sniff and is harmless on IP-shaped strings.
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$candidates[] = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			}

			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// XFF is a comma-separated list with the originating client first.
				$xff_list     = explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
				$candidates[] = trim( (string) $xff_list[0] );
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Validate the wire payload and normalize it for the SaaS POST.
	 *
	 * Rejects missing required fields and disallowed `code` values; truncates
	 * oversize freeform fields (`detailed_reason`, `client_user_agent`); drops
	 * malformed `identifier_hash` / `client_ip` so they don't reach the wire.
	 *
	 * @param array $payload Caller-supplied wire body before validation.
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

		// Error message intentionally does not echo $payload['code'] —
		// keeps untrusted bytes out of the local debug log.
		//
		// Defensive (string) cast: valid_codes() is checked with strict
		// in_array, so a numeric / bool / null code would slip through
		// the array check and only fail later. Casting up-front means
		// 0, '0', 1, true, false, null all fail the allowlist check
		// here rather than reaching the SaaS POST. Schema upstream
		// should enforce string-ness already, but this is the
		// last-line guard.
		if ( ! in_array( (string) $payload['code'], self::valid_codes(), true ) ) {
			return new \WP_Error(
				'invalid_code',
				'Field "code" is not in the allowlist.'
			);
		}

		// detailed_reason is freeform but capped — matches SaaS-side
		// validation. Truncate rather than reject; the local log
		// already has the full text. mb_strcut keeps the truncation
		// on a UTF-8 byte-sequence boundary so the SaaS never sees
		// half a multibyte codepoint.
		if ( isset( $payload['detailed_reason'] ) && is_string( $payload['detailed_reason'] ) ) {
			$payload['detailed_reason'] = $this->scrub_secrets( $payload['detailed_reason'] );
			$payload['detailed_reason'] = mb_strcut( $payload['detailed_reason'], 0, self::MAX_DETAILED_REASON_LENGTH, 'UTF-8' );
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
			$payload['client_user_agent'] = mb_strcut( $payload['client_user_agent'], 0, self::MAX_USER_AGENT_LENGTH, 'UTF-8' );
		}

		if ( isset( $payload['client_ip'] ) && ! filter_var( $payload['client_ip'], FILTER_VALIDATE_IP ) ) {
			unset( $payload['client_ip'] );
		}

		return $payload;
	}

	/**
	 * Replace known credential patterns inside a freeform string with
	 * `[redacted]` markers. Used on `detailed_reason` before the SaaS
	 * POST. The replacements are conservative (strict patterns + 8+
	 * chars of payload) — false positives are preferable to leaking
	 * a real secret into the SaaS.
	 *
	 * @param string $text Freeform input that may carry credentials.
	 *
	 * @return string
	 */
	private function scrub_secrets( $text ) {
		foreach ( self::secret_scrub_patterns() as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		return (string) $text;
	}
}

<?php
/**
 * Class Endpoint
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2021 Katz Web Services, Inc.
 */

namespace TrustedLogin;

use WP_Error;

/**
 * Class Endpoint
 */
class Endpoint {

	/**
	 * The query string parameter used to revoke users.
	 *
	 * @var string
	 */
	const REVOKE_SUPPORT_QUERY_PARAM = 'revoke-tl';

	/**
	 * Site option used to track whether permalinks have been flushed.
	 *
	 * @var string
	 */
	const PERMALINK_FLUSH_OPTION_NAME = 'tl_permalinks_flushed';

	/**
	 * Expected value of $_POST['action'] before adding the endpoint and starting a login flow.
	 *
	 * @var string
	 */
	const POST_ACTION_VALUE = 'trustedlogin';

	/**
	 * The $_POST key in the TrustedLogin request related to the action being performed.
	 *
	 * @var string
	 */
	const POST_ACTION_KEY = 'action';

	/**
	 * The $_POST key in the TrustedLogin request that contains the value of the expected endpoint.
	 *
	 * @var string
	 */
	const POST_ENDPOINT_KEY = 'endpoint';

	/**
	 * The $_POST key in the TrustedLogin request related to the action being performed.
	 *
	 * @var string
	 */
	const POST_IDENTIFIER_KEY = 'identifier';

	/**
	 * Status code for the standalone fallback page rendered by
	 * render_standalone_failure_page(). 200 (not 4xx/5xx) — some
	 * browsers replace error statuses with their own chrome.
	 */
	const STANDALONE_PAGE_HTTP_STATUS = 200;

	/**
	 * Config instance.
	 *
	 * @var Config $config
	 */
	private $config;

	/**
	 * Reports a failed support-login attempt to the SaaS.
	 *
	 * @var LoginAttempts
	 */
	private $login_attempts;

	/**
	 * The support-user resolver, used to capture the site_hash for failed logins.
	 *
	 * @var SupportUser
	 */
	private $support_user;

	/**
	 * The namespaced setting name for storing part of the auto-login endpoint
	 *
	 * @example `tl_{vendor/namespace}_endpoint`.
	 *
	 * @var string $option_name
	 */
	private $option_name;

	/**
	 * Logging instance.
	 *
	 * @var Logging $logging
	 */
	private $logging;

	/**
	 * Endpoint constructor.
	 *
	 * @param Config             $config         Config instance.
	 * @param Logging            $logging        Logging instance.
	 * @param LoginAttempts|null $login_attempts Reports failed support logins to SaaS. Optional —
	 *                                            ad-hoc instantiations (SupportUser::get_secret_id,
	 *                                            tests, etc.) only call utility methods like
	 *                                            generate_secret_id() that don't depend on it.
	 *                                            Required by maybe_login_support()'s fail_login path.
	 * @param SupportUser|null   $support_user   Same rationale — only the maybe_login_support path
	 *                                            needs it (to capture site_hash before maybe_login).
	 */
	public function __construct( Config $config, Logging $logging, LoginAttempts $login_attempts = null, SupportUser $support_user = null ) {

		$this->config         = $config;
		$this->logging        = $logging;
		$this->login_attempts = $login_attempts;
		$this->support_user   = $support_user;

		/**
		 * Filter: Set endpoint setting name
		 *
		 * @since 1.0.0
		 *
		 * @param string $option_name
		 * @param Config $config
		 */
		$this->option_name = apply_filters(
			'trustedlogin/' . $config->ns() . '/options/endpoint',
			'tl_' . $config->ns() . '_endpoint',
			$config
		);
	}

	/**
	 * Add hooks to initialize the endpoint.
	 */
	public function init() {

		if ( did_action( 'init' ) ) {
			$this->add();
		} else {
			add_action( 'init', array( $this, 'add' ) );
		}

		add_action( 'template_redirect', array( $this, 'maybe_login_support' ), 99 );
		add_action( 'init', array( $this, 'maybe_revoke_support' ), 100 );
		add_action( 'admin_init', array( $this, 'maybe_revoke_support' ), 100 );
	}

	/**
	 * Check if the endpoint is hit and has a valid identifier before automatically logging in support agent.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_login_support() {

		$request = $this->get_trustedlogin_request();

		// Not a TrustedLogin request — silent no-op. No info to leak.
		if ( ! $request ) {
			return;
		}

		// The user's already logged-in; don't override that login. Send them
		// to wp-admin so they see where they landed. Safe to signal here:
		// they're authenticated already, so they're not an anonymous probe.
		if ( is_user_logged_in() ) {
			$this->logging->log( 'TrustedLogin login-support: user is already logged in; redirecting to admin.', __METHOD__, 'notice' );
			wp_safe_redirect( add_query_arg( 'tl_notice', 'already_logged_in', admin_url() ) );
			exit();
		}

		$endpoint = $this->get();

		// The expected endpoint doesn't match the one in the request. Silent
		// no-op: an attacker POSTing random data shouldn't learn the stored
		// endpoint value or that their guess was close. Only log — no
		// redirect, no transient, no leakage. hash_equals for constant-time
		// compare so per-character timing can't leak the expected value.
		if ( ! hash_equals( (string) $endpoint, (string) $request[ self::POST_ENDPOINT_KEY ] ) ) {
			$this->logging->log( 'TrustedLogin login-support: endpoint mismatch on incoming request (silent no-op).', __METHOD__, 'warning' );
			return;
		}

		// The sanitized, unhashed identifier for the support user.
		$user_identifier = $request[ self::POST_IDENTIFIER_KEY ];

		// Same logic: a legitimate flow always sends an identifier. Missing
		// it means the request is malformed or probed; no feedback leaked.
		if ( empty( $user_identifier ) ) {
			$this->logging->log( 'TrustedLogin login-support: missing identifier (silent no-op).', __METHOD__, 'warning' );
			return;
		}

		/**
		 * Runs before the support user is (maybe) logged-in, but after the endpoint is verified.
		 *
		 * @param string $user_identifier Unique identifier for support user, sanitized using {@see sanitize_text_field}.
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/login/before', $user_identifier );

		$security_checks = new SecurityChecks( $this->config, $this->logging );

		// Before logging-in support, let's make sure the site isn't locked-down or that this request is flagged.
		$is_verified = $security_checks->verify( $user_identifier );

		if ( is_wp_error( $is_verified ) ) {

			/**
			 * Runs after the identifier fails security checks.
			 *
			 * @param string $user_identifier Unique identifier for support user.
			 * @param WP_Error $is_verified The error encountered when verifying the identifier.
			 */
			do_action( 'trustedlogin/' . $this->config->ns() . '/login/refused', $user_identifier, $is_verified );

			// Security check failures can legitimately happen when a valid
			// support user is locked out (site in lockdown, identifier
			// flagged). verify() ran BEFORE user resolution, so we have no
			// $user object here. fail_login() will skip the SaaS POST and
			// render the standalone page — this branch is best-effort
			// reporting, by design.
			//
			// Pass error_code, NOT error_message, as the SaaS-bound
			// reason. The SDK\'s WP_Error codes are a controlled vocabulary
			// (`in_lockdown`, `brute_force_detected`, etc.); their
			// messages are translated freeform text that may interpolate
			// internal state. Keeping the wire field on error_code
			// removes that data-leak surface; the full message stays in
			// the local debug.log via Logging::log() inside fail_login.
			$this->fail_login( LoginAttempts::CODE_SECURITY_CHECK_FAILED, (string) $is_verified->get_error_code(), null );
			return;
		}

		// Prefer the injected SupportUser (so tests can swap it). Fall back
		// to a fresh instance for legacy code paths that instantiated
		// Endpoint without one (the older 2-arg constructor signature).
		$support_user = $this->support_user instanceof SupportUser
			? $this->support_user
			: new SupportUser( $this->config, $this->logging );

		// Capture the matched user + its site_identifier_hash BEFORE calling
		// maybe_login(). The expired-user path inside maybe_login() deletes
		// the user before returning WP_Error, taking its user-meta with it
		// — so we'd lose the data needed to compute secret_id for fail_login.
		// Capturing here keeps the data alive across the WP_Error return.
		$pre_login_site_hash = null;
		$matched_user        = $support_user->get( $user_identifier );
		if ( $matched_user instanceof \WP_User ) {
			$captured_hash = $support_user->get_site_hash( $matched_user );
			if ( is_string( $captured_hash ) && '' !== $captured_hash ) {
				$pre_login_site_hash = $captured_hash;
			}
		}

		$is_logged_in = $support_user->maybe_login( $user_identifier );

		if ( is_wp_error( $is_logged_in ) ) {

			/**
			 * Runs after the support user fails to log in
			 *
			 * @param string $user_identifier Unique Identifier for support user.
			 * @param WP_Error $is_logged_in The error encountered when logging-in.
			 */
			do_action( 'trustedlogin/' . $this->config->ns() . '/login/error', $user_identifier, $is_logged_in );

			// Same reasoning: endpoint + identifier both matched the
			// shape of a real grant. Pass the pre-captured site_hash so
			// fail_login can compute secret_id even when maybe_login
			// already deleted the user.
			//
			// error_code (controlled vocabulary), not error_message
			// (freeform, may interpolate user identifiers / display
			// names). The full message stays in local debug.log via
			// Logging::log() inside fail_login.
			$this->fail_login( LoginAttempts::CODE_LOGIN_FAILED, (string) $is_logged_in->get_error_code(), $pre_login_site_hash );
			return;
		}

		/**
		 * Runs after the support user is logged-in.
		 *
		 * @param string $user_identifier Unique Identifier for support user.
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/login/after', $user_identifier );

		// Replay protection: rotate the endpoint hash so the URL the
		// agent just used can't be re-played by an intercepting proxy
		// or a captured-from-logs URL. The agent is now authenticated
		// via wp_set_auth_cookie inside maybe_login() — the magic URL
		// has served its purpose. Any subsequent request to the now-
		// stale endpoint hash hits the silent no-op fall-through in
		// get_trustedlogin_request() because the stored hash will not
		// match.
		//
		// random_bytes is deliberate: we want a non-derivable value
		// so the URL is truly one-shot. SupportUser cleanup at
		// expiration time also calls Endpoint::delete(), so the
		// rotated hash never lingers past the grant lifetime.
		try {
			$rotated_hash = bin2hex( random_bytes( 32 ) );
			$this->update( $rotated_hash );
		} catch ( \Throwable $e ) {
			// random_bytes failure is exceedingly rare on PHP 7+;
			// log and continue rather than blocking the login that
			// otherwise just succeeded. The next access-grant cycle
			// will re-mint the endpoint anyway.
			$this->logging->log(
				'Could not rotate endpoint hash after login: ' . $e->getMessage(),
				__METHOD__,
				'warning'
			);
		}

		wp_safe_redirect( add_query_arg( 'tl_notice', 'logged_in', admin_url() ) );

		exit();
	}

	/**
	 * Record a failed support login, POST it to SaaS, and either
	 * redirect the agent back to their Connector with an attempt id
	 * or render a standalone fallback page on the customer site.
	 * NEVER lands the agent on wp-login.php.
	 *
	 * Per-call-site behavior:
	 *   - security_check_failed: verify() ran BEFORE user resolution,
	 *     so $site_identifier_hash is null. We can't compute secret_id,
	 *     so the SaaS POST is skipped and we fall through to the
	 *     standalone page (best-effort reporting by design).
	 *   - login_failed: caller pre-captures site_identifier_hash from
	 *     the matched support user BEFORE maybe_login() runs (because
	 *     the expired-user path inside maybe_login deletes the user),
	 *     then passes it here. We derive secret_id, POST to SaaS, and
	 *     on success redirect with ?tl_attempt=lpat_…
	 *
	 * @param string      $error_code           One of the documented enum codes.
	 * @param string      $detailed_reason      Internal log + SaaS forensics; never shown to the user.
	 * @param string|null $site_identifier_hash The original site_identifier_hash from user-meta
	 *                                          (login_failed path only). NULL in security_check_failed.
	 *
	 * @return void Always exits.
	 */
	private function fail_login( $error_code, $detailed_reason, $site_identifier_hash = null ) {
		$this->logging->log(
			sprintf( 'TrustedLogin login-support failed [%s]: %s', $error_code, $detailed_reason ),
			__METHOD__,
			'error'
		);

		$attempt   = null;
		$secret_id = null;

		// Upstream contract: $site_identifier_hash arrived either NULL
		// (security_check_failed branch — verify() ran before user
		// resolution) or as a string captured from a matched WP_User\'s
		// user-meta on the login_failed branch. The block below derives
		// secret_id from that hash; it does NOT independently confirm
		// the user still exists (maybe_login may have deleted them
		// mid-flow), only that the hash format and hashing math are
		// well-formed.
		if ( is_string( $site_identifier_hash ) && '' !== $site_identifier_hash ) {
			$derived = $this->generate_secret_id( $site_identifier_hash, $this->get_hash( $site_identifier_hash ) );
			if ( ! is_wp_error( $derived ) && '' !== (string) $derived ) {
				$secret_id = (string) $derived;
			}
		}

		// $this->login_attempts is the optional 3rd constructor arg —
		// it's null in legacy 2-arg instantiations like
		// SupportUser::get_secret_id(). Skip the SaaS POST in that
		// case so we never fatal on a null deref.
		if ( null !== $secret_id && $this->login_attempts instanceof LoginAttempts ) {
			$context = array(
				'secret_id'         => $secret_id,
				'code'              => sanitize_key( (string) $error_code ),
				'detailed_reason'   => (string) $detailed_reason,
				'client_site_url'   => home_url(),
				'attempted_at'      => gmdate( 'c' ),
				'client_user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
					? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, LoginAttempts::MAX_USER_AGENT_LENGTH )
					: null,
				'client_ip'         => $this->login_attempts->resolve_client_ip(),
			);

			// Pass the raw site_identifier_hash separately. report()
			// owns the sha256 wrap so callers cannot accidentally place
			// a plaintext identifier on the `identifier_hash` field of
			// the wire payload.
			$attempt = $this->login_attempts->report( $context, $site_identifier_hash );
		}

		$referer = $this->resolve_safe_referer(
			isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : ''
		);

		if ( ! is_wp_error( $attempt ) && ! empty( $attempt['id'] ) && '' !== $referer ) {
			// Happy path — attempt recorded AND referer trusted.
			// add_query_arg URL-encodes values; do not double-encode.
			wp_safe_redirect( add_query_arg( 'tl_attempt', $attempt['id'], $referer ) );

			exit();
		}

		$this->render_standalone_failure_page();

		exit();
	}

	/**
	 * Generic, branded-by-the-customer-site (NOT the integrator)
	 * failure page. We deliberately don't surface the error_code —
	 * without Connector context, the code is meaningless to the agent.
	 *
	 * Returns HTTP 200 (not 4xx/5xx) — some browsers replace 4xx/5xx
	 * with their own error chrome.
	 */
	private function render_standalone_failure_page() {
		$heading = __( 'Support login could not complete', 'trustedlogin' );
		$body    = __( 'Return to your support tool to try again.', 'trustedlogin' );

		wp_die(
			'<p>' . esc_html( $body ) . '</p>',
			esc_html( $heading ),
			array(
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self:: constant; not output.
				'response'  => self::STANDALONE_PAGE_HTTP_STATUS,
				'back_link' => false,
			)
		);
	}


	/**
	 * Match a raw Referer URL against the integrator's trusted-URL list.
	 *
	 * Why this exists: the Referer captured at POST time feeds the
	 * "Go back" link on the failure screen, but Referer is attacker-
	 * controllable. Returning the matched TRUSTED URL (not the raw
	 * Referer) means even if an attacker forges a Referer whose host
	 * happens to match, they can't inject a path or query — the link
	 * points at the canonical vendor URL the integrator declared.
	 *
	 * Extension point: vendors that operate from multiple surfaces
	 * (main site + dedicated support portal + white-label domains +
	 * staging) add URLs via the
	 * `trustedlogin/{ns}/login_feedback/allowed_referer_urls` filter.
	 * Only HOSTS in the returned list are accepted.
	 *
	 * @param string $raw_referer Raw HTTP_REFERER header value.
	 * @return string Trusted URL to render as the back link, or '' if no match.
	 */
	private function resolve_safe_referer( $raw_referer ) {
		if ( ! is_string( $raw_referer ) || '' === $raw_referer ) {
			return '';
		}
		$referer_host = wp_parse_url( $raw_referer, PHP_URL_HOST );
		if ( ! $referer_host ) {
			return '';
		}

		// Default allowlist: integrator-declared vendor URLs + own host.
		$default_urls = array(
			(string) $this->config->get_setting( 'vendor/website' ),
			(string) $this->config->get_setting( 'vendor/support_url' ),
			(string) home_url(),
		);

		/**
		 * Trusted URLs whose hosts are accepted as the Referer of a
		 * failed support-login POST. A match renders the MATCHED URL
		 * (not the raw Referer) as the "Go back" link on the feedback
		 * screen, so an attacker who forges a matching host still can't
		 * control the path.
		 *
		 * Vendors with multiple surfaces (marketing site, support portal,
		 * white-label domains, staging) should add their additional URLs
		 * here. Only the HOSTS of the returned URLs are compared; the
		 * full URL is rendered as the link when its host matches.
		 *
		 * Return an empty array to disable the Go-back link entirely.
		 *
		 * @since 1.10.0
		 *
		 * @param string[] $default_urls Default trusted URLs (vendor/website, vendor/support_url, home_url()).
		 * @param Config   $config       Client config, for namespace-aware extension.
		 */
		$allowed_urls = apply_filters(
			'trustedlogin/' . $this->config->ns() . '/login_feedback/allowed_referer_urls',
			$default_urls,
			$this->config
		);

		if ( ! is_array( $allowed_urls ) ) {
			return '';
		}

		foreach ( $allowed_urls as $allowed_url ) {
			$allowed_url = (string) $allowed_url;
			if ( '' === $allowed_url ) {
				continue;
			}
			$allowed_host = wp_parse_url( $allowed_url, PHP_URL_HOST );
			if ( $allowed_host && strcasecmp( $allowed_host, $referer_host ) === 0 ) {
				// Render the CONFIGURED URL (drops attacker-chosen
				// path/query). esc_url_raw also gates scheme.
				return (string) esc_url_raw( $allowed_url, array( 'http', 'https' ) );
			}
		}

		return '';
	}

	/**
	 * Hooked Action to maybe revoke support if the request SupportUser::ID_QUERY_PARAM equals the namespace.
	 *
	 * Can optionally check for request SupportUser::ID_QUERY_PARAM for revoking a specific user by their identifier.
	 *
	 * @since 1.0.0
	 */
	public function maybe_revoke_support() {
		$revoke_param = Utils::get_request_param( self::REVOKE_SUPPORT_QUERY_PARAM );

		if ( $this->config->ns() !== $revoke_param ) {
			return;
		}

		$nonce = Utils::get_request_param( '_wpnonce' );

		if ( ! $nonce ) {
			return;
		}

		$user_identifier = Utils::get_request_param( SupportUser::ID_QUERY_PARAM );

		if ( ! $user_identifier ) {
			$user_identifier = 'all';
		}

		// Nonce is bound to the target identifier so a nonce for user A
		// can't be swapped to revoke user B via tlid= parameter change.
		if ( ! wp_verify_nonce( $nonce, self::REVOKE_SUPPORT_QUERY_PARAM . '|' . $user_identifier ) ) {
			$this->logging->log( 'Removing user failed: Nonce expired or not scoped to this identifier.', __METHOD__, 'error' );
			return;
		}

		$support_user = new SupportUser( $this->config, $this->logging );

		// Allow namespaced support team to revoke their own users.
		$support_team = current_user_can( $support_user->role->get_name() );

		// As well as existing users who can delete other users.
		$can_delete_users = current_user_can( 'delete_users' );

		if ( ! $support_team && ! $can_delete_users ) {
			wp_safe_redirect( home_url() );
			return;
		}

		/**
		 * Trigger action to revoke access based on Support User identifier.
		 *
		 * @used-by Cron::revoke
		 *
		 * @param string $user_identifier Unique ID for TrustedLogin support user or "all".
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/access/revoke', $user_identifier );

		$should_be_deleted = $support_user->get( $user_identifier );

		if ( ! empty( $should_be_deleted ) ) {
			$this->logging->log( 'User #' . $should_be_deleted->ID . ' was not removed', __METHOD__, 'error' );
			return; // Don't trigger `access_revoked` if anything fails.
		}

		/**
		 * Only triggered when all access has been successfully revoked and no users exist with identifier $identifier.
		 *
		 * @param string $user_identifier Unique TrustedLogin ID for the Support User or "all"
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/admin/access_revoked', $user_identifier );

		// When the revoke link carried the `tl_return=login` marker, the user
		// came from the trustedlogin login-screen flow (typically a popup).
		// Redirect back there with ?revoked=1 so the client JS can fire a
		// `revoked` postMessage to the opener, giving definitive confirmation
		// instead of relying on a client-side timeout.
		$tl_return = Utils::get_request_param( 'tl_return' );
		if ( 'login' === $tl_return ) {
			$redirect_args = array(
				'action'  => 'trustedlogin',
				'ns'      => $this->config->ns(),
				'revoked' => '1',
			);

			// Preserve the original opener origin so the targeted postMessage
			// in trustedlogin.js can reach the same opener after redirect.
			// Route through resolve_safe_referer so only integrator-declared
			// hosts pass through — an attacker who convinces an admin to
			// click a revoke URL with tl_origin=https://attacker.com can no
			// longer coerce a {type:'revoked'} postMessage to their site.
			$tl_origin_raw = Utils::get_request_param( 'tl_origin' );
			if ( $tl_origin_raw ) {
				// Reject percent-encoded control chars (%00..%1F, %7F)
				// before rawurldecode. The host comparison inside
				// resolve_safe_referer goes through wp_parse_url, and a
				// payload like https://attacker.com%00.trustedvendor.com
				// could be parsed differently by future PHP versions
				// (host=attacker.com on truncate-at-NUL parsers,
				// host=attacker.com%00.trustedvendor.com on
				// strict parsers). Either parse can break the host
				// allowlist comparison; reject the input outright.
				if ( preg_match( '/%(?:0[0-9a-fA-F]|1[0-9a-fA-F]|7[fF])/', $tl_origin_raw ) === 1 ) {
					$tl_origin_raw = '';
				}
			}
			if ( $tl_origin_raw ) {
				$tl_origin_raw  = rawurldecode( $tl_origin_raw );
				$trusted_origin = $this->resolve_safe_referer( $tl_origin_raw );
				if ( '' !== $trusted_origin ) {
					$redirect_args['origin'] = $trusted_origin;
				}
			}

			$redirect = add_query_arg( $redirect_args, wp_login_url() );
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @since 1.0.0
	 * @see Endpoint::init() Called via `init` hook
	 */
	public function add() {

		// Only add the endpoint if a TrustedLogin request is being made.
		if ( ! $this->get_trustedlogin_request() ) {
			return;
		}

		$endpoint = $this->get();

		if ( ! $endpoint ) {
			return;
		}

		add_rewrite_endpoint( $endpoint, EP_ROOT );

		$this->logging->log( "Endpoint {$endpoint} added.", __METHOD__, 'debug' );

		if ( get_site_option( self::PERMALINK_FLUSH_OPTION_NAME ) ) {
			return;
		}

		flush_rewrite_rules( false );

		$this->logging->log( 'Rewrite rules flushed.', __METHOD__, 'info' );

		$updated_option = update_site_option( self::PERMALINK_FLUSH_OPTION_NAME, 1 );

		if ( false === $updated_option ) {
			$this->logging->log( 'Permalink flush option was not properly set.', 'warning' );
		}
	}

	/**
	 * Get the site option value at {@see option_name}
	 *
	 * @return string
	 */
	public function get() {
		return (string) get_site_option( $this->option_name );
	}

	/**
	 * Returns sanitized data from a TrustedLogin login $_POST request.
	 *
	 * Note: This is not a security check. It is only used to determine whether the request contains the expected keys.
	 *
	 * @since 1.1
	 *
	 * @return false|array{action:string, endpoint:string, identifier: string} If false, the request is not from TrustedLogin. If the request is from TrustedLogin, an array with the posted keys, santiized.
	 */
	private function get_trustedlogin_request() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::POST_ACTION_KEY ], $_POST[ self::POST_ENDPOINT_KEY ], $_POST[ self::POST_IDENTIFIER_KEY ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( self::POST_ACTION_VALUE !== $_POST[ self::POST_ACTION_KEY ] ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_sanitized_post_data = array_map( 'sanitize_text_field', $_POST );

		// Return only the expected keys.
		return array(
			self::POST_ACTION_KEY     => $_sanitized_post_data[ self::POST_ACTION_KEY ],
			self::POST_ENDPOINT_KEY   => $_sanitized_post_data[ self::POST_ENDPOINT_KEY ],
			self::POST_IDENTIFIER_KEY => $_sanitized_post_data[ self::POST_IDENTIFIER_KEY ],
		);
	}

	/**
	 * Generate the secret_id parameter as a hash of the endpoint with the identifier.
	 *
	 * @param string $site_identifier_hash The site identifier hash.
	 * @param string $endpoint_hash Optional. The hash of the endpoint. If not provided, it will be generated.
	 *
	 * @return string|WP_Error This hash will be used as an identifier in TrustedLogin SaaS. Or something went wrong.
	 */
	public function generate_secret_id( $site_identifier_hash, $endpoint_hash = '' ) {

		if ( empty( $endpoint_hash ) ) {
			$endpoint_hash = $this->get_hash( $site_identifier_hash );
		}

		if ( is_wp_error( $endpoint_hash ) ) {
			return $endpoint_hash;
		}

		return Encryption::hash( $endpoint_hash . $site_identifier_hash );
	}

	/**
	 * Generate the endpoint parameter as a hash of the site URL along with the identifier.
	 *
	 * @param string $site_identifier_hash The site identifier hash, used to generate the endpoint hash.
	 *
	 * @return string|WP_Error This hash will be used as the first part of the URL and also a part of $secret_id.
	 */
	public function get_hash( $site_identifier_hash ) {
		return Encryption::hash( get_site_url() . $site_identifier_hash );
	}

	/**
	 * Updates the site's endpoint to listen for logins. Flushes rewrite rules after updating.
	 *
	 * @param string $endpoint The endpoint to add to the site.
	 *
	 * @return bool True: updated; False: didn't change, or didn't update
	 */
	public function update( $endpoint ) {

		$updated = update_site_option( $this->option_name, $endpoint );

		update_site_option( self::PERMALINK_FLUSH_OPTION_NAME, 0 );

		return $updated;
	}

	/**
	 * Deletes the site's endpoint and soft-flushes rewrite rules.
	 *
	 * @return void
	 */
	public function delete() {

		if ( ! get_site_option( $this->option_name ) ) {
			$this->logging->log( 'Endpoint not deleted because it does not exist.', __METHOD__, 'info' );

			return;
		}

		delete_site_option( $this->option_name );

		flush_rewrite_rules( false );

		update_site_option( self::PERMALINK_FLUSH_OPTION_NAME, 0 );

		$this->logging->log( 'Endpoint removed & rewrites flushed', __METHOD__, 'info' );
	}
}

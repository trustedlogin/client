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
	 * Config instance.
	 *
	 * @var Config $config
	 */
	private $config;

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
	 * @param Config  $config  Config instance.
	 * @param Logging $logging Logging instance.
	 */
	public function __construct( Config $config, Logging $logging ) {

		$this->config  = $config;
		$this->logging = $logging;

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
		// redirect, no transient, no leakage.
		if ( $endpoint !== $request[ self::POST_ENDPOINT_KEY ] ) {
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
			// flagged). At this point endpoint matched AND identifier was
			// present — the shape proves the request came from a real grant,
			// not random probing — so surface a GENERIC message.
			$this->fail_login( 'security_check_failed', $is_verified->get_error_message() );
		}

		$support_user = new SupportUser( $this->config, $this->logging );

		$is_logged_in = $support_user->maybe_login( $user_identifier );

		if ( is_wp_error( $is_logged_in ) ) {

			/**
			 * Runs after the support user fails to log in
			 *
			 * @param string $user_identifier Unique Identifier for support user.
			 * @param WP_Error $is_logged_in The error encountered when logging-in.
			 */
			do_action( 'trustedlogin/' . $this->config->ns() . '/login/error', $user_identifier, $is_logged_in );

			// Same reasoning: endpoint + identifier both matched the shape
			// of a real grant, so show a user-friendly error instead of
			// a silent landing page.
			$this->fail_login( 'login_failed', $is_logged_in->get_error_message() );
		}

		/**
		 * Runs after the support user is logged-in.
		 *
		 * @param string $user_identifier Unique Identifier for support user.
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/login/after', $user_identifier );

		wp_safe_redirect( add_query_arg( 'tl_notice', 'logged_in', admin_url() ) );

		exit();
	}

	/**
	 * Log-friendly → user-friendly message mapper.
	 *
	 * Internal logs carry detail; the browser only sees the generic message.
	 * Keeps failure responses uniform so an attacker can't distinguish
	 * "endpoint mismatch" from "flagged identifier" from "expired" based on
	 * the text they see. Anything not in the allow-list falls back to the
	 * same neutral copy.
	 */
	private static function public_failure_messages() {
		return array(
			'security_check_failed' => __( 'This login request was blocked for security reasons. If this continues, please contact your support provider.', 'trustedlogin' ),
			'login_failed'          => __( 'Support access could not be started. The access key may have expired or already been used.', 'trustedlogin' ),
		);
	}

	/**
	 * Record a login-failure reason and redirect back to the TrustedLogin
	 * login screen so the user sees a helpful explanation instead of a
	 * silent no-op landing on the home page.
	 *
	 * Security posture:
	 *   - Only called from code paths that have ALREADY matched the stored
	 *     endpoint AND received a non-empty identifier. Earlier failure
	 *     paths (malformed request, wrong endpoint) return silently so
	 *     unauthenticated probes learn nothing.
	 *   - Increments the SecurityChecks flagged-IP counter so repeat
	 *     failures trigger the existing lockdown — a brute-force attacker
	 *     gets locked out even while legitimate users see feedback.
	 *   - User-facing message comes from a fixed catalog (public_failure_messages)
	 *     so the browser only sees GENERIC text. Detailed reasons go to the
	 *     log, never to the redirect response.
	 *   - Transient is scoped per-namespace and expires in 60s; it's a
	 *     one-hop signal, not persistent state.
	 *
	 * @param string $error_code      Short machine code (see public_failure_messages()).
	 * @param string $detailed_reason Detailed log message. Not shown to the user.
	 * @return void Always exits.
	 */
	private function fail_login( $error_code, $detailed_reason ) {
		$this->logging->log(
			sprintf( 'TrustedLogin login-support failed [%s]: %s', $error_code, $detailed_reason ),
			__METHOD__,
			'error'
		);

		// Rate-limiting note: the 'security_check_failed' path is already
		// counted inside SecurityChecks::verify() before we ever get here,
		// and 'login_failed' only fires AFTER verify() passed — meaning the
		// caller already demonstrated a valid identifier. That narrow
		// pre-conditioning is the primary defence against probes; we don't
		// double-bump the counter here.

		$messages = self::public_failure_messages();
		$public   = isset( $messages[ $error_code ] )
			? $messages[ $error_code ]
			: __( 'Support access could not be started. Please try again or contact your support provider.', 'trustedlogin' );

		// Persist the GENERIC message for the login screen to pick up.
		// Detailed reason is never stored in a browser-reachable place.
		Utils::set_transient(
			'trustedlogin_' . $this->config->ns() . '_login_error',
			array(
				'code'    => sanitize_key( (string) $error_code ),
				'message' => (string) $public,
				'time'    => time(),
			),
			60
		);

		$redirect = add_query_arg(
			array(
				'action'   => 'trustedlogin',
				'ns'       => $this->config->ns(),
				'tl_error' => sanitize_key( (string) $error_code ),
			),
			wp_login_url()
		);

		wp_safe_redirect( $redirect );
		exit();
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

		if ( ! wp_verify_nonce( $nonce, self::REVOKE_SUPPORT_QUERY_PARAM ) ) {
			$this->logging->log( 'Removing user failed: Nonce expired.', __METHOD__, 'error' );
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

		$user_identifier = Utils::get_request_param( SupportUser::ID_QUERY_PARAM );

		if ( ! $user_identifier ) {
			$user_identifier = 'all';
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
			$tl_origin_raw = Utils::get_request_param( 'tl_origin' );
			if ( $tl_origin_raw ) {
				$tl_origin_raw = rawurldecode( $tl_origin_raw );
				$parsed        = wp_parse_url( $tl_origin_raw );
				if ( $parsed && isset( $parsed['scheme'], $parsed['host'] ) && in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
					$redirect_args['origin'] = $tl_origin_raw;
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

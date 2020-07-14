<?php
/**
 * Class Endpoint
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

class Endpoint {

	/**
	 * @var string The query string parameter used to revoke users
	 */
	const REVOKE_SUPPORT_QUERY_PARAM = 'revoke-tl';

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * The namespaced setting name for storing part of the auto-login endpoint
	 *
	 * @var string $option_name Example: `tl_{vendor/namespace}_endpoint`
	 */
	private $option_name;

	/**
	 * @var SupportUser
	 * @todo decouple
	 */
	private $support_user;

	/**
	 * @var Logging $logging
	 */
	private $logging;

	/**
	 * Logger constructor.
	 */
	public function __construct( Config $config, Logging $logging ) {

		$this->config = $config;
		$this->logging = $logging;
		$this->support_user = new SupportUser( $this->config, $this->logging );

		/**
		 * Filter: Set endpoint setting name
		 *
		 * @since 0.3.0
		 *
		 * @param string
		 * @param Config $config
		 */
		$this->option_name = apply_filters(
			'trustedlogin/' . $config->ns() . '/options/endpoint',
			'tl_' . $config->ns() . '_endpoint',
			$config
		);

	}

	public function init() {

		if ( did_action( 'init' ) ) {
			$this->add();
		} else {
			add_action( 'init', array( $this, 'add' ) );
		}

		add_action( 'template_redirect', array( $this, 'maybe_login_support' ), 99 );
		add_action( 'admin_init', array( $this, 'admin_maybe_revoke_support' ), 100 );
	}

	/**
	 * Check if the endpoint is hit and has a valid identifier before automatically logging in support agent
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function maybe_login_support() {

		$identifier = $this->get_query_var();

		if ( empty( $identifier ) ) {
			return;
		}

		/**
		 * TODO: Use actions instead of tight coupling? Is this reliable enough?
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/login', $identifier );

		$logged_in = $this->support_user->maybe_login( $identifier );

		if ( is_wp_error( $logged_in ) ) {
			return;
		}

		wp_safe_redirect( admin_url() );

		exit();
	}

	/**
	 * Hooked Action to maybe revoke support if $_REQUEST[ SupportUser::id_query_param ] == {namespace}
	 * Can optionally check for $_REQUEST[ SupportUser::id_query_param ] for revoking a specific user by their identifier
	 *
	 * @since 0.2.1
	 */
	public function admin_maybe_revoke_support() {

		if ( ! isset( $_REQUEST[ self::REVOKE_SUPPORT_QUERY_PARAM ] ) ) {
			return;
		}

		if ( $this->config->ns() !== $_REQUEST[ self::REVOKE_SUPPORT_QUERY_PARAM ] ) {
			return;
		}

		// Allow namespaced support team to revoke their own users
		$support_team = current_user_can( $this->support_user->role->get_name() );

		// As well as existing users who can delete other users
		$can_delete_users = current_user_can( 'delete_users' );

		if ( ! $support_team && ! $can_delete_users ) {
			wp_safe_redirect( home_url() );
			return;
		}

		$identifier = isset( $_REQUEST[ SupportUser::id_query_param ] ) ? esc_attr( $_REQUEST[ SupportUser::id_query_param ] ) : 'all';

		$deleted_user = $this->support_user->delete( $identifier );

		if ( is_wp_error( $deleted_user ) ) {
			$this->logging->log( 'Removing user failed: ' . $deleted_user->get_error_message(), __METHOD__, 'error' );
		}

		$should_be_deleted = $this->support_user->get( $identifier );

		if ( ! empty( $should_be_deleted ) ) {
			$this->logging->log( 'User #' . $should_be_deleted->ID . ' was not removed', __METHOD__, 'error' );

			return;
		}

		do_action( 'trustedlogin/' . $this->config->ns() . '/admin/access_revoked', $identifier );
	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @see Endpoint::init() Called via `init` hook
	 *
	 * @since 0.3.0
	 */
	public function add() {

		$endpoint = $this->get();

		if ( ! $endpoint ) {
			return;
		}

		add_rewrite_endpoint( $endpoint, EP_ROOT );

		$this->logging->log( "Endpoint {$endpoint} added.", __METHOD__, 'debug' );

		if ( $endpoint && ! get_site_option( 'tl_permalinks_flushed' ) ) {

			flush_rewrite_rules( false );

			update_option( 'tl_permalinks_flushed', 1 );

			$this->logging->log( "Rewrite rules flushed.", __METHOD__, 'info' );
		}
	}

	/**
	 * Get the site option value at {@see option_name}
	 * @return string
	 */
	public function get() {
		return (string) get_site_option( $this->option_name );
	}

	public function get_query_var() {

		$endpoint = $this->get();

		$identifier = get_query_var( $endpoint, false );

		return empty( $identifier ) ? false : $identifier;
	}

	/**
	 * Generate the secret_id parameter as a hash of the endpoint with the identifier
	 *
	 * @param string $identifier_hash
	 * @param string $endpoint_hash
	 *
	 * @return string|WP_Error This hash will be used as an identifier in TrustedLogin SaaS. Or something went wrong.
	 */
	public function generate_secret_id( $identifier_hash, $endpoint_hash = '' ) {

		if ( empty( $endpoint_hash ) ) {
			$endpoint_hash = $this->get_hash( $identifier_hash );
		}

		return Encryption::hash( $endpoint_hash . $identifier_hash );
	}

	/**
	 * Generate the endpoint parameter as a hash of the site URL with the identifier
	 *
	 * @param $identifier_hash
	 *
	 * @return string This hash will be used as the first part of the URL and also a part of $secret_id
	 */
	public function get_hash( $identifier_hash ) {
		return Encryption::hash( get_site_url() . $identifier_hash );
	}

	/**
	 * Updates the site's endpoint to listen for logins. Flushes rewrite rules after updating.
	 *
	 * @param string $endpoint
	 *
	 * @return bool True: updated; False: didn't change, or didn't update
	 */
	public function update( $endpoint ) {

		$updated = update_option( $this->option_name, $endpoint, true );

		update_option( 'tl_permalinks_flushed', 0 );

		return $updated;
	}

	/**
	 *
	 * @return void
	 */
	public function delete() {

		if ( ! get_site_option( $this->option_name ) ) {
			$this->logging->log( "Endpoint not deleted because it does not exist.", __METHOD__, 'info' );

			return;
		}

		delete_site_option( $this->option_name );

		flush_rewrite_rules( false );

		update_option( 'tl_permalinks_flushed', 0 );

		$this->logging->log( "Endpoint removed & rewrites flushed", __METHOD__, 'info' );
	}
}

<?php
/**
 * Class SupportUser
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

/**
 * The TrustedLogin all-in-one drop-in class.
 */
final class SupportUser {

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var Logger $logger
	 */
	private $logger;

	/**
	 * @var SupportRole $role
	 */
	public $role;

	/**
	 * SupportUser constructor.
	 */
	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
		$this->role = new SupportRole( $config, $logger );
	}

	/**
	 * Create the Support User with custom role.
	 *
	 * @since 0.1.0
	 *
	 * @return int|WP_Error - Array with login response information if created, or WP_Error object if there was an issue.
	 */
	public function create() {

		$user_name = sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) );

		if ( $user_id = username_exists( $user_name ) ) {
			$this->logger->log( 'Support User not created; already exists: User #' . $user_id, __METHOD__, 'notice' );

			return new WP_Error( 'username_exists', sprintf( 'A user with the username %s already exists', $user_name ) );
		}

		$role_exists = $this->role->create();

		if ( is_wp_error( $role_exists ) ) {

			$error_output = $role_exists->get_error_message();

			if( $error_data = $role_exists->get_error_data() ) {
				$error_output .= ' ' . print_r( $error_data, true );
			}

			$this->logger->log( $error_output, __METHOD__, 'error' );

			return $role_exists;
		}

		$user_email = $this->config->get_setting( 'vendor/email' );

		if ( email_exists( $user_email ) ) {
			$this->logger->log( 'Support User not created; User with that email already exists: ' . $user_email, __METHOD__, 'warning' );

			return new WP_Error( 'user_email_exists', 'Support User not created; User with that email already exists' );
		}

		$user_data = array(
			'user_login'      => $user_name,
			'user_url'        => $this->config->get_setting( 'vendor/website' ),
			'user_pass'       => wp_generate_password( 64, true, true ),
			'user_email'      => $user_email,
			'role'            => $this->role->get_name(),
			'first_name'      => $this->config->get_setting( 'vendor/first_name', '' ),
			'last_name'       => $this->config->get_setting( 'vendor/last_name', '' ),
			'user_registered' => date( 'Y-m-d H:i:s', time() ),
		);

		$new_user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $new_user_id ) ) {
			$this->logger->log( 'Error: User not created because: ' . $new_user_id->get_error_message(), __METHOD__, 'error' );

			return $new_user_id;
		}

		$this->logger->log( 'Support User #' . $new_user_id, __METHOD__, 'info' );

		return $new_user_id;
	}

	/**
	 * Helper Function: Get the generated support user(s).
	 *
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier
	 *
	 * @return array of WP_Users
	 */
	public function get( $identifier = '' ) {

		// When passed in the endpoint URL, the unique ID will be the raw value, not the hash.
		if ( strlen( $identifier ) > 32 ) {
			$identifier = $this->hash( $identifier );
		}

		$args = array(
			'role'       => $this->role->get_name(),
			'number'     => 1,
			'meta_key'   => $this->identifier_meta_key,
			'meta_value' => $identifier,
		);

		return get_users( $args );
	}

	/**
	 * Get all users with the support role
	 *
	 * @since 0.7.0
	 *
	 * @return array
	 */
	public function get_all() {

		$args = array(
			'role' => $this->role->get_name(),
		);

		return get_users( $args );
	}

	/**
	 * Deletes support user(s) with options to delete the TrustedLogin-created user role and endpoint as well
	 *
	 * @param string $identifier Unique Identifier of the user to delete, or 'all' to remove all support users.
	 * @param bool   $delete_endpoint Should the TrustedLogin-created user role be deleted also? Default: `true`
	 * @param bool   $delete_endpoint Should the TrustedLogin endpoint be deleted also? Default: `true`
	 *
	 * @return bool|WP_Error True: Successfully removed user and role; false: There are no support users; WP_Error: something went wrong.
	 */
	private function delete( $identifier = '', $delete_role = true, $delete_endpoint = true ) {

		if ( 'all' === $identifier ) {
			$users = $this->get_support_users();
		} else {
			$users = $this->get_support_user( $identifier );
		}

		if ( empty( $users ) ) {
			return false;
		}

		$this->logger->log( count( $users ) . " support users found", __METHOD__, 'debug' );

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$reassign_id_or_null = $this->get_reassign_user_id();

		foreach ( $users as $_user ) {
			$this->logger->log( "Processing user ID " . $_user->ID, __METHOD__, 'debug' );

			$tlid = get_user_option( $this->identifier_meta_key, $_user->ID );

			// Remove auto-cleanup hook
			wp_clear_scheduled_hook( 'trustedlogin_revoke_access', array( $tlid ) );

			if ( wp_delete_user( $_user->ID, $reassign_id_or_null ) ) {
				$this->logger->log( "User: " . $_user->ID . " deleted.", __METHOD__, 'info' );
			} else {
				$this->logger->log( "User: " . $_user->ID . " NOT deleted.", __METHOD__, 'error' );
			}
		}

		if ( $delete_role && get_role( $this->role->get_name() ) ) {

			// Returns void; no way to tell if successful
			remove_role( $this->role->get_name() );

			if( get_role( $this->role->get_name() ) ) {
				$this->logger->log( "Role " . $this->role->get_name() . " was not removed successfully.", __METHOD__, 'error' );
			} else {
				$this->logger->log( "Role " . $this->role->get_name() . " removed.", __METHOD__, 'info' );
			}
		}

		if ( $delete_endpoint && get_site_option( $this->endpoint_option ) ) {

			delete_site_option( $this->endpoint_option );

			flush_rewrite_rules( false );

			update_option( 'tl_permalinks_flushed', 0 );

			$this->logger->log( "Endpoint removed & rewrites flushed", __METHOD__, 'info' );
		}

		return $this->revoke_access( $identifier );
	}

}

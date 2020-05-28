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
	 * @var string The query parameter used to pass the unique user ID
	 */
	const id_query_param = 'tlid';

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var Logging $logging
	 */
	private $logging;

	/**
	 * @var SupportRole $role
	 */
	public $role;

	/**
	 * @var string $identifier_meta_key The namespaced setting name for storing the unique identifier hash in user meta
	 * @example tl_{vendor/namespace}_id
	 * @since 0.7.0
	 */
	private $identifier_meta_key;

	/**
	 * @var int $expires_meta_key The namespaced setting name for storing the timestamp the user expires
	 * @example tl_{vendor/namespace}_expires
	 * @since 0.7.0
	 */
	private $expires_meta_key;

	/**
	 * @var int $created_by_meta_key The ID of the user who created the TrustedLogin access
	 * @since 0.9.7
	 */
	private $created_by_meta_key;

	/**
	 * SupportUser constructor.
	 */
	public function __construct( Config $config, Logging $logging ) {
		$this->config = $config;
		$this->logging = $logging;
		$this->role = new SupportRole( $config, $logging );

		$this->identifier_meta_key = 'tl_' . $config->ns() . '_id';
		$this->expires_meta_key    = 'tl_' . $config->ns() . '_expires';
		$this->created_by_meta_key = 'tl_' . $config->ns() . '_created_by';
	}

	/**
	 * Allow accessing limited private properties with a magic method.
	 *
	 * @param string $name Name of property
	 *
	 * @return string|null Value of property, if defined. Otherwise, null.
	 */
	public function __get( $name ) {

		// Allow accessing limited private variables
		switch ( $name ) {
			case 'identifier_meta_key':
			case 'expires_meta_key':
			case 'created_by_meta_key':
				return $this->{$name};
				break;
		}

		return null;
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

		$user_id = username_exists( $user_name );

		if ( $user_id ) {
			$this->logging->log( 'Support User not created; already exists: User #' . $user_id, __METHOD__, 'notice' );

			return new WP_Error( 'username_exists', sprintf( 'A user with the username %s already exists', $user_name ) );
		}

		$role_exists = $this->role->create();

		if ( is_wp_error( $role_exists ) ) {

			$error_output = $role_exists->get_error_message();

			if( $error_data = $role_exists->get_error_data() ) {
				$error_output .= ' ' . print_r( $error_data, true );
			}

			$this->logging->log( $error_output, __METHOD__, 'error' );

			return $role_exists;
		}

		$user_email = $this->config->get_setting( 'vendor/email' );

		if ( email_exists( $user_email ) ) {
			$this->logging->log( 'Support User not created; User with that email already exists: ' . $user_email, __METHOD__, 'warning' );

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
			$this->logging->log( 'Error: User not created because: ' . $new_user_id->get_error_message(), __METHOD__, 'error' );

			return $new_user_id;
		}

		$this->logging->log( 'Support User #' . $new_user_id, __METHOD__, 'info' );

		return $new_user_id;
	}

	/**
	 * @param $identifier
	 *
	 * @return true|WP_Error
	 */
	public function maybe_login( $identifier ) {

		$support_user = $this->get( $identifier );

		if ( empty( $support_user ) ) {

			$this->logging->log( 'Support user not found at identifier ' . esc_attr( $identifier ), __METHOD__, 'notice' );

			return new WP_Error( 'user_not_found', 'Support user not found at itentifier ' . esc_attr( $identifier ) );
		}

		$expires = $this->get_expiration( $support_user );

		// This user has expired, but the cron didn't run...
		if ( $expires && time() > (int) $expires ) {

			$this->logging->log( 'The user was supposed to expire on ' . $expires . '; revoking now.', __METHOD__, 'warning' );

			$this->delete( $identifier, true );

			// TODO
			//$this->endpoint->delete();

			return new WP_Error( 'access_expired', 'The user was supposed to expire on ' . $expires . '; revoking now.' );
		}

		$this->login( $support_user );

		return true;
	}

	/**
	 * @param WP_User $support_user
	 */
	private function login( WP_User $support_user ) {

		if ( ! $support_user->exists() ) {
			return;
		}

		wp_set_current_user( $support_user->ID, $support_user->user_login );
		wp_set_auth_cookie( $support_user->ID );
		do_action( 'wp_login', $support_user->user_login, $support_user );
	}

	/**
	 * Helper Function: Get the generated support user(s).
	 *
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier
	 *
	 * @return WP_User|null WP_User if found; null if not
	 */
	public function get( $identifier = '' ) {

		if ( empty( $identifier ) ) {
			return null;
		}

		// When passed in the endpoint URL, the unique ID will be the raw value, not the hash.
		if ( strlen( $identifier ) > 32 ) {
			$identifier = Encryption::hash( $identifier );
		}

		$args = array(
			'role'       => $this->role->get_name(),
			'number'     => 1,
			'meta_key'   => $this->identifier_meta_key,
			'meta_value' => $identifier,
		);

		$user = get_users( $args );

		return empty( $user ) ? null : $user[0];
	}

	/**
	 * @param WP_User $user
	 *
	 * @return int|false Expiration timestamp; False if not set.
	 */
	public function get_expiration( WP_User $user ) {

		$expiration = get_user_option( $this->expires_meta_key, $support_user->ID );

		return $expiration ? $expiration : false;
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
	 * @param bool   $delete_role Should the TrustedLogin-created user role be deleted also? Default: `true`
	 *
	 * @return bool|WP_Error True: Successfully removed user and role; false: There are no support users; WP_Error: something went wrong.
	 */
	public function delete( $identifier = '', $delete_role = true ) {

		if ( 'all' === $identifier ) {
			$users = $this->get_all();
		} else {
			$user = $this->get( $identifier );
			$users = $user ? array( $user ) : null;
		}

		if ( empty( $users ) ) {
			return false;
		}

		$this->logging->log( count( $users ) . " support users found", __METHOD__, 'debug' );

		// Needed for wp_delete_user()
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$reassign_id_or_null = $this->get_reassign_user_id();

		foreach ( $users as $_user ) {
			$this->logging->log( "Processing user ID " . $_user->ID, __METHOD__, 'debug' );

			$identifier = $this->get_user_identifier( $_user );

			if ( ! $identifier || is_wp_error( $identifier ) ) {
				$this->logging->log( 'Identifier not found for: ' . $_user->ID . '; the user was NOT deleted.', __METHOD__, 'error' );
				continue;
			}

			// Remove auto-cleanup hook
			wp_clear_scheduled_hook( 'trustedlogin/' . $this->config->ns() . '/access/revoke', array( $identifier ) );

			$deleted = wp_delete_user( $_user->ID, $reassign_id_or_null );

			if ( $deleted ) {
				$this->logging->log( "User: " . $_user->ID . " deleted.", __METHOD__, 'info' );
			} else {
				$this->logging->log( "User: " . $_user->ID . " NOT deleted.", __METHOD__, 'error' );
			}
		}

		if( $delete_role ) {
			$this->role->delete();
		}

		return $this->delete( $identifier );
	}

	/**
	 * Get the ID of the best-guess appropriate admin user
	 *
	 * @since 0.7.0
	 *
	 * @return int|null User ID if there are admins, null if not
	 */
	private function get_reassign_user_id() {

		if( ! $this->config->get_setting( 'reassign_posts' ) ) {
			return null;
		}

		// TODO: Filter here?
		$admins = get_users( array(
			'role'    => 'administrator',
			'orderby' => 'registered',
			'order'   => 'DESC',
			'number'  => 1,
		) );

		$reassign_id = empty( $admins ) ? null : $admins[0]->ID;

		$this->logging->log( 'Reassign user ID: ' . var_export( $reassign_id, true ), __METHOD__, 'info' );

		return $reassign_id;
	}

	/**
	 * Schedules cron job to auto-revoke, adds user meta with unique ids
	 *
	 * @param int $user_id ID of generated support user
	 * @param string $identifier_hash Unique ID used by
	 * @param int $decay_timestamp Timestamp when user will be removed
	 *
	 * @return string|WP_Error Value of $identifier_meta_key if worked; empty string or WP_Error if not.
	 */
	public function setup( $user_id, $identifier_hash, $expiration_timestamp = null, Cron $cron = null ) {

		if ( $expiration_timestamp ) {

			$scheduled = $cron->schedule( $expiration_timestamp, $identifier_hash );

			if( $scheduled ) {
				update_user_option( $user_id, $this->expires_meta_key, $expiration_timestamp );
			}
		}

		$hash_of_identifier = Encryption::hash( $identifier_hash );

		if ( is_wp_error( $hash_of_identifier ) ) {
			return $hash_of_identifier;
		}

		update_user_option( $user_id, $this->identifier_meta_key, $hash_of_identifier, true );
		update_user_option( $user_id, $this->created_by_meta_key, get_current_user_id() );

		// Make extra sure that the identifier was saved. Otherwise, things won't work!
		return get_user_option( $this->identifier_meta_key, $user_id );
	}

	/**
	 * @param WP_User|int $user_id_or_object User ID or User object
	 *
	 * @return string|WP_Error User unique identifier if success; WP_Error if $user is not int or WP_User.
	 */
	public function get_user_identifier( $user_id_or_object ) {

		if ( empty( $this->identifier_meta_key ) ) {
			$this->logging->log( 'The meta key to identify users is not set.', __METHOD__, 'error' );

			return new WP_Error( 'missing_meta_key', 'The SupportUser object has not been properly instantiated.' );
		}

		if ( $user_id_or_object instanceof \WP_User ) {
			$user_id = $user_id_or_object->ID;
		} elseif ( is_int( $user_id_or_object ) ) {
			$user_id = $user_id_or_object;
		} else {

			$this->logging->log( 'The $user_id_or_object value must be int or WP_User: ' . var_export( $user_id_or_object, true ), __METHOD__, 'error' );

			return new WP_Error( 'invalid_type', '$user must be int or WP_User' );
		}

		return get_user_option( $this->identifier_meta_key, $user_id );
	}

	/**
	 * Returns admin URL to revoke support user
	 *
	 * @uses SupportUser::get_user_identifier()
	 *
	 * @param WP_User|int $user_id_or_object
	 *
	 * @return string|false Unsanitized URL to revoke support user. If not able to retrieve user identifier, returns false.
	 */
	public function get_revoke_url( $user_id_or_object ) {

		$identifier = $this->get_user_identifier( $user_id_or_object );

		if ( ! $identifier || is_wp_error( $identifier ) ) {
			return false;
		}

		$revoke_url = add_query_arg( array(
			Endpoint::revoke_support_query_param => $this->config->ns(),
			self::id_query_param  => $identifier,
		), admin_url( 'users.php' ) );

		$this->logging->log( "revoke_url: $revoke_url", __METHOD__, 'debug' );

		return $revoke_url;
	}
}

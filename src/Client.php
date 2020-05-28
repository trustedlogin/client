<?php
/**
 * The TrustedLogin drop-in class. Include this file and instantiate the class and you have secure support.
 *
 * @version 0.9.2
 * @copyright 2020 Katz Web Services, Inc.
 *
 * ###                    ###
 * ###   HEY DEVELOPER!   ###
 * ###                    ###
 * ###  (read me first)   ###
 *
 * Thanks for integrating TrustedLogin.
 *
 * 0. If you haven't already, sign up for a TrustedLogin account {@see https://www.trustedlogin.com}
 * 1. Prefix the namespace below with your own namespace (`namespace \ReplaceThisExample\TrustedLogin;`)
 * 2. Instantiate this class with a configuration array ({@see https://www.trustedlogin.com/configuration/} for more info)
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
final class Client {

	/**
	 * @var string The current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.9.6';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var bool
	 */
	static $valid_config;

	/**
	 * @var null|Logging $logging
	 */
	private $logging;

	/**
	 * @var SupportUser $support_user
	 */
	private $support_user;

	/**
	 * @var Remote $remote
	 */
	private $remote;

	/**
	 * @var Cron $cron
	 */
	private $cron;

	/**
	 * @var Endpoint $endpoint
	 */
	private $endpoint;

	/**
	 * @var Admin $admin
	 */
	private $admin;

	/**
	 * @var Ajax
	 */
	private $ajax;

	/**
	 * @var SiteAccess $site_access
	 */
	private $site_access;

	/**
	 * @var Encryption
	 */
	private $encryption;

	/**
	 * TrustedLogin constructor.
	 *
	 * @see https://docs.trustedlogin.com/ for more information
	 *
	 * @param Config $config
	 * @param bool $init Whether to initialize everything on instantiation
	 *
	 */
	public function __construct( Config $config, $init = true ) {

		try {
			self::$valid_config = $config->validate();
		} catch ( Exception $exception ) {
			self::$valid_config = false;
			return;
		}

		$this->config = $config;

		$this->logging = new Logging( $config );

		$this->endpoint = new Endpoint( $this->config, $this->logging );

		$this->cron = new Cron( $this->config, $this->logging );

		$this->support_user = new SupportUser( $this->config, $this->logging );

		$this->admin = new Admin( $this->config, $this->logging );

		$this->ajax = new Ajax( $this->config, $this->logging );

		$this->remote = new Remote( $this->config, $this->logging );

		$this->site_access = new SiteAccess( $this->config, $this->logging );

		$this->encryption = new Encryption( $this->config, $this->remote, $this->logging );

		if ( $init ) {
			$this->init();
		}
	}

	/**
	 * Initialize all the things!
	 *
	 */
	public function init() {
		$this->admin->init();
		$this->endpoint->init();
		$this->remote->init();
		$this->cron->init();
		$this->ajax->init();
	}

	/**
	 * This creates a TrustedLogin user ✨
	 *
	 * @return array|WP_Error
	 */
	public function grant_access() {

		if( ! self::$valid_config ) {
			return new WP_Error( 'invalid_configuration', 'TrustedLogin has not been properly configured or instantiated.', array( 'error_code' => 424 ) );
		}

		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'no_cap_create_users', 'Permissions issue: You do not have the ability to create users.', array( 'error_code' => 403 ) );
		}

		try {
			$support_user_id = $this->support_user->create();
		} catch ( Exception $exception ) {

			$this->logging->log( 'An exception occurred trying to create a support user.', __METHOD__, 'critical', $exception );

			return new WP_Error( 'support_user_exception', $exception->getMessage(), array( 'error_code' => 500 ) );
		}

		if ( is_wp_error( $support_user_id ) ) {

			$this->logging->log( sprintf( 'Support user not created: %s (%s)', $support_user_id->get_error_message(), $support_user_id->get_error_code() ), __METHOD__, 'error' );

			$support_user_id->add_data( array( 'error_code' => 409 ) );

			return $support_user_id;
		}

		$identifier_hash = $this->site_access->create_hash();

		if ( is_wp_error( $identifier_hash ) ) {

			$this->logging->log( 'Could not generate a secure secret.', __METHOD__, 'error' );

			return new WP_Error( 'secure_secret_failed', 'Could not generate a secure secret.', array( 'error_code' => 501 ) );
		}

		$endpoint_hash = $this->endpoint->get_hash( $identifier_hash );

		$updated = $this->endpoint->update( $endpoint_hash );

		if( ! $updated ) {
			$this->logging->log( 'Endpoint hash did not save or didn\'t update.', __METHOD__, 'info' );
		}

		$expiration_timestamp = $this->config->get_expiration_timestamp();

		// Add user meta, configure decay
		$did_setup = $this->support_user->setup( $support_user_id, $identifier_hash, $expiration_timestamp, $this->cron );

		if ( is_wp_error( $did_setup ) ) {

			$did_setup->add_data( array( 'error_code' => 503 ) );

			return $did_setup;
		}

		if ( empty( $did_setup ) ) {
			return new WP_Error( 'support_user_setup_failed', 'Error updating user with identifier.', array( 'error_code' => 503 ) );
		}

		$secret_id = $this->endpoint->generate_secret_id( $identifier_hash, $endpoint_hash );

		if ( is_wp_error( $secret_id ) ) {

			$did_setup->add_data( array( 'error_code' => 500 ) );

			return $secret_id;
		}

		$return_data = array(
			'site_url'    => get_site_url(),
			'endpoint'   => $endpoint_hash,
			'identifier' => $identifier_hash,
			'user_id'    => $support_user_id,
			'expiry'     => $expiration_timestamp,
			'access_key' => $secret_id,
			'is_ssl'     => is_ssl(),
		);

		if ( $this->config->meets_ssl_requirement() ) {

			try {

				$created = $this->site_access->create_secret( $secret_id, $identifier_hash );

			} catch ( Exception $e ) {

				$exception_error = new WP_Error( $e->getCode(), $e->getMessage(), array( 'status_code' => 500 ) );

				$this->logging->log( 'There was an error creating a secret.', __METHOD__, 'error', $e );

				return $exception_error;
			}

			if ( is_wp_error( $created ) ) {

				$this->logging->log( sprintf( 'There was an issue creating access (%s): %s', $created->get_error_code(), $created->get_error_message() ), __METHOD__, 'error' );

				$created->add_data( array( 'status_code' => 503 ) );

				return $created;
			}

		}

		do_action( 'trustedlogin/' . $this->config->ns() . '/access/created', array( 'url' => get_site_url(), 'action' => 'create' ) );

		return $return_data;
	}

}

<?php

namespace TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

final class Ajax {

	/**
	 * @var \TrustedLogin\Config
	 */
	private $config;

	/**
	 * @var null|\TrustedLogin\Logging $logging
	 */
	private $logging;

	/**
	 * Cron constructor.
	 *
	 * @param Config $config
	 * @param Logging|null $logging
	 */
	public function __construct( Config $config, Logging $logging ) {
		$this->config  = $config;
		$this->logging = $logging;
	}

	/**
	 *
	 */
	public function init() {
		add_action( 'wp_ajax_tl_' . $this->config->ns() . '_gen_support', array( $this, 'ajax_generate_support' ) );
	}

	/**
	 * AJAX handler for maybe generating a Support User
	 *
	 * @since 0.2.0
	 *
	 * @return void Sends a JSON success or error message based on what happens
	 */
	public function ajax_generate_support() {

		if ( empty( $_POST['vendor'] ) ) {

			$this->logging->log( 'Vendor not defined in TrustedLogin configuration.', __METHOD__, 'critical' );

			wp_send_json_error( array( 'message' => 'Vendor not defined in TrustedLogin configuration.' ) );
		}

		// There are multiple TrustedLogin instances, and this is not the one being called.
		// This should not occur, since the AJAX action is namespaced.
		if ( $this->config->ns() !== $_POST['vendor'] ) {

			$this->logging->log( 'Vendor does not match TrustedLogin configuration.', __METHOD__, 'critical' );

			wp_send_json_error( array( 'message' => 'Vendor does not match.' ) );
			return;
		}

		if ( empty( $_POST['_nonce'] ) ) {
			wp_send_json_error( array( 'message' => 'Nonce not sent in the request.' ) );
		}

		if ( ! check_ajax_referer( 'tl_nonce-' . get_current_user_id(), '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Verification issue: Request could not be verified. Please reload the page.' ) );
		}

		if ( ! current_user_can( 'create_users' ) ) {
			wp_send_json_error( array( 'message' => 'Permissions issue: You do not have the ability to create users.' ) );
		}

		$Endpoint = new Endpoint( $this->config, $this->logging );
		$SiteAccess = new SiteAccess( $this->config, $this->logging );
		$SupportUser = new SupportUser( $this->config, $this->logging );
		$Cron = new Cron( $this->config, $this->logging );

		try {
			$support_user_id = $SupportUser->create();
		} catch ( Exception $exception ) {

			$this->logging->log( 'An exception occured trying to create a support user.', __METHOD__, 'critical', $exception );

			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}

		if ( is_wp_error( $support_user_id ) ) {

			$this->logging->log( sprintf( 'Support user not created: %s (%s)', $support_user_id->get_error_message(), $support_user_id->get_error_code() ), __METHOD__, 'error' );

			wp_send_json_error( array( 'message' => $support_user_id->get_error_message() ), 409 );
		}

		$identifier_hash = $SiteAccess->create_hash();

		if ( is_wp_error( $identifier_hash ) ) {

			$this->logging->log( 'Could not generate a secure secret.', __METHOD__, 'error' );

			wp_send_json_error( array( 'message' => 'Could not generate a secure secret.' ), 501 );
		}

		$endpoint_hash = $Endpoint->get_hash( $identifier_hash );

		$Endpoint->update( $endpoint_hash );

		$expiration_timestamp = $this->config->get_expiration_timestamp();

		// Add user meta, configure decay
		$did_setup = $SupportUser->setup( $support_user_id, $identifier_hash, $expiration_timestamp, $Cron );

		if ( empty( $did_setup ) ) {
			wp_send_json_error( array( 'message' => 'Error updating user with identifier.' ), 503 );
		}

		$secret_id = $Endpoint->generate_secret_id( $identifier_hash, $endpoint_hash );

		$return_data = array(
			'site_url'    => get_site_url(),
			'endpoint'   => $endpoint_hash,
			'identifier' => $identifier_hash,
			'user_id'    => $support_user_id,
			'expiry'     => $expiration_timestamp,
			'access_key' => $secret_id,
			'is_ssl'     => is_ssl(),
		);

		if ( $this->config->meets_ssl_requirement() ){

			$created = false;

			try {

				$created = $SiteAccess->create_secret( $secret_id, $identifier_hash );

			} catch ( Exception $e ) {

				$exception_error = new WP_Error( $e->getCode(), $e->getMessage() );

				$this->logging->log( 'There was an error creating a secret.', __METHOD__, 'error', $e );

				wp_send_json_error( $exception_error, 500 );
			}

			if ( is_wp_error( $created ) ) {

				$this->logging->log( sprintf( 'There was an issue creating access (%s): %s', $created->get_error_code(), $created->get_error_message() ), __METHOD__, 'error' );

				wp_send_json_error( $created, 503 );

			}

		}

		do_action( 'trustedlogin/' . $this->config->ns() . '/access/created', array( 'url' => get_site_url(), 'action' => 'create' ) );

		wp_send_json_success( $return_data, 201 );

	}

}

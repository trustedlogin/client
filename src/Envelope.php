<?php
/**
 * Class Envelope
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
final class Envelope {

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var Encryption
	 */
	private $encryption;

	/**
	 * @var string Public key set in software (not Vendor-provided public key)
	 * @todo Rename to `api_key` again.
	 */
	private $public_key;

	/**
	 * Envelope constructor.
	 *
	 * @param string $public_key
	 * @param Encryption $encryption
	 */
	public function __construct( Config $config, Encryption $encryption ) {
		$this->config     = $config;
		$this->public_key = $this->config->get_setting( 'auth/public_key' );
		$this->encryption = $encryption;
	}

	/**
	 * @param string $secret_id
	 * @param string $identifier
	 * @param string $access_key
	 *
	 * @return array|WP_Error
	 */
	public function get( $secret_id, $identifier, $access_key = '' ) {

		if ( ! is_string( $secret_id ) ) {
			return new WP_Error( 'secret_not_string', 'The secret ID must be a string:' . print_r( $secret_id, true ) );
		}

		if ( ! is_string( $identifier ) ) {
			return new WP_Error( 'identifier_not_string', 'The identifier must be a string:' . print_r( $identifier, true ) );
		}

		if ( ! is_string( $access_key ) ) {
			return new WP_Error( 'access_key_not_string', 'The access key must be a string: ' . print_r( $access_key, true ) );
		}

		$e_keys = $this->encryption->generate_keys();

		if ( is_wp_error( $e_keys ) ){
			return $e_keys;
		}

		$nonce = $this->encryption->get_nonce();

		if ( is_wp_error( $nonce ) ){
			return $nonce;
		}

		$e_identifier = $this->encryption->encrypt( $identifier, $nonce, $e_keys->privateKey );

		if ( is_wp_error( $e_identifier ) ) {
			return $e_identifier;
		}

		$e_site_url = $this->encryption->encrypt( get_site_url(), $nonce, $e_keys->privateKey );

		if( is_wp_error( $e_site_url ) ) {
			return $e_site_url;
		}

		/**
		 * Filter: Allows devs to assign custom meta_data to be synced via TrustedLogin.
		 * 
		 * By default this data is transfered and stored in plain text, and should not contain any sensitive or identifiable information.
		 *
		 * @since 1.0.0
		 * 
		 * @param array  $meta_data
		 */
		$meta_data = apply_filters( 'trustedlogin/' . $this->config->ns() . '/envelope/meta', array() );

		return array(
			'secretId'   	  => $secret_id,
			'identifier' 	  => $e_identifier,
			'siteUrl'    	  => $e_site_url,
			'publicKey'  	  => $this->public_key,
			'accessKey'  	  => $access_key,
			'wpUserId'   	  => get_current_user_id(),
			'version'    	  => Client::version,
			'nonce'		 	  => $nonce,
			'clientPublicKey' => $e_keys->publicKey,
			'metaData'		  => $meta_data,
		);
	}

}

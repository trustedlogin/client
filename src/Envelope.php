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
	 * @var Config
	 */
	private $config;

	/**
	 * @var Encryption
	 */
	private $encryption;

	/**
	 * Envelope constructor.
	 *
	 * @param Encryption $encryption
	 * @param Config $config
	 */
	public function __construct( Config $config, Encryption $encryption ) {
		$this->config     = $config;
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

		$e_identifier = $this->encryption->encrypt( $identifier );

		if ( is_wp_error( $e_identifier ) ) {
			return $e_identifier;
		}

		$e_site_url = $this->encryption->encrypt( get_site_url() );

		if( is_wp_error( $e_site_url ) ) {
			return $e_site_url;
		}

		return array(
			'secretId'   => $secret_id,
			'identifier' => $e_identifier,
			'siteUrl'    => $e_site_url,
			'publicKey'  => $this->config->get_setting( 'auth/public_key' ),
			'accessKey'  => $access_key,
			'userId'     => get_current_user_id(),
			'version'    => Client::version,
		);
	}

}

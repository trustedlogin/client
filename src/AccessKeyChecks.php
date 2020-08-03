<?php
/**
 * Class AccessKeyChecks
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

use \WP_Error;

class AccessKeyChecks {

	/**
	 * @var string Namespace for the vendor
	 */
	private $ns;

	/**
	 * @var string The transient slug used for storing used accesskeys.
	 */
	private $used_accesskey_transient;

	/**
	 * @var int The number of incorrect accesskeys that should trigger an anomaly alert.
	 */
	const ACCESSKEY_LIMIT_COUNT = 3;

	/**
	 * @var int The number of seconds we should keep incorrect accesskeys stored for.
	 */
	const ACCESSKEY_LIMIT_EXPIRY = 10 * MINUTE_IN_SECONDS;

	public function __construct( Config $config ) {

		$this->ns = $config->ns();

		$this->used_accesskey_transient = 'tl-' . $this->ns . '-used-accesskeys';
		$this->logging_enabled = $config->get_setting( 'logging/enabled', false );

	}

	/**
	 * Fetches any recently used incorrect accesskeys
	 * 
	 * @return array|false Returns an array of accesskeys if transient found and hasn't expired. Otherwise returns false.
	 */
	private function get_used_accesskeys( ){
		return maybe_unserialize( get_transient( $this->used_accesskey_transient ) );
	}

	/**
	 * Detects if this identifier indicates that the site's acesskeys may be under a brute force attack.
	 * 
	 * @param  string $identifier The identifier provided via `SupportUser->maybe_login( $identifier );`
	 * 
	 * @return boolean True if an anomily was detected and site may be under attack. Else false. 
	 */
	public function detect_attack( $identifier ){

		$is_new = false;

		if ( false === ( $used_accesskeys = $this->get_used_accesskeys() ) ){
			$used_accesskeys = array();
		}

		if ( strlen( $identifier ) > 32 ) {
			$identifier = Encryption::hash( $identifier );
		}

		if ( in_array( $identifier, $used_accesskeys ) ) {
			$is_new = true;
		}

		$used_accesskeys[] =  $identifier;
		$this->save_used_accesskeys( $used_accesskeys );

		if ( $is_new ){
			return false;
		}

		// Check if this would be the 3rd wrong accesskey
		if ( count( $used_accesskeys ) >= self::ACCESSKEY_LIMIT_COUNT ){
			do_action( 'trustedlogin/' . $this->ns . '/brute_force_detected' );
			return true;
		}

		return false; 

	}

	/**
	 * Updates the tranisent holding incorrect accesskeys 
	 * 
	 * @param  array $accesskeys 
	 * @return void
	 */
	private function save_used_accesskeys( $accesskeys ){

		if ( ! is_array( $accesskeys ) ){
			return new WP_Error( 'param-not-array', '$accesskeys is not an array' );
		}

		delete_transient( $this->used_accesskey_transient );

		set_transient( $this->used_accesskey_transient, maybe_serialize( $accesskeys ), self::ACCESSKEY_LIMIT_EXPIRY );

	}

		set_transient( $this->transient_slug, maybe_serialize( $accesskeys ), self::ACCESSKEY_LIMIT_EXPIRY );

	}

}

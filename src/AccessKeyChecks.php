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
	 * @var Logging $logging
	 */
	private $logging;

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var string The transient slug used for storing used accesskeys.
	 */
	private $used_accesskey_transient;

	/**
	 * @var string The transient slug used for noting if we're temporarily blocking access.
	 */
	private $is_under_attack_transient;

	/**
	 * @var int The number of incorrect access keys that should trigger an anomaly alert.
	 */
	const ACCESSKEY_LIMIT_COUNT = 3;

	/**
	 * @var int The number of seconds we should keep incorrect access keys stored for.
	 */
	const ACCESSKEY_LIMIT_EXPIRY = 36000; // 10 * MINUTE_IN_SECONDS;

	/**
	 * @var int The number of seconds should block trustedlogin auto-logins for.
	 */
	const LOCKDOWN_EXPIRY = 72000; // 20 * MINUTE_IN_SECONDS;

	public function __construct( Config $config, Logging $logging ) {

		$this->ns = $config->ns();

		$this->logging = $logging;
		$this->config  = $config;

		$this->used_accesskey_transient = 'tl-' . $this->ns . '-used-accesskeys';
		$this->is_under_attack_transient = 'tl-' . $this->ns . '-under_attack';

	}

	/**
	 * Fetches any recently used incorrect accesskeys
	 *
	 * @return array|false Returns an array of accesskeys if transient found and hasn't expired. Otherwise returns false.
	 */
	private function get_used_accesskeys() {
		return maybe_unserialize( get_transient( $this->used_accesskey_transient ) );
	}

	/**
	 * Detects if this identifier indicates that the site's acesskeys may be under a brute force attack.
	 *
	 * @param  string $identifier The identifier provided via `SupportUser->maybe_login( $identifier );`
	 *
	 * @return boolean|WP_Error True if an anomaly was detected and site may be under attack. Else false.
	 */
	public function detect_attack( $identifier ) {

		if ( $this->in_lockdown() ){
			$this->logging->log( 'Site is in lockdown mode, aborting login.', __METHOD__, 'error' );
			return new WP_Error( 'in-lockdown', __( 'TrustedLogin temporarily disabled.' , 'trustedlogin') );
		}

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
		if ( count( $used_accesskeys ) >= self::ACCESSKEY_LIMIT_COUNT ) {

			set_transient( $this->is_under_attack_transient, time(), self::LOCKDOWN_EXPIRY );

			$notified = $this->notify_trustedlogin();

			if ( is_wp_error( $notified ) ){
				$this->logging->log( sprintf( 'Could not notify TrustedLogin (%s)', $notified->get_error_message() ), __METHOD__, 'error' );
			}

			do_action( 'trustedlogin/' . $this->ns . '/brute_force_detected' );

			return true;
		}

		return false;

	}

	/**
	 * Updates the transient holding incorrect access keys
	 *
	 * @param  array $access_keys
	 * @return void|WP_Error
	 */
	private function save_used_accesskeys( $accesskeys ) {

		if ( ! is_array( $accesskeys ) ) {
			return new WP_Error( 'param-not-array', '$accesskeys is not an array' );
		}

		delete_transient( $this->used_accesskey_transient );

		set_transient( $this->used_accesskey_transient, maybe_serialize( $accesskeys ), self::ACCESSKEY_LIMIT_EXPIRY );

	}

	/**
	 * Makes double-y sure the TrustedLogin Server approves this support-agent login.
	 *
	 * This function sends server variables to the TrustedLogin server to help prevent a number of attack vertices.
	 * It is *only* ever triggered, as part of the auto-login sequence.
	 * The session data synced will only ever be from authorized support teams, or potential attackers.
	 *
	 * @param  string $identifier The access key being used.
	 *
	 * @return true|WP_Error
	 */
	public function check_validity( $identifier ) {

		if ( $this->in_lockdown() ) {
			return new WP_Error( 'in-lockdown', __( 'TrustedLogin temporarily disabled.' , 'trustedlogin') );
		}

		$endpoint = 'verify-identifier';

		/**
		 * This array contains information from the Vendor's support agent
		 *  as a means of protecting against potential breaches.
		 *
		 * No site user/visitor/admin data is sent back to TrustedLogin server.
		 */
		$body = array(
			'identifier' => $identifier,
			'timestamp'  => time(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '',
			'user_ip'	 => isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '',
		);

		$remote = new Remote( $this->config, $this->logging );
		$api_response = $remote->send( $endpoint , $body, 'POST' );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		$response = $remote->handle_response( $api_response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Notifies the TrustedLogin server that a site may be under a possible bruteforce attack.
	 *
	 * @since  1.0.0
	 *
	 * @return true|WP_Error If the notification was sent, returns true, otherwise WP_Error on issue.
	 */
	public function notify_trustedlogin() {

		$endpoint = 'report-brute-force';

		/**
		 * This array contains identifiable information of either a malicious actor
		 *  or the Vendor's support agent who is triggering the alert.
		 *
		 * No site user/visitor/admin data is sent back to TrustedLogin server.
		 */
		$body = array(
			'timestamp'  => time(),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
			'user_ip'	 => $_SERVER['REMOTE_ADDR'],
		);

		$remote = new Remote( $this->config, $this->logging );
		$api_response = $remote->send( $endpoint , $body, 'POST' );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		$response = $remote->handle_response( $api_response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Checks if TrustedLogin is currently in lockdown
	 *
	 * @return boolean
	 */
	public function in_lockdown(){

		if ( get_transient( $this->is_under_attack_transient ) ){
			do_action( 'trustedlogin/' . $this->ns . '/locked_down' );
			return true;
		}

		return false;

	}

}

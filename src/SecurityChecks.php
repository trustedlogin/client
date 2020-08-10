<?php
/**
 * Class SecurityChecks
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

use \WP_Error;

final class SecurityChecks {

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
	private $in_lockdown_transient;

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

	/**
	 * @var string TrustedLogin endpoint to notify brute-force activity
	 */
	const BRUTE_FORCE_ENDPOINT = 'report-brute-force';

	/**
	 * @var string TrustedLogin endpoint to verify valid support activity
	 */
	const VERIFY_SUPPORT_AGENT_ENDPOINT = 'verify-identifier';

	public function __construct( Config $config, Logging $logging ) {

		$this->logging = $logging;
		$this->config  = $config;

		$this->used_accesskey_transient = 'tl-' . $this->config->ns() . '-used_accesskeys';
		$this->in_lockdown_transient    = 'tl-' . $this->config->ns() . '-in_lockdown';
	}

	/**
	 * Verifies that a provided identifier is still valid.
	 *
	 * Multiple security checks are performed, including brute-force and known-attacker-list checks
	 *
	 * @param string $identifier The identifier provided via {@see SupportUser::maybe_login()}
	 *
	 * @return true|WP_Error True if identifier passes checks. WP_Error if not.
	 */
	public function verify( $identifier ) {

		if ( $this->in_lockdown() ){

			$this->logging->log( 'Site is in lockdown mode, aborting login.', __METHOD__, 'error' );

			return new WP_Error( 'in-lockdown', __( 'TrustedLogin temporarily disabled.' , 'trustedlogin') );
		}

		if ( strlen( $identifier ) > 32 ) {
			$identifier = Encryption::hash( $identifier );
		}

		$accesskey = $this->check_brute_force( $identifier );

		if ( is_wp_error( $accesskey ) ) {

			$this->do_lockdown();

			return $accesskey;
		}

		$approved = $this->check_approved_identifier( $identifier );

		// Don't lock-down the site, since there could have been errors related to remote validation
		if ( is_wp_error( $approved ) ){

			$this->logging->log( 
				sprintf( 
					'There was an issue verifying identifier with TrustedLogin, aborting login. (%s)', 
					$approved->get_error_message() 
				),
				__METHOD__, 
				'error' 
			);
			
			return $approved;
		}

		return true;
	}

	/**
	 * Detects if this identifier indicates that the site's access keys may be under a brute force attack.
	 *
	 * @param  string $identifier The identifier provided via {@see SupportUser::maybe_login()}
	 *
	 * @return true|WP_Error WP_Error if an anomaly was detected and site may be under attack. Else true.
	 */
	private function check_brute_force( $identifier ) {

		$used_accesskeys = $this->maybe_add_used_accesskey( $identifier );

		// Is the number of attempted accesses below the lockdown limit?
		if ( count( $used_accesskeys ) >= self::ACCESSKEY_LIMIT_COUNT ) {

			$this->logging->log(
				'Potential Brute Force attack detected with identifier: ' . esc_attr( $identifier ),
				__METHOD__,
				'notice'
			);

			return new WP_Error( 'brute-force-detected', 'Login aborted due to potential brute force detection.');
		}

		return true;
	}

	/**
	 * @param string $identifier
	 *
	 * @return mixed
	 */
	private function maybe_add_used_accesskey( $identifier = '' ) {

		$used_accesskeys = get_site_transient( $this->used_accesskey_transient );

		if ( false === $used_accesskeys ){
			$used_accesskeys = array();
		}

		// This is a new access key
		if ( ! in_array( $identifier, $used_accesskeys, true ) ) {

			$used_accesskeys[] = $identifier;

			$transient_set = set_site_transient( $this->used_accesskey_transient, $used_accesskeys, self::ACCESSKEY_LIMIT_EXPIRY );

			if ( ! $transient_set ) {
				$this->logging->log( 'Used access key transient not properly set/updated.', __METHOD__, 'error' );
			}

		}

		return $used_accesskeys;
	}

	/**
	 * Returns the IP address of the requester
	 *
	 * @return null|string Returns null if REMOTE_ADDR isn't set, string IP address otherwise.
	 */
	private function get_ip() {

		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );

		$ip = trim( $ip );

		$ip = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE );

		return (string) $ip;
	}

	/**
	 * Makes double-y sure the TrustedLogin Server approves this support-agent login.
	 *
	 * This function sends server variables to the TrustedLogin server to help prevent a number of attack vectors.
	 * It is *only* ever triggered as part of the auto-login sequence.
	 * The session data synced will only ever be from authorized support teams, or potential attackers.
	 *
	 * @param string $identifier The access key being used.
	 *
	 * @return true|WP_Error
	 */
	private function check_approved_identifier( $identifier ) {

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
			'user_ip'	 => $this->get_ip(),
		);

		$remote = new Remote( $this->config, $this->logging );

		$api_response = $remote->send( self::VERIFY_SUPPORT_AGENT_ENDPOINT, $body, 'POST' );

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
	 * Notifies the TrustedLogin server that a site may be under a possible brute-force attack.
	 *
	 * @since  1.0.0
	 *
	 * @return true|WP_Error If the notification was sent, returns true, otherwise WP_Error on issue.
	 */
	private function report_lockdown() {

		/**
		 * This array contains identifiable information of either a malicious actor
		 *  or the Vendor's support agent who is triggering the alert.
		 *
		 * No site user/visitor/admin data is sent back to TrustedLogin server.
		 */
		$body = array(
			'timestamp'  => time(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '',
			'user_ip'	 => $this->get_ip(),
		);

		$remote = new Remote( $this->config, $this->logging );
		$api_response = $remote->send( self::BRUTE_FORCE_ENDPOINT , $body, 'POST' );

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
	 * Locks down the site to new access by TrustedLogin identifiers, reports lockdown to TrustedLogin
	 */
	private function do_lockdown() {

		$this->logging->log( 'Brute force is detected; starting lockdown.', __METHOD__, 'emergency' );

		$transient_set = set_site_transient( $this->in_lockdown_transient, time(), self::LOCKDOWN_EXPIRY );

		if ( ! $transient_set ) {
			$this->logging->log( 'Could not set the "in lockdown" transient.', __METHOD__, 'alert' );
		}

		$notified = $this->report_lockdown();

		if ( is_wp_error( $notified ) ){
			$this->logging->log( sprintf( 'Could not notify TrustedLogin (%s)', $notified->get_error_message() ), __METHOD__, 'error' );
		}

		/**
		 * Runs after the site is locked down to access from the Vendor
		 */
		do_action( 'trustedlogin/' . $this->config->ns() . '/lockdown/after' );
	}

	/**
	 * Checks if TrustedLogin is currently in lockdown
	 *
	 * @return bool
	 */
	public function in_lockdown(){
		return get_site_transient( $this->in_lockdown_transient );
	}

}

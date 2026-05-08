<?php
/**
 * Class Cron
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 */
final class Cron {

	/**
	 * Maximum number of times the SaaS-revoke retry cron will reattempt a
	 * single secret_id before giving up. Each attempt waits longer than
	 * the previous (5 min × attempt, capped at 1 hour).
	 *
	 * @since 1.10.0
	 */
	const MAX_SAAS_REVOKE_RETRIES = 5;

	/**
	 * Config instance.
	 *
	 * @var \TrustedLogin\Config
	 */
	private $config;

	/**
	 * The hook name for the cron job.
	 *
	 * @var string
	 */
	private $hook_name;

	/**
	 * The hook name for the deferred SaaS-revoke retry job.
	 *
	 * @var string
	 */
	private $retry_hook_name;

	/**
	 * Logging instance.
	 *
	 * @var null|\TrustedLogin\Logging $logging
	 */
	private $logging;

	/**
	 * Cron constructor.
	 *
	 * @param Config  $config Config instance.
	 * @param Logging $logging Logging instance.
	 */
	public function __construct( Config $config, Logging $logging ) {
		$this->config  = $config;
		$this->logging = $logging;

		$this->hook_name       = 'trustedlogin/' . $this->config->ns() . '/access/revoke';
		$this->retry_hook_name = 'trustedlogin/' . $this->config->ns() . '/site/retry_revoke';
	}

	/**
	 * Add hooks to revoke access using cron.
	 *
	 * The cron job is scheduled by {@see schedule()} and revoked by {@see revoke()}.
	 */
	public function init() {
		add_action( $this->hook_name, array( $this, 'revoke' ), 1 );
		add_action( $this->retry_hook_name, array( $this, 'retry_saas_revoke' ), 1 );
	}

	/**
	 * Option key holding the per-namespace queue of pending SaaS revokes.
	 * Shape: { secret_id: attempt_count, ... }.
	 *
	 * @since 1.10.0
	 */
	private function pending_queue_option_key() {
		return 'tl_' . $this->config->ns() . '_pending_saas_revoke';
	}

	/**
	 * Returns the current pending-SaaS-revoke queue.
	 *
	 * @since 1.10.0
	 *
	 * @return array<string, int>
	 */
	private function get_pending_saas_revoke_queue() {
		$queue = get_option( $this->pending_queue_option_key(), array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Persist the queue, deleting the option entirely when empty so we
	 * don't leave a stub option behind.
	 *
	 * @since 1.10.0
	 *
	 * @param array<string, int> $queue Map of secret_id => attempt_count.
	 */
	private function save_pending_saas_revoke_queue( array $queue ) {
		if ( empty( $queue ) ) {
			delete_option( $this->pending_queue_option_key() );
			return;
		}
		update_option( $this->pending_queue_option_key(), $queue, false );
	}

	/**
	 * Compute the retry delay (in seconds) for a given attempt count.
	 * Linear backoff capped at 1 hour: 5, 10, 15, 20, 25 minutes →
	 * after MAX attempts the queue gives up.
	 *
	 * @since 1.10.0
	 *
	 * @param int $attempt 1-indexed.
	 */
	private function retry_backoff_seconds( $attempt ) {
		return (int) min( 5 * MINUTE_IN_SECONDS * max( 1, (int) $attempt ), HOUR_IN_SECONDS );
	}

	/**
	 * Queue a deferred SaaS-revoke retry for a secret_id whose initial
	 * sync failed. {@see Client::revoke_access()} calls this when
	 * SiteAccess::revoke returns WP_Error so the local delete can complete
	 * without losing the SaaS-side cleanup.
	 *
	 * @since 1.10.0
	 *
	 * @param string $secret_id Site secret identifier.
	 * @param int    $attempt   1-indexed attempt counter.
	 *
	 * @return bool True if the retry was queued; false if input invalid.
	 */
	public function queue_saas_revoke_retry( $secret_id, $attempt = 1 ) {
		if ( ! is_string( $secret_id ) || '' === $secret_id ) {
			return false;
		}

		$attempt = max( 1, (int) $attempt );
		$queue   = $this->get_pending_saas_revoke_queue();

		$queue[ $secret_id ] = $attempt;
		$this->save_pending_saas_revoke_queue( $queue );

		if ( ! wp_next_scheduled( $this->retry_hook_name ) ) {
			wp_schedule_single_event( time() + $this->retry_backoff_seconds( $attempt ), $this->retry_hook_name );
		}

		return true;
	}

	/**
	 * Hooked Action: process the pending SaaS-revoke queue.
	 *
	 * For each queued secret_id, ask the live Client to retry the
	 * SiteAccess::revoke call. Successes drop from the queue.
	 * Failures bump the attempt counter; once a secret hits
	 * {@see self::MAX_SAAS_REVOKE_RETRIES} attempts we log and drop it.
	 *
	 * @since 1.10.0
	 */
	public function retry_saas_revoke() {
		$queue = $this->get_pending_saas_revoke_queue();
		if ( empty( $queue ) ) {
			return;
		}

		$client    = new Client( $this->config, false );
		$remaining = array();

		foreach ( $queue as $secret_id => $attempt ) {
			$secret_id = (string) $secret_id;
			$attempt   = max( 1, (int) $attempt );

			$ok = $client->retry_saas_revoke( $secret_id );

			if ( $ok ) {
				$this->logging->log( 'Pending SaaS revoke succeeded after ' . $attempt . ' attempt(s).', __METHOD__, 'notice' );
				continue;
			}

			$next_attempt = $attempt + 1;
			if ( $next_attempt > self::MAX_SAAS_REVOKE_RETRIES ) {
				$this->logging->log( 'Pending SaaS revoke gave up after ' . self::MAX_SAAS_REVOKE_RETRIES . ' attempts. Dropping from retry queue.', __METHOD__, 'error' );
				continue;
			}

			$remaining[ $secret_id ] = $next_attempt;
		}

		$this->save_pending_saas_revoke_queue( $remaining );

		if ( ! empty( $remaining ) ) {
			$next_delay = $this->retry_backoff_seconds( max( $remaining ) );
			wp_schedule_single_event( time() + $next_delay, $this->retry_hook_name );
		}
	}

	/**
	 * Schedule a cron job to revoke access for a specific support user.
	 *
	 * @param int    $expiration_timestamp The timestamp when the cron job should run.
	 * @param string $identifier_hash The unique identifier for the WP_User created {@see Encryption::get_random_hash()}.
	 *
	 * @return bool True if the cron job was scheduled, false if not.
	 */
	public function schedule( $expiration_timestamp, $identifier_hash ) {

		$hash = Encryption::hash( $identifier_hash );

		if ( is_wp_error( $hash ) ) {
			$this->logging->log( $hash, __METHOD__ );

			return false;
		}

		$args = array( $hash );

		/**
		 * Whether the event was scheduled.
		 *
		 * @var false|\WP_Error $scheduled_expiration
		 */
		$scheduled_expiration = wp_schedule_single_event( $expiration_timestamp, $this->hook_name, $args );

		if ( is_wp_error( $scheduled_expiration ) ) {
			$this->logging->log( 'Scheduling expiration failed: ' . sanitize_text_field( $scheduled_expiration->get_error_message() ), __METHOD__, 'error' );

			return false;
		}

		$this->logging->log( 'Scheduled Expiration succeeded for identifier ' . $identifier_hash, __METHOD__, 'info' );

		return $scheduled_expiration;
	}

	/**
	 * Reschedule a cron job to revoke access for a specific support user.
	 *
	 * @param int    $expiration_timestamp The timestamp when the cron job should run.
	 * @param string $site_identifier_hash The unique identifier for the WP_User created {@see Encryption::get_random_hash()}.
	 *
	 * @return bool
	 */
	public function reschedule( $expiration_timestamp, $site_identifier_hash ) {

		$hash = Encryption::hash( $site_identifier_hash );

		if ( is_wp_error( $hash ) ) {
			$this->logging->log( $hash, __METHOD__ );

			return false;
		}

		$unschedule_expiration = wp_clear_scheduled_hook( $this->hook_name, array( $hash ) );

		switch ( $unschedule_expiration ) {
			case false:
				$this->logging->log( sprintf( 'Could not clear scheduled hook for %s', $this->hook_name ), __METHOD__, 'error' );
				return false;
			case 0:
				$this->logging->log( sprintf( 'Cron event not found for %s', $this->hook_name ), __METHOD__, 'error' );
				return false;
		}

		return $this->schedule( $expiration_timestamp, $site_identifier_hash );
	}

	/**
	 * Hooked Action: Revokes access for a specific support user
	 *
	 * @since 1.0.0
	 *
	 * @param string $identifier_hash Identifier hash for the user associated with the cron job.
	 *
	 * @return void
	 */
	public function revoke( $identifier_hash ) {

		$this->logging->log( 'Running cron job to disable user. ID: ' . $identifier_hash, __METHOD__, 'notice' );

		$client = new Client( $this->config, false );

		$client->revoke_access( $identifier_hash );
	}
}

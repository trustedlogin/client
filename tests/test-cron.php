<?php
/**
 * Class TrustedLoginCronTest
 *
 * Pins Cron::schedule, Cron::reschedule, and the new SaaS-revoke retry
 * mechanism (queue_saas_revoke_retry + retry_saas_revoke).
 *
 * The "expired event fires → user deleted" path lives in
 * tests/e2e/tests/cron-expiration.spec.ts because it requires a real
 * `wp cron event run --due-now` cycle to validate the wp-cron wiring
 * end-to-end. Everything else — the WP-Cron API integration, queue
 * shape, backoff math, retry-cap behaviour — sits here.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class TrustedLoginCronTest extends WP_UnitTestCase {

	/** @var Config */
	private $config;

	/** @var Logging */
	private $logging;

	/** @var Cron */
	private $cron;

	/** @var string */
	private $ns;

	/** @var string */
	private $hook_name;

	/** @var string */
	private $retry_hook_name;

	public function setUp(): void {
		parent::setUp();

		$settings = array(
			'role'    => 'editor',
			'auth'    => array( 'api_key' => '9946ca31be6aa948' ),
			'decay'   => WEEK_IN_SECONDS,
			'vendor'  => array(
				'namespace'   => 'gravityview',
				'title'       => 'GravityView',
				'email'       => 'support@gravityview.co',
				'website'     => 'https://gravityview.co',
				'support_url' => 'https://gravityview.co/support/',
				'logo_url'    => '',
			),
		);

		$this->config          = new Config( $settings );
		$this->logging         = new Logging( $this->config );
		$this->cron            = new Cron( $this->config, $this->logging );
		$this->ns              = $this->config->ns();
		$this->hook_name       = 'trustedlogin/' . $this->ns . '/access/revoke';
		$this->retry_hook_name = 'trustedlogin/' . $this->ns . '/site/retry_revoke';
	}

	public function tearDown(): void {
		// Drop any TL-namespaced cron events so transactions don't carry
		// scheduled state into the next test.
		$cron = (array) get_option( 'cron', array() );
		foreach ( $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( array_keys( $hooks ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'trustedlogin/' ) ) {
					unset( $cron[ $ts ][ $hook ] );
				}
			}
			if ( empty( $cron[ $ts ] ) ) {
				unset( $cron[ $ts ] );
			}
		}
		update_option( 'cron', $cron );

		delete_option( 'tl_' . $this->ns . '_pending_saas_revoke' );

		parent::tearDown();
	}

	// -----------------------------------------------------------------
	//  schedule + reschedule
	// -----------------------------------------------------------------

	/**
	 * @covers \TrustedLogin\Cron::schedule()
	 *
	 * Cron::schedule hashes the input identifier before passing it to
	 * wp_schedule_single_event, so wp_next_scheduled with the SAME hash
	 * input must locate the event.
	 */
	public function test_schedule_registers_single_event_keyed_by_hashed_identifier() {
		$identifier = 'schedule-test-' . str_repeat( 'a', 32 );
		$ts         = time() + 3600;

		$this->cron->schedule( $ts, $identifier );

		$hashed = Encryption::hash( $identifier );
		$this->assertSame(
			$ts,
			wp_next_scheduled( $this->hook_name, array( $hashed ) ),
			'event must be queued under the hashed-identifier args at the requested timestamp'
		);
	}

	/**
	 * @covers \TrustedLogin\Cron::reschedule()
	 *
	 * reschedule(ts, id) drops the existing schedule and re-creates at
	 * the new timestamp.
	 */
	public function test_reschedule_advances_the_existing_event_timestamp() {
		$identifier = 'reschedule-test-' . str_repeat( 'b', 32 );
		$first      = time() + 1800;
		$this->cron->schedule( $first, $identifier );

		$second = time() + 5400;
		$this->cron->reschedule( $second, $identifier );

		$hashed = Encryption::hash( $identifier );
		$this->assertSame(
			$second,
			wp_next_scheduled( $this->hook_name, array( $hashed ) ),
			'rescheduled event must reflect the new timestamp'
		);
	}

	// -----------------------------------------------------------------
	//  queue_saas_revoke_retry — option write + cron schedule
	// -----------------------------------------------------------------

	/**
	 * @covers \TrustedLogin\Cron::queue_saas_revoke_retry()
	 */
	public function test_queue_saas_revoke_retry_writes_option_and_schedules_event() {
		$secret_id = 'deadbeefdeadbeefdeadbeefdeadbeef';
		$queued    = $this->cron->queue_saas_revoke_retry( $secret_id );

		$this->assertTrue( $queued );

		$queue = get_option( 'tl_' . $this->ns . '_pending_saas_revoke', array() );
		$this->assertIsArray( $queue );
		$this->assertArrayHasKey( $secret_id, $queue );
		$this->assertSame( 1, $queue[ $secret_id ], 'first attempt counter is 1' );

		$this->assertNotFalse(
			wp_next_scheduled( $this->retry_hook_name ),
			'retry cron event should be scheduled'
		);
	}

	/**
	 * @covers \TrustedLogin\Cron::queue_saas_revoke_retry()
	 *
	 * Empty/non-string input is a no-op (returns false). Defensive
	 * input handling — Client::revoke_access never feeds bad data, but
	 * if a refactor wires this into a different caller, garbage in
	 * shouldn't poison the queue.
	 */
	public function test_queue_saas_revoke_retry_rejects_empty_or_non_string() {
		$this->assertFalse( $this->cron->queue_saas_revoke_retry( '' ) );
		$this->assertFalse( $this->cron->queue_saas_revoke_retry( null ) ); // @phpstan-ignore-line
		$this->assertFalse( $this->cron->queue_saas_revoke_retry( 12345 ) ); // @phpstan-ignore-line

		$this->assertEmpty(
			get_option( 'tl_' . $this->ns . '_pending_saas_revoke', array() ),
			'invalid input must not write the queue option'
		);
	}

	// -----------------------------------------------------------------
	//  retry_saas_revoke — handler dispatches to Client::retry_saas_revoke
	// -----------------------------------------------------------------

	/**
	 * @covers \TrustedLogin\Cron::retry_saas_revoke()
	 *
	 * On success, the queued secret_id is removed and the queue option
	 * is dropped (or left empty).
	 */
	public function test_retry_saas_revoke_drains_queue_on_success() {
		$secret_id = 'successcase-deadbeefdeadbeefdead';
		$this->cron->queue_saas_revoke_retry( $secret_id );

		// Stub the SaaS DELETE to succeed.
		$filter = static function ( $preempt, $args, $url ) {
			if ( false !== strpos( (string) $url, '/api/v1/sites/' )
				&& isset( $args['method'] ) && 'DELETE' === $args['method'] ) {
				return array(
					'response' => array( 'code' => 204, 'message' => '' ),
					'body'     => '',
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 9, 3 );

		try {
			$this->cron->retry_saas_revoke();

			$queue = get_option( 'tl_' . $this->ns . '_pending_saas_revoke', 'deleted' );
			$this->assertTrue(
				'deleted' === $queue || ( is_array( $queue ) && empty( $queue ) ),
				'queue must be cleared after successful retry'
			);
		} finally {
			remove_filter( 'pre_http_request', $filter, 9 );
		}
	}

	/**
	 * @covers \TrustedLogin\Cron::retry_saas_revoke()
	 *
	 * On failure, the attempt counter advances and a new retry event is
	 * scheduled with backoff. After MAX_SAAS_REVOKE_RETRIES failed
	 * attempts the secret_id is dropped from the queue (gives up cleanly).
	 */
	public function test_retry_saas_revoke_drops_after_max_retries() {
		$secret_id = 'givesupcase-deadbeefdeadbeefdead';

		// Pre-seed the queue at the LAST allowable attempt so the next
		// failed handler call exhausts the cap.
		$this->cron->queue_saas_revoke_retry( $secret_id, Cron::MAX_SAAS_REVOKE_RETRIES );

		// Force every SaaS DELETE to fail.
		$filter = static function ( $preempt, $args, $url ) {
			if ( false !== strpos( (string) $url, '/api/v1/sites/' )
				&& isset( $args['method'] ) && 'DELETE' === $args['method'] ) {
				return array(
					'response' => array( 'code' => 500, 'message' => 'Internal' ),
					'body'     => '',
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 9, 3 );

		try {
			$this->cron->retry_saas_revoke();

			$queue = get_option( 'tl_' . $this->ns . '_pending_saas_revoke', 'deleted' );
			$this->assertTrue(
				'deleted' === $queue || ( is_array( $queue ) && empty( $queue ) ),
				'after MAX retries the secret_id must be dropped from the queue'
			);
		} finally {
			remove_filter( 'pre_http_request', $filter, 9 );
		}
	}
}

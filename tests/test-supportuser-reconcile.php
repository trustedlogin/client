<?php
/**
 * Integration tests for the expired-support-user sweep.
 *
 * A grant schedules one cron event to revoke access. If that event fires
 * while the plugin is inactive, WordPress consumes it with no listener
 * and nothing removes the support user. `maybe_login()` performs the same
 * check, but an abandoned grant is never logged into, so the sweep is the
 * only thing that reaches it.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class SupportUserReconcileTest extends WP_UnitTestCase {

	const NS = 'reconcile-vendor';
	const NS_OTHER = 'reconcile-other-vendor';

	/**
	 * Builds a SupportUser bound to the given namespace.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return SupportUser
	 */
	private function support_user_for( $ns ) {
		$config = new Config(
			array(
				'role'   => 'editor',
				'auth'   => array(
					'api_key' => '0123456789abcdef',
				),
				'vendor' => array(
					'namespace'   => $ns,
					'title'       => $ns,
					'email'       => 'support+' . $ns . '@example.test',
					'website'     => 'https://' . $ns . '.example.test',
					'support_url' => 'https://' . $ns . '.example.test/support/',
				),
			)
		);

		return new SupportUser( $config, new Logging( $config ) );
	}

	/**
	 * Creates a support user for a namespace.
	 *
	 * @param string   $ns         Namespace.
	 * @param int|null $expires    Expiration timestamp, or null to write none.
	 * @param string   $registered Value for user_registered.
	 *
	 * @return int User ID.
	 */
	private function seed_support_user( $ns, $expires, $registered = '2020-01-01 00:00:00' ) {
		$user_id = self::factory()->user->create(
			array(
				'role'            => 'editor',
				'user_registered' => $registered,
			)
		);

		update_user_option( $user_id, 'tl_' . $ns . '_id', md5( wp_generate_uuid4() ), true );

		if ( ! is_null( $expires ) ) {
			update_user_option( $user_id, 'tl_' . $ns . '_expires', $expires );
		}

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'fixture: the seeded user must exist' );
		$this->assertContains(
			$user_id,
			wp_list_pluck( $this->support_user_for( $ns )->get_all(), 'ID' ),
			'fixture: the seeded user must be visible to get_all() for its namespace'
		);

		return $user_id;
	}

	/**
	 * The case the sweep exists for: expiry passed, cron consumed, user left.
	 */
	public function test_sweep_deletes_a_support_user_whose_expiry_has_passed() {
		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		$deleted = $this->support_user_for( self::NS )->reconcile();

		$this->assertSame( 1, $deleted );
		$this->assertFalse( get_user_by( 'id', $user_id ), 'an expired support user must not survive the sweep' );
	}

	/**
	 * Access that has not expired must be left alone.
	 */
	public function test_sweep_keeps_a_support_user_whose_access_is_still_valid() {
		$user_id = $this->seed_support_user( self::NS, time() + WEEK_IN_SECONDS );

		$deleted = $this->support_user_for( self::NS )->reconcile();

		$this->assertSame( 0, $deleted );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'active access must survive the sweep' );
	}

	/**
	 * setup() only writes the expiration when cron scheduling succeeded, so a
	 * user with no expiration would otherwise never be removed. maybe_login()
	 * already treats that state as expired.
	 */
	public function test_sweep_deletes_a_support_user_that_has_no_expiration() {
		$user_id = $this->seed_support_user( self::NS, null );

		$deleted = $this->support_user_for( self::NS )->reconcile();

		$this->assertSame( 1, $deleted );
		$this->assertFalse( get_user_by( 'id', $user_id ) );
	}

	/**
	 * A grant running in another request writes the identifier before the
	 * expiration. The sweep must not delete it in that window.
	 */
	public function test_sweep_keeps_a_just_created_user_that_has_no_expiration_yet() {
		$user_id = $this->seed_support_user( self::NS, null, gmdate( 'Y-m-d H:i:s' ) );

		$deleted = $this->support_user_for( self::NS )->reconcile();

		$this->assertSame( 0, $deleted );
		$this->assertInstanceOf(
			\WP_User::class,
			get_user_by( 'id', $user_id ),
			'a support user created moments ago may still be mid-grant; the sweep must leave it'
		);
	}

	/**
	 * The sweep must only touch its own namespace.
	 */
	public function test_sweep_leaves_another_namespaces_expired_user_alone() {
		$mine   = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$theirs = $this->seed_support_user( self::NS_OTHER, time() - HOUR_IN_SECONDS );

		$deleted = $this->support_user_for( self::NS )->reconcile();

		$this->assertSame( 1, $deleted );
		$this->assertFalse( get_user_by( 'id', $mine ) );
		$this->assertInstanceOf(
			\WP_User::class,
			get_user_by( 'id', $theirs ),
			'another vendor\'s expired support user is not this namespace\'s to delete'
		);
	}

	/**
	 * The sweep is only reached if it is wired to something that runs.
	 */
	public function test_client_init_schedules_the_recurring_sweep() {
		$hook = 'trustedlogin/' . self::NS . '/access/reconcile';

		wp_unschedule_hook( $hook );

		$this->assertFalse( wp_next_scheduled( $hook ), 'fixture: the sweep must not be scheduled before init() runs' );
		$this->assertFalse( $this->sweep_is_hooked( $hook ), 'fixture: no callback before init() runs' );

		$this->client_for( self::NS )->init();

		$this->assertIsInt( wp_next_scheduled( $hook ), 'init() must schedule the sweep' );
		$this->assertTrue( $this->sweep_is_hooked( $hook ), 'init() must register a callback for the sweep' );
	}

	/**
	 * A recurring event survives firing with nothing listening; a single one
	 * is consumed, which is why the revoke event cannot be relied on here.
	 */
	public function test_the_sweep_is_scheduled_as_a_recurring_event() {
		$hook = 'trustedlogin/' . self::NS . '/access/reconcile';

		wp_unschedule_hook( $hook );

		$this->client_for( self::NS )->init();

		$this->assertSame( 'daily', wp_get_schedule( $hook ), 'the sweep must recur, not fire once' );
	}

	/**
	 * Nothing must be hooked to admin_init, so ordinary admin requests pay
	 * nothing for the sweep.
	 */
	public function test_the_sweep_does_not_run_on_admin_init() {
		$this->client_for( self::NS )->init();

		$this->assertFalse( $this->sweep_is_hooked( 'admin_init' ), 'the sweep must not cost a query on every admin page load' );
	}

	/**
	 * Builds a Client for a namespace without booting its hooks.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return Client
	 */
	private function client_for( $ns ) {
		return new Client(
			new Config(
				array(
					'role'   => 'editor',
					'auth'   => array(
						'api_key' => '0123456789abcdef',
					),
					'vendor' => array(
						'namespace'   => $ns,
						'title'       => $ns,
						'email'       => 'support+' . $ns . '@example.test',
						'website'     => 'https://' . $ns . '.example.test',
						'support_url' => 'https://' . $ns . '.example.test/support/',
					),
				)
			),
			false
		);
	}

	/**
	 * Whether any callback on $hook is the Cron sweep.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return bool
	 */
	private function sweep_is_hooked( $hook ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return false;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] )
					&& $callback['function'][0] instanceof Cron
					&& 'reconcile' === $callback['function'][1] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Nothing to do must cost nothing and report nothing.
	 */
	public function test_sweep_reports_zero_when_there_is_nothing_to_remove() {
		$this->assertSame( 0, $this->support_user_for( self::NS )->reconcile() );
	}
}

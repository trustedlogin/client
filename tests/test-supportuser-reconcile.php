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
	 * The sweep is only reached if Client wires it to a request that runs.
	 */
	public function test_client_init_registers_the_sweep_on_admin_init() {
		$config = new Config(
			array(
				'role'   => 'editor',
				'auth'   => array(
					'api_key' => '0123456789abcdef',
				),
				'vendor' => array(
					'namespace'   => self::NS,
					'title'       => self::NS,
					'email'       => 'support+' . self::NS . '@example.test',
					'website'     => 'https://' . self::NS . '.example.test',
					'support_url' => 'https://' . self::NS . '.example.test/support/',
				),
			)
		);

		$client = new Client( $config, false );

		$this->assertFalse(
			has_action( 'admin_init' ) && $this->sweep_is_hooked(),
			'fixture: the sweep must not be registered before init() runs'
		);

		$client->init();

		$this->assertTrue( $this->sweep_is_hooked(), 'Client::init() must register the sweep on admin_init' );
	}

	/**
	 * Whether any admin_init callback is SupportUser::reconcile().
	 *
	 * @return bool
	 */
	private function sweep_is_hooked() {
		global $wp_filter;

		if ( empty( $wp_filter['admin_init'] ) ) {
			return false;
		}

		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] )
					&& $callback['function'][0] instanceof Client
					&& 'reconcile_support_users' === $callback['function'][1] ) {
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

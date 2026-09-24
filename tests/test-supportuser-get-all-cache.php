<?php
/**
 * Integration tests for the SupportUser::get_all() result cache.
 *
 * Two properties are asserted:
 *
 *   1. Namespace scope. Several plugins can embed this SDK on one site,
 *      each with its own namespace and its own `tl_{ns}_id` user meta.
 *      A result cached for one namespace must never be returned to
 *      another.
 *   2. Freshness within a request. A support user created or deleted
 *      after an earlier call must be reflected by the next call.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class SupportUserGetAllCacheTest extends WP_UnitTestCase {

	const NS_A = 'cache-vendor-a';
	const NS_B = 'cache-vendor-b';
	const NS_C = 'cache-vendor-c';

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
	 * Creates a user carrying the identifier meta that get_all() queries on.
	 *
	 * @param string $ns Namespace the user belongs to.
	 *
	 * @return int User ID.
	 */
	private function seed_support_user( $ns ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		update_user_option( $user_id, 'tl_' . $ns . '_id', md5( wp_generate_uuid4() ), true );

		return $user_id;
	}

	/**
	 * A result cached for one namespace must not be handed to another.
	 */
	public function test_get_all_is_scoped_to_its_own_namespace() {
		$user_a = $this->seed_support_user( self::NS_A );
		$user_b = $this->seed_support_user( self::NS_B );

		$support_user_a = $this->support_user_for( self::NS_A );
		$support_user_b = $this->support_user_for( self::NS_B );

		$found_a = $support_user_a->get_all();

		$this->assertCount( 1, $found_a, 'namespace A must see exactly its own support user' );
		$this->assertSame( $user_a, $found_a[0]->ID );

		$found_b = $support_user_b->get_all();

		$this->assertCount( 1, $found_b, 'namespace B must see its own support user, not the one cached for namespace A' );
		$this->assertSame( $user_b, $found_b[0]->ID, 'namespace B received namespace A\'s support user from a shared cache' );
	}

	/**
	 * Calling in the other order must give the same answer.
	 */
	public function test_get_all_is_scoped_regardless_of_call_order() {
		$user_a = $this->seed_support_user( self::NS_A );
		$user_b = $this->seed_support_user( self::NS_B );

		$support_user_a = $this->support_user_for( self::NS_A );
		$support_user_b = $this->support_user_for( self::NS_B );

		$found_b = $support_user_b->get_all();
		$found_a = $support_user_a->get_all();

		$this->assertSame( array( $user_b ), wp_list_pluck( $found_b, 'ID' ) );
		$this->assertSame( array( $user_a ), wp_list_pluck( $found_a, 'ID' ) );
	}

	/**
	 * A namespace with no support users must report none, even after
	 * another namespace has populated the cache.
	 */
	public function test_get_all_returns_empty_for_a_namespace_with_no_users() {
		$this->seed_support_user( self::NS_A );

		$this->support_user_for( self::NS_A )->get_all();

		$this->assertSame(
			array(),
			$this->support_user_for( self::NS_C )->get_all(),
			'a namespace with no support users must not inherit another namespace\'s result'
		);
	}

	/**
	 * A user created after an earlier call must be visible to the next one.
	 */
	public function test_get_all_sees_a_user_created_after_an_earlier_call() {
		$support_user = $this->support_user_for( self::NS_C );

		$this->assertSame( array(), $support_user->get_all(), 'no support users exist yet' );

		$user_id = $this->seed_support_user( self::NS_C );

		$found = $support_user->get_all();

		$this->assertCount( 1, $found, 'a support user created after the first call must be returned by the next one' );
		$this->assertSame( $user_id, $found[0]->ID );
	}

	/**
	 * A user removed after an earlier call must be gone from the next one.
	 */
	public function test_get_all_drops_a_user_removed_after_an_earlier_call() {
		$user_id      = $this->seed_support_user( self::NS_C );
		$support_user = $this->support_user_for( self::NS_C );

		$this->assertCount( 1, $support_user->get_all() );

		delete_user_option( $user_id, 'tl_' . self::NS_C . '_id', true );

		$this->assertSame( array(), $support_user->get_all(), 'a user that no longer carries the identifier meta must not be returned' );
	}
}

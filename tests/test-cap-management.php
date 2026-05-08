<?php
/**
 * Cap-management integration tests for the support-role flow.
 *
 * "Editor role, minus the ability to edit posts" is the canonical
 * vendor-customized support session — broad enough to debug, scoped
 * away from the customer's actual content. The tests below pin
 * every variation of the cap merge that an integrator might pass:
 *
 *  - caps/add as an assoc array {cap: reason}
 *  - caps/remove as an assoc array {cap: reason}
 *  - caps/remove as a list array [cap, cap]   (real input shape from
 *    integrators who read the example as a flat list)
 *  - caps/add and caps/remove pointing at the same cap (remove wins,
 *    pinning the order-of-operations contract from SupportRole::create)
 *  - Role refresh: changing the cap config between grants must
 *    re-derive the role, not return a stale clone
 *
 * @group caps
 * @group security
 */

class TrustedLoginCapManagementTest extends WP_UnitTestCase {

	/** @var array */
	private $base_settings;

	public function setUp(): void {
		parent::setUp();

		$this->base_settings = array(
			'role'        => 'editor',
			'caps'        => array(),
			'webhook_url' => 'https://example.test/webhook',
			'auth'        => array(
				'api_key'     => '9946ca31be6aa948',
				'license_key' => 'license',
			),
			'decay'       => WEEK_IN_SECONDS,
			'vendor'      => array(
				'namespace'   => 'gravityview',
				'title'       => 'GravityView',
				'email'       => 'cap-test-' . bin2hex( random_bytes( 6 ) ) . '@example.test',
				'website'     => 'https://example.test',
				'support_url' => 'https://example.test/support',
				'logo_url'    => '',
			),
			'reassign_posts' => true,
		);
	}

	public function tearDown(): void {
		// Run parent rollback FIRST so the DB drops in-test role
		// writes; then reload wp_roles from the clean option so the
		// in-memory cache matches the rolled-back DB state.
		parent::tearDown();

		global $wp_roles;
		$wp_roles = null;
		wp_roles();
	}

	private function build_role( array $caps_overrides = array(), $role_to_clone = 'editor' ): \WP_Role {
		$settings = $this->base_settings;
		$settings['role'] = $role_to_clone;
		$settings['caps'] = $caps_overrides;
		// Each test gets a unique namespace so the cloned role
		// slug is distinct (avoids the "role already exists,
		// returning early" branch from polluting cross-test state).
		$settings['vendor']['namespace'] = 'capns' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );

		$config       = new \TrustedLogin\Config( $settings );
		$logging      = new \TrustedLogin\Logging( $config );
		$support_role = new \TrustedLogin\SupportRole( $config, $logging );

		// Diagnostic: confirm Config sees the caps/remove we passed.
		$seen_remove = $config->get_setting( 'caps/remove' );
		if ( ! empty( $caps_overrides['remove'] ) && empty( $seen_remove ) ) {
			$this->fail(
				'Config dropped caps/remove. Passed: ' . wp_json_encode( $caps_overrides['remove'] )
					. '; Config returned: ' . wp_json_encode( $seen_remove )
			);
		}

		$role = $support_role->create();
		$this->assertInstanceOf(
			\WP_Role::class,
			$role,
			'SupportRole::create must return WP_Role; got ' . ( is_wp_error( $role ) ? $role->get_error_code() . ': ' . $role->get_error_message() : gettype( $role ) )
		);

		return $role;
	}

	// -----------------------------------------------------------------
	//  caps/add — assoc shape
	// -----------------------------------------------------------------

	public function test_caps_add_assoc_shape_grants_each_cap(): void {
		$role = $this->build_role( array(
			'add' => array(
				'manage_options' => 'admin tools needed for debug',
			),
		) );

		$this->assertTrue(
			$role->has_cap( 'manage_options' ),
			'caps/add must grant the cap when supplied as {cap => reason}.'
		);

		// Editor's standard caps must still be present.
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
	}

	// -----------------------------------------------------------------
	//  caps/remove — assoc shape
	// -----------------------------------------------------------------

	public function test_caps_remove_assoc_shape_strips_each_cap(): void {
		$role = $this->build_role( array(
			'remove' => array(
				'edit_posts' => 'no editing posts during a support session',
			),
		) );

		// Dump diagnostic if assertion fails: both has_cap (which
		// runs through the role_has_cap filter) and the raw
		// $role->capabilities (the in-memory state remove_cap
		// should have mutated).
		$has_cap_dump = array();
		foreach ( array( 'edit_posts', 'edit_pages', 'read' ) as $cap ) {
			$has_cap_dump[ $cap ] = $role->has_cap( $cap ) ? 'true' : 'false';
		}
		$raw_caps = isset( $role->capabilities['edit_posts'] ) ? var_export( $role->capabilities['edit_posts'], true ) : 'unset';
		$role_in_global = wp_roles()->get_role( $role->name );
		$global_raw     = $role_in_global && isset( $role_in_global->capabilities['edit_posts'] )
			? var_export( $role_in_global->capabilities['edit_posts'], true )
			: 'unset';

		$this->assertFalse(
			$role->has_cap( 'edit_posts' ),
			"caps/remove must strip the cap. has_cap dump: " . wp_json_encode( $has_cap_dump )
				. " | role->capabilities['edit_posts']: {$raw_caps}"
				. " | wp_roles->get_role(name)->capabilities['edit_posts']: {$global_raw}"
		);

		// Other editor caps must remain.
		$this->assertTrue( $role->has_cap( 'edit_pages' ), 'edit_pages must stay — only edit_posts was asked to be removed.' );
		$this->assertTrue( $role->has_cap( 'read' ) );
	}

	// -----------------------------------------------------------------
	//  caps/remove — list shape (the reported foot-gun)
	// -----------------------------------------------------------------

	public function test_caps_remove_list_shape_strips_each_cap(): void {
		// An integrator reading the docs and passing a flat list
		// — "the caps to remove are X, Y, Z" — is the natural
		// input shape. The SDK must handle it identically to the
		// assoc shape so the cap actually disappears.
		$role = $this->build_role( array(
			'remove' => array( 'edit_posts', 'edit_pages' ),
		) );

		$this->assertFalse(
			$role->has_cap( 'edit_posts' ),
			'caps/remove as a list must strip each named cap. Treating list elements as cap NAMES is the natural read of the API.'
		);
		$this->assertFalse(
			$role->has_cap( 'edit_pages' ),
			'second list entry must also strip.'
		);

		// Editor caps NOT in the remove list must remain.
		$this->assertTrue( $role->has_cap( 'read' ) );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
	}

	// -----------------------------------------------------------------
	//  caps/add then caps/remove on the same cap — remove wins
	// -----------------------------------------------------------------

	public function test_caps_remove_overrides_caps_add_on_same_cap(): void {
		// SupportRole::create runs caps/add into the cap-merge,
		// then add_role, THEN caps/remove on the freshly-created
		// role. So if both reference the same cap, the remove
		// pass is the last writer — pin that order.
		$role = $this->build_role( array(
			'add'    => array( 'manage_options' => 'add' ),
			'remove' => array( 'manage_options' => 'remove' ),
		) );

		$this->assertFalse(
			$role->has_cap( 'manage_options' ),
			'When caps/add and caps/remove both reference the same cap, REMOVE wins — that is the order of operations in SupportRole::create.'
		);
	}

	// -----------------------------------------------------------------
	//  Role refresh: cap config change between grants must take effect
	// -----------------------------------------------------------------

	public function test_existing_role_is_refreshed_when_cap_config_changes(): void {
		// First call: editor + edit_posts removed.
		$ns       = 'capns_refresh_' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$settings = $this->base_settings;
		$settings['vendor']['namespace'] = $ns;
		$settings['caps'] = array( 'remove' => array( 'edit_posts' => 'first round' ) );

		$config       = new \TrustedLogin\Config( $settings );
		$logging      = new \TrustedLogin\Logging( $config );
		$support_role = new \TrustedLogin\SupportRole( $config, $logging );
		$first        = $support_role->create();
		$this->assertInstanceOf( \WP_Role::class, $first );
		$this->assertFalse( $first->has_cap( 'edit_posts' ), 'Sanity: first run strips edit_posts.' );

		// Second call: same namespace (so same role slug), but a
		// DIFFERENT cap config — now we want manage_options added
		// AND edit_pages removed. The role must reflect the new
		// config, not stale state from the first run.
		$settings['caps'] = array(
			'add'    => array( 'manage_options' => 'second round' ),
			'remove' => array( 'edit_pages' => 'second round' ),
		);
		$config2       = new \TrustedLogin\Config( $settings );
		$support_role2 = new \TrustedLogin\SupportRole( $config2, new \TrustedLogin\Logging( $config2 ) );
		$second        = $support_role2->create();
		$this->assertInstanceOf( \WP_Role::class, $second );

		$this->assertTrue(
			$second->has_cap( 'manage_options' ),
			'After cap config change, the role MUST reflect the new caps/add. Stale role-cache would grant a less-privileged session than the integrator configured.'
		);
		$this->assertFalse(
			$second->has_cap( 'edit_pages' ),
			'After cap config change, the new caps/remove MUST take effect. Stale role-cache would leave the support user with caps the integrator already removed.'
		);
		$this->assertTrue(
			$second->has_cap( 'edit_posts' ),
			'edit_posts is no longer in caps/remove on the second config — must be present.'
		);
	}

	// -----------------------------------------------------------------
	//  prevented_caps cannot be granted via caps/add (already pinned
	//  at Config::validate level by GrantingFlowSecurityTest, but
	//  re-pin here at the SupportRole layer in case Config is
	//  bypassed).
	// -----------------------------------------------------------------

	public function test_prevented_caps_in_clone_source_are_stripped_from_support_role(): void {
		// Administrator role has create_users (a prevented cap). A
		// clone of administrator must NOT grant create_users to the
		// support role even if the integrator forgot to remove it.
		$role = $this->build_role( array(), 'administrator' );

		$this->assertFalse(
			$role->has_cap( 'create_users' ),
			'create_users is on SupportRole::$prevented_caps and must be stripped from the cloned role even when the source role has it.'
		);
		$this->assertFalse(
			$role->has_cap( 'delete_users' ),
			'delete_users must be stripped.'
		);
		$this->assertFalse(
			$role->has_cap( 'promote_users' ),
			'promote_users must be stripped.'
		);

		// Some admin caps SHOULD survive — an admin clone is meant
		// to be a powerful support session.
		$source_admin = get_role( 'administrator' );
		$source_caps  = $source_admin ? array_keys( $source_admin->capabilities ) : array();
		$this->assertTrue(
			$role->has_cap( 'edit_posts' ),
			'edit_posts must survive admin clone. Source admin caps: ' . wp_json_encode( $source_caps )
		);
	}

	// -----------------------------------------------------------------
	//  E2E-ish — granting "editor minus edit_posts" produces a
	//  support user who can read but cannot publish/edit posts.
	// -----------------------------------------------------------------

	// -----------------------------------------------------------------
	//  clone_role: false — uses the named role directly, never clones
	// -----------------------------------------------------------------

	/**
	 * Under clone_role:false the SDK skips add_role entirely and
	 * assigns the integrator's named role directly to the support user.
	 * This is the materially-different code path from the default
	 * cloning behaviour, and a regression here would either:
	 *   - silently mutate the customer's actual role (caps), or
	 *   - assign a role slug that doesn't exist (broken support session)
	 */
	public function test_clone_role_false_assigns_named_role_directly() {
		$ns       = 'cnf_assign_' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$settings = $this->base_settings;
		$settings['vendor']['namespace'] = $ns;
		$settings['role']                = 'editor';
		$settings['clone_role']          = false;
		$settings['caps']                = array();

		$config       = new \TrustedLogin\Config( $settings );
		$logging      = new \TrustedLogin\Logging( $config );
		$support_role = new \TrustedLogin\SupportRole( $config, $logging );
		$support_user = new \TrustedLogin\SupportUser( $config, $logging );

		$role = $support_role->get();
		$this->assertInstanceOf( \WP_Role::class, $role );
		$this->assertSame( 'editor', $role->name, 'clone_role:false uses the named role verbatim' );

		$user_id = $support_user->create();
		$this->assertIsInt( $user_id );

		$user = get_userdata( $user_id );
		$this->assertContains(
			'editor',
			(array) $user->roles,
			'support user must hold the editor role directly, not a {ns}-support clone'
		);
		$this->assertNotContains(
			$ns . '-support',
			(array) $user->roles,
			'no clone role should be assigned under clone_role:false'
		);
	}

	/**
	 * SupportRole::delete refuses to remove a role on the protected list,
	 * regardless of whether the capability flag is present. Under
	 * clone_role:false the cap flag is never written (the role wasn't
	 * cloned), so the protected_roles guard is the second layer of
	 * defense. Pin both layers explicitly.
	 */
	public function test_clone_role_false_delete_refuses_to_drop_protected_role() {
		$ns       = 'cnf_delete_' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$settings = $this->base_settings;
		$settings['vendor']['namespace'] = $ns;
		$settings['role']                = 'editor';
		$settings['clone_role']          = false;
		$settings['caps']                = array();

		$config       = new \TrustedLogin\Config( $settings );
		$logging      = new \TrustedLogin\Logging( $config );
		$support_role = new \TrustedLogin\SupportRole( $config, $logging );

		// First call to ::get() resolves get_role('editor'). Then call
		// delete() — must NOT remove the editor role.
		$resolved = $support_role->get();
		$this->assertInstanceOf( \WP_Role::class, $resolved );

		$result = $support_role->delete();
		$this->assertNotTrue(
			$result,
			'delete() must not return true for the editor role; protected_roles guard or missing capability flag should refuse'
		);

		$this->assertNotNull(
			get_role( 'editor' ),
			'editor role must remain after a refused delete — losing it would break every editor user on the site'
		);
	}

	public function test_editor_minus_edit_posts_yields_support_user_who_cannot_edit(): void {
		$ns       = 'capns_e2e_' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$settings = $this->base_settings;
		$settings['vendor']['namespace'] = $ns;
		$settings['caps']                = array(
			'remove' => array( 'edit_posts' => 'no content edits during support' ),
		);

		$config       = new \TrustedLogin\Config( $settings );
		$logging      = new \TrustedLogin\Logging( $config );
		$support_role = new \TrustedLogin\SupportRole( $config, $logging );
		$support_user = new \TrustedLogin\SupportUser( $config, $logging );

		$role = $support_role->create();
		$this->assertInstanceOf( \WP_Role::class, $role );

		$user_id = $support_user->create();
		$this->assertIsInt( $user_id, 'Support user creation must succeed.' );

		// Seat the support user into the current request and
		// inspect the cap matrix from a real WP_User.
		wp_set_current_user( $user_id );
		$user = get_userdata( $user_id );

		$this->assertTrue(
			$user->has_cap( 'read' ),
			'Read access is essential for any support flow.'
		);
		$this->assertTrue(
			$user->has_cap( 'edit_pages' ),
			'edit_pages should remain — only edit_posts was scoped out.'
		);
		$this->assertFalse(
			$user->has_cap( 'edit_posts' ),
			'edit_posts must be denied at the live-user level — this is the property the integrator relied on when configuring the support session.'
		);
		$this->assertFalse(
			$user->has_cap( 'create_users' ),
			'create_users must be denied (prevented_cap).'
		);

		// Cleanup.
		wp_delete_user( $user_id );
		if ( is_multisite() && function_exists( 'wpmu_delete_user' ) ) {
			wpmu_delete_user( $user_id );
		}
	}
}

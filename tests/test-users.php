<?php
/**
 * Class TrustedLoginUsersTest
 *
 * @package Trustedlogin_Button
 */

/**
 * Sample test case.
 */
class TrustedLoginUsersTest extends WP_UnitTestCase {

	/**
	 * @var TrustedLogin
	 */
	private $TrustedLogin;

	/**
	 * @var ReflectionClass
	 */
	private $TrustedLoginReflection;

	private $config = array();

	public function setUp() {

		parent::setUp();

		$this->config = array(
			'role' => 'editor',
			'caps' => array(
				'add' => array(
					'manage_options' => 'we need this to make things work real gud',
					'edit_posts' => 'Access the posts that you created',
					'delete_users' => 'In order to manage the users that we thought you would want us to.',
				),
			),
			'webhook_url' => 'https://www.trustedlogin.com/webhook-example/',
			'auth' => array(
				'public_key' => '9946ca31be6aa948', // Public key for encrypting the securedKey
			),
			'decay' => WEEK_IN_SECONDS,
			'vendor' => array(
				'namespace' => 'gravityview',
				'title' => 'GravityView',
				'first_name' => 'Floaty',
				'last_name' => 'the Astronaut',
				'email' => 'support@gravityview.co',
				'website' => 'https://gravityview.co',
				'support_url' => 'https://gravityview.co/support/', // Backup to redirect users if TL is down/etc
				'logo_url' => '', // Displayed in the authentication modal
			),
			'reassign_posts' => true,
		);

		$this->TrustedLogin = new TrustedLogin\Client( $this->config );
		$this->TrustedLoginReflection = new ReflectionClass( '\TrustedLogin\Client' );
	}

	public function tearDown() {
		parent::tearDown();
	}

	private function _get_public_property( $name ) {

		$prop = $this->TrustedLoginReflection->getProperty( $name );
		$prop->setAccessible( true );

		return $prop;
	}

	private function _get_public_method( $name ) {

		$method = $this->TrustedLoginReflection->getMethod( $name );
		$method->setAccessible( true );

		return $method;
	}

	/**
	 * @covers TrustedLogin::support_user_create_role
	 */
	public function test_support_user_create_role() {
		$this->_test_cloned_cap( 'administrator' );
		$this->_test_cloned_cap( 'editor' );
		$this->_test_cloned_cap( 'author' );
		$this->_test_cloned_cap( 'contributor' );
		$this->_test_cloned_cap( 'subscriber' );

		$empty_role = $this->TrustedLogin->support_user_create_role( '', 'administrator' );
		$this->assertWPError( $empty_role, 'empty new role' );
		$this->assertEquals( 'new_role_slug_not_defined', $empty_role->get_error_code(), 'empty new role' );

		$cloned_role_slug_not_defined = $this->TrustedLogin->support_user_create_role( microtime(), '' );
		$this->assertWPError( $cloned_role_slug_not_defined, 'empty clone role' );
		$this->assertEquals( 'cloned_role_slug_not_defined', $cloned_role_slug_not_defined->get_error_code() );

		$not_string_new_role = $this->TrustedLogin->support_user_create_role( array('asdasd'), 'administrator' );
		$this->assertWPError( $not_string_new_role, 'not string new role' );
		$this->assertEquals( 'new_role_slug_not_string', $not_string_new_role->get_error_code(), 'not string new role' );

		$not_string_clone_role = $this->TrustedLogin->support_user_create_role( 'administrator', array('asdasd') );
		$this->assertWPError( $not_string_clone_role, 'not string clone role' );
		$this->assertEquals( 'cloned_role_slug_not_string', $not_string_clone_role->get_error_code(), 'not string clone role' );

		$this->assertTrue( $this->TrustedLogin->support_user_create_role( 'administrator', '1' ) instanceof WP_Role, 'role already exists' );

	}

	/**
	 * @param $role
	 */
	private function _test_cloned_cap( $role ) {

		$new_role = microtime();

		$new_role = $this->TrustedLogin->support_user_create_role( $new_role, $role );

		$this->assertTrue( $new_role instanceof WP_Role );

		$remove_caps = array(
			'create_users',
			'delete_users',
			'edit_users',
			'promote_users',
			'delete_site',
			'remove_users',
		);

		$new_role_caps = $new_role->capabilities;
		$cloned_caps = get_role( $role )->capabilities;

		foreach ( $remove_caps as $remove_cap ) {
			$this->assertFalse( in_array( $remove_cap, $new_role_caps, true ) );
			unset( $cloned_caps[ $remove_cap ] );
		}

		$added_caps = $this->TrustedLogin->get_setting('caps/add' );

		foreach ( (array) $added_caps as $added_cap => $reason ) {

			// The caps that were requested to be added are not allowed
			if ( in_array( $added_cap, $remove_caps, true ) ) {
				$this->assertFalse( in_array( $added_cap, array_keys( $new_role_caps ), true ), 'restricted caps were added, but should not have been' );
			} else {
				$this->assertTrue( in_array( $added_cap, array_keys( $new_role_caps ), true ), $added_cap . ' was not added, but should have been (for ' . $role .' role)' );
				$cloned_caps[ $added_cap ] = true;
			}

		}

		$this->assertEquals( $new_role_caps, $cloned_caps );
	}

	/**
	 * @covers TrustedLogin::create_support_user
	 * @covers TrustedLogin::support_user_create_role
	 */
	public function test_create_support_user() {
		global $wp_roles;

		$this->_reset_roles();

		$user_id = $this->TrustedLogin->create_support_user();

		// Was the user created?
		$this->assertNotFalse( $user_id );
		$this->assertNotWPError( $user_id );

		$support_user = new WP_User( $user_id );
		$this->assertTrue( $support_user->exists() );

		// Was the role created?
		$support_role_key = $this->_get_public_property( 'support_role' )->getValue( $this->TrustedLogin );
		$this->assertTrue( $wp_roles->is_role( $support_role_key ) );
		$support_role = $wp_roles->get_role( $support_role_key );
		$this->assertInstanceOf( 'WP_Role', $support_role, 'The support role key is "' . $support_role_key . '"' );

		if ( get_option( 'link_manager_enabled' ) ) {
			$support_user->add_cap( 'manage_links' );
		}

		$this->assertTrue( in_array( $support_role_key, $support_user->roles, true ) );

		foreach( $support_role->capabilities as $expected_cap => $enabled ) {

			$expect = true;

			// manage_links is magical.
			if ( 'manage_links' === $expected_cap ) {
				$link_manager_enabled = get_option( 'link_manager_enabled' );
				$expect = ! empty( $link_manager_enabled );
			}

			/**
			 * This cap requires `delete_users` for normal admins, or is_super_admin() for MS, which we aren't testing
			 * @see map_meta_cap():393
			 */
			if( 'unfiltered_html' === $expected_cap ) {
				$expect = ! is_multisite();
			}

			if( ! is_numeric( $expected_cap ) ) {
				$this->assertSame( $expect, $support_user->has_cap( $expected_cap ), 'Did not have ' . $expected_cap .', which was set to ' . var_export( $enabled, true ) );
			}
		}

		$username = sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->TrustedLogin->get_setting( 'vendor/title' ) );

		$this->assertSame( $this->TrustedLogin->get_setting('vendor/first_name'), $support_user->first_name );
		$this->assertSame( $this->TrustedLogin->get_setting('vendor/last_name'), $support_user->last_name );
		$this->assertSame( $this->TrustedLogin->get_setting('vendor/email'), $support_user->user_email );
		$this->assertSame( $this->TrustedLogin->get_setting('vendor/website'), $support_user->user_url );
		$this->assertSame( sanitize_user( $username ), $support_user->user_login );

		###
		###
		### Test error messages
		###
		###

		$this->_reset_roles();

		$duplicate_user = $this->TrustedLogin->create_support_user();
		$this->assertWPError( $duplicate_user );
		$this->assertSame( 'username_exists', $duplicate_user->get_error_code() );

		$this->_reset_roles();

		$config_with_new_title = $this->config;
		$config_with_new_title['vendor']['title'] = microtime();
		$TL_with_new_title = new TrustedLogin\Client( $config_with_new_title );

		$should_be_dupe_email = $TL_with_new_title->create_support_user();
		$this->assertWPError( $should_be_dupe_email );
		$this->assertSame( 'user_email_exists', $should_be_dupe_email->get_error_code() );

		$this->_reset_roles();

		$config_with_bad_role = $this->config;
		$config_with_bad_role['vendor']['title'] = microtime();
		$config_with_bad_role['vendor']['namespace'] = microtime();
		$config_with_bad_role['vendor']['email'] = microtime() . '@example.com';
		$config_with_bad_role['role'] = 'madeuprole';
		$TL_config_with_bad_role = new TrustedLogin\Client( $config_with_bad_role );

		$should_be_role_does_not_exist = $TL_config_with_bad_role->create_support_user();
		$this->assertWPError( $should_be_role_does_not_exist );
		$this->assertSame( 'role_does_not_exist', $should_be_role_does_not_exist->get_error_code() );


		$valid_config = $this->config;
		$valid_config['vendor']['title'] = microtime();
		$valid_config['vendor']['namespace'] = microtime();
		$valid_config['vendor']['email'] = microtime() . '@example.com';
		$TL_valid_config = new TrustedLogin\Client( $valid_config );

		// Check to see what happens when an error is returned during wp_insert_user()
		add_filter( 'pre_user_login', '__return_empty_string' );

		$should_be_empty_login = $TL_valid_config->create_support_user();
		$this->assertWPError( $should_be_empty_login );
		$this->assertSame( 'empty_user_login', $should_be_empty_login->get_error_code() );

		remove_filter( 'pre_user_login', '__return_empty_string' );
	}

	/**
	 * @covers TrustedLogin::get_expiration_timestamp
	 */
	function test_get_expiration_timestamp() {

		$valid_config = array(
			'auth' => array(
				'public_key' => 'not empty'
			),
			'vendor' => array(
				'namespace' => 'asdasd',
				'email' => 'asdasds',
				'title' => 'asdasdsad',
				'website' => 'https://example.com',
				'support_url' => 'https://example.com/support/',
			)
		);

		$DefaultTrustedLogin = new TrustedLogin\Client( $valid_config );

		$this->assertSame( ( time() + WEEK_IN_SECONDS ), $DefaultTrustedLogin->get_expiration_timestamp(), 'The method should have "WEEK_IN_SECONDS" set as default.' );

		$this->assertSame( time() + DAY_IN_SECONDS, $DefaultTrustedLogin->get_expiration_timestamp( DAY_IN_SECONDS ) );

		$this->assertSame( time() + WEEK_IN_SECONDS, $this->TrustedLogin->get_expiration_timestamp( WEEK_IN_SECONDS ) );

		$this->assertSame( false, $this->TrustedLogin->get_expiration_timestamp( 0 ) );

		$valid_config['decay'] = 12345;

		$DefaultTrustedLoginWithDecay = new TrustedLogin\Client( $valid_config );

		$this->assertSame( time() + 12345, $DefaultTrustedLoginWithDecay->get_expiration_timestamp() );

		$valid_config['decay'] = 0;

		$DefaultTrustedLoginWithNoDecay = new TrustedLogin\Client( $valid_config );

		$this->assertSame( false, $DefaultTrustedLoginWithNoDecay->get_expiration_timestamp() );
	}

	/**
	 * Make sure the user meta and cron are added correctly
	 *
	 * @covers TrustedLogin::support_user_setup
	 */
	function test_support_user_setup() {

		$current = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );

		wp_set_current_user( $current->ID );

		$user = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );

		$hash = 'asdsdasdasdasdsd';
		$hash_md5 = md5( $hash );
		$expiry = $this->TrustedLogin->get_expiration_timestamp( DAY_IN_SECONDS );

		$this->assertSame( $hash_md5, $this->TrustedLogin->support_user_setup( $user->ID, $hash, $expiry ) );
		$this->assertSame( (string) $expiry, get_user_option( $this->_get_public_property('expires_meta_key' )->getValue( $this->TrustedLogin ), $user->ID ) );
		$this->assertSame( (string) $current->ID, get_user_option( $this->_get_public_property('created_by_meta_key' )->getValue( $this->TrustedLogin ), $user->ID ) );

		// We are scheduling a single event cron, so it will return `false` when using wp_get_schedule().
		// False is the same result as an error, so we're doing more legwork here to validate.
		$crons = _get_cron_array();

		/** @see wp_get_schedule The md5/serialize/array/md5 nonsense is replicating that behavior */
		$cron_id = md5( serialize( array( $hash_md5 ) ) );

		$cron_key = 'trustedlogin/' . $this->config['vendor']['namespace'] . '/access/revoke';

		$this->assertArrayHasKey( $expiry, $crons );
		$this->assertArrayHasKey( $cron_key, $crons[ $expiry ] );
		$this->assertArrayHasKey( $cron_id, $crons[ $expiry ][ $cron_key ] );
		$this->assertSame( $hash_md5, $crons[ $expiry ][ $cron_key ][ $cron_id ]['args'][0] );
	}

	/**
	 * Reset the roles to default WordPress roles
	 */
	private function _reset_roles() {
		global $wp_roles;

		$wp_roles = new WP_Roles();
	}
}

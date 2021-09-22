<?php
/**
 * Class TrustedLoginAPITest
 *
 * @package Trustedlogin_Button
 */

/**
 * Sample test case.
 * @group ajax
 */
class TrustedLoginAJAXTest extends WP_Ajax_UnitTestCase {

	/**
	 * @var \TrustedLogin\Client
	 */
	private $TrustedLogin;

	/**
	 * @var ReflectionClass
	 */
	private $TrustedLoginReflection;

	/**
	 * @var \TrustedLogin\Config
	 */
	private $config;

	/**
	 * @var \TrustedLogin\Logging
	 */
	private $logging;

	/**
	 * @var int Get around Travis being annoying
	 */
	private $_real_error_level;

	public function setUp() {

		$this->_real_error_level = error_reporting();

		// Don't show errors at all while setting up WP_Ajax_UnitTestCase
		error_reporting( 0 );

		parent::setUp();

		$config = array(
			'role' => 'editor',
			'caps'     => array(
				'add' => array(
					'manage_options' => 'we need this to make things work real gud',
					'edit_posts'     => 'Access the posts that you created',
				),
			),
			'webhook_url'    => 'https://www.trustedlogin.com/webhook-example/',
			'auth'           => array(
				'api_key'   => '9946ca31be6aa948', // Public key for encrypting the securedKey
				'license_key' => 'my custom key',
			),
			'decay'          => WEEK_IN_SECONDS,
			'vendor'         => array(
				'namespace'   => 'gravityview',
				'title'       => 'GravityView',
				'email'       => 'support@gravityview.co',
				'website'     => 'https://gravityview.co',
				'support_url' => 'https://gravityview.co/support/', // Backup to redirect users if TL is down/etc
				'logo_url'    => '', // Displayed in the authentication modal
			),
			'reassign_posts' => true,
		);

		$this->config = new TrustedLogin\Config( $config );

		$this->option_keys = new \TrustedLogin\OptionKeys( $this->config );

		$this->TrustedLogin = new \TrustedLogin\Client( $this->config );

		$this->TrustedLoginReflection = new ReflectionClass( '\TrustedLogin\Client' );

		$this->logging = new \TrustedLogin\Logging( $this->config );

		$this->endpoint = new \TrustedLogin\Endpoint( $this->config, $this->logging );

	}

	public function tearDown() {

		parent::tearDown();

		error_reporting( $this->_real_error_level );

		$this->_delete_all_support_users();
	}

	/**
	 * Set a valid "tl_nonce-{user_id}" $_POST['_nonce'] value
	 * @see GravityView_Ajax::check_ajax_nonce()
	 */
	function _set_nonce( $user_id = null ) {

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$_POST['_nonce'] = wp_create_nonce( 'tl_nonce-' . $user_id );
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
	 * @covers TrustedLogin::ajax_generate_support
	 */
	function test_ajax_generate_support() {

		$this->_delete_all_support_users();

		$this->_setRole('administrator' );
		$current_user = wp_get_current_user();
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $current_user->ID );
		}

		unset( $_POST['vendor'] );
		$this->_catchHandleAjax();
		$this->assertContains( 'Vendor not defined', $this->_last_response );
		$this->_last_response = '';

		$_POST['vendor'] = 'asdasd';
		$this->_catchHandleAjax();
		$this->assertContains( 'Vendor does not match.', $this->_last_response, 'Vendor does not match config vendor.' );
		$this->_last_response = '';

		$_POST['vendor'] = $this->config->ns();
		$this->_catchHandleAjax();
		$this->assertContains( 'Nonce not sent', $this->_last_response );
		$this->_last_response = '';

		$_POST['vendor'] = $this->config->ns();
		$this->_set_nonce( 0 );
		$this->_catchHandleAjax();
		$this->assertContains( 'Verification issue', $this->_last_response, 'Nonce set to 0; should not validate.' );
		$this->_set_nonce();
		$this->_last_response = '';
		$this->_delete_all_support_users();

		$this->_setRole('subscriber' );
		$this->_set_nonce();
		$this->_catchHandleAjax();
		$this->assertContains( 'Permissions issue', $this->_last_response, 'User should not have permission to create users.' );
		$this->_last_response = '';
		$this->_delete_all_support_users();

		/**
		 * Create conflicting user name and try to create the user with the same username.
		 * Just wanting to make sure this step is tested, but:
		 * @see TrustedLoginUsersTest::test_create_support_user for full testing
		 */
		$user_name = sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) );
		$existing_user = $this->factory->user->create_and_get( array( 'user_login' => $user_name ) );
		$this->assertTrue( is_a( $existing_user, 'WP_User' ) );
		$this->_setRole( 'administrator' );
		$current_user = wp_get_current_user();
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $current_user->ID );
		}
		$this->_set_nonce();
		$this->_catchHandleAjax();
		$this->assertContains( 'already exists', $this->_last_response, sprintf( 'User %d should already have been created', $existing_user->ID ) );
		$this->_last_response = '';
		$this->assertTrue( wp_delete_user( $existing_user->ID ) ); // Cleanup
		$this->_delete_all_support_users();

		$this->_last_response = '';

		// Cause support_user_setup() to fail to trigger an error.
		add_filter( 'get_user_option_tl_gravityview_id', '__return_null' );

		$this->_set_nonce();
		$this->_catchHandleAjax();
		$this->assertContains( 'Error updating user', $this->_last_response, 'When support_user_setup() returns an error. Dump of $_REQUEST: ' . print_r( $_REQUEST, true ) );
		$this->_last_response = '';

		remove_filter( 'get_user_option_tl_gravityview_id', '__return_null' );
		$this->_delete_all_support_users();


		/**
		 * It doesn't matter if create_access() fails now, since we have properly checked everything else.
		 * Now we just want to make sure the return data array is correct
		 */
		$this->_catchHandleAjax();

		$last_response = $this->_last_response;

		$this->assertNotEquals( '', $last_response, 'Last response was empty - likely returning something instead of exiting with die() or wp_send_json_{status}' );

		$json = json_decode( $last_response, true );

		$this->assertTrue( is_array( $json ) );

		$this->assertArrayHasKey( 'success', $json );

		$this->assertTrue( $json['success'], 'JSON was not successfully fetched. There was an error: ' . print_r( $json, true ) );

		$this->assertArrayHasKey( 'data', $json );

		$data = $json['data'];

		$this->assertArrayHasKey( 'site_url', $data );
		$this->assertArrayHasKey( 'endpoint', $data );
		$this->assertArrayHasKey( 'identifier', $data );
		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertArrayHasKey( 'expiry', $data );
		$this->assertArrayHasKey( 'is_ssl', $data );
		$this->assertEquals( is_ssl(), $data['is_ssl'] );

		$this->_delete_all_support_users();
	}

	function _delete_all_support_users() {

		$support_user = new \TrustedLogin\SupportUser( $this->config, $this->logging );

		$users = $support_user->get_all();

		foreach ( $users as $user ) {
			wp_delete_user( $user->ID );
		}

		$user = get_user_by( 'email', $this->config->get_setting( 'vendor/email' ) );

		if( $user ) {
			wp_delete_user( $user->ID );
		}

		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix .'usermeta WHERE 1=1' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix .'users WHERE 1=1' );
	}

	/**
	 * Sets `_last_response` property
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	function _catchHandleAjax( $action = 'tl_%s_gen_support' ) {

		$action = sprintf( $action, $this->config->ns() );

		try {
			$this->_handleAjax( $action );
		} catch ( Exception $e ) {

		}
	}
}

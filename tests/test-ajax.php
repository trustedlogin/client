<?php
/**
 * Class TrustedLoginAPITest
 *
 * @package Trustedlogin_Button
 */

/**
 * Sample test case.
 *
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

	/**
	 * @var callable|null pre_http_request filter that stubs the
	 * vendor pubkey + SaaS sites endpoints so create_access can
	 * run end-to-end in PHPUnit.
	 */
	private $_http_stub;

	public function setUp(): void {

		$this->_real_error_level = error_reporting();

		// Don't show errors at all while setting up WP_Ajax_UnitTestCase
		error_reporting( 0 );

		parent::setUp();

		$config = array(
			'role'           => 'editor',
			'caps'           => array(
				'add' => array(
					'manage_options' => 'we need this to make things work real gud',
					'edit_posts'     => 'Access the posts that you created',
				),
			),
			'webhook_url'    => 'https://www.trustedlogin.com/webhook-example/',
			'auth'           => array(
				'api_key'   => '3b3dc46c0714cc8e', // Public key for encrypting the securedKey
				'license_key' => 'my custom key',
			),
			'decay'          => WEEK_IN_SECONDS,
			'vendor'         => array(
				'namespace'   => 'gravityview',
				'title'       => 'GravityView',
				'email'       => 'support@gravityview.co',
				'website'     => 'https://www.gravitykit.com',
				'support_url' => 'https://www.gravitykit.com/support/', // Backup to redirect users if TL is down/etc
				'logo_url'    => '', // Displayed in the authentication modal
			),
			'reassign_posts' => true,
		);

		$this->config = new TrustedLogin\Config( $config );

		$this->TrustedLogin = new \TrustedLogin\Client( $this->config );

		$this->TrustedLoginReflection = new ReflectionClass( '\TrustedLogin\Client' );

		$this->logging = new \TrustedLogin\Logging( $this->config );

		$this->endpoint = new \TrustedLogin\Endpoint( $this->config, $this->logging );

		// The "success" branch of test_ajax_generate_support runs
		// create_access(), which fans out to two HTTPS calls:
		// 1) the vendor pubkey endpoint at vendor.website
		// 2) the TrustedLogin SaaS at app.trustedlogin.com/api/v1/sites
		// Without these stubs the calls hit the live internet and a
		// 503/404/redirect tanks the response with a WP_Error.
		$this->_http_stub = static function ( $preempt, $args, $url ) {
			$url_string = (string) $url;

			if ( false !== strpos( $url_string, 'public_key' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode(
						array( 'publicKey' => 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899' )
					),
					'headers'  => array( 'content-type' => 'application/json' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			if ( false !== strpos( $url_string, '/api/v1/sites' ) ) {
				return array(
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'body'     => '{"success":true,"siteId":"abcd"}',
					'headers'  => array( 'content-type' => 'application/json' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return $preempt;
		};
		add_filter( 'pre_http_request', $this->_http_stub, 9, 3 );
	}

	public function tearDown(): void {

		if ( isset( $this->_http_stub ) ) {
			remove_filter( 'pre_http_request', $this->_http_stub, 9 );
			$this->_http_stub = null;
		}

		parent::tearDown();

		error_reporting( $this->_real_error_level );

		$this->_delete_all_support_users();
	}

	/**
	 * Set a valid "tl_nonce-{user_id}" $_POST['_nonce'] value
	 *
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

		$this->_setRole( 'administrator' );
		$current_user = wp_get_current_user();
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $current_user->ID );
		}

		unset( $_POST['vendor'] );
		$this->_catchHandleAjax();
		$this->assertMatchesRegularExpression( '/Vendor not defined/', $this->_last_response );
		$this->_last_response = '';

		$_POST['vendor'] = 'asdasd';
		$this->_catchHandleAjax();
		$this->assertMatchesRegularExpression( '/Vendor does not match\./', $this->_last_response, 'Vendor does not match config vendor.' );
		$this->_last_response = '';

		$_POST['vendor'] = $this->config->ns();
		$this->_catchHandleAjax();
		$this->assertMatchesRegularExpression( '/Nonce not sent/', $this->_last_response );
		$this->_last_response = '';

		$_POST['vendor'] = $this->config->ns();
		$this->_set_nonce( 0 );
		$this->_catchHandleAjax();
		$this->assertMatchesRegularExpression( '/Verification issue/', $this->_last_response, 'Nonce set to 0; should not validate.' );
		$this->_set_nonce();
		$this->_last_response = '';
		$this->_delete_all_support_users();

		$this->_setRole( 'subscriber' );
		$this->_set_nonce();
		$this->_catchHandleAjax();
		$this->assertMatchesRegularExpression( '/You do not have the ability to create users\./', $this->_last_response, 'User should not have permission to create users.' );
		$this->_last_response = '';
		$this->_delete_all_support_users();

		// Force fail on SSL check.
		add_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_false' );
		$this->_last_response = '';
		$this->_setRole('administrator' );
		$this->_set_nonce();
		$this->_catchHandleAjax();
		// Match the exact phrasing emitted by Client.php at the
		// fails_ssl_requirement branch. The message was updated to
		// be more agent-readable; this assertion was never resynced.
		$this->assertMatchesRegularExpression(
			'/Support access requires a secure \(HTTPS\) connection/',
			$this->_last_response,
			'When support_user_setup() returns an error. Dump of $_REQUEST: ' . print_r( $_REQUEST, true )
		);
		$this->_delete_all_support_users();
		remove_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_false' );


		// Force no check check for SSL.
		add_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_true' );

		$this->_last_response = '';

		// Cause support_user_setup() to fail to trigger an error.
		add_filter( 'get_user_option_tl_gravityview_id', '__return_null' );

		$this->_set_nonce();
		$this->_catchHandleAjax();
		$this->assertMatchesRegularExpression( '/Error updating user/', $this->_last_response, 'When support_user_setup() returns an error. Dump of $_REQUEST: ' . print_r( $_REQUEST, true ) );
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
		$this->assertArrayHasKey( 'reference_id', $data );
		$this->assertArrayHasKey( 'timing', $data );
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

		if ( $user ) {
			wp_delete_user( $user->ID );
		}

		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'usermeta WHERE 1=1' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'users WHERE 1=1' );
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

	/**
	 * Sets the role and also adds super-admin.
	 * @inheritDoc
	 */
	function _setRole( $role ) {
		parent::_setRole( $role );
		if ( 'administrator' === $role && function_exists( 'grant_super_admin' ) ) {
			$current_user = wp_get_current_user();
			grant_super_admin( $current_user->ID );
		}
	}
}

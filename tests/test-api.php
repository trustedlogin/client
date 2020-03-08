<?php
/**
 * Class TrustedLoginAPITest
 *
 * @package Trustedlogin_Button
 */

/**
 * Sample test case.
 */
class TrustedLoginAPITest extends WP_UnitTestCase {

	/**
	 * @var TrustedLogin
	 */
	private $TrustedLogin;

	/**
	 * @var ReflectionClass
	 */
	private $TrustedLoginReflection;

	/**
	 * @var array
	 */
	private $config;

	public function setUp() {

		parent::setUp();

		$this->config = array(
			'role'           => array(
				'editor' => 'Support needs to be able to access your site as an administrator to debug issues effectively.',
			),
			'extra_caps'     => array(
				'manage_options' => 'we need this to make things work real gud',
				'edit_posts'     => 'Access the posts that you created',
				'delete_users'   => 'In order to manage the users that we thought you would want us to.',
			),
			'webhook_url'    => 'https://www.trustedlogin.com/webhook-example/',
			'auth'           => array(
				'public_key'  => '9946ca31be6aa948', // Public key for encrypting the securedKey
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

		$this->TrustedLogin = new TrustedLogin\Client( $this->config );

		$this->TrustedLoginReflection = new ReflectionClass( '\TrustedLogin\Client' );
	}

	public function tearDown() {
		parent::tearDown();
	}

	/**
	 * @covers \TrustedLogin\Client::__construct
	 */
	public function test_constructor() {

		$expected_codes = array(
			1 => 'empty configuration array',
			2 => 'replace default namespace',
			3 => 'invalid configuration array',
		);

		try {
			new TrustedLogin\Client( array() );
		} catch ( \Exception $exception ) {
			$this->assertEquals( 1, $exception->getCode(), $expected_codes[1] );
		}

		try {
			new TrustedLogin\Client( array(
				'vendor' => true
			) );
		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] . ' ' .$exception->getMessage() );
			$this->assertContains( 'public key', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/namespace', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/title', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/email', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/website', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/support_url', $exception->getMessage(), $expected_codes[3] );
		}

		try {
			new TrustedLogin\Client( array(
				'vendor' => true,
				'auth' => array( 'public_key' => 'asdasd' ),
			) );
		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] );
			$this->assertNotContains( 'public key', $exception->getMessage(), $expected_codes[3] );
		}

		$valid_config = array(
			'auth' => array(
				'public_key' => 'not empty',
			),
			'webhook_url' => 'https://www.google.com',
			'vendor' => array(
				'namespace' => 'jonesbeach',
				'title' => 'Jones Beach Party',
				'first_name' => null,
				'last_name' => '',
				'email' => 'beach@example.com',
				'website' => 'https://example.com',
				'support_url' => 'https://example.com',
			),
		);


		try {
			$missing_namespace = $valid_config;
			unset( $missing_namespace['vendor']['namespace'] );
			new TrustedLogin\Client( $missing_namespace );
		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] );
			$this->assertContains( 'vendor/namespace', $exception->getMessage(), $expected_codes[3] );
			$this->assertNotContains( 'public key', $exception->getMessage(), $expected_codes[3] );
			$this->assertNotContains( 'vendor/title', $exception->getMessage(), $expected_codes[3] );
			$this->assertNotContains( 'vendor/email', $exception->getMessage(), $expected_codes[3] );
			$this->assertNotContains( 'vendor/website', $exception->getMessage(), $expected_codes[3] );
			$this->assertNotContains( 'vendor/support_url', $exception->getMessage(), $expected_codes[3] );
		}

		try {
			$invalid_website_url = $valid_config;
			$invalid_website_url['webhook_url'] = 'asdasdsd';
			$invalid_website_url['vendor']['support_url'] = 'asdasdsd';
			$invalid_website_url['vendor']['website'] = 'asdasdsd';
			new TrustedLogin\Client( $invalid_website_url );
		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] );
			$this->assertContains( 'webhook_url', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/support_url', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/website', $exception->getMessage(), $expected_codes[3] );
		}
	}

	/**
	 * @param $name
	 *
	 * @return ReflectionMethod
	 * @throws ReflectionException
	 */
	private function _get_public_method( $name ) {

		$method = $this->TrustedLoginReflection->getMethod( $name );
		$method->setAccessible( true );

		return $method;
	}

	private function _get_public_property( $name ) {

		$prop = $this->TrustedLoginReflection->getProperty( $name );
		$prop->setAccessible( true );

		return $prop;
	}

	/**
	 * @covers TrustedLogin::get_license_key
	 */
	public function test_get_license_key() {

		$this->assertSame( $this->config['auth']['license_key'], $this->TrustedLogin->get_license_key() );

		add_filter( 'trustedlogin/' . $this->config['vendor']['namespace'] . '/licence_key', '__return_zero' );

		$this->assertSame( 0, $this->TrustedLogin->get_license_key() );

		remove_filter( 'trustedlogin/' . $this->config['vendor']['namespace'] . '/licence_key', '__return_zero' );

	}

	public function test_init_settings() {
		// TODO: Add checks for filters
	}

	/**
	 * @covers \TrustedLogin\Client::get_setting()
	 */
	public function test_get_setting() {

		$config = array(
			'auth' => array(
				'public_key' => 'not empty',
			),
			'webhook_url' => 'https://www.google.com',
			'vendor' => array(
				'namespace' => 'jones-party',
				'title' => 'Jones Beach Party',
				'first_name' => null,
				'last_name' => '',
				'email' => 'beach@example.com',
				'website' => 'https://example.com',
				'support_url' => 'https://asdasdsd.example.com/support/',
			),
		);

		$TL = new \TrustedLogin\Client( $config );

		$this->assertEquals( 'https://www.google.com', $TL->get_setting( 'webhook_url') );

		$this->assertEquals( 'Jones Beach Party', $TL->get_setting( 'vendor/title') );

		$this->assertFalse( $TL->get_setting( 'non-existent key') );

		$this->assertEquals( 'default override', $TL->get_setting( 'non-existent key', 'default override' ) );

		$this->assertFalse( $TL->get_setting( 'vendor/first_name' ), 'Should use method default value (false) when returned value is NULL' );

		$this->assertEquals( 'default override', $TL->get_setting( 'vendor/first_name', 'default override' ), 'should use default override if value is NULL' );

		$this->assertEquals( '', $TL->get_setting( 'vendor/last_name' ) );

		// Test passed array values
		$passed_array = array(
			'try' => 'and try again',
			'first' => array(
				'three_positive_integers' => 123,
			),
		);
		$this->assertEquals( 'and try again', $TL->get_setting( 'try', null, $passed_array ) );
		$this->assertEquals( null, $TL->get_setting( 'missssing', null, $passed_array ) );
		$this->assertEquals( '123', $TL->get_setting( 'first/three_positive_integers', null, $passed_array ) );
	}

	/**
	 * @covers TrustedLogin\Client::api_send()
	 */
	public function test_api_send() {

		$this->assertWPError( $this->TrustedLogin->api_send( 'any-path', 'any data', 'not supported http method' ) );

		// Make sure the body has been removed from methods that don't support it
		add_filter( 'http_request_args', $filter_args = function ( $parsed_args, $url ) {
			$this->assertNull( $parsed_args['body'] );
			return $parsed_args;
		}, 10, 2 );

		#$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', 'any data', 'get' ), 'The method failed to auto-uppercase methods.' );
		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', 'any data', 'GET' ) );
		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', 'any data', 'HEAD' ) );

		remove_filter( 'http_request_args', $filter_args );

		// Make sure that POST and DELETE are able to sent body and that the body is properly formatted
		add_filter( 'http_request_args', $filter_args = function ( $parsed_args, $url ) {
			$this->assertEquals( json_encode( array( 'test', 'array' ) ), $parsed_args['body'] );
			return $parsed_args;
		}, 10, 2 );

		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', array( 'test', 'array' ), 'POST' ) );
		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', array( 'test', 'array' ), 'DELETE' ) );

		remove_filter( 'http_request_args', $filter_args );
	}

	/**
	 * @throws ReflectionException
	 * @covers TrustedLogin\Client::build_api_url
	 */
	public function test_build_api_url() {

		$method = $this->_get_public_method( 'build_api_url' );

		$this->assertEquals( $this->TrustedLogin::saas_api_url, $method->invoke( $this->TrustedLogin ) );

		$this->assertEquals( $this->TrustedLogin::saas_api_url, $method->invoke( $this->TrustedLogin, array('not-a-string') ) );

		$this->assertEquals( $this->TrustedLogin::saas_api_url . 'pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		add_filter( 'trustedlogin/not-my-namespace/api-url', function () { return 'https://www.google.com'; } );

		$this->assertEquals( $this->TrustedLogin::saas_api_url . 'pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/not-my-namespace/api-url' );

		add_filter( 'trustedlogin/gravityview/api-url', function () { return 'https://www.google.com'; } );

		$this->assertEquals( 'https://www.google.com/pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/gravityview/api-url' );
	}

	/**
	 * @covers TrustedLogin::handle_response
	 */
	public function test_handle_response() {

		// Response is an error itself
		$WP_Error = new WP_Error( 'example', 'Testing 123' );
		$this->assertSame( $WP_Error, $this->TrustedLogin->handle_response( $WP_Error ) );

		// Missing body
		$this->assertWPError( $this->TrustedLogin->handle_response( array( 'body' => '' ) ) );
		$this->assertSame( 'missing_response_body', $this->TrustedLogin->handle_response( array( 'body' => '' ) )->get_error_code() );

		// Verify error response codes
		$error_codes = array(
			'unauthenticated'  => 401,
			'invalid_token'    => 403,
			'not_found'        => 404,
			'unavailable'      => 500,
			'invalid_response' => '',
		);

		foreach ( $error_codes as $error_code => $response_code ) {

			$invalid_code_response = array(
				'body'     => 'Not Empty',
				'response' => array(
					'code' => $response_code,
				),
			);

			$handled_response = $this->TrustedLogin->handle_response( $invalid_code_response );

			$this->assertWPError( $handled_response );
			$this->assertSame( $error_code, $handled_response->get_error_code(), $response_code . ' should have triggered ' . $error_code );
		}

		// Verify invalid JSON
		$invalid_json_response = array(
			'body'     => 'Not JSON, that is for sure.',
			'response' => array(
				'code' => 200,
			),
		);

		$handled_response = $this->TrustedLogin->handle_response( $invalid_json_response );

		$this->assertWPError( $handled_response );
		$this->assertSame( 'invalid_response', $handled_response->get_error_code(), $response_code . ' should have triggered ' . $error_code );
		$this->assertSame( 'Not JSON, that is for sure.', $handled_response->get_error_data( 'invalid_response' ) );


		// Finally, VALID JSON
		$valid_json_response = array(
			'body'     => '{"message":"This works"}',
			'response' => array(
				'code' => 200,
			),
		);

		$handled_response = $this->TrustedLogin->handle_response( $valid_json_response );
		$this->assertNotWPError( $handled_response );
		$this->assertSame( array( 'message' => 'This works' ), $handled_response );

		$handled_response = $this->TrustedLogin->handle_response( $valid_json_response, 'message' );
		$this->assertNotWPError( $handled_response );
		$this->assertSame( array( 'message' => 'This works' ), $handled_response );

		$handled_response = $this->TrustedLogin->handle_response( $valid_json_response, array( 'missing_key' ) );
		$this->assertWPError( $handled_response );
		$this->assertSame( 'missing_required_key', $handled_response->get_error_code() );
	}
}

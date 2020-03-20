<?php
/**
 * Class TrustedLoginClientTest
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use WP_Error;

/**
 * Override default function_exists() behavior
 * @see https://stackoverflow.com/a/34386422/480856
 *
 * @param $function
 *
 * @return bool
 */
function function_exists( $function ) {

	if ( in_array( $function, TrustedLoginClientTest::$functions_not_exist, true ) ) {
		return false;
	}

	return \function_exists( $function );
}

function openssl_random_pseudo_bytes($length, &$crypto_strong = null) {

	if ( ! TrustedLoginClientTest::$openssl_crypto_strong ) {
		$crypto_strong = false;
	}

	return \function_exists( $length );
}

class TrustedLoginClientTest extends WP_UnitTestCase {

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

	/**
	 * @var \TrustedLogin\Logging
	 */
	private $logging;

	public static $functions_not_exist = array();

	public static $openssl_crypto_strong = true;

	public function setUp() {

		parent::setUp();

		$config = array(
			'role' => 'editor',
			'caps'     => array(
				'add' => array(
					'manage_options' => 'we need this to make things work real gud',
					'edit_posts'     => 'Access the posts that you created',
					'delete_users'   => 'In order to manage the users that we thought you would want us to.',
				),
			),
			'webhook_url'    => 'https://www.example.com/endpoint/',
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

		$this->config = new Config( $config );

		$this->TrustedLogin = new Client( $this->config );

		$this->TrustedLoginReflection = new \ReflectionClass( '\TrustedLogin\Client' );

		$this->logging = $this->_get_public_property( 'logging' )->getValue( $this->TrustedLogin );
	}

	public function tearDown() {
		parent::tearDown();
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

		$this->assertSame( $this->config->get_setting( 'auth/license_key' ), $this->TrustedLogin->get_license_key() );

		add_filter( 'trustedlogin/' . $this->config->ns() . '/licence_key', '__return_zero' );

		$this->assertSame( 0, $this->TrustedLogin->get_license_key() );

		remove_filter( 'trustedlogin/' . $this->config->ns() . '/licence_key', '__return_zero' );

	}

	/**
	 * @covers \TrustedLogin\Client::generate_identifier_hash
	 * @uses \TrustedLogin\openssl_random_pseudo_bytes()
	 * @uses \TrustedLogin\function_exists()
	 */
	public function test_generate_identifier_hash() {

		self::$functions_not_exist = array( 'random_bytes', 'openssl_random_pseudo_bytes' );

		$hash = $this->TrustedLogin->generate_identifier_hash();
		$this->assertEquals( 'generate_hash_failed', $hash->get_error_code() );

		$this->assertWPError( $hash );

		// OpenSSL exists, but not strong crypto
		self::$functions_not_exist = array( 'random_bytes' );
		self::$openssl_crypto_strong = false;
		$hash = $this->TrustedLogin->generate_identifier_hash();

		$this->assertWPError( $hash );
		$this->assertEquals( 'openssl_not_strong_crypto', $hash->get_error_code() );

		self::$functions_not_exist = array();
		self::$openssl_crypto_strong = true;
		$hash = $this->TrustedLogin->generate_identifier_hash();

		$this->assertEquals( 64, strlen( $hash ), print_r( $hash, true ) );
	}

	/**
	 * @covers TrustedLogin\Client::api_send()
	 */
	public function test_api_send() {

		$this->assertWPError( $this->TrustedLogin->api_send( 'any-path', 'any data', 'not supported http method' ) );

		$that = &$this;

		// Make sure the body has been removed from methods that don't support it
		add_filter( 'http_request_args', $filter_args = function ( $parsed_args, $url ) use ( $that ) {
			$that->assertNull( $parsed_args['body'] );
			return $parsed_args;
		}, 10, 2 );

		unset( $that );


		$uppercase = $this->TrustedLogin->api_send( 'sites', 'any data', 'head' );

		// If this failed, it's for some network reason, not because of the reason we're testing.
		if ( is_wp_error( $uppercase ) ) {
			$this->assertNotEquals( 'invalid_method', $uppercase->get_error_code(), 'The method failed to auto-uppercase methods.' );
		}

		$head_request = $this->TrustedLogin->api_send( 'sites', 'any data', 'HEAD' );

		if ( is_wp_error( $head_request ) ) {
			$this->assertNotWPError( $head_request, $head_request->get_error_code() . ': ' . $head_request->get_error_message() );
		}

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

		$this->assertEquals( \TrustedLogin\Client::saas_api_url, $method->invoke( $this->TrustedLogin ) );

		$this->assertEquals( \TrustedLogin\Client::saas_api_url, $method->invoke( $this->TrustedLogin, array('not-a-string') ) );

		$this->assertEquals( \TrustedLogin\Client::saas_api_url . 'pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		add_filter( 'trustedlogin/not-my-namespace/api_url', function () { return 'https://www.google.com'; } );

		$this->assertEquals( \TrustedLogin\Client::saas_api_url . 'pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/not-my-namespace/api_url' );

		add_filter( 'trustedlogin/gravityview/api_url', function () { return 'https://www.google.com'; } );

		$this->assertEquals( 'https://www.google.com/pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/gravityview/api_url' );
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

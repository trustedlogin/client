<?php
/**
 * Class TrustedLoginClientTest
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use WP_Error;

class TrustedLoginRemoteTest extends WP_UnitTestCase {

	/**
	 * @var array
	 */
	private $config;

	/**
	 * @var Remote
	 */
	private $remote;

	public function setUp(): void {
		parent::setUp();

		$config = array(
			'role'           => 'editor',
			'caps'           => array(
				'add' => array(
					'manage_options' => 'we need this to make things work real gud',
					'edit_posts'     => 'Access the posts that you created',
				),
			),
			'webhook_url'    => 'https://www.example.com/endpoint/',
			'auth'           => array(
				'api_key'     => '9946ca31be6aa948', // Public key for encrypting the securedKey
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

		$this->remote = new Remote( $this->config, new Logging( $this->config ) );
	}

	/**
	 * @param string $name Method to set to accessible
	 * @param string $reflection_class Class to reflect
	 *
	 * @return \ReflectionMethod
	 * @throws \ReflectionException
	 */
	private function _get_public_method( $name, $reflection_class = '\TrustedLogin\Remote' ) {

		$Reflection = new \ReflectionClass( $reflection_class );
		$method     = $Reflection->getMethod( $name );
		$method->setAccessible( true );

		return $method;
	}

	/**
	 * @param string $name Property to set to accessible
	 * @param string $reflection_class Class to reflect
	 *
	 * @return \ReflectionProperty
	 * @throws \ReflectionException
	 */
	private function _get_public_property( $name, $reflection_class = '\TrustedLogin\Remote' ) {

		$Reflection = new \ReflectionClass( $reflection_class );
		$prop       = $Reflection->getProperty( $name );
		$prop->setAccessible( true );

		return $prop;
	}

	/**
	 * @covers \TrustedLogin\Remote::send()
	 */
	public function test_api_send() {

		$this->assertWPError( $this->remote->send( 'any-path', 'any data', 'not supported http method' ) );

		$that = &$this;

		// Make sure the body has been removed from methods that don't support it
		add_filter(
			'http_request_args',
			$filter_args = function ( $parsed_args, $url ) use ( $that ) {
				$that->assertNull( $parsed_args['body'] );
				return $parsed_args;
			},
			10,
			2
		);

		unset( $that );

		$uppercase = $this->remote->send( 'sites', 'any data', 'head' );

		// If this failed, it's for some network reason, not because of the reason we're testing.
		if ( is_wp_error( $uppercase ) ) {
			$this->assertNotEquals( 'invalid_method', $uppercase->get_error_code(), 'The method failed to auto-uppercase methods.' );
		}

		$head_request = $this->remote->send( 'sites', 'any data', 'HEAD' );

		if ( is_wp_error( $head_request ) ) {
			$this->assertNotWPError( $head_request, $head_request->get_error_code() . ': ' . $head_request->get_error_message() );
		}

		remove_filter( 'http_request_args', $filter_args );

		// Make sure that POST and DELETE are able to sent body and that the body is properly formatted
		add_filter(
			'http_request_args',
			$filter_args = function ( $parsed_args, $url ) {
				$this->assertEquals( json_encode( array( 'test', 'array' ) ), $parsed_args['body'] );
				return $parsed_args;
			},
			10,
			2
		);

		$this->assertNotWPError( $this->remote->send( 'sites', array( 'test', 'array' ), 'POST' ) );
		$this->assertNotWPError( $this->remote->send( 'sites', array( 'test', 'array' ), 'DELETE' ) );

		remove_filter( 'http_request_args', $filter_args );
	}

	/**
	 * @throws \ReflectionException
	 * @covers \TrustedLogin\Remote::build_api_url
	 */
	public function test_build_api_url() {

		$method = $this->_get_public_method( 'build_api_url' );

		$this->assertEquals( \TrustedLogin\Remote::API_URL, $method->invoke( $this->remote ) );

		$this->assertEquals( \TrustedLogin\Remote::API_URL . 'pathy-path', $method->invoke( $this->remote, 'pathy-path' ) );

		add_filter(
			'trustedlogin/not-my-namespace/api_url',
			function () {
				return 'https://www.google.com';
			}
		);

		$this->assertEquals( \TrustedLogin\Remote::API_URL . 'pathy-path', $method->invoke( $this->remote, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/not-my-namespace/api_url' );

		add_filter(
			'trustedlogin/gravityview/api_url',
			function () {
				return 'https://www.google.com';
			}
		);

		$this->assertEquals( 'https://www.google.com/pathy-path', $method->invoke( $this->remote, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/gravityview/api_url' );

		try {
			$this->assertEquals( \TrustedLogin\Remote::API_URL, $method->invoke( $this->remote, array( 'not-a-string' ) ) );
		} catch ( \Exception $e ) {
			$this->assertEquals( 'Endpoint must be a string.', $e->getMessage() );
			$this->assertEquals( 400, $e->getCode() );
		}
	}

	/**
	 * @covers \TrustedLogin\Remote::handle_response()
	 */
	public function test_handle_response() {

		// No JSON at all.
		$this->assertWPError( $this->remote->handle_response( array( 'body' => '' ) ) );
		$this->assertSame( 'invalid_response', $this->remote->handle_response( array( 'body' => '' ) )->get_error_code() );

		// Missing JSON response body.
		$this->assertWPError( $this->remote->handle_response( array( 'body' => '{ example: "JSON" }' ) ) );
		$this->assertSame( 'missing_response_body', $this->remote->handle_response( array( 'response' => array( 'code' => 200 ), 'body' => '' ) )->get_error_code() );

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

			$handled_response = $this->remote->handle_response( $invalid_code_response );

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

		$handled_response = $this->remote->handle_response( $invalid_json_response );

		$this->assertWPError( $handled_response );
		$this->assertSame( 'invalid_response', $handled_response->get_error_code(), $response_code . ' should have triggered ' . $error_code );
		$this->assertSame( $invalid_json_response, $handled_response->get_error_data( 'invalid_response' ) );

		// Finally, VALID JSON
		$valid_json_response = array(
			'body'     => '{"message":"This works"}',
			'response' => array(
				'code' => 200,
			),
		);

		$handled_response = $this->remote->handle_response( $valid_json_response );
		$this->assertNotWPError( $handled_response );
		$this->assertSame( array( 'message' => 'This works' ), $handled_response );

		$handled_response = $this->remote->handle_response( $valid_json_response, 'message' );
		$this->assertNotWPError( $handled_response );
		$this->assertSame( array( 'message' => 'This works' ), $handled_response );

		$handled_response = $this->remote->handle_response( $valid_json_response, array( 'missing_key' ) );
		$this->assertWPError( $handled_response );
		$this->assertSame( 'missing_required_key', $handled_response->get_error_code() );
	}

	/**
	 * @covers \TrustedLogin\Remote::body_looks_like_html
	 *
	 * Data-driven: covers the shapes a hosting firewall / CDN returns in
	 * the real world (Cloudflare 415 HTML, Wordfence block page, nginx
	 * 502 Bad Gateway, bare `<html>` tag, etc.) plus negative cases
	 * (JSON, empty body, plain text, JSON string value containing HTML).
	 *
	 * Motivated by the 150+ "trustedlogin - change to admin" support
	 * tickets where the customer's vendor site returned HTML and the
	 * Client silently converted that into "Invalid response. Missing
	 * key: publicKey". The detector needs to catch all the real-world
	 * shapes without false-positiving on legitimate JSON.
	 */
	public function test_body_looks_like_html_detects_firewall_shapes() {
		$positive_cases = array(
			'cloudflare_415'      => "<!DOCTYPE html>\n<html><head><title>415</title></head><body>Blocked</body></html>",
			'wordfence_403'       => "<!DOCTYPE html>\n<html><head><title>Forbidden - Wordfence</title></head><body><h1>Your access to this site has been limited</h1></body></html>",
			'nginx_502'           => "<html>\n<head><title>502 Bad Gateway</title></head>\n<body><center><h1>502 Bad Gateway</h1></center></body></html>",
			'mixed_case_doctype'  => "<!doctype html><html>foo</html>",
			'leading_whitespace'  => "  \n  <!DOCTYPE html><html></html>",
			'just_html_tag'       => "<html></html>",
			'just_head_tag'       => "<head></head>",
			'just_body_tag'       => "<body>blocked</body>",
		);

		foreach ( $positive_cases as $label => $body ) {
			$this->assertTrue(
				Remote::body_looks_like_html( $body ),
				"Expected '$label' to be detected as HTML"
			);
		}

		$negative_cases = array(
			'empty_string'         => '',
			'whitespace_only'      => "   \n   ",
			'plain_json_object'    => '{"publicKey":"abc123"}',
			'plain_json_array'     => '[1,2,3]',
			'plain_text'           => 'Could not connect',
			'json_containing_html' => '{"message":"<html>not a real document</html>"}',
			'xml'                  => '<?xml version="1.0"?><root/>',
			'json_leading_space'   => '   {"ok":true}',
			'html_fragment_no_tag' => 'This is <div>a fragment</div> inside text',
		);

		foreach ( $negative_cases as $label => $body ) {
			$this->assertFalse(
				Remote::body_looks_like_html( $body ),
				"Expected '$label' to NOT be detected as HTML"
			);
		}
	}

	/**
	 * @covers \TrustedLogin\Remote::handle_response
	 *
	 * When the vendor site responds with HTML (regardless of HTTP
	 * status), handle_response should return a `vendor_response_not_json`
	 * WP_Error whose message mentions "firewall" and includes the HTTP
	 * status. The customer-friendly copy is specifically the thing
	 * motivated by the field tickets; regressing it would reintroduce
	 * the "Invalid response. Missing key: publicKey" surface.
	 */
	public function test_handle_response_converts_html_body_to_firewall_error() {
		$html_415 = array(
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => "<!DOCTYPE html>\n<html><title>Cloudflare 415</title></html>",
			'response' => array( 'code' => 415, 'message' => 'Unsupported Media Type' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$result = $this->remote->handle_response( $html_415, array( 'publicKey' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'vendor_response_not_json', $result->get_error_code() );
		$this->assertStringContainsString( 'firewall', strtolower( $result->get_error_message() ) );
		$this->assertStringContainsString( '415', $result->get_error_message() );

		// Must never leak the raw HTML into the customer-facing message.
		$this->assertStringNotContainsString( '<html>', $result->get_error_message() );
		$this->assertStringNotContainsString( 'DOCTYPE', $result->get_error_message() );

		// Must not leak internal jargon to the customer.
		$this->assertStringNotContainsStringIgnoringCase( 'publickey', $result->get_error_message() );
		$this->assertStringNotContainsStringIgnoringCase( 'trustedlogin', $result->get_error_message() );

		// Error data preserves the status + a sanitized preview for logs.
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 415, $data['status'] );
	}

	/**
	 * @covers \TrustedLogin\Remote::handle_response
	 *
	 * JSON 200 without the required `publicKey` key should produce the
	 * `missing_public_key` code (not the pre-fix generic
	 * `missing_required_key`) with customer-friendly copy — the vendor's
	 * Connector is misconfigured and the customer can't fix it.
	 */
	public function test_handle_response_missing_publickey_is_specific() {
		$json_no_key = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '{"somethingElse":"nope"}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$result = $this->remote->handle_response( $json_no_key, array( 'publicKey' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_public_key', $result->get_error_code() );
		$this->assertStringContainsString( 'support team', strtolower( $result->get_error_message() ) );
		$this->assertStringNotContainsStringIgnoringCase( 'publickey', $result->get_error_message() );
		$this->assertStringNotContainsStringIgnoringCase( 'trustedlogin', $result->get_error_message() );
	}

	/**
	 * @covers \TrustedLogin\Remote::handle_response
	 *
	 * JSON 200 with empty-string `publicKey` is treated the same as
	 * missing — an empty key can't be used to encrypt anything.
	 */
	public function test_handle_response_empty_publickey_is_specific() {
		$json_empty_key = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '{"publicKey":""}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$result = $this->remote->handle_response( $json_empty_key, array( 'publicKey' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_public_key', $result->get_error_code() );
	}

	/**
	 * @covers \TrustedLogin\Remote::check_response_code
	 *
	 * Unmapped status codes used to fall through to a silent `return (int)`
	 * which threw away the HTTP status. That's how the Cloudflare 415
	 * tickets got "Invalid response." with no context. Now the default
	 * branch returns a WP_Error that includes the status number.
	 */
	public function test_check_response_code_preserves_unmapped_status() {
		$response_415 = array(
			'headers'  => array(),
			'body'     => '{"ok":false}',
			'response' => array( 'code' => 415, 'message' => 'Unsupported Media Type' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$result = Remote::check_response_code( $response_415 );

		$this->assertWPError( $result );
		$this->assertSame( 'unexpected_response_code', $result->get_error_code() );
		$this->assertStringContainsString( '415', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertSame( 415, $data['status'] );
	}

	/**
	 * @covers \TrustedLogin\Remote::check_response_code
	 *
	 * 2xx codes without an explicit case should still return the int
	 * status (legacy contract — callers treat that as success).
	 */
	public function test_check_response_code_passes_through_unmapped_2xx() {
		$response_206 = array(
			'headers'  => array(),
			'body'     => 'chunk',
			'response' => array( 'code' => 206, 'message' => 'Partial Content' ),
			'cookies'  => array(),
			'filename' => null,
		);

		$this->assertSame( 206, Remote::check_response_code( $response_206 ) );
	}
}

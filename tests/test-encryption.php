<?php
/**
 * Class TrustedLoginEncryptionTest
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

	if ( in_array( $function, TrustedLoginEncryptionTest::$functions_not_exist, true ) ) {
		return false;
	}

	return \function_exists( $function );
}

class TrustedLoginEncryptionTest extends WP_UnitTestCase {

	/**
	 * @var \TrustedLogin\Client
	 */
	private $TrustedLogin;

	/**
	 * @var \ReflectionClass
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

	/**
	 * @var Encryption
	 */
	private $encryption;

	public static $functions_not_exist = array();

	public function setUp() {

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
				'api_key'  => '9946ca31be6aa948', // Public key for encrypting the securedKey
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

		$this->logging = new Logging( $this->config );
		$this->remote = new Remote( $this->config, $this->logging );

		$this->encryption = new Encryption( $this->config, $this->remote, $this->logging );
	}

	public function tearDown() {
		parent::tearDown();
	}

	/**
	 * @param $name
	 *
	 * @return \ReflectionMethod
	 * @throws \ReflectionException
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
	 * @covers \TrustedLogin\Encryption::generate_keys
	 */
	public function test_generate_keys() {

		self::$functions_not_exist = array( 'sodium_crypto_box_keypair' );

		$error = $this->encryption->generate_keys();

		$this->assertWPError( $error );
		$this->assertEquals( 'sodium_crypto_secretbox_not_available', $error->get_error_code() );

		// Now, functions exist again
		self::$functions_not_exist = array();

		$keys = $this->encryption->generate_keys();

		$this->assertNotWPError( $keys );
		$this->assertTrue( is_object( $keys ) );
		$this->assertTrue( isset( $keys->publicKey ) );
		$this->assertEquals( 32, strlen( $keys->publicKey ) );

		$this->assertTrue( isset( $keys->privateKey ) );
		$this->assertEquals( 32, strlen( $keys->privateKey ) );
	}

	/**
	 * @covers \TrustedLogin\Encryption::get_nonce
	 */
	public function test_get_nonce() {

		self::$functions_not_exist = array( 'random_bytes' );

		$error = $this->encryption->get_nonce();

		$this->assertWPError( $error );
		$this->assertEquals( 'missing_function', $error->get_error_code() );

		self::$functions_not_exist = array();

		$nonce = $this->encryption->get_nonce();

		$this->assertEquals( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen( $nonce ) );
	}
}

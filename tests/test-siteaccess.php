<?php
/**
 * Class TrustedLoginClientTest
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use \WP_UnitTestCase;
use \WP_Error;

/**
 * Override default function_exists() behavior
 * @see https://stackoverflow.com/a/34386422/480856
 *
 * @param $function
 *
 * @return bool
 */
function function_exists( $function ) {

	if ( in_array( $function, TrustedLoginSiteAccessTest::$functions_not_exist, true ) ) {
		return false;
	}

	return \function_exists( $function );
}

function openssl_random_pseudo_bytes($length, &$crypto_strong = null) {

	if ( ! TrustedLoginSiteAccessTest::$openssl_crypto_strong ) {
		$crypto_strong = false;
	}

	return \function_exists( $length );
}

class TrustedLoginSiteAccessTest extends WP_UnitTestCase {

	/**
	 * @var SiteAccess $site_access
	 */
	private $site_access;

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

		$config = new Config( $config );
		$logging = new Logging( $config );

		$this->site_access = new \TrustedLogin\SiteAccess( $config, $logging );
	}

	/**
	 * @covers \TrustedLogin\Client::create_hash
	 * @uses \TrustedLogin\openssl_random_pseudo_bytes()
	 * @uses \TrustedLogin\function_exists()
	 */
	public function test_create_hash() {

		self::$functions_not_exist = array( 'random_bytes', 'openssl_random_pseudo_bytes' );

		$hash = $this->site_access->create_hash();
		$this->assertEquals( 'generate_hash_failed', $hash->get_error_code() );

		$this->assertWPError( $hash );

		// OpenSSL exists, but not strong crypto
		self::$functions_not_exist = array( 'random_bytes' );
		self::$openssl_crypto_strong = false;
		$hash = $this->site_access->create_hash();

		$this->assertWPError( $hash );
		$this->assertEquals( 'openssl_not_strong_crypto', $hash->get_error_code() );

		self::$functions_not_exist = array();
		self::$openssl_crypto_strong = true;
		$hash = $this->site_access->create_hash();

		$this->assertEquals( 64, strlen( $hash ), print_r( $hash, true ) );
	}
}

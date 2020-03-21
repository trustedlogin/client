<?php
/**
 * Class TrustedLoginClientTest
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use WP_Error;

class TrustedLoginClientTest extends WP_UnitTestCase {

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
	 * @covers TrustedLogin::get_license_key
	 */
	public function test_get_license_key() {

		$site_access = $this->_get_public_property( 'site_access' )->getValue( $this->TrustedLogin );

		$this->assertSame( $this->config->get_setting( 'auth/license_key' ), $site_access->get_license_key() );

		add_filter( 'trustedlogin/' . $this->config->ns() . '/licence_key', '__return_zero' );

		$this->assertSame( 0, $site_access->get_license_key() );

		remove_filter( 'trustedlogin/' . $this->config->ns() . '/licence_key', '__return_zero' );

	}

}

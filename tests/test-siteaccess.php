<?php
/**
 * Class TrustedLoginClientTest
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use \WP_UnitTestCase;
use \WP_Error;

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

		$config = new Config( $config );
		$logging = new Logging( $config );

		$this->site_access = new \TrustedLogin\SiteAccess( $config, $logging );
	}
}

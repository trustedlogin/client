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

		$this->TrustedLogin = new Client( $this->config );

		$this->TrustedLoginReflection = new \ReflectionClass( '\TrustedLogin\Client' );

		$this->logging = $this->_get_public_property( 'logging' )->getValue( $this->TrustedLogin );
	}

	public function tearDown(): void {
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
	 * @covers \TrustedLogin\SiteAccess::get_license_key
	 */
	public function test_get_license_key() {

		$site_access = $this->_get_public_property( 'site_access' )->getValue( $this->TrustedLogin );

		$this->assertSame( $this->config->get_setting( 'auth/license_key' ), $site_access->get_license_key() );

	}

	/**
	 * @covers \TrustedLogin\Client::grant_access()
	 */
	public function test_grant_access_bad_user_cap() {
		$current = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $current->ID );
		$trustedlogin = new Client( $this->config );
		$expect_403   = $trustedlogin->grant_access();
		$error_data   = $expect_403->get_error_data();
		$error_code   = isset( $error_data['error_code'] ) ? $error_data['error_code'] : null;

		$this->assertEquals( $error_code, 403 );

		wp_set_current_user( 0 );
	}

	// -----------------------------------------------------------------
	//  revoke_access — return-value contract + retry queue
	// -----------------------------------------------------------------

	/**
	 * Sets up the WP-side state grant_access would have produced for
	 * $user_id: support role assignment, identifier metas, schedule cron.
	 * Returns the raw identifier the caller can pass to revoke_access().
	 */
	private function _seed_support_user(): array {
		$role_creator   = new SupportRole( $this->config, $this->logging );
		$role_creator->create();

		$support_user = new SupportUser( $this->config, $this->logging );
		$user_id      = $support_user->create();
		$this->assertIsInt( $user_id );

		$raw_identifier = Encryption::get_random_hash( $this->logging );
		$this->assertIsString( $raw_identifier );

		$cron      = new Cron( $this->config, $this->logging );
		$expiry    = time() + DAY_IN_SECONDS;
		$ok        = $support_user->setup( $user_id, $raw_identifier, $expiry, $cron );
		$this->assertNotFalse( $ok );

		return array( $user_id, $raw_identifier );
	}

	/**
	 * Short-circuit the SaaS DELETE so SiteAccess::revoke returns true.
	 * Returns the cleanup callback the caller MUST invoke in tearDown
	 * (or before the next test) so the filter doesn't leak.
	 *
	 * Also forces meets_ssl_requirement → true. PHPUnit runs in CLI
	 * with no $_SERVER['HTTPS'], so is_ssl() returns false → SiteAccess
	 * silently skips the HTTP call and returns true, bypassing the
	 * SaaS-error path we want to exercise.
	 */
	private function _stub_saas_delete( int $code = 204 ): callable {
		$filter = static function ( $preempt, $args, $url ) use ( $code ) {
			if ( false !== strpos( (string) $url, '/api/v1/sites/' )
				&& isset( $args['method'] ) && 'DELETE' === $args['method'] ) {
				return array(
					'response' => array( 'code' => $code, 'message' => '' ),
					'body'     => '',
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 9, 3 );

		$ssl_filter_tag = 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement';
		add_filter( $ssl_filter_tag, '__return_true' );

		return static function () use ( $filter, $ssl_filter_tag ) {
			remove_filter( 'pre_http_request', $filter, 9 );
			remove_filter( $ssl_filter_tag, '__return_true' );
		};
	}

	/**
	 * @covers \TrustedLogin\Client::revoke_access()
	 *
	 * Local cleanup is the contract: when the SaaS DELETE succeeds AND
	 * the user is deleted, revoke_access returns true.
	 */
	public function test_revoke_access_returns_true_on_full_success() {
		[ $user_id, $raw ] = $this->_seed_support_user();
		$cleanup = $this->_stub_saas_delete();

		try {
			$client = new Client( $this->config, false );
			$result = $client->revoke_access( $raw );

			$this->assertTrue( $result, 'revoke_access returns true on full success' );
			$this->assertFalse( get_userdata( $user_id ), 'support user must be deleted locally' );
		} finally {
			$cleanup();
		}
	}

	/**
	 * @covers \TrustedLogin\Client::revoke_access()
	 *
	 * When the SaaS DELETE fails, revoke_access still returns true (local
	 * cleanup is the contract that matters to the caller) AND the failed
	 * secret_id is queued for retry by the cron handler.
	 */
	public function test_revoke_access_returns_true_when_saas_fails_and_queues_retry() {
		[ $user_id, $raw ] = $this->_seed_support_user();
		$cleanup = $this->_stub_saas_delete( 500 );

		try {
			$client = new Client( $this->config, false );
			$result = $client->revoke_access( $raw );

			$this->assertTrue(
				$result,
				'revoke_access returns true even when SaaS sync errors — local cleanup succeeded.'
			);
			$this->assertFalse( get_userdata( $user_id ) );

			$queue = (array) get_option( 'tl_' . $this->config->ns() . '_pending_saas_revoke', array() );
			$this->assertCount( 1, $queue, 'failed SaaS revoke must be queued for retry' );
			$this->assertNotFalse(
				wp_next_scheduled( 'trustedlogin/' . $this->config->ns() . '/site/retry_revoke' ),
				'a retry cron event must be scheduled'
			);
		} finally {
			$cleanup();
		}
	}

	/**
	 * @covers \TrustedLogin\Client::revoke_access()
	 */
	public function test_revoke_access_with_empty_identifier_returns_false() {
		$client = new Client( $this->config, false );
		$this->assertFalse( $client->revoke_access( '' ) );
	}

	// NOTE: revoke_access('all') is covered at the e2e layer
	// (tests/e2e/tests/revoke-flow.spec.ts:198 — "revoke_access('all') clears
	// every support user"). It runs in a fresh WordPress process so the
	// shared Config::ns() static and cross-test multisite state don't
	// interfere; mirroring it in PHPUnit just produces flaky duplicates.

	// -----------------------------------------------------------------
	//  grant_access existing-user → extend branch
	// -----------------------------------------------------------------

	/**
	 * Stub the SaaS POST so the envelope sync round-trip returns the
	 * minimal-success response Remote::handle_response accepts. We're
	 * exercising the local extend-vs-create branch, not the SaaS shape.
	 */
	private function _stub_saas_sites_post(): callable {
		$filter = static function ( $preempt, $args, $url ) {
			if ( false !== strpos( (string) $url, '/api/v1/sites' )
				&& ( ! isset( $args['method'] ) || 'POST' === strtoupper( (string) $args['method'] ) ) ) {
				return array(
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'body'     => '{"success":true,"siteId":"abcd"}',
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 9, 3 );

		// PHPUnit runs in CLI; meets_ssl_requirement returns false by
		// default so grant_access bails before the extend branch even
		// runs. Force the per-namespace filter to true. The
		// extend-under-ssl-filter test re-asserts this filter to false
		// later in its body to exercise the explicit-fail path.
		$ssl_filter_tag = 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement';
		add_filter( $ssl_filter_tag, '__return_true' );

		return static function () use ( $filter, $ssl_filter_tag ) {
			remove_filter( 'pre_http_request', $filter, 9 );
			remove_filter( $ssl_filter_tag, '__return_true' );
		};
	}

	/**
	 * @covers \TrustedLogin\Client::grant_access()
	 *
	 * When a support user already exists, grant_access detects it via
	 * SupportUser::exists() and routes to the extend_access path, which
	 * returns array['type'='extend'].
	 */
	public function test_grant_access_routes_existing_user_through_extend() {
		$admin = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		// On multisite, only super admins have create_users — without
		// this the grant_access cap check returns no_cap_create_users
		// before the grant chain runs.
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $admin->ID );
		}

		$cleanup = $this->_stub_saas_sites_post();

		try {
			$client = new Client( $this->config, false );

			$first = $client->grant_access();
			$this->assertIsArray( $first, 'first grant_access should succeed' );
			$this->assertSame( 'new', $first['type'] );
			$first_user_id = $first['user_id'];
			$first_expiry  = (int) $first['expiry'];

			// Sleep so the second expiration timestamp differs from the
			// first by enough to assert "moved forward" cleanly.
			sleep( 1 );

			$second = $client->grant_access();
			$this->assertIsArray( $second, 'second grant_access should succeed' );
			$this->assertSame( 'extend', $second['type'], 'existing user should route through extend' );
			$this->assertSame( $first_user_id, $second['user_id'], 'extend reuses the same user' );
			$this->assertGreaterThan(
				$first_expiry,
				(int) $second['expiry'],
				'extend must move the expiration forward'
			);
		} finally {
			$cleanup();
			wp_set_current_user( 0 );
		}
	}

	/**
	 * @covers \TrustedLogin\Client::grant_access()
	 *
	 * The extend branch checks meets_ssl_requirement BEFORE any local
	 * mutation. Filter to false → grant_access returns
	 * fails_ssl_requirement instead of touching the cron schedule.
	 */
	public function test_extend_branch_short_circuits_under_ssl_filter() {
		$admin = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $admin->ID );
		}

		$cleanup = $this->_stub_saas_sites_post();

		try {
			$client = new Client( $this->config, false );
			$first  = $client->grant_access();
			$this->assertIsArray( $first );

			add_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_false' );
			try {
				$result = $client->grant_access();
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( 'fails_ssl_requirement', $result->get_error_code() );
			} finally {
				remove_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_false' );
			}
		} finally {
			$cleanup();
			wp_set_current_user( 0 );
		}
	}
}

<?php
/**
 * Per-namespace uninstall: {@see \TrustedLogin\Client::uninstall()}.
 *
 * Seeds every row the SDK persists for one namespace through the real
 * grant flow (SaaS stubbed) plus direct writes for the rows a grant
 * doesn't produce, then asserts the cleanup removes all of them and
 * nothing belonging to another namespace.
 *
 * @group integration
 * @group uninstall
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use TrustedLogin\Tests\Helpers\MaliciousSaasResponseTrait;

require_once __DIR__ . '/Helpers/MaliciousSaasResponseTrait.php';

class TrustedLoginUninstallTest extends WP_UnitTestCase {

	use MaliciousSaasResponseTrait;

	const WEBHOOK_URL = 'https://hooks.example.com/zap/uninstall-test';

	/** @var string[] Namespaces created by the current test, swept in tearDown. */
	private $namespaces = array();

	/** @var string[] Filters added by the current test, keyed hook => callback id. */
	private $ssl_filters = array();

	public function setUp(): void {
		parent::setUp();

		add_filter( 'http_request_host_is_external', array( $this, 'allow_test_webhook_host' ), 10, 2 );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $admin_id );
		}
	}

	public function tearDown(): void {
		remove_filter( 'http_request_host_is_external', array( $this, 'allow_test_webhook_host' ), 10 );
		$this->clear_saas_webhook_response_stub();

		foreach ( $this->ssl_filters as $hook ) {
			remove_filter( $hook, '__return_true' );
		}
		$this->ssl_filters = array();

		// Belt and braces: sweep anything a failing test left behind so
		// the next test starts clean. Uses the SUT only after its own
		// assertions have run, so a broken SUT can't hide itself here.
		foreach ( $this->namespaces as $namespace ) {
			if ( class_exists( '\TrustedLogin\Uninstaller' ) ) {
				Client::uninstall( $namespace );
			}
		}
		$this->namespaces = array();

		parent::tearDown();
	}

	public function allow_test_webhook_host( $is_external, $host ) {
		return 'hooks.example.com' === $host ? true : $is_external;
	}

	// ---------------------------------------------------------------
	// Fixtures
	// ---------------------------------------------------------------

	private function unique_namespace( $prefix = 'unins' ) {
		$namespace          = $prefix . '-' . bin2hex( random_bytes( 3 ) );
		$this->namespaces[] = $namespace;

		return $namespace;
	}

	private function build_config( $namespace, array $overrides = array() ) {
		$settings = array(
			'role'   => 'editor',
			'auth'   => array(
				'api_key'     => '0000111122223333',
				'license_key' => 'lic-' . $namespace,
			),
			'decay'  => WEEK_IN_SECONDS,
			'vendor' => array(
				'namespace'   => $namespace,
				'title'       => 'Uninstall Vendor',
				'email'       => 'uninstall-' . bin2hex( random_bytes( 4 ) ) . '@example.test',
				'website'     => 'https://example.test',
				'support_url' => 'https://example.test/support',
			),
		);

		return new Config( array_replace_recursive( $settings, $overrides ) );
	}

	/**
	 * Runs a real grant so the SDK writes every row a grant produces:
	 * support user + meta, support role, endpoint site option, expiry
	 * cron event, cached webhook URL, cached vendor public key.
	 *
	 * @return array The grant_access() return array.
	 */
	private function grant( Config $config ) {
		$hook = 'trustedlogin/' . $config->ns() . '/meets_ssl_requirement';
		add_filter( $hook, '__return_true' );
		$this->ssl_filters[] = $hook;

		$this->stub_saas_webhook_response( self::WEBHOOK_URL );

		$client = new Client( $config, false );
		$result = $client->grant_access();

		if ( is_wp_error( $result ) ) {
			$this->fail( 'grant_access() fixture failed: ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
		}

		$this->assertSame( 'new', $result['type'], 'Fixture must produce a NEW grant.' );

		return $result;
	}

	/**
	 * Seeds the rows a grant does not produce: log salt, brute-force
	 * transients, and a pending SaaS-revoke queue with its retry event.
	 */
	private function seed_non_grant_rows( Config $config ) {
		$ns = $config->ns();

		update_option( 'tl_' . $ns . '_log_salt', str_repeat( 'a', 64 ), false );
		Utils::set_transient( 'tl-' . $ns . '-used_accesskeys', array( 'abc' ), 600 );
		Utils::set_transient( 'tl-' . $ns . '-in_lockdown', time(), 1200 );

		$cron = new Cron( $config, new Logging( $config ) );
		$this->assertTrue( $cron->queue_saas_revoke_retry( 'secret-' . $ns ) );
	}

	private function log_directory() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . Logging::DIRECTORY_PATH;
	}

	/**
	 * Writes a log file with the exact name shape Logging produces for
	 * this namespace (salt seeded by seed_non_grant_rows), plus a foreign
	 * file and index.html that must survive.
	 *
	 * @return string[] [own file path, foreign file path, index path]
	 */
	private function seed_log_files( $ns ) {
		$dir = $this->log_directory();
		wp_mkdir_p( $dir );

		$own_hash     = hash( 'sha256', $ns . home_url( '/' ) . str_repeat( 'a', 64 ) );
		$foreign_hash = hash( 'sha256', 'someone-else' );

		$own     = $dir . 'client-debug-2026-01-01-' . $own_hash . '.log';
		$foreign = $dir . 'client-debug-2026-01-01-' . $foreign_hash . '.log';
		$index   = $dir . 'index.html';

		file_put_contents( $own, 'own' );
		file_put_contents( $foreign, 'foreign' );
		file_put_contents( $index, '<!-- -->' );

		return array( $own, $foreign, $index );
	}

	/**
	 * Network-wide support-user lookup that ignores multisite blog scoping.
	 *
	 * @return int[]
	 */
	private function support_user_ids( $ns ) {
		$args = array(
			'meta_key'     => 'tl_' . $ns . '_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'number'       => -1,
		);
		if ( is_multisite() ) {
			$args['blog_id'] = 0;
		}

		return array_map( 'intval', get_users( $args ) );
	}

	private function assert_namespace_rows_present( $ns, array $grant, $message_prefix = '' ) {
		$hash = Encryption::hash( $grant['identifier'] );

		$this->assertCount( 1, $this->support_user_ids( $ns ), $message_prefix . 'support user should exist' );
		$this->assertNotNull( get_role( $ns . '-support' ), $message_prefix . 'support role should exist' );
		$this->assertSame( $grant['endpoint'], get_site_option( 'tl_' . $ns . '_endpoint' ), $message_prefix . 'endpoint site option should exist' );
		$this->assertNotFalse( wp_next_scheduled( 'trustedlogin/' . $ns . '/access/revoke', array( $hash ) ), $message_prefix . 'expiry cron event should exist' );
		$this->assertSame( self::WEBHOOK_URL, get_option( 'tl_' . $ns . '_webhook_url' ), $message_prefix . 'cached webhook URL should exist' );
		$this->assertNotFalse( Utils::get_transient( 'tl_' . $ns . '_vendor_public_key' ), $message_prefix . 'cached vendor public key should exist' );
	}

	private function assert_namespace_rows_absent( $ns, $message_prefix = '' ) {
		global $wpdb;

		$this->assertSame( array(), $this->support_user_ids( $ns ), $message_prefix . 'support user must be deleted' );
		$this->assertNull( get_role( $ns . '-support' ), $message_prefix . 'support role must be removed' );
		$this->assertFalse( get_site_option( 'tl_' . $ns . '_endpoint' ), $message_prefix . 'endpoint site option must be deleted' );
		$this->assertFalse( wp_next_scheduled( 'trustedlogin/' . $ns . '/site/retry_revoke' ), $message_prefix . 'retry cron event must be cleared' );

		foreach ( array( 'tl_' . $ns . '_webhook_url', 'tl_' . $ns . '_log_salt', 'tl_' . $ns . '_pending_saas_revoke', 'tl_' . $ns . '_vendor_public_key', 'tl-' . $ns . '-used_accesskeys', 'tl-' . $ns . '-in_lockdown' ) as $option ) {
			// Read the table directly: get_option() is cached and the
			// transient wrapper deletes-on-read, either of which could
			// mask a row that is still on disk.
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			$this->assertNull( $row, $message_prefix . "option row {$option} must be deleted" );
		}

		// Any cron entry for this namespace, whatever its args.
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( array_keys( (array) $hooks ) as $hook ) {
				$this->assertStringNotContainsString( '/' . $ns . '/', $hook, $message_prefix . 'no cron event for the namespace may remain' );
			}
		}

		// No user meta for this namespace on ANY user (a deleted user's
		// meta goes with it; a leaked row would still show here).
		$meta_rows = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", '%' . $wpdb->esc_like( 'tl_' . $ns . '_' ) . '%' ) );
		$this->assertSame( '0', (string) $meta_rows, $message_prefix . 'no user meta for the namespace may remain' );
	}

	// ---------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------

	public function test_uninstall_removes_every_row_the_sdk_persists_for_the_namespace() {
		$ns     = $this->unique_namespace();
		$config = $this->build_config( $ns );
		$grant  = $this->grant( $config );
		$this->seed_non_grant_rows( $config );
		list( $own_log, $foreign_log, $index ) = $this->seed_log_files( $ns );

		// Positive control on the fixture.
		$this->assert_namespace_rows_present( $ns, $grant, 'before: ' );
		$this->assertNotFalse( wp_next_scheduled( 'trustedlogin/' . $ns . '/site/retry_revoke' ), 'before: retry cron event should exist' );
		$this->assertSame( str_repeat( 'a', 64 ), get_option( 'tl_' . $ns . '_log_salt' ) );
		$this->assertFileExists( $own_log );

		$report = Client::uninstall( $ns );

		$this->assert_namespace_rows_absent( $ns, 'after: ' );

		$this->assertFileDoesNotExist( $own_log, 'after: this namespace\'s log file must be deleted' );
		$this->assertFileExists( $foreign_log, 'after: another namespace\'s log file must survive' );
		$this->assertFileExists( $index, 'after: the shared index.html must survive' );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['support_users'] );
		$this->assertTrue( $report['role'] );
		$this->assertTrue( $report['endpoint'] );
		$this->assertSame( 1, $report['log_files'] );
		$this->assertGreaterThanOrEqual( 1, $report['cron_events'] );
		$this->assertEqualsCanonicalizing(
			array(
				'tl_' . $ns . '_webhook_url',
				'tl_' . $ns . '_log_salt',
				'tl_' . $ns . '_pending_saas_revoke',
				'tl_' . $ns . '_vendor_public_key',
				'tl-' . $ns . '-used_accesskeys',
				'tl-' . $ns . '-in_lockdown',
			),
			$report['options']
		);
	}

	public function test_uninstall_deletes_a_support_user_whose_expiry_event_was_already_consumed() {
		$ns     = $this->unique_namespace();
		$config = $this->build_config( $ns );
		$grant  = $this->grant( $config );

		// WP-Cron removes a single event before firing it. With the SDK
		// unloaded there is no handler, so the user outlives its expiry
		// with no event left to revoke it. Reproduce that state.
		wp_unschedule_hook( 'trustedlogin/' . $ns . '/access/revoke' );
		$this->assertFalse( wp_next_scheduled( 'trustedlogin/' . $ns . '/access/revoke', array( Encryption::hash( $grant['identifier'] ) ) ) );
		$this->assertCount( 1, $this->support_user_ids( $ns ), 'before: the support user lingers' );

		$report = Client::uninstall( $ns );

		$this->assertSame( array(), $this->support_user_ids( $ns ), 'after: the lingering support user must be deleted' );
		$this->assertSame( 1, $report['support_users'] );
	}

	public function test_uninstall_leaves_other_namespaces_and_shared_rows_untouched() {
		$ns_a     = $this->unique_namespace( 'keep' );
		$ns_b     = $this->unique_namespace( 'drop' );
		$config_a = $this->build_config( $ns_a );
		$config_b = $this->build_config( $ns_b );

		$grant_a = $this->grant( $config_a );
		$this->seed_non_grant_rows( $config_a );
		$grant_b = $this->grant( $config_b );
		$this->seed_non_grant_rows( $config_b );

		// The endpoint is a single site option per namespace; the second
		// grant did not touch the first namespace's.
		$this->assert_namespace_rows_present( $ns_a, $grant_a, 'before A: ' );
		$this->assert_namespace_rows_present( $ns_b, $grant_b, 'before B: ' );

		update_site_option( Endpoint::PERMALINK_FLUSH_OPTION_NAME, 1 );

		Client::uninstall( $ns_b );

		$this->assert_namespace_rows_absent( $ns_b, 'after B: ' );
		$this->assert_namespace_rows_present( $ns_a, $grant_a, 'after A: ' );
		$this->assertSame( str_repeat( 'a', 64 ), get_option( 'tl_' . $ns_a . '_log_salt' ), 'after A: log salt must survive' );
		$this->assertNotFalse( wp_next_scheduled( 'trustedlogin/' . $ns_a . '/site/retry_revoke' ), 'after A: retry cron event must survive' );
		$this->assertNotFalse( get_site_option( Endpoint::PERMALINK_FLUSH_OPTION_NAME ), 'the shared permalink flag is not namespaced and must survive' );
	}

	public function test_uninstall_is_a_safe_no_op_when_nothing_exists_and_when_called_twice() {
		$ns = $this->unique_namespace();

		$empty = Client::uninstall( $ns );

		$this->assertSame( 0, $empty['support_users'] );
		$this->assertFalse( $empty['role'] );
		$this->assertFalse( $empty['endpoint'] );
		$this->assertSame( array(), $empty['options'] );
		$this->assertSame( 0, $empty['cron_events'] );
		$this->assertSame( 0, $empty['log_files'] );

		$config = $this->build_config( $ns );
		$this->grant( $config );
		$this->seed_non_grant_rows( $config );

		$first  = Client::uninstall( $ns );
		$second = Client::uninstall( $ns );

		$this->assertSame( 1, $first['support_users'] );
		$this->assertSame( $empty, $second, 'A second run must find nothing to do and must not error.' );
		$this->assert_namespace_rows_absent( $ns, 'after second run: ' );
	}

	public function test_uninstall_runs_even_when_the_namespace_is_disabled_by_constant() {
		$ns     = $this->unique_namespace( 'disabled' );
		$config = $this->build_config( $ns );
		$grant  = $this->grant( $config );

		// Constants are process-global; the namespace is unique to this test.
		define( 'TRUSTEDLOGIN_DISABLE_' . strtoupper( $ns ), true );

		try {
			new Client( $config, false );
			$this->fail( 'Positive control: Client must refuse to construct for a disabled namespace.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		Client::uninstall( $config );

		$this->assert_namespace_rows_absent( $ns, 'after: ' );
		unset( $grant );
	}

	public function test_uninstall_honours_filtered_role_and_endpoint_option_names_when_given_a_config() {
		$ns     = $this->unique_namespace( 'filtered' );
		$config = $this->build_config( $ns );

		$role_filter     = static function () use ( $ns ) {
			return $ns . '-custom-support';
		};
		$endpoint_filter = static function () use ( $ns ) {
			return 'tl_' . $ns . '_custom_endpoint';
		};
		add_filter( 'trustedlogin/' . $ns . '/support_role', $role_filter );
		add_filter( 'trustedlogin/' . $ns . '/options/endpoint', $endpoint_filter );

		try {
			$grant = $this->grant( $config );

			$this->assertNotNull( get_role( $ns . '-custom-support' ), 'before: filtered role should exist' );
			$this->assertSame( $grant['endpoint'], get_site_option( 'tl_' . $ns . '_custom_endpoint' ), 'before: filtered endpoint option should exist' );

			$report = Client::uninstall( $config );

			$this->assertNull( get_role( $ns . '-custom-support' ), 'after: filtered role must be removed' );
			$this->assertFalse( get_site_option( 'tl_' . $ns . '_custom_endpoint' ), 'after: filtered endpoint option must be deleted' );
			$this->assertTrue( $report['role'] );
			$this->assertTrue( $report['endpoint'] );
		} finally {
			remove_filter( 'trustedlogin/' . $ns . '/support_role', $role_filter );
			remove_filter( 'trustedlogin/' . $ns . '/options/endpoint', $endpoint_filter );
		}
	}

	public function test_uninstall_never_removes_a_stock_role_used_without_cloning() {
		$ns     = $this->unique_namespace( 'noclone' );
		$config = $this->build_config( $ns, array( 'clone_role' => false, 'role' => 'editor' ) );
		$this->grant( $config );

		$this->assertNotNull( get_role( 'editor' ) );

		$report = Client::uninstall( $config );

		$this->assertNotNull( get_role( 'editor' ), 'A stock WordPress role must never be removed.' );
		$this->assertFalse( $report['role'] );
		$this->assertSame( array(), $this->support_user_ids( $ns ) );
	}

	/**
	 * Multisite: per-site rows live in each site's own options table and
	 * the plugin is removed from the whole network, so the default run
	 * visits every site. `network => false` limits it to the current site.
	 */
	public function test_uninstall_cleans_every_site_on_a_network_by_default() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$ns     = $this->unique_namespace( 'net' );
		$config = $this->build_config( $ns );

		$sub_site = self::factory()->blog->create();

		switch_to_blog( $sub_site );
		$sub_grant = $this->grant( $config );
		$this->seed_non_grant_rows( $config );
		$this->assertNotNull( get_role( $ns . '-support' ), 'before: sub-site role should exist' );
		restore_current_blog();

		$this->assertSame( self::WEBHOOK_URL, get_blog_option( $sub_site, 'tl_' . $ns . '_webhook_url' ), 'before: sub-site cached webhook URL should exist' );
		$this->assertFalse( get_option( 'tl_' . $ns . '_webhook_url' ), 'before: the main site has no cached webhook URL' );
		$this->assertCount( 1, $this->support_user_ids( $ns ), 'before: support user exists network-wide' );

		// Current site only: the sub-site's rows, its support user and the
		// shared endpoint the user logs in through all stay.
		$scoped = Client::uninstall( $ns, array( 'network' => false ) );

		$this->assertSame( self::WEBHOOK_URL, get_blog_option( $sub_site, 'tl_' . $ns . '_webhook_url' ), 'network=false: sub-site rows must be untouched' );
		$this->assertSame( 1, $scoped['sites'] );
		$this->assertCount( 1, $this->support_user_ids( $ns ), 'network=false: a support user on a site not visited must be left in place' );
		$this->assertSame( 0, $scoped['support_users'] );
		$this->assertNotEmpty( get_site_option( 'tl_' . $ns . '_endpoint' ), 'network=false: the endpoint the remaining support user logs in through must stay' );
		$this->assertFalse( $scoped['endpoint'] );

		// Default: every site.
		$report = Client::uninstall( $ns );

		$this->assertFalse( get_blog_option( $sub_site, 'tl_' . $ns . '_webhook_url' ), 'after: sub-site cached webhook URL must be deleted' );
		$this->assertFalse( get_blog_option( $sub_site, 'tl_' . $ns . '_log_salt' ), 'after: sub-site log salt must be deleted' );
		$this->assertFalse( get_site_option( 'tl_' . $ns . '_endpoint' ), 'after: network endpoint option must be deleted' );

		switch_to_blog( $sub_site );
		$this->assertNull( get_role( $ns . '-support' ), 'after: sub-site role must be removed' );
		$this->assertFalse( wp_next_scheduled( 'trustedlogin/' . $ns . '/site/retry_revoke' ), 'after: sub-site retry cron must be cleared' );
		restore_current_blog();

		$this->assertGreaterThanOrEqual( 2, $report['sites'] );
		$this->assertSame( 1, $report['support_users'], 'after: the default run deletes the sub-site support user' );
		$this->assertSame( array(), $this->support_user_ids( $ns ), 'after: no support user remains on the network' );
		unset( $sub_grant );
	}

	/**
	 * Records DELETE requests to the TrustedLogin sites endpoint without
	 * answering them; the SaaS stub answers.
	 *
	 * @return \ArrayObject
	 */
	private function record_saas_deletes() {
		$requests = new \ArrayObject();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $requests ) {
				if ( 'DELETE' === $args['method'] && false !== strpos( $url, '/sites/' ) ) {
					$requests[] = $url;
				}

				return $preempt;
			},
			1,
			3
		);

		return $requests;
	}

	public function test_uninstall_revokes_at_trustedlogin_when_the_config_has_an_api_key() {
		$ns     = $this->unique_namespace( 'saas' );
		$config = $this->build_config( $ns );
		$this->grant( $config );
		$this->seed_non_grant_rows( $config );

		$deletes = $this->record_saas_deletes();

		$report = Client::uninstall( $config );

		$this->assert_namespace_rows_absent( $ns, 'after: ' );
		$this->assertCount( 2, $deletes, 'the support user and the queued revoke must both be revoked at TrustedLogin' );
		$this->assertContains( Remote::API_URL . 'sites/secret-' . $ns, (array) $deletes, 'the queued revoke must be sent before the queue is deleted' );
		$this->assertSame( 2, $report['saas_revokes'] );
	}

	public function test_uninstall_by_namespace_alone_sends_no_request() {
		$ns     = $this->unique_namespace( 'nokey' );
		$config = $this->build_config( $ns );
		$this->grant( $config );
		$this->seed_non_grant_rows( $config );

		$deletes = $this->record_saas_deletes();

		$report = Client::uninstall( $ns );

		$this->assertCount( 0, $deletes, 'with no API key there is nothing to authenticate a revoke with' );
		$this->assertSame( 0, $report['saas_revokes'] );
	}

	public function test_uninstall_deletes_the_sweep_fallback_transient() {
		$ns = $this->unique_namespace( 'trans' );

		Utils::set_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, $ns ), time(), HOUR_IN_SECONDS );
		$this->assertNotFalse( get_option( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, $ns ) ), 'fixture: the transient row must exist' );

		Client::uninstall( $ns );

		$this->assertFalse( get_option( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, $ns ) ), 'the row must be gone, not just expired' );
	}

	/**
	 * After switch_to_blog() the rewrite object still describes the main
	 * site. A sub-site's stale rules must be cleared for WordPress to
	 * rebuild on that site, not overwritten with the main site's.
	 */
	public function test_uninstall_clears_rather_than_rebuilds_rewrite_rules_on_other_sites() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$ns       = $this->unique_namespace( 'rw' );
		$config   = $this->build_config( $ns );
		$grant    = $this->grant( $config );
		$sub_site = self::factory()->blog->create();

		update_blog_option( $sub_site, 'rewrite_rules', array( $grant['endpoint'] . '/?$' => 'index.php?tl=1' ) );

		Client::uninstall( $ns );

		$this->assertFalse( get_blog_option( $sub_site, 'rewrite_rules' ), 'the sub-site rules must be deleted so that site rebuilds its own' );
	}

	/**
	 * A PHP Error from a third-party hook must not leave the request
	 * switched to another site.
	 */
	public function test_uninstall_restores_the_current_site_when_a_hook_throws_an_error() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$ns       = $this->unique_namespace( 'err' );
		$config   = $this->build_config( $ns );
		$sub_site = self::factory()->blog->create();

		switch_to_blog( $sub_site );
		$this->grant( $config );
		restore_current_blog();

		$main    = get_current_blog_id();
		$thrower = function () {
			throw new \Error( 'third-party delete hook failed' );
		};
		add_action( 'delete_user', $thrower );

		$caught = null;
		try {
			Client::uninstall( $ns );
		} catch ( \Error $error ) {
			$caught = $error;
		}

		remove_action( 'delete_user', $thrower );

		$this->assertInstanceOf( \Error::class, $caught, 'fixture: the hook must have thrown during the run' );
		$this->assertSame( $main, get_current_blog_id(), 'the run must switch back before rethrowing' );
		$this->assertFalse( ms_is_switched() );
		$this->assertFalse( has_filter( 'trustedlogin/' . $ns . '/logging/enabled', '__return_false' ), 'logging must be unsilenced before rethrowing' );
	}

	/**
	 * Fails every DELETE to the TrustedLogin sites endpoint, recording the
	 * URL and timeout of each. Runs after the SaaS stub so it wins.
	 *
	 * @return \ArrayObject Recorded array( url, timeout ) pairs.
	 */
	private function fail_saas_deletes() {
		$requests = new \ArrayObject();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $requests ) {
				if ( 'DELETE' !== $args['method'] || false === strpos( $url, '/sites/' ) ) {
					return $preempt;
				}

				$requests[] = array( $url, $args['timeout'] );

				return new \WP_Error( 'http_request_failed', 'offline' );
			},
			20,
			3
		);

		return $requests;
	}

	public function test_uninstall_stops_revoking_after_a_failed_request_and_reports_the_rest() {
		$ns     = $this->unique_namespace( 'fail' );
		$config = $this->build_config( $ns );
		$this->grant( $config );
		$this->seed_non_grant_rows( $config );

		$requests = $this->fail_saas_deletes();

		$report = Client::uninstall( $config );

		$this->assertCount( 1, $requests, 'after one failure no further revoke may be sent' );
		$this->assertSame( Uninstaller::SAAS_REVOKE_TIMEOUT, $requests[0][1], 'each revoke must use the short timeout' );
		$this->assertSame( 0, $report['saas_revokes'] );
		$this->assertCount( 2, $report['saas_revokes_failed'], 'both the grant and the queued revoke must be reported' );
		$this->assertContains( 'secret-' . $ns, $report['saas_revokes_failed'] );
		$this->assert_namespace_rows_absent( $ns, 'after: ' );
	}

	public function test_uninstall_sends_no_revoke_once_the_time_budget_is_spent() {
		$ns     = $this->unique_namespace( 'budget' );
		$config = $this->build_config( $ns );
		add_filter( 'trustedlogin/' . $ns . '/meets_ssl_requirement', '__return_true' );
		$this->ssl_filters[] = 'trustedlogin/' . $ns . '/meets_ssl_requirement';

		$deletes = $this->record_saas_deletes();

		$uninstaller = new Uninstaller( $config );
		$reflection  = new \ReflectionClass( Uninstaller::class );

		$report = $reflection->getProperty( 'report' );
		$report->setAccessible( true );
		$report->setValue(
			$uninstaller,
			array(
				'saas_revokes'        => 0,
				'saas_revokes_failed' => array(),
			)
		);

		$started = $reflection->getProperty( 'saas_revokes_started' );
		$started->setAccessible( true );
		$started->setValue( $uninstaller, microtime( true ) - Uninstaller::SAAS_REVOKE_BUDGET - 1 );

		$revoke = $reflection->getMethod( 'revoke_at_saas' );
		$revoke->setAccessible( true );
		$revoke->invoke( $uninstaller, 'secret-over-budget' );

		$after = $report->getValue( $uninstaller );

		$this->assertCount( 0, $deletes, 'no request may be sent once the budget is spent' );
		$this->assertSame( array( 'secret-over-budget' ), $after['saas_revokes_failed'] );
	}

	public function test_uninstall_does_not_count_a_revoke_skipped_for_the_ssl_requirement() {
		$ns     = $this->unique_namespace( 'nossl' );
		$config = $this->build_config( $ns );
		$this->grant( $config );

		$hook = 'trustedlogin/' . $ns . '/meets_ssl_requirement';
		remove_filter( $hook, '__return_true' );
		add_filter( $hook, '__return_false' );

		$deletes = $this->record_saas_deletes();

		$report = Client::uninstall( $config );

		remove_filter( $hook, '__return_false' );

		$this->assertCount( 0, $deletes );
		$this->assertSame( 0, $report['saas_revokes'], 'a revoke that was never sent is not a revoke' );
		$this->assertCount( 1, $report['saas_revokes_failed'] );
	}

	public function test_uninstall_removes_the_endpoint_before_visiting_sites_when_every_site_is_visited() {
		$ns     = $this->unique_namespace( 'epfirst' );
		$config = $this->build_config( $ns );
		$this->grant( $config );

		$this->assertNotEmpty( get_site_option( 'tl_' . $ns . '_endpoint' ), 'fixture: the endpoint must exist' );

		$seen   = new \ArrayObject();
		$record = function () use ( $ns, $seen ) {
			$seen[] = get_site_option( 'tl_' . $ns . '_endpoint' );
		};
		add_action( 'delete_user', $record );

		$report = Client::uninstall( $config );

		remove_action( 'delete_user', $record );

		$this->assertNotEmpty( $seen, 'fixture: a support user must have been deleted' );
		$this->assertFalse( $seen[0], 'the endpoint must be gone before the first support user is deleted' );
		$this->assertTrue( $report['endpoint'] );
	}

	public function test_uninstall_deletes_the_sweep_failures_transient() {
		$ns = $this->unique_namespace( 'fails' );

		Utils::set_transient( sprintf( Cron::RECONCILE_FAILURES_TRANSIENT, $ns ), array( 'x' => array( 'count' => 1, 'retry_after' => time() ) ), HOUR_IN_SECONDS );

		Client::uninstall( $ns );

		$this->assertFalse( get_option( sprintf( Cron::RECONCILE_FAILURES_TRANSIENT, $ns ) ), 'the row must be gone, not just expired' );
	}

	/**
	 * Seeds a support user of the namespace on the current site.
	 *
	 * @return int User ID.
	 */
	private function seed_support_user( $ns ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_option( $user_id, 'tl_' . $ns . '_id', md5( wp_generate_uuid4() ), true );

		return $user_id;
	}

	public function test_uninstall_of_one_site_keeps_a_member_of_another_site_and_their_posts() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$ns       = $this->unique_namespace( 'member' );
		$user_id  = $this->seed_support_user( $ns );
		$sub_site = self::factory()->blog->create();

		add_user_to_blog( $sub_site, $user_id, 'editor' );

		switch_to_blog( $sub_site );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		restore_current_blog();

		$report = Client::uninstall( $ns, array( 'network' => false ) );

		$this->assertSame( 1, $report['support_users'] );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'a member of a site not visited must stay on the network' );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_current_blog_id() ) );

		switch_to_blog( $sub_site );
		$post = get_post( $post_id );
		restore_current_blog();

		$this->assertInstanceOf( \WP_Post::class, $post, 'the user\'s post on the other site must survive' );
	}

	public function test_uninstall_keeps_a_support_user_whose_only_site_is_archived() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$ns       = $this->unique_namespace( 'archived' );
		$user_id  = $this->seed_support_user( $ns );
		$sub_site = self::factory()->blog->create();

		add_user_to_blog( $sub_site, $user_id, 'editor' );
		remove_user_from_blog( $user_id, get_current_blog_id() );
		update_blog_status( $sub_site, 'archived', '1' );

		$this->assertSame( array(), get_blogs_of_user( $user_id ), 'fixture: the default site list must skip the archived site' );

		Client::uninstall( $ns, array( 'network' => false ) );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'a member of an archived site is not unclaimed' );

		update_blog_status( $sub_site, 'archived', '0' );
	}

	public function test_uninstall_creates_no_upload_folders_on_the_sites_it_visits() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$ns       = $this->unique_namespace( 'uploads' );
		$sub_site = self::factory()->blog->create();

		switch_to_blog( $sub_site );
		$uploads = wp_upload_dir( null, false );
		restore_current_blog();

		$this->assertFalse( is_dir( $uploads['path'] ), 'fixture: the new site has no upload folder yet' );

		Client::uninstall( $ns );

		$created = is_dir( $uploads['path'] );

		if ( $created ) {
			rmdir( $uploads['path'] );
		}

		$this->assertFalse( $created, 'uninstall must not create upload folders' );
	}
}

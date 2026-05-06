<?php
/**
 * TL-48 — SaaS-cached webhook URL: cache-write behavior in
 * {@see SiteAccess::sync_secret}.
 *
 * Stubs the SaaS HTTP response via MaliciousSaasResponseTrait so we
 * can drive every shape of `webhookUrl` value (HTTPS string, null,
 * empty string, missing, non-string, malicious schemes) and assert
 * the option-cache outcome.
 *
 * Pinned reconciliation decisions covered here:
 *   - null / absent / empty / non-string / type-mismatch ⇒ preserve cache.
 *   - HTTPS string ⇒ cache via update_option(..., autoload=false).
 *   - Invalid scheme / userinfo / oversized ⇒ NOT cached, host-only warn log.
 *   - Namespace isolation: two namespaces ⇒ two options.
 *   - Re-cached on every successful sync_secret.
 *   - GDPR: only the URL string is persisted (no headers, no extras).
 *
 * @group integration
 * @group webhook-url
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use TrustedLogin\Tests\Helpers\MaliciousSaasResponseTrait;
use TrustedLogin\Tests\Helpers\LogCaptureTrait;

require_once __DIR__ . '/Helpers/MaliciousSaasResponseTrait.php';
require_once __DIR__ . '/Helpers/LogCaptureTrait.php';

class TrustedLoginWebhookUrlSaasCacheTest extends WP_UnitTestCase {

	use MaliciousSaasResponseTrait;
	use LogCaptureTrait;

	const NS         = 'tl-cache-test';
	const OTHER_NS   = 'tl-cache-test-other';

	/** @var Config */
	private $config;

	/** @var SiteAccess */
	private $site_access;

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->build_config( self::NS );

		$logging           = new Logging( $this->config );
		$this->site_access = new SiteAccess( $this->config, $logging );

		$this->start_log_capture( self::NS );

		// WP test env sets WP_TESTS_DOMAIN as the only "external" host
		// that wp_http_validate_url accepts by default. Allow our test
		// webhook hosts so the sanitizer's wp_http_validate_url() check
		// passes against literal `hooks.example.com` etc.
		add_filter( 'http_request_host_is_external', array( $this, 'allow_test_webhook_hosts' ), 10, 2 );
	}

	public function allow_test_webhook_hosts( $is_external, $host ) {
		$allowed = array( 'hooks.example.com', 'attacker.test', 'h.test', 'ok.test' );
		if ( in_array( $host, $allowed, true ) ) {
			return true;
		}
		return $is_external;
	}

	public function tearDown(): void {
		remove_filter( 'http_request_host_is_external', array( $this, 'allow_test_webhook_hosts' ), 10 );
		$this->clear_saas_webhook_response_stub();
		$this->stop_log_capture();
		delete_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ) );
		delete_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::OTHER_NS ) );
		parent::tearDown();
	}

	private function build_config( $namespace ) {
		return new Config(
			array(
				'role'   => 'editor',
				'auth'   => array(
					'api_key'     => '0000111122223333',
					'license_key' => 'lic-' . $namespace,
				),
				'vendor' => array(
					'namespace'   => $namespace,
					'title'       => 'Test Vendor',
					'email'       => 'vendor@example.test',
					'website'     => 'https://example.test',
					'support_url' => 'https://example.test/support',
				),
			)
		);
	}

	private function option_key( $namespace ) {
		return sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, $namespace );
	}

	// ---------------------------------------------------------------
	// Happy path
	// ---------------------------------------------------------------

	public function test_https_webhook_url_in_response_is_cached() {
		$expected = 'https://hooks.example.com/zap/abc123';

		// Sanity: sanitizer must accept this URL in this environment
		// before we test the round-trip.
		$direct = Config::sanitize_webhook_url( $expected );
		if ( '' === $direct ) {
			$this->fail( 'Sanitizer rejected the test URL — likely WP_HTTP_BLOCK_EXTERNAL or similar test-env constraint. Test needs to add hooks.example.com to the accessible-hosts allow-list.' );
		}

		$this->stub_saas_webhook_response( $expected );

		$result = $this->site_access->sync_secret( 'secret-id-1', 'site-id-1', 'create' );

		if ( is_wp_error( $result ) ) {
			$logs = $this->getCapturedLogs();
			$this->fail( sprintf( 'sync_secret returned WP_Error: %s — %s. Captured logs: %s', $result->get_error_code(), $result->get_error_message(), wp_json_encode( array_column( $logs, 'message' ) ) ) );
		}

		$this->assertSame(
			$expected,
			get_option( $this->option_key( self::NS ) ),
			'A valid HTTPS webhookUrl from SaaS must land in the namespaced option.'
		);
	}

	public function test_subsequent_sync_overwrites_cache() {
		// First sync — old URL.
		$this->stub_saas_webhook_response( 'https://hooks.example.com/zap/OLD' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );
		$this->assertSame( 'https://hooks.example.com/zap/OLD', get_option( $this->option_key( self::NS ) ) );

		// Second sync — new URL. Re-stub the response.
		$this->clear_saas_webhook_response_stub();
		$this->stub_saas_webhook_response( 'https://hooks.example.com/zap/NEW' );
		$this->site_access->sync_secret( 's1', 'h1', 'extend' );

		$this->assertSame(
			'https://hooks.example.com/zap/NEW',
			get_option( $this->option_key( self::NS ) ),
			'A subsequent sync must overwrite the cached URL with the latest dashboard value.'
		);
	}

	public function test_namespace_isolation() {
		// Cache URL for self::NS.
		$this->stub_saas_webhook_response( 'https://hooks.example.com/zap/ns-a' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		// Cache different URL for self::OTHER_NS via a separate SiteAccess.
		$other_config        = $this->build_config( self::OTHER_NS );
		$other_logging       = new Logging( $other_config );
		$other_support       = new SupportUser( $other_config, $other_logging );
		$other_site_access   = new SiteAccess( $other_config, $other_logging, $other_support );

		$this->clear_saas_webhook_response_stub();
		$this->stub_saas_webhook_response( 'https://hooks.example.com/zap/ns-b' );
		$other_site_access->sync_secret( 's2', 'h2', 'create' );

		$this->assertSame( 'https://hooks.example.com/zap/ns-a', get_option( $this->option_key( self::NS ) ) );
		$this->assertSame( 'https://hooks.example.com/zap/ns-b', get_option( $this->option_key( self::OTHER_NS ) ) );
	}

	// ---------------------------------------------------------------
	// Preserve cache on null / absent / empty / type-mismatch
	// ---------------------------------------------------------------

	public function test_absent_webhook_url_preserves_existing_cache() {
		$existing = 'https://hooks.example.com/zap/preserved';
		update_option( $this->option_key( self::NS ), $existing, false );

		// `__omit__` sentinel — field not present in response at all.
		$this->stub_saas_webhook_response( '__omit__' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertSame(
			$existing,
			get_option( $this->option_key( self::NS ) ),
			'Older SaaS without the webhookUrl field must NOT clear the cache.'
		);
	}

	public function test_null_webhook_url_preserves_existing_cache() {
		$existing = 'https://hooks.example.com/zap/preserved';
		update_option( $this->option_key( self::NS ), $existing, false );

		$this->stub_saas_webhook_response( null );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertSame( $existing, get_option( $this->option_key( self::NS ) ) );
	}

	public function test_empty_string_webhook_url_preserves_existing_cache() {
		// Per reconciliation: empty string is NOT a "clear" signal.
		// A SaaS-controlled empty flipping the customer site to "no
		// notifications" would be a denial-of-signal attack.
		$existing = 'https://hooks.example.com/zap/preserved';
		update_option( $this->option_key( self::NS ), $existing, false );

		$this->stub_saas_webhook_response( '' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertSame( $existing, get_option( $this->option_key( self::NS ) ) );
	}

	public function test_integer_webhook_url_preserves_cache() {
		$existing = 'https://hooks.example.com/zap/preserved';
		update_option( $this->option_key( self::NS ), $existing, false );

		$this->stub_saas_webhook_response( 12345 );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertSame( $existing, get_option( $this->option_key( self::NS ) ) );
	}

	public function test_array_webhook_url_preserves_cache() {
		$existing = 'https://hooks.example.com/zap/preserved';
		update_option( $this->option_key( self::NS ), $existing, false );

		$this->stub_saas_webhook_response( array( 'url' => 'https://attacker.test/' ) );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertSame( $existing, get_option( $this->option_key( self::NS ) ) );
	}

	// ---------------------------------------------------------------
	// Reject + log on invalid string values
	// ---------------------------------------------------------------

	public function test_http_url_rejected_with_host_only_log() {
		$this->assertSame( '', Config::sanitize_webhook_url( 'http://hooks.example.com/zap/SECRET-PATH-abc' ) );

		$this->stub_saas_webhook_response( 'http://hooks.example.com/zap/SECRET-PATH-abc' );
		$result = $this->site_access->sync_secret( 's1', 'h1', 'create' );

		// Diagnostic dump on failure.
		if ( ! $this->assertFalse_silently( get_option( $this->option_key( self::NS ) ) ) ) {
			$logs = $this->getCapturedLogs();
			$this->fail( sprintf( 'sync_secret returned %s; logs: %s', wp_json_encode( $result ), wp_json_encode( array_map( function ( $l ) { return $l['level'] . ':' . $l['message']; }, $logs ) ) ) );
		}

		$this->assertLogContains( 'invalid webhookUrl', 'warning' );
		$this->assertLogContains( 'host=hooks.example.com', 'warning' );
		$this->assertLogNotContains( 'SECRET-PATH-abc' );
	}

	private function assertFalse_silently( $value ) {
		return false === $value;
	}

	public function test_javascript_scheme_rejected() {
		$this->stub_saas_webhook_response( 'javascript:alert(1)' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertFalse( get_option( $this->option_key( self::NS ) ) );
		$this->assertLogContains( 'invalid webhookUrl', 'warning' );
		// `javascript:` has no host — log should fall back to '[invalid-url]'.
		$this->assertLogContains( 'host=[invalid-url]', 'warning' );
	}

	public function test_userinfo_url_rejected() {
		$this->stub_saas_webhook_response( 'https://attacker:pass@hooks.example.com/zap/abc' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertFalse( get_option( $this->option_key( self::NS ) ) );
	}

	public function test_oversized_url_rejected() {
		$oversize = 'https://h.test/' . str_repeat( 'a', Config::WEBHOOK_URL_MAX_LENGTH + 100 );
		$this->stub_saas_webhook_response( $oversize );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$this->assertFalse( get_option( $this->option_key( self::NS ) ) );
	}

	// ---------------------------------------------------------------
	// Storage hygiene + GDPR / no-extra-fields persisted
	// ---------------------------------------------------------------

	public function test_only_url_string_is_persisted() {
		$expected = 'https://hooks.example.com/zap/clean';
		$this->stub_saas_webhook_response(
			$expected,
			// Pollute the response with extra fields that MUST NOT bleed
			// into wp_options.
			array(
				'clientIp'   => '203.0.113.42',
				'email'      => 'leak@example.test',
				'userAgent'  => 'Mozilla/5.0 ...',
				'__proto__'  => array( 'webhookUrl' => 'https://attacker.test/wh' ),
			)
		);
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		$value = get_option( $this->option_key( self::NS ) );
		$this->assertIsString( $value );
		$this->assertSame( $expected, $value, 'Only the validated URL string lands in wp_options.' );
		// Sanity: no PII keys end up in the value.
		$this->assertStringNotContainsString( '203.0.113.42', (string) $value );
		$this->assertStringNotContainsString( 'leak@example.test', (string) $value );
	}

	public function test_option_is_not_autoloaded() {
		// `autoload=false` is non-negotiable per security review —
		// autoloaded options ship in wp_load_alloptions() and would
		// expose the URL on every page load.
		$this->stub_saas_webhook_response( 'https://hooks.example.com/zap/abc' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		global $wpdb;
		$key      = $this->option_key( self::NS );
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $key )
		);
		// WP 6.7 changed autoload values from 'yes'/'no' to 'on'/'off'/
		// 'auto-on'/'auto-off'. Accept any shape that means NOT autoloaded.
		$not_autoloaded = array( 'no', 'off', 'auto-off' );
		$this->assertContains(
			$autoload,
			$not_autoloaded,
			'tl_{ns}_webhook_url MUST be stored with autoload off — URL is a bearer secret. Got: ' . var_export( $autoload, true )
		);
	}

	/**
	 * @group security-critical
	 */
	public function test_option_not_in_alloptions_cache() {
		$this->stub_saas_webhook_response( 'https://hooks.example.com/zap/abc' );
		$this->site_access->sync_secret( 's1', 'h1', 'create' );

		// Force alloptions to be hydrated.
		wp_load_alloptions();
		$alloptions = wp_cache_get( 'alloptions', 'options' );

		$this->assertIsArray( $alloptions );
		$this->assertArrayNotHasKey(
			$this->option_key( self::NS ),
			$alloptions,
			'webhook URL option must not appear in the autoloaded options cache.'
		);
	}
}

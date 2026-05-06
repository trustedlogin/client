<?php
/**
 * Webhook URL read-chain priority + deprecation cardinality.
 *
 * Pinned reconciliation decisions covered here:
 *   - Config `webhook/url` wins over cached SaaS URL (back-compat).
 *   - Legacy `webhook_url` alias still works.
 *   - Cached SaaS URL is the third-priority source.
 *   - Deprecation log fires AT MOST ONCE per request when Config is set.
 *   - Distinct shadowing log fires when BOTH Config AND cached are set.
 *   - `Remote::init()` registers hooks when only the cached URL exists.
 *   - Webhook payload shape is locked: no signature header, no extra fields.
 *
 * @group integration
 * @group webhook-url
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use TrustedLogin\Tests\Helpers\WebhookCaptureTrait;
use TrustedLogin\Tests\Helpers\LogCaptureTrait;

require_once __DIR__ . '/Helpers/WebhookCaptureTrait.php';
require_once __DIR__ . '/Helpers/LogCaptureTrait.php';

class TrustedLoginWebhookUrlReadChainTest extends WP_UnitTestCase {

	use WebhookCaptureTrait;
	use LogCaptureTrait;

	const NS = 'tl-readchain-test';

	const DEPRECATION_NEEDLE = '`webhook/url` config key is deprecated';
	const SHADOWING_NEEDLE   = 'shadowing the URL registered in your TrustedLogin dashboard';

	// Use real-resolving hosts so wp_http_validate_url accepts them in
	// the WP test env. Disambiguate by path so we can still tell which
	// source a webhook came from.
	const URL_CONFIG = 'https://example.com/wh-config';
	const URL_CACHED = 'https://example.com/wh-cached';
	const URL_LEGACY = 'https://example.com/wh-legacy';

	public function setUp(): void {
		parent::setUp();
		$this->start_webhook_capture();
		$this->start_log_capture( self::NS );
		Remote::reset_deprecation_flag();
	}

	public function tearDown(): void {
		$this->stop_webhook_capture();
		$this->stop_log_capture();
		delete_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ) );
		Remote::reset_deprecation_flag();
		parent::tearDown();
	}

	private function build_config_with_webhook_url( $webhook_url = null, $legacy_alias = null ) {
		$config = array(
			'role'   => 'editor',
			'auth'   => array(
				'api_key'     => '0000111122223333',
				'license_key' => 'lic-readchain',
			),
			'vendor' => array(
				'namespace'   => self::NS,
				'title'       => 'Test Vendor',
				'email'       => 'vendor@example.test',
				'website'     => 'https://example.test',
				'support_url' => 'https://example.test/support',
			),
		);
		if ( null !== $webhook_url ) {
			$config['webhook'] = array( 'url' => $webhook_url );
		}
		if ( null !== $legacy_alias ) {
			$config['webhook_url'] = $legacy_alias;
		}
		return new Config( $config );
	}

	private function fire_webhook( $config, $action = 'created' ) {
		$logging = new Logging( $config );
		$remote  = new Remote( $config, $logging );
		$remote->maybe_send_webhook( array(
			'url'    => 'https://example.test',
			'ns'     => self::NS,
			'action' => $action,
		) );
	}

	private function set_cached_url( $url ) {
		update_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ), $url, false );
	}

	// ---------------------------------------------------------------
	// Read-chain priority
	// ---------------------------------------------------------------

	public function test_config_url_wins_over_cached_url() {
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$this->set_cached_url( self::URL_CACHED );

		$this->fire_webhook( $config );

		$this->assertWebhookCount( 1 );
		$this->assertWebhookFiredToUrl( self::URL_CONFIG );
	}

	public function test_legacy_alias_url_works_when_set_alone() {
		$config = $this->build_config_with_webhook_url( null, self::URL_LEGACY );

		$this->fire_webhook( $config );

		$this->assertWebhookCount( 1 );
		$this->assertWebhookFiredToUrl( self::URL_LEGACY );
	}

	public function test_cached_url_used_when_config_unset() {
		$config = $this->build_config_with_webhook_url( null );
		$this->set_cached_url( self::URL_CACHED );

		$this->fire_webhook( $config );

		$this->assertWebhookCount( 1 );
		$this->assertWebhookFiredToUrl( self::URL_CACHED );
	}

	public function test_no_url_set_anywhere_no_post_fired() {
		$config = $this->build_config_with_webhook_url( null );

		$this->fire_webhook( $config );

		$this->assertNoWebhookFired();
	}

	// ---------------------------------------------------------------
	// Deprecation log cardinality
	// ---------------------------------------------------------------

	public function test_deprecation_logged_when_config_set() {
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$this->fire_webhook( $config );

		$this->assertLogContains( self::DEPRECATION_NEEDLE, 'warning' );
	}

	public function test_no_deprecation_when_only_cached_set() {
		$config = $this->build_config_with_webhook_url( null );
		$this->set_cached_url( self::URL_CACHED );

		$this->fire_webhook( $config );

		$this->assertLogNotContains( self::DEPRECATION_NEEDLE );
	}

	public function test_deprecation_logged_at_most_once_per_request() {
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		foreach ( array( 'created', 'extended', 'logged_in', 'revoked' ) as $action ) {
			$this->fire_webhook( $config, $action );
		}

		$this->assertWebhookCount( 4, 'all four actions should fire the webhook' );
		$this->assertLogCount( self::DEPRECATION_NEEDLE, 1, 'deprecation log must fire AT MOST ONCE per request, regardless of action count' );
	}

	// ---------------------------------------------------------------
	// Shadowing log when both Config and cached are set
	// ---------------------------------------------------------------

	public function test_shadowing_log_fires_when_both_set() {
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$this->set_cached_url( self::URL_CACHED );

		$this->fire_webhook( $config );

		$this->assertLogContains( self::SHADOWING_NEEDLE, 'warning' );
	}

	public function test_shadowing_log_not_fired_when_only_one_set() {
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$this->fire_webhook( $config );

		$this->assertLogNotContains( self::SHADOWING_NEEDLE );
	}

	// ---------------------------------------------------------------
	// Remote::init() guard — hook registration depends on URL availability
	// ---------------------------------------------------------------

	public function test_init_registers_hooks_when_only_cached_set() {
		$config  = $this->build_config_with_webhook_url( null );
		$this->set_cached_url( self::URL_CACHED );

		$logging = new Logging( $config );
		$remote  = new Remote( $config, $logging );
		$remote->init();

		$this->assertTrue(
			has_action( 'trustedlogin/' . self::NS . '/access/created' ) > 0,
			'Hooks must register when only the cached URL is set.'
		);
	}

	public function test_init_skips_when_neither_config_nor_cached_set() {
		$config  = $this->build_config_with_webhook_url( null );
		delete_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ) );

		$logging = new Logging( $config );
		$remote  = new Remote( $config, $logging );
		$remote->init();

		$this->assertFalse(
			has_action( 'trustedlogin/' . self::NS . '/access/created' ),
			'Hooks must NOT register when neither Config nor cached URL is set (existing perf optimization).'
		);
	}

	public function test_init_registers_hooks_when_config_set() {
		$config  = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$logging = new Logging( $config );
		$remote  = new Remote( $config, $logging );
		$remote->init();

		$this->assertTrue(
			has_action( 'trustedlogin/' . self::NS . '/access/created' ) > 0
		);
	}

	// ---------------------------------------------------------------
	// Payload shape — pin against scope creep
	// ---------------------------------------------------------------

	public function test_no_signature_header_added() {
		// Pin: this design explicitly does NOT add a signature header. Future
		// Connector-mediated work will. This test exists so the absence
		// is intentional — it must be inverted (not deleted) when
		// signing lands.
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$this->fire_webhook( $config );

		$this->assertWebhookCount( 1 );
		$webhook = $this->getCapturedWebhooks();
		$headers = $webhook[0]['args']['headers'];

		foreach ( array_keys( $headers ) as $name ) {
			$this->assertStringNotContainsString( 'TL-Signature', (string) $name );
			$this->assertStringNotContainsString( 'TrustedLogin-Signature', (string) $name );
			$this->assertStringNotContainsString( 'X-Hub-Signature', (string) $name );
		}
	}

	public function test_payload_fields_are_minimal() {
		// Pin the body shape so accidental "let's also send debug_data
		// on every event" refactors get caught here. Allowed top-level
		// keys are exactly what `Client::do_action` passes today.
		$config = $this->build_config_with_webhook_url( self::URL_CONFIG );
		$this->fire_webhook( $config );

		$this->assertWebhookCount( 1 );
		$webhook = $this->getCapturedWebhooks();
		$body    = json_decode( $webhook[0]['body'], true );

		$this->assertIsArray( $body );
		$allowed = array(
			'url', 'ns', 'action', 'access_key',
			'debug_data', 'ref', 'ticket',
		);
		foreach ( array_keys( $body ) as $key ) {
			$this->assertContains(
				$key,
				$allowed,
				sprintf( 'Unexpected webhook payload field "%s" — this test pins payload shape; expand `$allowed` deliberately if adding.', $key )
			);
		}
	}
}

<?php
/**
 * Grant Access screen surfaces that depend on the webhook URL read chain.
 *
 * Two optional parts of the Grant Access screen only make sense when a
 * webhook will be delivered:
 *   - the "Include a message for support?" ticket field
 *     (`webhook/create_ticket`), and
 *   - the "Include the Site Health troubleshooting report" consent
 *     checkbox (`webhook/debug_data`).
 *
 * Both must resolve the webhook URL through the same read chain
 * {@see Remote::maybe_send_webhook} uses to deliver — Config
 * `webhook/url`, the legacy `webhook_url` alias, then the option cached
 * from the TrustedLogin dashboard — so a site whose URL is registered
 * only in the dashboard offers the customer the same surfaces as a site
 * with the URL in Config.
 *
 * Also pins, by running a real grant, that debug data is sent only when
 * the consent checkbox was checked: no checkbox on the screen means no
 * debug data in the payload.
 *
 * @group integration
 * @group webhook-url
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use TrustedLogin\Tests\Helpers\MaliciousSaasResponseTrait;

require_once __DIR__ . '/Helpers/MaliciousSaasResponseTrait.php';

class TrustedLoginFormWebhookUrlReadChainTest extends WP_UnitTestCase {

	use MaliciousSaasResponseTrait;

	const NS = 'tl-formchain-test';

	// Vendor hosts use `.test`; webhook hosts use `example.com`, so a
	// host assertion can only be satisfied by the webhook row.
	const URL_CONFIG = 'https://example.com/wh-config?token=configsecret';
	const URL_CACHED = 'https://example.com/wh-cached?token=cachedsecret';
	const URL_LEGACY = 'https://example.com/wh-legacy';

	/** @var callable */
	private $http_offline;

	public function setUp(): void {
		parent::setUp();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		// get_auth_screen() runs a pre-flight pubkey fetch and the admin
		// debug panel pings the status endpoint. Fail both offline, but
		// only when no earlier filter (the SaaS stub at priority 9) has
		// already answered.
		$this->http_offline = static function ( $preempt ) {
			if ( false !== $preempt ) {
				return $preempt;
			}
			return new \WP_Error( 'short_circuited', 'No network access in tests.' );
		};
		add_filter( 'pre_http_request', $this->http_offline, 20, 3 );

		unset( $_GET['ref'], $_GET['reference_id'], $_GET['debug'], $_POST['ref'], $_POST['reference_id'] );

		Remote::reset_deprecation_flag();
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_offline, 20 );
		$this->clear_saas_webhook_response_stub();
		delete_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ) );
		unset( $_GET['ref'], $_GET['reference_id'], $_GET['debug'], $_POST['ref'], $_POST['reference_id'] );
		Remote::reset_deprecation_flag();

		$prefix = 'trustedlogin/' . self::NS . '/';
		global $wp_filter;
		if ( is_array( $wp_filter ) ) {
			foreach ( array_keys( $wp_filter ) as $hook ) {
				if ( 0 === strpos( $hook, $prefix ) ) {
					remove_all_actions( $hook );
					remove_all_filters( $hook );
				}
			}
		}

		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// Fixtures
	// ---------------------------------------------------------------

	/**
	 * @param array       $webhook      Values for the `webhook` settings group (url, create_ticket, debug_data).
	 * @param string|null $legacy_alias Value for the top-level legacy `webhook_url` key.
	 */
	private function build_config( array $webhook = array(), $legacy_alias = null ) {
		$settings = array(
			'role'    => 'editor',
			'auth'    => array(
				'api_key'     => '0123456789abcdef',
				'license_key' => 'lic-formchain',
			),
			'decay'   => WEEK_IN_SECONDS,
			'vendor'  => array(
				'namespace'   => self::NS,
				'title'       => 'Form Chain Vendor',
				'email'       => 'support+' . self::NS . '@example.test',
				'website'     => 'https://' . self::NS . '.example.test',
				'support_url' => 'https://' . self::NS . '.example.test/support/',
			),
			'webhook' => $webhook,
		);
		if ( null !== $legacy_alias ) {
			$settings['webhook_url'] = $legacy_alias;
		}
		return new Config( $settings );
	}

	private function build_form( Config $config ) {
		$logging = new Logging( $config );
		return new Form( $config, $logging, new SupportUser( $config, $logging ), new SiteAccess( $config, $logging ) );
	}

	private function set_cached_url( $url ) {
		update_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ), $url, false );
	}

	private function ticket_field_marker() {
		return 'id="tl-' . self::NS . '-ticket-message"';
	}

	private function debug_consent_marker() {
		return 'id="tl-' . self::NS . '-debug-data-consent"';
	}

	/**
	 * The inline `window.trustedLogin[ns]` payload generate_button() attaches
	 * to the script handle, decoded.
	 */
	private function get_button_settings( Form $form ) {
		global $wp_scripts;
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$form->register_assets();
		$form->generate_button( array( 'tag' => 'button' ), false );

		$before  = wp_scripts()->get_data( 'trustedlogin-' . self::NS, 'before' );
		$payload = is_array( $before ) ? implode( "\n", array_filter( $before, 'is_string' ) ) : (string) $before;

		$this->assertMatchesRegularExpression( '/window\.trustedLogin\[[^\]]+\] = (\{.*\});/s', $payload );
		preg_match( '/window\.trustedLogin\[[^\]]+\] = (\{.*\});/s', $payload, $m );

		$settings = json_decode( $m[1], true );
		$this->assertIsArray( $settings, 'inline payload must be valid JSON' );
		return $settings;
	}

	/**
	 * The `<p><strong>Webhook URL</strong>: …</p>` row of the admin debug panel.
	 */
	private function get_webhook_debug_row( Form $form ) {
		// The panel also prints the vendor public key, so the pubkey
		// fetch must succeed. The SaaS stub answers it; `/sites` is
		// never called on this path.
		$this->stub_saas_webhook_response( '__omit__' );

		$_GET['debug'] = '1';
		$html          = $form->get_auth_screen();

		$this->assertMatchesRegularExpression( '#<p><strong>Webhook URL</strong>: (.*?)</p>#s', $html, 'admin debug panel must contain the Webhook URL row' );
		preg_match( '#<p><strong>Webhook URL</strong>: (.*?)</p>#s', $html, $m );
		return $m[1];
	}

	// ---------------------------------------------------------------
	// Dashboard-registered URL (cached option) enables both surfaces
	// ---------------------------------------------------------------

	public function test_ticket_field_renders_when_only_dashboard_url_cached() {
		$this->set_cached_url( self::URL_CACHED );
		$form = $this->build_form( $this->build_config( array( 'create_ticket' => true ) ) );

		$this->assertStringContainsString( $this->ticket_field_marker(), $form->get_auth_screen() );
	}

	public function test_debug_consent_renders_when_only_dashboard_url_cached() {
		$this->set_cached_url( self::URL_CACHED );
		$form = $this->build_form( $this->build_config( array( 'debug_data' => true ) ) );

		$this->assertStringContainsString( $this->debug_consent_marker(), $form->get_auth_screen() );
	}

	public function test_inline_script_create_ticket_true_when_only_dashboard_url_cached() {
		$this->set_cached_url( self::URL_CACHED );
		$form = $this->build_form( $this->build_config( array( 'create_ticket' => true ) ) );

		$settings = $this->get_button_settings( $form );

		$this->assertTrue( $settings['create_ticket'], 'the JS only posts the ticket message when create_ticket is true' );
	}

	// ---------------------------------------------------------------
	// Legacy `webhook_url` alias enables both surfaces
	// ---------------------------------------------------------------

	public function test_ticket_field_renders_when_only_legacy_alias_set() {
		$form = $this->build_form( $this->build_config( array( 'create_ticket' => true ), self::URL_LEGACY ) );

		$this->assertStringContainsString( $this->ticket_field_marker(), $form->get_auth_screen() );
	}

	public function test_debug_consent_renders_when_only_legacy_alias_set() {
		$form = $this->build_form( $this->build_config( array( 'debug_data' => true ), self::URL_LEGACY ) );

		$this->assertStringContainsString( $this->debug_consent_marker(), $form->get_auth_screen() );
	}

	// ---------------------------------------------------------------
	// Admin debug panel reports the effective webhook target
	// ---------------------------------------------------------------

	public function test_admin_debug_row_shows_dashboard_host_when_only_dashboard_url_cached() {
		$this->set_cached_url( self::URL_CACHED );
		$form = $this->build_form( $this->build_config() );

		$row = $this->get_webhook_debug_row( $form );

		$this->assertStringNotContainsString( '(Empty)', $row );
		$this->assertStringContainsString( 'example.com', $row );
		$this->assertStringNotContainsString( 'cachedsecret', $row, 'webhook URLs are bearer secrets; the panel shows the host only' );
	}

	public function test_admin_debug_row_shows_config_host_without_token() {
		$form = $this->build_form( $this->build_config( array( 'url' => self::URL_CONFIG ) ) );

		$row = $this->get_webhook_debug_row( $form );

		$this->assertStringContainsString( 'example.com', $row );
		$this->assertStringNotContainsString( 'configsecret', $row, 'webhook URLs are bearer secrets; the panel shows the host only' );
	}

	public function test_admin_debug_row_reports_empty_when_no_url_anywhere() {
		$form = $this->build_form( $this->build_config() );

		$this->assertStringContainsString( '(Empty)', $this->get_webhook_debug_row( $form ) );
	}

	// ---------------------------------------------------------------
	// Pins: feature flags and existing gates still govern
	// ---------------------------------------------------------------

	public function test_config_url_enables_both_surfaces() {
		$form = $this->build_form( $this->build_config( array(
			'url'           => self::URL_CONFIG,
			'create_ticket' => true,
			'debug_data'    => true,
		) ) );

		$html = $form->get_auth_screen();

		$this->assertStringContainsString( $this->ticket_field_marker(), $html );
		$this->assertStringContainsString( $this->debug_consent_marker(), $html );
	}

	public function test_surfaces_hidden_when_no_url_anywhere() {
		$form = $this->build_form( $this->build_config( array(
			'create_ticket' => true,
			'debug_data'    => true,
		) ) );

		$html = $form->get_auth_screen();

		$this->assertStringNotContainsString( $this->ticket_field_marker(), $html );
		$this->assertStringNotContainsString( $this->debug_consent_marker(), $html );
		$this->assertFalse( $this->get_button_settings( $form )['create_ticket'] );
	}

	public function test_surfaces_hidden_when_flags_false_with_dashboard_url_cached() {
		$this->set_cached_url( self::URL_CACHED );
		$form = $this->build_form( $this->build_config( array(
			'create_ticket' => false,
			'debug_data'    => false,
		) ) );

		$html = $form->get_auth_screen();

		$this->assertStringNotContainsString( $this->ticket_field_marker(), $html );
		$this->assertStringNotContainsString( $this->debug_consent_marker(), $html );
	}

	public function test_ticket_field_hidden_when_reference_id_present_with_dashboard_url_cached() {
		$this->set_cached_url( self::URL_CACHED );
		$_GET['ref'] = 'existing-ticket-123';
		$form        = $this->build_form( $this->build_config( array( 'create_ticket' => true ) ) );

		$this->assertStringNotContainsString( $this->ticket_field_marker(), $form->get_auth_screen() );
	}

	// ---------------------------------------------------------------
	// Consent: debug data rides on the checkbox, not on the setting
	// ---------------------------------------------------------------

	/**
	 * Runs a real grant with the SaaS round-trip stubbed and returns the
	 * payload passed to `trustedlogin/{ns}/access/created` — the array
	 * Remote::maybe_send_webhook() posts verbatim.
	 */
	private function grant_and_capture_created_payload( $include_debug_data ) {
		$this->stub_saas_webhook_response( self::URL_CACHED );

		$config = $this->build_config( array( 'debug_data' => true, 'create_ticket' => true ) );
		add_filter( 'trustedlogin/' . self::NS . '/meets_ssl_requirement', '__return_true' );

		$captured = null;
		add_action(
			'trustedlogin/' . self::NS . '/access/created',
			function ( $data ) use ( &$captured ) {
				$captured = $data;
			}
		);

		$client = new Client( $config, false );
		$result = $client->grant_access( $include_debug_data );

		$this->assertIsArray( $result, 'grant must succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->assertIsArray( $captured, 'access/created must fire with a payload' );

		return $captured;
	}

	public function test_grant_without_consent_omits_debug_data_even_when_setting_enabled() {
		$payload = $this->grant_and_capture_created_payload( false );

		$this->assertArrayNotHasKey( 'debug_data', $payload );
	}

	public function test_grant_with_consent_includes_debug_data() {
		$payload = $this->grant_and_capture_created_payload( true );

		$this->assertArrayHasKey( 'debug_data', $payload );
		$this->assertIsString( $payload['debug_data'] );
		$this->assertNotSame( '', $payload['debug_data'] );
	}
}

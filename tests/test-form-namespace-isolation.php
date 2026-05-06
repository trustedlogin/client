<?php
/**
 * Integration tests asserting that Form output is namespace-isolated:
 *
 *   1. The grant button carries `data-tl-namespace="{ns}"` so the JS
 *      handler can dispatch to the right vendor's config.
 *   2. The inline JS payload writes to `window.trustedLogin["{ns}"]`
 *      via wp_add_inline_script, NOT to a global `tl_obj` via
 *      wp_localize_script (which collides when two TL-using plugins
 *      coexist on a single page — the second one wins, the first one
 *      sends AJAX requests to the wrong vendor).
 *   3. Two Form instances on the same request produce two distinct
 *      inline payloads keyed under their own namespaces, with neither
 *      writing the legacy `var tl_obj = ...` syntax.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class FormNamespaceIsolationTest extends WP_UnitTestCase {

	/** @var Client */
	private $client_a;

	/** @var Client */
	private $client_b;

	/** @var Form */
	private $form_a;

	/** @var Form */
	private $form_b;

	private const NS_A = 'isolation-vendor-a';
	private const NS_B = 'isolation-vendor-b';

	/** @var \WP_Scripts|null */
	private $original_wp_scripts;

	public function setUp(): void {
		parent::setUp();

		// Form::generate_button() short-circuits to '' for users that
		// can't create users — guard against accidental render in front-
		// end contexts. On multisite the test suite runs as, only
		// super_admins carry that cap.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$this->client_a = new Client( new Config( $this->minimal_config( self::NS_A, 'Vendor A' ) ) );
		$this->client_b = new Client( new Config( $this->minimal_config( self::NS_B, 'Vendor B' ) ) );

		// Each test starts from a clean wp_scripts() state so inline
		// data from a prior test doesn't bleed in. Snapshot the current
		// instance so tearDown() can restore it.
		global $wp_scripts;
		$this->original_wp_scripts = $wp_scripts;
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->form_a = $this->build_form_for( $this->client_a );
		$this->form_b = $this->build_form_for( $this->client_b );
	}

	public function tearDown(): void {

		// Restore the global $wp_scripts so subsequent tests don't see
		// the wp_register_script + wp_add_inline_script side-effects we
		// produced here.
		global $wp_scripts;
		$wp_scripts = $this->original_wp_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Client::__construct attaches namespaced action/filter callbacks
		// (init, admin_enqueue_scripts, ajax_*, etc.). Strip every hook
		// the two test instances may have registered so unrelated tests
		// running after us don't fire our callbacks.
		foreach ( array( self::NS_A, self::NS_B ) as $ns ) {
			$prefix = 'trustedlogin/' . $ns . '/';
			global $wp_filter;
			if ( is_array( $wp_filter ) ) {
				foreach ( array_keys( $wp_filter ) as $hook ) {
					if ( 0 === strpos( $hook, $prefix ) ) {
						remove_all_actions( $hook );
						remove_all_filters( $hook );
					}
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Form is held only as a local in Client::__construct (passed to
	 * Admin). Rebuild it from the Client's exposed dependencies via
	 * reflection so the test exercises the production wiring without
	 * needing a separate getter.
	 */
	private function build_form_for( Client $client ): Form {
		$ref          = new \ReflectionClass( '\TrustedLogin\Client' );
		$config       = $this->reflect_get( $ref, $client, 'config' );
		$logging      = $this->reflect_get( $ref, $client, 'logging' );
		$support_user = $this->reflect_get( $ref, $client, 'support_user' );
		$site_access  = $this->reflect_get( $ref, $client, 'site_access' );

		return new Form( $config, $logging, $support_user, $site_access );
	}

	private function reflect_get( \ReflectionClass $ref, $instance, string $name ) {
		$prop = $ref->getProperty( $name );
		$prop->setAccessible( true );
		return $prop->getValue( $instance );
	}

	private function minimal_config( string $ns, string $title ): array {
		return array(
			'role'    => 'editor',
			'auth'    => array(
				'api_key' => '0123456789abcdef',
			),
			'decay'   => WEEK_IN_SECONDS,
			'vendor'  => array(
				'namespace'   => $ns,
				'title'       => $title,
				'email'       => 'support+' . $ns . '@example.test',
				'website'     => 'https://' . $ns . '.example.test',
				'support_url' => 'https://' . $ns . '.example.test/support/',
			),
		);
	}

	/**
	 * The button HTML must carry data-tl-namespace so the JS handler
	 * can pick the right config when multiple TL plugins coexist.
	 */
	public function test_button_html_carries_namespace_data_attribute() {
		$html = $this->form_a->generate_button( array( 'tag' => 'button' ), false );

		$this->assertIsString( $html );
		$this->assertStringContainsString(
			sprintf( 'data-tl-namespace="%s"', self::NS_A ),
			$html,
			'button HTML must include data-tl-namespace so the click handler can dispatch correctly'
		);
	}

	/**
	 * The anchor variant (legacy default tag) must also carry the
	 * namespace attribute — same dispatch concern as <button>.
	 */
	public function test_anchor_html_carries_namespace_data_attribute() {
		$html = $this->form_a->generate_button( array( 'tag' => 'a' ), false );

		$this->assertStringContainsString(
			sprintf( 'data-tl-namespace="%s"', self::NS_A ),
			$html,
			'anchor variant must include data-tl-namespace too — the JS click handler is tag-agnostic'
		);
	}

	/**
	 * The inline script must be attached as a `before` payload (via
	 * wp_add_inline_script), not as a `var tl_obj = ...` blob (via
	 * wp_localize_script). The latter clobbers across namespaces.
	 */
	public function test_inline_script_uses_namespaced_root_not_global_tl_obj() {
		// register_assets() runs on `init` in production. The test's
		// $wp_scripts reset in setUp wipes that, so re-run it here.
		// wp_add_inline_script silently returns false when the handle
		// isn't registered yet — we'd be testing a no-op otherwise.
		$this->form_a->register_assets();
		$this->form_a->generate_button( array( 'tag' => 'button' ), false );

		$handle = 'trustedlogin-' . self::NS_A;
		$before = wp_scripts()->get_data( $handle, 'before' );

		$this->assertNotFalse( $before, 'inline script must be registered as `before` data on the script handle' );

		$payload = is_array( $before ) ? implode( "\n", array_filter( $before, 'is_string' ) ) : (string) $before;

		$this->assertStringContainsString(
			'window.trustedLogin',
			$payload,
			'payload must initialize the shared window.trustedLogin root'
		);
		$this->assertStringContainsString(
			sprintf( 'window.trustedLogin[%s]', wp_json_encode( self::NS_A ) ),
			$payload,
			'payload must write to window.trustedLogin[ns] specifically'
		);

		// Legacy localize storage uses 'data', not 'before'. After the
		// switch to wp_add_inline_script, no `data` payload should
		// remain — otherwise we're double-emitting and the global
		// tl_obj clobber comes back.
		$legacy_data = wp_scripts()->get_data( $handle, 'data' );
		if ( is_string( $legacy_data ) && '' !== $legacy_data ) {
			$this->assertStringNotContainsString(
				'var tl_obj',
				$legacy_data,
				'wp_localize_script must no longer be used; the legacy `var tl_obj = ...` blob would clobber across namespaces'
			);
		}
	}

	/**
	 * Two namespaces in the same request must produce two distinct
	 * inline payloads under two distinct handles, each keyed to its
	 * own namespace under window.trustedLogin.
	 */
	public function test_two_namespaces_produce_distinct_inline_payloads() {
		$this->form_a->register_assets();
		$this->form_b->register_assets();
		$this->form_a->generate_button( array( 'tag' => 'button' ), false );
		$this->form_b->generate_button( array( 'tag' => 'button' ), false );

		$before_a = wp_scripts()->get_data( 'trustedlogin-' . self::NS_A, 'before' );
		$before_b = wp_scripts()->get_data( 'trustedlogin-' . self::NS_B, 'before' );

		$this->assertNotFalse( $before_a, 'namespace A must register its own `before` payload' );
		$this->assertNotFalse( $before_b, 'namespace B must register its own `before` payload' );

		$payload_a = is_array( $before_a ) ? implode( "\n", array_filter( $before_a, 'is_string' ) ) : (string) $before_a;
		$payload_b = is_array( $before_b ) ? implode( "\n", array_filter( $before_b, 'is_string' ) ) : (string) $before_b;

		$this->assertStringContainsString(
			wp_json_encode( self::NS_A ),
			$payload_a,
			'namespace A payload must reference namespace A'
		);
		$this->assertStringNotContainsString(
			wp_json_encode( self::NS_B ),
			$payload_a,
			'namespace A payload must NOT leak namespace B identifiers'
		);

		$this->assertStringContainsString(
			wp_json_encode( self::NS_B ),
			$payload_b,
			'namespace B payload must reference namespace B'
		);
		$this->assertStringNotContainsString(
			wp_json_encode( self::NS_A ),
			$payload_b,
			'namespace B payload must NOT leak namespace A identifiers'
		);
	}
}

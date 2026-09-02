<?php
/**
 * Integration tests for the "Secured by TrustedLogin" badge on the Grant
 * Access screen (Form::get_auth_screen()). The badge links to a plain
 * end-customer explainer; nothing else on the consent screen changes. This
 * asserts the link's shape (href/target/rel/accessible name) and that the
 * pre-existing `trustedlogin/{ns}/template/auth` filter still lets a vendor
 * hide the whole badge.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class FormSecuredByBadgeTest extends WP_UnitTestCase {

	private const NS = 'badge-link-test';

	/** @var Form */
	private $form;

	/** @var callable */
	private $http_short_circuit;

	public function setUp(): void {
		parent::setUp();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		// get_auth_screen() runs a pre-flight reachability check (fetches
		// the vendor's public key) for any user without existing support
		// access. Short-circuit that HTTP call — the badge under test
		// renders identically either way, and this keeps the test hermetic.
		$this->http_short_circuit = static function () {
			return new \WP_Error( 'short_circuited', 'No network access in tests.' );
		};
		add_filter( 'pre_http_request', $this->http_short_circuit );

		$client = new Client( new Config( array(
			'role'   => 'editor',
			'auth'   => array( 'api_key' => '0123456789abcdef' ),
			'decay'  => WEEK_IN_SECONDS,
			'vendor' => array(
				'namespace'   => self::NS,
				'title'       => 'Badge Link Test',
				'email'       => 'support+' . self::NS . '@example.test',
				'website'     => 'https://' . self::NS . '.example.test',
				'support_url' => 'https://' . self::NS . '.example.test/support/',
			),
		) ) );

		$this->form = $this->build_form_for( $client );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_short_circuit );

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

	/**
	 * Form is held only as a local in Client::__construct. Rebuild it via
	 * reflection so the test exercises the production wiring.
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

	/**
	 * Isolates the `tl-{ns}-auth__secured_by` container from the full
	 * auth-screen HTML so assertions don't accidentally match unrelated
	 * markup elsewhere on the page.
	 */
	private function get_secured_by_html( string $auth_screen_html ): string {
		$this->assertMatchesRegularExpression(
			'#<div class="tl-' . preg_quote( self::NS, '#' ) . '-auth__secured_by">(.*?)</div>#s',
			$auth_screen_html,
			'auth screen must contain the secured_by container'
		);

		preg_match(
			'#<div class="tl-' . preg_quote( self::NS, '#' ) . '-auth__secured_by">(.*?)</div>#s',
			$auth_screen_html,
			$matches
		);

		return $matches[1];
	}

	public function test_badge_is_a_link_to_the_explainer_with_expected_attributes() {
		$badge_html = $this->get_secured_by_html( $this->form->get_auth_screen() );

		$this->assertStringContainsString(
			'https://www.trustedlogin.com/what-is-this/',
			$badge_html,
			'badge must link to the plain end-customer explainer'
		);
		$this->assertStringContainsString( 'utm_source=grant-screen', $badge_html );
		$this->assertStringContainsString( 'utm_medium=badge', $badge_html );
		$this->assertStringContainsString(
			'target="_blank"',
			$badge_html,
			'link must open in a new tab'
		);
		$this->assertStringContainsString(
			'rel="noopener"',
			$badge_html,
			'new-tab link must carry rel="noopener"'
		);
		$this->assertStringContainsString(
			'aria-label="What is TrustedLogin?"',
			$badge_html,
			'link must have the accessible name "What is TrustedLogin?"'
		);
	}

	/**
	 * Visual/content contract: same icon, same visible text, no button,
	 * no extra copy — only the wrapping tag changed, from plain markup to
	 * a link.
	 */
	public function test_badge_keeps_existing_icon_and_visible_text_only() {
		$badge_html = $this->get_secured_by_html( $this->form->get_auth_screen() );

		$this->assertStringContainsString( '<span class="trustedlogin-logo-medium"></span>', $badge_html );
		$this->assertStringContainsString( 'Secured by TrustedLogin', $badge_html );
		$this->assertStringNotContainsString( 'button', $badge_html, 'badge must not become a button' );

		// Exactly one link, exactly one occurrence of the visible copy —
		// guards against the badge growing a second/duplicate CTA.
		$this->assertSame( 1, substr_count( $badge_html, '<a ' ) );
		$this->assertSame( 1, substr_count( $badge_html, 'Secured by TrustedLogin' ) );
	}

	/**
	 * The pre-existing `trustedlogin/{ns}/template/auth` filter already
	 * let a vendor strip arbitrary markup from the auth screen before
	 * this change. Confirm it still lets a vendor remove the secured_by
	 * badge entirely now that its content is a link.
	 */
	public function test_vendor_can_still_hide_the_badge_via_the_template_filter() {
		$strip_badge = static function ( $template ) {
			return preg_replace(
				'#<div class="tl-\{\{ns\}\}-auth__secured_by">\{\{secured_by_trustedlogin\}\}</div>#',
				'',
				$template
			);
		};

		add_filter( 'trustedlogin/' . self::NS . '/template/auth', $strip_badge );

		$auth_screen_html = $this->form->get_auth_screen();

		remove_filter( 'trustedlogin/' . self::NS . '/template/auth', $strip_badge );

		$this->assertStringNotContainsString( 'Secured by TrustedLogin', $auth_screen_html );
		$this->assertStringNotContainsString( 'what-is-this', $auth_screen_html );
	}
}

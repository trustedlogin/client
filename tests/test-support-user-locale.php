<?php
/**
 * #140 — support_user/locale.
 *
 * Verifies that:
 *
 *   - A configured locale gets written to wp_usermeta during the same
 *     wp_insert_user() call (not a post-create update_user_meta race).
 *   - get_user_locale() returns the configured locale for the new user.
 *   - Malformed locale codes are ignored (site default flows through).
 *   - The trustedlogin/{ns}/support_user/locale filter can override.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class TrustedLoginSupportUserLocaleTest extends WP_UnitTestCase {

	private function build_config( array $overrides = array() ): Config {
		$base = array(
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'role'   => 'editor',
			'vendor' => array(
				'namespace'   => 'locale-test',
				'title'       => 'Locale Test',
				'email'       => 'support+{hash}@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
		);
		$base = array_replace_recursive( $base, $overrides );
		$config = new Config( $base );
		$config->validate();
		return $config;
	}

	public function setUp(): void {
		parent::setUp();

		// On multisite, the support user needs to be granted super-admin
		// (or at least added to the current blog) for SupportUser::create()
		// to find the support role. The role-create step itself works
		// fine on both; this is just so the cap chain resolves.
		if ( is_multisite() && function_exists( 'grant_super_admin' ) ) {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			grant_super_admin( $admin_id );
			wp_set_current_user( $admin_id );
		}
	}

	public function test_configured_locale_lands_on_new_support_user() {
		$config       = $this->build_config( array(
			'support_user' => array( 'locale' => 'fr_FR' ),
		) );
		$logging      = new Logging( $config );
		$support_role = new SupportRole( $config, $logging );
		$support_role->create();

		$support_user = new SupportUser( $config, $logging );
		$new_user_id  = $support_user->create();

		$this->assertNotInstanceOf( \WP_Error::class, $new_user_id, 'support user creation should succeed' );
		$this->assertSame( 'fr_FR', get_user_meta( (int) $new_user_id, 'locale', true ) );
	}

	public function test_malformed_locale_is_ignored() {
		$config       = $this->build_config( array(
			'support_user' => array( 'locale' => 'not-a-locale!' ),
		) );
		$logging      = new Logging( $config );
		$support_role = new SupportRole( $config, $logging );
		$support_role->create();

		$support_user = new SupportUser( $config, $logging );
		$new_user_id  = $support_user->create();

		$this->assertNotInstanceOf( \WP_Error::class, $new_user_id );
		// Garbage locale should NOT be written. wp_usermeta has nothing.
		$this->assertSame( '', (string) get_user_meta( (int) $new_user_id, 'locale', true ) );
	}

	public function test_filter_can_override_configured_locale() {
		$config = $this->build_config( array(
			'support_user' => array( 'locale' => 'de_DE' ),
		) );

		$swap_to_es = static function () { return 'es_ES'; };
		add_filter( 'trustedlogin/locale-test/support_user/locale', $swap_to_es, 10, 1 );

		try {
			$logging      = new Logging( $config );
			$support_role = new SupportRole( $config, $logging );
			$support_role->create();

			$support_user = new SupportUser( $config, $logging );
			$new_user_id  = $support_user->create();

			$this->assertNotInstanceOf( \WP_Error::class, $new_user_id );
			$this->assertSame( 'es_ES', get_user_meta( (int) $new_user_id, 'locale', true ) );
		} finally {
			remove_filter( 'trustedlogin/locale-test/support_user/locale', $swap_to_es, 10 );
		}
	}

	public function test_no_locale_setting_leaves_locale_unset() {
		$config       = $this->build_config(); // no support_user/locale
		$logging      = new Logging( $config );
		$support_role = new SupportRole( $config, $logging );
		$support_role->create();

		$support_user = new SupportUser( $config, $logging );
		$new_user_id  = $support_user->create();

		$this->assertNotInstanceOf( \WP_Error::class, $new_user_id );
		$this->assertSame( '', (string) get_user_meta( (int) $new_user_id, 'locale', true ) );
	}

	// -----------------------------------------------------------------
	//  Format-only validation accepts unusual but real locales.
	// -----------------------------------------------------------------

	/**
	 * @dataProvider valid_locales
	 */
	public function test_valid_locale_formats_are_accepted( string $locale ) {
		$config = $this->build_config( array(
			'support_user' => array( 'locale' => $locale ),
		) );

		// Drive the resolver via reflection so we don't have to spin a
		// full user creation for every locale.
		$logging      = new Logging( $config );
		$support_user = new SupportUser( $config, $logging );
		$rc           = new \ReflectionClass( SupportUser::class );
		$method       = $rc->getMethod( 'resolve_support_user_locale' );
		$method->setAccessible( true );

		$this->assertSame( $locale, $method->invoke( $support_user ) );
	}

	public function valid_locales(): array {
		return array(
			'standard de_DE'                 => array( 'de_DE' ),
			'pt_BR'                          => array( 'pt_BR' ),
			'WP variant suffix de_DE_formal' => array( 'de_DE_formal' ),
			'real WP.org locale pt_PT_ao90'  => array( 'pt_PT_ao90' ),
			'three-letter language ckb'      => array( 'ckb' ),
			'three-letter w/ region ckb_IQ'  => array( 'ckb_IQ' ),
		);
	}

	// -----------------------------------------------------------------
	//  WP locale-resolution behavior: get_user_locale() honors the
	//  per-user value the SDK wrote. switch_to_user_locale() flips
	//  the runtime locale to it.
	// -----------------------------------------------------------------

	public function test_get_user_locale_returns_configured_value() {
		$user_id = $this->grant_support_user_with_locale( 'fr_FR' );

		$this->assertSame( 'fr_FR', get_user_locale( $user_id ),
			'get_user_locale() should mirror the wp_usermeta `locale` row the SDK just wrote.' );
	}

	public function test_get_user_locale_falls_back_to_site_default_when_unset() {
		$user_id = $this->grant_support_user_with_locale( null );

		// In multisite, the harness blog locale stays at the site default.
		// We just assert that user-specific locale is NOT set — WP's
		// normal fallback chain takes over from there.
		$this->assertSame( '', (string) get_user_meta( $user_id, 'locale', true ) );
		$this->assertSame( get_locale(), get_user_locale( $user_id ),
			'With no user-level locale, get_user_locale should fall back to site locale.' );
	}

	public function test_switch_to_user_locale_activates_set_locale() {
		if ( ! function_exists( 'switch_to_user_locale' ) ) {
			$this->markTestSkipped( 'switch_to_user_locale() not available before WP 6.2.' );
		}

		$user_id = $this->grant_support_user_with_locale( 'de_DE' );

		$this->assertTrue( switch_to_user_locale( $user_id ) );
		try {
			$this->assertSame( 'de_DE', determine_locale(),
				'After switch_to_user_locale, determine_locale should return the user locale.' );
		} finally {
			restore_previous_locale();
		}
	}

	public function test_multiple_support_users_keep_independent_locales() {
		$id_a = $this->grant_support_user_with_locale( 'de_DE' );

		// Re-config under a different namespace so we can create a
		// SECOND support user with a different locale on the same site.
		$config_b = new Config( array(
			'auth'         => array( 'api_key' => 'b146ca31be6aa948' ),
			'role'         => 'editor',
			'vendor'       => array(
				'namespace'   => 'locale-test-b',
				'title'       => 'Locale Test B',
				'email'       => 'support+b+{hash}@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
			'support_user' => array( 'locale' => 'fr_FR' ),
		) );
		$config_b->validate();
		$logging_b = new Logging( $config_b );
		( new SupportRole( $config_b, $logging_b ) )->create();
		$id_b = ( new SupportUser( $config_b, $logging_b ) )->create();

		$this->assertNotInstanceOf( \WP_Error::class, $id_b );
		$this->assertSame( 'de_DE', get_user_locale( $id_a ) );
		$this->assertSame( 'fr_FR', get_user_locale( (int) $id_b ) );
	}

	public function test_locale_persists_across_simulated_logins() {
		$user_id = $this->grant_support_user_with_locale( 'es_ES' );

		// First "login" — simulate WP loading the user.
		wp_set_current_user( $user_id );
		$first = get_user_locale( $user_id );
		wp_set_current_user( 0 );

		// Drop the user object cache so we re-hydrate from the DB.
		clean_user_cache( $user_id );

		// Second "login".
		wp_set_current_user( $user_id );
		$second = get_user_locale( $user_id );

		$this->assertSame( 'es_ES', $first );
		$this->assertSame( 'es_ES', $second );
		$this->assertSame( $first, $second,
			'The user-locale row should survive cache eviction between sessions.' );
	}

	// -----------------------------------------------------------------
	//  Defensive re-assert: when a wp_pre_insert_user_data filter
	//  strips the `locale` arg before wp_insert_user writes it, the
	//  SDK still ends up with the requested locale in usermeta.
	// -----------------------------------------------------------------

	public function test_pre_insert_user_data_filter_stripping_locale_triggers_reassert() {
		$strip_locale = static function ( $data ) {
			if ( isset( $data['locale'] ) ) {
				unset( $data['locale'] );
			}
			return $data;
		};
		add_filter( 'wp_pre_insert_user_data', $strip_locale, 10, 1 );

		try {
			$user_id = $this->grant_support_user_with_locale( 'de_DE' );

			// Even though the filter stripped `locale` before insert,
			// SupportUser::create() detects the mismatch and re-asserts
			// via update_user_meta(). End-state must match the request.
			$this->assertSame( 'de_DE', (string) get_user_meta( $user_id, 'locale', true ) );
		} finally {
			remove_filter( 'wp_pre_insert_user_data', $strip_locale, 10 );
		}
	}

	public function test_filter_returning_empty_string_clears_configured_locale() {
		$clear = static function () { return ''; };
		add_filter( 'trustedlogin/locale-test/support_user/locale', $clear, 10, 1 );

		try {
			$config = $this->build_config( array(
				'support_user' => array( 'locale' => 'de_DE' ),
			) );
			$logging      = new Logging( $config );
			( new SupportRole( $config, $logging ) )->create();
			$user_id = ( new SupportUser( $config, $logging ) )->create();

			$this->assertNotInstanceOf( \WP_Error::class, $user_id );
			$this->assertSame( '', (string) get_user_meta( (int) $user_id, 'locale', true ),
				'Filter forcing empty should suppress the configured locale entirely.' );
		} finally {
			remove_filter( 'trustedlogin/locale-test/support_user/locale', $clear, 10 );
		}
	}

	public function test_filter_returning_garbage_is_rejected_by_format_check() {
		$garbage = static function () { return 'not a real locale!'; };
		add_filter( 'trustedlogin/locale-test/support_user/locale', $garbage, 10, 1 );

		try {
			$config = $this->build_config( array(
				'support_user' => array( 'locale' => 'de_DE' ),
			) );
			$logging      = new Logging( $config );
			( new SupportRole( $config, $logging ) )->create();
			$user_id = ( new SupportUser( $config, $logging ) )->create();

			$this->assertNotInstanceOf( \WP_Error::class, $user_id );
			$this->assertSame( '', (string) get_user_meta( (int) $user_id, 'locale', true ),
				'Garbage from the filter must be format-rejected, leaving locale unset.' );
		} finally {
			remove_filter( 'trustedlogin/locale-test/support_user/locale', $garbage, 10 );
		}
	}

	// -----------------------------------------------------------------
	//  Locale variants WordPress.org actually ships with — these
	//  should all be accepted by resolve_support_user_locale().
	// -----------------------------------------------------------------

	/**
	 * @dataProvider invalid_locales
	 */
	public function test_malformed_locale_formats_are_rejected( string $locale, string $why ) {
		$config = $this->build_config( array(
			'support_user' => array( 'locale' => $locale ),
		) );
		$logging      = new Logging( $config );
		$support_user = new SupportUser( $config, $logging );
		$rc           = new \ReflectionClass( SupportUser::class );
		$method       = $rc->getMethod( 'resolve_support_user_locale' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $support_user ), $why );
	}

	public function invalid_locales(): array {
		return array(
			'empty string'                  => array( '', 'no locale requested' ),
			'whitespace'                    => array( '   ', 'whitespace shouldn\'t pass' ),
			'shell injection attempt'       => array( 'de_DE; rm -rf /', 'shell-style payload must be rejected' ),
			'bare language too short'       => array( 'd', 'one-letter language code invalid' ),
			'wrong-case region'             => array( 'de_de', 'WP locale convention is uppercase region' ),
			'IETF style with hyphen'        => array( 'de-DE', 'WP uses underscores, not hyphens' ),
			'path traversal attempt'        => array( '../../etc/passwd', 'no slashes anywhere' ),
			'angle brackets'                => array( 'de_DE<script>', 'HTML-injection-style payload' ),
		);
	}

	// -----------------------------------------------------------------
	//  Helpers
	// -----------------------------------------------------------------

	/**
	 * Grant a support user, optionally with a configured locale, and
	 * return its user ID.
	 *
	 * @param string|null $locale Locale string to pin, or null for no
	 *                            `support_user/locale` config setting.
	 */
	private function grant_support_user_with_locale( $locale ): int {
		$overrides = array();
		if ( null !== $locale ) {
			$overrides['support_user'] = array( 'locale' => $locale );
		}
		$config       = $this->build_config( $overrides );
		$logging      = new Logging( $config );
		( new SupportRole( $config, $logging ) )->create();

		$new_user_id = ( new SupportUser( $config, $logging ) )->create();
		$this->assertNotInstanceOf( \WP_Error::class, $new_user_id );

		return (int) $new_user_id;
	}

	// =================================================================
	//  Coverage gaps from the audit pass
	// =================================================================

	public function test_get_user_locale_does_not_leak_across_current_user_switches() {
		$id_a = $this->grant_support_user_with_locale( 'de_DE' );

		// Create a second namespace + user with NO locale set.
		$config_b = new Config( array(
			'auth'   => array( 'api_key' => 'b146ca31be6aa948' ),
			'role'   => 'editor',
			'vendor' => array(
				'namespace'   => 'locale-test-no-locale',
				'title'       => 'Locale Test (no locale)',
				'email'       => 'support+nl+{hash}@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
		) );
		$config_b->validate();
		$logging_b = new Logging( $config_b );
		( new SupportRole( $config_b, $logging_b ) )->create();
		$id_b = ( new SupportUser( $config_b, $logging_b ) )->create();
		$this->assertNotInstanceOf( \WP_Error::class, $id_b );

		// Switch current user to B (no locale) and verify A's locale
		// is still de_DE — user-locale-per-user is fully independent
		// of `get_user_locale()` for the current user.
		wp_set_current_user( (int) $id_b );

		$this->assertSame( 'de_DE', get_user_locale( $id_a ) );
		$this->assertSame( get_locale(), get_user_locale( (int) $id_b ),
			'User with no locale meta falls through to site locale, not user A\'s locale.' );
	}

	public function test_locale_format_check_does_not_consult_get_available_languages() {
		// Pick a locale that almost certainly isn't installed on the
		// test box: zz_ZZ. If format-check is the gate (correct), the
		// locale lands in usermeta. If the SDK consulted
		// get_available_languages(), the locale would be rejected.
		$user_id = $this->grant_support_user_with_locale( 'zz_ZZ' );

		$this->assertSame( 'zz_ZZ', (string) get_user_meta( $user_id, 'locale', true ),
			'Format-only validation must accept unlisted locales — WordPress falls back to English on lookup.' );
	}

	public function test_en_US_is_accepted_despite_being_absent_from_get_available_languages() {
		// en_US is never in get_available_languages() (it IS the
		// default WP installs without a translation pack), but the
		// SDK must accept it as a legitimate locale request.
		$user_id = $this->grant_support_user_with_locale( 'en_US' );

		$this->assertSame( 'en_US', (string) get_user_meta( $user_id, 'locale', true ) );
	}

	public function test_strings_overrides_and_support_user_locale_coexist() {
		// Cross-feature smoke test: both #66 (strings overrides) and
		// #140 (support_user/locale) configured in one Config; both
		// active for the same Client.
		$config = new Config( array(
			'auth'         => array( 'api_key' => 'cross1234aabbccdd' ),
			'role'         => 'editor',
			'vendor'       => array(
				'namespace'   => 'cross-feature-test',
				'title'       => 'Cross Feature Test',
				'email'       => 'support+cross+{hash}@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
			'support_user' => array( 'locale' => 'fr_FR' ),
			'strings'      => array(
				Strings::SECURED_BY_TRUSTEDLOGIN => 'Cross-feature branding',
			),
		) );
		$config->validate();
		Strings::init( $config );

		// Create the user.
		$logging = new Logging( $config );
		( new SupportRole( $config, $logging ) )->create();
		$user_id = ( new SupportUser( $config, $logging ) )->create();

		// Locale landed.
		$this->assertNotInstanceOf( \WP_Error::class, $user_id );
		$this->assertSame( 'fr_FR', get_user_locale( (int) $user_id ) );

		// Override still applies.
		$this->assertSame(
			'Cross-feature branding',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);

		Strings::reset();
	}
}

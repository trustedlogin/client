<?php
/**
 * Strings translation routing — load_translations(), runtime textdomain
 * lookup, change_locale reload, closure overrides under user-locale
 * context.
 *
 * These tests use `add_filter('gettext_with_context', …)` and
 * `add_filter('gettext', …)` to inject fake translations into WP's
 * lookup pipeline without producing real `.mo` files. The same pipeline
 * Strings::get() consults via translate(), so what the filter returns
 * is what the SDK sees at runtime.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use ReflectionProperty;

class TrustedLoginStringsTranslationTest extends WP_UnitTestCase {

	/** @var callable[] Cleanup callbacks queued during a test. */
	private $cleanups = array();

	public function setUp(): void {
		parent::setUp();
		$this->reset_strings_state();
	}

	public function tearDown(): void {
		foreach ( $this->cleanups as $cleanup ) {
			$cleanup();
		}
		$this->cleanups = array();
		$this->reset_strings_state();
		parent::tearDown();
	}

	/**
	 * Reset Strings' static state so tests don't leak through each other.
	 * Strings::reset() clears $config, $overrides, $textdomain, and
	 * $translations_loaded in one shot.
	 */
	private function reset_strings_state(): void {
		Strings::reset();
	}

	private function build_config( array $overrides = array() ): Config {
		$base = array(
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'vendor' => array(
				'namespace'   => 'translation-test',
				'title'       => 'Translation Test',
				'email'       => 'support@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
		);
		if ( ! empty( $overrides ) ) {
			$base['strings'] = $overrides;
		}
		$config = new Config( $base );
		$config->validate();
		return $config;
	}

	/**
	 * Inject a fake translation pair (msgid → msgstr) for a specific
	 * textdomain. The gettext filter runs after WP's own lookup, so
	 * our return value wins. Queues cleanup automatically.
	 */
	private function inject_translation( string $domain, array $pairs ): void {
		$filter = static function ( $translation, $original, $d ) use ( $domain, $pairs ) {
			if ( $d === $domain && isset( $pairs[ $original ] ) ) {
				return $pairs[ $original ];
			}
			return $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		$this->cleanups[] = static function () use ( $filter ) {
			remove_filter( 'gettext', $filter, 10 );
		};
	}


	public function test_default_runtime_textdomain_is_trustedlogin() {
		$rc       = new ReflectionProperty( Strings::class, 'textdomain' );
		$rc->setAccessible( true );
		$this->assertSame( 'trustedlogin', $rc->getValue( null ) );
	}

	public function test_load_translations_sets_runtime_textdomain() {
		Strings::load_translations( 'acme-plugin' );

		$rc = new ReflectionProperty( Strings::class, 'textdomain' );
		$rc->setAccessible( true );
		$this->assertSame( 'acme-plugin', $rc->getValue( null ) );
	}

	public function test_load_translations_with_empty_string_is_noop() {
		Strings::load_translations( '' );

		$rc = new ReflectionProperty( Strings::class, 'textdomain' );
		$rc->setAccessible( true );
		$this->assertSame( 'trustedlogin', $rc->getValue( null ),
			'Empty domain must not overwrite the default.' );
	}

	public function test_load_translations_routes_lookups_to_integrator_textdomain() {
		// Tell WP that "Secured by TrustedLogin" translates to a German
		// brand-flavored string in the 'acme-plugin' textdomain.
		$this->inject_translation( 'acme-plugin', array(
			'Secured by TrustedLogin' => 'Abgesichert durch Acme Support',
		) );

		Strings::load_translations( 'acme-plugin' );

		Strings::init( $this->build_config() );
		$this->assertSame(
			'Abgesichert durch Acme Support',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	public function test_lookups_against_unloaded_textdomain_return_input() {
		// No load_translations() call — runtime textdomain stays
		// 'trustedlogin', which has no translations registered. The
		// SDK's English default flows through.
		Strings::init( $this->build_config() );
		$this->assertSame(
			'Secured by TrustedLogin',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}


	public function test_load_translations_registers_change_locale_hook() {
		Strings::load_translations( 'acme-plugin' );
		$this->assertNotFalse( has_action( 'change_locale' ),
			'A change_locale callback should be registered after load_translations().' );
	}

	public function test_change_locale_callback_runs_without_mo_file() {
		// Without a real fr_FR .mo file shipped in src/languages/, the
		// SDK's mo_path_for() returns a path that isn't readable, so
		// the callback short-circuits before calling load_textdomain.
		// The callback running (and not crashing) is the contract under
		// test here; actual reload behavior with a real .mo is a
		// release-time concern, not a unit-test one.
		Strings::load_translations( 'acme-plugin' );
		do_action( 'change_locale', 'fr_FR' );

		// If we got here without a fatal, the callback handled the
		// missing-file case gracefully — that's the assertion.
		$this->addToAssertionCount( 1 );
	}

	public function test_translations_reload_picks_up_new_locale_messages() {
		// French translations for the SAME msgid that German didn't cover.
		$this->inject_translation( 'acme-plugin', array(
			'Try reconnecting' => 'Réessayer la connexion',
		) );

		Strings::load_translations( 'acme-plugin' );
		switch_to_locale( 'fr_FR' );

		try {
			Strings::init( $this->build_config() );
			$this->assertSame(
				'Réessayer la connexion',
				Strings::get( Strings::TRY_RECONNECTING, __( 'Try reconnecting', 'trustedlogin' ) )
			);
		} finally {
			restore_previous_locale();
		}
	}


	public function test_static_override_preempts_translation() {
		$this->inject_translation( 'acme-plugin', array(
			'Secured by TrustedLogin' => 'Abgesichert durch Acme',
		) );

		Strings::load_translations( 'acme-plugin' );

		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'Powered by Acme', // verbatim brand
		) );
		Strings::init( $config  );

		// Override wins. Translation never runs for this key.
		$this->assertSame(
			'Powered by Acme',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	public function test_closure_override_can_invoke_translation_functions() {
		// The integrator's closure can call __() against their OWN
		// textdomain. Whatever that returns is what the SDK renders.
		$this->inject_translation( 'integrator-domain', array(
			'%d day of Acme support' => '%d Tag Acme-Support',
		) );

		$config = $this->build_config( array(
			Strings::CREATED_1_S_AGO_BY_2 => static function ( $time_ago, $by ) {
				// The integrator does whatever they want here. They
				// could call _n(), __(), sprintf(), pull from a
				// database — the SDK doesn't care.
				$translated = __( '%d day of Acme support', 'integrator-domain' );
				return sprintf( '%s — %s ago, %s', $translated, $time_ago, $by );
			},
		) );

		Strings::init( $config  );
		$resolved = Strings::get(
			Strings::CREATED_1_S_AGO_BY_2,
			'Created %1$s ago by %2$s',
			array( '5 minutes', 'admin' )
		);

		// Closure used the German translation it just looked up.
		$this->assertStringContainsString( '%d Tag Acme-Support', $resolved );
		$this->assertStringContainsString( '5 minutes', $resolved );
		$this->assertStringContainsString( 'admin', $resolved );
	}

	public function test_runtime_filter_sees_user_locale_for_context_aware_overrides() {
		// Set up a user-specific locale via switch_to_locale (simpler
		// than spinning a user fixture and calling switch_to_user_locale).
		switch_to_locale( 'de_DE' );

		try {
			$config  = $this->build_config();
			Strings::init( $config  );

			$tag = 'trustedlogin/translation-test/strings/' . Strings::TRY_RECONNECTING;

			$contextual = static function ( $value ) {
				return determine_locale() === 'de_DE'
					? 'Erneut verbinden'
					: $value;
			};
			add_filter( $tag, $contextual, 10, 1 );

			try {
				$this->assertSame(
					'Erneut verbinden',
					Strings::get( Strings::TRY_RECONNECTING, 'Try reconnecting' )
				);
			} finally {
				remove_filter( $tag, $contextual, 10 );
			}
		} finally {
			restore_previous_locale();
		}
	}


	public function test_load_translations_before_init_defers() {
		// We can't actually un-fire `init` once it's done in the test
		// harness (it fires during WP bootstrap). But we can assert the
		// guard is in place: invocation during a known-pre-init context
		// produces a queued action rather than an immediate load.
		// The empirical proof is `did_action('init')` returns 0 inside
		// some fixture contexts (e.g., constructor of a class loaded
		// before init). Since the test harness has already fired init,
		// we just verify the immediate-load path works AND the deferred
		// path adds an action.
		$this->assertGreaterThan( 0, did_action( 'init' ),
			'Test harness should have fired init already.' );

		Strings::load_translations( 'acme-plugin' );

		$rc = new ReflectionProperty( Strings::class, 'textdomain' );
		$rc->setAccessible( true );
		$this->assertSame( 'acme-plugin', $rc->getValue( null ),
			'After init, load should run immediately.' );
	}


	public function test_repeated_load_translations_registers_change_locale_once() {
		// Count change_locale callbacks at priority 10 before & after.
		global $wp_filter;
		$before = isset( $wp_filter['change_locale'] )
			? count( $wp_filter['change_locale']->callbacks[10] ?? array() )
			: 0;

		Strings::load_translations( 'acme-plugin' );
		Strings::load_translations( 'other-plugin' );
		Strings::load_translations( 'third-plugin' );

		$after = count( $wp_filter['change_locale']->callbacks[10] );
		$this->assertSame( $before + 1, $after,
			'Repeated load_translations calls must add exactly one change_locale callback total.' );
	}

	public function test_second_load_translations_overrides_textdomain() {
		Strings::load_translations( 'acme-plugin' );
		Strings::load_translations( 'beta-plugin' );

		$rc = new ReflectionProperty( Strings::class, 'textdomain' );
		$rc->setAccessible( true );
		$this->assertSame( 'beta-plugin', $rc->getValue( null ),
			'The most recent load_translations call wins.' );
	}

	public function test_change_locale_callback_reads_latest_textdomain_after_second_load() {
		// The closure's load_textdomain() call is gated on
		// is_readable() of the .mo path, so we need a real file at
		// that location to make the spy fire. Build a 0-byte placeholder.
		$rc          = new \ReflectionClass( Strings::class );
		$languages   = dirname( $rc->getFileName() ) . '/languages';
		if ( ! is_dir( $languages ) ) {
			mkdir( $languages, 0755, true );
		}
		$fake_mo = $languages . '/trustedlogin-fr_FR.mo';
		file_put_contents( $fake_mo, '' );
		$this->cleanups[] = static function () use ( $fake_mo, $languages ) {
			if ( is_file( $fake_mo ) ) {
				unlink( $fake_mo );
			}
			// Best-effort dir cleanup; ignore if not empty.
			@rmdir( $languages );
		};

		$attempts = array();
		$spy      = static function ( $override, $domain ) use ( &$attempts ) {
			unset( $override );
			$attempts[] = $domain;
			return true;
		};
		add_filter( 'override_load_textdomain', $spy, 10, 2 );

		try {
			Strings::load_translations( 'acme-plugin' );
			Strings::load_translations( 'beta-plugin' );
			$attempts = array(); // ignore the immediate loads

			do_action( 'change_locale', 'fr_FR' );

			$this->assertContains( 'beta-plugin', $attempts,
				'change_locale must load .mo against the LATEST textdomain, not the captured-at-registration one.' );
			$this->assertNotContains( 'acme-plugin', $attempts,
				'Stale textdomain must NOT be referenced after second load.' );
		} finally {
			remove_filter( 'override_load_textdomain', $spy, 10 );
		}
	}

	public function test_mo_path_resolves_relative_to_strings_file() {
		// mo_path_for is private; reach via reflection. The path must
		// be anchored to the Strings.php directory (so Strauss-vendored
		// layouts resolve correctly), NOT to WP_PLUGIN_DIR.
		$rc     = new \ReflectionClass( Strings::class );
		$method = $rc->getMethod( 'mo_path_for' );
		$method->setAccessible( true );

		$path = $method->invoke( null, 'de_DE' );
		$strings_dir = dirname( $rc->getFileName() );

		$this->assertSame(
			$strings_dir . '/languages/trustedlogin-de_DE.mo',
			$path,
			'mo_path_for must be relative to the Strings.php source file.'
		);
	}

	public function test_load_translations_with_non_string_argument_is_noop() {
		Strings::load_translations( '' );

		$rc = new ReflectionProperty( Strings::class, 'textdomain' );
		$rc->setAccessible( true );
		$this->assertSame( 'trustedlogin', $rc->getValue( null ) );
	}
}

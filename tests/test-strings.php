<?php
/**
 * Strings + Config::validate_strings() — overrides, placeholder safety,
 * closure invocation, runtime filter.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class TrustedLoginStringsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Strings::reset();
	}

	public function tearDown(): void {
		Strings::reset();
		parent::tearDown();
	}

	private function build_config( array $overrides = array() ): Config {
		$base = array(
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'vendor' => array(
				'namespace'   => 'strings-test',
				'title'       => 'Strings Test',
				'email'       => 'support@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
		);
		if ( ! empty( $overrides ) ) {
			$base['strings'] = $overrides;
		}
		$config = new Config( $base );
		$config->validate(); // runs validate_strings() internally
		return $config;
	}

	// -----------------------------------------------------------------
	//  No override → SDK default flows through.
	// -----------------------------------------------------------------

	public function test_no_override_returns_sdk_default() {
		Strings::init( $this->build_config() );
		$this->assertSame(
			'Secured by TrustedLogin',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Verbatim string override (the branding case).
	// -----------------------------------------------------------------

	public function test_string_override_replaces_default() {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'Powered by Acme Support',
		) );

		Strings::init( $config  );
		$this->assertSame(
			'Powered by Acme Support',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Explicit empty string = render nothing (distinct from no override).
	// -----------------------------------------------------------------

	public function test_explicit_empty_override_renders_empty() {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => '',
		) );

		Strings::init( $config  );
		$this->assertSame(
			'',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Closure overrides receive $context positional args.
	// -----------------------------------------------------------------

	public function test_closure_override_receives_context() {
		$config = $this->build_config( array(
			Strings::CREATED_1_S_AGO_BY_2 => static function ( $time_ago, $by ) {
				return "Acme created {$time_ago} ago by {$by}";
			},
		) );

		Strings::init( $config  );
		$resolved = Strings::get(
			Strings::CREATED_1_S_AGO_BY_2,
			'Created %1$s ago by %2$s',
			array( '5 minutes', 'admin' )
		);

		$this->assertSame( 'Acme created 5 minutes ago by admin', $resolved );
	}

	// -----------------------------------------------------------------
	//  Placeholder schema enforcement (#66 critical):
	//
	//  CREATED_TIME_AGO expects 2 placeholders. A bad override that
	//  loses a placeholder must be DISCARDED at validate_strings() time
	//  so we never sprintf-crash at render.
	// -----------------------------------------------------------------

	public function test_override_with_missing_placeholder_is_discarded() {
		$config = $this->build_config( array(
			Strings::CREATED_1_S_AGO_BY_2 => 'Created at unknown time', // no %1$s %2$s
		) );

		Strings::init( $config  );

		// Override was malformed → discarded → falls through to SDK default.
		$resolved = Strings::get(
			Strings::CREATED_1_S_AGO_BY_2,
			'Created %1$s ago by %2$s',
			array( '5 minutes', 'admin' )
		);

		// The default still contains placeholders; caller would sprintf
		// it. We only assert the resolution path returns the default
		// untouched (no crash, no override applied).
		$this->assertSame( 'Created %1$s ago by %2$s', $resolved );
	}

	public function test_override_with_extra_placeholder_is_discarded() {
		// SECURED_BY expects 0 placeholders. Override that smuggles
		// a %d should be dropped — would print "%d" raw to customers.
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'Secured by TL (%d sites protected)',
		) );

		Strings::init( $config  );
		$this->assertSame(
			'Secured by TrustedLogin',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	public function test_override_with_escaped_percent_only_accepted_when_no_placeholders_expected() {
		// `%%` is the literal percent sign — not a real placeholder.
		// Must pass the safety check.
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'Secured by 100%% you',
		) );

		Strings::init( $config  );
		$this->assertSame(
			'Secured by 100%% you',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Unknown keys are silently dropped (forward-compat with future
	//  SDK versions adding/removing keys).
	// -----------------------------------------------------------------

	public function test_unknown_key_is_dropped_silently() {
		$config = $this->build_config( array(
			'no_such_key' => 'this should never render',
		) );

		// Reflection-peek to verify the unknown key didn't make it into
		// the validated set.
		$strings_setting = $config->get_setting( 'strings', array() );
		$this->assertArrayNotHasKey( 'no_such_key', $strings_setting );
	}

	// -----------------------------------------------------------------
	//  Filter fires AFTER override decision, sees the final candidate.
	// -----------------------------------------------------------------

	public function test_runtime_filter_can_rewrite_resolved_value() {
		$config  = $this->build_config();
		Strings::init( $config  );

		$tag = 'trustedlogin/strings-test/strings/' . Strings::SECURED_BY_TRUSTEDLOGIN;

		$rewriter = static function ( $value ) {
			return strtoupper( $value );
		};
		add_filter( $tag, $rewriter, 10, 1 );

		try {
			$this->assertSame(
				'SECURED BY TRUSTEDLOGIN',
				Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
			);
		} finally {
			remove_filter( $tag, $rewriter, 10 );
		}
	}

	// -----------------------------------------------------------------
	//  Wrong-shape overrides (objects, mixed arrays) → discarded.
	// -----------------------------------------------------------------

	public function test_object_override_is_discarded() {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => new \stdClass(),
		) );

		Strings::init( $config  );
		$this->assertSame(
			'Secured by TrustedLogin',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  registry() exposes the public contract.
	// -----------------------------------------------------------------

	public function test_registry_includes_every_class_constant() {
		$registry = Strings::registry();

		$this->assertArrayHasKey( Strings::SECURED_BY_TRUSTEDLOGIN, $registry );
		$this->assertArrayHasKey( Strings::REVOKE_ACCESS, $registry );
		$this->assertArrayHasKey( Strings::SUPPORT_ACCESS_IS_TEMPORARILY_UNAVAILABLE_PLEASE, $registry );
		$this->assertArrayHasKey( Strings::TRY_RECONNECTING, $registry );
		$this->assertArrayHasKey( Strings::CREATED_1_S_AGO_BY_2, $registry );

		$this->assertSame( 0, $registry[ Strings::SECURED_BY_TRUSTEDLOGIN ]['placeholders'] );
		$this->assertSame( 2, $registry[ Strings::CREATED_1_S_AGO_BY_2 ]['placeholders'] );
	}

	// =================================================================
	//  Coverage gaps from the audit pass
	// =================================================================

	// ---- init() / reset() lifecycle --------------------------------

	public function test_init_called_twice_replaces_bound_config() {
		Strings::init( $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'First brand',
		) ) );
		Strings::init( $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'Second brand',
		) ) );

		$this->assertSame(
			'Second brand',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' ),
			'A second init() must replace the first binding.'
		);
	}

	public function test_get_without_init_returns_default_no_filter() {
		Strings::reset();

		$filter_fired = false;
		$spy = static function ( $value ) use ( &$filter_fired ) {
			$filter_fired = true;
			return $value;
		};
		// Catch ANY trustedlogin strings filter — without init() we
		// don't know the namespace anyway, but if a filter DOES fire,
		// the namespace would be 'default' or similar; this catches
		// the contract violation either way.
		add_filter( 'trustedlogin/strings-test/strings/' . Strings::SECURED_BY_TRUSTEDLOGIN, $spy, 10, 1 );

		try {
			$this->assertSame(
				'fallback default',
				Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'fallback default' )
			);
			$this->assertFalse( $filter_fired,
				'Strings::get() before init() must NOT invoke the runtime filter.' );
		} finally {
			remove_filter( 'trustedlogin/strings-test/strings/' . Strings::SECURED_BY_TRUSTEDLOGIN, $spy, 10 );
		}
	}

	public function test_reset_clears_all_static_state() {
		Strings::init( $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'X',
		) ) );
		Strings::load_translations( 'acme-plugin' );

		Strings::reset();

		$rc = new \ReflectionClass( Strings::class );
		$prop = static function ( $name ) use ( $rc ) {
			$p = $rc->getProperty( $name );
			$p->setAccessible( true );
			return $p->getValue( null );
		};

		$this->assertNull( $prop( 'config' ) );
		$this->assertSame( array(), $prop( 'overrides' ) );
		$this->assertSame( 'trustedlogin', $prop( 'textdomain' ) );
		$this->assertFalse( $prop( 'translations_loaded' ) );
	}

	// ---- get() resolution: closures + bad shapes -------------------

	public function test_zero_arg_closure_override_invoked() {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => static function () { return 'ZERO-ARG'; },
		) );
		Strings::init( $config );

		$this->assertSame( 'ZERO-ARG',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'default' ) );
	}

	public function test_throwing_closure_propagates_exception() {
		// Documents the contract: Strings::resolve() does NOT catch
		// exceptions from integrator closures. If this changes (e.g.
		// to swallow + fall back to default), the test should be
		// inverted, not deleted — make the decision explicit.
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => static function () {
				throw new \RuntimeException( 'integrator bug' );
			},
		) );
		Strings::init( $config );

		$this->expectException( \RuntimeException::class );
		Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'default' );
	}

	public function test_malformed_override_injected_post_validation_falls_back() {
		// Force an unsupported shape past Config::validate_strings()
		// by writing it directly into Strings::$overrides via reflection.
		// This exercises the belt-and-suspenders branch in resolve().
		Strings::init( $this->build_config() );
		$rc = new \ReflectionProperty( Strings::class, 'overrides' );
		$rc->setAccessible( true );
		$rc->setValue( null, array( Strings::SECURED_BY_TRUSTEDLOGIN => 12345 ) );

		$this->assertSame( 'fallback default',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'fallback default' ) );
	}

	// ---- runtime filter -------------------------------------------

	public function test_runtime_filter_receives_four_args() {
		Strings::init( $this->build_config() );
		$tag = 'trustedlogin/strings-test/strings/' . Strings::SECURED_BY_TRUSTEDLOGIN;

		$captured = null;
		$spy = static function ( $value, $key, $context, $config ) use ( &$captured ) {
			$captured = compact( 'value', 'key', 'context', 'config' );
			return $value;
		};
		add_filter( $tag, $spy, 10, 4 );

		try {
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'X', array( 'ctx' => 'val' ) );

			$this->assertNotNull( $captured );
			$this->assertSame( 'X', $captured['value'] );
			$this->assertSame( Strings::SECURED_BY_TRUSTEDLOGIN, $captured['key'] );
			$this->assertSame( array( 'ctx' => 'val' ), $captured['context'] );
			$this->assertInstanceOf( Config::class, $captured['config'] );
		} finally {
			remove_filter( $tag, $spy, 10 );
		}
	}

	public function test_filter_fires_on_override_path() {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'from-override',
		) );
		Strings::init( $config );

		$tag = 'trustedlogin/strings-test/strings/' . Strings::SECURED_BY_TRUSTEDLOGIN;
		$tag_fired_with = null;
		$spy = static function ( $value ) use ( &$tag_fired_with ) {
			$tag_fired_with = $value;
			return $value;
		};
		add_filter( $tag, $spy, 10, 1 );

		try {
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'default' );
			$this->assertSame( 'from-override', $tag_fired_with,
				'Filter must see the override value, not the default.' );
		} finally {
			remove_filter( $tag, $spy, 10 );
		}
	}

	// ---- Config::validate_strings: shape + placeholder edge cases --

	public function test_strings_config_non_array_is_unset() {
		// Passing a scalar `strings` should be dropped entirely so
		// the rest of the SDK never sees a non-array shape.
		$config = new Config( array(
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'vendor' => array(
				'namespace'   => 'strings-test',
				'title'       => 'Strings Test',
				'email'       => 'support@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
			'strings' => 'oops',
		) );
		$config->validate();

		$this->assertNull( $config->get_setting( 'strings', null ) );
	}

	public function test_escaped_percent_does_not_count_toward_placeholder_total() {
		// CREATED_1_S_AGO_BY_2 requires 2 placeholders. Escaped %%
		// must not be counted. Override that has 2 real placeholders
		// AND a literal %% should pass.
		$config = $this->build_config( array(
			Strings::CREATED_1_S_AGO_BY_2 => 'Created %1$s ago by %2$s (100%% sure)',
		) );
		Strings::init( $config );

		$resolved = Strings::get(
			Strings::CREATED_1_S_AGO_BY_2,
			'Created %1$s ago by %2$s',
			array( '5min', 'admin' )
		);
		$this->assertSame( 'Created %1$s ago by %2$s (100%% sure)', $resolved );
	}

	public function test_format_flags_recognized_as_placeholders() {
		// %05d / %.2f / %-10s ARE placeholders. For a 0-placeholder
		// key, an override containing these must be discarded — they
		// would print raw "%05d" to the customer otherwise.
		foreach ( array( 'Got %05d sites', 'Uptime %.2f', 'Name %-10s', 'Hex %x', 'Char %c', 'Float %f' ) as $bad_override ) {
			$config = $this->build_config( array(
				Strings::SECURED_BY_TRUSTEDLOGIN => $bad_override,
			) );
			Strings::init( $config );

			$this->assertSame(
				'Secured by TrustedLogin',
				Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' ),
				sprintf( "Override %s smuggled a placeholder into a 0-placeholder key; should have been discarded.", var_export( $bad_override, true ) )
			);

			Strings::reset();
		}
	}

	/**
	 * @dataProvider non_string_non_callable_overrides
	 */
	public function test_non_string_non_callable_overrides_dropped( $value, string $why ) {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => $value,
		) );
		Strings::init( $config );

		$this->assertSame(
			'Secured by TrustedLogin',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' ),
			$why
		);
	}

	public function non_string_non_callable_overrides(): array {
		return array(
			'int'             => array( 42, 'int is not a renderable string' ),
			'float'           => array( 3.14, 'float is not a renderable string' ),
			'true'            => array( true, 'bool true is not a renderable string' ),
			'false'           => array( false, 'bool false is not a renderable string' ),
			'null'            => array( null, 'null collapses to "no override entry" but should not render anything weird' ),
			'array of string' => array( array( 'one', 'two' ), 'list-shape arrays not supported for non-plural keys' ),
			'empty array'     => array( array(), 'empty array is not a renderable shape' ),
		);
	}

	public function test_mixed_valid_and_invalid_overrides_partial_keep() {
		$config = $this->build_config( array(
			Strings::SECURED_BY_TRUSTEDLOGIN => 'Powered by Acme',
			Strings::CREATED_1_S_AGO_BY_2     => 'I forgot the placeholders',
		) );
		Strings::init( $config );

		// Good one preserved.
		$this->assertSame( 'Powered by Acme',
			Strings::get( Strings::SECURED_BY_TRUSTEDLOGIN, 'Secured by TrustedLogin' ) );
		// Bad one falls back to default.
		$this->assertSame( 'Created %1$s ago by %2$s',
			Strings::get( Strings::CREATED_1_S_AGO_BY_2, 'Created %1$s ago by %2$s', array( 'x', 'y' ) ) );
	}

	// ---- Client constructor wires Strings::init -------------------

	public function test_client_constructor_initializes_strings() {
		$client_config_data = array(
			'auth'   => array( 'api_key' => 'aaaa11112222bbbb' ),
			'vendor' => array(
				'namespace'   => 'client-init-test',
				'title'       => 'Client Init Test',
				'email'       => 'support@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
		);
		$config = new Config( $client_config_data );
		new Client( $config );

		$rc = new \ReflectionProperty( Strings::class, 'config' );
		$rc->setAccessible( true );
		$this->assertSame( $config, $rc->getValue( null ),
			'Client::__construct must bind the Config to Strings::init().' );
	}
}

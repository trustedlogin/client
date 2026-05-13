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
		$strings = new Strings( $this->build_config() );
		$this->assertSame(
			'Secured by TrustedLogin',
			$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Verbatim string override (the branding case).
	// -----------------------------------------------------------------

	public function test_string_override_replaces_default() {
		$config = $this->build_config( array(
			Strings::SECURED_BY => 'Powered by Acme Support',
		) );

		$strings = new Strings( $config );
		$this->assertSame(
			'Powered by Acme Support',
			$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Explicit empty string = render nothing (distinct from no override).
	// -----------------------------------------------------------------

	public function test_explicit_empty_override_renders_empty() {
		$config = $this->build_config( array(
			Strings::SECURED_BY => '',
		) );

		$strings = new Strings( $config );
		$this->assertSame(
			'',
			$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  Closure overrides receive $context positional args.
	// -----------------------------------------------------------------

	public function test_closure_override_receives_context() {
		$config = $this->build_config( array(
			Strings::CREATED_TIME_AGO => static function ( $time_ago, $by ) {
				return "Acme created {$time_ago} ago by {$by}";
			},
		) );

		$strings = new Strings( $config );
		$resolved = $strings->get(
			Strings::CREATED_TIME_AGO,
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
			Strings::CREATED_TIME_AGO => 'Created at unknown time', // no %1$s %2$s
		) );

		$strings = new Strings( $config );

		// Override was malformed → discarded → falls through to SDK default.
		$resolved = $strings->get(
			Strings::CREATED_TIME_AGO,
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
			Strings::SECURED_BY => 'Secured by TL (%d sites protected)',
		) );

		$strings = new Strings( $config );
		$this->assertSame(
			'Secured by TrustedLogin',
			$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
		);
	}

	public function test_override_with_escaped_percent_only_accepted_when_no_placeholders_expected() {
		// `%%` is the literal percent sign — not a real placeholder.
		// Must pass the safety check.
		$config = $this->build_config( array(
			Strings::SECURED_BY => 'Secured by 100%% you',
		) );

		$strings = new Strings( $config );
		$this->assertSame(
			'Secured by 100%% you',
			$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
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
		$strings = new Strings( $config );

		$tag = 'trustedlogin/strings-test/strings/' . Strings::SECURED_BY;

		$rewriter = static function ( $value ) {
			return strtoupper( $value );
		};
		add_filter( $tag, $rewriter, 10, 1 );

		try {
			$this->assertSame(
				'SECURED BY TRUSTEDLOGIN',
				$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
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
			Strings::SECURED_BY => new \stdClass(),
		) );

		$strings = new Strings( $config );
		$this->assertSame(
			'Secured by TrustedLogin',
			$strings->get( Strings::SECURED_BY, 'Secured by TrustedLogin' )
		);
	}

	// -----------------------------------------------------------------
	//  registry() exposes the public contract.
	// -----------------------------------------------------------------

	public function test_registry_includes_every_class_constant() {
		$registry = Strings::registry();

		$this->assertArrayHasKey( Strings::SECURED_BY, $registry );
		$this->assertArrayHasKey( Strings::REVOKE_ACCESS_BUTTON, $registry );
		$this->assertArrayHasKey( Strings::SUPPORT_TEMPORARILY_UNAVAILABLE, $registry );
		$this->assertArrayHasKey( Strings::TRY_RECONNECTING, $registry );
		$this->assertArrayHasKey( Strings::CREATED_TIME_AGO, $registry );

		$this->assertSame( 0, $registry[ Strings::SECURED_BY ]['placeholders'] );
		$this->assertSame( 2, $registry[ Strings::CREATED_TIME_AGO ]['placeholders'] );
	}
}

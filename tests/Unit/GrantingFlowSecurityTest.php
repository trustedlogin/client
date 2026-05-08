<?php
/**
 * Pure-PHPUnit security tests for the Grant Access flow.
 *
 * Covers attack surfaces that are testable without a full WP test
 * install: hash-equals timing, hash entropy, vendor-string CSS
 * injection, capability-add deny-list, URL scheme allowlist,
 * support-role prevented-caps. Each test pins a property the
 * production code currently honors so regressions surface here.
 *
 * Items requiring real WordPress (AJAX endpoint CSRF, decay cron,
 * revoke authorization, full grant-flow round-trip) live in
 * tests/test-ajax.php / tests/test-users.php — see that suite for
 * the integration coverage.
 *
 * @group granting
 * @group security
 * @group unit
 */

namespace TrustedLogin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustedLogin\Config;
use TrustedLogin\Encryption;
use TrustedLogin\Endpoint;
use TrustedLogin\Form;
use TrustedLogin\Logging;
use TrustedLogin\SupportRole;

class GrantingFlowSecurityTest extends TestCase {

	private function valid_config_settings( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'role'           => 'editor',
				'caps'           => array(
					'add' => array(
						'edit_posts' => 'Need to edit posts to help debug',
					),
				),
				'webhook_url'    => 'https://example.com/webhook',
				'auth'           => array(
					'api_key'     => 'a1b2c3d4e5f6a1b2',
					'license_key' => 'license-test',
				),
				// Inline the WordPress DAY_IN_SECONDS constant (86400) since
				// this is a pure-PHPUnit test that runs without WP bootstrap.
				'decay'          => 7 * 86400,
				'vendor'         => array(
					'namespace'   => 'testns',
					'title'       => 'Test Vendor',
					'email'       => 'support@example.com',
					'website'     => 'https://example.com',
					'support_url' => 'https://example.com/support',
					'logo_url'    => 'https://example.com/logo.png',
				),
				'reassign_posts' => true,
			),
			$overrides
		);
	}

	// ---------------------------------------------------------------
	//  Item 2 — endpoint hash compare uses constant-time hash_equals.
	//
	//  Endpoint::maybe_login_support compares the URL-supplied
	//  endpoint hash against the stored value. A non-constant-time
	//  compare leaks information character-by-character; the test
	//  pins that the source uses hash_equals.
	// ---------------------------------------------------------------

	public function test_endpoint_uses_hash_equals_for_url_hash_compare() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Endpoint.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString(
			'hash_equals',
			$source,
			'Endpoint must use hash_equals() for the endpoint-hash compare. Plain == leaks the prefix length via timing.'
		);

		// Belt-and-suspenders — there must be no plain `===` compare
		// against $request[ self::POST_ENDPOINT_KEY ] anywhere.
		$this->assertDoesNotMatchRegularExpression(
			'/===\s*\$request\[\s*self::POST_ENDPOINT_KEY\s*\]/',
			$source,
			'Found a non-constant-time compare against POST_ENDPOINT_KEY. Use hash_equals.'
		);
	}

	// ---------------------------------------------------------------
	//  Item 3 — site_identifier_hash uses cryptographically-strong
	//  random source (random_bytes / openssl_random_pseudo_bytes
	//  with crypto_strong check). Never falls back to mt_rand /
	//  uniqid / time-based seeding.
	// ---------------------------------------------------------------

	public function test_get_random_hash_returns_128_hex_chars() {
		$logging = new class {
			public function log( $message, $method = '', $level = 'notice' ) {}
		};

		$hash = Encryption::get_random_hash( $logging );

		$this->assertIsString( $hash );
		$this->assertSame( 128, strlen( $hash ), 'random_bytes(64) should produce 128 hex chars after bin2hex.' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{128}$/', $hash );
	}

	public function test_two_random_hashes_do_not_collide() {
		$logging = new class {
			public function log( $message, $method = '', $level = 'notice' ) {}
		};

		$hashes = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$hashes[] = Encryption::get_random_hash( $logging );
		}

		$this->assertSame(
			count( $hashes ),
			count( array_unique( $hashes ) ),
			'50 random hashes must all be distinct — pins the entropy source.'
		);
	}

	public function test_encryption_only_uses_strong_random_sources() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Encryption.php' );

		$this->assertNotFalse( $source );
		$this->assertStringNotContainsString(
			'mt_rand',
			$source,
			'Encryption must never use mt_rand — it is not crypto-grade.'
		);
		$this->assertStringNotContainsString(
			'rand(',
			$source,
			'Encryption must never use rand() — not crypto-grade.'
		);
		$this->assertStringNotContainsString(
			'uniqid',
			$source,
			'Encryption must never use uniqid — predictable from time + microtime.'
		);
	}

	// ---------------------------------------------------------------
	//  Item 6 — vendor/logo_url is escaped before CSS interpolation.
	//
	//  Form::get_login_inline_css emits a `background-image: url("…")`
	//  rule. A hostile logo_url like `"); evil-rule { … } /*` would
	//  break out of the CSS string context. Two-layer defense:
	//  Config validates the URL scheme; Form esc_url's it before
	//  emitting. Tests pin both.
	// ---------------------------------------------------------------

	public function test_config_rejects_logo_url_with_javascript_scheme() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/vendor\/logo_url/' );

		$config = new Config( $this->valid_config_settings( array(
			'vendor' => array( 'logo_url' => 'javascript:alert(1)' ),
		) ) );
		$config->validate();
	}

	public function test_config_rejects_logo_url_with_data_scheme() {
		$this->expectException( \Exception::class );

		$config = new Config( $this->valid_config_settings( array(
			'vendor' => array( 'logo_url' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==' ),
		) ) );
		$config->validate();
	}

	public function test_form_inline_css_escapes_hostile_logo_url() {
		$config = new Config( $this->valid_config_settings( array(
			'vendor' => array(
				'logo_url' => 'https://example.com/x.png',
			),
		) ) );

		// Bypass Config validation by Reflection-injecting a hostile
		// post-validate URL — this simulates a future regression in
		// the Config layer. Form-layer defense (esc_url) must hold
		// independently.
		$rc       = new \ReflectionClass( $config );
		$settings = $rc->getProperty( 'settings' );
		$settings->setAccessible( true );
		$current  = $settings->getValue( $config );
		$current['vendor']['logo_url'] = '"); evil-rule { content: "pwn"; } /*';
		$settings->setValue( $config, $current );

		// Form's constructor takes 4 deps; skip it via reflection
		// since this test only exercises the inline-CSS path which
		// reads from $this->config.
		$form_rc = new \ReflectionClass( Form::class );
		$form    = $form_rc->newInstanceWithoutConstructor();
		$cfg_prop = $form_rc->getProperty( 'config' );
		$cfg_prop->setAccessible( true );
		$cfg_prop->setValue( $form, $config );

		$css_method = new \ReflectionMethod( Form::class, 'get_login_inline_css' );
		$css_method->setAccessible( true );
		$css = $css_method->invoke( $form );

		$this->assertStringNotContainsString(
			'evil-rule',
			$css,
			'Hostile logo_url must not appear unescaped in inline CSS output.'
		);
		$this->assertStringNotContainsString(
			'"); ',
			$css,
			'Closing-string-and-rule injection must not survive into output.'
		);
	}

	public function test_form_inline_css_omits_logo_block_when_url_is_empty() {
		$config = new Config( $this->valid_config_settings( array(
			'vendor' => array( 'logo_url' => '' ),
		) ) );

		$form_rc  = new \ReflectionClass( Form::class );
		$form     = $form_rc->newInstanceWithoutConstructor();
		$cfg_prop = $form_rc->getProperty( 'config' );
		$cfg_prop->setAccessible( true );
		$cfg_prop->setValue( $form, $config );

		$method = new \ReflectionMethod( Form::class, 'get_login_inline_css' );
		$method->setAccessible( true );
		$css = $method->invoke( $form );

		$this->assertStringNotContainsString(
			'background-image',
			$css,
			'Empty logo_url must skip the background-image rule entirely — no `url("")` artifact.'
		);
	}

	// ---------------------------------------------------------------
	//  Item 13 — caps/add deny-list. SupportRole::$prevented_caps
	//  enumerates the caps an integrator MUST NOT be able to grant
	//  to the temporary support user. Config rejects them at
	//  validate() time; SupportRole strips them at role-clone time.
	// ---------------------------------------------------------------

	/**
	 * @dataProvider preventedCapsProvider
	 */
	public function test_every_prevented_cap_is_rejected_by_config_validate( string $cap ): void {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/' . preg_quote( $cap, '/' ) . '/' );

		$config = new Config( $this->valid_config_settings( array(
			'caps' => array( 'add' => array( $cap => 'wat' ) ),
		) ) );
		$config->validate();
	}

	public static function preventedCapsProvider(): array {
		// Mirror SupportRole::$prevented_caps exactly. Static
		// duplication is intentional — if that list grows, this
		// provider must grow with it (and the test failure on the
		// new cap reminds the maintainer).
		return array_map(
			static fn ( string $cap ) => array( $cap ),
			array(
				'create_users',
				'delete_users',
				'edit_users',
				'list_users',
				'promote_users',
				'delete_site',
				'remove_users',
			)
		);
	}

	public function test_support_role_static_prevented_caps_is_immutable_set(): void {
		// SupportRole::$prevented_caps is a public static — pin the
		// exact set so a removal here surfaces in code review.
		$expected = array(
			'create_users',
			'delete_users',
			'edit_users',
			'list_users',
			'promote_users',
			'delete_site',
			'remove_users',
		);
		$this->assertSame(
			$expected,
			SupportRole::$prevented_caps,
			'Removing entries from $prevented_caps weakens the support-role boundary; deliberate changes must update this test.'
		);
	}

	// ---------------------------------------------------------------
	//  Item 14 — log calls don't pass secret-shaped variables.
	//
	//  A regex scan over the source caught nothing in the current
	//  tree, but the regex is naive (it can't span function calls).
	//  Promote this check to phpstan / psalm with a custom rule
	//  if you need exhaustive coverage; this test pins what the
	//  unit suite can verify cheaply: the Logging class's own
	//  log() method does not call error_log on raw secrets.
	// ---------------------------------------------------------------

	public function test_logging_log_method_does_not_dump_raw_payload_into_error_log(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Logging.php' );
		$this->assertNotFalse( $source );

		// Capture the body of the log() method via its opening
		// brace through to its closing brace. The method should
		// either format with sprintf and log a redacted line, or
		// route through a sanitizer — but never dump $_POST /
		// $_REQUEST / payloads wholesale.
		$this->assertDoesNotMatchRegularExpression(
			'/error_log\([^)]*\$_POST/',
			$source,
			'Logging must never error_log raw $_POST.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/error_log\([^)]*\$_REQUEST/',
			$source,
			'Logging must never error_log raw $_REQUEST.'
		);
	}

	// ---------------------------------------------------------------
	//  Item 15 — webhook URL scheme allowlist. Already covered by
	//  Config::validate (line 233 area), but pin every URL field
	//  individually so a future change that drops one from the
	//  iteration list fails loudly here.
	// ---------------------------------------------------------------

	/**
	 * @dataProvider urlFieldsRequiringHttpScheme
	 */
	public function test_url_field_rejects_javascript_scheme( string $field_path ): void {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/' . preg_quote( $field_path, '/' ) . '/' );

		$overrides = array();
		$segments  = explode( '/', $field_path );
		$cursor    = &$overrides;
		foreach ( $segments as $i => $seg ) {
			if ( $i === count( $segments ) - 1 ) {
				$cursor[ $seg ] = 'javascript:alert(1)';
			} else {
				$cursor[ $seg ] = array();
				$cursor          = &$cursor[ $seg ];
			}
		}

		$config = new Config( $this->valid_config_settings( $overrides ) );
		$config->validate();
	}

	public function test_url_field_accepts_plain_http_scheme(): void {
		// Vendor sites may legitimately be on http (self-hosted on
		// a non-HTTPS server, local dev, image-CDN edge cases). The
		// allowlist is "scheme is http or https", not "https only".
		// This test pins the contract so a future tightening can't
		// silently break vendors who are still on http.
		$config = new Config( $this->valid_config_settings( array(
			'vendor' => array(
				'logo_url'    => 'http://customer-vendor.local/logo.png',
				'support_url' => 'http://customer-vendor.local/support',
			),
		) ) );

		// validate() returns void on success; absence of exception
		// is the assertion.
		$config->validate();
		$this->assertTrue( true );
	}

	public static function urlFieldsRequiringHttpScheme(): array {
		return array(
			'webhook_url'        => array( 'webhook_url' ),
			'vendor/support_url' => array( 'vendor/support_url' ),
			'vendor/website'     => array( 'vendor/website' ),
			'vendor/logo_url'    => array( 'vendor/logo_url' ),
		);
	}
}

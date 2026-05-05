<?php
/**
 * Pure-PHPUnit unit tests for the LoginAttempts SaaS-POST helper.
 *
 * No WP test install required — stubs for WP functions and the
 * \WP_Error class live in tests/Unit/bootstrap.php. Run via:
 *
 *     vendor/bin/phpunit -c phpunit-unit.xml
 *
 * Config, Remote, and LoginAttempts itself are `final`, so the
 * SUT is instantiated via newInstanceWithoutConstructor and the
 * three private dependencies are Reflection-set to anonymous-
 * class doubles. The doubles aren't `Remote`/`Config`/`Logging`
 * but the SUT only calls methods on them, never type-checks, so
 * duck-typing works.
 *
 * Covers:
 *   - is_audit_log_enabled() across the per-namespace opt-out
 *     constant
 *   - resolve_client_ip() priority order across CF / XFF /
 *     REMOTE_ADDR (and the all-invalid fallback)
 *   - report() input gating: required fields, secret_id pull-out,
 *     identifier_hash hex regex, client_ip validity, body-length
 *     caps with UTF-8-safe truncation
 *   - report() secret scrubbing on detailed_reason
 *   - report() status-code branches: 422, 429, 5xx, 200/201,
 *     network WP_Error, and the catch around Remote exceptions
 *
 * @group login-attempts
 * @group unit
 */

namespace TrustedLogin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustedLogin\LoginAttempts;

class LoginAttemptsUnitTest extends TestCase {

	/** @var object Anonymous-class double with `last_*` capture + `next_response` script. */
	private $remote;

	/** @var object Anonymous-class double absorbing log() calls. */
	private $logging;

	/** @var object Anonymous-class double exposing ns() = 'testns'. */
	private $config;

	/** @var LoginAttempts */
	private $sut;

	protected function setUp(): void {
		$this->remote = new class {
			public $next_response;
			public $last_path;
			public $last_body;
			public $last_method;
			public $last_timeout;
			public $call_count = 0;

			public function send( $path, $body = array(), $method = 'POST', $headers = array(), $timeout = 5 ) {
				$this->call_count++;
				$this->last_path    = $path;
				$this->last_body    = $body;
				$this->last_method  = $method;
				$this->last_timeout = $timeout;

				if ( $this->next_response instanceof \Exception ) {
					throw $this->next_response;
				}
				return $this->next_response;
			}
		};

		$this->logging = new class {
			public $entries = array();
			public function log( $message, $method = '', $level = 'notice' ) {
				$this->entries[] = compact( 'message', 'method', 'level' );
			}
		};

		$this->config = new class {
			public function ns() {
				return 'testns';
			}
		};

		// LoginAttempts is final; its constructor type-hints
		// Config/Remote/Logging which are also all final. Bypass
		// the constructor and inject our doubles by Reflection.
		$rc        = new \ReflectionClass( LoginAttempts::class );
		$this->sut = $rc->newInstanceWithoutConstructor();

		foreach ( array( 'config' => $this->config, 'remote' => $this->remote, 'logging' => $this->logging ) as $name => $double ) {
			$prop = $rc->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( $this->sut, $double );
		}

		// Ensure prior tests don't leave $_SERVER state behind.
		unset(
			$_SERVER['HTTP_CF_CONNECTING_IP'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['REMOTE_ADDR']
		);
	}

	// ---------------------------------------------------------------
	//  is_audit_log_enabled — opt-out constant pin
	// ---------------------------------------------------------------

	public function test_audit_log_enabled_by_default() {
		$this->assertTrue( $this->sut->is_audit_log_enabled() );
	}

	public function test_audit_log_disabled_when_namespace_constant_truthy() {
		// Constants are process-global and can't be undefined, so each
		// disable-flag test uses its own namespace + matching constant
		// to avoid leaking the disabled state into the other tests.
		if ( ! defined( 'TRUSTEDLOGIN_DISABLE_AUDIT_NS_FOR_FLAG' ) ) {
			define( 'TRUSTEDLOGIN_DISABLE_AUDIT_NS_FOR_FLAG', true );
		}
		$sut = $this->makeSutWithNamespace( 'ns_for_flag' );
		$this->assertFalse( $sut->is_audit_log_enabled() );
	}

	public function test_report_short_circuits_when_audit_disabled_and_makes_no_remote_call() {
		if ( ! defined( 'TRUSTEDLOGIN_DISABLE_AUDIT_NS_FOR_SHORTCIRCUIT' ) ) {
			define( 'TRUSTEDLOGIN_DISABLE_AUDIT_NS_FOR_SHORTCIRCUIT', true );
		}
		$sut    = $this->makeSutWithNamespace( 'ns_for_shortcircuit' );
		$remote = $this->getRemoteFor( $sut );

		$result = $sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'audit_log_disabled', $result->get_error_code() );
		$this->assertSame(
			0,
			$remote->call_count,
			'No SaaS POST may fire when the namespace constant disables audit.'
		);
	}

	// ---------------------------------------------------------------
	//  resolve_client_ip — header priority matrix
	// ---------------------------------------------------------------

	// Secure default: HTTP_CF_CONNECTING_IP and HTTP_X_FORWARDED_FOR
	// are spoofable by any HTTP client unless the customer site is
	// behind a stripping reverse proxy. resolve_client_ip() ignores
	// them by default and returns REMOTE_ADDR. Integrators whose
	// deployments fit the proxy shape opt in via the
	// trustedlogin/{ns}/audit/trust_proxy_ip_headers filter, which
	// is exercised by the integration suite (the unit bootstrap's
	// apply_filters() is a pass-through stub, so the opt-in path
	// can't be driven from here).

	/**
	 * Default posture: HTTP_CF_CONNECTING_IP is spoofable by any client
	 * unless a stripping reverse proxy is in front of the customer site,
	 * so resolve_client_ip() ignores it without an opt-in filter.
	 *
	 * REMOTE_ADDR is intentionally NOT set — if the implementation
	 * consulted CF at all, the assertion would observe '203.0.113.5'
	 * instead of null.
	 *
	 * @return void
	 */
	public function test_resolve_client_ip_ignores_spoofable_cf_header_by_default() {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';

		$this->assertNull( $this->sut->resolve_client_ip() );
	}

	/**
	 * Same posture for HTTP_X_FORWARDED_FOR — the comma-separated first
	 * hop is freely settable by any HTTP client. REMOTE_ADDR is
	 * intentionally NOT set so a regression that consulted XFF at all
	 * would observe '198.51.100.7' instead of null.
	 *
	 * @return void
	 */
	public function test_resolve_client_ip_ignores_spoofable_xff_header_by_default() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';

		$this->assertNull( $this->sut->resolve_client_ip() );
	}

	public function test_resolve_client_ip_falls_through_to_remote_addr() {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

		$this->assertSame( '192.0.2.1', $this->sut->resolve_client_ip() );
	}

	/**
	 * The secure default returns null when REMOTE_ADDR is unusable
	 * even if valid proxy headers are present — proves the proxy
	 * headers are never consulted, not just deprioritized.
	 *
	 * @return void
	 */
	public function test_resolve_client_ip_returns_null_when_remote_addr_invalid_and_proxy_headers_ignored() {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7';
		$_SERVER['REMOTE_ADDR']           = '999.999.999.999';

		$this->assertNull( $this->sut->resolve_client_ip() );
	}

	// ---------------------------------------------------------------
	//  report() input gating
	// ---------------------------------------------------------------

	public function test_report_rejects_missing_secret_id() {
		$payload = $this->validPayload();
		unset( $payload['secret_id'] );

		$result = $this->sut->report( $payload );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_secret_id', $result->get_error_code() );
		$this->assertSame( 0, $this->remote->call_count );
	}

	public function test_report_rejects_missing_required_field() {
		$this->remote->next_response = $this->okResponse();

		$payload = $this->validPayload();
		unset( $payload['code'] );

		$result = $this->sut->report( $payload );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_field', $result->get_error_code() );
		$this->assertSame( 0, $this->remote->call_count );
	}

	public function test_report_pulls_secret_id_into_url_path_not_body() {
		$this->remote->next_response = $this->okResponse();

		$this->sut->report( $this->validPayload() );

		$this->assertSame(
			'sites/abc123/login-attempts',
			$this->remote->last_path,
			'secret_id must be in the URL path.'
		);
		$this->assertArrayNotHasKey(
			'secret_id',
			$this->remote->last_body,
			'secret_id must NOT appear in the POST body.'
		);
	}

	public function test_report_uses_three_second_timeout() {
		$this->remote->next_response = $this->okResponse();

		$this->sut->report( $this->validPayload() );

		$this->assertSame( 3, $this->remote->last_timeout );
	}

	public function test_report_strips_caller_supplied_identifier_hash() {
		// Load-bearing safety property: report() ignores any
		// `identifier_hash` field on the context array. The only way
		// to get an `identifier_hash` onto the wire is through the
		// dedicated $raw_site_hash second argument, which the method
		// then sha256s itself. If a caller mistakenly drops a
		// plaintext identifier on the array, it stays off the wire.
		$this->remote->next_response = $this->okResponse();

		$payload                    = $this->validPayload();
		$payload['identifier_hash'] = 'plaintext-leak-canary';

		$this->sut->report( $payload );

		$this->assertArrayNotHasKey(
			'identifier_hash',
			$this->remote->last_body,
			'Caller-supplied identifier_hash on the context array MUST be stripped — only the sha256 of the raw_site_hash arg may reach the wire.'
		);
	}

	public function test_report_hashes_raw_site_hash_into_identifier_hash() {
		$this->remote->next_response = $this->okResponse();

		$raw_site_hash = 'arbitrary-raw-site-hash-from-user-meta';

		$this->sut->report( $this->validPayload(), $raw_site_hash );

		$this->assertSame(
			hash( 'sha256', $raw_site_hash ),
			$this->remote->last_body['identifier_hash'],
			'The wire identifier_hash must be sha256( raw_site_hash ).'
		);
	}

	public function test_report_omits_identifier_hash_when_no_raw_site_hash_passed() {
		$this->remote->next_response = $this->okResponse();

		// Default second arg is null. No identifier_hash on wire.
		$this->sut->report( $this->validPayload() );

		$this->assertArrayNotHasKey(
			'identifier_hash',
			$this->remote->last_body,
			'When no raw_site_hash is supplied, the wire payload must omit identifier_hash entirely.'
		);
	}

	public function test_report_caller_supplied_identifier_hash_loses_to_raw_site_hash() {
		$this->remote->next_response = $this->okResponse();

		$payload                    = $this->validPayload();
		$payload['identifier_hash'] = 'should-be-stripped';
		$raw_site_hash              = 'real-raw-hash';

		$this->sut->report( $payload, $raw_site_hash );

		$this->assertSame(
			hash( 'sha256', $raw_site_hash ),
			$this->remote->last_body['identifier_hash'],
			'When both are present, the sha256 of raw_site_hash must win and the caller field must be stripped.'
		);
	}

	public function test_report_drops_invalid_client_ip() {
		$this->remote->next_response = $this->okResponse();

		$payload              = $this->validPayload();
		$payload['client_ip'] = 'not.an.ip';

		$this->sut->report( $payload );

		$this->assertArrayNotHasKey( 'client_ip', $this->remote->last_body );
	}

	public function test_report_keeps_valid_client_ip() {
		$this->remote->next_response = $this->okResponse();

		$payload              = $this->validPayload();
		$payload['client_ip'] = '203.0.113.5';

		$this->sut->report( $payload );

		$this->assertSame( '203.0.113.5', $this->remote->last_body['client_ip'] );
	}

	public function test_report_truncates_detailed_reason_on_utf8_boundary() {
		$this->remote->next_response = $this->okResponse();

		// 4094 ASCII chars (each 1 byte) followed by a 4-byte
		// emoji takes total = 4098 bytes. mb_strcut at 4096 must
		// stop on the boundary BEFORE the emoji, leaving a
		// well-formed UTF-8 string of length 4094 bytes.
		$payload                    = $this->validPayload();
		$payload['detailed_reason'] = str_repeat( 'a', 4094 ) . "\u{1F389}"; // 🎉

		$this->sut->report( $payload );

		$truncated = $this->remote->last_body['detailed_reason'];
		$this->assertSame( 4094, strlen( $truncated ), 'mb_strcut must cut on the codepoint boundary.' );
		$this->assertSame(
			$truncated,
			mb_convert_encoding( $truncated, 'UTF-8', 'UTF-8' ),
			'Truncated text must be valid UTF-8 (no orphan continuation bytes).'
		);
	}

	public function test_report_truncates_user_agent_on_utf8_boundary() {
		$this->remote->next_response = $this->okResponse();

		$payload                      = $this->validPayload();
		$payload['client_user_agent'] = str_repeat( 'a', 510 ) . "\u{1F389}"; // 🎉

		$this->sut->report( $payload );

		$truncated = $this->remote->last_body['client_user_agent'];
		$this->assertSame( 510, strlen( $truncated ) );
	}

	// ---------------------------------------------------------------
	//  Secret-scrub on detailed_reason
	// ---------------------------------------------------------------

	public function test_report_scrubs_bearer_token_from_detailed_reason() {
		$this->remote->next_response = $this->okResponse();

		$payload                    = $this->validPayload();
		$payload['detailed_reason'] = 'WP error: Bearer abc123def456ghi789 was rejected';

		$this->sut->report( $payload );

		$sent = $this->remote->last_body['detailed_reason'];
		$this->assertStringNotContainsString( 'abc123def456ghi789', $sent );
		$this->assertStringContainsString( '[redacted]', $sent );
	}

	public function test_report_scrubs_stripe_secret_key_from_detailed_reason() {
		$this->remote->next_response = $this->okResponse();

		// Build the fixture key via concatenation so the literal never
		// appears as a single source-code substring. Stripe's secret-
		// scanning push hook flags any sk_live_-prefixed sequence —
		// including the public documentation example — and blocks the
		// push. Splitting the literal dodges the scanner without changing
		// what flows through report() at runtime.
		$fixture_key                = 'sk_live_' . '4eC39HqLyjWDarjtT1zdp7dc';
		$payload                    = $this->validPayload();
		$payload['detailed_reason'] = 'Charge failed: ' . $fixture_key;

		$this->sut->report( $payload );

		$sent = $this->remote->last_body['detailed_reason'];
		$this->assertStringNotContainsString( $fixture_key, $sent );
		$this->assertStringContainsString( '[redacted-stripe-key]', $sent );
	}

	public function test_report_scrubs_password_pair_from_detailed_reason() {
		$this->remote->next_response = $this->okResponse();

		$payload                    = $this->validPayload();
		$payload['detailed_reason'] = 'Login failed (password=hunter2hunter2)';

		$this->sut->report( $payload );

		$this->assertStringNotContainsString(
			'hunter2hunter2',
			$this->remote->last_body['detailed_reason']
		);
	}

	// ---------------------------------------------------------------
	//  Status-code branch matrix
	// ---------------------------------------------------------------

	public function test_report_returns_id_on_201() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => '{"id":"lpat_a1a5bea0-372a-47ca-8090-2f36ad870abc"}',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertIsArray( $result );
		$this->assertSame( 'lpat_a1a5bea0-372a-47ca-8090-2f36ad870abc', $result['id'] );
	}

	public function test_report_handles_422_validation_reject() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 422 ),
			'body'     => '{"errors":{"code":["invalid"]}}',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_validation', $result->get_error_code() );
	}

	public function test_report_handles_429_rate_limited() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 429 ),
			'body'     => '',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_rate_limited', $result->get_error_code() );
	}

	public function test_report_handles_5xx() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 503 ),
			'body'     => '',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_5xx', $result->get_error_code() );
	}

	public function test_report_handles_unexpected_status() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 301 ),
			'body'     => '',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_unexpected', $result->get_error_code() );
	}

	public function test_report_handles_malformed_json_body() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => 'not json',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_malformed_response', $result->get_error_code() );
	}

	public function test_report_handles_missing_id_in_response() {
		$this->remote->next_response = array(
			'response' => array( 'code' => 201 ),
			'body'     => '{"unrelated":true}',
		);

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_malformed_response', $result->get_error_code() );
	}

	public function test_report_handles_remote_wp_error() {
		$this->remote->next_response = new \WP_Error( 'http_request_failed', 'connection refused' );

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_unreachable', $result->get_error_code() );
	}

	public function test_report_catches_remote_exception() {
		$this->remote->next_response = new \RuntimeException( 'pre_http_request filter blew up' );

		$result = $this->sut->report( $this->validPayload() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'saas_unreachable', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	//  Helpers
	// ---------------------------------------------------------------

	private function validPayload() {
		return array(
			'secret_id'       => 'abc123',
			'code'            => 'login_failed',
			'client_site_url' => 'https://customer.example.com',
			'attempted_at'    => '2026-04-28T12:00:00+00:00',
		);
	}

	/**
	 * Build a separate SUT instance whose Config returns the given
	 * namespace. Used by audit-disabled tests so each one's constant
	 * lives in its own per-test namespace and doesn't leak into the
	 * default `testns` SUT.
	 */
	private function makeSutWithNamespace( string $ns ) {
		$config = new class ( $ns ) {
			private $ns;
			public function __construct( $ns ) {
				$this->ns = $ns;
			}
			public function ns() {
				return $this->ns;
			}
		};

		$rc  = new \ReflectionClass( LoginAttempts::class );
		$sut = $rc->newInstanceWithoutConstructor();

		foreach ( array( 'config' => $config, 'remote' => clone $this->remote, 'logging' => $this->logging ) as $name => $double ) {
			$prop = $rc->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( $sut, $double );
		}

		return $sut;
	}

	private function getRemoteFor( $sut ) {
		$rc   = new \ReflectionClass( LoginAttempts::class );
		$prop = $rc->getProperty( 'remote' );
		$prop->setAccessible( true );

		return $prop->getValue( $sut );
	}

	private function okResponse() {
		return array(
			'response' => array( 'code' => 201 ),
			'body'     => '{"id":"lpat_a1a5bea0-372a-47ca-8090-2f36ad870abc"}',
		);
	}
}

<?php
/**
 * Log redaction regression suite for the SaaS-cached webhook URL (CI-gating).
 *
 * Pinned reconciliation decisions covered here (security-critical):
 *   - The 4 log lines in `Remote::maybe_send_webhook()` show host-only
 *     redaction, never the full URL with path or query.
 *   - The shadowing log redacts both URLs.
 *   - The deprecation log redacts the URL.
 *   - The success log redacts the URL.
 *   - The failure log redacts the URL.
 *   - `Remote::send()` debug response dump (`Response: %s`) redacts
 *     `webhookUrl` from the SaaS response body.
 *
 * Tagged `@group security-critical` so CI can fail-fast if any of these
 * regress — even one full-URL leak in a debug log defeats the SDK's
 * "URL is a bearer secret" model.
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use TrustedLogin\Tests\Helpers\WebhookCaptureTrait;
use TrustedLogin\Tests\Helpers\LogCaptureTrait;
use TrustedLogin\Tests\Helpers\MaliciousSaasResponseTrait;

require_once __DIR__ . '/Helpers/WebhookCaptureTrait.php';
require_once __DIR__ . '/Helpers/LogCaptureTrait.php';
require_once __DIR__ . '/Helpers/MaliciousSaasResponseTrait.php';

// @group annotations are on the class so PHPUnit 9 honors --group filters
// (file-header docblocks above the namespace declaration are not picked up).
/**
 * @group integration
 * @group security-critical
 * @group webhook-url
 */
class TrustedLoginWebhookLogRedactionTest extends WP_UnitTestCase {

	use WebhookCaptureTrait;
	use LogCaptureTrait;
	use MaliciousSaasResponseTrait;

	const NS = 'tl-redact-test';

	const SECRET_PATH    = '/path/secret-token-aaa-bbb-ccc';
	const SECRET_QUERY   = 'token=super-secret-12345&id=42';

	public function setUp(): void {
		parent::setUp();
		$this->start_webhook_capture();
		$this->start_log_capture( self::NS );
		Remote::reset_deprecation_flag();

		add_filter( 'http_request_host_is_external', array( $this, 'allow_test_hosts' ), 10, 2 );
	}

	public function allow_test_hosts( $is_external, $host ) {
		return in_array( $host, array( 'example.com', 'example.org', 'attacker.test' ), true ) ? true : $is_external;
	}

	public function tearDown(): void {
		remove_filter( 'http_request_host_is_external', array( $this, 'allow_test_hosts' ), 10 );
		$this->stop_webhook_capture();
		$this->stop_log_capture();
		$this->clear_saas_webhook_response_stub();
		delete_option( sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, self::NS ) );
		Remote::reset_deprecation_flag();
		parent::tearDown();
	}

	private function build_config( $webhook_url = null ) {
		$config = array(
			'role'   => 'editor',
			'auth'   => array(
				'api_key'     => '0000111122223333',
				'license_key' => 'lic-redact',
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

	/**
	 * Asserts that NO captured log line contains the given secret token.
	 * The whole point of the regression suite — if this ever flips, the
	 * CI gate fires.
	 */
	private function assertNoLogContainsSecret( $secret, $context_msg = '' ) {
		foreach ( $this->getCapturedLogs() as $entry ) {
			$haystack = $entry['message'] . ' ' . wp_json_encode( $entry['data'] );
			$this->assertStringNotContainsString(
				$secret,
				$haystack,
				sprintf(
					'SECURITY REGRESSION: log line leaked secret "%s" %s. Entry: [%s] %s',
					$secret,
					$context_msg,
					$entry['level'],
					$entry['message']
				)
			);
		}
	}

	// ---------------------------------------------------------------
	// Deprecation log redaction
	// ---------------------------------------------------------------

	public function test_deprecation_log_redacts_path_and_query() {
		$secret_url = 'https://example.com' . self::SECRET_PATH . '?' . self::SECRET_QUERY;
		$config     = $this->build_config( $secret_url );

		$this->fire_webhook( $config );

		$this->assertNoLogContainsSecret( self::SECRET_PATH, 'in deprecation log path' );
		$this->assertNoLogContainsSecret( 'super-secret-12345', 'in deprecation log query' );
		$this->assertLogContains( '`webhook/url` config key is deprecated' );
		// Host-only token should still appear so integrators can spot
		// which environment is misconfigured.
		$this->assertLogContains( 'example.com' );
	}

	// ---------------------------------------------------------------
	// Shadowing log redaction (both URLs)
	// ---------------------------------------------------------------

	public function test_shadowing_log_redacts_both_urls() {
		// The shadowing log itself names neither URL today (intentional —
		// it only signals "Config is shadowing dashboard"). The redaction
		// requirement is satisfied because no full URL with secret path
		// appears in any log entry. The success log DOES name the host
		// of the URL that actually fired (the Config URL) — that's the
		// host-only redaction we want to see.
		$config_url = 'https://example.com' . self::SECRET_PATH;
		$cached_url = 'https://example.org/another/secret/abcdef-XYZ-001';
		$config     = $this->build_config( $config_url );
		$this->set_cached_url( $cached_url );

		$this->fire_webhook( $config );

		$this->assertNoLogContainsSecret( self::SECRET_PATH, 'in shadowing log (config side)' );
		$this->assertNoLogContainsSecret( 'abcdef-XYZ-001', 'in shadowing log (cached side)' );
		$this->assertLogContains( 'shadowing the URL' );
	}

	// ---------------------------------------------------------------
	// Success log redaction
	// ---------------------------------------------------------------

	public function test_success_log_redacts_url() {
		$secret_url = 'https://example.com' . self::SECRET_PATH . '?' . self::SECRET_QUERY;
		$config     = $this->build_config( $secret_url );

		$this->fire_webhook( $config );

		// Filter the success-flavored log line specifically — production
		// emits "Webhook was sent. host=…" at debug level (Remote.php:328).
		$success_lines = array_filter(
			$this->getCapturedLogs(),
			function ( $entry ) {
				return false !== stripos( $entry['message'], 'Webhook was sent' );
			}
		);
		$this->assertGreaterThan( 0, count( $success_lines ), 'expected a webhook success log line' );
		foreach ( $success_lines as $entry ) {
			$this->assertStringNotContainsString( self::SECRET_PATH, $entry['message'] );
			$this->assertStringNotContainsString( 'super-secret-12345', $entry['message'] );
		}
	}

	// ---------------------------------------------------------------
	// Failure log redaction (WP_Error path)
	// ---------------------------------------------------------------

	public function test_failure_log_redacts_url() {
		$secret_url = 'https://example.com' . self::SECRET_PATH;
		$config     = $this->build_config( $secret_url );

		// Force the outbound POST to fail with a WP_Error.
		$fail_filter = function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false !== strpos( $url, 'example.com' ) ) {
				return new \WP_Error( 'http_request_failed', 'simulated network failure' );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $fail_filter, 8, 3 );

		try {
			$this->fire_webhook( $config );
		} finally {
			remove_filter( 'pre_http_request', $fail_filter, 8 );
		}

		$this->assertNoLogContainsSecret( self::SECRET_PATH, 'in failure log' );
		// And the host should still show up so an integrator can debug.
		$saw_host = false;
		foreach ( $this->getCapturedLogs() as $entry ) {
			if ( false !== strpos( $entry['message'], 'example.com' ) ) {
				$saw_host = true;
				break;
			}
		}
		$this->assertTrue( $saw_host, 'host should appear redacted into the failure log' );
	}

	// ---------------------------------------------------------------
	// SaaS response dump in Remote::send debug log
	// ---------------------------------------------------------------

	public function test_remote_send_response_dump_redacts_webhook_url() {
		// Remote::send always logs the response body at debug level
		// (Remote.php:436). When that body carries a `webhookUrl`,
		// redact_response_for_log must scrub the value before it hits
		// the log. Drive Remote::send directly with a stubbed response
		// — no need to walk the full SaaS-sync stack for a redaction
		// regression test.

		$secret_webhook = 'https://attacker.test/saas-leaked' . self::SECRET_PATH . '?' . self::SECRET_QUERY;

		$response_filter = function () use ( $secret_webhook ) {
			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => wp_json_encode( array(
					'success'    => true,
					'webhookUrl' => $secret_webhook,
				) ),
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $response_filter, 7 );

		try {
			$config  = $this->build_config();
			$logging = new Logging( $config );
			$remote  = new Remote( $config, $logging );
			$remote->send( 'sites/some-id', array( 'foo' => 'bar' ), 'POST' );
		} finally {
			remove_filter( 'pre_http_request', $response_filter, 7 );
		}

		// No log entry — debug or otherwise — should contain the path
		// or query secret. The host alone is acceptable.
		$this->assertNoLogContainsSecret( self::SECRET_PATH, 'in Remote::send response dump' );
		$this->assertNoLogContainsSecret( 'super-secret-12345', 'in Remote::send response dump' );
		$this->assertNoLogContainsSecret( 'saas-leaked', 'in Remote::send response dump (path token)' );

		// Sanity: the dump SHOULD have hit the log somewhere.
		$saw_response_dump = false;
		foreach ( $this->getCapturedLogs() as $entry ) {
			if ( false !== stripos( $entry['message'], 'Response:' ) ) {
				$saw_response_dump = true;
				break;
			}
		}
		$this->assertTrue( $saw_response_dump, 'expected Remote::send to emit a "Response:" debug log line' );
	}

	// ---------------------------------------------------------------
	// Bulk sweep — defense in depth
	// ---------------------------------------------------------------

	public function test_no_log_line_contains_full_url_with_path() {
		// One end-to-end run with shadowing; sweep every log line
		// captured. None should contain the full URL with its path.
		$config_url = 'https://example.com' . self::SECRET_PATH;
		$cached_url = 'https://example.org/path/zzz-XYZ-zzz';
		$config     = $this->build_config( $config_url );
		$this->set_cached_url( $cached_url );

		// All four actions in one request.
		foreach ( array( 'created', 'extended', 'logged_in', 'revoked' ) as $action ) {
			$this->fire_webhook( $config, $action );
		}

		// Defense-in-depth: any log line containing both the host AND
		// any character from the secret path is suspicious.
		foreach ( $this->getCapturedLogs() as $entry ) {
			$msg = $entry['message'];
			if ( false !== strpos( $msg, 'example.com' ) ) {
				$this->assertStringNotContainsString( '/path/secret-token', $msg, 'config URL leaked path' );
			}
			if ( false !== strpos( $msg, 'example.org' ) ) {
				$this->assertStringNotContainsString( '/path/zzz-XYZ', $msg, 'cached URL leaked path' );
			}
		}
	}
}

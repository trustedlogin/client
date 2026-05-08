<?php
/**
 * Pure-PHPUnit tests for {@see Config::sanitize_webhook_url}.
 *
 * The sanitizer is the bouncer at the cache
 * write — anything malformed, mis-schemed, or attacker-shaped that
 * gets past it ends up as a bearer secret in `wp_options` on a real
 * customer site.
 *
 * Pure-static so unit testing requires no WP bootstrap. Some edge
 * cases (esc_url_raw, wp_parse_url, wp_http_validate_url) require WP
 * functions — bootstrap.php handles loading WP for the unit suite
 * (it actually loads core; the "pure" label is about not needing a
 * full integration test environment).
 */

namespace TrustedLogin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustedLogin\Config;

/**
 * @group security
 * @group security-critical
 * @group unit
 * @group webhook-url
 */
class WebhookUrlSanitizerTest extends TestCase {

	// ----- happy path -------------------------------------------------------

	public function test_https_url_accepted() {
		$this->assertSame(
			'https://hooks.example.com/zap/abc123',
			Config::sanitize_webhook_url( 'https://hooks.example.com/zap/abc123' )
		);
	}

	public function test_https_url_with_query_accepted() {
		$this->assertSame(
			'https://hooks.example.com/zap/abc?token=xyz',
			Config::sanitize_webhook_url( 'https://hooks.example.com/zap/abc?token=xyz' )
		);
	}

	public function test_punycode_idn_accepted() {
		// Legitimate IDN — Punycode-encoded host. Should pass.
		$this->assertSame(
			'https://xn--n3h.example/wh',
			Config::sanitize_webhook_url( 'https://xn--n3h.example/wh' )
		);
	}

	public function test_url_at_max_length_accepted() {
		// 2048 chars total. Build by padding the path.
		$prefix  = 'https://h.test/';
		$padding = str_repeat( 'a', Config::WEBHOOK_URL_MAX_LENGTH - strlen( $prefix ) );
		$url     = $prefix . $padding;
		$this->assertSame( Config::WEBHOOK_URL_MAX_LENGTH, strlen( $url ), 'precondition: URL is exactly WEBHOOK_URL_MAX_LENGTH' );
		$this->assertSame( $url, Config::sanitize_webhook_url( $url ) );
	}

	// ----- scheme allow-list -----------------------------------------------

	public function test_http_rejected() {
		// HTTPS-only — URL is bearer secret; HTTP exposes it on every fire.
		$this->assertSame( '', Config::sanitize_webhook_url( 'http://hooks.example.com/wh' ) );
	}

	public function test_javascript_scheme_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( 'javascript:alert(1)' ) );
	}

	public function test_data_scheme_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( 'data:text/html,<script>alert(1)</script>' ) );
	}

	public function test_file_scheme_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( 'file:///etc/passwd' ) );
	}

	public function test_ftp_scheme_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( 'ftp://attacker.test/wh' ) );
	}

	public function test_scheme_less_url_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( '//attacker.test/wh' ) );
	}

	public function test_relative_path_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( '/wh' ) );
	}

	// ----- userinfo --------------------------------------------------------

	public function test_url_with_userinfo_rejected() {
		// `https://u:p@host/` invites credential confusion.
		$this->assertSame( '', Config::sanitize_webhook_url( 'https://user:pass@hooks.example.com/wh' ) );
	}

	public function test_url_with_user_only_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( 'https://user@hooks.example.com/wh' ) );
	}

	// ----- length cap ------------------------------------------------------

	public function test_url_over_max_length_rejected() {
		$prefix  = 'https://h.test/';
		$padding = str_repeat( 'a', Config::WEBHOOK_URL_MAX_LENGTH - strlen( $prefix ) + 1 );
		$url     = $prefix . $padding;
		$this->assertGreaterThan( Config::WEBHOOK_URL_MAX_LENGTH, strlen( $url ) );
		$this->assertSame( '', Config::sanitize_webhook_url( $url ) );
	}

	public function test_url_64kb_rejected() {
		// Defense against memory abuse.
		$url = 'https://h.test/' . str_repeat( 'a', 64 * 1024 );
		$this->assertSame( '', Config::sanitize_webhook_url( $url ) );
	}

	// ----- control characters / null bytes ---------------------------------

	public function test_control_char_url_rejected() {
		// CRLF injection attempt — header smuggling.
		$this->assertSame( '', Config::sanitize_webhook_url( "https://ok.test/wh\r\nX-Forwarded-For: 1.2.3.4" ) );
	}

	public function test_null_byte_url_rejected() {
		// Null-byte smuggling.
		$this->assertSame( '', Config::sanitize_webhook_url( "https://ok.test/wh\x00.attacker.test" ) );
	}

	public function test_tab_in_url_rejected() {
		$this->assertSame( '', Config::sanitize_webhook_url( "https://ok.test/wh\twith-tab" ) );
	}

	// ----- type mismatches -------------------------------------------------

	public function test_null_returns_empty() {
		$this->assertSame( '', Config::sanitize_webhook_url( null ) );
	}

	public function test_empty_string_returns_empty() {
		$this->assertSame( '', Config::sanitize_webhook_url( '' ) );
	}

	public function test_integer_returns_empty() {
		$this->assertSame( '', Config::sanitize_webhook_url( 12345 ) );
	}

	public function test_array_returns_empty() {
		$this->assertSame( '', Config::sanitize_webhook_url( array( 'https://ok.test/' ) ) );
	}

	public function test_object_returns_empty() {
		$this->assertSame( '', Config::sanitize_webhook_url( (object) array( 'url' => 'https://ok.test/' ) ) );
	}

	public function test_bool_returns_empty() {
		$this->assertSame( '', Config::sanitize_webhook_url( true ) );
		$this->assertSame( '', Config::sanitize_webhook_url( false ) );
	}

	// ----- HTML injection neutralization -----------------------------------

	public function test_html_injection_url_neutralized() {
		// `"><script>` chars must be either escaped or rejected — not
		// passed through verbatim into a string that later interpolates
		// into HTML or attribute context.
		$result = Config::sanitize_webhook_url( 'https://ok.test/wh"><script>alert(1)</script>' );
		// Either rejected (preferred) OR escaped (acceptable). Pin: the
		// raw `<script>` tag must NOT survive intact.
		$this->assertStringNotContainsString( '<script>', $result );
	}
}

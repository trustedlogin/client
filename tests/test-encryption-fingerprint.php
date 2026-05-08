<?php
/**
 * Integration tests for vendor public-key fingerprint pinning.
 *
 * Threat model: a MITM (compromised Connector site, hostile CDN,
 * malicious DNS) substitutes a different sodium pubkey of the
 * correct shape. Without pinning, the SDK would encrypt the support
 * envelope to the attacker\'s key and the attacker could decrypt the
 * granted credentials.
 *
 * The defense is `vendor/public_key_fingerprint` — a SHA-256 of the
 * expected pubkey, set in plugin config. Encryption::get_vendor_public_key
 * computes hash('sha256', $remote_key) on every fetch and refuses
 * the key if it doesn\'t match (constant-time hash_equals).
 *
 * E2E for this is awkward (config is bound at plugin-boot via
 * client.php), so this lives at the integration layer where we can
 * construct Config directly with the pin set.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;
use WP_Error;

class TrustedLoginEncryptionFingerprintTest extends WP_UnitTestCase {

	/** @var Config */
	private $config;

	/** @var Logging */
	private $logging;

	/** @var Remote */
	private $remote;

	/**
	 * The fixed pubkey our injected pre_http_request returns. 64 hex
	 * chars (32 raw bytes) is the sodium contract; SDK shape-validates
	 * before fingerprint-checking.
	 */
	const FAKE_PUBKEY = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

	private function build_config_with_fingerprint( string $fingerprint ): Config {
		return new Config( array(
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'vendor' => array(
				'namespace'                => 'fingerprint-test',
				'title'                    => 'Fingerprint Test',
				'email'                    => 'support@example.com',
				'website'                  => 'https://vendor.example.com',
				'support_url'              => 'https://vendor.example.com/support',
				// The setting under test. Empty string disables the
				// pin check entirely; non-empty enforces it.
				'public_key_fingerprint'   => $fingerprint,
			),
		) );
	}

	private function build_encryption( Config $config ): Encryption {
		$logging = new Logging( $config );
		$remote  = new Remote( $config, $logging );

		return new Encryption( $config, $remote, $logging );
	}

	/**
	 * Inject a known pubkey response for the vendor pubkey endpoint.
	 * Returns the cleanup callback the caller should invoke in tearDown.
	 */
	private function inject_pubkey_response( string $pubkey ): callable {
		$injector = static function ( $preempt, $args, $url ) use ( $pubkey ) {
			if ( ! is_string( $url ) || false === strpos( $url, 'public_key' ) ) {
				return $preempt;
			}

			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'publicKey' => $pubkey ) ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $injector, 10, 3 );

		return static function () use ( $injector ) {
			remove_filter( 'pre_http_request', $injector, 10 );
		};
	}

	private function clear_pubkey_transient( Config $config ): void {
		// Encryption caches the validated pubkey in a transient keyed
		// off the namespace; without clearing, a previous test\'s
		// successful fetch would short-circuit subsequent attempts.
		Utils::delete_transient( 'tl_' . $config->ns() . '_vendor_public_key' );
	}

	public function test_empty_fingerprint_bypasses_pin_check() {
		$config     = $this->build_config_with_fingerprint( '' );
		$encryption = $this->build_encryption( $config );
		$this->clear_pubkey_transient( $config );

		$cleanup = $this->inject_pubkey_response( self::FAKE_PUBKEY );

		$key = $encryption->get_vendor_public_key();

		$cleanup();

		$this->assertSame(
			self::FAKE_PUBKEY,
			$key,
			'Empty fingerprint must skip the pin check and return the fetched key.'
		);
	}

	public function test_matching_fingerprint_accepts_the_key() {
		$correct_fingerprint = hash( 'sha256', self::FAKE_PUBKEY );

		$config     = $this->build_config_with_fingerprint( $correct_fingerprint );
		$encryption = $this->build_encryption( $config );
		$this->clear_pubkey_transient( $config );

		$cleanup = $this->inject_pubkey_response( self::FAKE_PUBKEY );

		$key = $encryption->get_vendor_public_key();

		$cleanup();

		$this->assertSame(
			self::FAKE_PUBKEY,
			$key,
			'Matching fingerprint must accept the fetched key.'
		);
	}

	public function test_mismatching_fingerprint_refuses_the_key() {
		// A SHA-256 of a different value — same shape, wrong digest.
		$wrong_fingerprint = hash( 'sha256', 'this-is-not-the-real-pubkey' );

		$config     = $this->build_config_with_fingerprint( $wrong_fingerprint );
		$encryption = $this->build_encryption( $config );
		$this->clear_pubkey_transient( $config );

		$cleanup = $this->inject_pubkey_response( self::FAKE_PUBKEY );

		$result = $encryption->get_vendor_public_key();

		$cleanup();

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'Mismatched fingerprint must refuse the key with WP_Error.'
		);
		$this->assertSame(
			'public_key_fingerprint_mismatch',
			$result->get_error_code(),
			'Refusal must use the documented error code so the customer-facing template can react to it.'
		);
	}

	public function test_fingerprint_check_is_case_insensitive_on_expected_value() {
		// Real-world: integrators paste fingerprints from openssl,
		// which by convention emits lowercase hex. But a config file
		// might end up with uppercase via copy-paste. The SDK
		// strtolower()s the expected value before hash_equals so
		// uppercase configs still validate correctly.
		$correct_fingerprint = strtoupper( hash( 'sha256', self::FAKE_PUBKEY ) );

		$config     = $this->build_config_with_fingerprint( $correct_fingerprint );
		$encryption = $this->build_encryption( $config );
		$this->clear_pubkey_transient( $config );

		$cleanup = $this->inject_pubkey_response( self::FAKE_PUBKEY );

		$key = $encryption->get_vendor_public_key();

		$cleanup();

		$this->assertSame(
			self::FAKE_PUBKEY,
			$key,
			'Uppercase fingerprint config must still match a lowercase computed hash.'
		);
	}

	public function test_mismatched_pin_is_NOT_cached() {
		// If a rejected key were cached, a one-shot MITM could poison
		// subsequent (legitimate) fetches for the transient TTL —
		// 10 minutes — even after the network path is restored.
		// Verify the transient stays empty after a rejection.
		$wrong_fingerprint = hash( 'sha256', 'wrong' );

		$config     = $this->build_config_with_fingerprint( $wrong_fingerprint );
		$encryption = $this->build_encryption( $config );
		$this->clear_pubkey_transient( $config );

		$cleanup = $this->inject_pubkey_response( self::FAKE_PUBKEY );

		$encryption->get_vendor_public_key(); // expected: WP_Error

		$cleanup();

		$cached = Utils::get_transient( 'tl_' . $config->ns() . '_vendor_public_key' );
		$this->assertFalse(
			$cached,
			'A rejected pubkey must NOT be cached — caching would lock in the MITM.'
		);
	}
}

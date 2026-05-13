<?php
/**
 * Class TrustedLoginEnvelopeShapeTest
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class TrustedLoginEnvelopeShapeTest extends WP_UnitTestCase {

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * Cleanup callback returned by the pubkey HTTP injector.
	 *
	 * @var callable|null
	 */
	private $pubkey_cleanup;

	/**
	 * A fixed 64-hex (32-byte) value standing in for the vendor's
	 * sodium public key. The SDK shape-validates before using it,
	 * so any 64 hex chars satisfies Encryption::get_vendor_public_key().
	 */
	const FAKE_VENDOR_PUBKEY = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

	public function setUp(): void {
		parent::setUp();

		$this->config = new Config(
			array(
				'auth'   => array(
					'api_key'     => '9946ca31be6aa948',
					'license_key' => 'test-license-key',
				),
				'decay'  => WEEK_IN_SECONDS,
				'vendor' => array(
					'namespace'   => 'gravityview',
					'title'       => 'GravityView',
					'email'       => 'support@gravityview.co',
					'website'     => 'https://gravityview.co',
					'support_url' => 'https://gravityview.co/support/',
				),
			)
		);

		// The envelope path fetches the vendor pubkey over HTTPS.
		// Intercept that call so the test never depends on the live
		// vendor site being reachable.
		$injector = static function ( $preempt, $args, $url ) {
			if ( ! is_string( $url ) || false === strpos( $url, 'public_key' ) ) {
				return $preempt;
			}

			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'publicKey' => self::FAKE_VENDOR_PUBKEY ) ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $injector, 10, 3 );
		$this->pubkey_cleanup = static function () use ( $injector ) {
			remove_filter( 'pre_http_request', $injector, 10 );
		};

		// Avoid a stale transient short-circuiting the injector.
		Utils::delete_transient( 'tl_' . $this->config->ns() . '_vendor_public_key' );
	}

	public function tearDown(): void {
		if ( is_callable( $this->pubkey_cleanup ) ) {
			( $this->pubkey_cleanup )();
			$this->pubkey_cleanup = null;
		}
		Utils::delete_transient( 'tl_' . $this->config->ns() . '_vendor_public_key' );

		parent::tearDown();
	}

	/**
	 * Locks the wire contract between the client and the fake-saas (and the
	 * real SaaS). If this assertion fails, the client added or removed a
	 * field in Envelope::get() — decide in the SAME PR whether:
	 *
	 *   1. The fake-saas needs to learn the new field (update
	 *      tests/e2e/fake-saas/server.php to store/project it), OR
	 *   2. The field is client-only and doesn't need to cross the wire.
	 *
	 * Then update the expected list below.
	 */
	public function test_envelope_get_returns_expected_keys() {
		$logging    = new Logging( $this->config );
		$remote     = new Remote( $this->config, $logging );
		$encryption = new Encryption( $this->config, $remote, $logging );
		$envelope   = new Envelope( $this->config, $encryption );

		$result = $envelope->get( 'test-secret-id', 'test-identifier-hash', 'test-access-key' );

		$this->assertIsArray( $result, 'Envelope::get() returned a WP_Error — check sodium availability.' );

		$this->assertSame(
			array(
				'secretId',
				'identifier',
				'siteUrl',
				'publicKey',
				'accessKey',
				'wpUserId',
				'expiresAt',
				'version',
				'nonce',
				'clientPublicKey',
				'metaData',
			),
			array_keys( $result ),
			'Envelope::get() shape drifted. See the class docblock for what to do.'
		);
	}
}

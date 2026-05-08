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

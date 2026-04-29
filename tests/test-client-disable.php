<?php
/**
 * Class TrustedLoginClientDisableTest
 *
 * Pins the TRUSTEDLOGIN_DISABLE + TRUSTEDLOGIN_DISABLE_{NS} kill-switch
 * contract.
 *
 * These tests live in their own file (and run in separate PHP processes)
 * because of two intertwined PHP-level constraints:
 *
 *   1. PHP `define()` is process-global. Once a kill-switch constant is
 *      defined, every subsequent Client construct in the SAME process
 *      that resolves to that namespace fails. That would poison every
 *      other test that builds a Client with the same namespace.
 *
 *   2. Config::ns() uses a `static` cache so the FIRST Config in the
 *      process wins the namespace. A no-setUp() class is the only way
 *      to ensure the test's own Config is the first one — otherwise the
 *      shared TrustedLoginClientTest::setUp() would lock the namespace
 *      to 'gravityview' before the kill-switch test's namespace is
 *      registered.
 *
 * Each test below uses @runInSeparateProcess to fork a clean PHP
 * process for the test. A dedicated test file (no setUp constructing a
 * Config) is layered on top so the test's own Config is genuinely the
 * first ns() caller in the forked process.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class TrustedLoginClientDisableTest extends WP_UnitTestCase {

	private function _settings( string $ns ): array {
		return array(
			'role'   => 'editor',
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'decay'  => WEEK_IN_SECONDS,
			'vendor' => array(
				'namespace'   => $ns,
				'title'       => 'Disable Test',
				'email'       => $ns . '@example.test',
				'website'     => 'https://example.test',
				'support_url' => 'https://example.test/support',
			),
		);
	}

	/**
	 * Per-namespace kill switch: TRUSTEDLOGIN_DISABLE_<strtoupper($ns)>
	 * defined and truthy → Client constructor throws 403 Exception.
	 *
	 * The strtoupper($ns) is verbatim — dashes preserved. PHP define()
	 * accepts dashes in constant names; defined() returns true for them
	 * even though the bareword form is invalid syntax.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disable_namespace_constant_short_circuits_init() {
		$ns       = 'killswitchns';
		$constant = 'TRUSTEDLOGIN_DISABLE_' . strtoupper( $ns );
		define( $constant, true );

		$this->expectException( \Exception::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'TrustedLogin has been disabled for this namespace' );

		new Client( new Config( $this->_settings( $ns ) ) );
	}

	/**
	 * Global kill switch: TRUSTEDLOGIN_DISABLE truthy → EVERY namespace's
	 * Client refuses to initialize.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disable_global_constant_short_circuits_init() {
		define( 'TRUSTEDLOGIN_DISABLE', true );

		$this->expectException( \Exception::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'disabled globally' );

		new Client( new Config( $this->_settings( 'globaldisable' ) ) );
	}

	/**
	 * A wrong-namespace kill switch must NOT disable the real namespace.
	 *
	 * If a future SDK refactor accidentally case-folds or normalizes the
	 * constant name in a way that creates "fuzzy" matching, this test
	 * fails — surfacing the regression instead of silently turning every
	 * neighbour-namespace integrator off.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wrong_namespace_constant_does_not_disable_real_namespace() {
		// strtoupper('reallive') = REALLIVE. Define a constant for a
		// DIFFERENT namespace and confirm the Client for 'reallive'
		// still constructs without throwing.
		define( 'TRUSTEDLOGIN_DISABLE_DIFFERENT', true );

		$client = new Client( new Config( $this->_settings( 'reallive' ) ) );
		$this->assertInstanceOf( Client::class, $client );
	}
}

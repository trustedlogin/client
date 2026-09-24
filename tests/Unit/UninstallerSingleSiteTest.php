<?php
/**
 * Uninstaller on single-site WordPress, where the multisite API is not loaded.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustedLogin\Uninstaller;

class UninstallerSingleSiteTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['tl_unit_options'], $GLOBALS['tl_unit_rewrite_flushes'] );
		parent::tearDown();
	}

	/**
	 * Rewrite rules that still carry the login endpoint are flushed on the
	 * current site, without reaching for multisite-only functions.
	 */
	public function test_stale_rewrite_rules_are_flushed_without_multisite_functions() {
		$this->assertFalse( function_exists( 'ms_is_switched' ), 'fixture: this suite must model single-site WordPress' );

		$GLOBALS['tl_unit_options'] = array(
			'rewrite_rules' => array( 'abc123endpoint/?$' => 'index.php?tl=1' ),
		);

		$uninstaller = ( new \ReflectionClass( Uninstaller::class ) )->newInstanceWithoutConstructor();
		$method      = new \ReflectionMethod( Uninstaller::class, 'flush_stale_rewrite_rules' );
		$method->setAccessible( true );

		$method->invoke( $uninstaller, 'abc123endpoint' );

		$this->assertSame( 1, $GLOBALS['tl_unit_rewrite_flushes'], 'the current site\'s rules must be flushed' );
	}
}

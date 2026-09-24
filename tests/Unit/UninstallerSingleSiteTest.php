<?php
/**
 * Uninstaller on single-site WordPress, where the multisite API is not loaded.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustedLogin\Config;
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

	/**
	 * Builds an Uninstaller with its run state set, skipping the constructor.
	 *
	 * @param array $args   Run options.
	 * @param array $config Config settings.
	 *
	 * @return array{0: Uninstaller, 1: \ReflectionClass}
	 */
	private function uninstaller( array $args, array $config = array( 'vendor' => array( 'namespace' => 'unit-ns' ) ) ) {
		$reflection  = new \ReflectionClass( Uninstaller::class );
		$uninstaller = $reflection->newInstanceWithoutConstructor();

		$values = array(
			'config' => new Config( $config ),
			'args'   => $args,
			'report' => array(
				'network_skipped'     => false,
				'saas_revokes'        => 0,
				'saas_revokes_failed' => array(),
			),
		);

		foreach ( $values as $name => $value ) {
			$property = $reflection->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( $uninstaller, $value );
		}

		return array( $uninstaller, $reflection );
	}

	/**
	 * A single site is the whole install, so every run visits every site and
	 * may remove the endpoint before cleaning, even with `network => false`.
	 */
	public function test_a_single_site_run_always_visits_every_site() {
		list( $uninstaller, $reflection ) = $this->uninstaller( array( 'network' => false, 'delete_logs' => true ) );

		$method = $reflection->getMethod( 'visits_every_site' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $uninstaller ) );
	}

	/**
	 * A revoke skipped for the SSL requirement sends nothing, so it is
	 * reported as not revoked rather than counted.
	 */
	public function test_a_revoke_skipped_for_the_ssl_requirement_is_reported_not_counted() {
		list( $uninstaller, $reflection ) = $this->uninstaller(
			array( 'network' => null, 'delete_logs' => true ),
			array(
				'auth'   => array( 'api_key' => '0123456789abcdef' ),
				'vendor' => array( 'namespace' => 'unit-ns' ),
			)
		);

		$method = $reflection->getMethod( 'revoke_at_saas' );
		$method->setAccessible( true );
		$method->invoke( $uninstaller, 'secret-no-ssl' );

		$report = $reflection->getProperty( 'report' );
		$report->setAccessible( true );
		$result = $report->getValue( $uninstaller );

		$this->assertSame( 0, $result['saas_revokes'] );
		$this->assertSame( array( 'secret-no-ssl' ), $result['saas_revokes_failed'] );
	}
}

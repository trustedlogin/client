<?php
/**
 * Integration tests pinning the brute-force counter behavior in
 * SecurityChecks.
 *
 * The counter is keyed by `sha256(REMOTE_ADDR) | identifier`. Three
 * matches per IP trips a site-wide lockdown.
 *
 * Documented trade-off:
 *   - Same IP, multiple identifiers → counter accumulates per IP.
 *     This is the brute-force defense: an attacker on one IP can\'t
 *     burn past 3 wrong identifiers before getting locked out.
 *   - Different IPs, same identifier → counter does NOT aggregate.
 *     Per-IP scoping prevents one attacker from DoSing legit support
 *     by cycling 3 identifiers, but means an attacker with a
 *     botnet (or X-Forwarded-For spoofing — though Utils::get_ip
 *     reads REMOTE_ADDR only, NOT XFF) can guess unbounded.
 *
 * Both behaviors are documented in SecurityChecks::maybe_add_used_accesskey
 * with a comment explaining the cross-IP DoS reasoning. This file
 * pins both — if the per-IP scoping is ever removed, that test
 * fails. If aggregate-across-IPs is ever ADDED (improving the
 * defense), the cross-IP test fails — and that\'s a deliberate
 * regression marker so the trade-off change is intentional.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use ReflectionClass;
use WP_UnitTestCase;
use WP_Error;

class TrustedLoginSecurityChecksBruteForceTest extends WP_UnitTestCase {

	/** @var Config */
	private $config;

	/** @var Logging */
	private $logging;

	/** @var SecurityChecks */
	private $checks;

	/** @var ReflectionClass */
	private $checks_reflection;

	public function setUp(): void {
		parent::setUp();

		$this->config = new Config( array(
			'auth'   => array( 'api_key' => '9946ca31be6aa948' ),
			'vendor' => array(
				'namespace'   => 'brute-force-test',
				'title'       => 'Brute Force Test',
				'email'       => 'support@example.com',
				'website'     => 'https://vendor.example.com',
				'support_url' => 'https://vendor.example.com/support',
			),
		) );

		$this->logging = new Logging( $this->config );
		$this->checks  = new SecurityChecks( $this->config, $this->logging );

		$this->checks_reflection = new ReflectionClass( SecurityChecks::class );

		// Drain any transients carried over from earlier tests.
		Utils::delete_transient( 'tl-' . $this->config->ns() . '-used_accesskeys' );
		Utils::delete_transient( 'tl-' . $this->config->ns() . '-in_lockdown' );

		// Anchor REMOTE_ADDR so the per-IP scoping is deterministic.
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1'; // RFC 5737 TEST-NET-1
	}

	public function tearDown(): void {
		Utils::delete_transient( 'tl-' . $this->config->ns() . '-used_accesskeys' );
		Utils::delete_transient( 'tl-' . $this->config->ns() . '-in_lockdown' );
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tearDown();
	}

	/**
	 * Invoke the private check_brute_force(). Returns true (under
	 * limit) or WP_Error (over limit, lockdown engaged).
	 *
	 * @param string $identifier
	 *
	 * @return true|WP_Error
	 */
	private function check( string $identifier ) {
		$method = $this->checks_reflection->getMethod( 'check_brute_force' );
		$method->setAccessible( true );

		return $method->invoke( $this->checks, $identifier );
	}

	public function test_third_distinct_identifier_from_one_IP_trips_lockdown() {
		// ACCESSKEY_LIMIT_COUNT is 3 and the check fires when
		// `count >= limit`. So:
		//   attempt 1 → count = 1, under
		//   attempt 2 → count = 2, under
		//   attempt 3 → count = 3, AT limit, WP_Error
		// Lock the limit value so a future bump there forces a
		// re-read of this spec.
		$limit_const = $this->checks_reflection->getConstant( 'ACCESSKEY_LIMIT_COUNT' );
		$this->assertSame( 3, $limit_const,
			'ACCESSKEY_LIMIT_COUNT must remain 3 — the spec\'s assertions key off it.' );

		$this->assertTrue( $this->check( 'identifier-A' ), 'attempt 1 under limit' );
		$this->assertTrue( $this->check( 'identifier-B' ), 'attempt 2 under limit' );

		$result = $this->check( 'identifier-C' );
		$this->assertInstanceOf( WP_Error::class, $result,
			'attempt 3 reaches the limit (count == 3 >= 3) and trips brute-force detection' );
		$this->assertSame( 'brute_force_detected', $result->get_error_code() );
	}

	public function test_repeating_same_identifier_from_same_IP_does_not_double_bump() {
		Utils::delete_transient( 'tl-' . $this->config->ns() . '-used_accesskeys' );

		// First call counts (count = 1).
		$this->assertTrue( $this->check( 'sticky-ident' ) );

		// Repeats short-circuit on in_array — count stays 1.
		$this->assertTrue( $this->check( 'sticky-ident' ),
			'repeated identical attempt must not double-count' );
		$this->assertTrue( $this->check( 'sticky-ident' ),
			'a third repeat still must not double-count' );

		// One MORE distinct identifier (count = 2). Still under
		// limit because the duplicate didn\'t bump.
		$this->assertTrue( $this->check( 'fresh-ident-1' ),
			'fresh identifier #1 brings count to 2 — still under limit' );

		// Second new identifier (count = 3) trips.
		$result = $this->check( 'fresh-ident-2' );
		$this->assertInstanceOf( WP_Error::class, $result,
			'fresh identifier #2 brings count to 3 and trips — proves the duplicate above did not contribute extra increments' );
	}

	public function test_counter_does_NOT_aggregate_across_different_REMOTE_ADDRs() {
		Utils::delete_transient( 'tl-' . $this->config->ns() . '-used_accesskeys' );

		// Same identifier from many different IPs. Each IP is
		// independently scoped, so each one\'s per-IP counter sees
		// only "1 attempt" (its own).
		$ips = array(
			'203.0.113.1', '203.0.113.2', '203.0.113.3',
			'203.0.113.4', '203.0.113.5', '203.0.113.6',
			'203.0.113.7', '203.0.113.8',
		);

		foreach ( $ips as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			$result = $this->check( 'shared-target-identifier' );
			$this->assertTrue(
				$result === true,
				"each IP starts with a fresh per-IP counter — IP $ip should NOT be locked out. "
					. 'If this assertion ever fails, the SDK gained a global per-identifier '
					. 'counter (security improvement) — adjust the test to reflect the new model.'
			);
		}

		// Lockdown transient must remain unset across the 8 attempts.
		$lockdown = Utils::get_transient( 'tl-' . $this->config->ns() . '-in_lockdown' );
		$this->assertFalse( $lockdown,
			'8 attempts at the same identifier from 8 different IPs must NOT trip the lockdown — '
				. 'documented trade-off: per-IP scoping prevents single-IP DoS, at the cost of '
				. 'allowing botnet brute-force.' );
	}

	public function test_three_attempts_from_single_IP_trips_brute_force_check() {
		// Three failed attempts from the same IP must return WP_Error
		// from check_brute_force. The site-wide lockdown side-effect
		// (do_lockdown setting the namespace-scoped transient) is
		// covered separately by security-lockdown-during-attack.spec.ts.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->check( 'a' );
		$this->check( 'b' );
		$result_x = $this->check( 'c' );
		$this->assertInstanceOf( WP_Error::class, $result_x,
			'3 distinct identifiers from one IP must trip the per-IP brute-force counter' );
	}
}

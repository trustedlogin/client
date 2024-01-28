<?php
/**
 * Class TrustedLoginUtilsTest
 *
 * @package Trustedlogin_Button
 */

use TrustedLogin\Utils;

class TrustedLoginUtilsTest extends WP_UnitTestCase {

	/**
	 * @var wpdb
	 */
	protected $wpdb;

	public function setUp(): void {
		parent::setUp();
	}

	public function testSetTransientWithoutExpiration() {
		$transient = 'transient';
		$value     = 'value';

		$result = Utils::set_transient( $transient, $value );

		$this->assertTrue( $result );

		$row = get_option( $transient );

		$this->assertTrue( is_array( $row ) );
		$this->assertSame( $row['expiration'], 0 );
		$this->assertSame( $row['value'], $value );
	}

	public function testSetTransientWithExpiration() {
		$transient  = 'transient';
		$value      = 'value';
		$expiration = 60;
		$time = time();
		$expiration_time = $time + $expiration;

		$result = Utils::set_transient( $transient, $value, $expiration );

		$this->assertTrue( $result );

		$row = get_option( $transient );

		$this->assertTrue( is_array( $row ) );
		$this->assertSame( $row['expiration'], $expiration_time );
		$this->assertSame( $row['value'], $value );
	}

	public function testSetTransientExpiration() {
		$transient  = 'transient';
		$value      = 'value';
		$expiration = 1;
		$expiration_time = time() + $expiration;

		$result = Utils::set_transient( $transient, $value, $expiration );
		$this->assertTrue( $result );

		$row = get_option( $transient );

		$this->assertTrue( is_array( $row ) );
		$this->assertSame( $row['expiration'], $expiration_time );
		$this->assertSame( $row['value'], $value );

		$result = Utils::get_transient( $transient );
		$this->assertSame( $result, $value );

		sleep( 2 );

		$result = Utils::get_transient( $transient );
		$this->assertFalse( $result );
	}

	public function testSetTransientObject() {
		$transient  = 'transient';
		$value      = new stdClass();
		$value->example = 'example';

		$result = Utils::set_transient( $transient, $value );

		$this->assertTrue( $result );

		$row = get_option( $transient );

		$this->assertTrue( is_array( $row ) );
		$this->assertEquals( $row['value'], $value );
	}

	public function testRetrievesTransientWithoutExpiration() {
		$transient = 'transient';
		$value     = 'value';

		Utils::set_transient( $transient, $value );

		$result = Utils::get_transient( $transient );

		$this->assertEquals( $value, $result );
	}

	public function testGetTransientWithExpiredExpiration() {
		$transient  = 'transient';
		$value      = 'value';
		$expiration = - 1;

		$result = Utils::set_transient( $transient, $value, $expiration );

		$this->assertTrue( $result );

		$row = Utils::get_transient( $transient );

		$this->assertFalse( $row );
	}

	// Additional test methods follow the same pattern...
	// Note: For brevity, I have not included the full details of each test case.
	// You will need to adjust the specific expectations and return values as needed for your testing scenarios.

	public function tearDown(): void {
		parent::tearDown();
	}
}

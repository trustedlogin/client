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

	/**
	 * @var array
	 */
	protected $site_ids;

	public function setUp(): void {
		parent::setUp();

		$this->create_sites();
	}

	public function create_sites() {
		$this->site_ids = array();
		$this->site_ids[] = get_current_blog_id();
		$this->site_ids[] = $this->factory->blog->create( array( 'domain' => 'example.com', 'path' => '/' ) );
		$this->site_ids[] = $this->factory->blog->create( array( 'domain' => 'example.com', 'path' => '/example/' ) );
	}

	public function testSetTransientWithoutExpiration() {
		$transient = 'transient';
		$value     = 'value';

		$result = Utils::set_transient( $transient, $value );

		$this->assertEquals( 1, $result );

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

		$this->assertEquals( 1, $result );

		$row = get_option( $transient );

		$this->assertTrue( is_array( $row ) );
		$this->assertSame( $row['expiration'], $expiration_time );
		$this->assertSame( $row['value'], $value );
	}

	/**
	 * Tests that transients are deleted after expiration.
	 * Naming this test with ZZZ so it runs last, since it sets an expiration time.
	 */
	public function testZZZSetTransientExpiration() {
		$transient  = 'transient';
		$value      = 'value';
		$expiration = 1;
		$expiration_time = time() + $expiration;

		$result = Utils::set_transient( $transient, $value, $expiration );
		$this->assertEquals( 1, $result );

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

	public function testSetTransientsWhenSwitchingSites() {
		$transient = 'transient';
		$value     = 'value';

		// Initial site.
		switch_to_blog( $this->site_ids[0] );

		$result = Utils::set_transient( $transient, $value );
		$this->assertEquals( 1, $result );

		switch_to_blog( $this->site_ids[1] );

		// Other sites should not have access to the transient.
		$result = Utils::get_transient( $transient );
		$this->assertFalse( $result );

		$transient_site_2 = 'transient_site_2';
		$value_site_2     = 'value_site_2';

		$result = Utils::set_transient( $transient_site_2, $value_site_2 );
		$this->assertEquals( 1, $result );

		switch_to_blog( $this->site_ids[2] );

		// Other sites should not have access to the transient.
		$result = Utils::get_transient( $transient );
		$this->assertFalse( $result );

		$result = Utils::get_transient( $transient_site_2 );
		$this->assertFalse( $result );

		$transient_site_3 = 'transient_site_3';
		$value_site_3     = 'value_site_3';

		$result = Utils::set_transient( $transient_site_3, $value_site_3 );
		$this->assertEquals( 1, $result );

		switch_to_blog( $this->site_ids[0] );

		// Sanity check that it's still there.
		$result = Utils::get_transient( $transient );
		$this->assertSame( $result, $value );

		// And that the other site's transients are not.
		$result = Utils::get_transient( $transient_site_2 );
		$this->assertFalse( $result );

		$result = Utils::get_transient( $transient_site_3 );
		$this->assertFalse( $result );

		restore_current_blog();
	}

	public function testSetTransientObject() {
		$transient  = 'transient';
		$value      = new stdClass();
		$value->example = 'example';

		$result = Utils::set_transient( $transient, $value );

		$this->assertEquals( 1, $result );

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

		$this->assertEquals( 1, $result );

		$row = Utils::get_transient( $transient );

		$this->assertFalse( $row );
	}

	// Additional test methods follow the same pattern...
	// Note: For brevity, I have not included the full details of each test case.
	// You will need to adjust the specific expectations and return values as needed for your testing scenarios.

	/**
	 * @covers \TrustedLogin\Utils::delete_transient
	 *
	 * Round-trips: set a transient via Utils::set_transient, confirm it
	 * reads back, delete via Utils::delete_transient, confirm it's gone.
	 */
	public function test_delete_transient_roundtrip() {
		$key = 'tl_delete_transient_test_' . wp_generate_password( 12, false );

		Utils::set_transient( $key, array( 'payload' => 'still-here' ), 3600 );

		$this->assertSame(
			array( 'payload' => 'still-here' ),
			Utils::get_transient( $key ),
			'Expected value to round-trip through set_transient → get_transient'
		);

		$this->assertTrue(
			Utils::delete_transient( $key ),
			'delete_transient should return true for an existing transient'
		);

		$this->assertFalse(
			Utils::get_transient( $key ),
			'get_transient should return false after delete_transient'
		);
	}

	/**
	 * @covers \TrustedLogin\Utils::delete_transient
	 *
	 * Guard rails: empty/non-string inputs return false without side
	 * effects. Matches the defensive behavior of set_transient for the
	 * same input types.
	 */
	public function test_delete_transient_rejects_bad_input() {
		$this->assertFalse( Utils::delete_transient( '' ) );
		// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- testing defensive behavior with mixed types
		$this->assertFalse( Utils::delete_transient( 0 ) );
		$this->assertFalse( Utils::delete_transient( null ) );
		$this->assertFalse( Utils::delete_transient( array( 'not-a-string' ) ) );
	}

	/**
	 * @covers \TrustedLogin\Utils::delete_transient
	 *
	 * Deleting a transient that doesn't exist should return false
	 * (matching WP's delete_option behavior) and not throw.
	 */
	public function test_delete_transient_missing_returns_false() {
		$this->assertFalse(
			Utils::delete_transient( 'tl_missing_transient_' . wp_generate_password( 12, false ) )
		);
	}

	public function tearDown(): void {
		parent::tearDown();
	}
}

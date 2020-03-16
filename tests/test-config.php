<?php
/**
 * Class TrustedLoginConfigTest
 *
 * @package TrustedLogin\Client
 */


class TrustedLoginConfigTest extends WP_UnitTestCase {

	/**
	 * @covers \TrustedLogin\Config::__construct
	 * @covers \TrustedLogin\Config::validate
	 */
	public function test_config_vendor_stuff() {

		$expected_codes = array(
			1 => 'empty configuration array',
			2 => 'replace default namespace',
			3 => 'invalid configuration array',
		);

		try {

			$config = new \TrustedLogin\Config(array(
				'vendor' => true
			));

			$config->validate();

			$client = new TrustedLogin\Client( $config );

		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] . ' ' .$exception->getMessage() );
			$this->assertContains( 'public key', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/namespace', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/title', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/email', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/website', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/support_url', $exception->getMessage(), $expected_codes[3] );
		}
	}

	/**
	 * @covers \TrustedLogin\Config::__construct
	 * @covers \TrustedLogin\Config::validate
	 */
	public function test_config_public_key() {

		$expected_codes = array(
			1 => 'empty configuration array',
			2 => 'replace default namespace',
			3 => 'invalid configuration array',
		);

		try {

			$config = new \TrustedLogin\Config(array(
				'vendor' => true,
				'auth' => array( 'public_key' => 'asdasd' ),
			));

			$config->validate();

			$client = new TrustedLogin\Client( $config );

		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] );
			$this->assertNotContains( 'public key', $exception->getMessage(), $expected_codes[3] );
		}
	}

	/**
	 * @covers \TrustedLogin\Config::__construct
	 * @covers \TrustedLogin\Config::validate
	 */
	public function test_config_urls() {

		$expected_codes = array(
			1 => 'empty configuration array',
			2 => 'replace default namespace',
			3 => 'invalid configuration array',
		);

		$valid_config = array(
			'auth' => array(
				'public_key' => 'not empty',
			),
			'webhook_url' => 'https://www.google.com',
			'vendor' => array(
				'namespace' => 'jonesbeach',
				'title' => 'Jones Beach Party',
				'first_name' => null,
				'last_name' => '',
				'email' => 'beach@example.com',
				'website' => 'https://example.com',
				'support_url' => 'https://example.com',
			),
		);

		try {
			$invalid_website_url = $valid_config;
			$invalid_website_url['webhook_url'] = 'asdasdsd';
			$invalid_website_url['vendor']['support_url'] = 'asdasdsd';
			$invalid_website_url['vendor']['website'] = 'asdasdsd';

			$config = new \TrustedLogin\Config( $invalid_website_url );

			$config->validate();

			new TrustedLogin\Client( $config );
		} catch ( \Exception $exception ) {
			$this->assertEquals( 3, $exception->getCode(), $expected_codes[3] );
			$this->assertContains( 'webhook_url', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/support_url', $exception->getMessage(), $expected_codes[3] );
			$this->assertContains( 'vendor/website', $exception->getMessage(), $expected_codes[3] );
		}
	}

	/**
	 * @covers \TrustedLogin\Config::__construct
	 */
	public function test_config_not_array_string() {
		$this->expectException( TypeError::class );
		new \TrustedLogin\Config( 'asdsadsd' );
	}

	/**
	 * @covers \TrustedLogin\Config::__construct
	 */
	public function test_config_not_array_object() {
		$this->expectException( TypeError::class );
		$object = new ArrayObject();
		new \TrustedLogin\Config( $object );
	}

	/**
	 * @covers \TrustedLogin\Config::__construct
	 */
	public function test_config_empty_array() {
		$this->expectException( Exception::class );
		$this->expectExceptionCode( 1 );
		new \TrustedLogin\Config( array() );
	}

	/**
	 * @covers \TrustedLogin\Config::__construct
	 */
	public function test_config_empty() {
		$this->expectException( Exception::class );
		$this->expectExceptionCode( 1 );
		new \TrustedLogin\Config();
	}

	/**
	 * @covers \TrustedLogin\Config::get_setting()
	 */
	public function test_get_setting() {

		$config_array = array(
			'auth' => array(
				'public_key' => 'not empty',
			),
			'webhook_url' => 'https://www.google.com',
			'vendor' => array(
				'namespace' => 'jones-party',
				'title' => 'Jones Beach Party',
				'first_name' => null,
				'last_name' => '',
				'email' => 'beach@example.com',
				'website' => 'https://example.com',
				'support_url' => 'https://asdasdsd.example.com/support/',
			),
			'paths' => array(
				'css' => null,
			),
			'decay' => 0,
		);

		$config = new \TrustedLogin\Config( $config_array );

		$config->validate();

		$TL = new \TrustedLogin\Client( $config );

		$this->assertEquals( 0, $TL->get_setting( 'decay' ) );

		$this->assertEquals( 'https://www.google.com', $TL->get_setting( 'webhook_url') );

		$this->assertEquals( 'Jones Beach Party', $TL->get_setting( 'vendor/title') );

		$this->assertEquals( false, $TL->get_setting( 'non-existent key') );

		$this->assertEquals( 'default override', $TL->get_setting( 'non-existent key', 'default override' ) );

		$this->assertEquals( false, $TL->get_setting( 'vendor/first_name' ), 'Should use method default value (false) when returned value is NULL' );

		$this->assertEquals( 'default override', $TL->get_setting( 'vendor/first_name', 'default override' ), 'should use default override if value is NULL' );

		$this->assertEquals( '', $TL->get_setting( 'vendor/last_name' ) );


		$this->assertNotNull( $TL->get_setting( 'paths/css' ), 'Being passed NULL should not override default.' );
		$this->assertNotFalse( $TL->get_setting( 'paths/css' ), 'Being passed NULL should not override default.' );
		$this->assertContains( '.css', $TL->get_setting( 'paths/css' ), 'Being passed NULL should not override default.' );

		// Test passed array values
		$passed_array = array(
			'try' => 'and try again',
			'first' => array(
				'three_positive_integers' => 123,
			),
		);
		$this->assertEquals( 'and try again', $TL->get_setting( 'try', null, $passed_array ) );
		$this->assertEquals( null, $TL->get_setting( 'missssing', null, $passed_array ) );
		$this->assertEquals( '123', $TL->get_setting( 'first/three_positive_integers', null, $passed_array ) );
	}
}

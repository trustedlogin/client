
	/**
	 * @covers \TrustedLogin\Client::get_setting()
	 */
	public function test_get_setting() {

		$config = array(
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
		);

		$TL = new \TrustedLogin\Client( $config );

		$this->assertEquals( 'https://www.google.com', $TL->get_setting( 'webhook_url') );

		$this->assertEquals( 'Jones Beach Party', $TL->get_setting( 'vendor/title') );

		$this->assertFalse( $TL->get_setting( 'non-existent key') );

		$this->assertEquals( 'default override', $TL->get_setting( 'non-existent key', 'default override' ) );

		$this->assertFalse( $TL->get_setting( 'vendor/first_name' ), 'Should use method default value (false) when returned value is NULL' );

		$this->assertEquals( 'default override', $TL->get_setting( 'vendor/first_name', 'default override' ), 'should use default override if value is NULL' );

		$this->assertEquals( '', $TL->get_setting( 'vendor/last_name' ) );

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
		$this->assertWPError( $this->TrustedLogin->api_send( 'any-path', 'any data', 'not supported http method' ) );

		// Make sure the body has been removed from methods that don't support it
		add_filter( 'http_request_args', $filter_args = function ( $parsed_args, $url ) {
			$this->assertNull( $parsed_args['body'] );
			return $parsed_args;
		}, 10, 2 );

		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', 'any data', 'get' ), 'The method failed to auto-uppercase methods.' );
		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', 'any data', 'GET' ) );
		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', 'any data', 'HEAD' ) );

		remove_filter( 'http_request_args', $filter_args );

		// Make sure that POST and DELETE are able to sent body and that the body is properly formatted
		add_filter( 'http_request_args', $filter_args = function ( $parsed_args, $url ) {
			$this->assertEquals( json_encode( array( 'test', 'array' ) ), $parsed_args['body'] );
			return $parsed_args;
		}, 10, 2 );

		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', array( 'test', 'array' ), 'POST' ) );
		$this->assertNotWPError( $this->TrustedLogin->api_send( 'sites', array( 'test', 'array' ), 'DELETE' ) );

		remove_filter( 'http_request_args', $filter_args );

	/**
	 * @throws ReflectionException
	 * @covers TrustedLogin\Client::build_api_url
	 */
	public function test_build_api_url() {

		$method = $this->_get_public_method( 'build_api_url' );

		$this->assertEquals( $this->TrustedLogin::saas_api_url, $method->invoke( $this->TrustedLogin ) );

		$this->assertEquals( $this->TrustedLogin::saas_api_url, $method->invoke( $this->TrustedLogin, array('not-a-string') ) );

		$this->assertEquals( $this->TrustedLogin::saas_api_url . 'pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		add_filter( 'trustedlogin/not-my-namespace/api-url', function () { return 'https://www.google.com'; } );

		$this->assertEquals( $this->TrustedLogin::saas_api_url . 'pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/not-my-namespace/api-url' );

		add_filter( 'trustedlogin/gravityview/api-url', function () { return 'https://www.google.com'; } );

		$this->assertEquals( 'https://www.google.com/pathy-path', $method->invoke( $this->TrustedLogin, 'pathy-path' ) );

		remove_all_filters( 'trustedlogin/gravityview/api-url' );
	}

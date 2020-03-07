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

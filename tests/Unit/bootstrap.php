<?php
/**
 * Bootstrap for the pure-PHPUnit unit-test suite.
 *
 * Loads the project's Composer autoloader and stubs the WP API
 * surface that the SUT (TrustedLogin\LoginAttempts) reaches for
 * at runtime. The integration suite at tests/test-*.php runs
 * against a real WP test environment via tests/bootstrap.php —
 * keep these two bootstraps separate so the unit suite has no
 * implicit WP cost.
 */

// Every src/ file starts with `if (!defined('ABSPATH')) exit;` —
// define it before loading the autoloader so SUT classes load
// instead of silently aborting.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'TL_DOING_TESTS' ) ) {
	define( 'TL_DOING_TESTS', true );
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] )
			? $response['response']['code']
			: 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] )
			? (string) $response['body']
			: '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! class_exists( '\WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

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

// WP time-window constants used by Config defaults / decay validation.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
}

// Form output uses esc_url + esc_html.
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		// Match real esc_url's permissive scheme handling — http and
		// https BOTH valid (vendor sites may legitimately be on
		// http; the vendor controls their own infrastructure).
		// javascript: / data: / file: / vbscript: rejected.
		if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			return '';
		}
		// URL-encode the characters that would break a CSS / HTML
		// attribute string context.
		return str_replace(
			array( '"', "'", '<', '>', ' ' ),
			array( '%22', '%27', '%3C', '%3E', '%20' ),
			$url
		);
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { return (string) $text; }
}
if ( ! function_exists( 'wp_kses_bad_protocol' ) ) {
	function wp_kses_bad_protocol( $string, $allowed_protocols ) {
		// Tiny safe-list approximation: empty out the URL if it doesn't
		// start with one of the allowed schemes.
		$string = (string) $string;
		foreach ( (array) $allowed_protocols as $proto ) {
			if ( 0 === stripos( $string, $proto . ':' ) ) {
				return $string;
			}
		}
		return '';
	}
}
if ( ! function_exists( 'sanitize_title_with_dashes' ) ) {
	function sanitize_title_with_dashes( $title ) {
		$title = strtolower( (string) $title );
		return preg_replace( '/[^a-z0-9-]/', '-', $title );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {}
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() { return 'https://example.test'; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) { return true; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/test/'; }
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/uploads' ); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0755, true ); }
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( ...$args ) {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( ...$args ) {}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $str ) );
		return trim( $str );
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

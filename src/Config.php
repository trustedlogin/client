<?php
/**
 * Class Config
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

final class Config {

	/**
	 * @var array Default settings values
	 * @since 0.9.6
	 */
	private $default_settings = array(
		'auth' => array(
			'public_key' => null,
			'private_key' => null,
		),
		'decay' => WEEK_IN_SECONDS,
		'role' => 'editor',
		'paths' => array(
			'css' => null, // Default is defined in get_default_settings()
			'js'  => null, // Default is defined in get_default_settings()
		),
		'caps' => array(
			'add' => array(
			),
			'remove' => array(
			),
		),
		'webhook_url' => null,
		'vendor' => array(
			'namespace' => null,
			'title' => null,
			'first_name' => null,
			'last_name' => null,
			'email' => null,
			'website' => null,
			'support_url' => null,
			'logo_url' => null,
		),
		'menu' => array(
			'slug' => null,
			'title' => null,
			'priority' => null,
		),
		'reassign_posts' => true,
		'require_ssl' => true,
		'logging' => array(
			'enabled' => false,
			'directory' => null, // Set to WP_CONTENT_DIR . '/debug.log' in Logging class
			'threshold' => 'debug',
			'options' => array(),
		)
	);

	/**
	 * @var array $settings Configuration array after parsed and validated
	 * @since 0.1.0
	 */
	private $settings = array();

	/**
	 * Config constructor.
	 *
	 * @param array $settings
	 */
	public function __construct( array $settings = array() ) {

		if ( empty( $settings ) ) {
			throw new \Exception( 'Developer: TrustedLogin requires a configuration array. See https://trustedlogin.com/configuration/ for more information.', 1 );
		}

		$this->settings = $settings;
	}


	/**
	 * @param array $config
	 * @param array $settings
	 * @param bool  $throw_exception
	 *
	 * @throws \Exception
	 */
	public function validate() {


		if ( in_array( __NAMESPACE__, array( 'ReplaceMe', 'ReplaceMe\TrustedLogin' ) ) && ! defined('TL_DOING_TESTS') ) {
			throw new \Exception( 'Developer: make sure to change the namespace for the TrustedLogin class. See https://trustedlogin.com/configuration/ for more information.', 2 );
		}

		$errors = array();

		if ( ! isset( $this->settings['auth']['public_key'] ) ) {
			$errors[] = new WP_Error( 'missing_configuration', 'You need to set a public key. Get yours at https://app.trustedlogin.com' );
		}

		foreach( array( 'namespace', 'title', 'website', 'support_url', 'email' ) as $required_vendor_field ) {
			if ( ! isset( $this->settings['vendor'][ $required_vendor_field ] ) ) {
				$errors[] = new WP_Error( 'missing_configuration', sprintf( 'Missing required configuration: `vendor/%s`', $required_vendor_field ) );
			}
		}

		foreach( array( 'webhook_url', 'vendor/support_url', 'vendor/website' ) as $settings_key ) {
			$value = $this->get_setting( $settings_key, null, $this->settings );
			$url = wp_kses_bad_protocol( $value, array( 'http', 'https' ) );
			if ( $value && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$errors[] = new WP_Error(
					'invalid_configuration',
					sprintf( 'An invalid `%s` setting was passed to the TrustedLogin Client: %s',
						$settings_key,
						print_r( $this->get_setting( $settings_key, null, $this->settings ), true )
					)
				);
			}
		}

		if ( $errors ) {
			$error_text = array();
			foreach ( $errors as $error ) {
				if ( is_wp_error( $error ) ) {
					$error_text[] = $error->get_error_message();
				}
			}

			$exception_text = 'Invalid TrustedLogin Configuration. Learn more at https://www.trustedlogin.com/configuration/';
			$exception_text .= "\n- " . implode( "\n- ", $error_text );

			throw new \Exception( $exception_text, 3 );
		}

		return true;
	}

	/**
	 * Validate and initialize settings array passed to the Client contructor
	 *
	 * @param array|string $config Configuration array or JSON-encoded configuration array
	 *
	 * @return bool|WP_Error[] true: Initialization succeeded; array of WP_Error objects if there are any issues.
	 */
	protected function parse_settings( $config ) {

		if ( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}

		if ( ! is_array( $config ) || empty( $config ) ) {
			return array( new WP_Error( 'empty_configuration', 'Configuration array cannot be empty. See https://www.trustedlogin.com/configuration/ for more information.' ) );
		}

		$defaults = $this->get_default_settings();

		$filtered_config = array_filter( $config, array( $this, 'is_not_null' ) );

		return shortcode_atts( $defaults, $filtered_config );
	}

	/**
	 * Filter out null input values
	 *
	 * @internal Used for parsing settings
	 *
	 * @param mixed $input Input to test against.
	 *
	 * @return bool True: not null. False: null
	 */
	protected function is_not_null( $input ) {
		return ! is_null( $input );
	}

	/**
	 * Gets the default settings for the Client and define dynamic defaults (like paths/css and paths/js)
	 *
	 * @since 0.9.6
	 *
	 * @return array Array of default settings.
	 */
	public function get_default_settings() {

		$default_settings = $this->default_settings;

		$default_settings['paths']['css'] = plugin_dir_url( __FILE__ ) . 'assets/trustedlogin.css';
		$default_settings['paths']['js']  = plugin_dir_url( __FILE__ ) . 'assets/trustedlogin.js';

		return $default_settings;
	}

	/**
	 * @return string Vendor namespace, sanitized with dashes
	 */
	public function ns() {

		$ns = $this->get_setting( 'vendor/namespace' );

		return sanitize_title_with_dashes( $ns );
	}

	/**
	 * Helper Function: Get a specific setting or return a default value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The setting to fetch, nested results are delimited with forward slashes (eg vendor/name => settings['vendor']['name'])
	 * @param mixed $default - if no setting found or settings not init, return this value.
	 * @param array $settings Pass an array to fetch value for instead of using the default settings array
	 *
	 * @return string|array
	 */
	public function get_setting( $key, $default = null, $settings = array() ) {

		if ( empty( $settings ) ) {
			$settings = $this->settings;
		}

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			$this->log( 'Settings have not been configured, returning default value', __METHOD__, 'critical' );
			return $default;
		}

		if ( is_null( $default ) ) {
			$default = $this->get_multi_array_value( $this->get_default_settings(), $key );
		}

		return $this->get_multi_array_value( $settings, $key, $default );
	}

	/**
	 * Gets a specific property value within a multidimensional array.
	 *
	 * @param array  $array   The array to search in.
	 * @param string $name    The name of the property to find.
	 * @param string $default Optional. Value that should be returned if the property is not set or empty. Defaults to null.
	 *
	 * @return null|string|mixed The value
	 */
	private function get_multi_array_value( $array, $name, $default = null ) {

		if ( ! is_array( $array ) && ! ( is_object( $array ) && $array instanceof ArrayAccess ) ) {
			return $default;
		}

		$names = explode( '/', $name );
		$val   = $array;
		foreach ( $names as $current_name ) {
			$val = $this->get_array_value( $val, $current_name, $default );
		}

		return $val;
	}

	/**
	 * Get a specific property of an array without needing to check if that property exists.
	 *
	 * Provide a default value if you want to return a specific value if the property is not set.
	 *
	 * @param array  $array   Array from which the property's value should be retrieved.
	 * @param string $prop    Name of the property to be retrieved.
	 * @param string $default Optional. Value that should be returned if the property is not set or empty. Defaults to null.
	 *
	 * @return null|string|mixed The value
	 */
	private function get_array_value( $array, $prop, $default = null ) {
		if ( ! is_array( $array ) && ! ( is_object( $array ) && $array instanceof \ArrayAccess ) ) {
			return $default;
		}

		if ( isset( $array[ $prop ] ) ) {
			$value = $array[ $prop ];
		} else {
			$value = '';
		}

		$value_is_zero = 0 === $value;

		return ( empty( $value ) && ! $value_is_zero ) && $default !== null ? $default : $value;
	}

	/**
	 * Checks whether SSL requirements are met.
	 *
	 * @since 0,9.2
	 *
	 * @return bool  Whether the vendor-defined SSL requirements are met.
	 */
	public function meets_ssl_requirement(){

		if ( $this->get_setting( 'require_ssl', true ) && ! is_ssl() ){
			return false;
		}

		return true;
	}

}

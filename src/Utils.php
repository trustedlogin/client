<?php
/**
 * Class Utils
 *
 * @package GravityView\TrustedLogin\Client
 *
 * @copyright 2024 Katz Web Services, Inc.
 */
namespace TrustedLogin;

class Utils {

	/**
	 * Wrapper around {@see get_transient()}. Transient is stored as an option {@see self::set_transient()} in order to avoid object caching issues.
	 * Raw SQL query (taken from WP's core) is used in order to avoid object caching issues, such as with the Redis Object Cache plugin.
	 *
	 * @since 1.7.0
	 *
	 * @param string $transient Transient name.
	 *
	 * @return mixed|false Transient value or false if not set.
	 */
	public static function get_transient( $transient ) {
		global $wpdb;

		if ( ! is_string( $transient ) ) {
			return false;
		}

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$pre = apply_filters( "pre_transient_{$transient}", false, $transient );

		if ( false !== $pre ) {
			return $pre;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s LIMIT 1", $transient ) );

		if ( ! is_object( $row ) ) {
			return false;
		}

		$data = maybe_unserialize( $row->option_value );

		$value = self::retrieve_value_and_maybe_expire_transient( $transient, $data );

		return apply_filters( "transient_{$transient}", $value, $transient );
	}

	/**
	 * Wrapper around {@see set_transient()}. Transient is stored as an option in order to avoid object caching issues.
	 * Raw SQL query (taken from WP's core) is used in order to avoid object caching issues, such as with the Redis Object Cache plugin.
	 *
	 * @since 1.7.0
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration (optional) Time until expiration in seconds. Default: 0 (no expiration).
	 *
	 * @return bool True if the value was set, false otherwise.
	 */
	public static function set_transient( $transient, $value, $expiration = 0 ) {
		global $wpdb;

		if ( ! is_string( $transient ) ) {
			return false;
		}

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		wp_protect_special_option( $transient );

		$expiration = (int) $expiration;

		$value = apply_filters( "pre_set_transient_{$transient}", $value, $expiration, $transient );

		$expiration = apply_filters( "expiration_of_transient_{$transient}", $expiration, $value, $transient );

		$data = self::format_transient_data( $value, $expiration );

		// Insert or update the option.
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)", $transient, maybe_serialize( $data ), true ) );

		if ( $result ) {
			do_action( "set_transient_{$transient}", $data['value'], $data['expiration'], $transient );
			do_action( 'setted_transient', $transient, $data['value'], $data['expiration'] );
		}

		return $result;
	}

	/**
	 * Wrapper around {@see get_site_transient()}. Transient is stored as an option {@see self::set_site_transient()} in order to avoid object caching issues.
	 * Raw SQL query (taken from WP's core) is used in order to avoid object caching issues, such as with the Redis Object Cache plugin.
	 *
	 * @since 1.7.0
	 *
	 * @param string $transient Transient name.
	 *
	 * @return mixed|false Transient value or false if not set.
	 */
	public static function get_site_transient( string $transient ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		if ( ! is_multisite() ) {
			return self::get_transient( $transient );
		}

		$pre = apply_filters( "pre_site_transient_{$transient}", false, $transient );

		if ( false !== $pre ) {
			return $pre;
		}

		$network_id = get_current_network_id();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM `$wpdb->sitemeta` WHERE meta_key = %s AND site_id = %d", $transient, $network_id ) );

		if ( ! is_object( $row ) ) {
			return false;
		}

		$data = maybe_unserialize( $row->meta_value );

		$value = self::retrieve_value_and_maybe_expire_transient( $transient, $data );

		return apply_filters( "site_transient_{$transient}", $value, $transient );
	}

	/**
	 * Wrapper around {@see set_site_transient()}. Transient is stored as an option in order to avoid object caching issues.
	 * Raw SQL query (taken from WP's core) is used in order to avoid object caching issues, such as with the Redis Object Cache plugin.
	 *
	 * @since 1.7.0
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration (optional) Time until expiration in seconds. Default: 0 (no expiration).
	 *
	 * @return bool True if the value was set, false otherwise.
	 */
	public static function set_site_transient( string $transient, $value, int $expiration = 0 ): bool {
		global $wpdb;

		wp_protect_special_option( $transient );

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		if ( ! is_multisite() ) {
			return self::set_transient( $transient, $value, $expiration );
		}

		$expiration = (int) $expiration;

		$value = apply_filters( "pre_set_site_transient_{$transient}", $value, $transient );

		$expiration = apply_filters( "expiration_of_site_transient_{$transient}", $expiration, $value, $transient );

		$data = self::format_transient_data( $value, $expiration );

		$network_id = get_current_network_id();

		$transient_exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `$wpdb->sitemeta` WHERE `site_id` = %s AND `meta_key` = %s", $network_id, $transient )
		);

		// There is no unique constraint on the `sitemeta` table (unlike `options` table), so just in case let's delete existing transients.
		if ( $transient_exists > 0 ) {
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM `$wpdb->sitemeta` WHERE `site_id` = %s AND `meta_key` = %s", $network_id, $transient )
			);
		}

		$result = $wpdb->query(
			$wpdb->prepare( "INSERT INTO `$wpdb->sitemeta` (`site_id`, `meta_key`, `meta_value`) VALUES (%s, %s, %s)", $network_id, $transient, maybe_serialize( $data ) )
		);

		if ( $result ) {
			do_action( "set_site_transient_{$transient}", $value, $expiration, $transient );
			do_action( 'setted_site_transient', $transient, $value, $expiration );
		}

		return $result;
	}

	/**
	 * Retrieves a value from the transient data and conditionally deletes transient if expired.
	 *
	 * @since 1.7.0
	 *
	 * @param string $transient      Transient name.
	 * @param mixed  $transient_data Transient data as stored in the database (unserialized).
	 *
	 * @return false|mixed
	 */
	private static function retrieve_value_and_maybe_expire_transient( $transient, $transient_data ) {

		if ( ! is_array( $transient_data ) ) {
			return false;
		}

		// If the transient lacks an expiration time or value, it's not a valid transient.
		if ( ! array_key_exists( 'expiration', $transient_data ) || ! array_key_exists( 'value', $transient_data ) ) {
			return false;
		}

		// If the transient has a non-zero expiration and has expired, delete it and return false.
		if ( 0 !== ( isset( $transient_data['expiration'] ) ? $transient_data['expiration'] : 0 ) && time() > $transient_data['expiration'] ) {
			delete_option( $transient );

			return false;
		}

		return $transient_data['value'];
	}

	/**
	 * Formats transient data for storage in the database.
	 *
	 * @since 1.7.0
	 *
	 * @param mixed $value      Transient value.
	 * @param int   $expiration (optional) Time until expiration in seconds. Default: 0 (no expiration).
	 *
	 * @return array
	 */
	private static function format_transient_data( $value, $expiration = 0 ) {
		return array(
			'expiration' => 0 === $expiration ? $expiration : time() + $expiration,
			'value'      => $value,
	}

	/**
	 * Returns a key used to store transient in the class cache.
	 *
	 * @since 1.7.0
	 *
	 * @param string $transient Transient name.
	 *
	 * @return string
	 */
	private static function get_transient_key_for_cache( string $transient ) {
		return ! is_multisite() ? $transient : get_current_network_id() . '-' . $transient;
		);
	}
}

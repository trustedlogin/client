<?php
/**
 * Class Logging
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

class Logging {

	/**
	 * Path to logging directory (inside the WP Uploads base dir)
	 */
	const DIRECTORY_PATH = 'trustedlogin-logs/';

	/**
	 * @var string Namespace for the vendor
	 */
	private $ns;

	/**
	 * @var bool $logging_enabled
	 */
	private $logging_enabled = false;

	/**
	 * @var Katzgrau\KLogger\Logger|null|false Null: not instantiated; False: failed to instantiate.
	 */
	private $klogger = null;

	/**
	 * Logger constructor.
	 */
	public function __construct( Config $config ) {

		$this->ns = $config->ns();

		$this->logging_enabled = $config->get_setting( 'logging/enabled', false );

		$this->klogger = $this->setup_klogger( $config );
	}

	/**
	 * Attempts to initialize KLogger logging
	 *
	 * @param Config $config
	 *
	 * @return void
	 */
	private function setup_klogger( $config ) {

		$configured_logging_dir = $config->get_setting( 'logging/directory', '' );

		if( $configured_logging_dir ) {
			return $this->check_directory( $configured_logging_dir );
		}

		$logging_directory = $this->maybe_make_logging_directory();

		// Directory cannot be found or created. Cannot log.
		if( ! $logging_directory ) {
			return false;
		}

		// Directory cannot be written to
		if( ! $this->check_directory( $logging_directory ) ) {
			return false;
		}

		try {

			// Filename hash changes every day, make it harder to guess
			$filename_hash_data = $this->ns . home_url( '/' ) . wp_date( 'z' );

			$klogger = new \Katzgrau\KLogger\Logger (
				$logging_directory,
				$config->get_setting( 'logging/threshold', 'notice' ),
				$config->get_setting( 'logging/options', array(
					'extension'      => 'log',
					'prefix'         => sprintf( 'trustedlogin-debug-%s-', wp_hash( $filename_hash_data ) ),
				) )
			);

		} catch ( \RuntimeException $exception ) {

			$this->log( 'Could not initialize KLogger: ' . $exception->getMessage(), __METHOD__, 'error' );

			return false;
		}

		return $klogger;
	}

	/**
	 * Checks whether a path exists and is writable
	 *
	 * @param string $dirpath Path to directory
	 *
	 * @return bool|string If exists and writable, returns original string. Otherwise, returns false.
	 */
	private function check_directory( $dirpath ) {

		$file_exists = file_exists( $dirpath );
		$is_writable = wp_is_writable( $dirpath );

		// If the configured setting path exists and is writeable, use it.
		if( $file_exists && $is_writable ) {
			return $dirpath;
		}

		// Otherwise, try and log default errors
		if( ! $file_exists ) {
			$this->log( 'The defined logging directory does not exist: ' . $dirpath, __METHOD__, 'error' );
		}

		if( ! $is_writable ) {
			$this->log( 'The defined logging directory exists but could not be written to: ' . $dirpath, __METHOD__, 'error' );
		}

		// Then return early; respect the setting
		return false;
	}

	/**
	 * Returns the directory to use for logging if not defined by Config. Creates one if it doesn't exist.
	 *
	 * Note: Created directories are protected by an index.html file to prevent browsing.
	 *
	 * @return false|string Directory path, if exists; False if failure.
	 */
	private function maybe_make_logging_directory() {

		$upload_dir = wp_upload_dir();

		$log_dir = trailingslashit( $upload_dir['basedir'] ) . self::DIRECTORY_PATH;

		// Directory exists; return early
		if( file_exists( $log_dir ) ) {
			return $log_dir;
		}

		// Create the folder using wp_mkdir_p() instead of relying on KLogger
		$folder_created = wp_mkdir_p( $log_dir );

		// Something went wrong maping the directory
		if( ! $folder_created ) {
			$this->log( 'The log directory could not be created: ' . $log_dir, __METHOD__, 'error' );
			return false;
		}

		// Protect directory from being browsed by adding index.html
		$this->prevent_directory_browsing( $logging_directory );

		// Make sure the new log directory can be written to
		return $log_dir;
	}

	/**
	 * Prevent browsing a directory by adding an index.html file to it
	 *
	 * Code inspired by @see wp_privacy_generate_personal_data_export_file()
	 *
	 * @param string $dirpath Path to directory to protect (in this case, logging)
	 *
	 * @return bool True: File exists or was created; False: file could not be created.
	 */
	private function prevent_directory_browsing( $dirpath ) {

		// Protect export folder from browsing.
		$index_pathname = $dirpath . 'index.html';

		if ( file_exists( $index_pathname ) ) {
			return true;
		}

		$file = fopen( $index_pathname, 'w' );

		if ( false === $file ) {
			$this->log( 'Unable to protect directory from browsing.', __METHOD__, 'error' );
			return false;
		}

		fwrite( $file, '<!-- Silence is golden. TrustedLogin is also pretty great. -->' );
		fclose( $file );

		return true;
	}

	/**
	 * Returns whether logging is enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {

		$is_enabled = ! empty( $this->logging_enabled );

		/**
		 * Filter: Whether debug logging is enabled in TrustedLogin Client
		 *
		 * @since 0.4.2
		 *
		 * @param bool $debug_mode Default: false
		 */
		$is_enabled = apply_filters( 'trustedlogin/' . $this->ns . '/logging/enabled', $is_enabled );

		return (bool) $is_enabled;
	}

	/**
	 * @see https://github.com/php-fig/log/blob/master/Psr/Log/LogLevel.php for log levels
	 *
	 * @param string $method Method where the log was called
	 * @param string $level PSR-3 log level
	 *
	 * @param string $text Message to log
	 */
	public function log( $text = '', $method = '', $level = 'debug' ) {

		if ( ! $this->is_enabled() ) {
			return;
		}

		$levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

		if ( ! in_array( $level, $levels ) ) {

			$this->log( sprintf( 'Invalid level passed by %s method: %s', $method, $level ), __METHOD__, 'error' );

			$level = 'debug'; // Continue processing original log
		}

		do_action( 'trustedlogin/' . $this->ns . '/logging/log', $text, $method, $level );
		do_action( 'trustedlogin/' . $this->ns . '/logging/log_' . $level, $text, $method );

		// If logging is in place, don't use the error_log
		if ( has_action( 'trustedlogin/' . $this->ns . '/logging/log' ) || has_action( 'trustedlogin/' . $this->ns . '/logging/log_' . $level ) ) {
			return;
		}

		// The logger class didn't load for some reason
		if ( ! $this->klogger ) {

			// If WP_DEBUG and WP_DEBUG_LOG are enabled, by default, errors will be logged to that log file.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $method . ' (' . $level . '): ' . $text );
			}

			return;
		}

		$this->klogger->{$level}( $method . ': ' . $text );

	}
}

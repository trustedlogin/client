<?php
/**
 * Class Logger
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

class Logging {

	/**
	 * @var string Namespace for the vendor
	 */
	private $ns;

	/**
	 * @var bool $logging_setting
	 */
	private $logging_setting = false;

	/**
	 * @var Katzgrau\KLogger\Logger
	 */
	private $klogger;

	/**
	 * Logger constructor.
	 */
	public function __construct( Config $config ) {

		$this->ns = $config->ns();

		$this->logging_setting = $config->get_setting( 'logging/enabled', false );

		$this->klogger = new \Katzgrau\KLogger\Logger (
			$config->get_setting( 'logging/directory' ),
			$config->get_setting( 'logging/threshold' ),
			$config->get_setting( 'logging/options' )
		);
	}

	/**
	 * Returns whether logging is enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {

		$is_enabled = ! empty( $this->logging_setting );

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

		do_action( 'trustedlogin/' . $this->ns . '/logging/log', $text, $this->is_debug, $method, $level );
		do_action( 'trustedlogin/' . $this->ns . '/logging/log_' . $level, $text, $this->is_debug, $method );

		// If logging is in place, don't use the error_log
		if ( has_action( 'trustedlogin/' . $this->ns . '/logging/log' ) || has_action( 'trustedlogin/' . $this->ns . '/logging/log_' . $level ) ) {
			return;
		}

		// The logger class didn't load for some reason
		if ( ! class_exists( '\Katzgrau\KLogger' ) ) {

			// If WP_DEBUG and WP_DEBUG_LOG are enabled, by default, errors will be logged to that log file.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $method . ' (' . $level . '): ' . $text );
			}

			return;
		}

		$this->klogger->{$level}( $method . ': ' . $text );

	}
}

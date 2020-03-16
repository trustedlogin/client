<?php

namespace TrustedLogin;

use Katzgrau\KLogger;

class Logger {

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var Katzgrau\KLogger
	 */
	private $logger;

	/**
	 * @var string Namespace for the vendor
	 */
	private $ns;

	/**
	 * Logger constructor.
	 */
	public function __construct( Config $config ) {

		$this->config = $config;

		$this->ns = $this->config->ns();
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

		$levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

		if ( ! in_array( $level, $levels ) ) {

			$this->log( sprintf( 'Invalid level passed by %s method: %s', $method, $level ), __METHOD__, 'error' );

			$level = 'debug'; // Continue processing original log
		}

		do_action( 'trustedlogin/' . $this->ns . '/log', $text, $method, $level );
		do_action( 'trustedlogin/' . $this->ns . '/log/' . $level, $text, $method );

		// If logging is in place, don't use the error_log
		if ( has_action( 'trustedlogin/' . $this->ns . '/log' ) || has_action( 'trustedlogin/' . $this->ns . '/log/' . $level ) ) {
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

		$log_directory = $config->get_setting( 'logging/directory' );
		$log_threshold = $config->get_setting( 'logging/threshold' );
		$log_options = $config->get_setting( 'logging/options' );

		$this->logger = new Logger( $log_directory, $log_threshold, $log_options );

		$this->logger->{$level}( $method . ': ' . $text );

	}
}

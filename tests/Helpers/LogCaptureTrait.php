<?php
/**
 * Captures lines emitted by the SDK's `Logging` class so tests can
 * assert on log content + cardinality.
 *
 * Hooks the `trustedlogin/{ns}/logging/log_attempt` filter (the SDK's
 * own logging filter) for the namespace under test. Records everything
 * to an in-memory buffer.
 *
 * Per-test usage:
 *   - call `start_log_capture( $namespace )` in setUp.
 *   - call `stop_log_capture()` in tearDown.
 *   - assert via `assertLogContains( $needle )`, `assertLogCount(...)`,
 *     `assertLogNotContains(...)`.
 *
 * The filter signature mirrors the SDK's actual logging hook, so any
 * change there is caught by this trait failing.
 *
 * @package TrustedLogin\Client
 * @since 1.10.0
 */

namespace TrustedLogin\Tests\Helpers;

trait LogCaptureTrait {

	/** @var array<int, array{message:string,method:string,level:string,namespace:string}> */
	private $captured_logs = array();

	/** @var array<int, array{namespace:string,callback:callable,priority:int}> */
	private $log_capture_filters = array();

	protected function start_log_capture( $namespace ) {

		$this->captured_logs = array();
		$logs                = &$this->captured_logs;

		// Logging::log short-circuits when is_enabled() returns false
		// (the production default for tests). Force-enable so capture
		// works.
		$enable_filter = '__return_true';
		add_filter( 'trustedlogin/' . $namespace . '/logging/enabled', $enable_filter );

		// Hook the action Logging::log fires for every level. Capture
		// 4 args: $message, $method, $level, $data.
		$callback = function ( $message, $method, $level, $data ) use ( $namespace, &$logs ) {

			$logs[] = array(
				'message'   => is_string( $message ) ? $message : (string) wp_json_encode( $message ),
				'method'    => (string) $method,
				'level'     => (string) $level,
				'data'      => $data,
				'namespace' => $namespace,
			);
		};

		add_action( 'trustedlogin/' . $namespace . '/logging/log', $callback, 10, 4 );

		$this->log_capture_filters[] = array(
			'namespace'      => $namespace,
			'log_callback'   => $callback,
			'enable_filter'  => $enable_filter,
		);
	}

	protected function stop_log_capture() {
		foreach ( $this->log_capture_filters as $filter ) {
			remove_action(
				'trustedlogin/' . $filter['namespace'] . '/logging/log',
				$filter['log_callback'],
				10
			);
			remove_filter(
				'trustedlogin/' . $filter['namespace'] . '/logging/enabled',
				$filter['enable_filter']
			);
		}
		$this->log_capture_filters = array();
		$this->captured_logs       = array();
	}

	/** @return array<int, array{message:string,method:string,level:string,namespace:string}> */
	protected function getCapturedLogs() {
		return $this->captured_logs;
	}

	protected function assertLogContains( $needle, $level = null, $message = '' ) {
		foreach ( $this->captured_logs as $entry ) {
			if ( false !== strpos( $entry['message'], $needle ) ) {
				if ( null === $level || $entry['level'] === $level ) {
					$this->assertTrue( true );
					return;
				}
			}
		}
		$this->fail(
			$message ?: sprintf(
				'Expected log line containing "%s" at level "%s"; saw %d entries: %s',
				$needle,
				null === $level ? '*' : $level,
				count( $this->captured_logs ),
				wp_json_encode( array_column( $this->captured_logs, 'message' ) )
			)
		);
	}

	protected function assertLogNotContains( $needle, $message = '' ) {
		foreach ( $this->captured_logs as $entry ) {
			if ( false !== strpos( $entry['message'], $needle ) ) {
				$this->fail(
					$message ?: sprintf(
						'Did NOT expect log line containing "%s"; found at level "%s": %s',
						$needle,
						$entry['level'],
						$entry['message']
					)
				);
			}
		}
		$this->assertTrue( true );
	}

	protected function assertLogCount( $needle, $expected, $message = '' ) {
		$count = 0;
		foreach ( $this->captured_logs as $entry ) {
			if ( false !== strpos( $entry['message'], $needle ) ) {
				$count++;
			}
		}
		$this->assertSame(
			$expected,
			$count,
			$message ?: sprintf( 'Expected %d log lines containing "%s"; saw %d', $expected, $needle, $count )
		);
	}
}

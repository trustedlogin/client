<?php
/**
 * Captures outbound `wp_safe_remote_post` / `wp_remote_post` calls
 * during a test so assertions can check whether (and where) the SDK
 * fired a webhook.
 *
 * Hooks `pre_http_request` at high priority so it runs BEFORE the
 * actual HTTP transport. Returns a synthetic 200 response so the SDK
 * sees success.
 *
 * Per-test usage:
 *   - call `start_webhook_capture()` in setUp.
 *   - call `stop_webhook_capture()` in tearDown.
 *   - read `getCapturedWebhooks()` to inspect what fired.
 *
 * @package TrustedLogin\Client
 * @since 1.10.0
 */

namespace TrustedLogin\Tests\Helpers;

trait WebhookCaptureTrait {

	/** @var array<int, array{url: string, host: string, body: string, args: array}> */
	private $captured_webhooks = array();

	/** @var callable|null */
	private $webhook_capture_filter = null;

	protected function start_webhook_capture() {

		$this->captured_webhooks      = array();
		$webhooks                     = &$this->captured_webhooks;

		$filter = function ( $preempt, $args, $url ) use ( &$webhooks ) {

			// Only capture POSTs that look like webhook deliveries (i.e.
			// not the SaaS sync calls handled by MaliciousSaasResponseTrait).
			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
			if ( 'POST' !== $method ) {
				return $preempt;
			}
			if ( false !== strpos( $url, '/sites' ) ) {
				// SaaS sync — let the malicious-response trait handle it.
				return $preempt;
			}

			$body            = isset( $args['body'] ) ? (string) $args['body'] : '';
			$webhooks[]      = array(
				'url'  => $url,
				'host' => parse_url( $url, PHP_URL_HOST ),
				'body' => $body,
				'args' => $args,
			);

			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		$this->webhook_capture_filter = $filter;
		add_filter( 'pre_http_request', $filter, 5, 3 );
	}

	protected function stop_webhook_capture() {
		if ( null !== $this->webhook_capture_filter ) {
			remove_filter( 'pre_http_request', $this->webhook_capture_filter, 5 );
			$this->webhook_capture_filter = null;
		}
		$this->captured_webhooks = array();
	}

	/** @return array<int, array{url:string,host:?string,body:string,args:array}> */
	protected function getCapturedWebhooks() {
		return $this->captured_webhooks;
	}

	protected function assertWebhookFiredTo( $expected_host, $message = '' ) {
		$hosts = array_column( $this->captured_webhooks, 'host' );
		$this->assertContains(
			$expected_host,
			$hosts,
			$message ?: sprintf( 'Expected a webhook fired to host %s; saw: %s', $expected_host, implode( ', ', array_filter( $hosts ) ) )
		);
	}

	protected function assertWebhookFiredToUrl( $expected_url, $message = '' ) {
		$urls = array_column( $this->captured_webhooks, 'url' );
		$this->assertContains(
			$expected_url,
			$urls,
			$message ?: sprintf( 'Expected a webhook fired to URL %s; saw: %s', $expected_url, implode( ', ', $urls ) )
		);
	}

	protected function assertNoWebhookFired( $message = '' ) {
		$this->assertCount(
			0,
			$this->captured_webhooks,
			$message ?: sprintf( 'Expected zero webhooks; %d fired', count( $this->captured_webhooks ) )
		);
	}

	protected function assertWebhookCount( $expected, $message = '' ) {
		$this->assertCount(
			$expected,
			$this->captured_webhooks,
			$message ?: sprintf( 'Expected %d webhooks; %d fired', $expected, count( $this->captured_webhooks ) )
		);
	}
}

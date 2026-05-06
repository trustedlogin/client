<?php
/**
 * Stubs `pre_http_request` so SaaS calls return a controlled body
 * carrying an attacker-supplied `webhookUrl`.
 *
 * Used by every test in the TL-48 validation / sanitization /
 * disclosure suite to inject malicious or boundary-case values into
 * `SiteAccess::sync_secret()`'s SaaS round-trip without standing up
 * a real fake-saas process.
 *
 * Per-test usage:
 *   - call `stub_saas_webhook_response( 'https://attacker.test/wh' )`
 *     in setUp or in the body before triggering sync_secret.
 *   - call `clear_saas_webhook_response_stub()` in tearDown to remove
 *     the filter (otherwise it leaks across tests at priority 9).
 *
 * @package TrustedLogin\Client
 * @since 1.11.0
 */

namespace TrustedLogin\Tests\Helpers;

trait MaliciousSaasResponseTrait {

	/** @var callable|null Reference to the active filter so we can remove it cleanly. */
	private $malicious_response_filter = null;

	/**
	 * Registers a `pre_http_request` filter that returns a SaaS-style
	 * 200 response with the supplied `webhookUrl` value.
	 *
	 * Pass any value — string, null, integer, array — to test how the
	 * SDK handles it. Pass `__omit__` (sentinel) to omit the field
	 * entirely (simulates an older SaaS without the field).
	 *
	 * @param mixed $webhook_url_value
	 * @param array $extra_response_fields Additional keys to merge into the response body.
	 *
	 * @return void
	 */
	protected function stub_saas_webhook_response( $webhook_url_value, $extra_response_fields = array() ) {

		$response_body = array_merge(
			array( 'success' => true ),
			$extra_response_fields
		);

		if ( '__omit__' !== $webhook_url_value ) {
			$response_body['webhookUrl'] = $webhook_url_value;
		}

		$encoded = wp_json_encode( $response_body );

		$filter = function ( $preempt, $args, $url ) use ( $encoded ) {

			// Vendor public-key fetch — `Envelope::get` calls this before
			// sealing the envelope. Return a deterministic ed25519/sodium
			// pubkey so the encryption step can proceed; the test cares
			// about the SUBSEQUENT `/sites` cache write, not encryption.
			if ( false !== strpos( $url, 'trustedlogin/v1/public_key' ) ) {
				// Fixed test pubkey — output of sodium_crypto_box_keypair
				// once, then sodium_bin2hex on the public_key. Doesn't
				// have to round-trip with a real private key here because
				// the SaaS response below is also stubbed; the envelope
				// is built and shipped but never decrypted in this path.
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array(
						'publicKey' => 'd9c0c1c40e8e0fdda5c4e4c7e9f61e2d8c3b3a7f5e9d8c2b1a0f9e8d7c6b5a4f',
					) ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			// SaaS sync endpoint — what the test is actually exercising.
			if ( false === strpos( $url, '/sites' ) ) {
				return $preempt;
			}
			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => $encoded,
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		$this->malicious_response_filter = $filter;
		add_filter( 'pre_http_request', $filter, 9, 3 );
	}

	/**
	 * Removes the filter registered by {@see stub_saas_webhook_response}.
	 * Idempotent — safe to call even if no filter was registered.
	 */
	protected function clear_saas_webhook_response_stub() {
		if ( null !== $this->malicious_response_filter ) {
			remove_filter( 'pre_http_request', $this->malicious_response_filter, 9 );
			$this->malicious_response_filter = null;
		}
	}
}

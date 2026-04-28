<?php
/**
 * Fake TrustedLogin SaaS for local e2e testing.
 *
 * Implements the minimum subset of `app.trustedlogin.com/api/v1/` that the
 * client library and the connector both call during a normal access-grant
 * flow. State is persisted to a JSON file so client and vendor (which run in
 * separate WP processes) see the same envelopes.
 *
 * Run with:  php -S 0.0.0.0:8003 server.php
 *
 * Endpoints:
 *   POST /api/v1/sites/                              — client posts a sealed envelope
 *   POST /api/v1/accounts/{id}                       — vendor verifies team credentials
 *   POST /api/v1/accounts/{id}/sites/                — vendor searches secret IDs by access key
 *   POST /api/v1/sites/{id}/{secret_id}/get-envelope — vendor fetches the stored envelope
 *   POST /api/v1/accounts/{id}/messages               — client SDK posts an encrypted message
 *   GET  /api/v1/accounts/{id}/messages               — connector polls for messages (?since=)
 *   DELETE /api/v1/accounts/{id}/messages/{msg_id}    — connector deletes a processed message
 *   GET  /__state                                    — debug: dump current state
 *   POST /__reset                                    — debug: clear all envelopes
 *
 * NOT implemented (yet): signature/auth verification. The connector and
 * client both pass auth headers but the fake SaaS accepts everything. That
 * keeps the test deterministic and avoids needing the team's private key on
 * the SaaS side.
 *
 * Optional shared-secret gate: set FAKE_SAAS_TOKEN in the server's env and
 * send a matching `X-Fake-Saas-Token` header from client/vendor to reject
 * stray requests. Empty / unset env = gate disabled.
 */

const STATE_FILE = '/var/lib/fake-saas/state.json';
const SIGNING_KEYPAIR_FILE = '/var/lib/fake-saas/signing_keypair.hex';

if ( ! is_dir( dirname( STATE_FILE ) ) ) {
	mkdir( dirname( STATE_FILE ), 0777, true );
}

/**
 * Load (or generate + persist) the sodium signing keypair used to sign
 * get-envelope responses. Mirrors the real SaaS's `EnvelopeSigner`.
 *
 * Controlled by `FAKE_SAAS_SIGN` env var:
 *   - unset / "1" / "true" → signing ON (default)
 *   - "0" / "false"        → signing OFF (exercise legacy Connector path)
 *
 * Returns hex-encoded keypair (192 chars) or null when signing is disabled.
 */
function signing_keypair_hex(): ?string {
	// Runtime toggle (set by POST /__toggle-signing) trumps env.
	$toggle_file = '/var/lib/fake-saas/sign_toggle';
	if ( file_exists( $toggle_file ) ) {
		$toggle = trim( (string) file_get_contents( $toggle_file ) );
		if ( $toggle === '0' || $toggle === 'false' ) {
			return null;
		}
	} else {
		$flag = getenv( 'FAKE_SAAS_SIGN' );
		if ( $flag === '0' || $flag === 'false' ) {
			return null;
		}
	}

	if ( ! file_exists( SIGNING_KEYPAIR_FILE ) ) {
		$kp = sodium_crypto_sign_keypair();
		file_put_contents( SIGNING_KEYPAIR_FILE, sodium_bin2hex( $kp ) );
		chmod( SIGNING_KEYPAIR_FILE, 0600 );
	}

	$hex = trim( (string) file_get_contents( SIGNING_KEYPAIR_FILE ) );
	return $hex !== '' ? $hex : null;
}

function signing_public_key_hex(): ?string {
	$kp_hex = signing_keypair_hex();
	if ( ! $kp_hex ) {
		return null;
	}
	$kp = sodium_hex2bin( $kp_hex );
	return sodium_bin2hex( sodium_crypto_sign_publickey( $kp ) );
}

/**
 * Canonical JSON form for signing. Must match:
 *   - SaaS  app/Services/EnvelopeSigner::canonical
 *   - Connector php/EnvelopeVerifier::canonical
 * byte-for-byte or verification will fail.
 */
function envelope_canonical( array $envelope ): string {
	unset( $envelope['signature'], $envelope['signaturePublicKey'] );
	ksort( $envelope );
	return json_encode(
		$envelope,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
	);
}

function envelope_sign( array $envelope ): array {
	$kp_hex = signing_keypair_hex();
	if ( ! $kp_hex ) {
		return $envelope;
	}
	$kp        = sodium_hex2bin( $kp_hex );
	$secret    = sodium_crypto_sign_secretkey( $kp );
	$canonical = envelope_canonical( $envelope );
	$sig       = sodium_crypto_sign_detached( $canonical, $secret );
	$envelope['signature'] = sodium_bin2hex( $sig );
	return $envelope;
}

/**
 * Read–modify–write the state file under a single LOCK_EX critical section.
 *
 * Callback receives the current $state and MUST return
 *   [ $new_state_or_null, $payload, $status ]
 * where $new_state_or_null === null means "read-only, don't persist". The
 * lock is released (and file closed) before the HTTP response is emitted, so
 * concurrent workers can't see a half-written file.
 */
function with_state_locked( callable $fn ): void {
	$fh = fopen( STATE_FILE, 'c+' );
	if ( ! $fh || ! flock( $fh, LOCK_EX ) ) {
		reply( 500, array( 'error' => 'state lock failed' ) );
	}
	rewind( $fh );
	$raw     = stream_get_contents( $fh );
	$decoded = json_decode( $raw ?: '', true );
	$state   = is_array( $decoded ) ? $decoded : array();
	$state  += array( 'envelopes' => array(), 'messages' => array() );

	[ $new_state, $payload, $status ] = array_pad( $fn( $state ), 3, null );
	$status = $status ?? 200;

	if ( $new_state !== null ) {
		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, json_encode( $new_state, JSON_PRETTY_PRINT ) );
		fflush( $fh );
	}
	flock( $fh, LOCK_UN );
	fclose( $fh );

	reply( $status, $payload );
}

function reply( int $status, $body ): void {
	http_response_code( $status );
	// 204 No Content and null-body responses must not emit a JSON body.
	if ( $status !== 204 && $body !== null ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $body );
	}
	exit;
}

function read_body(): array {
	$raw     = file_get_contents( 'php://input' );
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : array();
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

error_log( sprintf( '[fake-saas] %s %s', $method, $path ) );

// Optional shared-secret auth. When FAKE_SAAS_TOKEN is set, every non-debug
// request must carry a matching X-Fake-Saas-Token header. Empty / unset env
// disables the check so existing tests keep working until both sides wire it.
$expected_token = getenv( 'FAKE_SAAS_TOKEN' ) ?: '';
if ( $expected_token !== '' && strpos( $path, '/__' ) !== 0 ) {
	$got_token = $_SERVER['HTTP_X_FAKE_SAAS_TOKEN'] ?? '';
	if ( ! hash_equals( $expected_token, $got_token ) ) {
		reply( 401, array( 'error' => 'bad token' ) );
	}
}

// ---------------------------------------------------------------------------
//  Login-attempts (Plan B) — constants shared by the actual POST handler
//  AND the debug routes below.
// ---------------------------------------------------------------------------
const LOGIN_ATTEMPT_MODE_STATE_KEY         = 'login_attempts_mode';
const LOGIN_ATTEMPT_REQUEST_LOG_KEY        = 'login_attempts';
const LOGIN_ATTEMPT_REQUEST_LOG_MAX        = 100;
const LOGIN_ATTEMPT_FIXED_LPAT_UUID        = 'a1a5bea0-372a-47ca-8090-2f36ad870abc';

// Allowed `mode` values (kept in sync with the test spec — JSON-stringified
// over the wire). When adding a new mode, mirror it in the spec's
// FakeSaasMode constant.
const LOGIN_ATTEMPT_MODE_OK                = 'ok';
const LOGIN_ATTEMPT_MODE_RATE_LIMITED      = 'rate_limited';
const LOGIN_ATTEMPT_MODE_SERVER_ERROR      = 'server_error';

// Status codes returned per mode. HTTP semantics are well-known but
// keeping them named here makes the test failure modes self-documenting.
const LOGIN_ATTEMPT_HTTP_CREATED           = 201;
const LOGIN_ATTEMPT_HTTP_RATE_LIMITED      = 429;
const LOGIN_ATTEMPT_HTTP_SERVER_ERROR      = 500;

// Debug routes.
if ( $method === 'GET' && $path === '/__state' ) {
	with_state_locked( fn( $state ) => array( null, $state, 200 ) );
}

// Plan B: flip the next /sites/{secret_id}/login-attempts response.
// Body: {"mode": "ok" | "rate_limited" | "server_error"}.
if ( $method === 'POST' && $path === '/__login-attempts-mode' ) {
	$body = read_body();
	with_state_locked( function ( $state ) use ( $body ) {
		$requested = isset( $body['mode'] ) ? (string) $body['mode'] : LOGIN_ATTEMPT_MODE_OK;
		$allowed   = array(
			LOGIN_ATTEMPT_MODE_OK,
			LOGIN_ATTEMPT_MODE_RATE_LIMITED,
			LOGIN_ATTEMPT_MODE_SERVER_ERROR,
		);
		$state[ LOGIN_ATTEMPT_MODE_STATE_KEY ] = in_array( $requested, $allowed, true )
			? $requested
			: LOGIN_ATTEMPT_MODE_OK;

		return array( $state, array( 'mode' => $state[ LOGIN_ATTEMPT_MODE_STATE_KEY ] ), 200 );
	} );
}

// Plan B: dump every login-attempt POST the fake-saas has seen since reset.
if ( $method === 'GET' && $path === '/__login-attempts' ) {
	with_state_locked( function ( $state ) {
		return array( null, array( 'requests' => $state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ] ?? array() ), 200 );
	} );
}

if ( $method === 'POST' && $path === '/__reset' ) {
	with_state_locked( fn( $state ) => array(
		array( 'envelopes' => array(), 'messages' => array() ),
		array( 'reset' => true ),
		200,
	) );
}

// Envelope-signing public key. Mirrors SaaS
//   GET /api/v1/envelope-signing-pubkey
// and also exposed at the SaaS-independent path
//   GET /signing-pubkey
// so e2e bootstrap can fetch it without route-matching the /api/v1 prefix.
if ( $method === 'GET' && ( $path === '/signing-pubkey' || $path === '/api/v1/envelope-signing-pubkey' ) ) {
	$pub = signing_public_key_hex();
	if ( $pub === null ) {
		reply( 404, array( 'error' => 'Envelope signing disabled.' ) );
	}
	reply( 200, array( 'publicKey' => $pub ) );
}

// Test-only toggle: flip signing ON/OFF without restarting the container.
// Body: { "enabled": true|false }.
// Writes the state into a marker file so future requests pick it up; also
// mutates getenv() for this process.
if ( $method === 'POST' && $path === '/__toggle-signing' ) {
	$body = read_body();
	$enabled = ! empty( $body['enabled'] );
	// Persist to an env-override file that signing_keypair_hex can read.
	file_put_contents( '/var/lib/fake-saas/sign_toggle', $enabled ? '1' : '0' );
	reply( 200, array( 'signing_enabled' => $enabled ) );
}

// Strip the /api/v1/ prefix.
if ( strpos( $path, '/api/v1/' ) !== 0 ) {
	reply( 404, array( 'error' => 'Path must start with /api/v1/', 'path' => $path ) );
}
$endpoint = substr( $path, strlen( '/api/v1/' ) );

// Expired envelopes are filtered at read-time inside each handler's locked
// critical section so lookups can't return something the real SaaS would have
// purged. The pruned shape is NOT persisted — an expired envelope stays
// visible in /__state for test debugging until a mutating request evicts it.
//
// `expires_at` matches the client's `expiresAt` (absolute GMT epoch).
// 0 means no expiration (client decay=0 case).
function is_expired( array $entry ): bool {
	$exp = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
	return $exp > 0 && $exp <= time();
}

// 1. POST sites/ — client posts a sealed envelope.
//
// The client envelope (see ReplaceMe\TrustedLogin\Envelope::get) contains:
//   - identifier:       encrypted site identifier hash
//   - siteUrl:          plaintext client site URL
//   - publicKey:        the team api_key (NOT a sodium key — confusingly named upstream)
//   - clientPublicKey:  the actual sodium public key for this envelope
//   - nonce:            hex sodium nonce
//   - accessKey:        the customer-facing access key
//   - secretId, expiresAt, version, wpUserId, metaData
//
// We store it indexed by accessKey, generating a fresh secret_id per envelope.
// When the vendor later requests the envelope, we transform it into the shape
// the connector expects: { identifier, siteUrl, publicKey, nonce, metaData }
// where `publicKey` is the client's actual sodium key (so the connector can
// decrypt). See tests/data/get-envelope.json in the connector for the
// reference shape.
if ( $method === 'POST' && ( $endpoint === 'sites/' || $endpoint === 'sites' ) ) {
	$body       = read_body();
	$access_key = $body['accessKey'] ?? null;
	if ( ! $access_key ) {
		reply( 400, array( 'error' => 'Missing accessKey in body' ) );
	}
	// Honour the client-supplied `expiresAt` (ABSOLUTE GMT timestamp).
	//
	// The trustedlogin/client library's Envelope::get() populates
	// `expiresAt` from Config::get_expiration_timestamp() — an integer
	// epoch. When the config's `decay` is 0 the Client returns `false`
	// to indicate no expiration. We mirror that verbatim: null /
	// missing / falsy expiresAt = envelope never expires here. Real
	// client defaults decay to WEEK_IN_SECONDS (with a validated 1–30
	// day range), so production envelopes always carry a finite value.
	$expires_at = isset( $body['expiresAt'] ) && $body['expiresAt']
		? (int) $body['expiresAt']
		: 0;

	// Key the stored envelope on the client-submitted `secretId`.
	//
	// The real TrustedLogin SaaS MUST record whatever secret_id the client
	// sent, because the same value gets recomputed client-side on every
	// later verify-identifier call:
	//   - Client::grant_access() generates
	//     secret_id = endpoint->generate_secret_id(site_identifier_hash, endpoint_hash)
	//   - Client posts envelope { ..., secretId: $secret_id, ... } to SaaS
	//   - Later SecurityChecks::check_approved_identifier() computes the
	//     SAME secret_id via SupportUser::get_secret_id(identifier) and
	//     POSTs sites/{secret_id}/verify-identifier. If the SaaS had used
	//     a different key, verify would always 404.
	//
	// Fallback to a random ID is kept so the fake-saas stays robust if a
	// test ever posts a malformed envelope without secretId.
	$secret_id = ! empty( $body['secretId'] ) ? (string) $body['secretId'] : bin2hex( random_bytes( 16 ) );

	with_state_locked( function ( $state ) use ( $access_key, $body, $secret_id, $expires_at ) {
		$state['envelopes'][ $access_key ] = array(
			'secret_id'          => $secret_id,
			'envelope_for_vendor' => array(
				'identifier' => $body['identifier'] ?? '',
				'siteUrl'    => $body['siteUrl'] ?? '',
				// The connector reads `publicKey` and uses it as the sodium key for
				// decryption. The client's `clientPublicKey` is the actual sodium key.
				'publicKey'  => $body['clientPublicKey'] ?? '',
				'nonce'      => $body['nonce'] ?? '',
				'metaData'   => $body['metaData'] ?? array(),
			),
			'created_at'         => time(),
			// 0 means no expiration (matches the client's decay=0 path where
			// Config::get_expiration_timestamp() returns false).
			'expires_at'         => $expires_at,
		);
		return array( $state, array( 'success' => true, 'siteId' => $secret_id ), 201 );
	} );
}

// 2. POST accounts/{id} — vendor verify (smoke check that credentials work).
if ( $method === 'POST' && preg_match( '#^accounts/(\d+)$#', $endpoint, $m ) ) {
	reply( 200, array(
		'account_id' => (int) $m[1],
		'status'     => 'active',
	) );
}

// 3. POST accounts/{id}/sites/ — vendor searches for envelopes by access key.
//
// Body: { searchKeys: [<access_key>, ...] }
// Response: { <access_key>: [<secret_id>, ...], ... }
//
// The connector then flattens this into a single list of secret IDs.
if ( $method === 'POST' && preg_match( '#^accounts/(\d+)/sites/?$#', $endpoint, $m ) ) {
	$body = read_body();
	$keys = $body['searchKeys'] ?? array();

	with_state_locked( function ( $state ) use ( $keys ) {
		$found = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $state['envelopes'][ $key ] ) ) {
				continue;
			}
			if ( is_expired( $state['envelopes'][ $key ] ) ) {
				continue;
			}
			$found[ $key ] = array( $state['envelopes'][ $key ]['secret_id'] );
		}
		if ( empty( $found ) ) {
			// 204 No Content — the connector treats this as "no sites found".
			return array( null, null, 204 );
		}
		return array( null, $found, 200 );
	} );
}

// 4. POST sites/{id}/{secret_id}/get-envelope — vendor fetches the envelope.
//
// When signing is enabled (FAKE_SAAS_SIGN != '0'), the envelope is signed with
// the persisted sodium keypair before return. The Connector's EnvelopeVerifier
// checks this signature to guard against SaaS→Connector tampering.
if ( $method === 'POST' && preg_match( '#^sites/(\d+)/([a-f0-9]+)/get-envelope$#', $endpoint, $m ) ) {
	$secret_id = $m[2];
	with_state_locked( function ( $state ) use ( $secret_id ) {
		foreach ( $state['envelopes'] as $entry ) {
			if ( $entry['secret_id'] !== $secret_id ) {
				continue;
			}
			if ( is_expired( $entry ) ) {
				return array( null, array( 'error' => 'Envelope expired.' ), 410 );
			}
			$envelope = envelope_sign( $entry['envelope_for_vendor'] );
			return array( null, $envelope, 200 );
		}
		return array( null, array( 'error' => 'Envelope not found for secret_id ' . $secret_id ), 404 );
	} );
}

// 4b. POST sites/{secret_id}/verify-identifier — client-side SecurityChecks
//     pings this to confirm the identifier hasn't been flagged by the SaaS
//     as suspicious. Expired envelopes are treated as unknown so replays
//     past the TTL fail exactly like they would against the real SaaS.
// 4b. POST sites/{secret_id}/login-attempts — client SDK reports a failed
//     support login (Plan B). Test-controllable response: by default returns
//     201 with a mock lpat_<UUID>; the /__login-attempts-mode debug
//     endpoint flips to rate_limited (429) or server_error (500). Debug
//     endpoints are registered above the /api/v1/ prefix gate.
if ( $method === 'POST' && preg_match( '#^sites/([a-f0-9]+)/login-attempts$#', $endpoint, $m ) ) {
	$secret_id = $m[1];
	$body      = read_body();

	with_state_locked( function ( $state ) use ( $secret_id, $body ) {
		// Append to a tiny in-memory request log so tests can assert what
		// was sent (most importantly: that secret_id is in the URL not the
		// body, and that detailed_reason was forwarded for storage).
		$state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ]   = $state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ] ?? array();
		$state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ][] = array(
			'secret_id' => $secret_id,
			'body'      => $body,
			'time'      => time(),
		);
		// Cap log size so a flooding test doesn't blow up state.json.
		if ( count( $state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ] ) > LOGIN_ATTEMPT_REQUEST_LOG_MAX ) {
			$state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ] = array_slice(
				$state[ LOGIN_ATTEMPT_REQUEST_LOG_KEY ],
				-LOGIN_ATTEMPT_REQUEST_LOG_MAX
			);
		}

		$mode = $state[ LOGIN_ATTEMPT_MODE_STATE_KEY ] ?? LOGIN_ATTEMPT_MODE_OK;
		switch ( $mode ) {
			case LOGIN_ATTEMPT_MODE_RATE_LIMITED:
				return array( $state, array( 'error' => 'rate limit hit' ), LOGIN_ATTEMPT_HTTP_RATE_LIMITED );
			case LOGIN_ATTEMPT_MODE_SERVER_ERROR:
				return array( $state, array( 'error' => 'upstream broke' ), LOGIN_ATTEMPT_HTTP_SERVER_ERROR );
			case LOGIN_ATTEMPT_MODE_OK:
			default:
				return array(
					$state,
					array( 'id' => 'lpat_' . LOGIN_ATTEMPT_FIXED_LPAT_UUID ),
					LOGIN_ATTEMPT_HTTP_CREATED,
				);
		}
	} );
}

if ( $method === 'POST' && preg_match( '#^sites/([a-f0-9]+)/verify-identifier$#', $endpoint, $m ) ) {
	$secret_id = $m[1];
	with_state_locked( function ( $state ) use ( $secret_id ) {
		foreach ( $state['envelopes'] as $entry ) {
			if ( $entry['secret_id'] !== $secret_id ) {
				continue;
			}
			if ( is_expired( $entry ) ) {
				return array( null, array( 'error' => 'Envelope expired.' ), 410 );
			}
			return array( null, array( 'verified' => true ), 200 );
		}
		return array( null, array( 'error' => 'Unknown secret_id for verify-identifier.' ), 404 );
	} );
}

// 5. POST accounts/{id}/messages — client SDK posts an encrypted message.
if ( $method === 'POST' && preg_match( '#^accounts/(\d+)/messages$#', $endpoint, $m ) ) {
	$body       = read_body();
	$account_id = $m[1];

	with_state_locked( function ( $state ) use ( $account_id, $body ) {
		$max_id     = array_reduce( $state['messages'], fn( $max, $msg ) => max( $max, $msg['id'] ), 0 );
		$msg_id     = $max_id + 1;
		$created_at = time();
		$state['messages'][] = array(
			'id'                     => $msg_id,
			'account_id'             => $account_id,
			'envelope'               => $body['envelope'] ?? '',
			'nonce'                  => $body['nonce'] ?? '',
			'sender_public_key'      => $body['client_public_key'] ?? '',
			'key_fingerprint'        => $body['key_fingerprint'] ?? '',
			'action'                 => $body['action'] ?? '',
			'ref'                    => $body['ref'] ?? null,
			'access_key_fingerprint' => $body['access_key_fingerprint'] ?? '',
			'created_at'             => $created_at,
		);
		return array( $state, array( 'id' => $msg_id, 'created_at' => $created_at ), 201 );
	} );
}

// 6. GET accounts/{id}/messages — connector polls for messages (supports ?since= query param).
if ( $method === 'GET' && preg_match( '#^accounts/(\d+)/messages$#', $endpoint, $m ) ) {
	$account_id = $m[1];
	$since      = (int) ( $_GET['since'] ?? 0 );
	with_state_locked( function ( $state ) use ( $account_id, $since ) {
		$result = array();
		foreach ( ( $state['messages'] ?? array() ) as $msg ) {
			if ( $msg['account_id'] === $account_id && $msg['created_at'] >= $since ) {
				$result[] = $msg;
			}
		}
		return array( null, $result, 200 );
	} );
}

// 7. DELETE accounts/{id}/messages/{msg_id} — connector deletes a processed message.
if ( $method === 'DELETE' && preg_match( '#^accounts/(\d+)/messages/(\d+)$#', $endpoint, $m ) ) {
	$msg_id = (int) $m[2];
	with_state_locked( function ( $state ) use ( $msg_id ) {
		$state['messages'] = array_values( array_filter( $state['messages'] ?? array(), function ( $msg ) use ( $msg_id ) {
			return $msg['id'] !== $msg_id;
		} ) );
		return array( $state, array( 'deleted' => true ), 200 );
	} );
}

reply( 404, array(
	'error'    => 'Unknown route',
	'method'   => $method,
	'endpoint' => $endpoint,
) );

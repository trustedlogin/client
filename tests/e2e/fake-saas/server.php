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
 */

const STATE_FILE = '/var/lib/fake-saas/state.json';

if ( ! is_dir( dirname( STATE_FILE ) ) ) {
	mkdir( dirname( STATE_FILE ), 0777, true );
}

function load_state(): array {
	if ( ! file_exists( STATE_FILE ) ) {
		return array( 'envelopes' => array(), 'messages' => array() );
	}
	$data = json_decode( file_get_contents( STATE_FILE ), true );
	if ( ! is_array( $data ) ) {
		return array( 'envelopes' => array(), 'messages' => array() );
	}
	if ( ! isset( $data['messages'] ) ) {
		$data['messages'] = array();
	}
	return $data;
}

function save_state( array $state ): void {
	file_put_contents( STATE_FILE, json_encode( $state, JSON_PRETTY_PRINT ), LOCK_EX );
}

function reply( int $status, $body ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $body );
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

// Debug routes.
if ( $method === 'GET' && $path === '/__state' ) {
	reply( 200, load_state() );
}
if ( $method === 'POST' && $path === '/__reset' ) {
	save_state( array( 'envelopes' => array(), 'messages' => array() ) );
	reply( 200, array( 'reset' => true ) );
}

// Strip the /api/v1/ prefix.
if ( strpos( $path, '/api/v1/' ) !== 0 ) {
	reply( 404, array( 'error' => 'Path must start with /api/v1/', 'path' => $path ) );
}
$endpoint = substr( $path, strlen( '/api/v1/' ) );

$state = load_state();

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
	$secret_id                          = ! empty( $body['secretId'] ) ? (string) $body['secretId'] : bin2hex( random_bytes( 16 ) );
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
	);
	save_state( $state );
	reply( 201, array( 'success' => true, 'siteId' => $secret_id ) );
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
	$body  = read_body();
	$keys  = $body['searchKeys'] ?? array();
	$found = array();
	foreach ( $keys as $key ) {
		if ( isset( $state['envelopes'][ $key ] ) ) {
			$found[ $key ] = array( $state['envelopes'][ $key ]['secret_id'] );
		}
	}
	if ( empty( $found ) ) {
		// 204 No Content — the connector treats this as "no sites found".
		http_response_code( 204 );
		exit;
	}
	reply( 200, $found );
}

// 4. POST sites/{id}/{secret_id}/get-envelope — vendor fetches the envelope.
if ( $method === 'POST' && preg_match( '#^sites/(\d+)/([a-f0-9]+)/get-envelope$#', $endpoint, $m ) ) {
	$secret_id = $m[2];
	foreach ( $state['envelopes'] as $entry ) {
		if ( $entry['secret_id'] === $secret_id ) {
			reply( 200, $entry['envelope_for_vendor'] );
		}
	}
	reply( 404, array( 'error' => 'Envelope not found for secret_id ' . $secret_id ) );
}

// 4b. POST sites/{secret_id}/verify-identifier — client-side SecurityChecks
//     pings this to confirm the identifier hasn't been flagged by the SaaS
//     as suspicious. We accept any known secret_id. Unknown → 404 so the
//     client flags it as a failed verification.
if ( $method === 'POST' && preg_match( '#^sites/([a-f0-9]+)/verify-identifier$#', $endpoint, $m ) ) {
	$secret_id = $m[1];
	foreach ( $state['envelopes'] as $entry ) {
		if ( $entry['secret_id'] === $secret_id ) {
			reply( 200, array( 'verified' => true ) );
		}
	}
	reply( 404, array( 'error' => 'Unknown secret_id for verify-identifier.' ) );
}

// 5. POST accounts/{id}/messages — client SDK posts an encrypted message.
if ( $method === 'POST' && preg_match( '#^accounts/(\d+)/messages$#', $endpoint, $m ) ) {
	$body       = read_body();
	$account_id = $m[1];
	$max_id     = array_reduce( $state['messages'], fn( $max, $m ) => max( $max, $m['id'] ), 0 );
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
	save_state( $state );
	reply( 201, array( 'id' => $msg_id, 'created_at' => $created_at ) );
}

// 6. GET accounts/{id}/messages — connector polls for messages (supports ?since= query param).
if ( $method === 'GET' && preg_match( '#^accounts/(\d+)/messages$#', $endpoint, $m ) ) {
	$account_id = $m[1];
	$since      = (int) ( $_GET['since'] ?? 0 );
	$result     = array();
	foreach ( ( $state['messages'] ?? array() ) as $msg ) {
		if ( $msg['account_id'] === $account_id && $msg['created_at'] >= $since ) {
			$result[] = $msg;
		}
	}
	reply( 200, $result );
}

// 7. DELETE accounts/{id}/messages/{msg_id} — connector deletes a processed message.
if ( $method === 'DELETE' && preg_match( '#^accounts/(\d+)/messages/(\d+)$#', $endpoint, $m ) ) {
	$msg_id            = (int) $m[2];
	$state['messages'] = array_values( array_filter( $state['messages'] ?? array(), function ( $msg ) use ( $msg_id ) {
		return $msg['id'] !== $msg_id;
	} ) );
	save_state( $state );
	reply( 200, array( 'deleted' => true ) );
}

reply( 404, array(
	'error'    => 'Unknown route',
	'method'   => $method,
	'endpoint' => $endpoint,
) );

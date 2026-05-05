/**
 * E2E: cron-fired support-user expiration.
 *
 * The SDK's "temporary access" promise hinges on a wp_schedule_single_event
 * registered at grant time firing at expiration time and calling
 * Cron::revoke → Client::revoke_access → SupportUser::delete. Every link
 * in that chain is unit-tested in PHPUnit; this spec validates the chain
 * end-to-end against real wp-cron.
 *
 * Why this lives in e2e (not PHPUnit):
 *
 *   - `wp cron event run --due-now` is the same code path wp-cron's
 *     scheduler hits in production (loopback request → spawn handler).
 *     PHPUnit can call the action handler directly, but that bypasses
 *     wp-cron's deduplication, locking, and process-spawn semantics.
 *   - A registration-only assertion (the action is bound to the right
 *     hook name) is in tests/test-cron.php — that doesn't need a browser
 *     stack. Only the runtime fire-and-cleanup assertion needs to land
 *     here.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { execSync } from 'child_process';
import * as path from 'path';

const E2E_DIR = path.resolve( __dirname, '..' );
const NS      = 'pro-block-builder';
const HOOK    = `trustedlogin/${ NS }/access/revoke`;

function wpCommand( args: string ): string {
	return execSync(
		`docker compose run --rm -T wp-cli-client wp ${ args }`,
		{ cwd: E2E_DIR, encoding: 'utf8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
	).toString().trim();
}

test.describe.configure( { mode: 'serial' } );

function resetState(): void {
	wpCli(
		'wp-cli-client',
		[
			'$cron = (array) get_option("cron", array());',
			'foreach ($cron as $ts => $hooks) {',
			'  if (!is_array($hooks)) { continue; }',
			'  foreach (array_keys($hooks) as $hook) {',
			'    if (0 === strpos((string) $hook, "trustedlogin/")) { unset($cron[$ts][$hook]); }',
			'  }',
			'  if (empty($cron[$ts])) { unset($cron[$ts]); }',
			'}',
			'update_option("cron", $cron);',
			// Drop any lingering test users with cron-e2e@ email.
			// We hit wp_users / wp_usermeta directly instead of
			// wp_delete_user because that helper is admin-only, may
			// no-op silently inside wp eval (wp-cli doesn't load
			// wp-admin/includes/user.php), and the leftover user from
			// a previous run trips email_exists() in
			// SupportUser::create() with USER_ERR:user_exists before
			// the cron path under test even runs.
			'global $wpdb;',
			'$ids = $wpdb->get_col("SELECT ID FROM {$wpdb->users} WHERE user_login != \'admin\' AND user_email LIKE \'%cron-e2e@%\'");',
			'foreach ($ids as $uid) {',
			'  $wpdb->delete( $wpdb->usermeta, array( "user_id" => (int) $uid ) );',
			'  $wpdb->delete( $wpdb->users,    array( "ID"      => (int) $uid ) );',
			'  clean_user_cache( (int) $uid );',
			'}',
			'echo "ok";',
		].join( ' ' ),
		'reset cron-expiration state',
	);
}

test.beforeEach( () => {
	resetState();
} );

test.afterAll( () => {
	resetState();
} );

test( 'expired event fires via wp-cron → support user is deleted, schedule is cleared', async () => {
	// Mint role + user, then route through SupportUser::setup so cron
	// schedule + user-meta line up the way grant_access wires them.
	const mintOut = wpCli(
		'wp-cli-client',
		[
			'$cfg  = new \\TrustedLogin\\Config(array(',
			'  "auth"   => array("api_key" => "0123456789abcdef"),',
			`  "vendor" => array("namespace" => ${ JSON.stringify( NS ) }, "title" => "Pro Block Builder", "email" => "cron-e2e@example.test", "website" => "http://localhost:8002", "support_url" => "http://localhost:8002/support"),`,
			'));',
			'$log = new \\TrustedLogin\\Logging($cfg);',
			'(new \\TrustedLogin\\SupportRole($cfg, $log))->create();',
			'$su  = new \\TrustedLogin\\SupportUser($cfg, $log);',
			'$uid = $su->create();',
			'if (is_wp_error($uid)) { echo "USER_ERR:" . $uid->get_error_code(); exit; }',
			'$raw_identifier = \\TrustedLogin\\Encryption::get_random_hash($log);',
			'$cron = new \\TrustedLogin\\Cron($cfg, $log);',
			// Past timestamp so wp cron event run --due-now picks it up immediately.
			'$result = $su->setup($uid, $raw_identifier, time() - 60, $cron);',
			'if (is_wp_error($result)) { echo "SETUP_ERR:" . $result->get_error_code(); exit; }',
			'$hashed = \\TrustedLogin\\Encryption::hash($raw_identifier);',
			`$scheduled = wp_next_scheduled(${ JSON.stringify( HOOK ) }, array($hashed));`,
			'echo "uid=" . $uid . " | hashed=" . $hashed . " | scheduled=" . ($scheduled ? "yes" : "no");',
		].join( ' ' ),
		'mint + setup with expired schedule',
	);

	const m = mintOut.match( /uid=(\d+) \| hashed=([a-f0-9]+) \| scheduled=yes/ );
	expect( m, `mint output should match expected pattern, got: ${ mintOut }` ).not.toBeNull();
	const uid    = parseInt( m![ 1 ], 10 );
	const hashed = m![ 2 ];

	// Fire wp-cron. This is the same dispatch that wp-cron's loopback
	// hits in production: enumerate due events, invoke each handler.
	wpCommand( 'cron event run --due-now' );

	const after = wpCli(
		'wp-cli-client',
		`echo (get_userdata(${ uid }) ? "still_exists" : "gone");`,
		'check user after cron fire',
	);
	expect( after, 'cron firing on the expired event must delete the support user' )
		.toBe( 'gone' );

	const stillScheduled = wpCli(
		'wp-cli-client',
		`echo (wp_next_scheduled(${ JSON.stringify( HOOK ) }, array(${ JSON.stringify( hashed ) })) ? "yes" : "no");`,
		'check schedule after fire',
	);
	expect( stillScheduled, 'wp_schedule_single_event must self-unschedule after firing' )
		.toBe( 'no' );
} );

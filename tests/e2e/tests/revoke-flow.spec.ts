/**
 * E2E: SaaS-revoke retry cron, end-to-end.
 *
 * The Cron::queue_saas_revoke_retry → wp_schedule_single_event →
 * Cron::retry_saas_revoke chain is unit-tested in tests/test-cron.php
 * (queue write, handler dispatch, MAX_SAAS_REVOKE_RETRIES backoff).
 * This spec validates that the retry hook is wired such that wp-cron
 * itself actually finds and fires it in production — i.e. that
 * `init()` registers the right hook name AND that wp-cron's loopback
 * dispatcher can locate it.
 *
 * Failure mode this catches: a refactor that changes the hook name in
 * one place but not the other (e.g. updates the queue function but
 * forgets the init() handler registration). PHPUnit's direct
 * Cron::retry_saas_revoke() call would still pass; production would
 * silently leak orphan SaaS sites because the retry never runs.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { execSync } from 'child_process';
import * as path from 'path';

const NS      = 'pro-block-builder';
const E2E_DIR = path.resolve( __dirname, '..' );

function wpCommand( args: string ): string {
	return execSync(
		`docker compose run --rm -T wp-cli-client wp ${ args }`,
		{ cwd: E2E_DIR, encoding: 'utf8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
	).toString().trim();
}

function resetState(): void {
	wpCli(
		'wp-cli-client',
		[
			`delete_option("tl_${ NS }_pending_saas_revoke");`,
			'$cron = (array) get_option("cron", array());',
			'foreach ($cron as $ts => $hooks) {',
			'  if (!is_array($hooks)) { continue; }',
			'  foreach (array_keys($hooks) as $hook) {',
			'    if (0 === strpos((string) $hook, "trustedlogin/")) { unset($cron[$ts][$hook]); }',
			'  }',
			'  if (empty($cron[$ts])) { unset($cron[$ts]); }',
			'}',
			'update_option("cron", $cron);',
			'echo "ok";',
		].join( ' ' ),
		'reset retry queue + cron events',
	);
}

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
	resetState();
} );

test.afterAll( () => {
	resetState();
} );

test( 'retry cron event drains the pending-SaaS-revoke queue when fired by wp-cron', async () => {
	// Seed the queue, then pull the scheduled event into the past so
	// `wp cron event run --due-now` fires it on the next invocation.
	// This exercises the full cron path: option write →
	// wp_schedule_single_event → Cron::init action wiring → handler
	// runs → SaaS DELETE → queue cleared.
	const seedOut = wpCli(
		'wp-cli-client',
		[
			'$cfg  = new \\TrustedLogin\\Config(array(',
			'  "auth"   => array("api_key" => "0123456789abcdef"),',
			`  "vendor" => array("namespace" => ${ JSON.stringify( NS ) }, "title" => "Pro Block Builder", "email" => "retry-cron@example.test", "website" => "http://localhost:8002", "support_url" => "http://localhost:8002/support"),`,
			'));',
			'$log  = new \\TrustedLogin\\Logging($cfg);',
			'$cron = new \\TrustedLogin\\Cron($cfg, $log);',
			'$cron->queue_saas_revoke_retry("deadbeefdeadbeefdeadbeefdeadbeef");',
			`$hook = "trustedlogin/${ NS }/site/retry_revoke";`,
			'$ts   = wp_next_scheduled( $hook );',
			'if ( $ts ) {',
			'  wp_unschedule_event( $ts, $hook );',
			'  wp_schedule_single_event( time() - 60, $hook );',
			'}',
			`echo "queued=" . count((array) get_option("tl_${ NS }_pending_saas_revoke", array())) . " | now_due=" . (wp_next_scheduled($hook) <= time() ? "yes" : "no");`,
		].join( ' ' ),
		'seed retry queue + push event due',
	);

	expect( seedOut, 'queue should hold one entry and the cron event should be due' )
		.toMatch( /queued=1 \| now_due=yes/ );

	wpCommand( 'cron event run --due-now' );

	const after = wpCli(
		'wp-cli-client',
		`$opt = get_option("tl_${ NS }_pending_saas_revoke", "deleted"); echo is_array($opt) ? ("size=" . count($opt)) : "deleted";`,
		'check queue after cron fire',
	);

	expect( after, 'after the retry cron fired against a healthy SaaS, the queue must be empty' )
		.toMatch( /^(deleted|size=0)$/ );
} );

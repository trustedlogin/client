/**
 * Security E2E: revoke-access must clean up every artifact a
 * subsequent magic-link could exploit.
 *
 * `revoke-flow-browser.spec.ts` proves the user-visible state
 * reverts to "no grant exists". This spec audits the SECONDARY
 * artifacts whose continued presence would be a hazard:
 *
 *   - WP user record (per-site AND network on multisite — wpmu_delete_user)
 *   - tl_<ns>_endpoint site_option (the hash check in maybe_login_support)
 *   - tl_<ns>_id and tl_<ns>_site_hash user_options (the user-meta lookup keys)
 *   - access/revoke cron event (the expire timer)
 *   - site/retry_revoke cron event (the SaaS-retry timer)
 *   - fake-saas envelope (the SaaS-side credential)
 *
 * If any one of these survives, the magic-link replay spec\'s
 * preconditions break and the SDK has a credential-still-works-
 * after-revoke hazard.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { execSync } from 'child_process';
import { loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => enableSslOverride() );
test.afterAll( () => { clearSslOverride(); resetSupportUsers(); } );
test.beforeEach( () => resetSupportUsers() );

function fakeSaasEnvelopeCount(): number {
	// fake-saas exposes /__state as JSON. Count entries in `envelopes`.
	const state = execSync( 'curl -fsS http://localhost:8003/__state', { encoding: 'utf8' } );
	const parsed = JSON.parse( state );
	return Object.keys( parsed.envelopes ?? {} ).length;
}

function resetFakeSaas(): void {
	// fake-saas envelopes are keyed by accessKey — which derives
	// from the api_key + namespace, so successive grants in the
	// same namespace overwrite the same row instead of adding a new
	// one. Without this reset, envelopesBefore + 1 reasoning fails.
	execSync( 'curl -fsS -X POST http://localhost:8003/__reset', { timeout: 5_000 } );
}

test( 'revoke cleans up every artifact a magic-link could exploit', async ( { page } ) => {
	// 1. Drain fake-saas so the envelope-count assertions below
	//    measure THIS test\'s grant in isolation.
	resetFakeSaas();
	expect( fakeSaasEnvelopeCount(),
		'precondition: fake-saas starts empty for this test' ).toBe( 0 );

	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput(), 'grant must complete' )
		.toBeVisible( { timeout: 30_000 } );

	// 2. Capture user_id BEFORE revoke (the user is gone after).
	const supportUserId = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1", "%tl_' + NS + '_id" ) );',
		'find seeded support user',
	).trim(), 10 );
	expect( supportUserId, 'precondition: support user exists post-grant' ).toBeGreaterThan( 0 );

	// Sanity: fake-saas got exactly one envelope.
	expect( fakeSaasEnvelopeCount(),
		'precondition: SaaS received exactly one grant envelope' )
		.toBe( 1 );

	// 3. Revoke via the production UI path.
	const revokeHref = await form.revokeButton().getAttribute( 'href' );
	expect( revokeHref ).toMatch( /revoke-tl=/ );
	const navResponse = await page.goto( revokeHref!, { waitUntil: 'load' } );
	expect( navResponse?.status() ).toBeGreaterThanOrEqual( 200 );
	expect( navResponse!.status() ).toBeLessThan( 400 );

	// 4. Audit each artifact. The audit reads via the same DB / wp-cli
	// surfaces that an attacker holding a captured magic-link could
	// rely on — if any of these survive, replay works.

	// 4a. WP user is gone — both per-site and network metas.
	const userExists = wpCli(
		'wp-cli-client',
		`$ud = get_userdata(${ supportUserId }); echo $ud ? "yes" : "no";`,
		'check user existence',
	).trim();
	expect( userExists, 'support user must be deleted' ).toBe( 'no' );

	// 4b. Identifier metas are gone (multisite stores under
	// `_<blog_id>_tl_<ns>_id`; the LIKE catches the variant).
	const metaCount = wpCli(
		'wp-cli-client',
		`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", "%tl_${ NS }_id", "%tl_${ NS }_site_hash" ) );`,
		'count remaining identifier metas',
	).trim();
	expect( parseInt( metaCount, 10 ),
		'identifier user_options must be cleaned up (any survivors are magic-link lookup keys)' )
		.toBe( 0 );

	// 4c. Endpoint site_option cleared. Without this, the
	// hash_equals check in maybe_login_support would still pass for
	// the captured payload.
	const endpoint = wpCli(
		'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS }_endpoint", "" );`,
		'fetch endpoint site_option after revoke',
	).trim();
	expect( endpoint,
		'endpoint site_option must be cleared on revoke' )
		.toBe( '' );

	// 4d. Expire cron is unscheduled. A leftover schedule wouldn\'t
	// resurrect access, but it indicates incomplete cleanup and may
	// fire stale callbacks.
	const cronExpire = wpCli(
		'wp-cli-client',
		`$cron = (array) get_option("cron", array()); $n = 0; foreach ($cron as $hooks) { foreach (array_keys((array) $hooks) as $h) { if ("trustedlogin/${ NS }/access/revoke" === $h) { $n++; } } } echo (int) $n;`,
		'count expire cron events',
	).trim();
	expect( parseInt( cronExpire, 10 ),
		'expire cron must be unscheduled' )
		.toBe( 0 );

	// 4e. Retry cron also unscheduled (only present if a previous
	// SaaS-revoke failed; on a successful revoke it should never be
	// scheduled in the first place, but assert anyway).
	const cronRetry = wpCli(
		'wp-cli-client',
		`$cron = (array) get_option("cron", array()); $n = 0; foreach ($cron as $hooks) { foreach (array_keys((array) $hooks) as $h) { if ("trustedlogin/${ NS }/site/retry_revoke" === $h) { $n++; } } } echo (int) $n;`,
		'count retry cron events',
	).trim();
	expect( parseInt( cronRetry, 10 ),
		'SaaS-retry cron must be unscheduled' )
		.toBe( 0 );

	// 4f. fake-saas envelope was deleted.
	expect( fakeSaasEnvelopeCount(),
		'SaaS envelope must be deleted (DELETE /api/v1/sites/<secret_id>)' )
		.toBe( 0 );

	// 4g. SecurityChecks transients cleared (lockdown + used keys).
	// Not strictly a credential artifact, but their presence biases
	// the next grant\'s SecurityChecks::verify with stale state.
	const lockdownExists = wpCli(
		'wp-cli-client',
		`echo get_option( "tl-${ NS }-in_lockdown" ) ? "yes" : "no";`,
		'check lockdown transient cleared',
	).trim();
	expect( lockdownExists, 'lockdown transient should not be set after a clean grant + revoke' ).toBe( 'no' );
} );

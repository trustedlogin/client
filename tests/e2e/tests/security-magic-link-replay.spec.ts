/**
 * Security E2E: a captured magic-link payload must not work after
 * the grant has been revoked.
 *
 * Threat model: a vendor agent\'s magic-link URL is captured by some
 * means — proxy log, browser history, leaked SaaS event, etc. The
 * customer revokes access. The attacker replays the URL. The SDK
 * must silently no-op — no auth cookie, no support session.
 *
 * The "before revocation" replay is BENIGN: the same identifier
 * legitimately re-logs the agent in (they\'re the same person, the
 * grant is still active). That\'s by design and not what this spec
 * tests. The contract under test is "POST-revocation, no replay".
 *
 * What the SDK relies on:
 *   1. The support user is deleted on revoke. SupportUser::get
 *      returns null for the captured identifier.
 *   2. The endpoint site_option is cleared on revoke. Even if the
 *      user record were intact, the hash_equals check would fail.
 *
 * If either #1 or #2 regresses, this spec fails — and that
 * regression is a magic-link-still-works-after-revoke vulnerability.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { CLIENT_URL, loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => enableSslOverride() );
test.afterAll( () => { clearSslOverride(); resetSupportUsers(); } );
test.beforeEach( () => resetSupportUsers() );

test( 'magic-link replay AFTER revoke is silently rejected', async ( { page, browser } ) => {
	// 1. Drive a real grant via the UI.
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput(), 'grant must complete' )
		.toBeVisible( { timeout: 30_000 } );

	// 2. Capture the magic-link payload BEFORE revocation. This is
	//    the "leaked URL" the attacker holds.
	const supportUserId = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1", "%tl_' + NS + '_id" ) );',
		'find support user',
	).trim(), 10 );
	expect( supportUserId, 'a support user must exist after the grant' ).toBeGreaterThan( 0 );

	const endpoint = wpCli( 'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS }_endpoint", "" );`, 'fetch endpoint' ).trim();
	const identifier = wpCli( 'wp-cli-client',
		`echo (string) get_user_option( "tl_${ NS }_site_hash", ${ supportUserId } );`, 'fetch site_hash' ).trim();

	expect( endpoint, 'endpoint must be populated' ).toMatch( /^[a-f0-9]{16,}$/ );
	expect( identifier, 'site_hash must be populated' ).toMatch( /^[a-zA-Z0-9_-]{16,}$/ );

	// 3. Sanity check: the captured payload works BEFORE revoke.
	//    This is BENIGN behavior — the agent legitimately re-logs in.
	//    We just confirm the captured payload is valid before the
	//    revocation, otherwise step 5 is meaningless.
	{
		const benignContext = await browser.newContext();
		const benignPage = await benignContext.newPage();
		await benignPage.setContent( `
			<form id="f" action="${ CLIENT_URL }/" method="POST">
				<input name="action" value="trustedlogin">
				<input name="endpoint" value="${ endpoint }">
				<input name="identifier" value="${ identifier }">
			</form>
			<script>document.getElementById('f').submit()</script>
		` );
		await benignPage.waitForURL( /\/wp-admin\/.*tl_notice=logged_in/, { timeout: 15_000 } );
		await benignContext.close();
	}

	// 4. Revoke via the production UI path. The revoke button is an
	//    `<a href>` with the REVOKE_SUPPORT_QUERY_PARAM + nonce —
	//    Endpoint::add hooks on init and validates that nonce. This
	//    is exactly what an admin clicking Revoke goes through.
	const revokeHref = await form.revokeButton().getAttribute( 'href' );
	expect( revokeHref, 'revoke button must have a usable href' ).toMatch( /revoke-tl=/ );
	const navResponse = await page.goto( revokeHref!, { waitUntil: 'load' } );
	expect( navResponse?.status(), 'revoke navigation must return 2xx' )
		.toBeGreaterThanOrEqual( 200 );
	expect( navResponse!.status() ).toBeLessThan( 400 );

	// Confirm via the SAME admin UI surface a real customer would
	// look at: re-navigate to the form. If revoke worked, the form
	// renders in "no grant" state (grant button visible, no revoke
	// button). No direct DB inspection — that\'d be scaffolding.
	await form.navigate();
	await expect( form.revokeButton(), 'revoke button must be gone from a re-rendered form' )
		.toHaveCount( 0 );
	await expect( form.grantButton(), 'grant button must be back after revoke' )
		.toBeVisible();

	// 5. Replay the captured URL. This is the attack. It must fail.
	const attackerContext = await browser.newContext();
	const attackerPage = await attackerContext.newPage();
	await attackerPage.setContent( `
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ endpoint }">
			<input name="identifier" value="${ identifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );
	// Wait for either a wp-admin redirect (BAD — replay worked) or
	// any other terminal page state (good — replay rejected).
	await attackerPage.waitForLoadState( 'load', { timeout: 15_000 } );

	expect( attackerPage.url(), 'replayed URL after revoke must NOT redirect to /wp-admin/' )
		.not.toMatch( /\/wp-admin\// );

	// And: no auth cookie set. Visiting wp-admin from the same
	// context must redirect to wp-login (anonymous), not show
	// "Howdy, ...".
	await attackerPage.goto( CLIENT_URL + '/wp-admin/', { waitUntil: 'domcontentloaded' } );
	expect( attackerPage.url(), 'replay must not have authenticated the attacker' )
		.toMatch( /wp-login\.php/ );

	await attackerContext.close();
} );

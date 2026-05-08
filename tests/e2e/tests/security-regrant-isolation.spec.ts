/**
 * Security E2E: a captured magic-link from grant #1 must not work
 * after grant #1 is revoked AND a NEW grant (#2) is issued for the
 * same namespace.
 *
 * Threat model: customer A grants support → agent gets URL #1.
 * Customer A revokes (or session ends). Customer A grants again →
 * agent gets URL #2 (new identifier, new endpoint). The captured
 * URL #1 is the threat — different values from URL #2, but if the
 * SDK reuses ANY state across grants (sticky transients,
 * predictable counters, identifier collisions), the attacker
 * holding URL #1 could target the active session.
 *
 * What must be true:
 *   1. URL #2 has a different endpoint hash than URL #1.
 *   2. URL #2 has a different identifier than URL #1.
 *   3. URL #1 replayed against the new state must fail (no user
 *      with the URL-#1 identifier exists; the endpoint hash check
 *      may pass or fail, but the user lookup must fail).
 *   4. URL #2 still works (sanity — confirms grant #2 is healthy).
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

interface MagicLinkPayload {
	endpoint: string;
	identifier: string;
	supportUserId: number;
}

async function driveGrantAndCapture( page: import( '@playwright/test' ).Page ): Promise<MagicLinkPayload> {
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton(), 'grant button must render' ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput(), 'grant must complete' )
		.toBeVisible( { timeout: 30_000 } );

	const supportUserId = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1", "%tl_' + NS + '_id" ) );',
		'find support user',
	).trim(), 10 );
	const endpoint = wpCli( 'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS }_endpoint", "" );`, 'fetch endpoint' ).trim();
	const identifier = wpCli( 'wp-cli-client',
		`echo (string) get_user_option( "tl_${ NS }_site_hash", ${ supportUserId } );`, 'fetch site_hash' ).trim();

	expect( supportUserId ).toBeGreaterThan( 0 );
	expect( endpoint ).toMatch( /^[a-f0-9]{16,}$/ );
	expect( identifier ).toMatch( /^[a-zA-Z0-9_-]{16,}$/ );

	return { supportUserId, endpoint, identifier };
}

async function uiRevoke( page: import( '@playwright/test' ).Page ): Promise<void> {
	const form = new GrantForm( page );
	const revokeHref = await form.revokeButton().getAttribute( 'href' );
	expect( revokeHref ).toMatch( /revoke-tl=/ );
	const navResponse = await page.goto( revokeHref!, { waitUntil: 'load' } );
	expect( navResponse?.status() ).toBeGreaterThanOrEqual( 200 );
	expect( navResponse!.status() ).toBeLessThan( 400 );
}

test( 'captured URL from grant #1 does NOT work against grant #2 state', async ( { page, browser } ) => {
	await loginAsAdmin( page );

	// 1. First grant — capture URL #1.
	const url1 = await driveGrantAndCapture( page );

	// 2. Sanity-replay URL #1 in a fresh context — proves it works
	//    BEFORE revoke. (Otherwise the post-revoke replay assertion
	//    is meaningless.)
	{
		const ctx = await browser.newContext();
		const p   = await ctx.newPage();
		await p.setContent( `
			<form id="f" action="${ CLIENT_URL }/" method="POST">
				<input name="action" value="trustedlogin">
				<input name="endpoint" value="${ url1.endpoint }">
				<input name="identifier" value="${ url1.identifier }">
			</form>
			<script>document.getElementById('f').submit()</script>
		` );
		await p.waitForURL( /\/wp-admin\/.*tl_notice=logged_in/, { timeout: 15_000 } );
		await ctx.close();
	}

	// 3. Revoke grant #1 via the production UI.
	await page.bringToFront();
	await new GrantForm( page ).navigate();
	await uiRevoke( page );

	// 4. Second grant — same admin, same namespace. Capture URL #2.
	const url2 = await driveGrantAndCapture( page );

	// 5. The new payload must differ from the old one across both
	//    secrets. If endpoint OR identifier collides, the captured
	//    URL #1 could target grant #2.
	expect( url2.endpoint, 'grant #2 endpoint hash must differ from grant #1' )
		.not.toBe( url1.endpoint );
	expect( url2.identifier, 'grant #2 identifier must differ from grant #1' )
		.not.toBe( url1.identifier );
	expect( url2.supportUserId, 'grant #2 user must be a different WP user' )
		.not.toBe( url1.supportUserId );

	// 6. Replay the captured URL #1 against the now-active grant #2
	//    state. This is the attack: attacker holds an old URL,
	//    customer is on a fresh session.
	const attacker = await browser.newContext();
	const attackerPage = await attacker.newPage();
	await attackerPage.setContent( `
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ url1.endpoint }">
			<input name="identifier" value="${ url1.identifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );
	await attackerPage.waitForLoadState( 'load', { timeout: 15_000 } );

	expect( attackerPage.url(),
		'old captured URL must not authenticate against new-grant state' )
		.not.toMatch( /\/wp-admin\// );

	// 7. And the new agent\'s URL #2 still works (sanity: grant #2
	//    is healthy — confirms our test isn\'t passing because grant
	//    #2 is also broken).
	const agent = await browser.newContext();
	const agentPage = await agent.newPage();
	await agentPage.setContent( `
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ url2.endpoint }">
			<input name="identifier" value="${ url2.identifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );
	await agentPage.waitForURL( /\/wp-admin\/.*tl_notice=logged_in/, { timeout: 15_000 } );

	await attacker.close();
	await agent.close();
} );

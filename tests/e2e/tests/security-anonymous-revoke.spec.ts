/**
 * Security E2E: an unauthenticated GET to the revoke URL must NOT
 * destroy a live grant.
 *
 * `revoke-flow-browser.spec.ts:99` covers logged-in admin with a
 * BAD nonce. This covers the complementary case: a logged-out
 * attacker who happened to obtain a valid nonce (or guesses one)
 * can still NOT revoke because Endpoint::add hooks on `init` only
 * when the request shape includes `?revoke-tl=<ns>` AND the cap
 * gate `current_user_can('delete_users')` (or whatever the SDK
 * requires) holds for the current user.
 *
 * For an anonymous request, get_current_user_id() is 0 and the cap
 * check fails. The request must be a no-op.
 *
 * Threat model: an attacker who can\'t authenticate but CAN issue
 * arbitrary GET requests (open admin tabs, accidental
 * Referer-leaked URLs, social engineering) shouldn\'t be able to
 * revoke a live support session as a denial-of-support attack.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => enableSslOverride() );
test.afterAll( () => { clearSslOverride(); resetSupportUsers(); } );
test.beforeEach( () => resetSupportUsers() );

test( 'logged-out GET to a revoke URL must not destroy the grant', async ( { page, browser } ) => {
	// 1. Drive a real grant.
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput() ).toBeVisible( { timeout: 30_000 } );

	// 2. Capture the rendered revoke URL — this is what the
	//    attacker would have if they\'d sniffed it from an admin\'s
	//    open tab or accidentally-shared link.
	const revokeHref = await form.revokeButton().getAttribute( 'href' );
	expect( revokeHref ).toMatch( /revoke-tl=/ );

	const userCountBefore = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count support users before anonymous request',
	).trim(), 10 );
	expect( userCountBefore, 'precondition: a support user exists' ).toBe( 1 );

	// 3. Fresh BrowserContext = no admin cookies. The attacker is
	//    anonymous to client-wp.
	const attackerContext = await browser.newContext();
	const attackerPage = await attackerContext.newPage();

	// 4. GET the captured revoke URL anonymously. Should:
	//    - 302 to wp-login (the cap check redirects unauthenticated
	//      users), OR
	//    - 200 with no-op behavior (no Client::revoke_access call).
	//    Either way, the support user must still exist after.
	await attackerPage.goto( revokeHref!, { waitUntil: 'load' } );

	// 5. CRITICAL: support user is still there.
	const userCountAfter = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count support users after anonymous attempt',
	).trim(), 10 );
	expect( userCountAfter,
		'anonymous GET must NOT destroy the support user — denial-of-support is the attack' )
		.toBe( 1 );

	// And the endpoint option is still there.
	const endpoint = wpCli(
		'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS }_endpoint", "" );`,
		'fetch endpoint after anonymous attempt',
	).trim();
	expect( endpoint, 'endpoint option must remain populated' )
		.toMatch( /^[a-f0-9]{16,}$/ );

	// 6. Final: anonymous attacker landed on wp-login (not on the
	//    grant form, not on a successful revoke confirmation).
	expect( attackerPage.url(),
		'unauthenticated request should redirect to wp-login.php' )
		.toMatch( /wp-login\.php/ );

	await attackerContext.close();
} );

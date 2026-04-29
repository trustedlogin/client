/**
 * Security E2E: the `tl_origin` query param on a revoke URL must
 * be filtered through the integrator-declared trusted-host
 * allowlist before being placed in the redirect target. An
 * attacker-supplied origin (e.g. `attacker.com`) must NOT survive
 * into the response\'s `origin=` query arg.
 *
 * Threat model: TrustedLogin\'s popup-based grant flow uses
 * postMessage between the connector site (vendor) and the customer
 * site (client). After a successful revoke, the client redirects
 * back to wp-login.php?action=trustedlogin&...&origin=<safe-origin>
 * so the JS can postMessage a `revoked` event back to the opener
 * window with the right targetOrigin.
 *
 * If `tl_origin` were echoed verbatim, an attacker who tricked an
 * admin into clicking a revoke URL with `tl_origin=https://attacker.com`
 * could:
 *   - inject `attacker.com` as the `origin=` value
 *   - the wp-login.php page (a vendor-controlled surface in the
 *     popup flow) would then run `postMessage(..., 'attacker.com')`
 *   - if attacker has any DOM access, they receive a confirmed
 *     "revoked" event from the customer\'s admin context.
 *
 * Endpoint::maybe_revoke_support routes tl_origin through
 * resolve_safe_referer, which only echoes back integrator-declared
 * hosts. This spec pins that contract.
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

/**
 * Drive a grant via the UI and return the rendered revoke URL —
 * we mutate it (add tl_return + tl_origin) for the actual test.
 */
async function grantAndCaptureRevokeHref( page: import( '@playwright/test' ).Page ): Promise<string> {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput() ).toBeVisible( { timeout: 30_000 } );

	const revokeHref = await form.revokeButton().getAttribute( 'href' );
	expect( revokeHref, 'revoke URL must be populated' ).toMatch( /revoke-tl=/ );
	return revokeHref!;
}

test( 'attacker-controlled tl_origin is filtered out of the redirect target', async ( { page } ) => {
	const revokeHref = await grantAndCaptureRevokeHref( page );

	// Add tl_return=login (triggers the redirect-with-origin branch)
	// + tl_origin=attacker.com (the attack).
	const sep = revokeHref.includes( '?' ) ? '&' : '?';
	const attackerHref = `${ revokeHref }${ sep }tl_return=login&tl_origin=${ encodeURIComponent( 'https://attacker.example.com/popup' ) }`;

	const navResponse = await page.goto( attackerHref, { waitUntil: 'load' } );
	expect( navResponse?.status() ).toBeGreaterThanOrEqual( 200 );

	// The final URL after redirects must contain the trustedlogin
	// confirmation params (revoked=1 etc.) but MUST NOT carry the
	// attacker domain anywhere in the URL.
	const finalUrl = page.url();
	expect( finalUrl,
		'attacker.example.com must not appear in the post-revoke URL' )
		.not.toContain( 'attacker.example.com' );
	expect( finalUrl,
		'attacker.example.com must not survive even URL-encoded' )
		.not.toContain( encodeURIComponent( 'attacker.example.com' ) );

	// And the support user IS gone — the revoke happened despite
	// the tl_origin filtering. Filtering must not break the legit
	// path.
	const userCount = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count users after revoke',
	).trim(), 10 );
	expect( userCount, 'revoke must complete normally — filtering is silent, not blocking' ).toBe( 0 );
} );

test( 'tl_origin matching the customer site IS preserved (sanity that filter isn\'t too aggressive)', async ( { page } ) => {
	const revokeHref = await grantAndCaptureRevokeHref( page );

	// Use CLIENT_URL — that\'s home_url(), one of the default
	// allowed hosts. Should pass through.
	const sep = revokeHref.includes( '?' ) ? '&' : '?';
	const trustedHref = `${ revokeHref }${ sep }tl_return=login&tl_origin=${ encodeURIComponent( CLIENT_URL ) }`;

	const navResponse = await page.goto( trustedHref, { waitUntil: 'load' } );
	expect( navResponse?.status() ).toBeGreaterThanOrEqual( 200 );

	// Resolve_safe_referer returns the matched URL when the host
	// equals one of the trusted hosts. The redirect should preserve
	// origin=<CLIENT_URL>.
	const finalUrl = page.url();
	expect( finalUrl,
		'a trusted tl_origin (matches home_url) must survive into the redirect' )
		.toMatch( /origin=/ );
} );

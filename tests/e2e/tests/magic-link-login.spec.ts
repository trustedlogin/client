/**
 * Browser-driven E2E: support agent magic-link login.
 *
 * The whole point of TrustedLogin is this flow. An admin grants access,
 * the SaaS hands the support agent a redirect URL, the agent visits it,
 * Endpoint::maybe_login_support() validates the endpoint+identifier and
 * sets the WP auth cookie. The agent lands in /wp-admin/ as the support
 * user — temporary admin/editor/whatever the namespace's role specifies.
 *
 * Other specs cover the granting half. This one covers the payoff.
 *
 * Wire path:
 *   1. Admin grants via the real UI — clicks "Grant Support Access" so
 *      the production AJAX → fake-saas → endpoint-update chain runs.
 *      Seeding via wp-cli would skip the SaaS POST and the rewrite
 *      flush, both of which the magic-link path depends on.
 *   2. Pull the now-present endpoint option + the support user's
 *      site_hash (the value SaaS sends as `identifier`).
 *   3. Fresh BrowserContext (no admin cookies) auto-submits a form to
 *      client_url + '/' with the same shape SaaS sends real agents.
 *   4. WP rewrite endpoint → template_redirect → maybe_login_support
 *      → wp_set_auth_cookie → wp_safe_redirect to admin_url.
 *   5. Agent lands in /wp-admin/, REST /users/me confirms the
 *      auth cookie belongs to the support user (not the granter).
 *
 * If any link regresses — the rewrite endpoint missing, the POST
 * action constant changed, the cookie not set, the redirect target
 * wrong, the SecurityChecks gating the wrong thing — this spec
 * fails. PHPUnit can't drive a real cookie jar through
 * wp_safe_redirect, so this is the only place the production
 * payoff is asserted.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { CLIENT_URL, loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	enableSslOverride();
} );

test.afterAll( () => {
	clearSslOverride();
	resetSupportUsers();
} );

test.beforeEach( () => {
	resetSupportUsers();
} );

/**
 * Drive a real grant via the admin UI. Returns the secret values the
 * SaaS would normally hand to the support agent. Pulling them via
 * wp-cli is unavoidable (no UI surface exposes site_hash) but the
 * STATE THAT PRODUCES THEM is the result of a real button click.
 */
async function grantAndExtractAgentCredentials( page: import( '@playwright/test' ).Page ): Promise<{
	endpoint: string;
	identifier: string;
	supportUserId: number;
}> {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton(), 'grant button must render before grant' ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput(), 'grant must complete and re-render the form' )
		.toBeVisible( { timeout: 30_000 } );

	const supportUserId = parseInt( wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1", "%tl_' + NS + '_id" ) );',
		'find seeded support user id',
	).trim(), 10 );
	expect( supportUserId, 'a support user must exist after a real grant click' ).toBeGreaterThan( 0 );

	const endpoint = wpCli(
		'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS }_endpoint", "" );`,
		'fetch endpoint option (post-grant)',
	).trim();

	const identifier = wpCli(
		'wp-cli-client',
		`echo (string) get_user_option( "tl_${ NS }_site_hash", ${ supportUserId } );`,
		'fetch site_hash (post-grant)',
	).trim();

	expect( endpoint, 'endpoint option must be populated after a real grant' ).toMatch( /^[a-f0-9]{16,}$/ );
	expect( identifier, 'site_hash must be populated after a real grant' ).toMatch( /^[a-zA-Z0-9_-]{16,}$/ );

	return { endpoint, identifier, supportUserId };
}

test( 'support agent visits endpoint URL → logged in as support user, lands in /wp-admin/', async ( { page, browser } ) => {
	const { endpoint, identifier, supportUserId } = await grantAndExtractAgentCredentials( page );

	// Fresh context: no admin cookies. The agent is a stranger to this site.
	const agentContext = await browser.newContext();
	const agentPage = await agentContext.newPage();

	// Auto-submitting form mimics the SaaS-side redirect that real
	// agents go through. request.post would lose the Set-Cookie chain
	// because the cookies wouldn't carry into a subsequent page.goto.
	// Form submission keeps everything in one origin so the auth
	// cookie set by wp_set_auth_cookie persists for the redirect target.
	await agentPage.setContent( `
		<!doctype html>
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ endpoint }">
			<input name="identifier" value="${ identifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );

	// The endpoint handler wp_safe_redirects to admin_url() with
	// ?tl_notice=logged_in after setting the auth cookie.
	await agentPage.waitForURL( /\/wp-admin\/.*tl_notice=logged_in/, { timeout: 15_000 } );

	// AUTHORITATIVE check: window.userSettings.uid is wp-localized by
	// core on every wp-admin page. It\'s the current user\'s id from
	// the auth cookie WP just validated — i.e., it answers
	// "whose cookie does this browser actually have?" by primary key,
	// not by display-name pattern.
	const cookieUid = await agentPage.evaluate( () => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const u = ( window as any ).userSettings;
		return u && typeof u.uid !== 'undefined' ? Number( u.uid ) : null;
	} );
	expect( cookieUid, 'auth cookie must identify the seeded support user by id' )
		.toBe( supportUserId );

	// Cross-check via the user-visible surface too — admin-bar text
	// is what a real customer (or screen-reader) would notice.
	const adminBar = await agentPage.locator( '#wp-admin-bar-my-account' ).first().textContent();
	expect( adminBar, 'admin-bar must NOT show the granting admin' )
		.not.toMatch( /Howdy, admin\b/i );
	expect( adminBar, 'admin-bar must show the support user (namespace title + " support")' )
		.toMatch( /pro block builder support/i );

	// Endpoint::maybe_login_support sets a green admin notice on the
	// dashboard via add_query_arg('tl_notice', 'logged_in', admin_url()).
	// This is the user-visible signal that the magic-link path
	// completed successfully — assert against the rendered notice text.
	await expect( agentPage.locator( '.notice' ).filter( { hasText: /pro block builder support user/i } ),
		'green TL notice must confirm support-login completed' )
		.toBeVisible();

	await agentContext.close();
} );

test( 'mismatched endpoint → silent no-op (does NOT log in)', async ( { page, browser } ) => {
	// Real grant first so the identifier is real, then exercise the
	// magic link with a WRONG endpoint hash. Endpoint::maybe_login_support
	// must hash_equals the endpoint and silently no-op on mismatch
	// (no cookie, no redirect to wp-admin) — a 401/redirect would
	// leak the stored endpoint timing to a brute-forcer.
	const { identifier } = await grantAndExtractAgentCredentials( page );

	const agentContext = await browser.newContext();
	const agentPage = await agentContext.newPage();

	const wrongEndpoint = 'a'.repeat( 32 );

	const navPromise = agentPage.waitForLoadState( 'load' );
	await agentPage.setContent( `
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ wrongEndpoint }">
			<input name="identifier" value="${ identifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );
	await navPromise;

	expect( agentPage.url(), 'mismatched endpoint must not redirect to wp-admin' )
		.not.toMatch( /\/wp-admin\// );

	const restMe = await agentPage.evaluate( async () => {
		const r = await fetch( '/wp-json/wp/v2/users/me', { credentials: 'same-origin' } );
		return { ok: r.ok, status: r.status };
	} );
	expect( restMe.ok, 'mismatched endpoint must not have set a session cookie' ).toBe( false );

	await agentContext.close();
} );

test( 'already-logged-in user hitting the endpoint → redirected to admin (no session swap)', async ( { page, browser } ) => {
	// Edge case: if the visitor is already logged in (e.g. an admin
	// clicks a SaaS-emailed link from their own browser),
	// maybe_login_support short-circuits with tl_notice=already_logged_in
	// and does NOT swap their session. Guards against accidental
	// privilege drop or cookie corruption.
	const { endpoint, identifier } = await grantAndExtractAgentCredentials( page );

	// New context, login as admin in it, then POST the magic link
	// from that authenticated context.
	const adminContext = await browser.newContext();
	const adminPage = await adminContext.newPage();
	await loginAsAdmin( adminPage );

	await adminPage.setContent( `
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ endpoint }">
			<input name="identifier" value="${ identifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );

	await adminPage.waitForURL( /tl_notice=already_logged_in/, { timeout: 15_000 } );

	// Confirm: still admin, not the support user. Admin-bar text is
	// the user-visible authoritative signal — REST cookie auth would
	// require the X-WP-Nonce dance and isn\'t the production wire.
	// textContent concatenates "Howdy, [display_name]" with the avatar
	// tooltip "[user_login]" (both render "admin"), producing
	// "Howdy, adminadmin..." — anchor to the prefix, don\'t use \b.
	const adminBar = await adminPage.locator( '#wp-admin-bar-my-account' ).first().textContent();
	expect( adminBar, 'admin must remain admin — session must NOT be swapped' )
		.toMatch( /^\s*Howdy,\s*admin/i );
	expect( adminBar, 'admin-bar must NOT show the support user' )
		.not.toMatch( /pro block builder support/i );

	await adminContext.close();
} );

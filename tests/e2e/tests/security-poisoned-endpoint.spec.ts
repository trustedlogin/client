/**
 * Security E2E: an attacker who can write the
 * `tl_<ns>_endpoint` site_option (compromised plugin, hostile DB
 * restore, SQL injection elsewhere) and POSTs the magic-link with a
 * matching endpoint hash + ANY identifier must NOT log in.
 *
 * Threat model: the endpoint hash is the first secret the SDK
 * checks (Endpoint::maybe_login_support → hash_equals on the stored
 * site_option). If an attacker controls that site_option, the hash
 * gate is bypassed by definition. The remaining defenses are:
 *   1. SecurityChecks::verify, which calls SaaS verify-identifier.
 *      If the attacker hasn\'t also poisoned the SaaS state, the
 *      identifier won\'t resolve.
 *   2. SupportUser::get, which looks up the user by identifier
 *      meta. If no support user exists for that hash, get() returns
 *      null and maybe_login produces WP_Error.
 *
 * In the absence of a real grant, BOTH conditions hold — there\'s
 * no support user, no SaaS envelope. Even with a poisoned endpoint
 * site_option, the magic-link must silently no-op.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { CLIENT_URL } from './helpers/login';
import { NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => enableSslOverride() );
test.afterAll( () => { clearSslOverride(); resetSupportUsers(); } );
test.beforeEach( () => resetSupportUsers() );

test( 'magic-link with attacker-controlled endpoint but no support user → silent no-op', async ( { browser } ) => {
	// 1. No grant exists. Confirm the precondition: no support
	//    user, no endpoint option populated. (resetSupportUsers in
	//    beforeEach should have done this; assert it for the spec\'s
	//    own integrity — if cleanup regresses, the assertions below
	//    would trivially pass.)
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'precondition: no support users',
	).trim();
	expect( parseInt( userCount, 10 ),
		'precondition: no grant exists at start of test' ).toBe( 0 );

	// 2. Attacker writes a known endpoint hash to the site_option.
	//    This simulates: SQL injection, malicious plugin, hostile DB
	//    restore — anything that gives one-time write access to
	//    wp_options/wp_sitemeta.
	const attackerEndpoint = 'a'.repeat( 32 );
	wpCli(
		'wp-cli-client',
		`update_site_option( "tl_${ NS }_endpoint", ${ JSON.stringify( attackerEndpoint ) } ); echo "ok";`,
		'attacker poisons endpoint option',
	);

	// 3. Attacker POSTs the magic-link from a fresh browser context
	//    (no auth cookies). Endpoint matches; identifier is anything
	//    valid-looking. A vulnerable SDK would log them in as some
	//    pre-existing TL support user (none exists, but the test
	//    proves the no-user branch fails closed).
	const attackerContext = await browser.newContext();
	const attackerPage = await attackerContext.newPage();
	const attackerIdentifier = 'b'.repeat( 64 ); // size+shape of a real raw site_hash

	await attackerPage.setContent( `
		<!doctype html>
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ attackerEndpoint }">
			<input name="identifier" value="${ attackerIdentifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );
	// Wait for the response to settle. Can\'t waitForURL on a
	// negative — just wait for `load` and inspect the URL after.
	await attackerPage.waitForLoadState( 'load', { timeout: 15_000 } );

	// 4. The contract: NOT redirected to /wp-admin/. The endpoint
	//    handler\'s no-user branch MUST hit fail_login (security
	//    check or login_failed) and render a non-authenticated page
	//    (standalone failure page or wp-login.php redirect).
	expect( attackerPage.url(),
		'poisoned endpoint with no support user must NOT log the attacker into wp-admin' )
		.not.toMatch( /\/wp-admin\// );

	// 5. Cookie-jar check: the next request to a protected page
	//    must redirect to wp-login.php. If wp_set_auth_cookie ever
	//    fired, this would land on the dashboard.
	await attackerPage.goto( CLIENT_URL + '/wp-admin/', { waitUntil: 'domcontentloaded' } );
	expect( attackerPage.url(),
		'poisoned endpoint must not have set an auth cookie' )
		.toMatch( /wp-login\.php/ );

	await attackerContext.close();

	// Cleanup: clear the attacker\'s poisoned option so afterAll
	// resetSupportUsers doesn\'t have to know about it.
	wpCli(
		'wp-cli-client',
		`delete_site_option( "tl_${ NS }_endpoint" ); echo "ok";`,
		'cleanup: delete attacker endpoint',
	);
} );

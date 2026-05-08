/**
 * Security E2E: while a brute-force lockdown is active, even the
 * LEGITIMATE magic-link must be refused. Fail-closed contract.
 *
 * The complement of `ctf-lockdown.spec.ts`: that test proves 3 bogus
 * identifiers trip lockdown. This one proves the OTHER half — once
 * tripped, the real identifier is also rejected.
 *
 * Why both halves matter:
 *   - Trip-on-bogus prevents brute-force key-guessing.
 *   - Reject-real-during-lockdown is what the SDK trades to get
 *     fail-closed: the support user genuinely can\'t get in until
 *     the lockdown clears (LOCKDOWN_EXPIRY transient TTL). It\'s a
 *     deliberate availability hit in exchange for confidentiality.
 *
 * If the legitimate-identifier path ever bypassed the lockdown
 * check (e.g. someone shipped "if user_exists, skip lockdown" as a
 * UX win), an attacker could keep guessing forever and the lockdown
 * would only be a speed bump.
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

test( 'during lockdown, the legitimate magic-link is refused', async ( { page, browser, request } ) => {
	// 1. Drive a real grant via the UI so we have a legitimate
	//    endpoint + identifier to test with later.
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
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
	const realIdentifier = wpCli( 'wp-cli-client',
		`echo (string) get_user_option( "tl_${ NS }_site_hash", ${ supportUserId } );`, 'fetch site_hash' ).trim();
	expect( endpoint ).toMatch( /^[a-f0-9]{16,}$/ );
	expect( realIdentifier ).toMatch( /^[a-zA-Z0-9_-]{16,}$/ );

	// 2. Trip the lockdown. SecurityChecks::ACCESSKEY_LIMIT_COUNT is
	//    3 — three distinct bogus identifiers from the same IP-hash
	//    push the site into in_lockdown=true. Use the same
	//    `request` fixture for all attempts so the IP-hash is stable.
	const post = ( ident: string ) =>
		request.post( CLIENT_URL + '/', {
			maxRedirects: 0,
			form: { action: 'trustedlogin', endpoint, identifier: ident },
		} );

	const rand = () => wpCli( 'wp-cli-client', 'echo bin2hex(random_bytes(64));', 'random ident' ).trim();
	for ( let i = 0; i < 3; i++ ) {
		await post( rand() );
	}

	// 3. Confirm lockdown is engaged. Utils::set_transient stores
	//    its value as a raw option row, so we check the option
	//    directly rather than via WP\'s transient API.
	const lockdownRaw = wpCli(
		'wp-cli-client',
		`$v = get_option("tl-${ NS }-in_lockdown"); echo $v ? "yes" : "no";`,
		'check lockdown engaged',
	).trim();
	expect( lockdownRaw,
		'precondition: 3 bogus identifiers must engage lockdown — otherwise this test is testing nothing' )
		.toBe( 'yes' );

	// 4. The actual contract: REPLAY THE LEGITIMATE MAGIC-LINK while
	//    lockdown is active. Open a fresh browser context (no
	//    cookies) and POST the real endpoint + real identifier —
	//    the same payload that worked in step 1.
	const agentContext = await browser.newContext();
	const agentPage = await agentContext.newPage();
	await agentPage.setContent( `
		<!doctype html>
		<form id="f" action="${ CLIENT_URL }/" method="POST">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ endpoint }">
			<input name="identifier" value="${ realIdentifier }">
		</form>
		<script>document.getElementById('f').submit()</script>
	` );
	await agentPage.waitForLoadState( 'load', { timeout: 15_000 } );

	// 5. Even though endpoint + identifier are correct, the
	//    SecurityChecks::verify path runs in_lockdown() FIRST and
	//    short-circuits with WP_Error → fail_login. The agent must
	//    NOT land on /wp-admin/.
	expect( agentPage.url(),
		'lockdown must reject even the legitimate identifier — fail-closed contract' )
		.not.toMatch( /\/wp-admin\// );

	// 6. And no auth cookie was set.
	await agentPage.goto( CLIENT_URL + '/wp-admin/', { waitUntil: 'domcontentloaded' } );
	expect( agentPage.url(),
		'lockdown rejection must not have set an auth cookie' )
		.toMatch( /wp-login\.php/ );

	await agentContext.close();
} );

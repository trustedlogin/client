/**
 * Browser-driven E2E: admin\'s session expires between form load and
 * grant click — must NOT silently swallow the request, must NOT mint
 * a support user, must surface SOMETHING the user can act on.
 *
 * Production scenario: a tab open overnight when the auth cookie
 * gets invalidated by `wp_destroy_other_sessions()` from another
 * device, by a manual logout, or by an `auth_cookie_expired`
 * action. The pre-load grant button is still in the DOM, the JS
 * thinks it can submit, but the cookie is no longer valid.
 *
 * trustedlogin.js posts to admin-ajax. With no auth cookie WP routes
 * the request through the unauthenticated AJAX path. The Ajax::ajax_generate_support
 * permission_callback (and check_ajax_referer) must reject — anything
 * else means a logged-out user could mint support access.
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

test( 'cookie cleared between form load and click → no support user minted', async ( { page, context } ) => {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	const grant = form.grantButton();
	await expect( grant, 'grant button must render before clearing cookies' ).toBeVisible();

	// Drop ALL cookies for the client origin. Mirrors session-destroyed
	// state: the page has the form rendered, JS has the localized
	// nonce, but the next request can\'t prove who the user is.
	await context.clearCookies();

	await grant.click();

	// trustedlogin.js routes admin-ajax 4xx responses through the
	// error path → renders the error banner. Wait for that.
	const errorBanner = page.locator( `.tl-${ NS }-auth__response_error` );
	await expect( errorBanner, 'session-expiry click must surface an error banner, not silently succeed' )
		.toBeVisible( { timeout: 15_000 } );

	// CRITICAL: no support user. An admin-ajax POST without a logged-in
	// session that successfully mints a support user is a worst-case
	// auth bypass — exactly what permission_callback exists to prevent.
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count support users after session-expiry click',
	).trim();
	expect( parseInt( userCount, 10 ),
		'session-expired click must NOT mint a support user' )
		.toBe( 0 );

	// Belt: visiting the form again now that we\'re logged out lands
	// on wp-login.php (cap-gated by admin.php). Confirms the session
	// is genuinely cleared — guard against a Playwright-context quirk
	// that would falsely satisfy the assertion above.
	await page.goto( CLIENT_URL + form.path, { waitUntil: 'domcontentloaded' } );
	expect( page.url(), 'after cookie clear, accessing the grant form must redirect to wp-login' )
		.toMatch( /wp-login\.php/ );
} );

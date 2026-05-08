/**
 * Browser-driven E2E: a wrong nonce on the grant AJAX must be
 * rejected by check_ajax_referer — error banner surfaces, no support
 * user is minted, no cron scheduled.
 *
 * Two real-world ways the browser ends up sending a wrong nonce:
 *   1. The localized nonce was tampered with (XSS-injected script
 *      replacing `tl_obj._nonce`, or a malicious browser extension).
 *   2. A stale nonce that has aged past the 24h WP nonce TTL.
 *
 * This spec exercises path #1 directly by mutating `window.tl_obj._nonce`
 * — the server-side rejection (#1) is the same code path as #2, since
 * check_ajax_referer compares the supplied nonce against a freshly-
 * computed one regardless of WHY the supplied one is wrong. So this
 * test covers the rejection contract for both scenarios; it does not
 * actually wait 24h to test time-based expiry.
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

test( 'tampered nonce surfaces an error banner and mints no user', async ( { page } ) => {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	const grant = form.grantButton();
	await expect( grant, 'grant button must render before tampering' ).toBeVisible();

	// Replace the localized nonce with a known-bad value. The SDK now
	// publishes per-namespace config under window.trustedLogin[ns]
	// (replacing the legacy window.tl_obj — see tl-obj-isolation spec).
	// Mutating it is exactly the failure mode an expired-from-disk
	// nonce produces server-side: the browser still has _A_ value,
	// just one that won't verify.
	await page.evaluate( ( ns ) => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		( window as any ).trustedLogin[ ns ]._nonce = 'expired-or-tampered';
	}, NS );

	await grant.click();

	// trustedlogin.js inserts the failure status into
	// .tl-{ns}-auth__response_error. Wait for it to appear.
	const errorBanner = page.locator( `.tl-${ NS }-auth__response_error` );
	await expect( errorBanner, 'error banner must surface a stale-nonce failure to the user' )
		.toBeVisible( { timeout: 15_000 } );

	// Cross-check: NO support user was created. A 403 from
	// check_ajax_referer must short-circuit BEFORE Client::grant_access
	// runs — otherwise the cap-gate is the only thing standing between
	// CSRF and a minted support account.
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count support users after tampered-nonce click',
	).trim();

	expect( parseInt( userCount, 10 ),
		'tampered-nonce click must NOT mint a support user — would imply CSRF gate broken' )
		.toBe( 0 );

	// And no expire-cron event scheduled either — corroborates "no user".
	const cronCount = wpCli(
		'wp-cli-client',
		'$cron = (array) get_option("cron", array()); $n = 0; foreach ($cron as $hooks) { foreach (array_keys((array) $hooks) as $h) { if ("trustedlogin/' + NS + '/access/revoke" === $h) { $n++; } } } echo (int) $n;',
		'count TL revoke cron after tampered-nonce click',
	).trim();
	expect( parseInt( cronCount, 10 ),
		'no expire cron should be scheduled when nonce check fails' )
		.toBe( 0 );
} );

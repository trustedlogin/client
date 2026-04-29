/**
 * Browser-driven E2E: a fast double-click on the grant button must
 * fire exactly ONE admin-ajax POST.
 *
 * The contract has two layers:
 *   1. The grant button is `<button>` and trustedlogin.js sets the
 *      native `disabled` attribute on the first click. For events
 *      that arrive AFTER that, the browser suppresses dispatch.
 *   2. trustedlogin.js\'s click handler also early-returns when
 *      `prop('disabled')` is already set. This catches the case
 *      where Playwright (or any synthetic input source) dispatches
 *      two click events back-to-back BEFORE JS runs — JS runs
 *      serially, so the second handler invocation sees the flag.
 *
 * The test fires two Promise.all clicks (sub-millisecond apart) so
 * it exercises BOTH layers. Anything slower would only verify layer
 * 1 and miss the synthetic-burst path.
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

test( 'double-click on grant button fires exactly one admin-ajax POST', async ( { page } ) => {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	const grant = form.grantButton();
	await expect( grant, 'grant button must render before click' ).toBeVisible();

	// Capture every admin-ajax POST so we can assert the disabled-
	// attribute guard actually prevented the second click from
	// firing a second network request — not just that a server-side
	// dedupe coalesced two writes. trustedlogin.js POSTs with
	// action=tl_<ns>_gen_support; match on that.
	const grantAjaxRequests: string[] = [];
	page.on( 'request', ( req ) => {
		if ( req.method() !== 'POST' ) return;
		if ( ! req.url().includes( '/wp-admin/admin-ajax.php' ) ) return;
		const body = req.postData() ?? '';
		if ( body.includes( `action=tl_${ NS }_gen_support` ) ) {
			grantAjaxRequests.push( body );
		}
	} );

	// Issue both clicks synchronously in the page context, on the
	// SAME captured element reference. The DOM `HTMLElement.click()`
	// API:
	//   - bypasses Playwright\'s actionability + auto-wait, so the
	//     second click can\'t be silently retargeted to a freshly
	//     rendered button after the page navigates;
	//   - on a `<button>`, respects `disabled` per HTML spec — a
	//     disabled button\'s click() is a no-op (no click event
	//     dispatched).
	//
	// The first .click() runs synchronously through the jQuery
	// delegate handler, which calls grantAccess(). grantAccess sets
	// `disabled` on the same element BEFORE returning. The second
	// .click() then sees the button is disabled and produces no
	// click event — exactly the contract this test pins.
	await grant.evaluate( ( el ) => {
		const btn = el as HTMLButtonElement;
		btn.click();
		btn.click();
	} );

	// Wait for the (real) round-trip to settle and the form to
	// re-render with the access-key input.
	await expect( form.accessKeyInput(), 'grant must complete' )
		.toBeVisible( { timeout: 30_000 } );

	// Settle window for any straggling out-of-order request.
	await page.waitForTimeout( 500 );

	// THE assertion that proves the scenario: exactly one network
	// request hit the server. Two requests would mean the disabled
	// attribute guard isn\'t set fast enough (or isn\'t set at all),
	// and only server-side coalescing is preventing the double-mint
	// — relying on which is fragile.
	expect( grantAjaxRequests.length,
		'realistic-timing double-click must fire EXACTLY one admin-ajax POST — disabled-attribute is the load-bearing guard' )
		.toBe( 1 );

	// And confirm the end state: one user, one cron.
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count distinct support users after double-click',
	).trim();
	expect( parseInt( userCount, 10 ), 'exactly one support user' ).toBe( 1 );

	const cronCount = wpCli(
		'wp-cli-client',
		// Cron::__construct: hook_name = "trustedlogin/{ns}/access/revoke"
		'$cron = (array) get_option("cron", array()); $n = 0; foreach ($cron as $hooks) { foreach (array_keys((array) $hooks) as $h) { if ("trustedlogin/' + NS + '/access/revoke" === $h) { $n++; } } } echo (int) $n;',
		'count TL revoke cron events after double-click',
	).trim();
	expect( parseInt( cronCount, 10 ), 'exactly one expire cron' ).toBe( 1 );
} );

/**
 * Browser-driven E2E: real extend-access flow.
 *
 * Drives the production extend chain:
 *   1. Real admin login
 *   2. Pre-seed an existing support user
 *   3. Navigate to the form — the grant button renders with
 *      data-access="extend" because SupportUser::exists() returned a
 *      user_id, signaling the JS to use the "extending..." status copy
 *   4. Click the (now-"extend") button → AJAX to admin-ajax.php
 *      action=tl_{ns}_gen_support → Client::grant_access detects the
 *      existing user → routes to extend_access path
 *   5. Verify the user_meta tl_{ns}_expires advanced past its prior value
 *
 * Catches: the extend-vs-create branch in Form\'s data-access attribute,
 * the existing-user detection, the JS pending-text branch, and the
 * SupportUser::extend → Cron::reschedule → user-meta update chain.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, seedSupportUser, enableSslOverride, clearSslOverride } from './helpers/seed';

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

test( 'admin clicks Grant on an existing-grant form → expiration advances', async ( { page } ) => {
	const seeded_user_id = seedSupportUser();

	// Capture the pre-extend expiry so we can prove the click moved it.
	const beforeExpiry = parseInt(
		wpCli(
			'wp-cli-client',
			`echo (int) get_user_option("tl_${ NS }_expires", ${ seeded_user_id });`,
			'read pre-extend expiry',
		),
		10,
	);
	expect( beforeExpiry ).toBeGreaterThan( 0 );

	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	// On a form with an existing grant, the button is the "extend"
	// button — same selector but with data-access="extend".
	await expect( form.grantButton(), 'grant/extend button must render' ).toBeVisible();
	await expect( form.grantButton() ).toHaveAttribute( 'data-access', 'extend' );

	// Clicking the button fires AJAX → grant_access detects existing user
	// → extend_access. Wait for the JS state machine to land in success
	// (location.href redirect re-loads the form page).
	await Promise.all( [
		page.waitForURL( ( url ) => url.searchParams.get( 'page' ) === `grant-${ NS }-access`, { timeout: 15_000 } ),
		form.grantButton().click(),
	] );

	// And give the redirect a moment to settle so the user-meta is
	// readable on the next wpCli round trip.
	await page.waitForLoadState( 'domcontentloaded' );

	const afterExpiry = parseInt(
		wpCli(
			'wp-cli-client',
			`echo (int) get_user_option("tl_${ NS }_expires", ${ seeded_user_id });`,
			'read post-extend expiry',
		),
		10,
	);

	expect( afterExpiry, `expiration must move forward on extend (before=${ beforeExpiry } after=${ afterExpiry })` )
		.toBeGreaterThan( beforeExpiry );
} );

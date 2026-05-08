/**
 * Browser-driven E2E: real grant-access flow.
 *
 * Drives the production wire path:
 *   1. Real wp-login.php form submission as admin → cookie jar
 *   2. Navigate to /wp-admin/admin.php?page=grant-{ns}-access
 *   3. Click the SDK\'s grant button
 *   4. AJAX fires admin-ajax.php?action=tl_{ns}_gen_support, hits
 *      Ajax::ajax_generate_support, validates the wp-localized nonce,
 *      runs Client::grant_access, posts the envelope to fake-saas
 *   5. JS receives the response, sets location.href to the form URL
 *   6. Page re-renders showing the access-key input + revoke button
 *
 * If any link in that chain regresses — JS dispatch broken, nonce
 * regeneration off, AJAX permission_callback misconfigured, JSON
 * response shape changed, etc. — this spec fails. PHPUnit can\'t catch
 * any of those because none of them run in CLI.
 *
 * What we cross-check via wpCli (NOT what we test): we sanity-confirm
 * a support user with the SDK\'s role exists after the click. The
 * meaningful assertions are all DOM-based.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { loginAsAdmin } from './helpers/login';
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

test( 'admin clicks Grant Support Access → support user is minted, access key visible', async ( { page } ) => {
	await loginAsAdmin( page );

	const form = new GrantForm( page );
	await form.navigate();

	// The grant button must be present BEFORE click. If this fails the
	// form\'s "no existing grant" branch isn\'t rendering — the markup
	// regressed or the menu/cap gating broke.
	await expect( form.grantButton(), 'grant button must render on a fresh form' )
		.toBeVisible();

	// Click the grant button. trustedlogin.js sets a "_pending" status,
	// fires AJAX to admin-ajax.php?action=tl_{ns}_gen_support, then on
	// success sets location.href to the form URL — same path, same
	// query — which reloads the page in "grant exists" mode.
	//
	// The redirect URL doesn't actually change (same query args), so
	// waitForURL is useless as a signal. The signal that the round-trip
	// completed is the access-key input appearing in the re-rendered
	// form. 30s covers cold-Apache-bootstrap + AJAX + reload + render.
	await form.grantButton().click();

	await expect( form.accessKeyInput(), 'access-key input must render after a successful grant' )
		.toBeVisible( { timeout: 30_000 } );

	// The grant button gets replaced by a revoke button on success.
	await expect( form.revokeButton(), 'revoke button must replace the grant button after success' )
		.toBeVisible();

	// And the status banner must NOT be in error state.
	await expect( form.statusOfType( 'error' ),
		'no error banner should be present on a successful grant' )
		.toHaveCount( 0 );

	// Sanity cross-check: a support-role user actually exists in WP.
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", "tl_' + NS + '_id"));',
		'count support users by meta',
	);
	expect( parseInt( userCount, 10 ), 'exactly one support user should exist after a grant' )
		.toBe( 1 );
} );

test( 'unauthenticated visit to the grant form redirects to wp-login', async ( { page } ) => {
	// No login. Navigate directly. WP routes the request to wp-login.php
	// because admin.php?page=… requires create_users; the AJAX gate is
	// already covered by the AJAX-permission_callback unit test, but the
	// menu-page gate is HTTP-time and lives only here.
	const form = new GrantForm( page );
	await page.goto( 'http://localhost:8002' + form.path, { waitUntil: 'domcontentloaded' } );

	expect( page.url(), 'unauthenticated form access must hit wp-login.php' )
		.toMatch( /wp-login\.php/ );
} );

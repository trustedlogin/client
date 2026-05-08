/**
 * Browser-driven E2E: real revoke-access flow.
 *
 * Drives the full production revoke chain:
 *   1. Real admin login
 *   2. Pre-seed an existing support user (via SDK, not via grant click —
 *      avoids coupling this spec to grant-flow stability)
 *   3. Navigate to the form
 *   4. Click "Revoke Access" — it\'s a <a href> with the REVOKE_SUPPORT
 *      query-string + nonce; the browser navigates to it
 *   5. Endpoint detects REVOKE_SUPPORT_QUERY_PARAM, validates the
 *      per-user nonce, calls Client::revoke_access
 *   6. Page reloads in "no grant exists" state — grant button visible
 *      again, no revoke button
 *
 * Catches: revoke-button render branch in Form.php, the
 * REVOKE_SUPPORT_QUERY_PARAM nonce gate, the Endpoint hook ordering,
 * and DOM state after revoke. PHPUnit can\'t exercise the nonce gate
 * because it\'s tied to the request\'s wp_verify_nonce + admin context.
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

test( 'admin clicks Revoke Access → support user is removed, form returns to grant state', async ( { page } ) => {
	// Pre-seed a support user. The form will detect it and render the
	// revoke button instead of the grant button.
	const seeded_user_id = seedSupportUser();

	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	// On a form load with an existing grant, the revoke button must
	// be visible. If not, Form.php\'s "grant exists" branch regressed
	// or the support-user detection (SupportUser::exists) is broken.
	// Note: the grant button stays in the DOM because it doubles as
	// the "extend" affordance when a grant exists (JS reads its
	// data-access attribute to decide between grant/extend semantics).
	await expect( form.revokeButton(), 'revoke button must render when a grant exists' )
		.toBeVisible();

	// Capture the rendered href so the assertion failure message
	// shows the nonce shape if the click chain breaks.
	const revokeHref = await form.revokeButton().getAttribute( 'href' );
	expect( revokeHref, 'revoke button must have a usable href' )
		.toMatch( /revoke-tl=/ );

	// Use page.goto on the rendered href instead of click(). The href is
	// what production users would hit on click; goto removes any
	// JS-prevented-default ambiguity AND lets Playwright surface the
	// HTTP response (status + redirect chain) for diagnosis.
	const navResponse = await page.goto( revokeHref!, { waitUntil: 'load' } );
	expect( navResponse?.status(), 'revoke navigation must return 2xx' )
		.toBeGreaterThanOrEqual( 200 );
	expect( navResponse!.status() ).toBeLessThan( 400 );

	// The user MUST be gone. This is the production-critical
	// invariant: clicking revoke deletes the support user.
	const diagnostic = wpCli(
		'wp-cli-client',
		[
			`$ud = get_userdata(${ seeded_user_id });`,
			'echo "uid_query=" . (' + seeded_user_id + ') . " | exists=" . ($ud ? "yes" : "no");',
			'$by_email = get_user_by("email", "browser-seed@example.test");',
			'echo " | by_email=" . ($by_email ? $by_email->ID : "none");',
		].join( ' ' ),
		'diag: check seeded user after revoke',
	);
	expect(
		diagnostic,
		`seeded support user must be deleted by the revoke chain. Revoke URL: ${ revokeHref }. Diagnostic: ${ diagnostic }`,
	).toMatch( /exists=no/ );

	// And the form must reflect that — re-render shows no revoke button.
	await form.navigate();
	await expect( form.revokeButton(), 'revoke button must be gone from a re-rendered form' )
		.toHaveCount( 0 );
} );

test( 'revoke link without a valid nonce is refused', async ( { page } ) => {
	// Pre-seed a user. Then craft a revoke URL with a BAD nonce and hit
	// it directly. The Endpoint guard must reject it (no deletion).
	const seeded_user_id = seedSupportUser();

	await loginAsAdmin( page );

	const url = `http://localhost:8002/wp-admin/?revoke-tl=${ NS }&_wpnonce=invalidnonce123`;
	await page.goto( url, { waitUntil: 'domcontentloaded' } );

	// User must still exist — the nonce gate refused the request.
	const exists = wpCli(
		'wp-cli-client',
		`echo (get_userdata(${ seeded_user_id }) ? "yes" : "no");`,
		'check seeded user after invalid-nonce revoke attempt',
	);
	expect( exists, 'invalid nonce must NOT delete the support user' ).toBe( 'yes' );
} );

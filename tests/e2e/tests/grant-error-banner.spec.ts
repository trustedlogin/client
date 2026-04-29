/**
 * Browser-driven E2E: grant-flow error UX.
 *
 * Production failure mode: SaaS POST /api/v1/sites returns a non-success
 * response (500, malformed body, network timeout). The SDK\'s JS
 * receives a JSON error from admin-ajax.php and must surface a
 * user-friendly status banner — NOT a JS console error or unhandled
 * promise rejection.
 *
 * This spec forces the SaaS to return 500 via a tl_test_force_saas_500
 * harness option. The harness adds a pre_http_request filter that
 * intercepts POSTs to /api/v1/sites/ and replies with 500. The SDK\'s
 * Remote::handle_response converts that to WP_Error, Client returns it
 * to the AJAX handler, AJAX returns it as the JSON response, JS
 * captures it via remote_error and renders the error banner.
 *
 * Critically, the test also asserts there\'s NO orphan support user
 * left behind — Client::rollback_orphan_support_user must have run.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

function enableSaas500(): void {
	wpCli(
		'wp-cli-client',
		`update_option("tl_test_force_saas_500", "yes"); echo "ok";`,
		'force SaaS 500',
	);
}

function clearSaas500(): void {
	wpCli(
		'wp-cli-client',
		`delete_option("tl_test_force_saas_500"); echo "ok";`,
		'clear SaaS 500',
	);
}

test.beforeAll( () => {
	enableSslOverride();
	enableSaas500();
} );

test.afterAll( () => {
	clearSaas500();
	clearSslOverride();
	resetSupportUsers();
} );

test.beforeEach( () => {
	resetSupportUsers();
} );

test( 'fake-saas returns 500 → form shows error banner, no orphan user is left', async ( { page } ) => {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();

	// JS\'s remote_error handler should add the error class to the
	// status banner. trustedlogin.js calls outputStatus(msg, 'error').
	await expect( form.statusOfType( 'error' ),
		'a SaaS-error response must surface as a user-friendly error banner, not a silent failure' )
		.toBeVisible( { timeout: 10_000 } );

	// And the support user that grant_access started to provision
	// must NOT remain — Client::rollback_orphan_support_user runs on
	// the SaaS-error branch.
	const orphanCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", "tl_' + NS + '_id"));',
		'count orphan support users',
	);
	expect( parseInt( orphanCount, 10 ),
		'no orphan support user should remain after a SaaS-error rollback' )
		.toBe( 0 );
} );

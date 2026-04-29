/**
 * Browser-driven E2E: vendor public-key fetch fails at the network
 * layer (DNS unresolved, connection refused, TLS handshake timeout).
 * The grant click must surface a clean error banner — NOT a hung
 * "Sending encrypted access" spinner that the user has to manually
 * cancel by reloading.
 *
 * Production scenario: vendor-wp is briefly unreachable from the
 * customer site. Could be a DNS hiccup, a transient firewall block,
 * or the vendor pushing a deploy. The customer\'s grant click MUST
 * NOT silently swallow the request OR hang the browser tab.
 *
 * The existing tl-response-injector mu-plugin has a `request_failed`
 * mode that returns WP_Error from pre_http_request — same effect as
 * a real wp_remote_get network failure. Set it, click, assert the
 * error path runs end-to-end through the AJAX handler back to the
 * DOM\'s error banner.
 *
 * Distinct from compat-malformed-pubkey-response.spec.ts which
 * exercises *malformed-but-200* responses (HTML where JSON expected,
 * 415 from a CDN, etc.). This is the hard-failure leg of the same
 * compatibility matrix.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => enableSslOverride() );
test.afterAll( () => {
	clearSslOverride();
	resetSupportUsers();
	// Make absolutely sure we don\'t leave the injector armed.
	wpCli( 'wp-cli-client', `delete_option("tl_inject_pubkey_response"); echo "ok";`, 'clear injector' );
} );

test.beforeEach( () => {
	resetSupportUsers();
	// Drop the pubkey transient so the SDK is forced to refetch (and
	// thus hit our re-pointed URL). Utils::set_transient stores the
	// value as a raw option row, so delete_option targets the right
	// storage.
	wpCli(
		'wp-cli-client',
		`delete_option( "tl_${ NS }_vendor_public_key" ); echo "ok";`,
		'clear pubkey transient',
	);
	// Re-point the vendor pubkey URL to an unreachable host. Real DNS
	// would resolve vendor-wp differently in production, so this
	// mirrors what a customer sees when their firewall blocks
	// vendor-wp or vendor-wp DNS-fails.
	wpCli(
		'wp-cli-client',
		`update_option( "tl_test_break_pubkey_fetch", "yes" ); echo "ok";`,
		'arm broken pubkey URL filter',
	);
} );

test.afterEach( () => {
	wpCli( 'wp-cli-client', `delete_option("tl_test_break_pubkey_fetch"); echo "ok";`, 'disarm broken pubkey URL' );
} );

test( 'vendor pubkey unreachable → preflight banner replaces grant button, no user minted', async ( { page } ) => {
	await loginAsAdmin( page );
	const form = new GrantForm( page );
	await form.navigate();

	// Preflight runs on page load (Form::print_auth_screen → pubkey
	// fetch → on WP_Error → renders the failure template instead of
	// the grant button). The user never sees an in-flight spinner —
	// the banner is up before they can click anything.
	const errorBanner = page.locator( `.tl-${ NS }-auth__response_error` );
	await expect( errorBanner,
		'pubkey unreachable must surface a preflight error banner immediately, not an indefinite spinner' )
		.toBeVisible( { timeout: 30_000 } );

	// The customer-facing copy must match Remote.php\'s WP_Error
	// message — that\'s the contract this template fulfils.
	await expect( errorBanner, 'banner message must come from Remote::handle_response' )
		.toContainText( /unreachable|temporarily unavailable|server returned|not ready/i );

	// Grant button MUST be replaced by the "Contact support" CTA —
	// otherwise a customer would click Grant against a known-broken
	// vendor connection, mint a half-state user, and end up with a
	// support user the agent can\'t actually use.
	await expect( page.locator( '.tl-client-grant-button' ),
		'preflight failure must replace the grant button with a contact CTA' )
		.toHaveCount( 0 );
	await expect( page.locator( `.tl-${ NS }-auth__contact` ),
		'contact-support CTA must render in place of the grant button' )
		.toBeVisible();

	// Cross-check: NO support user was minted. Preflight runs BEFORE
	// SupportUser::create, so a customer hitting an unreachable
	// vendor must not leave any user behind.
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count users after preflight failure',
	).trim();
	expect( parseInt( userCount, 10 ),
		'preflight failure must NOT mint a support user — only-mint-on-success contract' )
		.toBe( 0 );

	// And no expire-cron event scheduled either.
	const cronCount = wpCli(
		'wp-cli-client',
		'$cron = (array) get_option("cron", array()); $n = 0; foreach ($cron as $hooks) { foreach (array_keys((array) $hooks) as $h) { if ("trustedlogin/' + NS + '/access/revoke" === $h) { $n++; } } } echo (int) $n;',
		'count expire crons after preflight failure',
	).trim();
	expect( parseInt( cronCount, 10 ),
		'no expire cron should be scheduled when preflight failed' )
		.toBe( 0 );
} );

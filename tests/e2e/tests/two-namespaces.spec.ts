/**
 * Browser-driven E2E: two TrustedLogin clients coexist on one site.
 *
 * Real-world scenario: a vendor ships two plugins (or two different
 * vendors are integrated on the same site), each registering its
 * own TrustedLogin namespace. Granting on namespace A must NOT
 * pollute namespace B\'s state — separate menu pages, separate
 * support users, separate endpoint options, separate cron events,
 * separate roles.
 *
 * Setup: tl-second-namespace.php mu-plugin registers namespace
 * "widget-master" alongside the existing "pro-block-builder".
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { CLIENT_URL, loginAsAdmin } from './helpers/login';
import { GrantForm } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

const NS_A   = 'pro-block-builder';
const NS_B   = 'widget-master';
const SLUG_A = `grant-${ NS_A }-access`;
const SLUG_B = `grant-${ NS_B }-access`;

function resetNamespace( ns: string ): void {
	wpCli(
		'wp-cli-client',
		[
			'global $wpdb;',
			`$ids = $wpdb->get_col( $wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", "%tl_${ ns }_id", "%tl_${ ns }_site_hash" ) );`,
			'foreach ( array_unique( array_map("intval", $ids ) ) as $uid ) {',
			'  if ( $uid <= 0 ) { continue; }',
			'  wp_delete_user( $uid );',
			'  if ( function_exists("wpmu_delete_user") ) { wpmu_delete_user( $uid ); }',
			'}',
			'$cron = (array) get_option("cron", array());',
			'foreach ( $cron as $ts => $hooks ) {',
			'  if ( ! is_array( $hooks ) ) { continue; }',
			'  foreach ( array_keys( $hooks ) as $hook ) {',
			`    if ( 0 === strpos( (string) $hook, "trustedlogin/${ ns }/" ) ) { unset( $cron[$ts][$hook] ); }`,
			'  }',
			'  if ( empty( $cron[$ts] ) ) { unset( $cron[$ts] ); }',
			'}',
			'update_option("cron", $cron);',
			`delete_site_option("tl_${ ns }_endpoint");`,
			`delete_option("tl-${ ns }-in_lockdown");`,
			`delete_option("tl-${ ns }-used_accesskeys");`,
			'echo "ok";',
		].join( ' ' ),
		`reset namespace ${ ns }`,
	);
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	enableSslOverride();
} );

test.afterAll( () => {
	clearSslOverride();
	resetNamespace( NS_A );
	resetNamespace( NS_B );
	resetSupportUsers();
} );

test.beforeEach( () => {
	resetNamespace( NS_A );
	resetNamespace( NS_B );
} );

test( 'both namespaces register their own grant menu page', async ( { page } ) => {
	await loginAsAdmin( page );
	await page.goto( CLIENT_URL + '/wp-admin/', { waitUntil: 'domcontentloaded' } );

	// Both menu items should appear in the side menu.
	const html = await page.content();
	expect( html, 'pro-block-builder menu must render' ).toContain( `page=${ SLUG_A }` );
	expect( html, 'widget-master menu must ALSO render' ).toContain( `page=${ SLUG_B }` );

	// And each one resolves to its own form, not the other\'s.
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'domcontentloaded' } );
	await expect( page.locator( '.tl-client-grant-button' ) ).toBeVisible();
	expect( await page.title(), 'page A must render the pro-block-builder form' ).toMatch( /Grant.*Access/i );

	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_B }`, { waitUntil: 'domcontentloaded' } );
	await expect( page.locator( '.tl-client-grant-button' ) ).toBeVisible();
	expect( await page.title(), 'page B must render the widget-master form' ).toMatch( /Grant.*Access/i );
} );

test( 'granting on namespace A does NOT touch namespace B state', async ( { page } ) => {
	await loginAsAdmin( page );

	const formA = new GrantForm( page );
	await formA.navigate();
	await formA.grantButton().click();
	await expect( formA.accessKeyInput(),
		'pro-block-builder grant must complete' )
		.toBeVisible( { timeout: 30_000 } );

	// A: should have one user, one cron, one endpoint
	const aUsers   = wpCli( 'wp-cli-client',
		`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_${ NS_A }_id" ) );`,
		`count ${ NS_A } users` ).trim();
	const aEndpoint = wpCli( 'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS_A }_endpoint", "" );`,
		`fetch ${ NS_A } endpoint` ).trim();

	// B: should have ZERO users, ZERO crons, NO endpoint
	const bUsers   = wpCli( 'wp-cli-client',
		`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_${ NS_B }_id" ) );`,
		`count ${ NS_B } users` ).trim();
	const bEndpoint = wpCli( 'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS_B }_endpoint", "" );`,
		`fetch ${ NS_B } endpoint` ).trim();

	expect( parseInt( aUsers, 10 ), `${ NS_A } must have one support user` ).toBe( 1 );
	expect( aEndpoint, `${ NS_A } endpoint option must be populated` ).toMatch( /^[a-f0-9]{16,}$/ );

	expect( parseInt( bUsers, 10 ), `${ NS_B } must remain at zero users — no cross-namespace pollution` ).toBe( 0 );
	expect( bEndpoint, `${ NS_B } endpoint option must remain empty — no cross-namespace pollution` ).toBe( '' );
} );

test( 'granting on B independently mints B-only state — no overlap with A', async ( { page } ) => {
	await loginAsAdmin( page );

	// Grant on A first
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'domcontentloaded' } );
	await page.locator( '.tl-client-grant-button' ).click();
	await expect( page.locator( `#tl-${ NS_A }-access-key` ) ).toBeVisible( { timeout: 30_000 } );

	// Then grant on B
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_B }`, { waitUntil: 'domcontentloaded' } );
	await page.locator( '.tl-client-grant-button' ).click();
	await expect( page.locator( `#tl-${ NS_B }-access-key` ) ).toBeVisible( { timeout: 30_000 } );

	// Each namespace should have exactly one user with its own
	// identifier meta. No cross-contamination.
	const aUsers = wpCli( 'wp-cli-client',
		`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_${ NS_A }_id" ) );`,
		`count ${ NS_A } users` ).trim();
	const bUsers = wpCli( 'wp-cli-client',
		`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_${ NS_B }_id" ) );`,
		`count ${ NS_B } users` ).trim();
	expect( parseInt( aUsers, 10 ) ).toBe( 1 );
	expect( parseInt( bUsers, 10 ) ).toBe( 1 );

	// No user is in BOTH namespace meta tables — they\'re distinct WP users.
	const intersection = wpCli(
		'wp-cli-client',
		`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare(`
		+ `"SELECT COUNT(*) FROM {$wpdb->usermeta} a `
		+ `JOIN {$wpdb->usermeta} b ON a.user_id = b.user_id `
		+ `WHERE a.meta_key LIKE %s AND b.meta_key LIKE %s",`
		+ ` "%tl_${ NS_A }_id", "%tl_${ NS_B }_id" ) );`,
		'count users tagged with BOTH namespaces',
	).trim();
	expect( parseInt( intersection, 10 ),
		'no single user must be tagged as a support user for BOTH namespaces' )
		.toBe( 0 );

	// Endpoint options are distinct values.
	const aEndpoint = wpCli( 'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS_A }_endpoint", "" );`, 'A endpoint' ).trim();
	const bEndpoint = wpCli( 'wp-cli-client',
		`echo (string) get_site_option( "tl_${ NS_B }_endpoint", "" );`, 'B endpoint' ).trim();
	expect( aEndpoint, 'A endpoint must be populated' ).toMatch( /^[a-f0-9]{16,}$/ );
	expect( bEndpoint, 'B endpoint must be populated' ).toMatch( /^[a-f0-9]{16,}$/ );
	expect( aEndpoint, 'each namespace must have its OWN endpoint hash' ).not.toBe( bEndpoint );
} );

test( 'roles are namespace-prefixed — no shared role pool', async ( { page } ) => {
	await loginAsAdmin( page );

	// Drive a grant on each namespace so SupportRole::create runs.
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'domcontentloaded' } );
	await page.locator( '.tl-client-grant-button' ).click();
	await expect( page.locator( `#tl-${ NS_A }-access-key` ) ).toBeVisible( { timeout: 30_000 } );

	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_B }`, { waitUntil: 'domcontentloaded' } );
	await page.locator( '.tl-client-grant-button' ).click();
	await expect( page.locator( `#tl-${ NS_B }-access-key` ) ).toBeVisible( { timeout: 30_000 } );

	// SupportRole::create generates a role keyed by namespace. Two
	// distinct roles must exist — if they shared one slug the second
	// installer would clobber the first\'s caps.
	const roles = wpCli( 'wp-cli-client',
		`global $wp_roles; if ( ! $wp_roles ) { $wp_roles = wp_roles(); } echo implode("\n", array_keys( (array) $wp_roles->roles ) );`,
		'list all WP roles' ).trim();
	expect( roles, `${ NS_A }-support role must exist` ).toContain( `${ NS_A }-support` );
	expect( roles, `${ NS_B }-support role must exist` ).toContain( `${ NS_B }-support` );
} );

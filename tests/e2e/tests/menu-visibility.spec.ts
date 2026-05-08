/**
 * Browser-driven E2E: Grant Support Access menu visibility by role.
 *
 * Admin.php registers the menu with a `create_users` cap requirement.
 * On multisite, `create_users` is a super-admin-only cap by default
 * — site admins (without grant_super_admin) shouldn\'t see the menu,
 * editors and subscribers shouldn\'t either.
 *
 * The cap-enforcement spec covers what happens AFTER a grant exists
 * (can the support user reach edit.php, etc.). This covers the
 * BEFORE-grant menu gate — admins-of-the-wrong-shape shouldn\'t see
 * a "Grant Support Access" link at all. A regression here (e.g.
 * dropping the cap requirement, or adding it to a hook that runs
 * before super-admin promotion is checked) would let lower-tier
 * roles see UI they shouldn\'t.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { CLIENT_URL } from './helpers/login';
import { NS } from './helpers/grant-form';

const LOGIN_SECRET = 'e2e-only';
const MENU_SLUG    = `grant-${ NS }-access`;

// Helper: tl-test-login as user_id, then GET /wp-admin/. Returns the
// rendered HTML so we can inspect the admin menu.
async function dashboardHtmlAs( context: any, userId: number ): Promise<string> {
	const loginResp = await context.get(
		`${ CLIENT_URL }/tl-test-login?user_id=${ userId }&k=${ LOGIN_SECRET }&redirect=${ encodeURIComponent( '/wp-admin/' ) }`,
		{ maxRedirects: 0 },
	);
	if ( loginResp.status() !== 302 ) {
		throw new Error( `tl-test-login returned ${ loginResp.status() }: ${ ( await loginResp.text() ).slice( 0, 400 ) }` );
	}
	const dashboard = await context.get( CLIENT_URL + '/wp-admin/' );
	if ( ! dashboard.ok() ) {
		throw new Error( `dashboard returned ${ dashboard.status() }` );
	}
	return await dashboard.text();
}

// Helper: create a user with a role and return the new user_id.
function mintUser( role: 'subscriber' | 'editor' | 'administrator', login: string ): number {
	const out = wpCli(
		'wp-cli-client',
		`require_once ABSPATH . "wp-admin/includes/user.php"; `
		+ `if ( username_exists( "${ login }" ) ) { wp_delete_user( get_user_by("login", "${ login }")->ID ); } `
		+ `$uid = wp_create_user( "${ login }", "pw-${ login }-12345", "${ login }@example.test" ); `
		+ `if ( is_wp_error( $uid ) ) { echo "ERR:" . $uid->get_error_code(); exit; } `
		+ `$u = new WP_User( $uid ); $u->set_role( "${ role }" ); `
		+ `if ( is_multisite() ) { add_user_to_blog( get_current_blog_id(), $uid, "${ role }" ); } `
		+ `echo (int) $uid;`,
		`mint ${ role } ${ login }`,
	);
	if ( /^ERR:/.test( out ) ) {
		throw new Error( `mintUser failed: ${ out }` );
	}
	return parseInt( out, 10 );
}

function deleteUser( userId: number ): void {
	wpCli(
		'wp-cli-client',
		`require_once ABSPATH . "wp-admin/includes/user.php"; `
		+ `wp_delete_user( ${ userId } ); `
		+ `if ( function_exists( "wpmu_delete_user" ) ) { wpmu_delete_user( ${ userId } ); } `
		+ `echo "ok";`,
		`delete ${ userId }`,
	);
}

test.describe.configure( { mode: 'serial' } );

let editorId: number;
let subscriberId: number;
let siteAdminId: number;

test.beforeAll( () => {
	editorId     = mintUser( 'editor',        'menu-vis-editor' );
	subscriberId = mintUser( 'subscriber',    'menu-vis-subscriber' );
	siteAdminId  = mintUser( 'administrator', 'menu-vis-siteadmin' );
	// siteAdminId mirrors a multisite site administrator: full
	// administrator role minus create_users (which on real multisite
	// is reserved for super admins via map_meta_cap). The e2e stack
	// runs single-site, so we strip the cap explicitly to reproduce
	// the same authorization shape — otherwise this test would fail
	// on every single-site CI run because regular admins keep
	// create_users by default.
	wpCli(
		'wp-cli-client',
		// add_cap("create_users", false), not remove_cap("create_users") —
		// remove_cap only deletes USER-level cap entries, but admins
		// inherit create_users from the administrator ROLE. Storing the
		// cap as false at the user level overrides the role's true.
		`$u = new WP_User( ${ siteAdminId } ); $u->add_cap( "create_users", false ); echo "ok";`,
		'strip create_users from siteAdminId',
	);
} );

test.afterAll( () => {
	deleteUser( editorId );
	deleteUser( subscriberId );
	deleteUser( siteAdminId );
} );

test( 'super admin (admin/uid=1) → Grant menu visible', async ( { request } ) => {
	const html = await dashboardHtmlAs( request, 1 );
	expect( html, 'super admin must see the Grant Support Access menu link' )
		.toContain( `page=${ MENU_SLUG }` );
} );

test( 'site admin without super-admin → Grant menu hidden', async ( { request } ) => {
	const html = await dashboardHtmlAs( request, siteAdminId );
	expect( html, 'site admin without create_users (multisite) must NOT see the Grant menu' )
		.not.toContain( `page=${ MENU_SLUG }` );
} );

test( 'editor → Grant menu hidden', async ( { request } ) => {
	const html = await dashboardHtmlAs( request, editorId );
	expect( html, 'editor must NOT see the Grant menu — lacks create_users' )
		.not.toContain( `page=${ MENU_SLUG }` );
} );

test( 'subscriber → Grant menu hidden', async ( { request } ) => {
	const html = await dashboardHtmlAs( request, subscriberId );
	expect( html, 'subscriber must NOT see the Grant menu' )
		.not.toContain( `page=${ MENU_SLUG }` );
} );

// And the page itself must reject — defense in depth: even if the
// menu rendering regressed, navigating directly to admin.php?page=…
// must still gate via the menu_page cap.
test( 'editor navigating directly to /wp-admin/admin.php?page=grant-{ns}-access → blocked', async ( { request } ) => {
	const loginResp = await request.get(
		`${ CLIENT_URL }/tl-test-login?user_id=${ editorId }&k=${ LOGIN_SECRET }&redirect=${ encodeURIComponent( '/wp-admin/admin.php?page=' + MENU_SLUG ) }`,
		{ maxRedirects: 0 },
	);
	expect( loginResp.status(), 'tl-test-login must 302 to the redirect target' ).toBe( 302 );

	const target = await request.get( CLIENT_URL + `/wp-admin/admin.php?page=${ MENU_SLUG }` );
	const body = await target.text();
	expect( body, 'editor must hit the WP "you do not have sufficient permissions" gate' )
		.toMatch( /you do not have sufficient permissions|sorry, you are not allowed/i );
} );

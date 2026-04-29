/**
 * E2E: capability enforcement at the WordPress runtime layer.
 *
 * The integration test (tests/test-cap-management.php) already pins the
 * cap matrix on the WP_User: $user->has_cap('edit_posts') === false after
 * caps/remove. This spec extends that one layer up — it drives an actual
 * Apache request as a support user and confirms WordPress's wp-admin
 * gating returns the "not allowed" interstitial for the right URLs.
 *
 * For each scenario we:
 *   1. Use wp-cli to instantiate the SDK (real Config + SupportRole +
 *      SupportUser classes) with a chosen role + caps config, returning
 *      the freshly-minted user_id.
 *   2. Hit the tl-test-login mu-plugin endpoint to put that user_id into
 *      Playwright's cookie jar.
 *   3. Visit specific wp-admin URLs and assert blocked / allowed.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';

const CLIENT_URL    = 'http://localhost:8002';
const LOGIN_SECRET  = 'e2e-only';

// WP's cap-denied interstitial in wp-admin renders the wp_die() page,
// which has a <title>WordPress &rsaquo; Error</title>. Plain wp-admin
// pages don't carry that title. Matching on the title string is much
// more reliable than grepping the body for "Sorry, you are not allowed"
// — that phrase also leaks into JS l10n bundles and false-positives
// pages that actually loaded.
const WP_DIE_TITLE = /<title>WordPress\s*(?:&rsaquo;|›)\s*Error/i;

// Mint a support role + user via the SDK's real classes. Returns the
// support user's ID. Each call uses a unique namespace so role slugs
// don't collide across scenarios.
function mintSupportUser( opts: {
	role: string;
	add?: Record<string, string> | string[];
	remove?: Record<string, string> | string[];
} ): number {
	const ns = 'capns_' + Math.random().toString( 36 ).slice( 2, 10 );

	const phpCaps = JSON.stringify( {
		add:    opts.add    ?? {},
		remove: opts.remove ?? {},
	} );

	const phpSnippet = [
		'$caps_in = json_decode(' + JSON.stringify( phpCaps ) + ', true);',
		'$cfg = new \\TrustedLogin\\Config(array(',
		'  "role"   => ' + JSON.stringify( opts.role ) + ',',
		'  "caps"   => $caps_in,',
		'  "auth"   => array("api_key" => "0123456789abcdef"),',
		'  "vendor" => array(',
		'    "namespace"   => ' + JSON.stringify( ns ) + ',',
		'    "title"       => "Cap Enforcement E2E",',
		'    "email"       => ' + JSON.stringify( ns ) + ' . "@example.test",',
		'    "website"     => "http://localhost:8002",',
		'    "support_url" => "http://localhost:8002/support",',
		'  ),',
		'));',
		'$log  = new \\TrustedLogin\\Logging($cfg);',
		'$role = (new \\TrustedLogin\\SupportRole($cfg, $log))->create();',
		'if (is_wp_error($role)) { echo "ROLE_ERR:" . $role->get_error_code(); exit; }',
		'$uid = (new \\TrustedLogin\\SupportUser($cfg, $log))->create();',
		'if (is_wp_error($uid)) { echo "USER_ERR:" . $uid->get_error_code(); exit; }',
		'echo (int) $uid;',
	].join( ' ' );

	const out = wpCli( 'wp-cli-client', phpSnippet, `mint support user (${ opts.role })` );
	if ( /^(ROLE_ERR|USER_ERR):/.test( out ) ) {
		throw new Error( `mintSupportUser failed: ${ out }` );
	}
	const id = parseInt( out, 10 );
	if ( ! Number.isFinite( id ) || id <= 0 ) {
		throw new Error( `mintSupportUser returned non-numeric: ${ JSON.stringify( out ) }` );
	}
	return id;
}

// Wipe support users + custom roles between tests so every scenario starts
// from a known state. Skipped roles are wp-core defaults we never delete.
function resetState(): void {
	wpCli(
		'wp-cli-client',
		[
			'foreach (get_users(array("number" => -1)) as $u) {',
			'  if ($u->user_login === "admin") { continue; }',
			'  if (preg_match("/cap enforcement e2e support/i", (string) $u->user_login)) { wp_delete_user($u->ID); }',
			'  if (false !== strpos((string) $u->user_email, "capns_")) { wp_delete_user($u->ID); }',
			'}',
			'global $wp_roles;',
			'foreach (array_keys($wp_roles->roles) as $slug) {',
			'  if (0 === strpos($slug, "capns_")) { remove_role($slug); }',
			'}',
			'echo "ok";',
		].join( ' ' ),
		'reset cap-enforcement state',
	);
}

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
	resetState();
} );

test.afterAll( () => {
	resetState();
} );

// Helper: log in as user_id via the test-login endpoint, then GET the
// target URL and return the response body. Uses Playwright's request
// fixture so each test gets an isolated cookie jar.
async function asUser( context: any, userId: number, targetPath: string ): Promise<{ status: number; body: string }> {
	// Step 1: log in. The endpoint sets an auth cookie and 302s to wp-admin.
	const loginResp = await context.get(
		`${ CLIENT_URL }/tl-test-login?user_id=${ userId }&k=${ LOGIN_SECRET }&redirect=${ encodeURIComponent( targetPath ) }`,
		{ maxRedirects: 0 },
	);
	if ( loginResp.status() !== 302 ) {
		throw new Error( `tl-test-login returned ${ loginResp.status() }: ${ ( await loginResp.text() ).slice( 0, 400 ) }` );
	}

	// Step 2: follow the redirect to the target. The auth cookie set on
	// step 1 is in the request context's jar now.
	const targetResp = await context.get( CLIENT_URL + targetPath );
	return { status: targetResp.status(), body: await targetResp.text() };
}

// ---------------------------------------------------------------------------
//  editor minus edit_posts — the canonical scenario from the changelog
// ---------------------------------------------------------------------------

test( 'editor minus edit_posts → /wp-admin/edit.php is blocked', async ( { request } ) => {
	const uid = mintSupportUser( {
		role:   'editor',
		remove: { edit_posts: 'no editing posts during a support session' },
	} );

	const { body } = await asUser( request, uid, '/wp-admin/edit.php' );
	expect( body, 'edit.php must show the "not allowed" interstitial when edit_posts is removed' )
		.toMatch( WP_DIE_TITLE );
} );

test( 'editor minus edit_posts → /wp-admin/edit.php?post_type=page renders (edit_pages survives)', async ( { request } ) => {
	const uid = mintSupportUser( {
		role:   'editor',
		remove: { edit_posts: 'reason' },
	} );

	const { body, status } = await asUser( request, uid, '/wp-admin/edit.php?post_type=page' );
	expect( status, 'pages screen must load' ).toBe( 200 );
	expect( body, 'pages screen must NOT show the not-allowed interstitial' )
		.not.toMatch( WP_DIE_TITLE );
	expect( body ).toMatch( /<title[^>]*>Pages/i );
} );

test( 'editor minus edit_posts (LIST shape) → still blocked from edit.php', async ( { request } ) => {
	// List-shape was the foot-gun documented in the changelog: prior to
	// the fix this would not actually remove the cap, and edit.php would
	// load. After the fix the list shape is normalized identically to
	// the assoc shape.
	const uid = mintSupportUser( {
		role:   'editor',
		remove: [ 'edit_posts' ],
	} );

	const { body } = await asUser( request, uid, '/wp-admin/edit.php' );
	expect( body, 'list-shape caps/remove must be honoured at runtime' )
		.toMatch( WP_DIE_TITLE );
} );

// ---------------------------------------------------------------------------
//  editor (vanilla) — sanity that the support clone has the editor caps
// ---------------------------------------------------------------------------

test( 'editor clone (no overrides) → can reach edit.php', async ( { request } ) => {
	const uid = mintSupportUser( { role: 'editor' } );
	const { body, status } = await asUser( request, uid, '/wp-admin/edit.php' );
	expect( status ).toBe( 200 );
	expect( body ).not.toMatch( WP_DIE_TITLE );
	expect( body ).toMatch( /<title[^>]*>Posts/i );
} );

// ---------------------------------------------------------------------------
//  administrator clone — admin caps survive, but prevented_caps don't
// ---------------------------------------------------------------------------

test( 'administrator clone → can reach options-general.php', async ( { request } ) => {
	const uid = mintSupportUser( { role: 'administrator' } );
	const { status, body } = await asUser( request, uid, '/wp-admin/options-general.php' );
	expect( status ).toBe( 200 );
	expect( body, 'admin clone should reach General Settings' ).not.toMatch( WP_DIE_TITLE );
} );

test( 'administrator clone → CANNOT reach users.php (list_users is a prevented_cap)', async ( { request } ) => {
	const uid = mintSupportUser( { role: 'administrator' } );
	const { body } = await asUser( request, uid, '/wp-admin/users.php' );
	expect( body, 'list_users is on SupportRole::$prevented_caps; users.php must be blocked' )
		.toMatch( WP_DIE_TITLE );
} );

test( 'administrator clone → CANNOT reach users.php?action=add (create_users prevented)', async ( { request } ) => {
	const uid = mintSupportUser( { role: 'administrator' } );
	const { body } = await asUser( request, uid, '/wp-admin/user-new.php' );
	expect( body, 'create_users is prevented; the add-user screen must refuse' )
		.toMatch( WP_DIE_TITLE );
} );

// ---------------------------------------------------------------------------
//  subscriber clone — should NOT see the post listing
// ---------------------------------------------------------------------------

test( 'subscriber clone → CANNOT reach edit.php', async ( { request } ) => {
	const uid = mintSupportUser( { role: 'subscriber' } );
	const { body } = await asUser( request, uid, '/wp-admin/edit.php' );
	expect( body, 'subscriber should not be able to list posts' )
		.toMatch( WP_DIE_TITLE );
} );

// ---------------------------------------------------------------------------
//  Role refresh — second grant with tighter caps must take effect
// ---------------------------------------------------------------------------

test( 'role refresh: second grant with tighter caps blocks the previously-allowed page', async ( { request }, testInfo ) => {
	// This test makes two wp-cli calls (each spawns a fresh wp-cli
	// container, ~5s) and two HTTP nav rounds (login + form GET). When
	// the suite has run for a while, wp-cli\'s WP-bootstrap cost drifts
	// up enough that the default 60s timeout is too tight. Bump just
	// this test instead of slowing the global default.
	testInfo.setTimeout( 120_000 );

	// First grant: editor, edit_posts intact. Confirm post listing renders.
	const ns = 'capns_refresh_' + Math.random().toString( 36 ).slice( 2, 8 );
	const phpFirst = [
		'$cfg = new \\TrustedLogin\\Config(array(',
		'  "role" => "editor",',
		'  "caps" => array(),',
		'  "auth" => array("api_key" => "0123456789abcdef"),',
		'  "vendor" => array(',
		'    "namespace"   => ' + JSON.stringify( ns ) + ',',
		'    "title"       => "Refresh E2E",',
		'    "email"       => ' + JSON.stringify( ns ) + ' . "@example.test",',
		'    "website"     => "http://localhost:8002",',
		'    "support_url" => "http://localhost:8002/support",',
		'  ),',
		'));',
		'$log = new \\TrustedLogin\\Logging($cfg);',
		'(new \\TrustedLogin\\SupportRole($cfg, $log))->create();',
		'$uid = (new \\TrustedLogin\\SupportUser($cfg, $log))->create();',
		'echo (int) $uid;',
	].join( ' ' );
	const uid = parseInt( wpCli( 'wp-cli-client', phpFirst, 'first grant' ), 10 );

	let r = await asUser( request, uid, '/wp-admin/edit.php' );
	expect( r.body, 'first grant should NOT block edit.php' ).not.toMatch( WP_DIE_TITLE );

	// Second grant: same namespace (so same role slug), but caps/remove
	// now strips edit_posts. The reconcile path in SupportRole::create
	// must update the existing role.
	const phpSecond = [
		'$cfg = new \\TrustedLogin\\Config(array(',
		'  "role" => "editor",',
		'  "caps" => array("remove" => array("edit_posts" => "second-grant tightened")),',
		'  "auth" => array("api_key" => "0123456789abcdef"),',
		'  "vendor" => array(',
		'    "namespace"   => ' + JSON.stringify( ns ) + ',',
		'    "title"       => "Refresh E2E",',
		'    "email"       => ' + JSON.stringify( ns ) + ' . "@example.test",',
		'    "website"     => "http://localhost:8002",',
		'    "support_url" => "http://localhost:8002/support",',
		'  ),',
		'));',
		'$log  = new \\TrustedLogin\\Logging($cfg);',
		'$role = (new \\TrustedLogin\\SupportRole($cfg, $log))->create();',
		'echo "ok";',
	].join( ' ' );
	wpCli( 'wp-cli-client', phpSecond, 'second grant tighter' );

	r = await asUser( request, uid, '/wp-admin/edit.php' );
	expect( r.body, 'after tighter second grant, edit.php must now be blocked' )
		.toMatch( WP_DIE_TITLE );
} );

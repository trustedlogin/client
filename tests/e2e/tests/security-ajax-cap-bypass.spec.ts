/**
 * Security E2E: a non-admin user with a valid nonce must NOT be
 * able to mint a support user via direct admin-ajax POST.
 *
 * Threat model: an editor (or any role lacking `create_users`) is
 * the lowest-privilege user with WP-admin access. If they could POST
 * to admin-ajax.php?action=tl_<ns>_gen_support and bypass the cap
 * check, they\'d escalate to creating administrator-equivalent users.
 *
 * Two layers stand between editor → granted access:
 *   1. The localized nonce: `tl_nonce-<user_id>`. The form only
 *      renders for users with create_users, so an editor never sees
 *      the page that prints the nonce. But an editor can mint their
 *      own nonce via `wp_create_nonce` (it\'s public; the secret is
 *      the action string + the editor\'s session_token).
 *   2. The AJAX handler\'s `current_user_can('create_users')` check
 *      at Ajax.php:121. THIS is the load-bearing gate.
 *
 * The test arms the attacker with the strongest possible input — a
 * valid same-user nonce — and verifies the cap check rejects.
 */

import { test, expect } from '@playwright/test';
import { wpCli } from './_helpers';
import { CLIENT_URL } from './helpers/login';
import { NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

const LOGIN_SECRET = 'e2e-only';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => enableSslOverride() );
test.afterAll( () => { clearSslOverride(); resetSupportUsers(); } );
test.beforeEach( () => resetSupportUsers() );

test( 'editor with valid nonce → admin-ajax rejects, no support user minted', async ( { request } ) => {
	// 1. Mint an editor user. wp-cli idempotently replaces if a
	//    previous run left one behind.
	const editorId = parseInt( wpCli(
		'wp-cli-client',
		`require_once ABSPATH . "wp-admin/includes/user.php"; `
		// On multisite, wp_delete_user only removes from the current
		// site; the user persists at the network level. Use
		// wpmu_delete_user to fully clear stale fixtures.
		+ `if ( $existing = get_user_by("login", "ajax-bypass-editor") ) { `
		+ `  wp_delete_user( $existing->ID ); `
		+ `  if ( function_exists( "wpmu_delete_user" ) ) { wpmu_delete_user( $existing->ID ); } `
		+ `} `
		+ `$uid = wp_create_user( "ajax-bypass-editor", "pw-bypass-12345", "ajax-bypass@example.test" ); `
		+ `if ( is_wp_error( $uid ) ) { echo "ERR:" . $uid->get_error_code() . ":" . $uid->get_error_message(); exit; } `
		+ `$u = new WP_User( $uid ); $u->set_role( "editor" ); `
		+ `if ( is_multisite() ) { add_user_to_blog( get_current_blog_id(), $uid, "editor" ); } `
		+ `echo (int) $uid;`,
		'mint editor user',
	).trim(), 10 );
	expect( editorId ).toBeGreaterThan( 0 );

	// 2. Login the editor via the cookie-jar shortcut (auth path
	//    isn\'t what we\'re testing — the cap gate on the AJAX is).
	const loginResp = await request.get(
		`${ CLIENT_URL }/tl-test-login?user_id=${ editorId }&k=${ LOGIN_SECRET }&redirect=${ encodeURIComponent( '/wp-admin/' ) }`,
		{ maxRedirects: 0 },
	);
	expect( loginResp.status(), 'tl-test-login must 302' ).toBe( 302 );

	// 3. Mint a real `tl_nonce-<editorId>` via the same session — WP
	//    nonces are session-token-bound, so generating one in wp-cli
	//    (no session) wouldn\'t pass check_ajax_referer. The harness
	//    endpoint runs IN the request context with the cookie jar so
	//    wp_get_session_token() returns the editor\'s real token.
	const nonceResp = await request.get( `${ CLIENT_URL }/?tl_test_mint_nonce=1` );
	expect( nonceResp.status(), 'nonce mint endpoint must respond 200' ).toBe( 200 );
	const nonceBody = await nonceResp.json();
	expect( nonceBody.user_id, 'nonce minter must see the editor as current user' ).toBe( editorId );
	const editorNonce = nonceBody.nonce;
	expect( editorNonce, 'nonce must be 10-char string' ).toMatch( /^[a-f0-9]{10}$/ );

	// 4. POST to admin-ajax.php as the editor with the right shape.
	const ajaxResp = await request.post(
		`${ CLIENT_URL }/wp-admin/admin-ajax.php`,
		{
			form: {
				action:     `tl_${ NS }_gen_support`,
				vendor:     NS,
				_nonce:     editorNonce,
				reference_id: 'editor-bypass-' + Date.now(),
			},
		}
	);

	const body = await ajaxResp.text();

	// AJAX returns 200 with `{ success: false, data: { message: ... } }`
	// on cap failure (wp_send_json_error). Status 4xx is acceptable
	// too if the handler ever changes shape.
	expect( body, 'editor must be told they lack create_users' )
		.toMatch( /not have the ability to create users|create_users|do not have permission|sufficient permission/i );

	// 5. CRITICAL: no support user minted. If the cap check were
	//    bypassed, this is where it would show.
	const userCount = wpCli(
		'wp-cli-client',
		'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "%tl_' + NS + '_id" ) );',
		'count support users after editor bypass attempt',
	).trim();
	expect( parseInt( userCount, 10 ),
		'editor with valid nonce MUST NOT mint a support user — cap bypass = privilege escalation' )
		.toBe( 0 );

	// And no expire-cron event scheduled either.
	const cronCount = wpCli(
		'wp-cli-client',
		'$cron = (array) get_option("cron", array()); $n = 0; foreach ($cron as $hooks) { foreach (array_keys((array) $hooks) as $h) { if ("trustedlogin/' + NS + '/access/revoke" === $h) { $n++; } } } echo (int) $n;',
		'count expire crons after editor bypass attempt',
	).trim();
	expect( parseInt( cronCount, 10 ),
		'no cron should be scheduled when the cap gate rejects' )
		.toBe( 0 );

	// Cleanup the editor — the spec\'s afterAll resetSupportUsers
	// only sweeps support users, not regular WP users.
	wpCli(
		'wp-cli-client',
		`require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user( ${ editorId } ); if ( function_exists( "wpmu_delete_user" ) ) { wpmu_delete_user( ${ editorId } ); } echo "ok";`,
		'cleanup editor',
	);
} );

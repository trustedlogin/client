/**
 * E2E: SaaS-mediated login-attempt feedback.
 *
 * Replaces the old wp-login.php?tl_error=… override flow. Verifies:
 *   - Customer site POSTs to fake-saas /api/v1/sites/{secret_id}/login-attempts
 *     with the expected body shape (no secret_id leak; identifier_hash is
 *     SHA-256, not plaintext).
 *   - On 201 + trusted referer, the browser is redirected back to the
 *     referer with ?tl_attempt=lpat_…
 *   - On any failure (5xx, 429, untrusted referer, audit disabled,
 *     security_check_failed branch with no user) renders the standalone
 *     wp_die() page on the customer site — NOT wp-login.php.
 *   - The trustedlogin/{ns}/login_feedback/allowed_referer_urls filter
 *     still gates which referers are trusted.
 */

import { test, expect, BrowserContext, Page } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli, resetClientState as resetClientStateShared } from './_helpers';

// ---------------------------------------------------------------------------
//  Fixtures + named constants (no magic numbers)
// ---------------------------------------------------------------------------

type VendorState = {
	form_id:       string;
	form_page_url: string;
	namespace:     string;
	client_url:    string;
	vendor_url:    string;
};

const VENDOR_STATE: VendorState = JSON.parse(
	fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

/** fake-saas listens on host port 8003 (see docker-compose.yml). */
const FAKE_SAAS_HOST_URL = 'http://localhost:8003';

/** SaaS returns this attempt id on the happy path (matches server.php fixture). */
const FIXED_LPAT_ID = 'lpat_a1a5bea0-372a-47ca-8090-2f36ad870abc';

/**
 * Escape every regex metacharacter so a literal string built from a
 * URL can be embedded inside a `new RegExp(...)` source. The fixture
 * URLs (`http://localhost:8002`, etc.) contain `.` and `:` which
 * would otherwise match more than the literal value.
 */
function escapeRegex( s: string ): string {
	return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

/**
 * Allowed `mode` values for the fake-saas /__login-attempts-mode toggle.
 * Mirror the LOGIN_ATTEMPT_MODE_* constants in fake-saas/server.php.
 */
const FAKE_SAAS_MODE = {
	OK:           'ok',
	RATE_LIMITED: 'rate_limited',
	SERVER_ERROR: 'server_error',
} as const;
type FakeSaasMode = typeof FAKE_SAAS_MODE[ keyof typeof FAKE_SAAS_MODE ];

/** UUID-shaped check used in the redirect URL match. */
const LPAT_REGEX = /tl_attempt=lpat_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/;

const STANDALONE_HEADING = 'Support login could not complete';
// wp_die() puts the second-arg "title" only in <title>, not in <h1> —
// so body.innerText assertions need to match the message paragraph instead.
const STANDALONE_BODY = 'Return to your support tool to try again.';

// Test wait budgets (in milliseconds) — extracted so a slow CI box can
// nudge them in one place.
const NAV_TIMEOUT_MS    = 15_000;
const QUERY_TIMEOUT_MS  = 30_000;

// ---------------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------------

async function loginClientAdmin( ctx: BrowserContext ) {
	const p = await ctx.newPage();
	await p.goto( `${ VENDOR_STATE.client_url }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	if ( ! p.url().includes( 'wp-login.php' ) ) { await p.close(); return; }
	await p.locator( '#user_login' ).fill( 'admin' );
	await p.locator( '#user_pass' ).fill( 'admin' );
	await Promise.all( [
		p.waitForURL( /\/wp-admin\//, { timeout: NAV_TIMEOUT_MS } ),
		p.locator( '#wp-submit' ).click(),
	] );
	await p.close();
}

const resetClientState = resetClientStateShared;

/**
 * Drive a full grant flow so the client site has a support user
 * with the meta needed for fail_login() to recover secret_id.
 *
 * Returns endpoint + identifier captured from the vendor side.
 */
async function grantAndCaptureSecrets( ctx: BrowserContext ): Promise<{
	key:        string;
	endpoint:   string;
	identifier: string;
}> {
	await loginClientAdmin( ctx );

	const vp = await ctx.newPage();
	await vp.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
	await vp.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: NAV_TIMEOUT_MS } );
	await vp.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
	await vp.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

	let popup: Page | undefined;
	const popupPromise = ctx.waitForEvent( 'page' ).then( p => { popup = p; } );
	await vp.locator( '.tl-grant-access input[type="submit"]' ).click();
	await popupPromise;

	// The popup is best-effort: in some grant flows it auto-closes
	// before we can attach to it (postMessage path), and in others
	// the button isn't yet rendered. Both are recoverable — the
	// site_key element below is the actual signal we wait for. Log
	// the swallowed errors so a real regression doesn't hide here.
	await popup!.waitForLoadState( 'domcontentloaded' ).catch( ( e: Error ) => {
		// eslint-disable-next-line no-console
		console.warn( '[grant] popup load skipped:', e.message );
	} );
	await popup!.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click().catch( ( e: Error ) => {
		// eslint-disable-next-line no-console
		console.warn( '[grant] popup button click skipped:', e.message );
	} );

	await vp.waitForFunction( () => {
		const el = document.querySelector( '.tl-site-key' );
		return el && el.textContent && el.textContent.length > 10;
	}, null, { timeout: QUERY_TIMEOUT_MS } );
	const key = await vp.evaluate( () => document.querySelector( '.tl-site-key' )!.textContent! );
	await vp.close();

	const out = wpCli(
		'wp-cli-vendor',
		`wp_set_current_user( get_user_by( "login", "admin" )->ID );`
		+ `$parts = ( new \\TrustedLogin\\Vendor\\AccessKeyLogin() )->handle( array(`
		+ `  \\TrustedLogin\\Vendor\\AccessKeyLogin::ACCOUNT_ID_INPUT_NAME => "999",`
		+ `  \\TrustedLogin\\Vendor\\AccessKeyLogin::ACCESS_KEY_INPUT_NAME => ${ JSON.stringify( key ) },`
		+ `) );`
		+ `if ( is_wp_error( $parts ) ) { echo "ERR:" . $parts->get_error_code() . ":" . $parts->get_error_message(); exit; }`
		+ `$first = reset( $parts );`
		+ `echo $first["endpoint"] . "|" . $first["identifier"];`,
		'grantAndCaptureSecrets:handle',
	);
	if ( out.startsWith( 'ERR:' ) ) {
		throw new Error( 'AccessKeyLogin::handle() failed: ' + out );
	}
	const [ endpoint, identifier ] = out.split( '|' );
	if ( ! endpoint || ! identifier ) {
		throw new Error( 'Failed to parse handle() output: ' + out );
	}
	return { key, endpoint, identifier };
}

/**
 * Force the matched support user into a state where SupportUser::maybe_login()
 * fails. Easiest reliable trigger: zero out the expires meta so is_active()
 * returns false and maybe_login returns WP_Error('access_expired'). This is
 * the production path that lands at the login_failed call site.
 */
function expireSupportUser(): void {
	// SupportUser writes the expires meta via update_user_option(...,
	// $global=false), which stores at the per-blog-prefixed key
	// (wp_tl_pro-block-builder_expires). To force expiration we have
	// to write to the SAME key shape — matching SupportUser's call —
	// or get_user_option will keep reading the original future
	// timestamp from the prefixed slot.
	const out = wpCli(
		'wp-cli-client',
		`require_once ABSPATH . "wp-admin/includes/user.php"; `
		+ `$users = get_users( array( "meta_key" => "tl_pro-block-builder_id", "number" => 1 ) ); `
		+ `if ( empty( $users ) ) { echo "no_user"; exit; } `
		+ `$user = $users[0]; `
		+ `update_user_option( $user->ID, "tl_pro-block-builder_expires", 1 ); `
		+ `wp_cache_flush(); `
		+ `$readback = get_user_option( "tl_pro-block-builder_expires", $user->ID ); `
		+ `echo "expired:user_id=" . $user->ID . ":readback=" . var_export( $readback, true );`,
		'expireSupportUser',
	);
	if ( ! out.startsWith( 'expired:' ) ) {
		throw new Error( `expireSupportUser failed: ${ out }` );
	}
}

/**
 * Set fake-saas response mode for the next /login-attempts POST.
 */
function setFakeSaasMode( mode: FakeSaasMode ): void {
	execSync(
		`curl -fsS -X POST -H 'Content-Type: application/json' `
		+ `-d '${ JSON.stringify( { mode } ) }' `
		+ `${ FAKE_SAAS_HOST_URL }/__login-attempts-mode >/dev/null`,
		{ timeout: 5_000 },
	);
}

/**
 * Fetch the in-memory request log of every /login-attempts POST the
 * fake-saas has seen since the last reset. Each entry has secret_id +
 * body + time.
 */
function readFakeSaasAttempts(): Array<{ secret_id: string; body: any; time: number }> {
	const stdout = execSync(
		`curl -fsS ${ FAKE_SAAS_HOST_URL }/__login-attempts`,
		{ timeout: 5_000, encoding: 'utf8' },
	);
	const parsed = JSON.parse( stdout );
	return parsed.requests || [];
}

/**
 * POST endpoint+identifier into the client site's TrustedLogin handler
 * via a real form submission so the resulting redirect is a navigation
 * the browser actually follows. Returns the page after the redirect
 * settles.
 */
async function submitTrustedLoginForm(
	page: Page,
	endpoint: string,
	identifier: string,
): Promise<void> {
	await page.goto( 'about:blank' );

	// Wait for the navigation triggered by form.submit() so callers can
	// reason about the post-submit DOM. Without this, waitForFunction
	// against body.innerText polls about:blank's empty body and times out.
	await Promise.all( [
		page.waitForLoadState( 'load', { timeout: NAV_TIMEOUT_MS } ),
		page.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
			<input name="action" value="trustedlogin">
			<input name="endpoint" value="${ endpoint }">
			<input name="identifier" value="${ identifier }">
		</form><script>document.getElementById('f').submit();</script>` ),
	] );
}

// ---------------------------------------------------------------------------
//  Test setup
// ---------------------------------------------------------------------------

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
	resetClientState();
	setFakeSaasMode( FAKE_SAAS_MODE.OK );
} );

// ---------------------------------------------------------------------------
//  Tests — happy path
// ---------------------------------------------------------------------------

test( 'login_failed → POSTs to SaaS, redirects to vendor with ?tl_attempt=lpat_…', async ( { browser } ) => {
	const grantCtx = await browser.newContext();
	const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
	await grantCtx.close();

	// Force SupportUser::maybe_login() into the access_expired branch.
	expireSupportUser();

	// Fresh agent context — no client cookies. POST with an HTTP_REFERER
	// of the vendor site so resolve_safe_referer() trusts it.
	const agentCtx = await browser.newContext( {
		extraHTTPHeaders: { Referer: VENDOR_STATE.vendor_url },
	} );
	const p = await agentCtx.newPage();

	await submitTrustedLoginForm( p, endpoint, identifier );

	// Wait for the redirect to the vendor URL with ?tl_attempt=lpat_…
	await p.waitForURL( LPAT_REGEX, { timeout: NAV_TIMEOUT_MS } );

	expect( p.url() ).toContain( FIXED_LPAT_ID );
	expect( p.url() ).toContain( VENDOR_STATE.vendor_url );

	// fake-saas saw exactly one POST, body shape is correct
	const attempts = readFakeSaasAttempts();
	expect( attempts.length ).toBe( 1 );

	const req = attempts[ 0 ];
	expect( req.secret_id ).toMatch( /^[a-f0-9]+$/ ); // in URL, not body
	expect( req.body.secret_id ).toBeUndefined();
	expect( req.body.code ).toBe( 'login_failed' );
	// home_url() may or may not include the trailing slash depending on
	// WP's permalink settings — accept either form. Escape ALL regex
	// metacharacters in the URL (not just /) so dots in the host don't
	// match arbitrary chars.
	expect( req.body.client_site_url ).toMatch(
		new RegExp( '^' + escapeRegex( VENDOR_STATE.client_url ) + '/?$' ),
	);
	expect( req.body.attempted_at ).toMatch( /^\d{4}-\d{2}-\d{2}T/ );

	// identifier_hash is SHA-256 of site_identifier_hash (NOT $user_identifier),
	// so we can't compare to a known value — but it MUST be 64 hex chars and
	// MUST NOT match the plaintext identifier or its hash.
	expect( req.body.identifier_hash ).toMatch( /^[a-f0-9]{64}$/ );
	expect( req.body.identifier_hash ).not.toBe( identifier );

	await agentCtx.close();
} );

// ---------------------------------------------------------------------------
//  Tests — fall-throughs to standalone page
// ---------------------------------------------------------------------------

test( 'login_failed with untrusted referer → standalone page, NO redirect', async ( { browser } ) => {
	const grantCtx = await browser.newContext();
	const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
	await grantCtx.close();
	expireSupportUser();

	// Use APIRequestContext (no browser nav) — the about:blank-form-submit
	// helper lands on chrome-error://chromewebdata when extraHTTPHeaders
	// supplies a cross-origin Referer that doesn't match the page origin,
	// so the standalone page never gets a chance to render. Pure HTTP
	// POST sends the spoofed Referer cleanly and lets the SDK decide.
	const agentCtx = await browser.newContext();
	const resp = await agentCtx.request.post( VENDOR_STATE.client_url + '/', {
		maxRedirects: 0,
		headers: { Referer: 'http://attacker.example/path' },
		form: { action: 'trustedlogin', endpoint, identifier },
	} );

	// Security invariant: server MUST NOT redirect to anything when the
	// Referer is untrusted. status=200 is the strongest possible "no
	// redirect happened" check; the Location-header assertions below
	// are belt-and-suspenders for clarity.
	expect( resp.status(), 'must NOT redirect on untrusted referer' ).toBe( 200 );
	const location = resp.headers()[ 'location' ] ?? '';
	expect( location, 'no tl_attempt redirect' ).not.toMatch( LPAT_REGEX );
	expect( location, 'no redirect to attacker-controlled host' ).not.toContain( 'attacker.example' );
	const body = await resp.text();
	expect( body, 'standalone page must include the wp_die heading' ).toContain( STANDALONE_HEADING );

	// SaaS still got the POST — fail_login records before checking referer.
	const attempts = readFakeSaasAttempts();
	expect( attempts.length ).toBe( 1 );
	expect( attempts[ 0 ].body.code ).toBe( 'login_failed' );

	await agentCtx.close();
} );

test( 'security_check_failed (no user) → standalone page, NO SaaS POST', async ( { browser } ) => {
	// Random identifier matches no support user — verify() rejects BEFORE
	// the user lookup runs. fail_login is called with $user = null, so
	// secret_id can't be recovered, the SaaS POST is skipped, and we
	// fall through to the standalone page.
	const ctx = await browser.newContext();
	const { endpoint } = await grantAndCaptureSecrets( ctx );
	await ctx.close();

	const unknownIdentifier = wpCli(
		'wp-cli-client',
		`echo bin2hex( random_bytes( 64 ) );`,
		'random unknown identifier',
	);
	expect( unknownIdentifier ).toMatch( /^[a-f0-9]{128}$/ );

	const agentCtx = await browser.newContext( {
		extraHTTPHeaders: { Referer: VENDOR_STATE.vendor_url },
	} );
	const p = await agentCtx.newPage();

	await submitTrustedLoginForm( p, endpoint, unknownIdentifier );

	await p.waitForFunction(
		( heading ) => document.body && document.body.innerText.indexOf( heading ) !== -1,
		STANDALONE_BODY,
		{ timeout: NAV_TIMEOUT_MS },
	);

	// Critical: NO SaaS POST happened — we couldn't compute secret_id.
	const attempts = readFakeSaasAttempts();
	expect( attempts.length ).toBe( 0 );

	await agentCtx.close();
} );

test( 'SaaS 5xx → standalone page (lost report, agent unblocked)', async ( { browser } ) => {
	const grantCtx = await browser.newContext();
	const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
	await grantCtx.close();
	expireSupportUser();
	setFakeSaasMode( FAKE_SAAS_MODE.SERVER_ERROR );

	const agentCtx = await browser.newContext( {
		extraHTTPHeaders: { Referer: VENDOR_STATE.vendor_url },
	} );
	const p = await agentCtx.newPage();

	await submitTrustedLoginForm( p, endpoint, identifier );

	await p.waitForFunction(
		( heading ) => document.body && document.body.innerText.indexOf( heading ) !== -1,
		STANDALONE_BODY,
		{ timeout: NAV_TIMEOUT_MS },
	);

	// fake-saas DID receive the POST — it just rejected with 500.
	const attempts = readFakeSaasAttempts();
	expect( attempts.length ).toBe( 1 );

	// Agent saw the standalone page, NOT a redirect.
	expect( p.url() ).not.toMatch( LPAT_REGEX );

	await agentCtx.close();
} );

test( 'SaaS 429 → standalone page (rate-limit eaten, no spam)', async ( { browser } ) => {
	const grantCtx = await browser.newContext();
	const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
	await grantCtx.close();
	expireSupportUser();
	setFakeSaasMode( FAKE_SAAS_MODE.RATE_LIMITED );

	const agentCtx = await browser.newContext( {
		extraHTTPHeaders: { Referer: VENDOR_STATE.vendor_url },
	} );
	const p = await agentCtx.newPage();

	await submitTrustedLoginForm( p, endpoint, identifier );

	await p.waitForFunction(
		( heading ) => document.body && document.body.innerText.indexOf( heading ) !== -1,
		STANDALONE_BODY,
		{ timeout: NAV_TIMEOUT_MS },
	);

	expect( p.url() ).not.toMatch( LPAT_REGEX );

	await agentCtx.close();
} );

test( 'standalone page returns HTTP 200 (so browser does not show its own error chrome)', async ( { browser } ) => {
	const grantCtx = await browser.newContext();
	const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
	await grantCtx.close();
	expireSupportUser();
	setFakeSaasMode( FAKE_SAAS_MODE.SERVER_ERROR );

	const agentCtx = await browser.newContext( {
		extraHTTPHeaders: { Referer: VENDOR_STATE.vendor_url },
	} );
	const p = await agentCtx.newPage();

	// Capture the response of the navigation initiated by the form post.
	const respPromise = p.waitForResponse( resp =>
		resp.url().startsWith( VENDOR_STATE.client_url ) && resp.request().method() !== 'OPTIONS'
	);

	await submitTrustedLoginForm( p, endpoint, identifier );

	const resp = await respPromise;
	// 200 (the standalone-page status) — not 4xx or 5xx.
	expect( resp.status() ).toBe( 200 );

	await agentCtx.close();
} );

// ---------------------------------------------------------------------------
//  Tests — wp-login.php?tl_error=… is REMOVED
// ---------------------------------------------------------------------------

test( 'crafted wp-login.php?tl_error= URL no longer surfaces a banner', async ( { browser } ) => {
	const ctx = await browser.newContext();
	const p = await ctx.newPage();

	// The old override flow read this query param; it's been deleted.
	// The page should render WordPress's vanilla login form.
	await p.goto(
		`${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin`
		+ `&ns=${ VENDOR_STATE.namespace }&tl_error=security_check_failed`,
		{ waitUntil: 'domcontentloaded' },
	);

	// No TrustedLogin error banner should appear.
	const bannerCount = await p.locator( '.tl-login-feedback, #login_error.tl-login-feedback--error' ).count();
	expect( bannerCount ).toBe( 0 );

	await ctx.close();
} );

// ---------------------------------------------------------------------------
//  Tests — opt-out via TRUSTEDLOGIN_DISABLE_AUDIT_{NS} constant
// ---------------------------------------------------------------------------

test( 'TRUSTEDLOGIN_DISABLE_AUDIT_{NS} = true → standalone page, NO POST', async ( { browser } ) => {
	const grantCtx = await browser.newContext();
	const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
	await grantCtx.close();
	expireSupportUser();

	// Drop a one-shot mu-plugin via `docker compose exec` as root —
	// wp-cli-client runs as www-data (uid 33) and can't write to
	// mu-plugins/, which is created at root permissions when the WP
	// image initializes the volume.
	execSync(
		`docker compose exec -T --user root client-wp sh -c `
		+ `'echo "<?php define( \\"TRUSTEDLOGIN_DISABLE_AUDIT_PRO-BLOCK-BUILDER\\", true );" > /var/www/html/wp-content/mu-plugins/tl-disable-audit.php'`,
		{ cwd: path.join( __dirname, '..' ), encoding: 'utf8', timeout: 10_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
	);

	const agentCtx = await browser.newContext( {
		extraHTTPHeaders: { Referer: VENDOR_STATE.vendor_url },
	} );

	// try/finally so the mu-plugin + browser context are always torn
	// down even if an assertion fails mid-test — otherwise a stray
	// `tl-disable-audit.php` poisons every subsequent test.
	try {
		const p = await agentCtx.newPage();

		await submitTrustedLoginForm( p, endpoint, identifier );

		await p.waitForFunction(
			( heading ) => document.body && document.body.innerText.indexOf( heading ) !== -1,
			STANDALONE_BODY,
			{ timeout: NAV_TIMEOUT_MS },
		);

		// Critical: SaaS got NO POST — the constant short-circuits.
		const attempts = readFakeSaasAttempts();
		expect( attempts.length ).toBe( 0 );
	} finally {
		// Cleanup runs whether or not the test body threw. Same root-shell
		// trick as the install above.
		execSync(
			`docker compose exec -T --user root client-wp rm -f /var/www/html/wp-content/mu-plugins/tl-disable-audit.php`,
			{ cwd: path.join( __dirname, '..' ), encoding: 'utf8', timeout: 10_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
		);

		await agentCtx.close();
	}
} );

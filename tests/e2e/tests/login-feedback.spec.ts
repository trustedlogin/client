/**
 * E2E: login-attempt feedback.
 *
 * Covers the tl_error / tl_notice UX added to Endpoint::maybe_login_support()
 * and Form::admin_notice_login_outcome(). Verifies both the POSITIVE signals
 * (success + already-logged-in notices on wp-admin) and the SECURITY posture
 * (malformed probes stay silent; crafted tl_error URLs don't surface fake
 * messages).
 */

import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { execSync } from 'child_process';

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

const E2E_DIR = path.resolve( __dirname, '..' );

async function loginClientAdmin( ctx: BrowserContext ) {
    const p = await ctx.newPage();
    await p.goto( `${ VENDOR_STATE.client_url }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    if ( ! p.url().includes( 'wp-login.php' ) ) { await p.close(); return; }
    await p.locator( '#user_login' ).fill( 'admin' );
    await p.locator( '#user_pass' ).fill( 'admin' );
    await Promise.all( [
        p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        p.locator( '#wp-submit' ).click(),
    ] );
    await p.close();
}

/**
 * Reset the client site to a clean baseline: no support users, no lockdown,
 * no used-accesskey cache, no stale endpoint, no stale feedback transient.
 *
 * Utils::set_transient in trustedlogin-client writes to OPTIONS (not WP core's
 * transient API), so delete_transient() is insufficient — we delete the
 * underlying site_option rows directly.
 */
function resetClientState() {
    try {
        execSync(
            `docker compose run --rm -T wp-cli-client wp eval '`
            + `require_once ABSPATH . "wp-admin/includes/user.php";`
            + `foreach ( get_users( array( "meta_key" => "tl_pro-block-builder_id" ) ) as $u ) { wp_delete_user( $u->ID ); }`
            + `delete_site_option( "tl-pro-block-builder-in_lockdown" );`
            + `delete_site_option( "tl-pro-block-builder-used_accesskeys" );`
            + `delete_site_option( "tl_pro-block-builder_endpoint" );`
            + `delete_site_option( "trustedlogin_pro-block-builder_login_error" );`
            + `echo "ok";`
            + `'`,
            { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 20000 }
        );
    } catch ( e ) { /* best effort */ }

    // Also wipe fake-saas state so old envelopes don't match new grants.
    try {
        execSync( `curl -sS -X POST http://localhost:8003/__reset >/dev/null`, { timeout: 5000 } );
    } catch ( e ) { /* best effort */ }
}

/**
 * Drive a full grant flow against the GF form page so the client site has
 * a support user + the vendor can extract the access key / endpoint /
 * identifier needed to later simulate the login POST.
 *
 * Returns { key, endpoint, identifier } pulled directly from the fake-saas
 * state so tests don't need to scrape HTML.
 */
async function grantAndCaptureSecrets( ctx: BrowserContext ): Promise<{
    key:        string;
    endpoint:   string;
    identifier: string;
}> {
    // Client admin needs to be logged in so the grant popup can render its CTA.
    await loginClientAdmin( ctx );

    const vp = await ctx.newPage();
    await vp.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await vp.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );
    await vp.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await vp.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    let popup: Page | undefined;
    const popupPromise = ctx.waitForEvent( 'page' ).then( p => { popup = p; } );
    await vp.locator( '.tl-grant-access input[type="submit"]' ).click();
    await popupPromise;

    try { await popup!.waitForLoadState( 'domcontentloaded' ); } catch {}
    try { await popup!.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click(); } catch {}

    await vp.waitForFunction( () => {
        const el = document.querySelector( '.tl-site-key' );
        return el && el.textContent && el.textContent.length > 10;
    }, null, { timeout: 30_000 } );
    const key = await vp.evaluate( () => document.querySelector( '.tl-site-key' )!.textContent! );
    await vp.close();

    // Use AccessKeyLogin::handle() on the VENDOR side to decrypt the
    // envelope the same way the production flow does. That gives us the
    // unhashed identifier + endpoint the client expects on its POST.
    const raw = execSync(
        `docker compose run --rm -T wp-cli-vendor wp eval '`
        // handle() enforces role-based authorization (must be a team-approved
        // role). wp-cli runs without an authenticated user by default, so we
        // spin up the admin explicitly. Matches how a support agent would be
        // running the flow through wp-admin.
        + `wp_set_current_user( get_user_by( "login", "admin" )->ID );`
        + `$parts = ( new \\TrustedLogin\\Vendor\\AccessKeyLogin() )->handle( array(`
        + `  \\TrustedLogin\\Vendor\\AccessKeyLogin::ACCOUNT_ID_INPUT_NAME => "999",`
        + `  \\TrustedLogin\\Vendor\\AccessKeyLogin::ACCESS_KEY_INPUT_NAME => ${ JSON.stringify( key ) },`
        + `) );`
        + `if ( is_wp_error( $parts ) ) { echo "ERR:" . $parts->get_error_code() . ":" . $parts->get_error_message(); exit; }`
        + `$first = reset( $parts );`
        + `echo $first["endpoint"] . "|" . $first["identifier"];`
        + `'`,
        { cwd: E2E_DIR, encoding: 'utf8', timeout: 20000 }
    );
    const lines = raw.split( '\n' ).filter( l => l && ! /^\s/.test( l ) && ! /^Container/.test( l ) );
    const last  = lines[ lines.length - 1 ];
    if ( last.startsWith( 'ERR:' ) ) {
        throw new Error( 'handle() failed: ' + last );
    }
    const [ endpoint, identifier ] = last.split( '|' );
    if ( ! endpoint || ! identifier ) {
        throw new Error( 'Failed to parse handle() output: ' + raw );
    }

    return { key, endpoint, identifier };
}

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
    resetClientState();
} );

// ---------------------------------------------------------------------------
// Positive signals
// ---------------------------------------------------------------------------

test( 'successful grant → browser lands on wp-admin with success notice', async ( { browser } ) => {
    // Use a DEDICATED context: the support agent has NOT logged into the
    // client site themselves, which is the realistic scenario.
    const grantCtx = await browser.newContext();
    const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    // Fresh context: no prior client cookies.
    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();

    // Drive the POST via a tiny HTML form so the resulting redirect is a
    // real navigation (cookies applied). Not using request.post so the
    // browser actually follows the redirect.
    await p.goto( 'about:blank' );
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ identifier }">
    </form><script>document.getElementById('f').submit();</script>` );

    await p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } );
    expect( p.url() ).toMatch( /tl_notice=logged_in/ );

    await expect( p.locator( '.notice-success' ) ).toBeVisible();
    await expect( p.locator( '.notice-success' ) ).toContainText( /logged in as a/i );

    await agentCtx.close();
} );

test( 'already-logged-in agent → info notice explains skipped login', async ( { browser } ) => {
    const grantCtx = await browser.newContext();
    const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    // Fresh context, but we deliberately pre-log-in as admin so the
    // TL flow takes the "already authenticated" branch.
    const agentCtx = await browser.newContext();
    await loginClientAdmin( agentCtx );

    const p = await agentCtx.newPage();
    await p.goto( 'about:blank' );
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ identifier }">
    </form><script>document.getElementById('f').submit();</script>` );

    await p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } );
    expect( p.url() ).toMatch( /tl_notice=already_logged_in/ );

    await expect( p.locator( '.notice-info' ) ).toBeVisible();
    await expect( p.locator( '.notice-info' ) ).toContainText( /already signed in/i );

    await agentCtx.close();
} );

// ---------------------------------------------------------------------------
// Failure signals — only surface after legit endpoint+identifier pair
// ---------------------------------------------------------------------------

test( 'unknown identifier with correct endpoint → security_check_failed feedback', async ( { browser } ) => {
    // Submitting a correctly-shaped (128-char hex) identifier that maps to
    // no support user drives SecurityChecks::verify() → get_secret_id()
    // returns null → check_approved_identifier() hits the SaaS with an
    // empty secret_id and 404s → verify returns WP_Error →
    // fail_login('security_check_failed') fires.
    //
    // Scope: this test only covers the `security_check_failed` code path
    // (the common case). The `login_failed` path fires after verify()
    // passes but before login completes; hand-crafting a valid-to-verify
    // identifier that subsequently fails login requires privileged state
    // manipulation the e2e stack can't reliably do, so it's left for
    // integration-level testing.
    const ctx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( ctx );
    await ctx.close();

    const unknownIdentifier = 'a'.repeat( 128 );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ unknownIdentifier }">
    </form><script>document.getElementById('f').submit();</script>` );

    // Failure redirect lands on wp-login.php?action=trustedlogin&...&tl_error=security_check_failed
    await p.waitForURL( /wp-login\.php.*tl_error=security_check_failed/, { timeout: 15_000 } );

    await expect( p.locator( '.tl-login-feedback--error' ) ).toBeVisible();
    await expect( p.locator( '.tl-login-feedback--error' ) ).toContainText(
        /security reasons|access key may have expired|could not be started/i
    );

    // Security assertions: the user-facing text must NOT leak internal
    // detail — no identifier hash, no endpoint value, no specific
    // machine-parseable code spelled out in prose.
    const text = ( await p.locator( '.tl-login-feedback--error' ).textContent() ) || '';
    expect( text ).not.toContain( unknownIdentifier );
    expect( text ).not.toContain( endpoint );
    expect( text.toLowerCase() ).not.toContain( 'security_check_failed' );
    expect( text.toLowerCase() ).not.toContain( 'endpoint' );
    expect( text.toLowerCase() ).not.toContain( 'identifier' );

    await agentCtx.close();
} );

test( 'expired support user → login_failed feedback path', async ( { browser } ) => {
    // Complement to the security_check_failed test: exercise the OTHER
    // failure branch in Endpoint::maybe_login_support().
    //
    // Flow: grant creates a valid user; we expire that user by rewinding
    // its tl_{ns}_expires user meta to a past timestamp. verify() still
    // passes (user lookup + SaaS secret match work), then
    // SupportUser::maybe_login() detects is_active==false and returns
    // WP_Error('access_expired') — fail_login('login_failed') fires.
    const ctx = await browser.newContext();
    const { endpoint, identifier } = await grantAndCaptureSecrets( ctx );
    await ctx.close();

    // Age out the support user by 24h in the past.
    execSync(
        `docker compose run --rm -T wp-cli-client wp eval '`
        + `$u = get_users( array( "meta_key" => "tl_pro-block-builder_id", "number" => 1 ) );`
        + `if ( $u ) { update_user_option( $u[0]->ID, "wp_tl_pro-block-builder_expires", time() - 86400, true ); echo "aged"; }`
        + `'`,
        { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 15000 }
    );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ identifier }">
    </form><script>document.getElementById('f').submit();</script>` );

    await p.waitForURL( /wp-login\.php.*tl_error=login_failed/, { timeout: 15_000 } );

    await expect( p.locator( '.tl-login-feedback--error' ) ).toBeVisible();
    await expect( p.locator( '.tl-login-feedback--error' ) ).toContainText(
        /access key may have expired|could not be started/i
    );

    // Same leak assertions as the security_check_failed test.
    const text = ( await p.locator( '.tl-login-feedback--error' ).textContent() ) || '';
    expect( text ).not.toContain( identifier );
    expect( text ).not.toContain( endpoint );
    expect( text.toLowerCase() ).not.toContain( 'access_expired' );

    await agentCtx.close();
} );

// ---------------------------------------------------------------------------
// Security posture tests
// ---------------------------------------------------------------------------

test( 'malformed request (missing identifier) → silent no-op, no message leaked', async ( { browser } ) => {
    const ctx = await browser.newContext();
    const p = await ctx.newPage();

    // POST with action=trustedlogin + a bogus endpoint, NO identifier.
    await p.goto( 'about:blank' );
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="bogus_endpoint_string">
    </form><script>document.getElementById('f').submit();</script>` );

    // The response should not redirect to wp-login.php with an error —
    // it should just render the home page (no trustedlogin response).
    await p.waitForLoadState( 'networkidle' );
    expect( p.url() ).not.toMatch( /tl_error=/ );
    expect( p.url() ).not.toMatch( /wp-login\.php/ );
    // The feedback banner must NOT appear anywhere.
    await expect( p.locator( '.tl-login-feedback' ) ).toHaveCount( 0 );

    await ctx.close();
} );

test( 'endpoint mismatch with valid identifier shape → silent no-op (no endpoint leak)', async ( { browser } ) => {
    const grantCtx = await browser.newContext();
    const { identifier } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const ctx = await browser.newContext();
    const p = await ctx.newPage();

    // Real identifier but a DIFFERENT endpoint — simulates an attacker who
    // somehow learned an identifier but not the stored endpoint. The
    // response must not leak whether the endpoint guess was close.
    await p.goto( 'about:blank' );
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="00000000000000000000000000000000">
        <input name="identifier" value="${ identifier }">
    </form><script>document.getElementById('f').submit();</script>` );

    await p.waitForLoadState( 'networkidle' );
    expect( p.url() ).not.toMatch( /tl_error=/ );
    expect( p.url() ).not.toMatch( /wp-login\.php/ );
    await expect( p.locator( '.tl-login-feedback' ) ).toHaveCount( 0 );

    await ctx.close();
} );

test( 'crafted wp-login.php?tl_error= without a matching transient shows nothing', async ( { browser } ) => {
    // Anyone can navigate to ?tl_error=X directly; if there's no matching
    // transient, the feedback banner must not render. This blocks phishing
    // via link-crafting ("your support login failed, click here to retry").
    const ctx = await browser.newContext();
    const p = await ctx.newPage();

    const url = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&tl_error=login_failed`;
    await p.goto( url, { waitUntil: 'domcontentloaded' } );

    await expect( p.locator( '.tl-login-feedback' ) ).toHaveCount( 0 );

    await ctx.close();
} );

test( 'failure message is one-hop: consumed after first render', async ( { browser } ) => {
    // Trigger a failure so a transient is set + page shows the message.
    // Reuse the same bogus-identifier technique as the "generic error"
    // test: it reliably reaches fail_login() without depending on
    // envelope-replay semantics of our fake-saas.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const bogusIdentifier = 'a'.repeat( 128 );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ bogusIdentifier }">
    </form><script>document.getElementById('f').submit();</script>` );
    await p.waitForURL( /wp-login\.php.*tl_error=/, { timeout: 15_000 } );
    await expect( p.locator( '.tl-login-feedback--error' ) ).toBeVisible();

    // Grab the same URL (with ?tl_error=...) and reload — the transient
    // should be gone so the banner doesn't appear a second time.
    const errorUrl = p.url();
    const p2 = await agentCtx.newPage();
    await p2.goto( errorUrl, { waitUntil: 'domcontentloaded' } );
    await expect( p2.locator( '.tl-login-feedback' ) ).toHaveCount( 0 );

    await agentCtx.close();
} );

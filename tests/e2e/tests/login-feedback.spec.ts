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
import { wpCli, resetClientState as resetClientStateShared } from './_helpers';

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

// Reset the client site + fake-saas from the shared helper. No more
// silent-catch best-effort cleanup: a failed reset aborts the test so
// we don't build on stale state.
const resetClientState = resetClientStateShared;

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
    const out = wpCli(
        'wp-cli-vendor',
        // handle() enforces role-based authorization (must be a team-approved
        // role). wp-cli runs without an authenticated user by default, so we
        // spin up the admin explicitly. Matches how a support agent would be
        // running the flow through wp-admin.
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
    // Submitting a correctly-shaped identifier that maps to no support
    // user drives SecurityChecks::verify() → get_secret_id() returns
    // null → check_approved_identifier() hits the SaaS with an empty
    // secret_id and 404s → verify returns WP_Error →
    // fail_login('security_check_failed') fires.
    //
    // The identifier is generated by the CLIENT's own randomness helper
    // (same entropy + hex encoding a real grant would produce), not a
    // lazy 'a'.repeat() — so if someone later adds entropy validation
    // to verify(), this test still exercises a realistic shape.
    const ctx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( ctx );
    await ctx.close();

    const unknownIdentifier = wpCli(
        'wp-cli-client',
        `echo bin2hex( random_bytes( 64 ) );`,
        'generate 128-char random identifier',
    );
    expect( unknownIdentifier ).toMatch( /^[a-f0-9]{128}$/ );

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
    const aged = wpCli(
        'wp-cli-client',
        `$u = get_users( array( "meta_key" => "tl_pro-block-builder_id", "number" => 1 ) );`
        + `if ( $u ) { update_user_option( $u[0]->ID, "wp_tl_pro-block-builder_expires", time() - 86400, true ); echo "aged"; } else { echo "nouser"; }`,
        'expire support user',
    );
    expect( aged, 'expected to find the newly-granted support user' ).toBe( 'aged' );

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

test( 'failure feedback renders "Go back" link pointing at the POST referer', async ( { browser } ) => {
    // The feedback screen lands on wp-login.php — by that point the browser
    // referer has been overwritten by the redirect hop, so the link must be
    // built from the referer captured at POST time by Endpoint::fail_login().
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli(
        'wp-cli-client',
        `echo bin2hex( random_bytes( 64 ) );`,
        'generate identifier for referer test',
    );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    // POST originates from the real vendor form page so the browser sends
    // a Referer header. Without this, wp_get_raw_referer() on the POST has
    // nothing to store and the Go-back link can't render.
    await p.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await p.evaluate( ( { action, endpoint, identifier } ) => {
        const f = document.createElement( 'form' );
        f.method = 'POST'; f.action = action;
        for ( const [ n, v ] of Object.entries( { action: 'trustedlogin', endpoint, identifier } ) ) {
            const i = document.createElement( 'input' );
            i.name = n; i.value = v; f.appendChild( i );
        }
        document.body.appendChild( f ); f.submit();
    }, { action: VENDOR_STATE.client_url + '/', endpoint, identifier: unknownIdentifier } );

    await p.waitForURL( /wp-login\.php.*tl_error=security_check_failed/, { timeout: 15_000 } );

    const goBack = p.locator( '.tl-login-feedback__actions a', { hasText: /go back/i } );
    await expect( goBack ).toBeVisible();
    // The href must be a plain http(s) URL (no javascript:/data:, etc.) —
    // esc_url() enforces the scheme allowlist.
    const href = await goBack.getAttribute( 'href' );
    expect( href ).toMatch( /^https?:\/\// );
    // And it should point at the vendor origin (cross-origin by design for
    // this link; it's an <a href>, not a wp_safe_redirect target).
    expect( href ).toContain( new URL( VENDOR_STATE.vendor_url ).host );

    await agentCtx.close();
} );

test( 'integrator can extend the Referer allowlist via filter (multi-domain vendors)', async ( { browser } ) => {
    // Vendors that serve many customer sites sometimes run support from
    // multiple surfaces (marketing site + support portal + white-label
    // domains). The filter
    //   trustedlogin/{ns}/login_feedback/allowed_referer_urls
    // lets them opt-in additional hosts without widening the default
    // allowlist for everyone.
    //
    // Test: register a throwaway host via the filter, POST with a Referer
    // matching it, assert the Go-back link renders pointing at the
    // filter-added URL.

    // The mu-plugin at tests/e2e/mu-plugins/tl-extra-referer.php registers:
    //   trustedlogin/pro-block-builder/login_feedback/allowed_referer_urls
    // and appends 'https://support.vendor.test/portal'.

    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli(
        'wp-cli-client', `echo bin2hex( random_bytes( 64 ) );`, 'rand ident for filter test',
    );

    // Browsers own the Referer header on form navigation, so we can't fake
    // it via page.route(). Use APIRequestContext instead — it lets us set
    // arbitrary headers on a real POST. We then navigate the page to the
    // 302 Location (wp-login.php?tl_error=...) to assert the feedback UI.
    const agentCtx = await browser.newContext();
    const resp = await agentCtx.request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        form: { action: 'trustedlogin', endpoint, identifier: unknownIdentifier },
        headers: { Referer: 'https://support.vendor.test/some/deep/path?x=1' },
    } );
    expect( resp.status() ).toBe( 302 );
    const location = resp.headers()[ 'location' ] || '';
    expect( location ).toMatch( /wp-login\.php.*tl_error=security_check_failed/ );

    const p = await agentCtx.newPage();
    await p.goto( location, { waitUntil: 'domcontentloaded' } );

    const goBack = p.locator( '.tl-login-feedback__actions a', { hasText: /go back/i } );
    await expect( goBack ).toBeVisible();
    const href = await goBack.getAttribute( 'href' );
    // Must be the filter-added URL (host matched), NOT the raw
    // attacker-path Referer — path/query from Referer is discarded.
    expect( href ).toBe( 'https://support.vendor.test/portal' );
    expect( href ).not.toContain( '/some/deep/path' );
    expect( href ).not.toContain( 'x=1' );

    await agentCtx.close();
} );

test( 'spoofed cross-origin Referer never reaches the Go-back link', async ( { browser } ) => {
    // Attack model: an attacker POSTs to client.com/ with a legit endpoint
    // + bogus identifier + Referer: https://evil.example/phish. The
    // transient stores referer → attacker sends victim to
    // wp-login.php?tl_error=..., victim sees Go-back pointing at evil.example.
    //
    // Defense: fail_login() only stores the referer if its host matches one
    // the integrator declared in config (vendor/website, vendor/support_url,
    // or home_url). An attacker-controlled host falls through to empty.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli(
        'wp-cli-client',
        `echo bin2hex( random_bytes( 64 ) );`,
        'generate identifier for spoof test',
    );

    // Use APIRequestContext to actually send a forged Referer header
    // (page.route() can't fake it on a navigation POST — browsers own
    // that header). This sends the exact attack request an adversary
    // would send via curl/script.
    const agentCtx = await browser.newContext();
    const resp = await agentCtx.request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        form: { action: 'trustedlogin', endpoint, identifier: unknownIdentifier },
        headers: { Referer: 'https://evil.example/phish' },
    } );
    expect( resp.status() ).toBe( 302 );
    const location = resp.headers()[ 'location' ] || '';
    expect( location ).toMatch( /wp-login\.php.*tl_error=security_check_failed/ );

    const p = await agentCtx.newPage();
    await p.goto( location, { waitUntil: 'domcontentloaded' } );

    await expect( p.locator( '.tl-login-feedback--error' ) ).toBeVisible();
    // Spoofed host MUST NOT appear anywhere on the page.
    const html = await p.content();
    expect( html ).not.toContain( 'evil.example' );
    // Go-back link MUST be omitted (host didn't match any allowed list).
    await expect( p.locator( '.tl-login-feedback__actions a', { hasText: /go back/i } ) ).toHaveCount( 0 );

    await agentCtx.close();
} );

test( 'failure feedback omits "Go back" when no referer was captured', async ( { browser } ) => {
    // Direct navigation to the POST endpoint (no Referer header) must produce
    // a feedback screen WITHOUT a broken/empty Go-back link.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli(
        'wp-cli-client',
        `echo bin2hex( random_bytes( 64 ) );`,
        'generate identifier for no-referer test',
    );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    // Start from about:blank (no origin) so the POST carries no Referer.
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ unknownIdentifier }">
    </form><script>document.getElementById('f').submit();</script>` );
    await p.waitForURL( /wp-login\.php.*tl_error=security_check_failed/, { timeout: 15_000 } );

    await expect( p.locator( '.tl-login-feedback--error' ) ).toBeVisible();
    // Contact support link must still be present (independent of referer).
    await expect( p.locator( '.tl-login-feedback__actions a', { hasText: /contact support/i } ) ).toBeVisible();
    // But Go back must be absent.
    await expect( p.locator( '.tl-login-feedback__actions a', { hasText: /go back/i } ) ).toHaveCount( 0 );

    await agentCtx.close();
} );

test( 'failure message is one-hop: consumed after first render', async ( { browser } ) => {
    // Trigger a failure so a transient is set + page shows the message.
    // Reuse the same bogus-identifier technique as the "generic error"
    // test: it reliably reaches fail_login() without depending on
    // envelope-replay semantics of our fake-saas.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const bogusIdentifier = wpCli(
        'wp-cli-client',
        `echo bin2hex( random_bytes( 64 ) );`,
        'generate random identifier for one-hop test',
    );
    expect( bogusIdentifier ).toMatch( /^[a-f0-9]{128}$/ );

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

/**
 * Screenshot capture for login-feedback documentation.
 *
 * Drives each UX state end-to-end against the real stack and saves PNGs into
 * the TrustedLogin docs site at
 *   <TL_DOCS_REPO>/static/img/client/login-feedback/
 *
 * Docs are maintained in the separate `trustedlogin-docs` repo (docusaurus).
 * This spec expects it checked out at ../../../../../../../../trustedlogin-docs
 * relative to this file — the layout on a standard local dev machine
 * (`~/Local/dev/app/public/wp-content/plugins/client` + `~/Local/trustedlogin-docs`).
 *
 * Override with the `TL_DOCS_STATIC_DIR` env var to point elsewhere.
 *
 * Run: `npx playwright test capture-screenshots.spec.ts`
 */

import { test, BrowserContext, Page } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli, resetClientState as resetClientStateShared } from './_helpers';

const DEFAULT_DOCS_DIR = path.resolve(
    __dirname,
    // plugins/client/tests/e2e/tests → up 9 → ~/Local/
    '..', '..', '..', '..', '..', '..', '..', '..', '..',
    'trustedlogin-docs', 'static', 'img', 'client', 'login-feedback',
);
const OUT_DIR = process.env.TL_DOCS_STATIC_DIR || DEFAULT_DOCS_DIR;

// We check the PARENT of OUT_DIR because OUT_DIR itself is created
// on first run (fs.mkdirSync below) — but the parent (the checked-out
// docs repo's static/img/client tree) must already exist. Without it,
// we're either on the wrong machine layout or the docs repo isn't
// checked out at the expected sibling path.
if ( ! fs.existsSync( path.dirname( OUT_DIR ) ) ) {
    throw new Error(
        `trustedlogin-docs repo not found (expected the parent of ${ OUT_DIR } to exist). ` +
        `Set TL_DOCS_STATIC_DIR to override the output location.`,
    );
}

fs.mkdirSync( OUT_DIR, { recursive: true } );

/**
 * The build-sass script compiles SCSS with a `$namespace` variable
 * baked into every selector (`.tl-${ns}-auth`, `.button-trustedlogin-${ns}`).
 * If the compiled CSS doesn't carry the namespace the integrating plugin
 * uses, nothing matches and the screens render unstyled — turning
 * every screenshot into a PR-quality disaster without the spec failing.
 *
 * The `client.php` shipped with this repo uses `pro-block-builder`, so
 * we grep the compiled CSS for that selector and rebuild if missing.
 */
function ensureCssMatchesNamespace( namespace: string ): void {
    const repoRoot = path.resolve( __dirname, '..', '..', '..' );
    const cssPath  = path.join( repoRoot, 'src', 'assets', 'trustedlogin.css' );
    const marker   = `tl-${ namespace }-auth`;

    let compiled = '';
    try { compiled = fs.readFileSync( cssPath, 'utf-8' ); } catch {}

    if ( compiled.includes( marker ) ) {
        return;
    }

    console.log( `[capture] CSS missing '${ marker }' — rebuilding for namespace '${ namespace }'` );
    // execFileSync (not execSync) — argv array bypasses /bin/sh entirely so
    // repoRoot / namespace can't be interpreted as shell metacharacters.
    execFileSync(
        'docker',
        [ 'run', '--rm', '-v', `${ repoRoot }:/app`, '-w', '/app', 'php:8.2-cli', 'php', 'bin/build-sass', `--namespace=${ namespace }` ],
        { stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 60_000 },
    );
}

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

// Rebuild trustedlogin.css for the integrating plugin's namespace if the
// current compiled CSS doesn't already carry it. Cheap when cache is
// fresh (~30ms grep), transparent when it isn't (runs the build in
// Docker). Without this, a stale CSS from a different namespace silently
// turns every capture into an unstyled WP-admin screenshot.
ensureCssMatchesNamespace( VENDOR_STATE.namespace );

async function shot( p: Page, name: string ) {
    await p.screenshot( { path: path.join( OUT_DIR, name ), fullPage: false } );
}

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

async function grantAndCaptureSecrets( ctx: BrowserContext, captureForm = false ): Promise<{
    key: string; endpoint: string; identifier: string;
}> {
    await loginClientAdmin( ctx );
    const vp = await ctx.newPage();
    await vp.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await vp.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );

    if ( captureForm ) {
        await vp.locator( '.tl-grant-access' ).scrollIntoViewIfNeeded();
        await shot( vp, '01-vendor-grant-form.png' );
    }

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

    if ( captureForm ) {
        await vp.locator( '.tl-site-key' ).scrollIntoViewIfNeeded();
        await shot( vp, '02-vendor-after-grant.png' );
    }

    const key = await vp.evaluate( () => document.querySelector( '.tl-site-key' )!.textContent! );
    await vp.close();

    const out = wpCli(
        'wp-cli-vendor',
        `wp_set_current_user( get_user_by( "login", "admin" )->ID );`
        + `$parts = ( new \\TrustedLogin\\Vendor\\AccessKeyLogin() )->handle( array(`
        + `  \\TrustedLogin\\Vendor\\AccessKeyLogin::ACCOUNT_ID_INPUT_NAME => "999",`
        + `  \\TrustedLogin\\Vendor\\AccessKeyLogin::ACCESS_KEY_INPUT_NAME => ${ JSON.stringify( key ) },`
        // $trusted=true — CLI invocation has no $_REQUEST nonce.
        // Connector commit ca4e4b7 made nonce-check unconditional.
        + `), true );`
        + `if ( is_wp_error( $parts ) ) { echo "ERR:" . $parts->get_error_code() . ":" . $parts->get_error_message(); exit; }`
        + `$first = reset( $parts );`
        + `echo $first["endpoint"] . "|" . $first["identifier"];`,
        'capture:handle',
    );
    if ( out.startsWith( 'ERR:' ) ) { throw new Error( 'handle() failed: ' + out ); }
    const [ endpoint, identifier ] = out.split( '|' );
    return { key, endpoint, identifier };
}

test.describe.configure( { mode: 'serial' } );
test.beforeEach( () => { resetClientStateShared(); } );

// Submit the trustedlogin POST from a real vendor URL so the browser
// sends a Referer — captures the production shape (including the
// "Go back" link on the feedback screen).
async function submitFromVendor( p: Page, endpoint: string, identifier: string ) {
    // Land on a real vendor page first so the upcoming POST has an origin.
    await p.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await p.evaluate( ( { action, endpoint, identifier } ) => {
        const f = document.createElement( 'form' );
        f.method = 'POST';
        f.action = action;
        for ( const [ n, v ] of Object.entries( { action: 'trustedlogin', endpoint, identifier } ) ) {
            const i = document.createElement( 'input' );
            i.name = n; i.value = v; f.appendChild( i );
        }
        document.body.appendChild( f );
        f.submit();
    }, { action: VENDOR_STATE.client_url + '/', endpoint, identifier } );
}

test( 'capture: vendor form + success notice', async ( { browser } ) => {
    const grantCtx = await browser.newContext();
    const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx, true );
    await grantCtx.close();

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await submitFromVendor( p, endpoint, identifier );
    await p.waitForURL( /\/wp-admin\/.*tl_notice=logged_in/, { timeout: 15_000 } );
    await p.locator( '.notice-success' ).waitFor( { state: 'visible', timeout: 10_000 } );
    await shot( p, '03-success-banner.png' );
    await agentCtx.close();
} );

test( 'capture: already-logged-in info notice', async ( { browser } ) => {
    const grantCtx = await browser.newContext();
    const { endpoint, identifier } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const agentCtx = await browser.newContext();
    await loginClientAdmin( agentCtx );
    const p = await agentCtx.newPage();
    await submitFromVendor( p, endpoint, identifier );
    await p.waitForURL( /\/wp-admin\/.*tl_notice=already_logged_in/, { timeout: 15_000 } );
    await p.locator( '.notice-info' ).waitFor( { state: 'visible', timeout: 10_000 } );
    await shot( p, '04-already-logged-in.png' );
    await agentCtx.close();
} );

test( 'capture: security_check_failed feedback', async ( { browser } ) => {
    const ctx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( ctx );
    await ctx.close();

    const unknownIdentifier = wpCli( 'wp-cli-client', `echo bin2hex( random_bytes( 64 ) );`, 'rand ident' );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await submitFromVendor( p, endpoint, unknownIdentifier );
    await p.waitForURL( /wp-login\.php.*tl_error=security_check_failed/, { timeout: 15_000 } );
    await p.locator( '.tl-login-feedback--error' ).waitFor( { state: 'visible' } );
    await shot( p, '05-security-check-failed.png' );
    await agentCtx.close();
} );

test( 'capture: login_failed feedback (expired support user)', async ( { browser } ) => {
    const ctx = await browser.newContext();
    const { endpoint, identifier } = await grantAndCaptureSecrets( ctx );
    await ctx.close();

    wpCli(
        'wp-cli-client',
        `$u = get_users( array( "meta_key" => "tl_pro-block-builder_id", "number" => 1 ) );`
        + `if ( $u ) { update_user_option( $u[0]->ID, "wp_tl_pro-block-builder_expires", time() - 86400, true ); echo "aged"; } else { echo "nouser"; }`,
        'expire support user',
    );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await submitFromVendor( p, endpoint, identifier );
    await p.waitForURL( /wp-login\.php.*tl_error=login_failed/, { timeout: 15_000 } );
    await p.locator( '.tl-login-feedback--error' ).waitFor( { state: 'visible' } );
    await shot( p, '06-login-failed.png' );
    await agentCtx.close();
} );

test( 'capture: crafted tl_error URL shows nothing', async ( { browser } ) => {
    const ctx = await browser.newContext();
    const p = await ctx.newPage();
    const url = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&tl_error=login_failed`;
    await p.goto( url, { waitUntil: 'domcontentloaded' } );
    await shot( p, '07-crafted-url-no-message.png' );
    await ctx.close();
} );

// ---------------------------------------------------------------------------
// Referer allowlist — the `login_feedback/allowed_referer_urls` filter.
// Each test below reproduces the assertion scenario from
// tests/login-feedback.spec.ts and captures the feedback screen at the
// moment the Go-back link is (or isn't) rendered.
// ---------------------------------------------------------------------------

test( 'capture: Go-back points at filter-extended vendor domain', async ( { browser } ) => {
    // mu-plugin `tl-extra-referer.php` registers an extra trusted URL:
    //   https://support.vendor.test/portal
    // POST carries a Referer whose HOST matches that URL. The rendered
    // Go-back link points at the FILTER-ADDED URL (deep path discarded),
    // demonstrating host-match + render-the-configured-URL behavior.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli( 'wp-cli-client', `echo bin2hex( random_bytes( 64 ) );`, 'rand ident' );

    const agentCtx = await browser.newContext();
    const resp = await agentCtx.request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        form: { action: 'trustedlogin', endpoint, identifier: unknownIdentifier },
        headers: { Referer: 'https://support.vendor.test/some/deep/path?x=1' },
    } );
    const location = resp.headers()[ 'location' ] || '';
    const p = await agentCtx.newPage();
    await p.goto( location, { waitUntil: 'domcontentloaded' } );
    await p.locator( '.tl-login-feedback--error' ).waitFor( { state: 'visible' } );
    await p.locator( '.tl-login-feedback__actions a', { hasText: /go back/i } ).waitFor( { state: 'visible' } );
    await shot( p, '08-goback-filter-extended.png' );
    await agentCtx.close();
} );

test( 'capture: spoofed Referer → Go-back is absent', async ( { browser } ) => {
    // Attacker POSTs with a forged Referer. The host doesn't match any URL
    // on the allowlist, so nothing is stored and the Go-back link is
    // omitted. The spoofed host appears nowhere on the page.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli( 'wp-cli-client', `echo bin2hex( random_bytes( 64 ) );`, 'rand ident' );

    const agentCtx = await browser.newContext();
    const resp = await agentCtx.request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        form: { action: 'trustedlogin', endpoint, identifier: unknownIdentifier },
        headers: { Referer: 'https://evil.example/phish' },
    } );
    const location = resp.headers()[ 'location' ] || '';
    const p = await agentCtx.newPage();
    await p.goto( location, { waitUntil: 'domcontentloaded' } );
    await p.locator( '.tl-login-feedback--error' ).waitFor( { state: 'visible' } );
    await shot( p, '09-goback-spoof-rejected.png' );
    await agentCtx.close();
} );

test( 'capture: no Referer → Go-back is absent, Contact support remains', async ( { browser } ) => {
    // Direct navigation to the POST endpoint (about:blank origin). No
    // Referer header at all. Go-back is omitted gracefully; the other
    // actions (Contact support, back-to-site) remain.
    const grantCtx = await browser.newContext();
    const { endpoint } = await grantAndCaptureSecrets( grantCtx );
    await grantCtx.close();

    const unknownIdentifier = wpCli( 'wp-cli-client', `echo bin2hex( random_bytes( 64 ) );`, 'rand ident' );

    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await p.setContent( `<form id="f" method="POST" action="${ VENDOR_STATE.client_url }/">
        <input name="action" value="trustedlogin">
        <input name="endpoint" value="${ endpoint }">
        <input name="identifier" value="${ unknownIdentifier }">
    </form><script>document.getElementById('f').submit();</script>` );
    await p.waitForURL( /wp-login\.php.*tl_error=security_check_failed/, { timeout: 15_000 } );
    await p.locator( '.tl-login-feedback--error' ).waitFor( { state: 'visible' } );
    await shot( p, '10-goback-no-referer.png' );
    await agentCtx.close();
} );

// ---------------------------------------------------------------------------
// Pre-flight fallback screen: shown on the Grant Access admin page when
// the Client can't reach the plugin's support team's site (firewall
// intercept, misconfigured Connector, unreachable host). These shots
// demonstrate the screen that replaces the usual Grant Access form —
// what 150+ stuck customers would have seen instead of a dead-end form.
// ---------------------------------------------------------------------------

const NS = 'pro-block-builder';

/**
 * Tell the injector mu-plugin which scripted pubkey response to return
 * on the next fetch, and wipe the 10-minute cache so the screen actually
 * hits it. Used by the pre-flight screenshot tests below.
 */
function injectPubkey( mode: string ): void {
    wpCli(
        'wp-cli-client',
        `update_option( "tl_inject_pubkey_response", array( "mode" => ${ JSON.stringify( mode ) } ), false );`
        + `delete_option( "tl_${ NS }_vendor_public_key" );`
        + `wp_cache_delete( "tl_${ NS }_vendor_public_key", "options" );`
        + `echo "ok";`,
        'inject ' + mode,
    );
}

function clearPubkeyInjection(): void {
    wpCli(
        'wp-cli-client',
        `delete_option( "tl_inject_pubkey_response" );`
        + `delete_option( "tl_${ NS }_vendor_public_key" );`
        + `wp_cache_delete( "tl_${ NS }_vendor_public_key", "options" );`
        + `echo "ok";`,
        'clear pubkey injection',
    );
}

async function openGrantAccessPage( ctx: BrowserContext ): Promise<Page> {
    const p = await ctx.newPage();
    // Log in first — the Grant Support Access menu requires manage_options.
    await p.goto( `${ VENDOR_STATE.client_url }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    if ( p.url().includes( 'wp-login.php' ) ) {
        await p.locator( '#user_login' ).fill( 'admin' );
        await p.locator( '#user_pass' ).fill( 'admin' );
        await p.locator( '#wp-submit' ).click( { noWaitAfter: true } );
        await p.waitForURL( /\/wp-admin\//, { timeout: 30_000, waitUntil: 'commit' } );
    }
    await p.goto(
        `${ VENDOR_STATE.client_url }/wp-admin/admin.php?page=grant-${ NS }-access`,
        { waitUntil: 'domcontentloaded' },
    );
    return p;
}

test( 'capture: pre-flight fallback — firewall intercept (Cloudflare 415)', async ( { browser } ) => {
    resetClientStateShared();
    injectPubkey( 'html_cloudflare_415' );

    const ctx = await browser.newContext();
    const p = await openGrantAccessPage( ctx );
    await p.locator( `.tl-${ NS }-auth__response_error[data-preflight-error]` ).waitFor( { state: 'visible' } );
    await shot( p, '11-preflight-firewall.png' );
    await ctx.close();

    clearPubkeyInjection();
} );

test( 'capture: pre-flight fallback — Connector not configured (501)', async ( { browser } ) => {
    resetClientStateShared();
    injectPubkey( 'http_501' );

    const ctx = await browser.newContext();
    const p = await openGrantAccessPage( ctx );
    await p.locator( `.tl-${ NS }-auth__response_error[data-preflight-error]` ).waitFor( { state: 'visible' } );
    await shot( p, '12-preflight-not-configured.png' );
    await ctx.close();

    clearPubkeyInjection();
} );

test( 'capture: pre-flight fallback — support site unreachable (DNS/timeout)', async ( { browser } ) => {
    resetClientStateShared();
    injectPubkey( 'request_failed' );

    const ctx = await browser.newContext();
    const p = await openGrantAccessPage( ctx );
    await p.locator( `.tl-${ NS }-auth__response_error[data-preflight-error]` ).waitFor( { state: 'visible' } );
    await shot( p, '13-preflight-unreachable.png' );
    await ctx.close();

    clearPubkeyInjection();
} );

test( 'capture: healthy Grant Access screen (pre-flight passes)', async ( { browser } ) => {
    resetClientStateShared();
    clearPubkeyInjection();

    const ctx = await browser.newContext();
    const p = await openGrantAccessPage( ctx );
    // Usual form — NOT the fallback screen.
    await p.locator( `.tl-${ NS }-auth__actions .tl-client-grant-button` ).waitFor( { state: 'visible' } );
    await shot( p, '14-grant-access-healthy.png' );
    await ctx.close();
} );

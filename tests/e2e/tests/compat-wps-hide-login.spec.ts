/**
 * E2E compatibility: wps-hide-login (https://wordpress.org/plugins/wps-hide-login/).
 *
 * wps-hide-login hides `wp-login.php` behind a custom slug (here:
 * `hidden-login`) and returns 404 to direct requests on the old URL.
 *
 * The Client SDK builds every login URL via `wp_login_url()`, which
 * wps-hide-login filters — so the failure-feedback redirect must end up
 * at the custom slug, and the feedback screen must render there.
 *
 * Caveat worth documenting:
 *   The GRANT popup (vendor-side) navigates the agent to the client's
 *   `/wp-login.php?action=trustedlogin&...` URL. Because the Connector
 *   plugin doesn't know about the client's hidden-login slug, that URL
 *   is constructed from the vendor side and 404s under wps-hide-login.
 *   To keep these tests focused on the Client SDK's own behavior, we
 *   grant access BEFORE activating wps-hide-login, then verify every
 *   Client-side flow still works once it's active.
 */

import { execSync } from 'child_process';
import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli, E2E_DIR } from './_helpers';

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

const HIDE_LOGIN_SLUG = 'hidden-login';

function wpCommand( container: 'wp-cli-client' | 'wp-cli-vendor', args: string ): string {
    return execSync(
        `docker compose run --rm -T ${ container } wp ${ args }`,
        { cwd: E2E_DIR, encoding: 'utf8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
    ).toString().trim();
}

async function loginClientAdmin( ctx: BrowserContext, loginPath = 'wp-login.php' ) {
    const p = await ctx.newPage();
    await p.goto( `${ VENDOR_STATE.client_url }/${ loginPath }`, { waitUntil: 'domcontentloaded' } );
    if ( ! /\/wp-admin\//.test( p.url() ) ) {
        await p.locator( '#user_login' ).fill( 'admin' );
        await p.locator( '#user_pass' ).fill( 'admin' );
        await Promise.all( [
            p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
            p.locator( '#wp-submit' ).click(),
        ] );
    }
    await p.close();
}

async function grantAndCaptureSecrets( ctx: BrowserContext ): Promise<{
    endpoint: string; identifier: string;
}> {
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
        'compat-whl:handle',
    );
    if ( out.startsWith( 'ERR:' ) ) { throw new Error( 'handle() failed: ' + out ); }
    const [ endpoint, identifier ] = out.split( '|' );
    return { endpoint, identifier };
}

test.describe.configure( { mode: 'serial' } );

// Captured once in beforeAll — all tests in this spec reuse the same
// endpoint/identifier so we don't depend on the popup flow while
// wps-hide-login is active.
let sharedEndpoint = '';
let sharedIdentifier = '';

test.beforeAll( async ( { browser } ) => {
    // 1. Grant access BEFORE activating wps-hide-login. The Connector's
    //    popup navigates to the client's /wp-login.php which would 404
    //    under wps-hide-login. Capturing now keeps the test focused on
    //    what the Client SDK itself does, not the cross-plugin popup URL.
    const ctx = await browser.newContext();
    const secrets = await grantAndCaptureSecrets( ctx );
    await ctx.close();
    sharedEndpoint   = secrets.endpoint;
    sharedIdentifier = secrets.identifier;

    // 2. Activate wps-hide-login + set a known custom slug.
    wpCommand( 'wp-cli-client', 'plugin activate wps-hide-login' );
    wpCli(
        'wp-cli-client',
        `update_option( "whl_page", ${ JSON.stringify( HIDE_LOGIN_SLUG ) } ); echo "ok";`,
        'set whl_page',
    );

    // 3. Sanity check — wps-hide-login is genuinely blocking the old URL.
    //    If this passes but `/wp-login.php` still 200s, the assertions
    //    below would be meaningless. Fail early with a clear message.
    const probe = execSync(
        `curl -s -o /dev/null -w "%{http_code}" ${ VENDOR_STATE.client_url }/wp-login.php`,
        { encoding: 'utf8', timeout: 5_000 },
    ).toString().trim();
    if ( probe !== '404' ) {
        throw new Error( `wps-hide-login not active (expected 404 on /wp-login.php, got ${ probe }).` );
    }
} );

test.afterAll( () => {
    wpCli(
        'wp-cli-client',
        `delete_option( "whl_page" ); echo "ok";`,
        'clear whl_page',
    );
    wpCommand( 'wp-cli-client', 'plugin deactivate wps-hide-login' );
} );

// NOTE: No beforeEach resetClientState here — we need the granted support
// user + endpoint stored on the client site to stay alive across all tests.

// ---------------------------------------------------------------------------

test( 'direct /wp-login.php is 404 under wps-hide-login (sanity)', async ( { browser } ) => {
    const ctx = await browser.newContext();
    const resp = await ctx.request.get( `${ VENDOR_STATE.client_url }/wp-login.php`, {
        maxRedirects: 0,
    } );
    expect( resp.status() ).toBe( 404 );
    await ctx.close();
} );

test( 'successful grant → agent lands in wp-admin with the success notice', async ( { browser } ) => {
    // The login POST goes to the site root (NOT wp-login.php), so
    // wps-hide-login has no bearing on the POST itself. Success
    // redirects to admin_url(), which is also unaffected.
    const agentCtx = await browser.newContext();
    const p = await agentCtx.newPage();
    await p.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await p.evaluate( ( { action, endpoint, identifier } ) => {
        const f = document.createElement( 'form' );
        f.method = 'POST'; f.action = action;
        for ( const [ n, v ] of Object.entries( { action: 'trustedlogin', endpoint, identifier } ) ) {
            const i = document.createElement( 'input' );
            i.name = n; i.value = v; f.appendChild( i );
        }
        document.body.appendChild( f ); f.submit();
    }, { action: VENDOR_STATE.client_url + '/', endpoint: sharedEndpoint, identifier: sharedIdentifier } );

    await p.waitForURL( /\/wp-admin\/.*tl_notice=logged_in/, { timeout: 15_000 } );
    await expect( p.locator( '.notice-success' ) ).toBeVisible();
    await agentCtx.close();
} );

test( 'failed login → standalone page (does not leak through /wp-login.php)', async ( { browser } ) => {
    // Random identifier triggers SecurityChecks::verify() failure, which
    // calls fail_login(security_check_failed, …, null). With a null
    // site_identifier_hash there's no secret_id, no SaaS POST, no
    // attempt id — the SDK intentionally renders the standalone page
    // (HTTP 200) instead of redirecting anywhere. That's the secure
    // default for unauthenticated probes; the wps-hide-login compat
    // claim is that nothing in this path leaks the legacy
    // /wp-login.php URL to the user.
    const unknownIdentifier = wpCli(
        'wp-cli-client', `echo bin2hex( random_bytes( 64 ) );`, 'rand ident',
    );

    const agentCtx = await browser.newContext();
    const resp = await agentCtx.request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        form: { action: 'trustedlogin', endpoint: sharedEndpoint, identifier: unknownIdentifier },
    } );

    expect( resp.status() ).toBe( 200 );
    const body = await resp.text();
    expect( body ).not.toContain( 'wp-login.php' );
    expect( body ).toContain( 'Support login could not complete' );
    await agentCtx.close();
} );

test( 'revoke with tl_return=login → redirect uses wps-hide-login slug, NOT wp-login.php', async ( { browser } ) => {
    // wps-hide-login filters site_url / login_url to swap 'wp-login.php'
    // for the configured slug. The SDK's revoke handler is the ONLY
    // place that uses wp_login_url() (Endpoint.php:592 in the
    // tl_return=login branch — fired when an agent revokes from the
    // popup). fail_login uses $referer instead, so this revoke flow is
    // the only real test of "SDK respects wps-hide-login's filter".
    //
    // Reuse sharedIdentifier from beforeAll (which granted before
    // wps-hide-login was activated) so we don't have to log in via
    // /wp-login.php (now 404) to mint a new user.
    const ctx = await browser.newContext();
    await loginClientAdmin( ctx, HIDE_LOGIN_SLUG );

    const nonce = wpCli(
        'wp-cli-client',
        `echo wp_create_nonce( "revoke-tl|" . ${ JSON.stringify( sharedIdentifier ) } );`,
        'create revoke nonce',
    ).trim();

    // Param names mirror the SDK constants — REVOKE_SUPPORT_QUERY_PARAM
    // ('revoke-tl') and SupportUser::ID_QUERY_PARAM ('tlid'), with the
    // nonce action 'revoke-tl|{identifier}' (Endpoint.php:523,
    // SupportUser.php:748). tl_return=login flips the post-revoke
    // redirect from home_url() to wp_login_url() (Endpoint.php:570).
    const revokeUrl = `${ VENDOR_STATE.client_url }/wp-admin/?revoke-tl=${ encodeURIComponent( VENDOR_STATE.namespace ) }&tlid=${ encodeURIComponent( sharedIdentifier ) }&_wpnonce=${ encodeURIComponent( nonce ) }&tl_return=login`;
    const resp = await ctx.request.get( revokeUrl, { maxRedirects: 0 } );

    expect( resp.status(), 'revoke handler should 302 to wp_login_url() with revoked=1' ).toBe( 302 );
    const location = resp.headers()[ 'location' ] ?? '';
    expect( location, 'redirect must NOT leak the legacy /wp-login.php path' ).not.toContain( 'wp-login.php' );
    expect( location, 'redirect must hit the wps-hide-login slug' ).toContain( HIDE_LOGIN_SLUG );
    expect( location, 'revoke marker for the popup postMessage handler' ).toContain( 'revoked=1' );

    await ctx.close();
} );

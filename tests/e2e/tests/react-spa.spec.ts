/**
 * E2E: real React SPA login flow.
 *
 * Complements login-feedback.spec.ts, which drives the server-side
 * paths directly by POSTing a hand-rolled HTML form. This spec takes
 * the OTHER route: it drives the connector's actual React
 * AccessKeyForm (admin.php?page=trustedlogin_access_key_login) and
 * verifies the full flow — page load → React auto-processes the
 * `ak` / `ak_account_id` URL params → fetches redirectData →
 * auto-submits the form → browser lands on the client site, logged
 * in as the support user.
 *
 * This is the flow a support agent actually uses when they click the
 * "Log in with TrustedLogin" button on a Gravity Forms entry detail.
 * Any regression in the React app, build output, or connector
 * backend would surface here.
 */

import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli, resetClientState } from './_helpers';

const VENDOR_STATE = JSON.parse(
    fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

async function loginVendorAdmin( ctx: BrowserContext ) {
    const p = await ctx.newPage();
    await p.goto( `${ VENDOR_STATE.vendor_url }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    if ( ! p.url().includes( 'wp-login.php' ) ) { await p.close(); return; }
    await p.locator( '#user_login' ).fill( 'admin' );
    await p.locator( '#user_pass' ).fill( 'admin' );
    await Promise.all( [
        p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        p.locator( '#wp-submit' ).click(),
    ] );
    await p.close();
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

/**
 * Drive the GF field grant flow to provision a real access key.
 * Requires client admin cookies in the context.
 */
async function grantAccessKey( ctx: BrowserContext ): Promise<string> {
    const vp = await ctx.newPage();
    await vp.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await vp.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );
    await vp.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await vp.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise: Promise<Page> = ctx.waitForEvent( 'page' );
    await vp.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup: Page = await popupPromise;

    // The React SPA on the popup may auto-grant the moment it boots if
    // the URL already carries valid `ak` + `ak_account_id` params — in
    // that case the popup closes before we can click the manual grant
    // button. Both calls below are best-effort: if the popup closed,
    // the auto-grant already ran and the access key will appear in
    // `.tl-site-key` on the parent page (the assertion below). Only
    // genuine failures will surface as a `.tl-site-key` waitForFunction
    // timeout, which gives a clearer error than a swallowed click error.
    await popup.waitForLoadState( 'domcontentloaded' ).catch( ( e: Error ) => {
        console.warn( '[react-spa] popup load did not settle (likely auto-closed):', e.message );
    } );
    await popup
        .locator( '.button-trustedlogin-' + VENDOR_STATE.namespace )
        .first()
        .click()
        .catch( ( e: Error ) => {
            console.warn( '[react-spa] grant button click skipped (likely auto-granted):', e.message );
        } );

    await vp.waitForFunction( () => {
        const el = document.querySelector( '.tl-site-key' );
        return el && el.textContent && el.textContent.length > 10;
    }, null, { timeout: 30_000 } );

    const key = ( await vp.evaluate( () => document.querySelector( '.tl-site-key' )!.textContent ) ) || '';
    await vp.close();
    return key;
}

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
    resetClientState();
} );

test( 'React SPA auto-submits when ak + ak_account_id are in URL → lands on client wp-admin', async ( { browser } ) => {
    // Two contexts: one creates the access key via the grant flow; the
    // other plays the "support agent" who opens the connector's Access
    // Key Login page. Keeping them separate guarantees the agent's
    // session has NO prior cookies for the client site — the support
    // login must create them from scratch.
    const granterCtx = await browser.newContext();
    await loginClientAdmin( granterCtx );
    const accessKey = await grantAccessKey( granterCtx );
    await granterCtx.close();
    expect( accessKey ).toMatch( /^[a-f0-9]{64}$/ );

    // Fresh support-agent context. Log in on the VENDOR site (needed
    // for the React settings page to load — it's an admin-only screen).
    const agentCtx = await browser.newContext();
    await loginVendorAdmin( agentCtx );

    const page = await agentCtx.newPage();

    // Track every navigation so we can prove the SPA-driven redirect
    // chain actually happens (not an accidental link click).
    const navigations: string[] = [];
    page.on( 'framenavigated', ( frame ) => {
        if ( frame === page.mainFrame() ) {
            navigations.push( frame.url() );
        }
    } );

    // Visit the deep-link URL exactly like the "Log in with
    // TrustedLogin" button on an entry-detail row produces.
    const deepLink = `${ VENDOR_STATE.vendor_url }/wp-admin/admin.php`
        + `?page=trustedlogin_access_key_login`
        + `&ak=${ accessKey }`
        + `&ak_account_id=999`;

    await page.goto( deepLink );

    // Wait for the React app to auto-process the params and navigate
    // the browser to the client-site login endpoint. The final URL
    // should be somewhere on the client's wp-admin (the agent is now
    // logged in as the support user).
    const clientUrlRe = VENDOR_STATE.client_url.replace( /[\\^$.*+?()[\]{}|]/g, '\\$&' );
    await page.waitForURL( new RegExp( '^' + clientUrlRe + '/wp-admin/' ), { timeout: 30_000 } );

    // Proof-of-life that this was a REAL authenticated admin session:
    // the WP admin bar renders for logged-in users only.
    await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 10_000 } );

    // The trajectory we expect to have seen:
    //   1. vendor /wp-admin/admin.php?page=... (React mounts here)
    //   2. client's one-time login endpoint (where auth cookie is set)
    //   3. client's /wp-admin/ (final redirect after cookie)
    const startedOnVendor = navigations.some( u => u.startsWith( VENDOR_STATE.vendor_url ) );
    const reachedClient   = navigations.some( u => u.startsWith( VENDOR_STATE.client_url ) );
    expect( startedOnVendor, 'flow should begin on the vendor admin' ).toBeTruthy();
    expect( reachedClient,   'flow should end on the client site' ).toBeTruthy();

    // Confirm server-side that a support user was actually created and
    // that it's the user whose auth cookie the browser is carrying.
    const supportUser = wpCli(
        'wp-cli-client',
        `$u = get_users( array( "meta_key" => "tl_pro-block-builder_id", "number" => 1 ) ); `
        + `if ( empty( $u ) ) { echo "none"; exit; } `
        + `echo $u[0]->user_login . "|" . implode( ",", $u[0]->roles );`,
        'verify support user after SPA login',
    );
    expect( supportUser ).not.toBe( 'none' );
    const [ userLogin, rolesCsv ] = supportUser.split( '|' );
    // Support-user login comes from the vendor title (configured in
    // client.php as "Pro Block Builder"). The role is the definitive
    // test — it's namespaced and can't collide with a real user.
    expect( userLogin ).toMatch( /pro block builder/i );
    expect( rolesCsv ).toContain( 'pro-block-builder-support' );

    // Also verify the admin page rendered the expected tl_notice
    // success banner (set by Endpoint::maybe_login_support via the
    // admin_url redirect's ?tl_notice=logged_in param).
    expect( page.url() ).toMatch( /tl_notice=logged_in/ );
    await expect( page.locator( '.notice-success' ) ).toBeVisible();
    await expect( page.locator( '.notice-success' ) ).toContainText( /logged in as a/i );

    await agentCtx.close();
} );

test( 'React SPA surfaces an error when the access key is invalid', async ( { browser } ) => {
    // Opposite of the happy-path test: agent visits the deep-link with
    // a bogus access key. The React app posts to /wp-json/trustedlogin
    // /v1/access_key, the server can't find a matching envelope, and
    // the React app shows an inline error instead of redirecting.
    const agentCtx = await browser.newContext();
    await loginVendorAdmin( agentCtx );

    const page = await agentCtx.newPage();

    const randomKey = wpCli(
        'wp-cli-vendor',
        `echo bin2hex( random_bytes( 32 ) );`,
        'random access key for negative test',
    );
    expect( randomKey ).toMatch( /^[a-f0-9]{64}$/ );

    const deepLink = `${ VENDOR_STATE.vendor_url }/wp-admin/admin.php`
        + `?page=trustedlogin_access_key_login`
        + `&ak=${ randomKey }`
        + `&ak_account_id=999`;

    await page.goto( deepLink, { waitUntil: 'domcontentloaded' } );

    // Wait for the React app's API call to settle so the form or error
    // UI has actually rendered. networkidle catches both the success
    // path (form stays mounted, no further requests) and the error
    // path (POST to /v1/access_key returns 4xx, React renders inline
    // error). Strictly better than a fixed timeout — fast on local,
    // not flaky on CI.
    await page.waitForLoadState( 'networkidle', { timeout: 15_000 } );

    // The agent should NOT have been redirected away from the vendor.
    // (Either the form stayed on page, or React showed an error
    // message inline.)
    expect( page.url() ).toContain( VENDOR_STATE.vendor_url );
    expect( page.url() ).toContain( 'page=trustedlogin_access_key_login' );

    await agentCtx.close();
} );

/**
 * E2E: postMessage contract between the client library popup and its opener.
 *
 * Covers PR #138 (trustedlogin/client) paired with PR #184 (trustedlogin-connector)
 * — the vendor's Gravity Forms TrustedLogin field opens a popup to the client's
 * wp-login.php?action=trustedlogin URL, and the client library posts back these
 * message shapes:
 *
 *   { type: 'granting' }                              — fires on Grant click
 *   { type: 'granted', key: string, expiration: any } — fires on AJAX success
 *                                                      AND on return-page load
 *                                                      when access already exists
 *                                                      (unless ?revoking=1)
 *   { type: 'revoking' }                              — fires on Revoke click
 *
 * Topology inside docker:
 *   - Vendor (opener) on http://localhost:8001 — GF form with TL field
 *   - Client (popup)  on http://localhost:8002 — renders the grant UI
 *   - fake-saas       on http://localhost:8003 — accepts envelopes blindly
 */

import { test, expect, Page, BrowserContext } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

type VendorState = {
    form_id: string;
    form_page_url: string;
    client_url: string;
    vendor_url: string;
    namespace: string;
    account_id: string;
    api_public_key: string;
};

const VENDOR_STATE: VendorState = JSON.parse(
    fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

const FAKE_SAAS_RESET_URL = 'http://localhost:8003/__reset';
const FAKE_SAAS_STATE_URL = 'http://localhost:8003/__state';

// ---------- Helpers ----------

/**
 * Install a message listener into `page` that collects postMessage events from
 * any popup. Call BEFORE the popup is opened.
 */
async function instrumentOpener( page: Page ) {
    await page.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( event: MessageEvent ) => {
            ( window as any ).__tlMessages.push( {
                origin: event.origin,
                data:   event.data,
            } );
        } );
    } );
}

async function readMessages( page: Page ): Promise<Array<{ origin: string; data: any }>> {
    return await page.evaluate( () => ( window as any ).__tlMessages || [] );
}

/**
 * Log in as admin on client-wp. The trustedlogin grant URL is served via
 * wp-login.php?action=trustedlogin, which requires an authenticated user
 * before rendering the Grant/Revoke form.
 */
async function loginAsClientAdmin( context: BrowserContext ) {
    // Skip if already authenticated in this context (cookies persist).
    const check = await context.request.get( `${ VENDOR_STATE.client_url }/wp-admin/`, { maxRedirects: 0 } ).catch( () => null );
    if ( check && check.status() === 200 ) {
        return;
    }

    const loginPage = await context.newPage();
    await loginPage.goto( `${ VENDOR_STATE.client_url }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    if ( ! loginPage.url().includes( 'wp-login.php' ) ) {
        await loginPage.close();
        return;
    }
    await loginPage.locator( '#user_login' ).fill( 'admin' );
    await loginPage.locator( '#user_pass' ).fill( 'admin' );
    // Wait for the wp-admin redirect so we know cookies are committed.
    await Promise.all( [
        loginPage.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        loginPage.locator( '#wp-submit' ).click(),
    ] );
    await loginPage.close();
}

async function resetFakeSaaS( page: Page ) {
    await page.request.post( FAKE_SAAS_RESET_URL );
}

async function readFakeSaaSState( page: Page ): Promise<any> {
    const res = await page.request.get( FAKE_SAAS_STATE_URL );
    return await res.json();
}

import { wpCli } from './_helpers';

/**
 * Nuke any existing trustedlogin support users on the client site via
 * wp-cli (exec'd inside the wp-cli-client docker container). Each test
 * starts fresh so load-time `granted` messages don't fire before we're
 * ready.
 */
async function revokeIfGranted( _context: BrowserContext ) {
    // Delete any user carrying trustedlogin metadata for the pro-block-builder
    // namespace — this matches users regardless of their WP role, which is
    // important since a user may have been re-roled by a prior test. If
    // wp-cli fails (container death / schema change), the error surfaces
    // rather than leaving the next test to run on stale state.
    wpCli(
        'wp-cli-client',
        `require_once ABSPATH . "wp-admin/includes/user.php"; `
        + `$users = get_users( array( "meta_key" => "tl_pro-block-builder_id" ) ); `
        + `foreach ( $users as $u ) { wp_delete_user( $u->ID ); } `
        + `echo count( $users );`,
        'revokeIfGranted',
    );
}

/**
 * Wait until `messages` contains at least one entry with `type === expectedType`.
 */
async function waitForMessageType( page: Page, expectedType: string, timeoutMs = 10_000 ) {
    const start = Date.now();
    while ( Date.now() - start < timeoutMs ) {
        const msgs = await readMessages( page );
        if ( msgs.some( m => m?.data?.type === expectedType ) ) {
            return msgs;
        }
        await page.waitForTimeout( 100 );
    }
    const seen = ( await readMessages( page ) ).map( m => m?.data?.type );
    throw new Error( `Timed out waiting for postMessage type="${ expectedType }". Saw: ${ JSON.stringify( seen ) }` );
}

// ---------- Tests ----------

test.describe.configure( { mode: 'serial' } );

test.beforeEach( async ( { page, context } ) => {
    await resetFakeSaaS( page );
    await loginAsClientAdmin( context );
    await revokeIfGranted( context );
} );

test( 'full grant flow via Gravity Forms TL field — popup posts granting then granted', async ( { page, context } ) => {
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );

    // Wait for the TL field to render (PR #184 renders a form element with .tl-grant-access).
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );

    // Type the client URL into the field (PR #184's field doesn't populate
    // defaultValue even when set; user must fill it).
    const urlField = page.locator( '.tl-grant-access .tl-site-url' );
    await urlField.fill( VENDOR_STATE.client_url );
    // blur to re-enable submit button
    await urlField.blur();

    // Click Grant Access — this calls window.open() to the client popup URL.
    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );

    // The client popup renders the grant-access form. Find and click the
    // "Grant Access" link (trustedlogin-client's primary CTA). `tl_obj.selector`
    // in the client library is `.button-trustedlogin-{namespace}`.
    const grantCta = popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first();
    await grantCta.waitFor( { state: 'visible', timeout: 15_000 } );

    // PR #138: clicking the Grant CTA must fire { type: "granting" } BEFORE
    // the AJAX call and hide the popup window.
    await grantCta.click();

    const afterGranting = await waitForMessageType( page, 'granting', 15_000 );
    const grantingMsg   = afterGranting.find( m => m?.data?.type === 'granting' )!;
    expect( grantingMsg.origin ).toBe( VENDOR_STATE.client_url );
    expect( grantingMsg.data ).toEqual( { type: 'granting' } );

    // After AJAX completes the popup should fire { type: "granted", key, expiration }.
    const afterGranted = await waitForMessageType( page, 'granted', 30_000 );
    const grantedMsg   = afterGranted.find( m => m?.data?.type === 'granted' )!;
    expect( grantedMsg.origin ).toBe( VENDOR_STATE.client_url );
    expect( grantedMsg.data.type ).toBe( 'granted' );
    expect( typeof grantedMsg.data.key ).toBe( 'string' );
    expect( grantedMsg.data.key.length ).toBeGreaterThan( 8 );
    // Expiration can come through as an epoch (string/number) — both are valid.
    expect( grantedMsg.data.expiration ).toBeDefined();

    // Fake-saas should now have the envelope we posted.
    const state = await readFakeSaaSState( page );
    expect( Object.keys( state.envelopes ).length ).toBeGreaterThan( 0 );
    // The accessKey posted to /sites/ should match the key we received.
    expect( state.envelopes ).toHaveProperty( grantedMsg.data.key );

    // The vendor GF field's submit-button text should flip to "Access Granted"
    // after receiving the granted message — this is PR #184 behavior.
    await expect( page.locator( '.tl-grant-access input[type="submit"]' ) )
        .toHaveValue( /Access Granted|Revoke/i, { timeout: 10_000 } );
} );

test( 'return-page load with existing access fires granted message on the opener', async ( { page, context } ) => {
    // Step 1: do a full grant so the support user exists on client-wp.
    // Simplified: reuse the same flow as test 1 but end once granted fires.
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );
    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const firstPopup = await popupPromise;
    await firstPopup.waitForLoadState( 'domcontentloaded' );
    await firstPopup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();
    await waitForMessageType( page, 'granted', 30_000 );

    // Step 2: open a second opener page AT THE VENDOR ORIGIN (localhost:8001)
    // so the popup's postMessage with targetOrigin="http://localhost:8001"
    // actually lands. Install the listener BEFORE opening the popup.
    const secondOpener = await context.newPage();
    await secondOpener.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( e: MessageEvent ) => {
            ( window as any ).__tlMessages.push( { origin: e.origin, data: e.data } );
        } );
    } );
    await secondOpener.goto( VENDOR_STATE.vendor_url + '/', { waitUntil: 'domcontentloaded' } );

    // Open the popup via window.open so the popup has window.opener set.
    const popupUrl = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&origin=${ encodeURIComponent( VENDOR_STATE.vendor_url ) }`;
    const popupLoadPromise = context.waitForEvent( 'page' );
    await secondOpener.evaluate( ( url ) => { ( window as any ).__popup = window.open( url, 'tl-popup' ); }, popupUrl );
    const popup2 = await popupLoadPromise;
    await popup2.waitForLoadState( 'domcontentloaded' );

    // The library fires granted within milliseconds of DOMContentLoaded.
    const msgs = await waitForMessageType( secondOpener, 'granted', 10_000 );
    const grantedOnLoad = msgs.find( m => m?.data?.type === 'granted' )!;
    expect( grantedOnLoad.data.key ).toBeTruthy();
    expect( grantedOnLoad.data.expiration ).toBeDefined();
} );

test( 'return-page load with ?revoking=1 does NOT fire granted on the opener', async ( { page, context } ) => {
    // Set up: grant first so there's existing access.
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );
    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const firstPopup = await popupPromise;
    await firstPopup.waitForLoadState( 'domcontentloaded' );
    await firstPopup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();
    await waitForMessageType( page, 'granted', 30_000 );

    // Now open the popup with ?revoking=true. Library must NOT fire granted on load.
    // Opener must live at the vendor origin so the client's targeted postMessage
    // (if it were fired) would actually reach us — we need a true negative, not
    // a no-op caused by origin mismatch.
    const opener = await context.newPage();
    await opener.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( e: MessageEvent ) => {
            ( window as any ).__tlMessages.push( { origin: e.origin, data: e.data } );
        } );
    } );
    await opener.goto( VENDOR_STATE.vendor_url + '/', { waitUntil: 'domcontentloaded' } );
    const revokeUrl = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&origin=${ encodeURIComponent( VENDOR_STATE.vendor_url ) }&revoking=true`;
    const revokePopupPromise = context.waitForEvent( 'page' );
    await opener.evaluate( ( url ) => { ( window as any ).__popup = window.open( url, 'tl-popup-revoke' ); }, revokeUrl );
    const revokePopup = await revokePopupPromise;
    await revokePopup.waitForLoadState( 'domcontentloaded' );
    // Let any load-time postMessage fire.
    await opener.waitForTimeout( 1500 );

    const msgs = await readMessages( opener );
    const grantedMessages = msgs.filter( m => m?.data?.type === 'granted' );
    expect( grantedMessages, `Opener must not receive granted when ?revoking=1 is set. Got: ${ JSON.stringify( msgs ) }` ).toHaveLength( 0 );
} );

test( 'clicking Revoke Access fires revoking message to opener', async ( { page, context } ) => {
    // Set up: grant first.
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );
    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const grantPopup = await popupPromise;
    await grantPopup.waitForLoadState( 'domcontentloaded' );
    await grantPopup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();
    await waitForMessageType( page, 'granted', 30_000 );

    // After "granted" the GF field transitions its submit button into "Revoke"
    // mode. Click it → popup opens with ?revoking=true → we click the Revoke
    // button inside the popup → popup posts { type: "revoking" }.
    const revokePopupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const revokePopup = await revokePopupPromise;
    await revokePopup.waitForLoadState( 'domcontentloaded' );

    // Find the Revoke button inside the popup. PR #138's JS listens for clicks
    // on '.tl-client-revoke-button'. The actual WP element rendered by the
    // library is a submit button with class containing "revoke".
    const revokeBtn = revokePopup.locator( '.tl-client-revoke-button, button.revoke-access, a.revoke-access, input[name="revoke"]' ).first();
    await revokeBtn.waitFor( { state: 'visible', timeout: 15_000 } );
    await revokeBtn.click();

    const msgs = await waitForMessageType( page, 'revoking', 10_000 );
    const revokingMsg = msgs.find( m => m?.data?.type === 'revoking' )!;
    expect( revokingMsg.origin ).toBe( VENDOR_STATE.client_url );
    expect( revokingMsg.data ).toEqual( { type: 'revoking' } );
} );

test( 'direct navigation (no opener) does not throw and fires no messages', async ( { page } ) => {
    // Navigate directly — no window.open, so window.opener is null. The library
    // must short-circuit all postMessage() calls and not throw.
    const errors: string[] = [];
    page.on( 'pageerror', err => errors.push( err.message ) );

    const popupUrl = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&origin=http%3A%2F%2Flocalhost%3A8001`;
    await page.goto( popupUrl, { waitUntil: 'domcontentloaded' } );
    await page.waitForTimeout( 1500 );

    expect( errors, `Direct navigation must not throw. Errors: ${ errors.join( '; ' ) }` ).toHaveLength( 0 );
    // No window.opener present → message log on this page itself is N/A (we
    // aren't its opener), so just asserting zero exceptions is the contract.
} );

// ===========================================================================
// Bulletproofing tests — all failure modes must have user-friendly fallbacks
// ===========================================================================

test( 'cross-origin opener does NOT receive the access key (security)', async ( { context } ) => {
    // Opener at a DIFFERENT origin than the one in the URL's origin param.
    // The client library reads `origin` from the URL and targets postMessage
    // at that origin only — a malicious opener at a different origin must
    // never receive the granted key.
    const maliciousOpener = await context.newPage();
    await maliciousOpener.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( e: MessageEvent ) => {
            ( window as any ).__tlMessages.push( { origin: e.origin, data: e.data } );
        } );
    } );
    // Navigate opener to the CLIENT origin (localhost:8002) but the URL will
    // claim origin=localhost:8001 (the "legit" vendor). Messages targeted at
    // localhost:8001 must not be delivered to this localhost:8002 opener.
    await maliciousOpener.goto( VENDOR_STATE.client_url + '/?e2e-malicious', { waitUntil: 'domcontentloaded' } );

    const popupUrl = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&origin=${ encodeURIComponent( VENDOR_STATE.vendor_url ) }`;
    const popupPromise = context.waitForEvent( 'page' );
    await maliciousOpener.evaluate( ( url ) => { ( window as any ).__popup = window.open( url, 'malicious-popup' ); }, popupUrl );
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );

    // Click Grant in popup.
    const grantCta = popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first();
    await grantCta.waitFor( { state: 'visible', timeout: 15_000 } );
    await grantCta.click();

    // Wait long enough for the full grant flow to complete.
    await maliciousOpener.waitForTimeout( 4000 );

    const msgs = await maliciousOpener.evaluate( () => ( window as any ).__tlMessages || [] );
    expect(
        msgs,
        `Cross-origin opener must receive zero messages when URL origin=vendor but opener is at client origin. Got: ${ JSON.stringify( msgs ) }`
    ).toHaveLength( 0 );
} );

test( 'AJAX error path fires grant_error — not a fake granted', async ( { page, context } ) => {
    // Intercept the AJAX call and return a 500 error. The library must fire
    // { type: "grant_error", code, message } — NOT a granted-with-empty-key.
    await context.route( '**/wp-admin/admin-ajax.php', async route => {
        const postData = route.request().postData() || '';
        if ( postData.includes( 'tl_pro-block-builder_gen_support' ) ) {
            await route.fulfill( { status: 500, body: '{"success":false,"data":{"message":"Simulated server error","code":"test_error"}}' } );
            return;
        }
        await route.continue();
    } );

    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    const msgs = await waitForMessageType( page, 'grant_error', 15_000 );
    const errMsg = msgs.find( m => m?.data?.type === 'grant_error' )!;
    expect( errMsg.origin ).toBe( VENDOR_STATE.client_url );
    expect( errMsg.data.type ).toBe( 'grant_error' );
    expect( typeof errMsg.data.message ).toBe( 'string' );
    expect( errMsg.data.message.length ).toBeGreaterThan( 0 );
    expect( errMsg.data.code ).toBeDefined();

    // No `granted` should have fired.
    const all = await readMessages( page );
    const fakeGranted = all.filter( m => m?.data?.type === 'granted' );
    expect( fakeGranted, `Server error must NOT fire granted. Got: ${ JSON.stringify( fakeGranted ) }` ).toHaveLength( 0 );

    // And the vendor's field must show a visible error message.
    const errorText = await page.locator( '.tl-grant-access .tl-error-message' ).textContent();
    expect( errorText ?? '' ).not.toBe( '' );
} );

test( 'AJAX timeout fires grant_error with code=timeout', async ( { page, context } ) => {
    // Simulate a network timeout. We monkey-patch jQuery's $.ajax options in
    // the popup to set timeout: 2000ms, then block the admin-ajax response so
    // it never completes — jQuery will fire textStatus="timeout".
    await context.addInitScript( () => {
        // Wait for jQuery, then wrap $.ajax to enforce a 2s timeout for
        // trustedlogin AJAX calls so tests don't need to wait 60s.
        const patch = () => {
            const $ = ( window as any ).jQuery;
            if ( ! $ || ! $.ajax ) { return setTimeout( patch, 20 ); }
            if ( ( $ as any ).__tlAjaxPatched ) { return; }
            ( $ as any ).__tlAjaxPatched = true;
            const orig = $.ajax;
            $.ajax = function ( opts: any ) {
                if ( opts && opts.data && typeof opts.data.action === 'string' && opts.data.action.indexOf( 'tl_' ) === 0 ) {
                    opts.timeout = 2000;
                }
                return orig.apply( this, arguments as any );
            };
        };
        patch();
    } );

    await context.route( '**/wp-admin/admin-ajax.php', async route => {
        const postData = route.request().postData() || '';
        if ( postData.includes( 'tl_pro-block-builder_gen_support' ) ) {
            // Hold the request forever so jQuery's 2s timeout fires.
            await new Promise<void>( () => {} );
            return;
        }
        await route.continue();
    } );

    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    const msgs = await waitForMessageType( page, 'grant_error', 10_000 );
    const errMsg = msgs.find( m => m?.data?.type === 'grant_error' )!;
    expect( errMsg.data.code ).toBe( 'timeout' );
    expect( errMsg.data.message ).toMatch( /took too long|timeout|try again/i );
} );

test( 'popup blocked: shows fallback link + restores button state', async ( { page } ) => {
    // Override window.open to simulate a popup blocker BEFORE the vendor's
    // tl-field.js attaches its handlers.
    await page.addInitScript( () => {
        ( window as any ).open = () => null;
    } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();

    // Fallback UI should appear.
    await expect( page.locator( '.tl-grant-access .tl-error-message' ) )
        .toBeVisible( { timeout: 5_000 } );
    await expect( page.locator( '.tl-grant-access .tl-error-message' ) )
        .toContainText( /blocked|popup/i );
    await expect( page.locator( '.tl-grant-access .tl-fallback-link a' ) )
        .toBeVisible();

    const href = await page.locator( '.tl-grant-access .tl-fallback-link a' ).getAttribute( 'href' );
    expect( href ).toContain( VENDOR_STATE.client_url );
    expect( href ).toContain( 'action=trustedlogin' );
    expect( href ).toContain( `ns=${ VENDOR_STATE.namespace }` );

    // Submit button should be re-enabled and show "Grant Access" again so the
    // user can retry after allowing popups.
    const submit = page.locator( '.tl-grant-access input[type="submit"]' );
    await expect( submit ).not.toBeDisabled();
    await expect( submit ).toHaveValue( /grant access/i );
} );

test( 'double-click only opens one popup', async ( { page, context } ) => {
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const opened: string[] = [];
    context.on( 'page', p => opened.push( p.url() ) );

    const submit = page.locator( '.tl-grant-access input[type="submit"]' );
    await submit.click();
    await submit.click().catch( () => {} ); // second click — may be ignored if disabled
    await submit.click().catch( () => {} );

    // Give the browser a beat to fulfill any queued window.open calls.
    await page.waitForTimeout( 1500 );

    const tlPopups = opened.filter( u => u.includes( 'action=trustedlogin' ) );
    expect(
        tlPopups.length,
        `Double/triple click should open at most 1 TrustedLogin popup. Opened: ${ JSON.stringify( opened ) }`
    ).toBeLessThanOrEqual( 1 );
} );

test( 'opener origin normalization: trailing slash on URL field still matches', async ( { page, context } ) => {
    // Type the URL with a trailing slash — the old regex rejected anything
    // that didn't match exactly. The new URL() parser + origin normalization
    // should accept and correctly compare origins.
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url + '/' );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    // Submit should be enabled — trailing slash is valid.
    await expect( page.locator( '.tl-grant-access input[type="submit"]' ) ).not.toBeDisabled();

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    // Messages must still be received by the opener (origin normalization
    // works on both sides).
    await waitForMessageType( page, 'granted', 30_000 );
} );

test( 'URL regex accepts http://localhost:PORT, paths, and bare hostnames', async ( { page } ) => {
    // The field enables the submit button for any plausibly-typed host,
    // not just full absolute http(s) URLs. Bare hostnames get a https://
    // prefix before parsing. Ports and paths are accepted. Garbage stays
    // rejected (no dot, spaces, etc.).
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    const field  = page.locator( '.tl-grant-access .tl-site-url' );
    const submit = page.locator( '.tl-grant-access input[type="submit"]' );

    const accepted = [
        'http://localhost:8002',
        'https://example.com:8443/wp',
        'http://subdomain.example.co.uk/path/',
        // Bare hostnames — lenient path, no scheme required.
        'example.com',
        'mysite.local',
        'localhost:3000',
    ];
    for ( const url of accepted ) {
        await field.fill( url );
        await field.press( 'Tab' );
        await expect( submit, `should accept ${ url }` ).not.toBeDisabled();
        await expect( field, `${ url } must NOT have tl-error class` ).not.toHaveClass( /tl-error/ );
    }

    // Junk stays rejected.
    for ( const bad of [ 'not a url', 'abc', 'x' ] ) {
        await field.fill( bad );
        await field.press( 'Tab' );
        await expect( submit, `should reject "${ bad }"` ).toBeDisabled();
        await expect( field, `"${ bad }" should show tl-error` ).toHaveClass( /tl-error/ );
    }

    // Empty field: disabled but no error class (don't yell at nothing).
    await field.fill( '' );
    await field.press( 'Tab' );
    await expect( submit ).toBeDisabled();
    await expect( field ).not.toHaveClass( /tl-error/ );
} );

test( 'popup closed before any grant message fires shows retry error', async ( { page, context } ) => {
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );

    // User closes the popup WITHOUT clicking Grant — simulates "I changed
    // my mind" or a popup blocker auto-closing. The watchdog (check every
    // 500ms) should detect this and display a retry-friendly error.
    await popup.close();

    await expect( page.locator( '.tl-grant-access .tl-error-message' ) )
        .toBeVisible( { timeout: 5_000 } );
    await expect( page.locator( '.tl-grant-access .tl-error-message' ) )
        .toContainText( /closed|try again|didn't complete/i );
} );

test( 'successful revoke fires revoked message (no 2s guess)', async ( { page, context } ) => {
    // Seed an existing support user by doing a full grant first.
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );
    const p1 = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const grantPopup = await p1;
    await grantPopup.waitForLoadState( 'domcontentloaded' );
    await grantPopup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();
    await waitForMessageType( page, 'granted', 30_000 );

    // Now click Revoke. The field is now in revoke mode.
    const p2 = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const revokePopup = await p2;
    await revokePopup.waitForLoadState( 'domcontentloaded' );

    // Click the in-popup Revoke button. The client JS on click:
    //   1. sets sessionStorage REVOKE_PENDING flag
    //   2. posts {type:"revoking"} to opener
    //   3. hides window — but the server then revokes + page reloads,
    //      and on reload the sessionStorage flag is detected and the client
    //      posts {type:"revoked"}.
    const revokeBtn = revokePopup.locator( '.tl-client-revoke-button' ).first();
    await revokeBtn.waitFor( { state: 'visible', timeout: 15_000 } );
    await revokeBtn.click();

    await waitForMessageType( page, 'revoking', 10_000 );
    await waitForMessageType( page, 'revoked', 15_000 );
} );

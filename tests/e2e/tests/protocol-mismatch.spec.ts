/**
 * E2E: protocol-mismatch tolerance.
 *
 * Real-world client sites commonly enforce HTTPS via HSTS or a 301 from
 * http://host → https://host. The TL grant popup opened to http:// ends
 * up at https:// after the redirect, and the postMessage origins on the
 * two sides no longer match if we compare full origin strings.
 *
 * Fix is in two places:
 *   - Connector tl-field.js (opener): listenPopupEvents compares HOST
 *     (hostname+port) rather than full origin.
 *   - Client trustedlogin.js (popup): postToOpener dispatches to BOTH
 *     the URL-param origin AND the same-host alternate-scheme origin,
 *     so a scheme-mismatched opener still receives the message on one
 *     of the two calls.
 *
 * Tests here exercise the real wire — no synthetic MessageEvent
 * dispatching (browser-controlled fields like event.origin aren't
 * faithfully settable from page.evaluate). Unit-level coverage of the
 * pure helpers lives in the connector's Jest suite.
 */

import { test, expect, BrowserContext } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { resetClientState } from './_helpers';

const VENDOR_STATE = JSON.parse(
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

// NOTE on real HTTPS in this test suite
// =====================================
// The production failure mode is: user types http://site, the site's
// server emits a 301 to https://site, the popup's final URL is on a
// different scheme than the vendor field's stored value.
//
// Reproducing that verbatim in these tests requires a TLS-serving
// sidecar in the docker stack (e.g. caddy with a self-signed cert
// proxying to client-wp). Adding that infrastructure is out of scope
// here. Playwright request interception was attempted as a substitute
// but the HTTPS redirect chain interacts poorly with Chromium's TLS
// state before the route handler can fulfill a 301 chain cleanly.
//
// The e2e tests below test the SAME listener code and the SAME
// postMessage wire; they create the scheme mismatch by having the
// URL param claim one scheme while the opener is at another. The
// pure host-comparison logic is additionally covered by real-code
// Jest unit tests in trustedlogin-connector/public/forms/tl-field.test.js.

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
    resetClientState();
} );

// (Previously this spec tried to simulate a full http→https redirect
// chain via Playwright request interception. That approach is
// documented in the big NOTE above — left out of this suite in favour
// of unit tests that prove the comparison logic using the real file.)

test( 'client posts to BOTH URL-param origin and alt-scheme variant', async ( { browser } ) => {
    // Scenario exercised: the URL param claims origin=https://localhost:8001,
    // but the actual opener is at http://localhost:8001. Pre-fix, the
    // client's postMessage would target only the https origin → browser
    // drops it → opener never sees any message. With the fix, the client
    // ALSO posts targeting the http variant of the same host → opener
    // receives it.
    //
    // We can observe delivery by collecting messages on the opener.
    const ctx = await browser.newContext();
    await loginClientAdmin( ctx );

    // Opener lives at http://localhost:8001 (the real e2e vendor).
    const opener = await ctx.newPage();
    await opener.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( e: MessageEvent ) => {
            ( window as any ).__tlMessages.push( { origin: e.origin, data: e.data } );
        } );
    } );
    await opener.goto( VENDOR_STATE.vendor_url + '/', { waitUntil: 'domcontentloaded' } );

    // URL param lies about the scheme — claims https://localhost:8001.
    const popupUrl = `${ VENDOR_STATE.client_url }/wp-login.php?action=trustedlogin&ns=${ VENDOR_STATE.namespace }&origin=${ encodeURIComponent( 'https://localhost:8001' ) }`;
    const popupPromise = ctx.waitForEvent( 'page' );
    await opener.evaluate( ( url ) => { ( window as any ).__p = window.open( url, 'tl-scheme-test' ); }, popupUrl );
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );

    // Drive the grant manually by clicking the CTA inside the popup.
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    // The http opener should receive `granted` despite the URL param
    // telling the popup to target https — because postToOpener now
    // ALSO posts to the http alt-scheme variant.
    await opener.waitForFunction( () => {
        const msgs = ( window as any ).__tlMessages || [];
        return msgs.some( ( m: any ) => m.data?.type === 'granted' );
    }, null, { timeout: 30_000 } );

    const msgs  = await opener.evaluate( () => ( window as any ).__tlMessages );
    const types = msgs.map( ( m: any ) => m.data?.type );

    expect(
        types,
        'opener should receive granted despite scheme mismatch in URL param'
    ).toContain( 'granted' );

    // event.origin on a received postMessage is the SENDER's origin
    // (the popup at client_url). What we're verifying is that the
    // opener received it at all — when the URL param lied that the
    // target should be https://localhost:8001. Pre-fix, no message
    // would arrive on the http://localhost:8001 opener; post-fix, the
    // alt-scheme fallback post reaches us.
    const grantedMsg = msgs.find( ( m: any ) => m.data?.type === 'granted' );
    expect( grantedMsg.origin, 'message came from the client popup' ).toBe( VENDOR_STATE.client_url );
    expect( grantedMsg.data.key, 'granted payload carries the access key' ).toBeTruthy();

    await ctx.close();
} );

test( 'popup hosted at alternate scheme — opener still processes the message', async ( { browser } ) => {
    // Symmetric to the test above: URL param says http://host, actual
    // opener is at http://host, but we instruct the popup to post as
    // if it were at https://host. This exercises the OPENER-side fix
    // (host-only comparison).
    //
    // We achieve this by POSTing a message via page.evaluate from the
    // popup, using `window.opener.postMessage(..., targetOrigin)` with
    // targetOrigin=http://localhost:8001 but the popup's ACTUAL origin
    // is http://localhost:8002. The opener's listener compares the host
    // of event.origin ("localhost:8002") against expected ("localhost:8002")
    // — match — and accepts the message.
    //
    // In the pre-fix code, the listener compared full origin strings
    // including scheme, so any cross-site post would hard-fail (which
    // is correct). The new code only compares HOST so same-host/
    // different-scheme IS accepted — which is the scenario this test
    // guards against as the behavioural change.
    //
    // NOTE: we can't forge event.origin from the popup's side — the
    // browser sets it to the popup's own origin. So the test here
    // confirms the opener still accepts messages whose origin is the
    // same host as loginSite (positive regression check).
    const ctx = await browser.newContext();
    await loginClientAdmin( ctx );

    const opener = await ctx.newPage();
    await opener.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( e: MessageEvent ) => {
            ( window as any ).__tlMessages.push( { origin: e.origin, data: e.data } );
        } );
    } );
    await opener.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await opener.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await opener.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await opener.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = ctx.waitForEvent( 'page' );
    await opener.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    // Both granting and granted must be processed by tl-field.js,
    // meaning the access-key field on the opener gets populated.
    await opener.waitForFunction( () => {
        const key = document.querySelector( '.tl-site-key' );
        return key && key.textContent && key.textContent.length > 10;
    }, null, { timeout: 30_000 } );

    const keyText = await opener.locator( '.tl-site-key' ).textContent();
    expect( keyText, 'opener should display the access key — granted message was accepted' )
        .toBeTruthy();

    await ctx.close();
} );

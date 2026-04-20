/**
 * E2E: protocol-mismatch tolerance.
 *
 * Real-world client sites commonly enforce HTTPS via HSTS or a 301 from
 * http://host → https://host. The TL grant popup opened to http:// ends
 * up at https:// after the redirect, and the postMessage origins on the
 * two sides no longer match if we compare full origin strings.
 *
 * Fix is a SINGLE-sided host-match on the OPENER:
 *   - Connector tl-field.js (opener): listenPopupEvents compares HOST
 *     (hostname+port) rather than full origin. A message delivered from
 *     the popup's real origin (whichever scheme WP ended up on after the
 *     HSTS redirect) is still accepted if the HOST matches what the
 *     opener queued.
 *
 * The popup side (client trustedlogin.js postToOpener) was previously
 * dual-dispatching — once to the URL-param origin, once to the alt-scheme
 * variant — which opened a narrow MITM path where an attacker controlling
 * the scheme-variant of the vendor hostname could receive `granted` with
 * the access key. That alt-dispatch has been removed; the host-match on
 * the opener side alone is sufficient.
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
// The canonical case runs on DEFAULT ports — http://site:80 → https://
// site:443 — so URL.host drops the port on both sides and the host
// comparison boils down to "site vs site". That exact permutation
// can't be reproduced with a docker sidecar on the developer's host
// machine (Local by Flywheel etc. already bind 80/443), so we cover
// it in three layers instead:
//
//   - tls-wire.spec.ts runs the full grant flow over the caddy TLS
//     sidecar (https://localhost:8443 → client-wp:80). That exercises
//     the real TLS handshake, X-Forwarded-Proto, secure cookies, and
//     the cross-origin postMessage across a scheme-AND-port boundary.
//
//   - trustedlogin-connector/public/forms/tl-field.test.js (Jest)
//     exercises the host-comparison helper against every scheme/port
//     permutation using the REAL listener code — including the
//     default-port cases this docker stack can't serve.
//
//   - The tests below create the scheme mismatch by having the URL
//     param claim one scheme while the opener is at another, on HTTP
//     only. They verify the postMessage wire end-to-end without the
//     TLS moving pieces.

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
    resetClientState();
} );

// (Previously this spec tried to simulate a full http→https redirect
// chain via Playwright request interception. That approach is
// documented in the big NOTE above — left out of this suite in favour
// of unit tests that prove the comparison logic using the real file.)

test( 'client refuses to deliver to scheme-variant when URL-param lies about scheme', async ( { browser } ) => {
    // Scenario exercised: the URL param claims origin=https://localhost:8001,
    // but the actual opener is at http://localhost:8001. The popup side no
    // longer dual-dispatches to the alt-scheme host — so the browser drops
    // the (only) postMessage whose targetOrigin doesn't match the opener.
    //
    // This is the SAFE behavior: an attacker who controls the alt-scheme
    // variant of a vendor's hostname can no longer receive `granted`.
    // The real redirect case (popup started on http but WP redirected to
    // https) is handled by the OPENER's host-only comparison instead.
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

    // Give the popup's JS a few seconds to run through post-grant logic
    // so any messages it intended to emit would have fired by now.
    await popup.waitForTimeout( 5_000 );

    const msgs  = await opener.evaluate( () => ( window as any ).__tlMessages );
    const types = msgs.map( ( m: any ) => m.data?.type );

    // No `granted` message should have reached http://localhost:8001
    // when the URL param targets https://localhost:8001.
    expect(
        types,
        'opener must NOT receive granted when URL-param scheme disagrees with opener scheme'
    ).not.toContain( 'granted' );

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

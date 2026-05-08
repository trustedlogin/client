/**
 * E2E: the real TLS wire.
 *
 * The rest of the suite drives client-wp via http://localhost:8002. This
 * spec drives it via https://localhost:8443 — the caddy sidecar terminating
 * TLS with a self-signed local-CA cert and proxying to client-wp:80 with
 * X-Forwarded-Proto: https. The browser speaks real TLS, WP's is_ssl()
 * returns true, admin cookies carry the Secure flag, and site_url()
 * builds https://localhost:8443 URLs.
 *
 * What this spec proves (that can't be proved on the HTTP-only fixture):
 *   1. The trustedlogin client JS loads from an HTTPS origin and runs.
 *   2. admin-ajax.php is reachable from an HTTPS page without mixed-
 *      content blocking (proves WP_HOME is correctly TLS-aware).
 *   3. The popup's postMessage to the vendor opener (http://localhost:8001)
 *      crosses a real cross-origin boundary with a different scheme AND
 *      a different port, not a synthetic one made up inside page.evaluate.
 *   4. fake-saas verify-identifier answers the TLS popup the same way it
 *      answers the HTTP popup.
 *
 * What this spec does NOT (and cannot) prove:
 *   - The canonical HSTS redirect chain (http://site → https://site on
 *     default ports). Our sidecar uses non-default ports so the host
 *     comparison isn't "site vs site" — it's "localhost:8002" vs
 *     "localhost:8443". That specific case is covered by the real-file
 *     jest unit tests in trustedlogin-connector/public/forms/tl-field.test.js.
 */
import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { resetClientState } from './_helpers';

const VENDOR_STATE = JSON.parse(
    fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

const CLIENT_TLS_URL = VENDOR_STATE.client_url_tls as string;
if ( ! CLIENT_TLS_URL || typeof CLIENT_TLS_URL !== 'string' ) {
    throw new Error( 'tls-wire.spec.ts: fixtures/.cache-vendor-state.json missing `client_url_tls`. Re-run bootstrap-vendor.sh after the caddy sidecar is up.' );
}

async function loginWpAdmin( ctx: BrowserContext, base: string ): Promise<void> {
    const p = await ctx.newPage();
    await p.goto( `${ base }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    if ( ! p.url().includes( 'wp-login.php' ) ) { await p.close(); return; }
    await p.locator( '#user_login' ).fill( 'admin' );
    await p.locator( '#user_pass' ).fill( 'admin' );
    await Promise.all( [
        p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        p.locator( '#wp-submit' ).click(),
    ] );
    await p.close();
}

test.describe.configure( { mode: 'serial' } );

test.beforeEach( () => {
    resetClientState();
} );

test( 'grant flow works end-to-end when the client site is served over real TLS', async ( { browser } ) => {
    const ctx = await browser.newContext();
    // Two admin logins: one on the TLS client (so the popup's support-user
    // creation runs authenticated), one on the HTTP vendor (so the grant
    // field renders + processes postMessages).
    await loginWpAdmin( ctx, CLIENT_TLS_URL );
    await loginWpAdmin( ctx, VENDOR_STATE.vendor_url );

    const vp: Page = await ctx.newPage();
    await vp.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await vp.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    // Store the TLS URL in the vendor field, then trigger the popup.
    await vp.locator( '.tl-grant-access .tl-site-url' ).fill( CLIENT_TLS_URL );
    await vp.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = ctx.waitForEvent( 'page' );
    await vp.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );

    // Proof-of-life: the popup ended up on the TLS origin.
    expect(
        popup.url(),
        'popup should land on the caddy TLS sidecar'
    ).toMatch( /^https:\/\/localhost:8443\// );

    // Drive the grant CTA inside the TLS popup.
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    // The vendor field should receive the `granted` postMessage across the
    // http://localhost:8001 ↔ https://localhost:8443 boundary and populate
    // the access-key field.
    await vp.waitForFunction( () => {
        const el = document.querySelector( '.tl-site-key' );
        return el && el.textContent && el.textContent.length > 10;
    }, null, { timeout: 30_000 } );

    const keyText = ( await vp.locator( '.tl-site-key' ).textContent() ) || '';
    expect(
        keyText.trim(),
        'access key should be a 64-char hex — proves the TLS popup posted to the HTTP opener'
    ).toMatch( /^[a-f0-9]{64}$/ );

    await ctx.close();
} );

test( 'WP detects TLS via X-Forwarded-Proto — secure cookies + https URLs', async ( { browser } ) => {
    // Guards against regressions in the caddy→WP forwarded-proto wiring:
    //   - is_ssl() must return true when X-Forwarded-Proto: https
    //   - admin cookies must carry the Secure flag (browser won't send
    //     them back over HTTP if set from HTTPS)
    //   - site_url()/admin_url() must produce https://localhost:8443 URLs
    const ctx = await browser.newContext();

    // Navigate to wp-login and submit — if cookies aren't set with Secure,
    // Playwright's cookie API would reveal that.
    const p = await ctx.newPage();
    await p.goto( `${ CLIENT_TLS_URL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    await p.locator( '#user_login' ).fill( 'admin' );
    await p.locator( '#user_pass' ).fill( 'admin' );
    await Promise.all( [
        p.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        p.locator( '#wp-submit' ).click(),
    ] );

    // #wpadminbar renders only for authenticated sessions.
    await expect( p.locator( '#wpadminbar' ) ).toBeVisible();

    // Every `wordpress_*` cookie set for the TLS origin must have the
    // Secure flag on — proves WP saw is_ssl()=true when setting them.
    const cookies = await ctx.cookies( CLIENT_TLS_URL );
    const wpCookies = cookies.filter( c => c.name.startsWith( 'wordpress_' ) );
    expect( wpCookies.length, 'at least one wordpress_* cookie should be present' ).toBeGreaterThan( 0 );
    for ( const c of wpCookies ) {
        expect( c.secure, `cookie ${ c.name } should be Secure` ).toBe( true );
    }

    // Canonical link in <head> should be the TLS URL, proving home_url()
    // built URLs with the forwarded scheme+host, not the WP_HOME default.
    const canonical = await p.evaluate( () => {
        const l = document.querySelector( 'link[rel="canonical"]' ) as HTMLLinkElement | null;
        return l ? l.href : ( document.querySelector( 'base' ) as HTMLBaseElement | null )?.href ?? '';
    } );
    // wp-admin pages don't always emit canonical — probe the admin bar's
    // site-name link instead (always present, uses home_url()).
    const siteLink = await p.locator( '#wp-admin-bar-site-name > a' ).first().getAttribute( 'href' );
    expect(
        siteLink || canonical,
        'admin-bar site link should point at the TLS origin'
    ).toMatch( /^https:\/\/localhost:8443\// );

    await ctx.close();
} );

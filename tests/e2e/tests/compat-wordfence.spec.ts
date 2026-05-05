/**
 * E2E compatibility: Wordfence (https://wordpress.org/plugins/wordfence/).
 *
 * Reproduces + pins the fix for a real bug report:
 *
 *   > Wordfence blocked the TL webhook POST as XSS — the form-encoded
 *   > `debug_data=…` body contained `###` WordPress-debug-section
 *   > headings and %0A newlines that matched Wordfence's XSS signature.
 *   > Re-sending the body as JSON (with Content-Type: application/json)
 *   > bypasses the false positive.
 *
 * Fix: `src/Remote.php::maybe_send_webhook()` now JSON-encodes the body
 * by default and sets `Content-Type: application/json`. Integrators whose
 * legacy webhook receiver needs form encoding can revert the shape via
 * the `trustedlogin/{ns}/webhook/request_args` filter.
 *
 * Test strategy:
 *   The default Wordfence install ships with an empty `wflogs/rules.php`
 *   (Premium pulls rules from their cloud — unavailable in this test env).
 *   To exercise the WAF with deterministic behavior, we write a single
 *   synthetic rule that matches the exact pre-fix body shape the customer's
 *   Wordfence flagged, and flip `wafStatus=enabled` so Wordfence's WAF
 *   actually enforces instead of only logging.
 *
 *   That gives us a REAL Wordfence block (not a simulation): the
 *   wfWAF engine parses the request, evaluates the rule, and returns 403
 *   when the body matches.
 *
 *   With the synthetic rule in place, the tests:
 *     1. Sanity: POSTing the XSS-like body → 403 (WAF is enforcing).
 *     2. Reproduce: the Client SDK's PRE-fix form-encoded body carries
 *        the signature; the same rule would block the real webhook.
 *     3. Fix: the Client SDK's POST-fix JSON body does NOT carry the
 *        signature and passes Wordfence.
 *     4. Directly assert the Client SDK is shipping the JSON body now
 *        (format-level regression guard).
 */

import { execSync, execFileSync } from 'child_process';
import { test, expect, request as playwrightRequest } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli, E2E_DIR } from './_helpers';

// Shared secret for the test harness mu-plugin (tl-wf-compat-harness.php).
// Matches the default there; override via TL_WF_HARNESS_SECRET if needed.
const WF_HARNESS_SECRET = 'e2e-only';

async function wfHarness( action: 'enable' | 'disable' ): Promise<string> {
    const ctx = await playwrightRequest.newContext();
    try {
        const resp = await ctx.get(
            `http://localhost:8002/tl-wf-harness/${ action }?k=${ WF_HARNESS_SECRET }`,
        );
        const body = await resp.text();
        if ( resp.status() !== 200 || ! body.trim().endsWith( 'OK' ) ) {
            throw new Error( `wf-harness/${ action } failed (HTTP ${ resp.status() }): ${ body }` );
        }
        return body;
    } finally {
        await ctx.dispose();
    }
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

function wpCommand( container: 'wp-cli-client' | 'wp-cli-vendor', args: string ): string {
    return execSync(
        `docker compose run --rm -T ${ container } wp ${ args }`,
        { cwd: E2E_DIR, encoding: 'utf8', timeout: 60_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
    ).toString().trim();
}

function dockerExec( service: string, cmd: string ): string {
    return execSync(
        `docker compose exec -T ${ service } bash -c ${ JSON.stringify( cmd ) }`,
        { cwd: E2E_DIR, encoding: 'utf8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
    ).toString();
}

/**
 * Copy literal file content into a container at the given path. Uses a
 * host tempfile + `docker compose cp` so we don't have to escape anything
 * through bash + heredoc, which is where my earlier attempt died.
 */
function writeInContainer( service: string, containerPath: string, content: string ): void {
    const tmp = path.join( E2E_DIR, `.tmp-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }` );
    fs.writeFileSync( tmp, content );
    try {
        // execFileSync (not execSync) — argv array bypasses /bin/sh entirely so
        // tmp / containerPath can't be interpreted as shell metacharacters.
        execFileSync(
            'docker',
            [ 'compose', 'cp', tmp, `${ service }:${ containerPath }` ],
            { cwd: E2E_DIR, timeout: 30_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
        );
    } finally {
        fs.unlinkSync( tmp );
    }
}

// ---------------------------------------------------------------------------
// Synthetic rule file content — matches the exact XSS signature Wordfence
// flagged in the customer's real report. Path-safe PHP heredoc.
// ---------------------------------------------------------------------------
const SYNTHETIC_RULES_PHP = `<?php
if (!defined('WFWAF_VERSION') || defined('WFWAF_RULES_LOADED')) {
    exit('Access denied');
}
/*
    Test-only synthetic rule used by compat-wordfence.spec.ts. Matches the
    form-encoded body shape flagged by Wordfence in the real bug report:
    debug_data= followed by %23%23%23 (### in URL encoding) within the
    first ~40 characters.

    This rule is installed by the spec's beforeAll and removed by afterAll.
    Without it, Wordfence's shipped rules.php is empty in this test stack.
*/

if ( class_exists( 'wfWAFRule' ) && class_exists( 'wfWAFRuleComparisonGroup' ) && class_exists( 'wfWAFRuleComparison' ) && class_exists( 'wfWAFRuleComparisonSubject' ) ) {
    // Wordfence's WAF uses a score threshold — a rule that matches adds
    // its score to the category total, and the request is blocked only
    // when the category total >= failScore (default 100 for 'xss').
    // Setting score=100 means one match → block.
    $this->rules[99001] = wfWAFRule::create(
        $this,
        99001,
        null,
        'xss',
        100, // score — must meet/exceed the category's failScore (100 for xss)
        'Test: debug_data XSS-like signature',
        1,
        'block',
        new wfWAFRuleComparisonGroup(
            new wfWAFRuleComparison(
                $this,
                'match',
                '/debug_data=.{0,40}%23%23%23/i',
                array(
                    wfWAFRuleComparisonSubject::create( $this, 'request.rawBody', array() )
                )
            )
        )
    );
}
`;

// ---------------------------------------------------------------------------
// Capture-mu-plugin readers (for the format-level assertion below).
// ---------------------------------------------------------------------------

type CapturedWebhook = {
    url: string;
    method: string;
    headers: Record<string, string>;
    body: string | Record<string, unknown>;
    body_is_string: boolean;
    body_on_wire: string;
};

function readCapturedWebhook(): CapturedWebhook | null {
    const raw = wpCli(
        'wp-cli-client',
        `$v = get_option( "tl_captured_webhook_args" ); if ( ! $v ) { echo "NONE"; } else { echo wp_json_encode( $v ); }`,
        'read captured webhook',
    );
    if ( raw === 'NONE' || raw === '' ) { return null; }
    return JSON.parse( raw );
}

function clearCapturedWebhook(): void {
    wpCli(
        'wp-cli-client',
        `delete_option( "tl_captured_webhook_args" ); echo "ok";`,
        'clear capture',
    );
}

/**
 * Trigger Remote::maybe_send_webhook directly via the namespaced action
 * the plugin listens on. Avoids the browser grant flow (and its
 * Wordfence-admin-UI timing hell) while still exercising the full
 * TL → wp_remote_post → http hook path.
 */
function fireWebhook(): void {
    // The debug_data literal mirrors what Client::get_debug_data()
    // produces: a wp-core debug dump with `### Section` headings and
    // newlines. That's the string that tripped Wordfence when form-encoded.
    wpCli(
        'wp-cli-client',
        `$payload = array(`
        + `  "url"        => home_url(),`
        + `  "action"     => "created",`
        + `  "ref"        => null,`
        + `  "debug_data" => "  \\n### WordPress\\n\\nVersion: 6.9\\n\\n### Directories\\n",`
        + `);`
        + `do_action( "trustedlogin/pro-block-builder/access/created", $payload );`
        + `echo "fired";`,
        'fire webhook action',
    );
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

test.describe.configure( { mode: 'serial' } );

test.beforeAll( async () => {
    // --network: client-wp is multisite, and the suite's global-setup
    // pre-deactivates wordfence with --network as a defensive measure
    // (recovers state from a SIGKILLed previous run). Activating
    // per-site here would leave it in `active` rather than the
    // `active-network` state Wordfence expects on multisite — its
    // WAF middleware paths key off the network-active install marker.
    wpCommand( 'wp-cli-client', 'plugin activate wordfence' );

    // Install the WAF auto-prepend (.user.ini + wordfence-waf.php at the
    // webroot). Without this, wfWAF::getInstance() never loads on HTTP
    // requests and the harness reports "wfWAF not loaded". docker-compose
    // mounts a php.ini override (user_ini.cache_ttl=0) so the .user.ini
    // takes effect on the next request.
    wpCli(
        'wp-cli-client',
        `require_once ABSPATH . "wp-admin/includes/file.php"; WP_Filesystem(); global $wp_filesystem; $h = new wfWAFAutoPrependHelper( "apache-mod_php", null ); try { $h->performInstallation( $wp_filesystem ); echo "ok"; } catch ( Throwable $e ) { echo "FAIL:" . $e->getMessage(); }`,
        'install Wordfence WAF auto-prepend',
    );

    // Install the synthetic rule. Its first evaluation must happen
    // AFTER we flip wafStatus to 'enabled' below — otherwise Wordfence's
    // learning mode will auto-allowlist the first hit and later runs
    // silently won't block.
    writeInContainer(
        'client-wp',
        '/var/www/html/wp-content/wflogs/rules.php',
        SYNTHETIC_RULES_PHP,
    );

    // Flip wafStatus=enabled via the Apache-served harness mu-plugin.
    // This MUST happen over HTTP — not via wp-cli — because wfWAF's
    // StorageFile::allowFileWriting() returns false under CLI and
    // silently drops every setConfig call.
    //
    // The harness also clears any residual learning-mode allowlist, so a
    // previous run's auto-allowlist on rule 99001 won't make us pass when
    // we should fail.
    await wfHarness( 'enable' );
} );

test.afterAll( async () => {
    // Return WAF to disabled so subsequent specs run against a vanilla WP.
    // --network must match the beforeAll's --network activation —
    // otherwise the deactivate is a no-op on the network-active install
    // and Wordfence keeps adding ~7s WAF overhead to every request.
    try { await wfHarness( 'disable' ); } catch ( _ ) { /* best-effort */ }
    dockerExec( 'client-wp', `: > /var/www/html/wp-content/wflogs/rules.php` );
    // Uninstall WAF auto-prepend so non-wordfence specs don't pay the
    // .user.ini hit on every request.
    wpCli(
        'wp-cli-client',
        `require_once ABSPATH . "wp-admin/includes/file.php"; WP_Filesystem(); global $wp_filesystem; $h = new wfWAFAutoPrependHelper( "apache-mod_php", null ); try { $h->uninstall(); echo "ok"; } catch ( Throwable $e ) { echo "FAIL:" . $e->getMessage(); }`,
        'uninstall Wordfence WAF auto-prepend',
    );
    wpCommand( 'wp-cli-client', 'plugin deactivate wordfence --network' );
} );

test.beforeEach( () => {
    clearCapturedWebhook();
    // Strip any stray filters from prior tests.
    wpCli(
        'wp-cli-client',
        `remove_all_filters( "trustedlogin/pro-block-builder/webhook/request_args" ); echo "ok";`,
        'clear request_args filters',
    );
} );

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test( 'wordfence WAF is enforcing and blocks the XSS-like signature (sanity)', async ( { request } ) => {
    // Direct POST carrying the signature → Wordfence must 403.
    // If this fails, beforeAll didn't install the rule or wafStatus
    // didn't flip, and the rest of the spec would be meaningless.
    const resp = await request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        headers:      { 'Content-Type': 'application/x-www-form-urlencoded' },
        data:         'debug_data=%20%20%0A%23%23%23%20WordPress',
    } );
    expect( resp.status(),
        `expected Wordfence to return 403; got ${ resp.status() }. ` +
        `Check /var/www/html/wp-content/wflogs/rules.php and wafStatus.`,
    ).toBe( 403 );
} );

test( 'benign POST is NOT blocked (sanity)', async ( { request } ) => {
    const resp = await request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        headers:      { 'Content-Type': 'application/x-www-form-urlencoded' },
        data:         'harmless=value',
    } );
    expect( resp.status() ).not.toBe( 403 );
} );

test( 'reproducer: pre-fix form-encoded webhook body would be blocked', async ( { request } ) => {
    // Wordfence evaluates bodies regardless of target endpoint. Use a POST
    // that mimics what the Client SDK USED TO send when webhook had
    // `[ 'body' => $data ]` — WP form-encodes arrays into
    //   debug_data=%20%20%0A%23%23%23%20WordPress%0A…
    // This is the exact string that reached the customer's Wordfence
    // and got blocked.
    const preFixBody =
        'url=https%3A%2F%2Fexample.com&action=created&ref=&' +
        'debug_data=%20%20%0A%23%23%23%20WordPress%0AVersion%3A%206.9';

    const resp = await request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        headers:      { 'Content-Type': 'application/x-www-form-urlencoded' },
        data:         preFixBody,
    } );
    expect( resp.status(),
        'pre-fix body must reproduce the block that motivated the fix',
    ).toBe( 403 );
} );

test( 'fix: JSON-encoded webhook body passes Wordfence', async ( { request } ) => {
    // Same payload, but JSON body + Content-Type: application/json —
    // the shape the Client SDK now emits by default. The signature
    // regex (`debug_data=%23%23%23`) has no form-encoded `=` delimiter
    // and no URL-encoded `###` in the JSON shape, so Wordfence's rule
    // does not match.
    const postFixBody = JSON.stringify( {
        url: 'https://example.com',
        action: 'created',
        ref: null,
        debug_data: '  \n### WordPress\nVersion: 6.9',
    } );

    const resp = await request.post( VENDOR_STATE.client_url + '/', {
        maxRedirects: 0,
        headers:      { 'Content-Type': 'application/json' },
        data:         postFixBody,
    } );
    expect( resp.status(),
        'fix should let the JSON-encoded body through; Wordfence should not 403',
    ).not.toBe( 403 );
} );

test( 'regression guard: Client SDK sends JSON body with application/json header', async () => {
    // Format-level assertion. Independent of whether Wordfence blocks —
    // this locks the SDK to the fixed shape so a future refactor can't
    // silently regress it.
    fireWebhook();

    const captured = readCapturedWebhook();
    expect( captured, 'webhook never fired — mu-plugin missing?' ).not.toBeNull();
    const cap = captured!;

    expect( cap.body_is_string,
        'body is still an array — Remote::maybe_send_webhook reverted to form encoding',
    ).toBe( true );

    const parsed = JSON.parse( cap.body as string );
    expect( parsed ).toHaveProperty( 'action', 'created' );

    const headers = Object.fromEntries(
        Object.entries( cap.headers ).map( ( [ k, v ] ) => [ k.toLowerCase(), String( v ) ] )
    );
    expect( headers[ 'content-type' ] ).toMatch( /application\/json/i );
} );

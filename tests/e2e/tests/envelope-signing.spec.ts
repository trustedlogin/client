/**
 * E2E: SaaS → Connector envelope signing.
 *
 * Covers the cross-repo fix that adds a detached Ed25519 signature to
 * get-envelope responses so the Connector can detect MITM tampering of the
 * plaintext `siteUrl` / `publicKey` envelope fields.
 *
 * Topology (standard e2e stack):
 *   - Vendor at http://localhost:8001 — connector + our verifier
 *   - Client at http://localhost:8002 — grant flow
 *   - fake-saas at http://localhost:8003 — signs by default (mirrors real SaaS)
 *
 * Scenarios:
 *   1. Happy path — signing on, valid envelope → flow succeeds.
 *   2. Tampered envelope — mu-plugin swaps siteUrl before verify → flow fails.
 *   3. Hard mode + unsigned — fake-saas signing off, enforce filter on → fail.
 *   4. Back-compat — no pubkey configured, signing off → flow succeeds.
 */

import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import { execSync } from 'child_process';
import { wpCli } from './_helpers';

type VendorState = {
    form_id: string;
    form_page_url: string;
    client_url: string;
    vendor_url: string;
    namespace: string;
    account_id: string;
    api_public_key: string;
    saas_envelope_pubkey?: string;
};

const VENDOR_STATE: VendorState = JSON.parse(
    fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

const FAKE_SAAS_RESET_URL    = 'http://localhost:8003/__reset';
const FAKE_SAAS_PUBKEY_URL   = 'http://localhost:8003/signing-pubkey';
const FAKE_SAAS_TOGGLE_URL   = 'http://localhost:8003/__toggle-signing';
const MU_PLUGIN_DIR          = '/var/www/html/wp-content/mu-plugins';

// ---------- Helpers ----------

async function loginAsClientAdmin( context: BrowserContext ) {
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
    await Promise.all( [
        loginPage.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        loginPage.locator( '#wp-submit' ).click(),
    ] );
    await loginPage.close();
}

async function loginAsVendorAdmin( context: BrowserContext ) {
    const check = await context.request.get( `${ VENDOR_STATE.vendor_url }/wp-admin/`, { maxRedirects: 0 } ).catch( () => null );
    if ( check && check.status() === 200 ) {
        return;
    }
    const loginPage = await context.newPage();
    await loginPage.goto( `${ VENDOR_STATE.vendor_url }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    if ( ! loginPage.url().includes( 'wp-login.php' ) ) {
        await loginPage.close();
        return;
    }
    await loginPage.locator( '#user_login' ).fill( 'admin' );
    await loginPage.locator( '#user_pass' ).fill( 'admin' );
    await Promise.all( [
        loginPage.waitForURL( /\/wp-admin\//, { timeout: 15_000 } ),
        loginPage.locator( '#wp-submit' ).click(),
    ] );
    await loginPage.close();
}

/**
 * Drive the vendor flow that actually exercises verify_envelope.
 *
 * 1. Full client-side grant to register an envelope in fake-saas.
 * 2. Navigate the vendor admin to `?ak=<key>&ak_account_id=<id>` which
 *    triggers AccessKeyLogin::handle() → TrustedLoginService::get_valid_secrets()
 *    → verify_envelope(). That's the chokepoint where signature verification
 *    actually runs.
 */
async function runFullVendorGrantFlow( page: Page, context: BrowserContext ): Promise<string> {
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    const msgs = await waitForMessageType( page, 'granted', 30_000 );
    const granted = msgs.find( ( m: any ) => m?.data?.type === 'granted' )!;
    const accessKey: string = granted.data.key;

    // Drive the same path the React Access Key Login form (and the
    // Help Scout widget's embedded form) drive: POST to the
    // REST endpoint at /wp-json/trustedlogin/v1/access_key with the
    // ak + ak_account_id + a freshly-minted nonce. That endpoint
    // calls AccessKeyLogin::handle() which calls
    // TrustedLoginService::get_valid_secrets() → verify_envelope —
    // the chokepoint where envelope signature verification actually
    // runs.
    //
    // Don't use the bare `/wp-admin/?trustedlogin=1&ak=...` URL —
    // that would only fire if MaybeRedirect::handle were registered
    // as a template_redirect action, which it isn't on this branch.
    await loginAsVendorAdmin( context );
    // Mint nonces inside the SAME session as the REST POST. Two
    // nonces are needed:
    //   - ak_nonce: the connector's custom verifier (action='ak-redirect')
    //   - rest_nonce: WP REST cookie check (X-WP-Nonce header,
    //     action='wp_rest'). Without this, WP rejects with 403
    //     `rest_cookie_invalid_nonce` before the connector handler
    //     even runs.
    // Both come from the admin-ajax helper in vendor-e2e-ak-nonce.php
    // executed under the cookie session, so session tokens match.
    const nonceRes = await context.request.post(
        `${ VENDOR_STATE.vendor_url }/wp-admin/admin-ajax.php?action=tl_e2e_get_ak_nonce`,
    );
    const nonceJson = await nonceRes.json();
    if ( ! nonceJson?.success ) {
        throw new Error( '[runFullVendorGrantFlow] could not mint nonces: ' + JSON.stringify( nonceJson ) );
    }
    const akNonce   = nonceJson.data.ak_nonce   as string;
    const restNonce = nonceJson.data.rest_nonce as string;
    const restRes = await context.request.post(
        `${ VENDOR_STATE.vendor_url }/wp-json/trustedlogin/v1/access_key`,
        {
            data: {
                ak:             accessKey,
                ak_account_id:  VENDOR_STATE.account_id,
                _tl_ak_nonce:   akNonce,
            },
            headers: { 'X-WP-Nonce': restNonce },
        }
    );
    // Print so the test runner shows the actual response. The
    // tampered scenario expects a non-2xx or a wrapped error; the
    // happy scenario expects 2xx with `success`.
    const restBody = await restRes.text();
    // eslint-disable-next-line no-console
    console.log( `[runFullVendorGrantFlow] access_key REST: ${ restRes.status() } ${ restBody.substring( 0, 400 ) }` );

    return accessKey;
}

async function resetFakeSaaS( page: Page ) {
    await page.request.post( FAKE_SAAS_RESET_URL );
}

async function revokeIfGranted() {
    // Trigger the Client SDK's own revoke flow so each test starts
    // with no support user. This goes through `Client::revoke_access`
    // → `SupportUser::delete` → `wp_delete_user` + (multisite-safe)
    // `wpmu_delete_user`, mirroring what a customer admin would do
    // by clicking "revoke access" in production.
    //
    // The PHP runs via `wp eval-file` so the file's namespace
    // separators survive verbatim — passing PHP with backslashes
    // through `wp eval` shell-quoting mangles them on the way in.
    const phpFile = path.join( os.tmpdir(), `tl-e2e-revoke-${ Date.now() }.php` );
    fs.writeFileSync(
        phpFile,
        `<?php
// Use the Client SDK handle exposed by tl-e2e-sdk-handle.php (an
// e2e-only mu-plugin). Falling back to "no client" is a hard error
// here — the helper is part of the e2e fixture, not optional.
if ( ! isset( $GLOBALS['__tl_e2e']['client'] ) ) {
    fwrite( STDERR, "tl_e2e_client missing — is tl-e2e-sdk-handle.php loaded?\\n" );
    exit( 1 );
}
try {
    $result = $GLOBALS['__tl_e2e']['client']->revoke_access( 'all' );
    echo 'sdk_revoke=' . var_export( $result, true );
} catch ( \\Throwable $e ) {
    echo 'sdk_revoke_threw=' . $e->getMessage();
}
`
    );
    const e2eDir = path.resolve( __dirname, '..' );
    try {
        execSync(
            `docker compose run --rm -T -v ${ phpFile }:/tmp/revoke.php wp-cli-client wp eval-file /tmp/revoke.php`,
            { cwd: e2eDir, stdio: 'pipe' },
        );
    } catch ( e: any ) {
        throw new Error( `[revokeIfGranted] SDK revoke command failed: ${ e?.message || e }` );
    } finally {
        try { fs.unlinkSync( phpFile ); } catch ( _e ) { /* ignore */ }
    }

    // Verify the SDK revoke actually removed all TrustedLogin users.
    // If users remain, fail loudly — that's an SDK bug we want to
    // catch, not paper over. Matches the user's intent: "if
    // revokeIfGranted fails to remove all TL users, that should be a
    // failed test."
    const remainingRaw = execSync(
        `docker exec e2e-mariadb-1 mysql -uwp -pwp client_wp -N -e "
            SELECT u.ID, u.user_email
            FROM wp_users u
            LEFT JOIN wp_usermeta um ON u.ID = um.user_id
                                     AND um.meta_key LIKE 'tl_%_id'
            WHERE (u.user_email = 'support@example.com' OR um.meta_key IS NOT NULL)
              AND u.user_login != 'admin'
        "`,
        { stdio: [ 'ignore', 'pipe', 'pipe' ] }
    ).toString().trim();

    if ( remainingRaw !== '' ) {
        throw new Error(
            '[revokeIfGranted] SDK revoke left TrustedLogin users behind:\n'
            + remainingRaw
            + '\nThis indicates an SDK regression in revoke_access(\'all\') — '
            + 'investigate Client::revoke_access / SupportUser::delete before '
            + 'falling back to manual cleanup.'
        );
    }
}

/**
 * Write a file into the vendor-wp container via `docker cp` from a tempfile.
 * This is more robust than heredocs-through-docker-exec because the PHP
 * payload contains both backticks and single/double quotes that shell
 * quoting mangles.
 */
function dockerWriteFile( container: string, targetPath: string, contents: string ) {
    const tmp = path.join( os.tmpdir(), `tl-e2e-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 8 ) }` );
    fs.writeFileSync( tmp, contents );
    try {
        execSync( `docker cp ${ tmp } ${ container }:${ targetPath }`, { stdio: 'pipe' } );
    } finally {
        try { fs.unlinkSync( tmp ); } catch ( _e ) { /* ignore */ }
    }
}

function dockerRemoveFile( container: string, targetPath: string ) {
    try {
        execSync( `docker exec -u root ${ container } rm -f ${ targetPath }`, { stdio: 'pipe' } );
    } catch ( _e ) { /* ignore */ }
}

const VENDOR_CONTAINER = 'e2e-vendor-wp-1';

/**
 * Install a Vendor-side mu-plugin that intercepts the envelope returned by
 * the SaaS API handler and mutates the `siteUrl` field. This simulates a
 * MITM attacker who can rewrite the SaaS→Connector response in-flight.
 */
function installTamperMuPlugin() {
    const php = `<?php
/**
 * Plugin Name: TrustedLogin e2e - Envelope tamper (signing test).
 * Loaded only inside the e2e docker stack.
 */
add_filter( 'http_response', function ( $response, $args, $url ) {
    if ( strpos( $url, '/get-envelope' ) === false ) {
        return $response;
    }
    $body = wp_remote_retrieve_body( $response );
    $decoded = json_decode( $body, true );
    if ( is_array( $decoded ) && isset( $decoded['siteUrl'] ) ) {
        $decoded['siteUrl'] = 'https://evil.example.com';
        // Keep signature intact - that is the whole point of this test: a
        // MITM who flips siteUrl must be detected by the signature check.
        $response['body'] = wp_json_encode( $decoded );
    }
    return $response;
}, 10, 3 );
`;
    dockerWriteFile( VENDOR_CONTAINER, `${ MU_PLUGIN_DIR }/e2e-envelope-tamper.php`, php );
}

function removeTamperMuPlugin() {
    dockerRemoveFile( VENDOR_CONTAINER, `${ MU_PLUGIN_DIR }/e2e-envelope-tamper.php` );
}

/**
 * Install a Vendor-side mu-plugin that flips `require_envelope_signature`
 * to hard mode (true). When combined with fake-saas signing OFF, this
 * exercises the reject-unsigned codepath.
 */
function installEnforceMuPlugin() {
    const php = `<?php
/**
 * Plugin Name: TrustedLogin e2e - Enforce envelope signing (hard mode).
 */
add_filter( 'trustedlogin/vendor/require_envelope_signature', '__return_true' );
`;
    dockerWriteFile( VENDOR_CONTAINER, `${ MU_PLUGIN_DIR }/e2e-envelope-enforce.php`, php );
}

function removeEnforceMuPlugin() {
    dockerRemoveFile( VENDOR_CONTAINER, `${ MU_PLUGIN_DIR }/e2e-envelope-enforce.php` );
}

/**
 * Truncate the vendor's debug.log so a test only inspects log lines it
 * produced. Runs as root (the log is owned by www-data).
 */
function truncateVendorDebugLog(): void {
    try {
        execSync(
            `docker exec -u root ${ VENDOR_CONTAINER } sh -c ": > /var/www/html/wp-content/debug.log"`,
            { stdio: 'pipe' },
        );
    } catch ( _e ) { /* ignore */ }
}

/**
 * Read the TrustedLogin-specific log (not WP debug.log). The Logger trait
 * writes every log line to /wp-content/uploads/trustedlogin-logs/vendor-*.log
 * when the team's `error_logging` setting is enabled.
 */
function readTrustedLoginLogTail( lines = 300 ): string {
    try {
        return execSync(
            `docker exec -u root ${ VENDOR_CONTAINER } sh -c "tail -n ${ lines } /var/www/html/wp-content/uploads/trustedlogin-logs/vendor-*.log 2>/dev/null || echo '(no log)'"`,
            { stdio: 'pipe', encoding: 'utf8', timeout: 10_000 },
        );
    } catch ( e: any ) {
        return `(trustedlogin log read failed: ${ e.message })`;
    }
}

function truncateTrustedLoginLog(): void {
    try {
        execSync(
            `docker exec -u root ${ VENDOR_CONTAINER } sh -c "for f in /var/www/html/wp-content/uploads/trustedlogin-logs/vendor-*.log; do [ -f \\"$f\\" ] && : > \\"$f\\"; done"`,
            { stdio: 'pipe' },
        );
    } catch ( _e ) { /* ignore */ }
}

async function toggleFakeSaaSSigning( page: Page, enabled: boolean ) {
    await page.request.post( FAKE_SAAS_TOGGLE_URL, { data: { enabled } } );
}

async function restoreVendorPubkey() {
    if ( VENDOR_STATE.saas_envelope_pubkey ) {
        // Use double-quoted PHP strings so the shell's outer single-quoted
        // eval string stays intact. JSON.stringify gives us a valid PHP
        // double-quoted literal for the pubkey.
        wpCli(
            'wp-cli-vendor',
            `update_option( "trustedlogin_vendor_saas_envelope_public_key", ${ JSON.stringify( VENDOR_STATE.saas_envelope_pubkey ) } ); echo "set";`,
            'restoreVendorPubkey',
        );
    }
}

async function clearVendorPubkey() {
    wpCli(
        'wp-cli-vendor',
        `delete_option( "trustedlogin_vendor_saas_envelope_public_key" ); echo "cleared";`,
        'clearVendorPubkey',
    );
}

async function instrumentOpener( page: Page ) {
    await page.addInitScript( () => {
        ( window as any ).__tlMessages = [];
        window.addEventListener( 'message', ( event: MessageEvent ) => {
            ( window as any ).__tlMessages.push( { origin: event.origin, data: event.data } );
        } );
    } );
}

async function readMessages( page: Page ) {
    return await page.evaluate( () => ( window as any ).__tlMessages || [] );
}

async function waitForMessageType( page: Page, expectedType: string, timeoutMs = 15_000 ) {
    const start = Date.now();
    while ( Date.now() - start < timeoutMs ) {
        const msgs = await readMessages( page );
        if ( msgs.some( ( m: any ) => m?.data?.type === expectedType ) ) {
            return msgs;
        }
        await page.waitForTimeout( 100 );
    }
    const seen = ( await readMessages( page ) ).map( ( m: any ) => m?.data?.type );
    throw new Error( `Timed out waiting for postMessage type="${ expectedType }". Saw: ${ JSON.stringify( seen ) }` );
}

// ---------- Tests ----------

test.describe.configure( { mode: 'serial' } );

test.beforeEach( async ( { page, context } ) => {
    await resetFakeSaaS( page );
    await toggleFakeSaaSSigning( page, true );
    await restoreVendorPubkey();
    removeTamperMuPlugin();
    removeEnforceMuPlugin();
    truncateVendorDebugLog();
    truncateTrustedLoginLog();
    await loginAsClientAdmin( context );
    await revokeIfGranted();
} );

test.afterEach( async () => {
    removeTamperMuPlugin();
    removeEnforceMuPlugin();
} );

test( 'signing: SaaS pubkey endpoint returns a hex key', async ( { page } ) => {
    const res = await page.request.get( FAKE_SAAS_PUBKEY_URL );
    expect( res.status() ).toBe( 200 );
    const body = await res.json();
    expect( typeof body.publicKey ).toBe( 'string' );
    expect( body.publicKey ).toMatch( /^[0-9a-f]{64}$/ );
} );

test( 'signing: happy path — full grant flow succeeds with signing enabled', async ( { page, context } ) => {
    // Sanity: vendor pubkey option is populated.
    const stored = wpCli(
        'wp-cli-vendor',
        `echo get_option( "trustedlogin_vendor_saas_envelope_public_key" );`,
        'stored-pubkey',
    );
    expect( stored ).toMatch( /^[0-9a-f]{64}$/ );

    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );
    await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
    await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );
    await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

    // If signing is wired correctly end-to-end, the client still completes.
    const msgs = await waitForMessageType( page, 'granted', 30_000 );
    const granted = msgs.find( ( m: any ) => m?.data?.type === 'granted' )!;
    expect( granted.data.key ).toBeTruthy();
} );

test( 'signing: tampered siteUrl after SaaS response is rejected', async ( { page, context } ) => {
    // Install a tampering mu-plugin on the VENDOR site that mutates the
    // envelope's siteUrl after SaaS returns. The signature must not cover
    // the tampered form → verify_envelope returns a WP_Error → the grant
    // flow errors out instead of silently redirecting to the attacker host.
    installTamperMuPlugin();

    try {
        await runFullVendorGrantFlow( page, context );

        // Read the TrustedLogin log (not WP debug.log; the connector's
        // Logger trait writes to wp-content/uploads/trustedlogin-logs/).
        const vendorLogTail = readTrustedLoginLogTail();

        // The verify_envelope path logs the mismatch. We assert either
        // "envelope_signature_invalid" or our verifier's log line appears.
        expect(
            /envelope_signature_invalid|signature verification failed|Envelope signature did not verify|MITM tamper/i.test( vendorLogTail ),
            `Expected TrustedLogin log to mention signature mismatch. Tail:\n${ vendorLogTail.slice( -3000 ) }`,
        ).toBeTruthy();
    } finally {
        removeTamperMuPlugin();
    }
} );

test( 'signing: hard mode rejects unsigned envelopes', async ( { page, context } ) => {
    // Flip fake-saas signing off + turn on the enforce filter.
    await toggleFakeSaaSSigning( page, false );
    installEnforceMuPlugin();

    try {
        await runFullVendorGrantFlow( page, context );

        const vendorLogTail = readTrustedLoginLogTail();

        expect(
            /envelope_signature_missing|missing a signature and hard-mode/i.test( vendorLogTail ),
            `Expected TrustedLogin log to mention missing signature. Tail:\n${ vendorLogTail.slice( -3000 ) }`,
        ).toBeTruthy();
    } finally {
        removeEnforceMuPlugin();
        await toggleFakeSaaSSigning( page, true );
    }
} );

test( 'signing: legacy back-compat — no pubkey + unsigned envelope succeeds', async ( { page, context } ) => {
    // Clear the stored pubkey option AND disable signing on fake-saas: this
    // is the shape an integrator upgrading the Connector plugin without
    // configuring signing sees. The grant flow must keep working unchanged.
    await clearVendorPubkey();
    await toggleFakeSaaSSigning( page, false );

    try {
        await instrumentOpener( page );
        await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
        await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );
        await page.locator( '.tl-grant-access .tl-site-url' ).fill( VENDOR_STATE.client_url );
        await page.locator( '.tl-grant-access .tl-site-url' ).press( 'Tab' );

        const popupPromise = context.waitForEvent( 'page' );
        await page.locator( '.tl-grant-access input[type="submit"]' ).click();
        const popup = await popupPromise;
        await popup.waitForLoadState( 'domcontentloaded' );
        await popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first().click();

        const msgs = await waitForMessageType( page, 'granted', 30_000 );
        const granted = msgs.find( ( m: any ) => m?.data?.type === 'granted' )!;
        expect( granted.data.key ).toBeTruthy();
    } finally {
        await restoreVendorPubkey();
        await toggleFakeSaaSSigning( page, true );
    }
} );

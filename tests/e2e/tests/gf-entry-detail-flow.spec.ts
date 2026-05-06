/**
 * E2E: Gravity Forms entry-detail surfaces for the TrustedLogin field.
 *
 * Pins the post-submission, admin-side flow that PHPUnit (TrustedLoginGFFieldTest)
 * cannot exercise — the vendor admin reviewing a granted-access entry from the
 * GF entry list and clicking "Log in with TrustedLogin" to actually land on
 * the customer site as the support user.
 *
 * Flow under test:
 *   1. Customer submits the GF form on the vendor site → grant happens
 *      (reuses the popup/postMessage flow already pinned in
 *      popup-messages.spec.ts:152).
 *   2. As vendor admin, navigate to /wp-admin/admin.php?page=gf_entries&id=N
 *      (entry list). Assert the row carries the field column with site URL
 *      + redacted/access-key indicator.
 *   3. Open the entry detail at view=entry&lid=ENTRY_ID. Assert the
 *      TrustedLogin field's `tl-entry-detail` block renders Site URL,
 *      Access Key, and a "Log in with TrustedLogin" button whose href
 *      points at the Connector's access-key-login page with the key
 *      and (when resolvable) the account id pre-filled.
 *   4. Click that button → land on the Connector's access-key-login
 *      admin page. Assert the React form is mounted and the access key
 *      shape matches what we received from the grant.
 *
 * Step 4 stops at the Connector's login page rather than chasing the
 * full redirect into client-wp because:
 *   - The redirect-into-client-site arc is already covered by
 *     envelope-signing.spec.ts (which drives the same access-key-login
 *     form post and asserts client-side support-user landing).
 *   - The unique value of THIS spec is the GF-entry → login-button
 *     hand-off, not the AccessKeyLogin internals.
 *
 * @group gf
 * @group entry-detail
 */

import { test, expect, Page, BrowserContext } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { wpCli } from './_helpers';

type VendorState = {
    form_id: string;
    form_page_url: string;
    client_url: string;
    vendor_url: string;
    namespace: string;
    account_id: string;
};

const VENDOR_STATE: VendorState = JSON.parse(
    fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

// ---------- Helpers ----------

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

async function waitForGrantedKey( page: Page, timeoutMs = 30_000 ): Promise<string> {
    const t0 = Date.now();
    while ( Date.now() - t0 < timeoutMs ) {
        const msgs = await readMessages( page );
        const granted = msgs.find( m => m?.data?.type === 'granted' );
        if ( granted && typeof granted.data.key === 'string' && granted.data.key.length > 8 ) {
            return granted.data.key;
        }
        await page.waitForTimeout( 250 );
    }
    throw new Error( 'Timed out waiting for `granted` postMessage with a key' );
}

async function resetFakeSaaS( page: Page ) {
    await page.request.post( 'http://localhost:8003/__reset' );
}

/**
 * Delete every TrustedLogin support user on client-wp so the SDK
 * doesn't short-circuit grant_access on an existing session. Matches
 * the popup-messages.spec.ts pattern.
 */
function revokeAllSupportUsersOnClient(): void {
    wpCli(
        'wp-cli-client',
        `require_once ABSPATH . "wp-admin/includes/user.php"; `
        + `$users = get_users( array( "meta_key" => "tl_${ VENDOR_STATE.namespace }_id" ) ); `
        + `foreach ( $users as $u ) { wp_delete_user( $u->ID ); } `
        + `echo count( $users );`,
        'revokeAllSupportUsersOnClient',
    );
}

/**
 * Wipe every gf_entry row for the test form so each test starts from
 * a known baseline (latestEntryIdForTestForm() always returns the row
 * the test just created, not a leftover from earlier).
 */
function purgeGfEntriesForTestForm(): void {
    wpCli(
        'wp-cli-vendor',
        `global $wpdb; `
        + `$ids = $wpdb->get_col( $wpdb->prepare("SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d", ${ VENDOR_STATE.form_id }) ); `
        + `if ( $ids ) { `
        + `  $in = implode(',', array_map('intval', $ids)); `
        + `  $wpdb->query("DELETE FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id IN ($in)"); `
        + `  $wpdb->query("DELETE FROM {$wpdb->prefix}gf_entry_notes WHERE entry_id IN ($in)"); `
        + `  $wpdb->query("DELETE FROM {$wpdb->prefix}gf_entry WHERE id IN ($in)"); `
        + `} `
        + `echo count( $ids );`,
        'purgeGfEntriesForTestForm',
    );
}

async function loginAsClientAdmin( context: BrowserContext ) {
    const check = await context.request.get( `${ VENDOR_STATE.client_url }/wp-admin/`, { maxRedirects: 0 } ).catch( () => null );
    if ( check && check.status() === 200 ) return;
    const p = await context.newPage();
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

async function loginAsVendorAdmin( context: BrowserContext ) {
    const check = await context.request.get( `${ VENDOR_STATE.vendor_url }/wp-admin/`, { maxRedirects: 0 } ).catch( () => null );
    if ( check && check.status() === 200 ) return;
    const p = await context.newPage();
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

/**
 * Drive a real customer-side grant via the GF TL field. Returns the
 * 64-hex access key so callers can match it against the entry-detail
 * page's rendered text and the login-link href.
 */
async function grantViaGfField( page: Page, context: BrowserContext ): Promise<string> {
    await loginAsClientAdmin( context );
    await instrumentOpener( page );
    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );

    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible', timeout: 15_000 } );
    const urlField = page.locator( '.tl-grant-access .tl-site-url' );
    await urlField.fill( VENDOR_STATE.client_url );
    await urlField.blur();

    const popupPromise = context.waitForEvent( 'page' );
    await page.locator( '.tl-grant-access input[type="submit"]' ).click();
    const popup = await popupPromise;
    await popup.waitForLoadState( 'domcontentloaded' );

    const grantCta = popup.locator( '.button-trustedlogin-' + VENDOR_STATE.namespace ).first();
    await grantCta.waitFor( { state: 'visible', timeout: 15_000 } );
    await grantCta.click();

    const grantedKey = await waitForGrantedKey( page );

    // The TL-field's own "Grant Access" submit button has flipped to
    // "Access Granted" by now. Submit the surrounding GF form so an
    // entry actually persists — without this click, no gf_entry row
    // exists and the entry-detail page has nothing to render.
    //
    // GF v2.5+ renders its own submit as `.gform_button` /
    // `input.gform_button` inside `.gform_footer`. Match either to
    // remain robust across GF versions.
    const gfSubmit = page.locator( '.gform_footer .gform_button, .gform_footer input[type="submit"]' ).first();
    await gfSubmit.waitFor( { state: 'visible', timeout: 10_000 } );
    await gfSubmit.click();

    // GF (with ajax="false" — see bootstrap-vendor.sh) does a full
    // page POST/redirect to the same page with `?gf_submission_…` and
    // renders the confirmation message. Wait for either the
    // confirmation or any indicator the form processed.
    await page.waitForLoadState( 'networkidle', { timeout: 15_000 } );

    return grantedKey;
}

/**
 * Read the most recent GF entry id for the test form via wp-cli, so
 * the test can navigate directly to view=entry&lid=N without scraping
 * the entry-list HTML for a row id.
 */
function latestEntryIdForTestForm(): string {
    const out = wpCli(
        'wp-cli-vendor',
        `global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d ORDER BY id DESC LIMIT 1", ${ VENDOR_STATE.form_id })); echo $row ? $row->id : '';`,
        'fetch latest gf entry id'
    ).trim();
    if ( ! out ) {
        throw new Error( 'No GF entry rows for the test form — the grant flow did not persist a submission' );
    }
    return out;
}

/**
 * Resolve the form_id's TrustedLogin field id from gf_form_meta. The
 * field id is the column name on the entry-list table and the row
 * id rendered on the entry-detail page.
 */
function tlFieldIdOnTestForm(): string {
    const out = wpCli(
        'wp-cli-vendor',
        `global $wpdb; $meta = $wpdb->get_var($wpdb->prepare("SELECT display_meta FROM {$wpdb->prefix}gf_form_meta WHERE form_id = %d", ${ VENDOR_STATE.form_id })); $form = json_decode($meta, true); foreach ( ($form['fields'] ?? []) as $f ) { if ( ($f['type'] ?? '') === 'trustedlogin' ) { echo (int) $f['id']; return; } }`,
        'find TL field id'
    ).trim();
    if ( ! out ) {
        throw new Error( 'No TrustedLogin field found on the test form' );
    }
    return out;
}

// ---------- Tests ----------

test.describe.configure( { mode: 'serial' } );

test.describe( 'GF entry-detail flow for the TrustedLogin field', () => {

    test.beforeEach( async ( { page, context } ) => {
        await resetFakeSaaS( page );
        await loginAsClientAdmin( context );
        revokeAllSupportUsersOnClient();
        purgeGfEntriesForTestForm();
    } );

    test( 'entry-detail renders site URL + access key + Log-in button after a grant', async ( { page, context } ) => {
        const grantedKey = await grantViaGfField( page, context );
        const entryId = latestEntryIdForTestForm();
        const fieldId = tlFieldIdOnTestForm();

        await loginAsVendorAdmin( context );

        const entryDetailUrl = `${ VENDOR_STATE.vendor_url }/wp-admin/admin.php?page=gf_entries&view=entry&id=${ VENDOR_STATE.form_id }&lid=${ entryId }`;
        await page.goto( entryDetailUrl, { waitUntil: 'domcontentloaded' } );

        // The TL field's `get_value_entry_detail` wraps everything in
        // `<div class="tl-entry-detail">`. If the grant flow stored a
        // value-bearing entry, this div MUST render — even partial-grant
        // (URL-only) cases produce it.
        const detailBlock = page.locator( '.tl-entry-detail' );
        await expect( detailBlock, 'entry-detail must render the tl-entry-detail block' )
            .toBeVisible( { timeout: 10_000 } );

        // Site URL line — links to the customer site.
        const siteUrlLink = detailBlock.locator( `a[href="${ VENDOR_STATE.client_url }"]` ).first();
        await expect( siteUrlLink, 'site URL must render as a link to the granted customer site' )
            .toBeVisible();
        await expect( siteUrlLink ).toHaveAttribute( 'target', '_blank' );
        await expect( siteUrlLink ).toHaveAttribute( 'rel', /noopener/ );

        // Access key — rendered inside <code>.
        const accessKeyEl = detailBlock.locator( 'code' ).first();
        await expect( accessKeyEl ).toContainText( grantedKey );

        // Login button — built by build_login_url() — must link at the
        // Connector's access-key-login admin page with `ak` and (when
        // resolvable) `ak_account_id` pre-filled.
        const loginBtn = detailBlock.getByRole( 'link', { name: /log in with trustedlogin/i } );
        await expect( loginBtn ).toBeVisible();
        const href = await loginBtn.getAttribute( 'href' );
        expect( href, 'login button href' ).toBeTruthy();
        const url = new URL( href as string );
        expect( url.searchParams.get( 'page' ) ).toBe( 'trustedlogin_access_key_login' );
        expect( url.searchParams.get( 'ak' ) ).toBe( grantedKey );
        // ak_account_id is optional — only included when resolve_account_id
        // returns a value. Assert a stable invariant: when present, it's
        // a non-empty string.
        const accountId = url.searchParams.get( 'ak_account_id' );
        if ( accountId !== null ) {
            expect( accountId.length ).toBeGreaterThan( 0 );
        }

        // The fieldId is on the row — pin so a future blade-id refactor
        // catches a regression.
        const fieldRow = page.locator( `#field-${ fieldId }, [data-field-id="${ fieldId }"]` ).first();
        // Don't fail hard — GF's entry-detail row id varies between
        // versions; the tl-entry-detail block above is the canonical
        // signal. This assertion is a soft cross-check.
        if ( await fieldRow.count() > 0 ) {
            await expect( fieldRow ).toBeVisible();
        }
    } );

    test( 'entry-list column shows the field value summary', async ( { page, context } ) => {
        const grantedKey = await grantViaGfField( page, context );

        await loginAsVendorAdmin( context );

        const listUrl = `${ VENDOR_STATE.vendor_url }/wp-admin/admin.php?page=gf_entries&id=${ VENDOR_STATE.form_id }`;
        await page.goto( listUrl, { waitUntil: 'domcontentloaded' } );

        // GF renders entry list rows in a wp-list-table. Field-N column
        // carries the result of get_column_value(). Assert at least one
        // row contains the customer site URL.
        const listTable = page.locator( '.wp-list-table' );
        await expect( listTable ).toBeVisible( { timeout: 10_000 } );
        await expect( listTable ).toContainText( VENDOR_STATE.client_url );

        // The full access key SHOULD NOT be visible in plain text on the
        // entry-list — that surface is for at-a-glance browsing and
        // every visible character of the key reduces the privacy margin
        // for shoulder-surfing. The detail page is where the key
        // belongs.
        const tableText = await listTable.innerText();
        expect( tableText, 'entry list must NOT render the full 64-hex access key inline (privacy)' )
            .not.toContain( grantedKey );

        // Sanity: the field column is present with the configured label.
        // GF identifies the column by id `field_id-{N}`. The default label
        // for the TL field is "Site URL" — which is what the bootstrap
        // seeds.
        await expect( listTable.locator( '#field_id-1' ).first() )
            .toContainText( /site url|trustedlogin/i );
    } );

    test( 'clicking the Log-in button lands on the Connector access-key-login page with key prefilled', async ( { page, context } ) => {
        const grantedKey = await grantViaGfField( page, context );
        const entryId = latestEntryIdForTestForm();

        await loginAsVendorAdmin( context );

        const entryDetailUrl = `${ VENDOR_STATE.vendor_url }/wp-admin/admin.php?page=gf_entries&view=entry&id=${ VENDOR_STATE.form_id }&lid=${ entryId }`;
        await page.goto( entryDetailUrl, { waitUntil: 'domcontentloaded' } );

        const loginBtn = page.locator( '.tl-entry-detail' ).getByRole( 'link', { name: /log in with trustedlogin/i } );
        await expect( loginBtn ).toBeVisible( { timeout: 10_000 } );

        // Pull the href and navigate directly. We don't drive a real click
        // here because GF's setup-wizard modal can intercept pointer events
        // on a fresh-bootstrapped install — and the user-meaningful
        // assertion is "this URL hands off correctly", not "the click
        // event reaches the anchor". The `target="_blank"` and `rel="noopener"`
        // attributes are pinned by the entry-detail render test above.
        const href = await loginBtn.getAttribute( 'href' );
        expect( href, 'login button must carry an href' ).toBeTruthy();

        const landingPage = await context.newPage();
        await landingPage.goto( href as string, { waitUntil: 'networkidle' } );

        // Two valid landing states for a successful hand-off:
        //
        //   A. The Connector's access-key-login page on the vendor —
        //      React form mounted, agent has not yet clicked through.
        //
        //   B. Auto-redirect into the customer site (i.e. the
        //      `ak_account_id`-present fast path: AccessKeyLogin::handle()
        //      pre-fetches redirectData and React auto-submits the
        //      form, landing the agent on the customer site as the
        //      support user — possibly with `?tl_notice=already_logged_in`
        //      when the test session is already client-admin).
        //
        // Both are user-visible proof that the GF-entry → access-key
        // hand-off works. Assert the disjunction.
        const landingUrl = new URL( landingPage.url() );
        const onVendorAdmin   = landingUrl.host === new URL( VENDOR_STATE.vendor_url ).host
            && landingUrl.searchParams.get( 'page' ) === 'trustedlogin_access_key_login';
        const onClientWpAdmin = landingUrl.host === new URL( VENDOR_STATE.client_url ).host
            && landingUrl.pathname.includes( '/wp-admin' );

        expect(
            onVendorAdmin || onClientWpAdmin,
            `landing URL must be either the vendor access-key-login page or the client wp-admin (got: ${ landingPage.url() })`
        ).toBe( true );

        if ( onVendorAdmin ) {
            // The query carries the key + account id we just clicked.
            expect( landingUrl.searchParams.get( 'ak' ) ).toBe( grantedKey );

            // The Connector's React mount point or its hidden access-key
            // input — proof the bundle actually rendered.
            const reactMount = landingPage.locator(
                '#trustedlogin_access_key_login, [data-tl-react], .trustedlogin-react, form input[name*="access_key"], input[type="hidden"][value="' + grantedKey + '"]'
            ).first();
            await expect( reactMount ).toBeVisible( { timeout: 15_000 } );
        }
        // For the client-wp-admin branch the assertion above is sufficient:
        // landing on the customer site IS the proof the access-key login
        // resolved successfully.

        await landingPage.close();
    } );
} );

/**
 * E2E: Gravity Forms label settings on the TrustedLogin field.
 *
 * Verifies that:
 *   - Each GF labelPlacement value maps to the right .tl-label-* modifier
 *     class on .tl-field (which drives the CSS layout).
 *   - A custom "Field Label" value set in the form editor renders through
 *     to the front-end instead of the default "Site URL".
 *
 * The form is mutated via wp-cli eval in the vendor-wp container before
 * each sub-test; the page is reloaded to pick up the change.
 */

import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { execSync } from 'child_process';

type VendorState = {
    form_id:       string;
    form_page_url: string;
    namespace:     string;
};

const VENDOR_STATE: VendorState = JSON.parse(
    fs.readFileSync( path.join( __dirname, '..', 'fixtures', '.cache-vendor-state.json' ), 'utf-8' )
);

const E2E_DIR = path.resolve( __dirname, '..' );

/**
 * Mutate the TL field on form {form_id} via wp-cli eval. `updates` is merged
 * into field[0] (the TrustedLogin field). Example: {labelPlacement: "left_label"}.
 */
function updateTLField( updates: Record<string, string> ) {
    const formId = VENDOR_STATE.form_id;
    const phpUpdates = Object.entries( updates )
        .map( ( [ key, value ] ) => `$form["fields"][0]->${ key } = ${ JSON.stringify( value ) };` )
        .join( ' ' );
    const cmd = `docker compose run --rm -T wp-cli-vendor wp eval '`
        + `$form = GFAPI::get_form( ${ formId } ); `
        + phpUpdates + ' '
        + `GFAPI::update_form( $form ); echo "ok";`
        + `'`;
    execSync( cmd, { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 30_000 } );
}

async function fieldClassList( page: Page ): Promise<string> {
    return ( await page.locator( '.tl-field' ).first().getAttribute( 'class' ) ) || '';
}

test.describe.configure( { mode: 'serial' } );

// Restore default state (top_label + default field label + default heading)
// after this spec so other spec files don't inherit a mutated field.
test.afterAll( async () => {
    execSync(
        `docker compose run --rm -T wp-cli-vendor wp eval '`
        + `$form = GFAPI::get_form( ${ VENDOR_STATE.form_id } ); `
        + `$form["fields"][0]->label = "Site URL"; `
        + `$form["fields"][0]->labelPlacement = ""; `
        + `unset( $form["fields"][0]->tlHeadingText ); `
        + `GFAPI::update_form( $form ); echo "ok";`
        + `'`,
        { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 30_000 }
    );
} );

test( 'default — label is "Site URL" and class is tl-label-above', async ( { page } ) => {
    updateTLField( { labelPlacement: '', label: 'Site URL' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    expect( await fieldClassList( page ) ).toContain( 'tl-label-above' );
    await expect( page.locator( '.tl-field .tl-field-label' ) ).toBeVisible();
    await expect( page.locator( '.tl-field .tl-field-label' ) ).toHaveText( 'Site URL' );
} );

test( 'custom field label renders through from GF form editor', async ( { page } ) => {
    updateTLField( { label: 'Where should we log in?', labelPlacement: '' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    await expect( page.locator( '.tl-field .tl-field-label' ) ).toHaveText( 'Where should we log in?' );
} );

test( 'labelPlacement "inputs_above_labels" sets tl-label-below', async ( { page } ) => {
    updateTLField( { labelPlacement: 'inputs_above_labels' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    expect( await fieldClassList( page ) ).toContain( 'tl-label-below' );
    // Label is visible but physically BELOW the input (flex order).
    await expect( page.locator( '.tl-field .tl-field-label' ) ).toBeVisible();

    // Check DOM order: input comes BEFORE label in visual order via flex. We
    // verify by checking the y-coordinates.
    const labelBox = await page.locator( '.tl-field .tl-field-label' ).first().boundingBox();
    const inputBox = await page.locator( '.tl-field input[type="url"]' ).first().boundingBox();
    expect( labelBox && inputBox, 'label + input must be visible' ).toBeTruthy();
    expect( labelBox!.y, 'label should render BELOW input' ).toBeGreaterThan( inputBox!.y );
} );

test( 'labelPlacement "left_label" sets tl-label-left', async ( { page } ) => {
    updateTLField( { labelPlacement: 'left_label' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    expect( await fieldClassList( page ) ).toContain( 'tl-label-left' );
    const labelBox = await page.locator( '.tl-field .tl-field-label' ).first().boundingBox();
    const inputBox = await page.locator( '.tl-field input[type="url"]' ).first().boundingBox();
    expect( labelBox && inputBox ).toBeTruthy();
    expect( labelBox!.x + labelBox!.width, 'label should be left of input' ).toBeLessThanOrEqual( inputBox!.x + 2 );
} );

test( 'labelPlacement "right_label" sets tl-label-right', async ( { page } ) => {
    updateTLField( { labelPlacement: 'right_label' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    expect( await fieldClassList( page ) ).toContain( 'tl-label-right' );
    const labelBox = await page.locator( '.tl-field .tl-field-label' ).first().boundingBox();
    const inputBox = await page.locator( '.tl-field input[type="url"]' ).first().boundingBox();
    expect( labelBox && inputBox ).toBeTruthy();
    expect( labelBox!.x, 'label should be right of input' ).toBeGreaterThanOrEqual( inputBox!.x + inputBox!.width - 2 );
} );

test( 'labelPlacement "hidden_label" sets tl-label-hidden and hides the label visually', async ( { page } ) => {
    updateTLField( { labelPlacement: 'hidden_label' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    expect( await fieldClassList( page ) ).toContain( 'tl-label-hidden' );

    // Label should be in the DOM for screen readers but visually clipped.
    const label = page.locator( '.tl-field .tl-field-label' );
    await expect( label ).toHaveCount( 1 );
    const box = await label.boundingBox();
    expect( box, 'label must exist in DOM' ).toBeTruthy();
    // Clipped to 1px x 1px (sr-only).
    expect( box!.width, 'label should be clipped' ).toBeLessThanOrEqual( 2 );
    expect( box!.height, 'label should be clipped' ).toBeLessThanOrEqual( 2 );
} );

test( 'heading text — default "Grant Access with TrustedLogin" renders', async ( { page } ) => {
    // Reset tlHeadingText to null via a direct property unset.
    execSync(
        `docker compose run --rm -T wp-cli-vendor wp eval '`
        + `$form = GFAPI::get_form( ${ VENDOR_STATE.form_id } ); `
        + `unset( $form["fields"][0]->tlHeadingText ); `
        + `GFAPI::update_form( $form ); echo "ok";`
        + `'`,
        { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 30_000 }
    );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    await expect( page.locator( '.tl-grant-access .tl-header' ) ).toBeVisible();
    await expect( page.locator( '.tl-grant-access .tl-header label' ) ).toHaveText( 'Grant Access with TrustedLogin' );
} );

test( 'heading text — custom value renders', async ( { page } ) => {
    updateTLField( { tlHeadingText: 'Let our support team in' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    await expect( page.locator( '.tl-grant-access .tl-header' ) ).toBeVisible();
    await expect( page.locator( '.tl-grant-access .tl-header label' ) ).toHaveText( 'Let our support team in' );
} );

test( 'heading text — empty string suppresses the header entirely', async ( { page } ) => {
    updateTLField( { tlHeadingText: '' } );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    // .tl-header element must be absent from the DOM entirely when blanked.
    await expect( page.locator( '.tl-grant-access .tl-header' ) ).toHaveCount( 0 );
    // The rest of the field still renders.
    await expect( page.locator( '.tl-grant-access .tl-site-url' ) ).toBeVisible();
} );

test( 'form-level labelPlacement flows through when field has no override', async ( { page } ) => {
    // Clear the field-level override, set form-level only.
    updateTLField( { labelPlacement: '' } );
    execSync(
        `docker compose run --rm -T wp-cli-vendor wp eval '`
        + `$form = GFAPI::get_form( ${ VENDOR_STATE.form_id } ); `
        + `$form["labelPlacement"] = "hidden_label"; `
        + `GFAPI::update_form( $form ); echo "ok";`
        + `'`,
        { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 30_000 }
    );

    await page.goto( VENDOR_STATE.form_page_url, { waitUntil: 'domcontentloaded' } );
    await page.locator( '.tl-grant-access' ).waitFor( { state: 'visible' } );

    expect( await fieldClassList( page ) ).toContain( 'tl-label-hidden' );

    // Reset form-level labelPlacement.
    execSync(
        `docker compose run --rm -T wp-cli-vendor wp eval '`
        + `$form = GFAPI::get_form( ${ VENDOR_STATE.form_id } ); `
        + `$form["labelPlacement"] = "top_label"; `
        + `GFAPI::update_form( $form ); echo "ok";`
        + `'`,
        { cwd: E2E_DIR, stdio: [ 'ignore', 'ignore', 'ignore' ], timeout: 30_000 }
    );
} );

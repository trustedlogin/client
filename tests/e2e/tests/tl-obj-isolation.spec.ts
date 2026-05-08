/**
 * E2E: per-namespace JS-config isolation.
 *
 * The button-grant flow used to write its config blob to a single
 * global `window.tl_obj` via wp_localize_script. With two TL-using
 * plugins coexisting on a single site (and especially on a single
 * page), the second namespace's localize call would clobber the
 * first's `tl_obj` and the first vendor's button click would dispatch
 * to the WRONG vendor's AJAX action.
 *
 * Option B: switch to a namespaced root, `window.trustedLogin[ns]`,
 * with a `data-tl-namespace` attribute on the rendered button so the
 * delegated JS handler can pick the right config per click.
 *
 * This spec asserts the new contract end-to-end:
 *   1. `window.trustedLogin` is the active root, not `window.tl_obj`.
 *   2. The grant button carries `data-tl-namespace`.
 *   3. Each namespace has its OWN entry under window.trustedLogin
 *      and the entries don't bleed into each other.
 */

import { test, expect } from '@playwright/test';
import { CLIENT_URL, loginAsAdmin } from './helpers/login';

const NS_A   = 'pro-block-builder';
const NS_B   = 'widget-master';
const SLUG_A = `grant-${ NS_A }-access`;
const SLUG_B = `grant-${ NS_B }-access`;

test.describe.configure( { mode: 'serial' } );

test( 'window.tl_obj global is gone — replaced by window.trustedLogin root', async ( { page } ) => {
	await loginAsAdmin( page );
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'networkidle' } );
	await expect( page.locator( '.tl-client-grant-button' ) ).toBeVisible();

	const probe = await page.evaluate( () => ( {
		hasLegacy: typeof ( window as unknown as { tl_obj?: unknown } ).tl_obj !== 'undefined',
		hasRoot:   typeof ( window as unknown as { trustedLogin?: unknown } ).trustedLogin === 'object'
			&& ( window as unknown as { trustedLogin?: Record<string, unknown> } ).trustedLogin !== null,
	} ) );

	expect( probe.hasLegacy,
		'window.tl_obj must NOT be defined — that is the colliding global Option B retires' )
		.toBe( false );
	expect( probe.hasRoot,
		'window.trustedLogin must be defined as the namespaced config root' )
		.toBe( true );
} );

test( 'window.trustedLogin[ns] holds the active namespace config', async ( { page } ) => {
	await loginAsAdmin( page );
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'networkidle' } );
	await expect( page.locator( '.tl-client-grant-button' ) ).toBeVisible();

	const cfg = await page.evaluate( ( ns ) => {
		const root = ( window as unknown as { trustedLogin?: Record<string, unknown> } ).trustedLogin;
		if ( ! root ) {
			return null;
		}
		const entry = root[ ns ];
		if ( ! entry || typeof entry !== 'object' ) {
			return null;
		}
		const e = entry as Record<string, unknown>;
		const vendor = e.vendor as Record<string, unknown> | undefined;
		return {
			vendorNamespace: vendor && typeof vendor.namespace === 'string' ? vendor.namespace : null,
			ajaxurl:         typeof e.ajaxurl === 'string' ? e.ajaxurl : null,
			selector:        typeof e.selector === 'string' ? e.selector : null,
		};
	}, NS_A );

	expect( cfg, `window.trustedLogin["${ NS_A }"] must exist` ).not.toBeNull();
	expect( cfg!.vendorNamespace, 'vendor.namespace must match the loaded page' ).toBe( NS_A );
	expect( cfg!.ajaxurl, 'ajaxurl must be the wp-admin AJAX endpoint' ).toMatch( /admin-ajax\.php$/ );
	expect( cfg!.selector, 'selector must point to the namespaced grant button' ).toContain( NS_A );
} );

test( 'grant button carries data-tl-namespace matching its config key', async ( { page } ) => {
	await loginAsAdmin( page );
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'networkidle' } );

	const button = page.locator( '.tl-client-grant-button' ).first();
	await expect( button ).toBeVisible();

	await expect( button,
		'data-tl-namespace lets the delegated click handler look up the right config' )
		.toHaveAttribute( 'data-tl-namespace', NS_A );
} );

test( 'second namespace has its own entry — no cross-contamination', async ( { page } ) => {
	await loginAsAdmin( page );

	// Visit page A first, capture its config.
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_A }`, { waitUntil: 'networkidle' } );
	await expect( page.locator( '.tl-client-grant-button' ) ).toBeVisible();
	const aOnly = await page.evaluate( () => {
		const root = ( window as unknown as { trustedLogin?: Record<string, unknown> } ).trustedLogin;
		return root ? Object.keys( root ).sort() : [];
	} );

	// Now visit page B (fresh page load — separate window.trustedLogin).
	await page.goto( CLIENT_URL + `/wp-admin/admin.php?page=${ SLUG_B }`, { waitUntil: 'networkidle' } );
	await expect( page.locator( '.tl-client-grant-button' ) ).toBeVisible();
	const bOnly = await page.evaluate( () => {
		const root = ( window as unknown as { trustedLogin?: Record<string, unknown> } ).trustedLogin;
		return root ? Object.keys( root ).sort() : [];
	} );

	expect( aOnly,
		`page A must populate window.trustedLogin["${ NS_A }"]` )
		.toContain( NS_A );
	expect( bOnly,
		`page B must populate window.trustedLogin["${ NS_B }"]` )
		.toContain( NS_B );
} );

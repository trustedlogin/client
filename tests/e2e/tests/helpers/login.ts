/**
 * Real wp-login.php form login.
 *
 * Drives the actual production login chain: GET wp-login.php (sets the
 * test cookie WP requires before the credential POST), then POST the
 * form. Cookies land in the page's BrowserContext jar; subsequent
 * navigations are authenticated.
 *
 * The cap-enforcement spec uses tl-test-login mu-plugin (a cookie-jar
 * shortcut). That's appropriate for "is the cap gate enforced" tests
 * because the auth path isn't what's under test. Browser-driven flow
 * specs (grant/revoke/extend/error-banner) MUST go through the real
 * wp-login.php so they validate the production wire path end-to-end.
 */

import type { Page } from '@playwright/test';

export const CLIENT_URL = 'http://localhost:8002';

export async function loginAsAdmin(
	page: Page,
	opts: { user?: string; pass?: string; baseUrl?: string } = {},
): Promise<void> {
	const baseUrl = opts.baseUrl ?? CLIENT_URL;
	const user    = opts.user ?? 'admin';
	const pass    = opts.pass ?? 'admin';

	// `load` (not `domcontentloaded`): wp-login.php enqueues
	// password-strength-meter.js + zxcvbn which can rebind input
	// handlers after DOMContentLoaded. Filling between those two
	// events occasionally lands the value into the wrong DOM node;
	// the visible input then submits empty and the browser\'s HTML5
	// "Please fill out this field" tooltip is the test\'s only
	// signal. Waiting for `load` removes the race entirely.
	await page.goto( `${ baseUrl }/wp-login.php`, { waitUntil: 'load' } );

	const passwordInput = page.locator( 'input[name="pwd"]' );
	await passwordInput.waitFor( { state: 'visible' } );

	// Retry loop. wp-login.php enqueues password-strength-meter.js +
	// zxcvbn.min.js, both of which can rebind input handlers AFTER
	// `load`. A fill that happens just before that rebind sometimes
	// gets wiped, leaving an empty field that submits and produces
	// the HTML5 "Please fill out this field" tooltip 30s later.
	// Fill, verify, retry — usually first try sticks; second always
	// does. If it fails 3x in a row, surface the real error.
	let lastObserved = '';
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		await page.fill( 'input[name="log"]', user );
		await passwordInput.fill( pass );

		lastObserved = await passwordInput.inputValue();
		if ( lastObserved === pass ) {
			break;
		}

		// Give zxcvbn a moment to finish whatever it was doing, then retry.
		await page.waitForTimeout( 250 );
	}

	if ( lastObserved !== pass ) {
		throw new Error(
			`loginAsAdmin: password fill did not stick after 3 attempts (last got ${ JSON.stringify( lastObserved ) }). `
			+ 'wp-login.php strength-meter scripts are aggressively clearing the input.'
		);
	}

	// noWaitAfter: the dashboard renders a slow widget (admin pointer
	// iframe + MOTW-blocked resources) whose load can blow past the
	// default 15s actionTimeout. We don\'t care about the dashboard;
	// we care that the redirect to /wp-admin/ landed. waitForURL is
	// the actual assertion of "login worked".
	await page.click( 'input[name="wp-submit"]', { noWaitAfter: true } );
	await page.waitForURL( /\/wp-admin\// );
}

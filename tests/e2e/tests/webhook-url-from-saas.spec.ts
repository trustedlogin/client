/**
 * Browser-driven E2E: webhook URL cached from SaaS sync.
 *
 * Pins the production wire path that PHPUnit cannot reach:
 *   1. fake-saas is told to include `webhookUrl` in the next /sites/
 *      response (POST /__set-webhook-url).
 *   2. Admin clicks Grant Support Access in wp-admin.
 *   3. Client::grant_access → SiteAccess::sync_secret POSTs the
 *      sealed envelope to fake-saas.
 *   4. fake-saas replies 201 with { success: true, siteId, webhookUrl }.
 *   5. Config::sanitize_webhook_url accepts the value, then
 *      SiteAccess writes it into option `tl_{NS}_webhook_url`.
 *
 * What we assert via wp-cli:
 *   - the option exists and matches the SaaS-supplied value (happy path)
 *   - the option is rejected when fake-saas returns a non-https URL
 *     (validates the post-decode sanitizer is wired into the cache write)
 *   - autoload is `off` (or its WP-6.7+ equivalents) so the URL never
 *     leaks into wp_load_alloptions().
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { wpCli } from './_helpers';
import { loginAsAdmin } from './helpers/login';
import { GrantForm, NS } from './helpers/grant-form';
import { resetSupportUsers, enableSslOverride, clearSslOverride } from './helpers/seed';

const FAKE_SAAS_BASE = 'http://localhost:8003';
const OPTION_KEY = `tl_${ NS }_webhook_url`;

function setSaasWebhookUrl( url: string ): void {
	const payload = JSON.stringify( { url } );
	try {
		execSync(
			`curl -fsS -X POST -H "Content-Type: application/json" -d ${ JSON.stringify( payload ) } ${ FAKE_SAAS_BASE }/__set-webhook-url >/dev/null`,
			{ timeout: 5_000 }
		);
	} catch ( e: any ) {
		throw new Error( `failed to set fake-saas webhookUrl: ${ e.message }` );
	}
}

function readOption( key: string ): string {
	return wpCli(
		'wp-cli-client',
		`echo (string) get_option('${ key }', '');`,
		`read option ${ key }`
	).trim();
}

function readAutoload( key: string ): string {
	return wpCli(
		'wp-cli-client',
		`global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", '${ key }'));`,
		`read autoload for ${ key }`
	).trim();
}

function deleteOption( key: string ): void {
	wpCli(
		'wp-cli-client',
		`delete_option('${ key }');`,
		`delete option ${ key }`
	);
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	enableSslOverride();
} );

test.afterAll( () => {
	clearSslOverride();
	resetSupportUsers();
	// Leave fake-saas in a clean state so no later spec inherits a webhookUrl.
	setSaasWebhookUrl( '' );
} );

test.beforeEach( () => {
	resetSupportUsers();
	deleteOption( OPTION_KEY );
} );

test( 'SaaS-supplied webhookUrl is cached as option and autoload=off', async ( { page } ) => {
	const expected = 'https://hooks.example.com/wh-' + Date.now().toString( 36 );
	setSaasWebhookUrl( expected );

	await loginAsAdmin( page );

	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput() ).toBeVisible( { timeout: 30_000 } );

	// Cache write happens synchronously inside sync_secret on the same
	// wp request that processed the AJAX. By the time the access-key
	// input renders, the option is committed.
	const stored = readOption( OPTION_KEY );
	expect( stored, 'option must equal SaaS-supplied webhookUrl' ).toBe( expected );

	// Autoload guard — the SDK explicitly writes the option with autoload=false
	// so it never enters wp_load_alloptions(). WP 6.7 changed the on-disk
	// representation from 'no' → 'off' / 'auto-off'. Accept any of those.
	const autoload = readAutoload( OPTION_KEY );
	expect( [ 'no', 'off', 'auto-off' ], `autoload was '${ autoload }' — expected a non-autoloaded value` )
		.toContain( autoload );
} );

test( 'SaaS-supplied http:// webhookUrl is rejected — option stays absent', async ( { page } ) => {
	// Sanitizer must reject non-https schemes (sanitize_webhook_url)
	// even when the SaaS hands them back. Defense in depth against a
	// SaaS bug or attacker-controlled response substitution.
	setSaasWebhookUrl( 'http://hooks.example.com/insecure' );

	await loginAsAdmin( page );

	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput() ).toBeVisible( { timeout: 30_000 } );

	const stored = readOption( OPTION_KEY );
	expect( stored, 'http:// URL must NOT be persisted to the cache option' ).toBe( '' );
} );

test( 'SaaS-supplied URL with userinfo is rejected — option stays absent', async ( { page } ) => {
	setSaasWebhookUrl( 'https://attacker:pwn@hooks.example.com/abc' );

	await loginAsAdmin( page );

	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput() ).toBeVisible( { timeout: 30_000 } );

	const stored = readOption( OPTION_KEY );
	expect( stored, 'URL containing user:pass must NOT be persisted' ).toBe( '' );
} );

test( 'older SaaS without webhookUrl field — option stays absent (back-compat)', async ( { page } ) => {
	// Empty string clears the override; fake-saas omits webhookUrl.
	setSaasWebhookUrl( '' );

	await loginAsAdmin( page );

	const form = new GrantForm( page );
	await form.navigate();
	await expect( form.grantButton() ).toBeVisible();
	await form.grantButton().click();
	await expect( form.accessKeyInput() ).toBeVisible( { timeout: 30_000 } );

	const stored = readOption( OPTION_KEY );
	expect( stored, 'no webhookUrl in SaaS response → option must remain unset' ).toBe( '' );
} );

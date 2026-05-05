/**
 * Playwright global setup — runs once before the entire suite starts.
 *
 * Defensive: deactivate Wordfence on client-wp before any spec runs.
 *
 * Why: compat-wordfence.spec.ts activates Wordfence in beforeAll and
 * deactivates it in afterAll. If a previous run was killed with
 * SIGKILL (e.g. `pkill -9 playwright` during debugging), afterAll
 * never executed and Wordfence remained active. With WAF
 * intercepting every request, every page load against client-wp
 * grows from <1s to ~8s, blowing past the default 15s actionTimeout
 * on browser-driven flow specs and timing out logins, AJAX, etc.
 *
 * Running `wp plugin deactivate wordfence` here costs ~2s once and is
 * a no-op when Wordfence is already inactive.
 *
 * Wired up in playwright.config.ts via globalSetup.
 */

import { spawnSync } from 'child_process';
import * as path from 'path';

const E2E_DIR = path.resolve( __dirname );

export default async function globalSetup(): Promise<void> {
	const result = spawnSync(
		'docker',
		[ 'compose', 'run', '--rm', '-T', 'wp-cli-client', 'wp', 'plugin', 'deactivate', 'wordfence' ],
		{ cwd: E2E_DIR, encoding: 'utf8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'pipe' ] },
	);

	// `wp plugin deactivate` exits 0 when the plugin is already inactive
	// AND when it deactivates successfully — both are fine. Only an
	// outright error (docker compose unreachable, network broken)
	// produces a non-zero exit.
	if ( result.status !== 0 ) {
		const stderr = ( result.stderr || '' ).toString().trim();
		// eslint-disable-next-line no-console
		console.warn( `[global-setup] could not pre-deactivate wordfence: ${ stderr || 'unknown error' }` );
	}
}

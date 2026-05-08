/**
 * Playwright global teardown — runs once after the entire suite completes.
 *
 * Drops the client-wp WordPress debug.log into test-results/ if there
 * were any test failures during the run. Without this, debugging a
 * failed test means manually opening a docker shell after the fact
 * (and hoping the log hasn\'t been overwritten by subsequent tests).
 *
 * Wired up in playwright.config.ts via globalTeardown.
 */

import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

const E2E_DIR = path.resolve( __dirname );
const RESULTS_DIR = path.join( E2E_DIR, 'test-results' );

export default async function globalTeardown(): Promise<void> {
	// Playwright already created test-results/ for any failures. If the
	// dir doesn\'t exist, the run was either fully green or never
	// reached test execution — either way, no log dump needed.
	if ( ! fs.existsSync( RESULTS_DIR ) ) {
		return;
	}

	// Skip the dump if no failure folders exist. Playwright creates one
	// `test-results/<test-name>/` per failed test; an empty results dir
	// means a clean run.
	const entries = fs.readdirSync( RESULTS_DIR, { withFileTypes: true } );
	const hasFailureDirs = entries.some( e => e.isDirectory() && e.name !== '.last-run' );
	if ( ! hasFailureDirs ) {
		return;
	}

	try {
		const debugLog = execSync(
			'docker compose exec -T client-wp cat /var/www/html/wp-content/debug.log 2>/dev/null || true',
			{ cwd: E2E_DIR, encoding: 'utf8', timeout: 10_000 },
		).toString();

		if ( ! debugLog.trim() ) {
			return;
		}

		// Tail to the last 500 lines — full debug.log can balloon to
		// megabytes after multiple runs and that\'s never useful.
		const tail = debugLog.split( '\n' ).slice( -500 ).join( '\n' );

		const target = path.join( RESULTS_DIR, 'wp-debug.log' );
		fs.writeFileSync( target, tail );

		// eslint-disable-next-line no-console
		console.log( `\n[global-teardown] WordPress debug.log (last 500 lines) → ${ target }` );
	} catch ( e: any ) {
		// Don\'t mask test failures with teardown failures. Log and move on.
		// eslint-disable-next-line no-console
		console.warn( `[global-teardown] could not capture debug.log: ${ e.message }` );
	}
}

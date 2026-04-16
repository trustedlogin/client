/**
 * Shared test helpers.
 *
 * Centralises wp-cli invocation + state reset so individual spec files
 * don't hand-roll execSync with ignored stdio (which used to swallow
 * real errors and leave tests running on stale state).
 */

import { execSync, type ExecSyncOptionsWithStringEncoding } from 'child_process';
import * as path from 'path';

export const E2E_DIR = path.resolve( __dirname, '..' );

/**
 * Run a wp-cli `eval` against the named container, failing the caller
 * loudly when the command exits non-zero or its output contains an
 * error marker. Returns stdout (trimmed).
 *
 * Caller is responsible for escaping single quotes inside the PHP
 * snippet, or using JSON.stringify for string literals.
 */
export function wpCli(
    container: 'wp-cli-client' | 'wp-cli-vendor',
    phpSnippet: string,
    label?: string,
): string {
    const cmd = `docker compose run --rm -T ${ container } wp eval '${ phpSnippet }'`;

    let stdout: string;
    try {
        stdout = execSync( cmd, {
            cwd:      E2E_DIR,
            encoding: 'utf8',
            timeout:  30_000,
            stdio:    [ 'ignore', 'pipe', 'pipe' ],
        } as ExecSyncOptionsWithStringEncoding );
    } catch ( e: any ) {
        // execSync throws on non-zero exit; surface both streams so we
        // don't debug blind. Include the snippet that failed so the
        // test report is self-contained.
        const msg = [
            `wpCli failed${ label ? ' (' + label + ')' : '' }:`,
            `  cmd: ${ cmd }`,
            `  exit: ${ e.status ?? '?' }`,
            `  stdout: ${ ( e.stdout || '' ).toString().trim() || '(empty)' }`,
            `  stderr: ${ ( e.stderr || '' ).toString().trim() || '(empty)' }`,
        ].join( '\n' );
        throw new Error( msg );
    }

    // wp-cli eval puts PHP warnings on stderr but still exits 0. Docker
    // compose prepends " Container e2e-mariadb-1  Running" etc. to
    // stdout. Strip those framing lines, then look for explicit PHP
    // error markers we shouldn't tolerate silently.
    const body = stdout
        .split( '\n' )
        .filter( line => ! /^\s*Container /.test( line ) )
        .filter( line => ! /^Creating volume /.test( line ) )
        .join( '\n' )
        .trim();

    if ( /(Fatal error|Uncaught |Parse error):/i.test( body ) ) {
        throw new Error(
            `wpCli produced a PHP fatal${ label ? ' (' + label + ')' : '' }:\n${ body }\n  cmd: ${ cmd }`
        );
    }

    return body;
}

/**
 * Reset the client site + fake-saas to a clean baseline.
 *
 * Kills: stored endpoint option, lockdown transient, used-accesskeys
 * transient, login-error transient, any lingering support users with
 * the pro-block-builder meta key. Also clears fake-saas state.
 *
 * Any failure in the reset is fatal for the test that called it —
 * silent failures here were the cause of several flakes.
 */
export function resetClientState(): void {
    wpCli(
        'wp-cli-client',
        `global $wpdb; `
        + `$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \\"tl-pro-block-builder-%\\" OR option_name LIKE \\"tl_pro-block-builder%\\" OR option_name LIKE \\"trustedlogin_pro-block-builder_%\\"" ); `
        + `require_once ABSPATH . "wp-admin/includes/user.php"; `
        + `foreach ( get_users( array( "meta_key" => "tl_pro-block-builder_id" ) ) as $u ) { wp_delete_user( $u->ID ); } `
        + `echo "ok";`,
        'resetClientState',
    );

    // fake-saas reset (HTTP endpoint, not wp-cli).
    try {
        execSync( `curl -fsS -X POST http://localhost:8003/__reset >/dev/null`, { timeout: 5_000 } );
    } catch ( e: any ) {
        throw new Error( `fake-saas reset failed: ${ e.message }` );
    }
}

/**
 * Ensure a wp-admin cache flush + opcache reset on the named container.
 *
 * WordPress object cache + PHP opcache can both hold stale form data
 * after a GFAPI::update_form() call made via wp-cli. Without this,
 * the next HTTP request to the same container may serve cached HTML
 * that still reflects the pre-update form — a known flaky behavior.
 */
export function flushCaches( container: 'wp-cli-client' | 'wp-cli-vendor' ): void {
    wpCli(
        container,
        // Gravity Forms has its own GFCache on TOP of WP's object cache:
        // GFAPI::get_form() reads from gform_get_meta() which caches the
        // display_meta blob. Flushing only wp_cache_flush() isn't enough
        // when the form's field list changes; GFCache::flush() is the
        // right hammer.
        `wp_cache_flush(); `
        + `if ( class_exists( "GFCache" ) ) { GFCache::flush( true ); } `
        + `if ( class_exists( "GFFormsModel" ) && method_exists( "GFFormsModel", "flush_current_forms" ) ) { GFFormsModel::flush_current_forms(); } `
        + `if ( function_exists( "opcache_reset" ) ) { opcache_reset(); } `
        + `echo "flushed";`,
        'flushCaches:' + container,
    );
}

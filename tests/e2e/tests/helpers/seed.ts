/**
 * Pre-seed support users / lifecycle state via wp-cli.
 *
 * Browser-driven flow specs that need to start FROM "a grant already
 * exists" (revoke and extend) shouldn't have to drive the full grant
 * flow first — that'd couple every spec to grant-flow's stability and
 * triple test runtime. Instead, mint the support user via the SDK
 * directly through wp-cli, then the spec just navigates to the form
 * and exercises whatever branch it cares about.
 */

import { wpCli } from '../_helpers';
import { NS } from './grant-form';

/**
 * Wipe every test-managed support user from the WordPress install.
 * On multisite, sweeps both the per-site and the network user tables.
 *
 * Also drains the SecurityChecks lockdown + used_accesskeys transients.
 * Without that, a previous test that exercised the magic-link-failure
 * path (3 bogus identifiers, in_lockdown=true) leaves the site
 * locked-down — every subsequent grant\'s magic link will be refused
 * regardless of correctness.
 */
export function resetSupportUsers(): void {
	wpCli(
		'wp-cli-client',
		[
			'global $wpdb;',
			// Match on the SDK's per-user identifier meta so we catch
			// users regardless of which test created them.
			'$ids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s OR meta_key = %s", "tl_' + NS + '_id", "tl_' + NS + '_site_hash"));',
			'foreach (array_unique(array_map("intval", $ids)) as $uid) {',
			'  if ($uid <= 0) { continue; }',
			'  wp_delete_user($uid);',
			'  if (function_exists("wpmu_delete_user")) { wpmu_delete_user($uid); }',
			'}',
			// And drain any TL-namespaced cron events so a stale schedule
			// from a previous run doesn\'t fire mid-test.
			'$cron = (array) get_option("cron", array());',
			'foreach ($cron as $ts => $hooks) {',
			'  if (!is_array($hooks)) { continue; }',
			'  foreach (array_keys($hooks) as $hook) {',
			'    if (0 === strpos((string) $hook, "trustedlogin/")) { unset($cron[$ts][$hook]); }',
			'  }',
			'  if (empty($cron[$ts])) { unset($cron[$ts]); }',
			'}',
			'update_option("cron", $cron);',
			// SecurityChecks transients: lockdown + brute-force counter.
			// Utils::set_transient stores these as raw options rows (not
			// through WP\'s transient API), so delete_transient() is a
			// no-op here. delete_option targets the actual storage.
			'delete_option("tl-' + NS + '-in_lockdown");',
			'delete_option("tl-' + NS + '-used_accesskeys");',
			'echo "ok";',
		].join( ' ' ),
		'reset support users + cron + security transients',
	);
}

/**
 * Mint a support user by running the SDK\'s create + setup + endpoint
 * chain. Same end state as a successful grant_access — the user has
 * the {ns}-support role, identifier metas wired, cron scheduled, AND
 * the endpoint site_option is populated so the magic-link login path
 * works.
 *
 * Returns the user_id for the seeded user.
 */
export function seedSupportUser(): number {
	const out = wpCli(
		'wp-cli-client',
		[
			'$cfg = new \\TrustedLogin\\Config(array(',
			'  "auth"   => array("api_key" => "0123456789abcdef"),',
			`  "vendor" => array("namespace" => ${ JSON.stringify( NS ) }, "title" => "Pro Block Builder", "email" => "browser-seed@example.test", "website" => "http://localhost:8002", "support_url" => "http://localhost:8002/support"),`,
			'));',
			'$log  = new \\TrustedLogin\\Logging($cfg);',
			'(new \\TrustedLogin\\SupportRole($cfg, $log))->create();',
			'$su   = new \\TrustedLogin\\SupportUser($cfg, $log);',
			'$uid  = $su->create();',
			'if (is_wp_error($uid)) { echo "ERR:" . $uid->get_error_code(); exit; }',
			'$raw  = \\TrustedLogin\\Encryption::get_random_hash($log);',
			'$cron = new \\TrustedLogin\\Cron($cfg, $log);',
			'$su->setup($uid, $raw, time() + DAY_IN_SECONDS, $cron);',
			// Replicate Client::grant_access\'s endpoint hash + update so
			// the rewrite endpoint and the site_option are present —
			// without these, the magic-link flow would 404 / silently
			// no-op even though the support user exists.
			'$endpoint = new \\TrustedLogin\\Endpoint($cfg, $log, null, $su);',
			'$endpoint_hash = $endpoint->get_hash($raw);',
			'if (is_wp_error($endpoint_hash)) { echo "ERR:" . $endpoint_hash->get_error_code(); exit; }',
			'$endpoint->update($endpoint_hash);',
			'flush_rewrite_rules(false);',
			'echo (int) $uid;',
		].join( ' ' ),
		'seed support user',
	);

	if ( /^ERR:/.test( out ) ) {
		throw new Error( `seedSupportUser failed: ${ out }` );
	}
	const id = parseInt( out, 10 );
	if ( ! Number.isFinite( id ) || id <= 0 ) {
		throw new Error( `seedSupportUser returned non-numeric: ${ JSON.stringify( out ) }` );
	}
	return id;
}

/**
 * Set the SSL gate to true via the harness, regardless of is_ssl(). The
 * test stack runs on http://localhost:8002 so meets_ssl_requirement
 * defaults to false; flip it on for the duration of these specs.
 */
export function enableSslOverride(): void {
	wpCli(
		'wp-cli-client',
		`update_option("tl_test_force_ssl_true", "yes"); echo "ok";`,
		'enable SSL override',
	);
}

export function clearSslOverride(): void {
	wpCli(
		'wp-cli-client',
		`delete_option("tl_test_force_ssl_true"); echo "ok";`,
		'clear SSL override',
	);
}

<?php
/**
 * Class Uninstaller
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2026 Katz Web Services, Inc.
 */

namespace TrustedLogin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes everything the SDK stores for one vendor namespace. Entry
 * point for vendors is {@see Client::uninstall()}.
 *
 * Deleted on each site visited: support users carrying `tl_{ns}_id`
 * meta (and their meta and expiry cron events); the cloned support role
 * when it carries the TrustedLogin flag capability; the options
 * `tl_{ns}_webhook_url`, `tl_{ns}_log_salt`, `tl_{ns}_pending_saas_revoke`;
 * the transient-style rows `tl_{ns}_vendor_public_key`,
 * `tl-{ns}-used_accesskeys`, `tl-{ns}-in_lockdown` (expiry is enforced
 * only on read, so unread rows never go away on their own); the
 * `tl_{ns}_reconcile_ran` transient; the
 * `trustedlogin/{ns}/site/retry_revoke` cron event; this namespace's
 * default-named `client-debug-*.log` files.
 *
 * When the Config carries `auth/api_key`, each deleted support user and
 * each queued SaaS revoke is also revoked at TrustedLogin before its rows
 * go. A failed request is not retried; the rows are deleted regardless.
 *
 * Deleted once, as a site option (network-wide on multisite): the
 * `tl_{ns}_endpoint` login endpoint hash, unless support users remain on
 * sites the run did not visit.
 *
 * Never touched: a stock role named in Config, `tl_permalinks_flushed`
 * (shared by every namespace), the `trustedlogin-logs/` directory and
 * its `index.html`, and rows belonging to any other namespace.
 *
 * Logging is silenced while the run executes so no log file or salt is
 * written back during the delete.
 *
 * @since 1.11.0
 */
final class Uninstaller {

	/**
	 * Config for the namespace being removed.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Logging instance handed to the SDK objects the cleanup reuses.
	 *
	 * @var Logging
	 */
	private $logging;

	/**
	 * Sanitized namespace.
	 *
	 * @var string
	 */
	private $ns;

	/**
	 * Run options. See {@see Client::uninstall()} for the keys.
	 *
	 * @var array{network: bool|null, delete_logs: bool}
	 */
	private $args;

	/**
	 * Report of what the run deleted. See {@see run()} for the shape.
	 *
	 * @var array
	 */
	private $report;

	/**
	 * Uninstaller constructor.
	 *
	 * @param Config $config Config for the namespace being removed.
	 * @param array  $args   Run options. See {@see Client::uninstall()}.
	 */
	public function __construct( Config $config, array $args = array() ) {
		$this->config  = $config;
		$this->logging = new Logging( $config );
		$this->ns      = $config->ns();
		$this->args    = wp_parse_args(
			$args,
			array(
				'network'     => null,
				'delete_logs' => true,
			)
		);
	}

	/**
	 * Builds an Uninstaller from either a Config or a bare namespace string.
	 *
	 * A namespace string is enough for the default setup (cloned role,
	 * unfiltered option names). Pass the same Config the plugin boots
	 * the Client with when it sets `clone_role`, `role`,
	 * `logging/directory`, or `auth/api_key` (needed to revoke access at
	 * TrustedLogin). `uninstall.php` runs without the plugin's main file,
	 * so filters the plugin adds on `trustedlogin/{ns}/support_role`,
	 * `trustedlogin/{ns}/options/endpoint` or
	 * `trustedlogin/{ns}/options/vendor_public_key` must be added again in
	 * `uninstall.php` before the call.
	 *
	 * @param Config|string $config_or_namespace Config instance, or the `vendor/namespace` value.
	 * @param array         $args                Run options. See {@see Client::uninstall()}.
	 *
	 * @return Uninstaller
	 *
	 * @throws \Exception When the namespace is empty.
	 */
	public static function from( $config_or_namespace, array $args = array() ) {

		if ( $config_or_namespace instanceof Config ) {
			return new self( $config_or_namespace, $args );
		}

		$namespace = is_string( $config_or_namespace ) ? trim( $config_or_namespace ) : '';

		if ( '' === $namespace ) {
			throw new \Exception( 'Developer: TrustedLogin uninstall needs a Config instance or a non-empty namespace string.', 400 );
		}

		$config = new Config( array( 'vendor' => array( 'namespace' => $namespace ) ) );

		return new self( $config, $args );
	}

	/**
	 * Deletes everything stored for the namespace. Safe to run when
	 * nothing exists and safe to run more than once.
	 *
	 * @return array{
	 *   support_users: int,
	 *   role: bool,
	 *   endpoint: bool,
	 *   options: string[],
	 *   cron_events: int,
	 *   log_files: int,
	 *   sites: int,
	 *   network_skipped: bool,
	 *   saas_revokes: int
	 * } What was deleted. `options` lists the option rows removed on any
	 *   site. `sites` is how many sites were visited. `network_skipped`
	 *   is true when a large network (see wp_is_large_network()) limited
	 *   the run to the current site. `saas_revokes` counts the revoke
	 *   requests that did not fail.
	 *
	 * @throws \Exception When the namespace is empty, or when a site-level step throws.
	 * @throws \Error Re-thrown from a site-level step.
	 */
	public function run() {

		if ( '' === $this->ns ) {
			throw new \Exception( 'Developer: TrustedLogin uninstall needs a non-empty namespace.', 400 );
		}

		$this->report = array(
			'support_users'   => 0,
			'role'            => false,
			'endpoint'        => false,
			'options'         => array(),
			'cron_events'     => 0,
			'log_files'       => 0,
			'sites'           => 0,
			'network_skipped' => false,
			'saas_revokes'    => 0,
		);

		$silence = 'trustedlogin/' . $this->ns . '/logging/enabled';
		add_filter( $silence, '__return_false', PHP_INT_MAX );

		try {
			$endpoint       = new Endpoint( $this->config, $this->logging );
			$endpoint_value = $endpoint->get();

			foreach ( $this->site_ids() as $site_id ) {
				$this->clean_site( (int) $site_id, $endpoint_value );
				++$this->report['sites'];
			}

			$this->report['support_users'] += $this->delete_unclaimed_support_users();

			// The endpoint is one network-wide option; support users left
			// on sites this run did not visit still log in through it.
			$endpoint_in_use = array() !== $this->support_user_ids( true );

			if ( '' !== $endpoint_value && ! $endpoint_in_use ) {
				// The support-user delete path may have removed the site
				// option already; delete() is a no-op then.
				$endpoint->delete();
				$this->report['endpoint'] = '' === $endpoint->get();
			}
		} catch ( \Exception $exception ) {
			remove_filter( $silence, '__return_false', PHP_INT_MAX );
			throw $exception;
		} catch ( \Error $error ) {
			remove_filter( $silence, '__return_false', PHP_INT_MAX );
			throw $error;
		}

		remove_filter( $silence, '__return_false', PHP_INT_MAX );

		$this->report['options'] = array_values( array_unique( $this->report['options'] ) );

		return $this->report;
	}

	/**
	 * Sites to visit. The plugin is removed from the whole network, so
	 * every site is cleaned unless `network` is false or the network is
	 * large enough that a full sweep could time out. Support users on
	 * sites not visited are left in place.
	 *
	 * @return int[]
	 */
	private function site_ids() {

		$current = get_current_blog_id();

		if ( ! is_multisite() || false === $this->args['network'] || ! function_exists( 'get_sites' ) ) {
			return array( $current );
		}

		if ( null === $this->args['network'] && wp_is_large_network() ) {
			$this->report['network_skipped'] = true;

			return array( $current );
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		if ( ! in_array( $current, $site_ids, true ) ) {
			array_unshift( $site_ids, $current );
		}

		return $site_ids;
	}

	/**
	 * Cleans one site's rows, switching to it when it isn't the current one.
	 *
	 * @param int    $site_id        Site to clean.
	 * @param string $endpoint_value Endpoint hash captured before any deletion.
	 *
	 * @throws \Exception Re-thrown from any step after the site switch is undone.
	 * @throws \Error Re-thrown from any step after the site switch is undone.
	 */
	private function clean_site( $site_id, $endpoint_value ) {

		$switched = is_multisite() && get_current_blog_id() !== $site_id;

		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			$this->delete_support_users();
			$this->revoke_pending_saas_revokes();
			$this->delete_role();
			$this->clear_cron_events();
			$this->flush_stale_rewrite_rules( $endpoint_value );

			// The salt is read while deleting log files, so files go first.
			if ( $this->args['delete_logs'] ) {
				$this->report['log_files'] += $this->delete_log_files();
			}

			$this->delete_options();
		} catch ( \Exception $exception ) {
			if ( $switched ) {
				restore_current_blog();
			}
			throw $exception;
		} catch ( \Error $error ) {
			if ( $switched ) {
				restore_current_blog();
			}
			throw $error;
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Support users for this namespace, by the identifier meta every
	 * grant writes.
	 *
	 * @param bool $network_wide On multisite, ignore site membership and return every matching user on the network.
	 *
	 * @return int[]
	 */
	private function support_user_ids( $network_wide = false ) {

		$support_user = new SupportUser( $this->config, $this->logging );

		$args = array(
			'meta_key'     => $support_user->user_identifier_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- @phpstan-ignore-line
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'number'       => -1,
		);

		if ( $network_wide && is_multisite() ) {
			$args['blog_id'] = 0;
		}

		return array_map( 'intval', (array) get_users( $args ) );
	}

	/**
	 * Deletes the support users that belong to the current site through
	 * the same path a revoke uses, so posts are reassigned and multisite
	 * rows are removed the same way.
	 */
	private function delete_support_users() {

		$support_user = new SupportUser( $this->config, $this->logging );

		foreach ( $this->support_user_ids() as $user_id ) {
			$identifier = get_user_option( $support_user->user_identifier_meta_key, $user_id ); // @phpstan-ignore-line

			if ( ! is_string( $identifier ) || '' === $identifier ) {
				continue;
			}

			$secret_id = $support_user->get_secret_id( $identifier );

			if ( is_string( $secret_id ) && '' !== $secret_id ) {
				$this->revoke_at_saas( $secret_id );
			}

			// Role and endpoint are handled separately by the run.
			$deleted = $support_user->delete( $identifier, false, false );

			if ( true === $deleted ) {
				++$this->report['support_users'];
			}
		}
	}

	/**
	 * Multisite: a support user removed from every site but never deleted
	 * from the network still carries the identifier meta. Delete those too.
	 * A user who is still a member of any site is left to that site's
	 * cleanup, which reassigns their posts; {@see wpmu_delete_user()}
	 * deletes a member's posts on every site without reassigning them.
	 *
	 * @return int Users deleted.
	 */
	private function delete_unclaimed_support_users() {

		if ( ! is_multisite() ) {
			return 0;
		}

		$user_ids = $this->support_user_ids( true );

		if ( empty( $user_ids ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		$deleted = 0;

		foreach ( $user_ids as $user_id ) {
			$sites = get_blogs_of_user( $user_id );

			if ( ! empty( $sites ) ) {
				continue;
			}

			if ( function_exists( 'wpmu_delete_user' ) && wpmu_delete_user( $user_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Sends the revokes queued after a failed request, before the queue
	 * option is deleted.
	 */
	private function revoke_pending_saas_revokes() {

		$queue = get_option( 'tl_' . $this->ns . '_pending_saas_revoke', array() );

		if ( ! is_array( $queue ) ) {
			return;
		}

		foreach ( array_keys( $queue ) as $secret_id ) {
			$this->revoke_at_saas( (string) $secret_id );
		}
	}

	/**
	 * Tells TrustedLogin a site secret is revoked. Needs `auth/api_key`.
	 *
	 * @param string $secret_id Site secret identifier.
	 */
	private function revoke_at_saas( $secret_id ) {

		if ( '' === $secret_id || ! $this->config->get_setting( 'auth/api_key' ) ) {
			return;
		}

		$site_access = new SiteAccess( $this->config, $this->logging );
		$revoked     = $site_access->revoke( $secret_id, new Remote( $this->config, $this->logging ) );

		if ( true === $revoked ) {
			++$this->report['saas_revokes'];
		}
	}

	/**
	 * Removes the cloned support role on the current site.
	 * {@see SupportRole::delete()} refuses stock and protected roles and
	 * any role without the TrustedLogin flag capability.
	 */
	private function delete_role() {

		$role = new SupportRole( $this->config, $this->logging );

		if ( true === $role->delete() ) {
			$this->report['role'] = true;
		}
	}

	/**
	 * Clears every event on the namespace's cron hooks, whatever their args.
	 */
	private function clear_cron_events() {

		$hooks = array(
			'trustedlogin/' . $this->ns . '/access/revoke',
			'trustedlogin/' . $this->ns . '/site/retry_revoke',
		);

		foreach ( $hooks as $hook ) {
			$this->report['cron_events'] += $this->unschedule_hook( $hook );
		}
	}

	/**
	 * Clears all events for one hook. Falls back to walking the cron
	 * array on WordPress versions without wp_unschedule_hook().
	 *
	 * @param string $hook Cron hook name.
	 *
	 * @return int Events cleared.
	 */
	private function unschedule_hook( $hook ) {

		if ( function_exists( 'wp_unschedule_hook' ) ) {
			$cleared = wp_unschedule_hook( $hook );

			return is_int( $cleared ) ? $cleared : 0;
		}

		$cleared = 0;

		foreach ( (array) _get_cron_array() as $timestamp => $cron ) {
			if ( empty( $cron[ $hook ] ) ) {
				continue;
			}

			foreach ( $cron[ $hook ] as $event ) {
				$args = isset( $event['args'] ) ? $event['args'] : array();

				if ( false !== wp_unschedule_event( $timestamp, $hook, $args ) ) {
					++$cleared;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Flushes this site's rewrite rules only when they still carry the
	 * login endpoint. The endpoint rule is added on the site that
	 * handled a login, which is not always the site running the uninstall.
	 *
	 * @param string $endpoint_value Endpoint hash captured before any deletion.
	 */
	private function flush_stale_rewrite_rules( $endpoint_value ) {

		if ( '' === $endpoint_value ) {
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return;
		}

		$has_endpoint_rule = false !== strpos( implode( "\n", array_keys( $rules ) ), $endpoint_value );

		if ( ! $has_endpoint_rule ) {
			return;
		}

		// After switch_to_blog() the global rewrite object still describes
		// the original site, so a flush would write its rules here. Deleting
		// the option makes WordPress rebuild them on this site's next request.
		if ( ms_is_switched() ) {
			delete_option( 'rewrite_rules' );

			return;
		}

		flush_rewrite_rules( false );
	}

	/**
	 * Option rows to delete on the current site. The vendor public key
	 * name goes through the same filter Encryption applies.
	 *
	 * @return string[]
	 */
	private function option_names() {

		$vendor_public_key_option = apply_filters(
			'trustedlogin/' . $this->ns . '/options/vendor_public_key',
			'tl_' . $this->ns . '_vendor_public_key',
			$this->config
		);

		return array(
			sprintf( Config::WEBHOOK_URL_OPTION_KEY_TEMPLATE, $this->ns ),
			'tl_' . $this->ns . '_log_salt',
			'tl_' . $this->ns . '_pending_saas_revoke',
			(string) $vendor_public_key_option,
			'tl-' . $this->ns . '-used_accesskeys',
			'tl-' . $this->ns . '-in_lockdown',
			sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, $this->ns ),
		);
	}

	/**
	 * Deletes the namespace's option rows on the current site.
	 * delete_option() reads the table directly, so rows written by
	 * {@see Utils::set_transient()} (which bypasses the options cache)
	 * are found too.
	 */
	private function delete_options() {

		foreach ( $this->option_names() as $option ) {
			if ( delete_option( $option ) ) {
				$this->report['options'][] = $option;
			}
		}
	}

	/**
	 * Deletes this namespace's default-named log files in the log
	 * directory. The filename hash is derived from the stored salt, so
	 * this runs before the salt option is deleted. A file name changed
	 * through `logging/options` is not matched.
	 *
	 * @return int Files deleted.
	 */
	private function delete_log_files() {

		$directory = (string) $this->config->get_setting( 'logging/directory', '' );

		if ( '' === $directory ) {
			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
				return 0;
			}

			$directory = trailingslashit( $upload_dir['basedir'] ) . Logging::DIRECTORY_PATH;
		}

		$directory = trailingslashit( $directory );

		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		// Mirrors Logging::setup_klogger(): with a salt, the hash covers
		// namespace + home URL + salt; without one, namespace + home URL.
		$hash_inputs = array( $this->ns . home_url( '/' ) );
		$salt        = get_option( 'tl_' . $this->ns . '_log_salt', '' );

		if ( is_string( $salt ) && '' !== $salt ) {
			$hash_inputs[] = $this->ns . home_url( '/' ) . $salt;
		}

		$deleted = 0;

		foreach ( $hash_inputs as $hash_input ) {
			$pattern = $directory . 'client-debug-*-' . hash( 'sha256', $hash_input ) . '.log';
			$files   = glob( $pattern );

			foreach ( (array) $files as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}

				wp_delete_file( $file );

				if ( ! file_exists( $file ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}
}

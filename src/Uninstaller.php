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
 * Deletes everything the SDK stores for one vendor namespace on the sites
 * it visits: support users, the cloned role, the login endpoint, cron events,
 * the namespace's options and its default-named log files. Stock roles, the
 * shared `tl_permalinks_flushed` flag and other namespaces' rows are left.
 * Entry point: {@see Client::uninstall()}.
 *
 * @since TBD
 */
final class Uninstaller {

	/**
	 * Seconds each TrustedLogin revoke request may take.
	 *
	 * @since TBD
	 */
	const SAAS_REVOKE_TIMEOUT = 3;

	/**
	 * Seconds the run may spend on TrustedLogin revoke requests in total.
	 * Revokes left once it is spent are reported, not sent.
	 *
	 * @since TBD
	 */
	const SAAS_REVOKE_BUDGET = 20;

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
	 * When the run's first TrustedLogin revoke request started, or 0.
	 *
	 * @var float
	 */
	private $saas_revokes_started = 0.0;

	/**
	 * Whether a TrustedLogin revoke request failed during this run. Later
	 * revokes are reported, not sent.
	 *
	 * @var bool
	 */
	private $saas_revoke_failed = false;

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
	 * Builds an Uninstaller from a Config or a bare namespace string. See
	 * {@see Client::uninstall()} for when a namespace is not enough.
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
	 *   saas_revokes: int,
	 *   saas_revokes_failed: string[]
	 * } What was deleted. `options` lists option rows removed on any site;
	 *   `network_skipped` is true when a large network limited the run to the
	 *   current site; `saas_revokes` counts revokes TrustedLogin confirmed;
	 *   `saas_revokes_failed` lists the secret IDs not revoked at TrustedLogin,
	 *   because a request failed, the SSL requirement was not met, or an
	 *   earlier failure or the time limit stopped further requests.
	 *
	 * @throws \Exception When the namespace is empty, or when a site-level step throws.
	 * @throws \Error Re-thrown from a site-level step.
	 */
	public function run() {

		if ( '' === $this->ns ) {
			throw new \Exception( 'Developer: TrustedLogin uninstall needs a non-empty namespace.', 400 );
		}

		$this->report = array(
			'support_users'       => 0,
			'role'                => false,
			'endpoint'            => false,
			'options'             => array(),
			'cron_events'         => 0,
			'log_files'           => 0,
			'sites'               => 0,
			'network_skipped'     => false,
			'saas_revokes'        => 0,
			'saas_revokes_failed' => array(),
		);

		$this->saas_revokes_started = 0.0;
		$this->saas_revoke_failed   = false;

		// Logging would write a salt and log file back while they are deleted.
		$silence = 'trustedlogin/' . $this->ns . '/logging/enabled';
		add_filter( $silence, '__return_false', PHP_INT_MAX );

		try {
			$endpoint       = new Endpoint( $this->config, $this->logging );
			$endpoint_value = $endpoint->get();
			$site_ids       = $this->site_ids();

			// When every site is visited, every support user goes, so the
			// endpoint can go first and a run cut short still removes it.
			$visits_every_site = $this->visits_every_site();
			$endpoint_deleted  = false;

			if ( '' !== $endpoint_value && $visits_every_site ) {
				$endpoint->delete();
				$endpoint_deleted         = '' === $endpoint->get();
				$this->report['endpoint'] = $endpoint_deleted;
			}

			foreach ( $site_ids as $site_id ) {
				$this->clean_site( (int) $site_id, $endpoint_value );
				++$this->report['sites'];
			}

			$this->report['support_users'] += $this->delete_unclaimed_support_users();

			// The endpoint is one network-wide option; support users left
			// on sites this run did not visit still log in through it.
			$endpoint_in_use = array() !== $this->support_user_ids( true );

			if ( '' !== $endpoint_value && ! $endpoint_in_use && ! $endpoint_deleted ) {
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

		$this->report['options']             = array_values( array_unique( $this->report['options'] ) );
		$this->report['saas_revokes_failed'] = array_values( array_unique( $this->report['saas_revokes_failed'] ) );

		return $this->report;
	}

	/**
	 * Sites to visit: every site, unless `network` is false or the network is
	 * large enough that a full sweep could time out.
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
	 * Whether the run visits every site: always on single-site, and on
	 * multisite unless `network` is false or a large network limited the
	 * run. Call after {@see site_ids()}.
	 *
	 * @since TBD
	 *
	 * @return bool
	 */
	private function visits_every_site() {

		if ( ! is_multisite() ) {
			return true;
		}

		return false !== $this->args['network'] && ! $this->report['network_skipped'];
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
	 * Deletes this site's support users through {@see SupportUser::delete()},
	 * which reassigns their posts.
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
	 * Deletes support users who belong to no site, counting archived, spam
	 * and deleted sites. A member of any site is left to that site's cleanup:
	 * {@see wpmu_delete_user()} deletes a member's posts without reassigning
	 * them.
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
			$sites = get_blogs_of_user( $user_id, true );

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
	 * Each request gets {@see SAAS_REVOKE_TIMEOUT} seconds. After a failure,
	 * or once {@see SAAS_REVOKE_BUDGET} seconds are spent, no more requests
	 * are sent. Secret IDs not revoked are added to `saas_revokes_failed`.
	 *
	 * @since TBD
	 *
	 * @param string $secret_id Site secret identifier.
	 */
	private function revoke_at_saas( $secret_id ) {

		if ( '' === $secret_id || ! $this->config->get_setting( 'auth/api_key' ) ) {
			return;
		}

		if ( ! $this->config->meets_ssl_requirement() ) {
			$this->report['saas_revokes_failed'][] = $secret_id;

			return;
		}

		if ( 0.0 === $this->saas_revokes_started ) {
			$this->saas_revokes_started = microtime( true );
		}

		$budget_spent = microtime( true ) - $this->saas_revokes_started >= self::SAAS_REVOKE_BUDGET;

		if ( $this->saas_revoke_failed || $budget_spent ) {
			$this->report['saas_revokes_failed'][] = $secret_id;

			return;
		}

		$site_access = new SiteAccess( $this->config, $this->logging );
		$revoked     = $site_access->revoke( $secret_id, new Remote( $this->config, $this->logging ), self::SAAS_REVOKE_TIMEOUT );

		if ( true === $revoked ) {
			++$this->report['saas_revokes'];

			return;
		}

		$this->saas_revoke_failed              = true;
		$this->report['saas_revokes_failed'][] = $secret_id;
	}

	/**
	 * Removes the cloned support role on the current site.
	 * {@see SupportRole::delete()} refuses stock, protected and unflagged roles.
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
	 * Clears this site's rewrite rules when they still carry the login
	 * endpoint, which is added on whichever site handled a login.
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
		// ms_is_switched() is only loaded on multisite.
		$is_switched = is_multisite() && ms_is_switched();

		if ( $is_switched ) {
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
			sprintf( Cron::RECONCILE_FAILURES_TRANSIENT, $this->ns ),
		);
	}

	/**
	 * Deletes the namespace's option rows on the current site, including rows
	 * {@see Utils::set_transient()} writes outside the options cache.
	 */
	private function delete_options() {

		foreach ( $this->option_names() as $option ) {
			if ( delete_option( $option ) ) {
				$this->report['options'][] = $option;
			}
		}
	}

	/**
	 * Deletes this namespace's default-named log files. Runs before the salt
	 * option is deleted, because the file name hash is derived from it.
	 *
	 * @return int Files deleted.
	 */
	private function delete_log_files() {

		$directory = (string) $this->config->get_setting( 'logging/directory', '' );

		if ( '' === $directory ) {
			$upload_dir = wp_upload_dir( null, false );

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

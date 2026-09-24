<?php
/**
 * Integration tests for the expired-support-user sweep.
 *
 * A grant schedules one cron event to revoke access. If that event fires
 * while the plugin is inactive, WordPress consumes it with no listener
 * and nothing removes the support user. `maybe_login()` performs the same
 * check, but an abandoned grant is never logged into, so the sweep is the
 * only thing that reaches it.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class SupportUserReconcileTest extends WP_UnitTestCase {

	const NS = 'reconcile-vendor';
	const NS_OTHER = 'reconcile-other-vendor';

	/**
	 * Builds a SupportUser bound to the given namespace.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return SupportUser
	 */
	private function support_user_for( $ns ) {
		$config = new Config(
			array(
				'role'   => 'editor',
				'auth'   => array(
					'api_key' => '0123456789abcdef',
				),
				'vendor' => array(
					'namespace'   => $ns,
					'title'       => $ns,
					'email'       => 'support+' . $ns . '@example.test',
					'website'     => 'https://' . $ns . '.example.test',
					'support_url' => 'https://' . $ns . '.example.test/support/',
				),
			)
		);

		return new SupportUser( $config, new Logging( $config ) );
	}

	/**
	 * Runs the expired-access sweep for a namespace on the current site.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return int Support users of the namespace that left the current site.
	 */
	private function sweep( $ns ) {
		$before = count( $this->support_user_for( $ns )->get_all() );
		$config = $this->config_for( $ns );

		( new Cron( $config, new Logging( $config ) ) )->reconcile();

		return $before - count( $this->support_user_for( $ns )->get_all() );
	}

	/**
	 * Creates a support user for a namespace.
	 *
	 * @param string   $ns         Namespace.
	 * @param int|null $expires    Expiration timestamp, or null to write none.
	 * @param string   $registered Value for user_registered.
	 *
	 * @return int User ID.
	 */
	private function seed_support_user( $ns, $expires, $registered = '2020-01-01 00:00:00' ) {
		$user_id = self::factory()->user->create(
			array(
				'role'            => 'editor',
				'user_registered' => $registered,
			)
		);

		update_user_option( $user_id, 'tl_' . $ns . '_id', md5( wp_generate_uuid4() ), true );
		update_user_option( $user_id, 'tl_' . $ns . '_site_hash', str_repeat( 'b', 32 ), true );

		if ( ! is_null( $expires ) ) {
			update_user_option( $user_id, 'tl_' . $ns . '_expires', $expires );
		}

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'fixture: the seeded user must exist' );
		$this->assertContains(
			$user_id,
			wp_list_pluck( $this->support_user_for( $ns )->get_all(), 'ID' ),
			'fixture: the seeded user must be visible to get_all() for its namespace'
		);

		return $user_id;
	}

	/**
	 * The case the sweep exists for: expiry passed, cron consumed, user left.
	 */
	public function test_sweep_deletes_a_support_user_whose_expiry_has_passed() {
		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		$deleted = $this->sweep( self::NS );

		$this->assertSame( 1, $deleted );
		$this->assertFalse( get_user_by( 'id', $user_id ), 'an expired support user must not survive the sweep' );
	}

	/**
	 * Access that has not expired must be left alone.
	 */
	public function test_sweep_keeps_a_support_user_whose_access_is_still_valid() {
		$user_id = $this->seed_support_user( self::NS, time() + WEEK_IN_SECONDS );

		$deleted = $this->sweep( self::NS );

		$this->assertSame( 0, $deleted );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'active access must survive the sweep' );
	}

	/**
	 * setup() only writes the expiration when cron scheduling succeeded, so a
	 * user with no expiration would otherwise never be removed. maybe_login()
	 * already treats that state as expired.
	 */
	public function test_sweep_deletes_a_support_user_that_has_no_expiration() {
		$user_id = $this->seed_support_user( self::NS, null );

		$deleted = $this->sweep( self::NS );

		$this->assertSame( 1, $deleted );
		$this->assertFalse( get_user_by( 'id', $user_id ) );
	}

	/**
	 * A grant whose cron event could not be scheduled has no expiration.
	 * While the request that created it may still be syncing with
	 * TrustedLogin, the sweep must leave it.
	 */
	public function test_sweep_keeps_a_just_created_user_that_has_no_expiration_yet() {
		$user_id = $this->seed_support_user( self::NS, null, gmdate( 'Y-m-d H:i:s' ) );

		$deleted = $this->sweep( self::NS );

		$this->assertSame( 0, $deleted );
		$this->assertInstanceOf(
			\WP_User::class,
			get_user_by( 'id', $user_id ),
			'a support user created moments ago may still be mid-grant; the sweep must leave it'
		);
	}

	/**
	 * The sweep must only touch its own namespace.
	 */
	public function test_sweep_leaves_another_namespaces_expired_user_alone() {
		$mine   = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$theirs = $this->seed_support_user( self::NS_OTHER, time() - HOUR_IN_SECONDS );

		$deleted = $this->sweep( self::NS );

		$this->assertSame( 1, $deleted );
		$this->assertFalse( get_user_by( 'id', $mine ) );
		$this->assertInstanceOf(
			\WP_User::class,
			get_user_by( 'id', $theirs ),
			'another vendor\'s expired support user is not this namespace\'s to delete'
		);
	}

	/**
	 * The sweep is only reached if it is wired to something that runs.
	 */
	public function test_init_registers_the_sweep_on_the_core_cron_hook() {
		$this->assertFalse( $this->sweep_is_hooked( Cron::RECONCILE_HOOK ), 'fixture: no callback before init() runs' );

		$this->client_for( self::NS )->init();

		$this->assertTrue( $this->sweep_is_hooked( Cron::RECONCILE_HOOK ), 'init() must register the sweep on the core hook' );
	}

	/**
	 * The sweep rides a core event, so the SDK must schedule nothing of its
	 * own to keep, reschedule, or clear on uninstall.
	 */
	public function test_init_schedules_no_cron_event_of_its_own() {
		$this->client_for( self::NS )->init();

		$own = array();

		foreach ( array_keys( (array) _get_cron_array() ) as $timestamp ) {
			foreach ( array_keys( (array) _get_cron_array()[ $timestamp ] ) as $hook ) {
				if ( 0 === strpos( $hook, 'trustedlogin/' ) ) {
					$own[] = $hook;
				}
			}
		}

		$this->assertSame( array(), $own, 'init() must not schedule a TrustedLogin cron event' );
	}

	/**
	 * Firing the core hook must actually remove an expired support user.
	 */
	public function test_firing_the_core_hook_deletes_an_expired_support_user() {
		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		$this->client_for( self::NS )->init();

		do_action( Cron::RECONCILE_HOOK );

		$this->assertFalse( get_user_by( 'id', $user_id ), 'the core hook must drive the sweep end to end' );
	}

	/**
	 * The sweep depends on core still scheduling this event. If a future
	 * release drops or renames it, nothing would run and nothing would say so.
	 */
	public function test_core_still_schedules_the_hook_the_sweep_depends_on() {
		wp_unschedule_hook( Cron::RECONCILE_HOOK );

		$this->assertFalse( wp_next_scheduled( Cron::RECONCILE_HOOK ), 'fixture: the event must be gone before init fires' );

		do_action( 'init' );

		$this->assertIsInt(
			wp_next_scheduled( Cron::RECONCILE_HOOK ),
			'WordPress must still schedule ' . Cron::RECONCILE_HOOK . '; the sweep has no event of its own'
		);
	}

	/**
	 * Builds a Client for a namespace without booting its hooks.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return Client
	 */
	private function client_for( $ns ) {
		return new Client(
			new Config(
				array(
					'role'   => 'editor',
					'auth'   => array(
						'api_key' => '0123456789abcdef',
					),
					'vendor' => array(
						'namespace'   => $ns,
						'title'       => $ns,
						'email'       => 'support+' . $ns . '@example.test',
						'website'     => 'https://' . $ns . '.example.test',
						'support_url' => 'https://' . $ns . '.example.test/support/',
					),
				)
			),
			false
		);
	}

	/**
	 * Whether any callback on $hook is the Cron sweep.
	 *
	 * @param string $hook Hook name.
	 *
	 * @return bool
	 */
	private function sweep_is_hooked( $hook ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return false;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] )
					&& $callback['function'][0] instanceof Cron
					&& 'reconcile' === $callback['function'][1] ) {
					return true;
				}
			}
		}

		return false;
	}

	// ---------------------------------------------------------------
	// Revoking through the Client
	// ---------------------------------------------------------------

	/**
	 * Seeds an expired support user carrying the site hash revoke_access()
	 * needs to build the secret ID.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return int User ID.
	 */
	private function seed_revocable_user( $ns ) {
		$user_id = $this->seed_support_user( $ns, time() - HOUR_IN_SECONDS );

		update_user_option( $user_id, 'tl_' . $ns . '_site_hash', str_repeat( 'b', 32 ), true );

		return $user_id;
	}

	/**
	 * Records requests to the TrustedLogin sites endpoint and answers them.
	 *
	 * @param array|\WP_Error $answer Response to return.
	 *
	 * @return \ArrayObject Recorded "METHOD url" strings.
	 */
	private function record_saas_requests( $answer ) {
		$requests = new \ArrayObject();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $requests, $answer ) {
				if ( false === strpos( $url, '/sites' ) ) {
					return $preempt;
				}

				$requests[] = $args['method'] . ' ' . $url;

				return $answer;
			},
			10,
			3
		);

		add_filter( 'trustedlogin/' . self::NS . '/meets_ssl_requirement', '__return_true' );

		return $requests;
	}

	/**
	 * The expiry event revokes at TrustedLogin and fires `access/revoked`;
	 * the sweep that backstops it must do the same.
	 */
	public function test_core_hook_revokes_expired_access_at_trustedlogin_and_fires_revoked() {
		$user_id  = $this->seed_revocable_user( self::NS );
		$requests = $this->record_saas_requests(
			array(
				'response' => array( 'code' => 204, 'message' => 'No Content' ),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
			)
		);
		$revoked  = 0;
		add_action(
			'trustedlogin/' . self::NS . '/access/revoked',
			function () use ( &$revoked ) {
				++$revoked;
			}
		);

		$this->client_for( self::NS )->init();

		do_action( Cron::RECONCILE_HOOK );

		$this->assertFalse( get_user_by( 'id', $user_id ) );
		$this->assertCount( 1, $requests, 'the sweep must tell TrustedLogin the access is revoked' );
		$this->assertStringStartsWith( 'DELETE ', $requests[0] );
		$this->assertSame( 1, $revoked, 'the sweep must fire access/revoked so the webhook is sent' );
	}

	/**
	 * A failed TrustedLogin request queues the retry, as a normal revoke does.
	 */
	public function test_core_hook_queues_a_retry_when_trustedlogin_is_unreachable() {
		$user_id = $this->seed_revocable_user( self::NS );
		$this->record_saas_requests( new \WP_Error( 'http_request_failed', 'offline' ) );

		$this->client_for( self::NS )->init();

		do_action( Cron::RECONCILE_HOOK );

		$this->assertFalse( get_user_by( 'id', $user_id ), 'local cleanup continues when TrustedLogin is unreachable' );
		$this->assertNotEmpty( get_option( 'tl_' . self::NS . '_pending_saas_revoke' ), 'the revoke must be queued for retry' );

		delete_option( 'tl_' . self::NS . '_pending_saas_revoke' );
		wp_unschedule_hook( 'trustedlogin/' . self::NS . '/site/retry_revoke' );
	}

	/**
	 * The sweep runs on a core hook at priority 1. An error from a
	 * third-party delete hook must not stop core's own callback.
	 */
	public function test_core_hook_callbacks_still_run_when_a_delete_hook_throws() {
		$this->seed_revocable_user( self::NS );
		$this->record_saas_requests( new \WP_Error( 'http_request_failed', 'offline' ) );

		$thrower = function () {
			throw new \Error( 'third-party delete hook failed' );
		};
		add_action( 'delete_user', $thrower );

		$later = 0;
		add_action(
			Cron::RECONCILE_HOOK,
			function () use ( &$later ) {
				++$later;
			},
			10
		);

		$this->client_for( self::NS )->init();

		do_action( Cron::RECONCILE_HOOK );

		remove_action( 'delete_user', $thrower );
		delete_option( 'tl_' . self::NS . '_pending_saas_revoke' );
		wp_unschedule_hook( 'trustedlogin/' . self::NS . '/site/retry_revoke' );

		$this->assertSame( 1, $later, 'callbacks after the sweep must still run' );
	}

	// ---------------------------------------------------------------
	// Fallback when core's event is not scheduled
	// ---------------------------------------------------------------

	public function test_admin_page_load_runs_the_sweep_once_an_hour_when_core_event_is_missing() {
		$this->log_in_administrator();
		$cron = new Cron( $this->config_for( self::NS ), new Logging( $this->config_for( self::NS ) ) );
		wp_unschedule_hook( Cron::RECONCILE_HOOK );
		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );

		$first = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		$this->assertFalse( get_user_by( 'id', $first ), 'with no core event, an admin page load must run the sweep' );

		$second = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $second ), 'the fallback runs at most once an hour' );

		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );
	}

	public function test_admin_page_load_does_nothing_while_core_event_is_scheduled() {
		$this->log_in_administrator();
		$cron = new Cron( $this->config_for( self::NS ), new Logging( $this->config_for( self::NS ) ) );

		if ( ! wp_next_scheduled( Cron::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Cron::RECONCILE_HOOK );
		}

		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'the core event runs the sweep; admin pages must not' );
	}


	/**
	 * Logs in a site administrator, who can manage options.
	 *
	 * @return int User ID.
	 */
	private function log_in_administrator() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	/**
	 * Core's event can stay scheduled and never run, as on a site with
	 * DISABLE_WP_CRON and no system cron. An event more than an hour
	 * overdue counts as not running.
	 */
	public function test_admin_page_load_runs_the_sweep_when_core_event_is_overdue() {
		$this->log_in_administrator();
		$cron = new Cron( $this->config_for( self::NS ), new Logging( $this->config_for( self::NS ) ) );
		wp_unschedule_hook( Cron::RECONCILE_HOOK );
		wp_schedule_event( time() - 2 * HOUR_IN_SECONDS, 'hourly', Cron::RECONCILE_HOOK );
		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );

		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		$this->assertFalse( get_user_by( 'id', $user_id ), 'an overdue core event must not block the admin fallback' );

		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );
		wp_unschedule_hook( Cron::RECONCILE_HOOK );
	}

	public function test_admin_page_load_skips_the_sweep_for_a_visitor_who_is_not_logged_in() {
		wp_set_current_user( 0 );
		$cron = new Cron( $this->config_for( self::NS ), new Logging( $this->config_for( self::NS ) ) );
		wp_unschedule_hook( Cron::RECONCILE_HOOK );
		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );

		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'an anonymous admin-post or admin-ajax request must not run the sweep' );
		$this->assertFalse( Utils::get_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) ), 'a skipped request must not use up the hourly run' );
	}

	public function test_admin_page_load_skips_the_sweep_for_a_user_who_cannot_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$cron = new Cron( $this->config_for( self::NS ), new Logging( $this->config_for( self::NS ) ) );
		wp_unschedule_hook( Cron::RECONCILE_HOOK );
		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );

		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ) );
	}

	public function test_admin_page_load_skips_the_sweep_during_ajax() {
		$this->log_in_administrator();
		$cron = new Cron( $this->config_for( self::NS ), new Logging( $this->config_for( self::NS ) ) );
		wp_unschedule_hook( Cron::RECONCILE_HOOK );
		Utils::delete_transient( sprintf( Cron::RECONCILE_FALLBACK_TRANSIENT, self::NS ) );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$cron->maybe_reconcile_without_core_event();

		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'admin-ajax requests must not run the sweep' );
	}

	// ---------------------------------------------------------------
	// One user at a time
	// ---------------------------------------------------------------

	/**
	 * A failing revoke must not stop the others, and must not be retried
	 * on the next hourly run.
	 */
	public function test_sweep_revokes_the_others_when_one_revoke_throws_and_backs_off_the_failure() {
		$first    = $this->seed_revocable_user( self::NS );
		$second   = $this->seed_revocable_user( self::NS );
		$requests = $this->record_saas_requests(
			array(
				'response' => array( 'code' => 204, 'message' => 'No Content' ),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
			)
		);

		$identifiers = $this->support_user_for( self::NS )->get_expired_identifiers();
		$this->assertCount( 2, $identifiers, 'fixture: both users must be expired' );

		$failing_id = $this->support_user_for( self::NS )->get( $identifiers[0] )->ID;
		$other_id   = $first === $failing_id ? $second : $first;

		$thrower = function ( $user_id ) use ( $failing_id ) {
			if ( $failing_id === (int) $user_id ) {
				throw new \Error( 'third-party delete hook failed' );
			}
		};
		add_action( 'delete_user', $thrower );

		$this->sweep( self::NS );

		$this->assertFalse( get_user_by( 'id', $other_id ), 'the user after the failing one must still be revoked' );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $failing_id ), 'fixture: the failing user must still be here' );
		$this->assertCount( 2, $requests, 'fixture: each user was revoked at TrustedLogin once' );

		$failures = Utils::get_transient( sprintf( Cron::RECONCILE_FAILURES_TRANSIENT, self::NS ) );
		$this->assertIsArray( $failures );
		$this->assertArrayHasKey( $identifiers[0], $failures, 'the failure must be recorded for backoff' );
		$this->assertSame( 1, $failures[ $identifiers[0] ]['count'] );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, $failures[ $identifiers[0] ]['retry_after'], 'the retry must wait longer than one hourly run' );

		$this->sweep( self::NS );

		remove_action( 'delete_user', $thrower );

		$this->assertCount( 2, $requests, 'the next hourly run must not send TrustedLogin another DELETE for the failing user' );

		Utils::delete_transient( sprintf( Cron::RECONCILE_FAILURES_TRANSIENT, self::NS ) );
	}

	/**
	 * Access extended while the sweep is revoking others must be kept.
	 */
	public function test_sweep_keeps_a_user_whose_access_is_extended_during_the_sweep() {
		$first  = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$second = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		$extend = function () use ( $first, $second ) {
			foreach ( array( $first, $second ) as $user_id ) {
				if ( get_user_by( 'id', $user_id ) ) {
					update_user_option( $user_id, 'tl_' . self::NS . '_expires', time() + WEEK_IN_SECONDS );
				}
			}
		};
		add_action( 'trustedlogin/' . self::NS . '/access/revoked', $extend );

		$this->assertSame( 1, $this->sweep( self::NS ), 'only the user revoked before the extension may go' );

		remove_action( 'trustedlogin/' . self::NS . '/access/revoked', $extend );
	}

	// ---------------------------------------------------------------
	// Multisite membership
	// ---------------------------------------------------------------

	/**
	 * A support user who also belongs to another site is removed from this
	 * site only, so their posts on the other site survive.
	 */
	public function test_sweep_keeps_a_member_of_another_site_and_their_posts_there() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$user_id  = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );
		$sub_site = self::factory()->blog->create();

		add_user_to_blog( $sub_site, $user_id, 'editor' );

		switch_to_blog( $sub_site );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		restore_current_blog();

		$this->assertSame( 1, $this->sweep( self::NS ) );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'a member of another site must stay on the network' );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_current_blog_id() ), 'the user must be removed from the swept site' );
		$this->assertFalse( get_user_option( 'tl_' . self::NS . '_expires', $user_id ), 'the swept site\'s expiration must be removed' );

		switch_to_blog( $sub_site );
		$post = get_post( $post_id );
		restore_current_blog();

		$this->assertInstanceOf( \WP_Post::class, $post, 'the user\'s post on the other site must survive' );
		$this->assertSame( $user_id, (int) $post->post_author );
	}

	/**
	 * Once the user belongs to no site, archived ones included, they are
	 * deleted from the network.
	 */
	public function test_sweep_deletes_the_user_from_the_network_once_no_site_is_left() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$user_id = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		$this->assertSame( 1, $this->sweep( self::NS ) );
		$this->assertFalse( get_user_by( 'id', $user_id ), 'a user who belongs to no site must be deleted from the network' );
	}

	// ---------------------------------------------------------------
	// Shared role and endpoint
	// ---------------------------------------------------------------

	/**
	 * A grant in progress holds the cloned role before its identifier meta
	 * is written, so the role stays. The endpoint follows support users
	 * only: a user who merely holds the role keeps no login endpoint alive.
	 */
	public function test_sweep_keeps_a_held_role_but_removes_an_endpoint_no_support_user_needs() {
		$config  = $this->config_for( self::NS );
		$logging = new Logging( $config );
		$role    = ( new SupportRole( $config, $logging ) )->get();

		$this->assertInstanceOf( \WP_Role::class, $role, 'fixture: the cloned role must exist' );

		$expired = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		self::factory()->user->create( array( 'role' => $role->name ) );

		$endpoint = new Endpoint( $config, $logging );
		$endpoint->update( 'role-holder-endpoint' );

		$this->assertSame( 1, $this->sweep( self::NS ) );
		$this->assertFalse( get_user_by( 'id', $expired ) );
		$this->assertInstanceOf( \WP_Role::class, get_role( $role->name ), 'the role held by another user must stay' );
		$this->assertSame( '', $endpoint->get(), 'with no support user left, the endpoint must go even though a user holds the role' );

		remove_role( $role->name );
	}

	/**
	 * With no one left holding the role or the endpoint, both go.
	 */
	public function test_sweep_removes_role_and_endpoint_when_nothing_uses_them() {
		$config  = $this->config_for( self::NS );
		$logging = new Logging( $config );
		$role    = ( new SupportRole( $config, $logging ) )->get();

		$expired = self::factory()->user->create(
			array(
				'role'            => $role->name,
				'user_registered' => '2020-01-01 00:00:00',
			)
		);
		update_user_option( $expired, 'tl_' . self::NS . '_id', md5( 'expired' ), true );
		update_user_option( $expired, 'tl_' . self::NS . '_site_hash', str_repeat( 'b', 32 ), true );
		update_user_option( $expired, 'tl_' . self::NS . '_expires', time() - HOUR_IN_SECONDS );

		$endpoint = new Endpoint( $config, $logging );
		$endpoint->update( 'unused-endpoint' );

		$this->assertSame( 1, $this->sweep( self::NS ) );
		$this->assertNull( get_role( $role->name ), 'an unused cloned role must be removed' );
		$this->assertSame( '', $endpoint->get(), 'an unused endpoint must be removed' );
	}

	/**
	 * Corrupt identifier meta must be skipped, not crash the sweep.
	 */
	public function test_sweep_skips_a_user_whose_identifier_is_not_a_string() {
		$user_id = self::factory()->user->create(
			array(
				'role'            => 'editor',
				'user_registered' => '2020-01-01 00:00:00',
			)
		);
		update_user_option( $user_id, 'tl_' . self::NS . '_id', array( 'not', 'a', 'string' ), true );

		$this->assertSame( 0, $this->sweep( self::NS ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ) );
	}

	// ---------------------------------------------------------------
	// Multisite
	// ---------------------------------------------------------------

	/**
	 * The expiration is stored per site. A support user added to a second
	 * site has none there, and must not be deleted from the network by
	 * that site's sweep while its grant is still valid.
	 */
	public function test_sweep_on_another_site_keeps_a_member_whose_grant_is_valid() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$user_id  = $this->seed_support_user( self::NS, time() + WEEK_IN_SECONDS );
		$sub_site = self::factory()->blog->create();

		add_user_to_blog( $sub_site, $user_id, 'editor' );

		switch_to_blog( $sub_site );
		$deleted = $this->sweep( self::NS );
		restore_current_blog();

		$this->assertSame( 0, $deleted );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'a valid grant must survive a sweep on another site it belongs to' );
	}

	/**
	 * The endpoint is one network-wide option. Removing the last support
	 * user on one site must keep it for a support user on another.
	 */
	public function test_sweep_keeps_the_endpoint_while_another_site_has_a_support_user() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$config   = $this->config_for( self::NS );
		$endpoint = new Endpoint( $config, new Logging( $config ) );
		$endpoint->update( 'shared-endpoint' );

		$sub_site = self::factory()->blog->create();

		switch_to_blog( $sub_site );
		$this->seed_support_user( self::NS, time() + WEEK_IN_SECONDS );
		restore_current_blog();

		$expired = $this->seed_support_user( self::NS, time() - HOUR_IN_SECONDS );

		$this->assertSame( 1, $this->sweep( self::NS ) );
		$this->assertFalse( get_user_by( 'id', $expired ) );
		$this->assertSame( 'shared-endpoint', $endpoint->get(), 'the other site\'s support user still logs in through the endpoint' );

		$endpoint->delete();
	}

	/**
	 * Builds a Config for a namespace.
	 *
	 * @param string $ns Namespace.
	 *
	 * @return Config
	 */
	private function config_for( $ns ) {
		return new Config(
			array(
				'role'   => 'editor',
				'auth'   => array(
					'api_key' => '0123456789abcdef',
				),
				'vendor' => array(
					'namespace'   => $ns,
					'title'       => $ns,
					'email'       => 'support+' . $ns . '@example.test',
					'website'     => 'https://' . $ns . '.example.test',
					'support_url' => 'https://' . $ns . '.example.test/support/',
				),
			)
		);
	}

	/**
	 * Rolling back a failed grant on one site must not delete a user who
	 * still belongs to another site.
	 */
	public function test_failed_grant_rollback_keeps_a_member_of_another_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$user_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sub_site = self::factory()->blog->create();
		add_user_to_blog( $sub_site, $user_id, 'editor' );

		$client   = $this->client_for( self::NS );
		$rollback = new \ReflectionMethod( Client::class, 'delete_unsynced_support_user' );
		$rollback->setAccessible( true );
		$rollback->invoke( $client, $user_id );

		clean_user_cache( $user_id );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $user_id ), 'the network account must stay while the user belongs to another site' );
		$this->assertArrayHasKey( $sub_site, get_blogs_of_user( $user_id, true ), 'the other site\'s membership must stay' );
		$this->assertArrayNotHasKey( get_current_blog_id(), get_blogs_of_user( $user_id, true ), 'the user must be removed from the site that rolled back' );
	}

	/**
	 * A rolled-back user who belongs to no other site is deleted from the
	 * network, so the next grant can reuse the email address.
	 */
	public function test_failed_grant_rollback_deletes_a_user_on_no_other_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$client   = $this->client_for( self::NS );
		$rollback = new \ReflectionMethod( Client::class, 'delete_unsynced_support_user' );
		$rollback->setAccessible( true );
		$rollback->invoke( $client, $user_id );

		clean_user_cache( $user_id );

		$this->assertFalse( get_user_by( 'id', $user_id ) );
	}

	/**
	 * Nothing to do must cost nothing and report nothing.
	 */
	public function test_sweep_reports_zero_when_there_is_nothing_to_remove() {
		$this->assertSame( 0, $this->sweep( self::NS ) );
	}
}

<?php
/**
 * Adversarial integration tests for the Grant Access flow.
 *
 * Uses the real Client object graph (no Reflection on private
 * dependencies). Real Config, Logging, SupportUser, Endpoint —
 * exercised end-to-end against the WP test database.
 *
 * Covers:
 *   - Item 5: revoke authorization (nonce binding, capability gate,
 *     happy-path delete)
 *   - Item 10: endpoint URL replay after revoke → no resurrection
 *
 * Identifiers in tests use the IDENT() helper which produces
 * 40-char strings. SupportUser::get() hashes inputs >32 chars
 * before lookup; production site_identifier_hash values are always
 * >32 chars (random 64-byte hex), so this matches the real shape.
 *
 * Items deferred (require time mocking or coordinated multi-user
 * setup that fights the email-collision guard):
 *   - Item 4: decay enforcement (Cron schedule + advance time)
 *   - Item 7: grant_access POST replay (the email guard handles
 *     local user dedup; a SaaS-side replay test belongs in the
 *     SaaS controller suite, which already covers it)
 *   - Item 11: user-create / SaaS-sync race (needs Remote::send
 *     mid-flight failure injection coordinated with DB rollback)
 *
 * @group security
 * @group grant
 */

class TrustedLoginGrantSecurityTest extends WP_UnitTestCase {

	const IDENT_A     = 'identA-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const IDENT_REAL  = 'realTarget-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const IDENT_OTHER = 'otherTarget-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const IDENT_X     = 'targetX-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const IDENT_HAPPY = 'happyPath-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const IDENT_REPLAY = 'replay-target-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const IDENT_EXPIRED = 'expired-target-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** @var \TrustedLogin\Config */
	private $config;

	/** @var \TrustedLogin\Logging */
	private $logging;

	/** @var \TrustedLogin\SupportUser */
	private $support_user;

	/** @var \TrustedLogin\Endpoint */
	private $endpoint;

	/** @var \TrustedLogin\Cron */
	private $cron;

	public function setUp(): void {
		parent::setUp();

		// Random per-test email so the SDK's email-collision guard
		// doesn't fight us between test methods.
		$unique_email = 'grant-security-' . bin2hex( random_bytes( 6 ) ) . '@example.test';

		$this->config = new \TrustedLogin\Config( array(
			'role'        => 'editor',
			'caps'        => array( 'add' => array( 'edit_posts' => 'help debug' ) ),
			'webhook_url' => 'https://www.trustedlogin.com/webhook-example/',
			'auth'        => array( 'api_key' => '9946ca31be6aa948', 'license_key' => 'license' ),
			'decay'       => WEEK_IN_SECONDS,
			'vendor'      => array(
				'namespace'   => 'gravityview',
				'title'       => 'GravityView',
				'email'       => $unique_email,
				'website'     => 'https://example.test',
				'support_url' => 'https://example.test/support',
				'logo_url'    => '',
			),
			'reassign_posts' => true,
		) );

		$this->logging      = new \TrustedLogin\Logging( $this->config );
		$this->support_user = new \TrustedLogin\SupportUser( $this->config, $this->logging );
		$this->endpoint     = new \TrustedLogin\Endpoint( $this->config, $this->logging );

		// Wire the Cron action handler that maybe_revoke_support
		// triggers via do_action('trustedlogin/{ns}/access/revoke').
		// In production Client::init() does this; the test
		// constructs the Endpoint directly so it must register the
		// Cron handler itself.
		$this->cron = new \TrustedLogin\Cron( $this->config, $this->logging );
		$this->cron->init();
	}

	public function tearDown(): void {
		// Detach the Cron action handler we wired up in setUp so it
		// doesn't accumulate across test methods.
		remove_action(
			'trustedlogin/' . $this->config->ns() . '/access/revoke',
			array( $this->cron, 'revoke' ),
			1
		);

		foreach ( $this->support_user->get_all() as $user ) {
			wp_delete_user( $user->ID );
		}
		$leftover = get_user_by( 'email', $this->config->get_setting( 'vendor/email' ) );
		if ( $leftover ) {
			wp_delete_user( $leftover->ID );
		}

		parent::tearDown();
	}

	private function seed_support_user( string $identifier ): \WP_User {
		$user_id = $this->support_user->create();
		$this->assertIsInt(
			$user_id,
			'seed_support_user: create() must return an int. Got: ' . ( is_wp_error( $user_id ) ? $user_id->get_error_code() : gettype( $user_id ) )
		);

		$identifier_hash = \TrustedLogin\Encryption::hash( $identifier );

		update_user_option(
			$user_id,
			$this->support_user->user_identifier_meta_key,
			$identifier_hash,
			true
		);

		$user = get_userdata( $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );

		return $user;
	}

	private function become_admin(): int {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// In multisite, regular administrators don't have
		// delete_users by default — that's a super-admin cap.
		// The test framework runs as multisite (WP_TESTS_MULTISITE=1
		// in phpunit.xml.dist), so promote so the cap-check
		// branches in maybe_revoke_support behave like a real WP
		// admin would on a single-site install.
		if ( function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $admin_id );
		}

		return $admin_id;
	}

	public function _swallow_redirect( $location, $status ) {
		return false;
	}

	// -----------------------------------------------------------------
	//  Item 5 — revoke authorization
	// -----------------------------------------------------------------

	public function test_revoke_silently_ignores_request_without_nonce(): void {
		$this->become_admin();
		$this->seed_support_user( self::IDENT_A );

		$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ] = $this->config->ns();
		$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ]          = self::IDENT_A;

		try {
			$this->endpoint->maybe_revoke_support();
		} finally {
			unset(
				$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ],
				$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ]
			);
		}

		$this->assertNotNull(
			$this->support_user->get( self::IDENT_A ),
			'No-nonce revoke must be silently ignored; the support user must still exist.'
		);
	}

	public function test_revoke_rejects_nonce_scoped_to_a_different_target(): void {
		$this->become_admin();
		$this->seed_support_user( self::IDENT_REAL );

		$nonce_for_other = wp_create_nonce(
			\TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM . '|' . self::IDENT_OTHER
		);

		$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ] = $this->config->ns();
		$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ]          = self::IDENT_REAL;
		$_GET['_wpnonce']                                            = $nonce_for_other;

		try {
			$this->endpoint->maybe_revoke_support();
		} finally {
			unset(
				$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ],
				$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ],
				$_GET['_wpnonce']
			);
		}

		$this->assertNotNull(
			$this->support_user->get( self::IDENT_REAL ),
			'Nonce-target mismatch must be rejected; realTarget must still exist.'
		);
	}

	public function test_revoke_rejects_caller_without_delete_users_or_support_role(): void {
		$this->become_admin();
		$this->seed_support_user( self::IDENT_X );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ] = $this->config->ns();
		$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ]          = self::IDENT_X;
		$_GET['_wpnonce']                                            = wp_create_nonce(
			\TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM . '|' . self::IDENT_X
		);

		add_filter( 'wp_redirect', array( $this, '_swallow_redirect' ), 10, 2 );

		try {
			$this->endpoint->maybe_revoke_support();
		} finally {
			remove_filter( 'wp_redirect', array( $this, '_swallow_redirect' ), 10 );
			unset(
				$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ],
				$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ],
				$_GET['_wpnonce']
			);
		}

		$this->assertNotNull(
			$this->support_user->get( self::IDENT_X ),
			'A subscriber with neither delete_users nor the support-team role must NOT be able to revoke.'
		);
	}

	public function test_revoke_with_correct_nonce_and_cap_does_delete_user(): void {
		$this->become_admin();
		$this->seed_support_user( self::IDENT_HAPPY );

		$this->assertInstanceOf( \WP_User::class, $this->support_user->get( self::IDENT_HAPPY ) );

		$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ] = $this->config->ns();
		$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ]          = self::IDENT_HAPPY;
		$_GET['_wpnonce']                                            = wp_create_nonce(
			\TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM . '|' . self::IDENT_HAPPY
		);

		add_filter( 'wp_redirect', array( $this, '_swallow_redirect' ), 10, 2 );

		try {
			$this->endpoint->maybe_revoke_support();
		} finally {
			remove_filter( 'wp_redirect', array( $this, '_swallow_redirect' ), 10 );
			unset(
				$_GET[ \TrustedLogin\Endpoint::REVOKE_SUPPORT_QUERY_PARAM ],
				$_GET[ \TrustedLogin\SupportUser::ID_QUERY_PARAM ],
				$_GET['_wpnonce']
			);
		}

		$this->assertNull(
			$this->support_user->get( self::IDENT_HAPPY ),
			'Admin with delete_users + correct-target nonce must be able to revoke.'
		);
	}

	// -----------------------------------------------------------------
	//  Item 4 — decay (TTL) enforcement at maybe_login. The Cron
	//  schedule is the primary expiration mechanism; the
	//  maybe_login flow is the safety net for "cron didn't fire"
	//  scenarios. An expired user attempting to log in via the
	//  endpoint URL must be refused AND cleaned up — never given
	//  access just because the scheduled revoke missed.
	// -----------------------------------------------------------------

	public function test_maybe_login_refuses_and_deletes_expired_support_user(): void {
		$this->become_admin();

		$user = $this->seed_support_user( self::IDENT_EXPIRED );

		// Stamp expiration in the past — production stamps it via
		// SupportUser::setup() at create-time.
		$expires_meta_key = 'tl_' . $this->config->ns() . '_expires';
		update_user_option( $user->ID, $expires_meta_key, time() - HOUR_IN_SECONDS, true );

		$this->assertNotNull(
			$this->support_user->get( self::IDENT_EXPIRED ),
			'Sanity: support user exists before maybe_login.'
		);

		$result = $this->support_user->maybe_login( self::IDENT_EXPIRED );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'maybe_login must return WP_Error for an expired user — never grant a session even if the Cron revoke missed.'
		);
		$this->assertSame(
			'access_expired',
			$result->get_error_code(),
			'The error code must be access_expired so the standalone fallback page can render the right copy.'
		);

		$this->assertNull(
			$this->support_user->get( self::IDENT_EXPIRED ),
			'maybe_login must DELETE the expired user — leaving them around would let a future flow with a different code path inadvertently log them in.'
		);
	}

	// -----------------------------------------------------------------
	//  Item 8 — username collision. SupportUser::generate_unique_username
	//  produces "<vendor> Support"; if a user with that login already
	//  exists, it appends " 2", " 3", … until a free name is found.
	//  The property: a pre-existing user with the same username is
	//  NEVER silently bound to the new support-grant context.
	// -----------------------------------------------------------------

	public function test_create_does_not_bind_to_pre_existing_username(): void {
		$this->become_admin();

		// Pre-seed a non-support user whose login matches what
		// generate_unique_username would otherwise pick. Vendor
		// title is "GravityView" → username "GravityView Support"
		// (after sanitize_user the space becomes part of the
		// stored login).
		$expected_default = sanitize_user( 'GravityView Support' );
		$squatter_id      = self::factory()->user->create( array(
			'user_login' => $expected_default,
			'user_email' => 'squatter-' . bin2hex( random_bytes( 4 ) ) . '@example.test',
			'role'       => 'subscriber',
		) );
		$this->assertIsInt( $squatter_id );
		$this->assertSame( $expected_default, get_userdata( $squatter_id )->user_login );

		// Now create the support user. The squatter must NOT be
		// touched; the support user must get a different login.
		$support_user_id = $this->support_user->create();
		$this->assertIsInt(
			$support_user_id,
			'create() must succeed even when the default username is taken: ' . ( is_wp_error( $support_user_id ) ? $support_user_id->get_error_code() : gettype( $support_user_id ) )
		);
		$this->assertNotSame(
			$squatter_id,
			$support_user_id,
			'create() must not silently re-bind to a pre-existing user with the default support username.'
		);

		$support_user = get_userdata( $support_user_id );
		$this->assertInstanceOf( \WP_User::class, $support_user );
		$this->assertNotSame(
			$expected_default,
			$support_user->user_login,
			'New support user MUST have a distinct user_login when the default is taken (expected " 2"-suffix).'
		);
		// Suffix should start with " 2" when the first slot is busy.
		$this->assertStringStartsWith(
			$expected_default . ' ',
			$support_user->user_login,
			'Collision-resolution suffix must keep the original prefix, not pick an unrelated name.'
		);

		// Squatter's role/email/login must be unchanged.
		$squatter_after = get_userdata( $squatter_id );
		$this->assertSame( $expected_default, $squatter_after->user_login );
		$this->assertContains(
			'subscriber',
			(array) $squatter_after->roles,
			'Pre-existing user role must NOT have been modified by the support-user creation flow.'
		);
	}

	// -----------------------------------------------------------------
	//  Item 11 — user-create / SaaS-sync race. If the local support
	//  user is created but the SaaS sync subsequently fails, the
	//  local user MUST be rolled back. An orphan user with the
	//  cloned support role and no SaaS handle is a privilege
	//  escalation surface — it can be used to log in via the
	//  endpoint URL but the integrator has no record of the grant.
	// -----------------------------------------------------------------

	public function test_grant_access_rolls_back_local_user_when_saas_sync_fails(): void {
		$this->become_admin();

		// Build a real Client with the full dependency graph but no
		// hooks fired. Then Reflection-swap just the Remote — the
		// outermost HTTP boundary — to simulate SaaS unreachable.
		$client = new \TrustedLogin\Client( $this->config, false );

		$fake_remote = new class {
			public $sent = array();

			public function send( $path, $data = array(), $method = 'POST', $additional_headers = array(), $timeout = null ) {
				$this->sent[] = compact( 'path', 'data', 'method' );
				return new \WP_Error( 'http_request_failed', 'simulated SaaS unreachable' );
			}

			public function handle_response( $response ) {
				return $response;
			}

			public function init() {}

			public function maybe_send_webhook( ...$args ) {}
		};

		$rc = new ReflectionClass( $client );

		// Swap Client::$remote.
		$prop = $rc->getProperty( 'remote' );
		$prop->setAccessible( true );
		$prop->setValue( $client, $fake_remote );

		// Also swap SiteAccess's internal remote — sync_secret takes
		// it injected, but it caches a ref through the Client's
		// instantiated graph. Force the same fake there.
		$site_access_prop = $rc->getProperty( 'site_access' );
		$site_access_prop->setAccessible( true );
		$site_access = $site_access_prop->getValue( $client );

		$site_access_rc = new ReflectionClass( $site_access );
		// SiteAccess uses a `Remote` typed param to sync_secret —
		// the call site in Client passes Client::$remote in. Confirm
		// by reading src/Client.php near sync_secret( ..., $remote ).
		// If SiteAccess holds its own ref, swap it too.
		if ( $site_access_rc->hasProperty( 'remote' ) ) {
			$sa_remote = $site_access_rc->getProperty( 'remote' );
			$sa_remote->setAccessible( true );
			$sa_remote->setValue( $site_access, $fake_remote );
		}

		add_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_true' );

		try {
			$result = $client->grant_access();
		} finally {
			remove_filter( 'trustedlogin/' . $this->config->ns() . '/meets_ssl_requirement', '__return_true' );
		}

		// Two acceptable outcomes — both are fail-closed:
		//   a) WP_Error returned with the local user already deleted
		//      (grant_access's wp_delete_user rollback path fires).
		//   b) The flow returns earlier with a different WP_Error
		//      (e.g. the secret_id step doesn't reach the SaaS).
		// What's NEVER acceptable: a successful return AND an
		// orphaned support user left in the DB.
		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'grant_access must NOT return a success array when the SaaS sync fails.'
		);

		// `get_all()` has a function-static cache that persists
		// across test methods, so use the unhashed-email lookup
		// instead — that's the unique key for the support user
		// in this test (vendor.email is generated per-test in setUp).
		$leftover = get_user_by( 'email', $this->config->get_setting( 'vendor/email' ) );
		$this->assertFalse(
			$leftover,
			'After SaaS-sync failure, NO support user may remain in the local DB. Orphaned support users with cloned-role caps are a privilege-escalation surface — the endpoint URL would still let an attacker log in even though the integrator has no record of the grant.'
		);
	}

	// -----------------------------------------------------------------
	//  Item 12 — concurrent grants. A true load test (parallel HTTP
	//  workers) is out of scope for unit tests, but the underlying
	//  uniqueness + non-clobber properties that MAKE concurrent
	//  grants safe are testable here:
	//
	//   - Each grant produces a fresh site_identifier_hash
	//     (Encryption::get_random_hash, already covered in
	//     tests/Unit/GrantingFlowSecurityTest).
	//   - The endpoint hash + secret_id derived from a hash are
	//     deterministic-by-input, so distinct identifiers produce
	//     distinct endpoint URLs.
	//   - The Cron schedule keys events by the identifier hash
	//     (passed via $args to wp_schedule_single_event), so two
	//     grants for two different users coexist as two separate
	//     scheduled events.
	//   - Cron::reschedule for one identifier does NOT clear another
	//     identifier's pending revoke.
	// -----------------------------------------------------------------

	public function test_endpoint_hashes_are_distinct_for_distinct_identifiers(): void {
		$hash_a = $this->endpoint->get_hash( self::IDENT_A );
		$hash_b = $this->endpoint->get_hash( self::IDENT_REAL );

		$this->assertNotEmpty( $hash_a );
		$this->assertNotEmpty( $hash_b );
		$this->assertNotSame(
			$hash_a,
			$hash_b,
			'Two grants with distinct identifiers must produce distinct endpoint hashes — otherwise concurrent grants collide on the same login URL.'
		);
	}

	public function test_secret_ids_are_distinct_for_distinct_identifiers(): void {
		$secret_a = $this->endpoint->generate_secret_id( self::IDENT_A, $this->endpoint->get_hash( self::IDENT_A ) );
		$secret_b = $this->endpoint->generate_secret_id( self::IDENT_REAL, $this->endpoint->get_hash( self::IDENT_REAL ) );

		$this->assertNotEmpty( $secret_a );
		$this->assertNotEmpty( $secret_b );
		$this->assertNotSame(
			$secret_a,
			$secret_b,
			'Two grants must produce distinct secret_ids — the SaaS uses secret_id as the row key, so collision means one grant overwrites the other.'
		);
	}

	public function test_cron_schedule_for_one_identifier_does_not_clobber_another(): void {
		$expires_a = time() + HOUR_IN_SECONDS;
		$expires_b = time() + 2 * HOUR_IN_SECONDS;

		$scheduled_a = $this->cron->schedule( $expires_a, self::IDENT_A );
		$scheduled_b = $this->cron->schedule( $expires_b, self::IDENT_REAL );

		$this->assertNotFalse(
			$scheduled_a,
			'Cron::schedule for identifier A must succeed.'
		);
		$this->assertNotFalse(
			$scheduled_b,
			'Cron::schedule for identifier B must succeed even though A is already scheduled.'
		);

		// Both events must be present — wp_next_scheduled returns
		// the timestamp for an event matching (hook, args). Args
		// are the per-identifier hash, so distinct identifiers
		// must yield distinct schedules.
		$hook_name = 'trustedlogin/' . $this->config->ns() . '/access/revoke';

		$next_a = wp_next_scheduled(
			$hook_name,
			array( \TrustedLogin\Encryption::hash( self::IDENT_A ) )
		);
		$next_b = wp_next_scheduled(
			$hook_name,
			array( \TrustedLogin\Encryption::hash( self::IDENT_REAL ) )
		);

		$this->assertSame(
			$expires_a,
			$next_a,
			'Identifier A\'s cron event must be scheduled at A\'s expiration.'
		);
		$this->assertSame(
			$expires_b,
			$next_b,
			'Identifier B\'s cron event must coexist at B\'s expiration — not clobbered by A\'s scheduling.'
		);

		// Cleanup so subsequent tests don't see leftover schedules.
		wp_clear_scheduled_hook( $hook_name, array( \TrustedLogin\Encryption::hash( self::IDENT_A ) ) );
		wp_clear_scheduled_hook( $hook_name, array( \TrustedLogin\Encryption::hash( self::IDENT_REAL ) ) );
	}

	public function test_cron_reschedule_targets_only_the_named_identifier(): void {
		$expires_a   = time() + HOUR_IN_SECONDS;
		$expires_b   = time() + 2 * HOUR_IN_SECONDS;
		$rescheduled = time() + 3 * HOUR_IN_SECONDS;

		$this->cron->schedule( $expires_a, self::IDENT_A );
		$this->cron->schedule( $expires_b, self::IDENT_REAL );

		$this->cron->reschedule( $rescheduled, self::IDENT_A );

		$hook_name = 'trustedlogin/' . $this->config->ns() . '/access/revoke';

		$next_a = wp_next_scheduled(
			$hook_name,
			array( \TrustedLogin\Encryption::hash( self::IDENT_A ) )
		);
		$next_b = wp_next_scheduled(
			$hook_name,
			array( \TrustedLogin\Encryption::hash( self::IDENT_REAL ) )
		);

		$this->assertSame(
			$rescheduled,
			$next_a,
			'reschedule(A) must move A to the new timestamp.'
		);
		$this->assertSame(
			$expires_b,
			$next_b,
			'reschedule(A) must NOT touch B\'s pending revoke.'
		);

		wp_clear_scheduled_hook( $hook_name, array( \TrustedLogin\Encryption::hash( self::IDENT_A ) ) );
		wp_clear_scheduled_hook( $hook_name, array( \TrustedLogin\Encryption::hash( self::IDENT_REAL ) ) );
	}

	public function test_two_consecutive_grant_setups_produce_distinct_identifier_hashes(): void {
		// Each grant generates a fresh site_identifier_hash via
		// Encryption::get_random_hash. Two consecutive calls must
		// produce distinct values — otherwise grant A and grant B
		// would alias to the same support session.
		$hash_a = \TrustedLogin\Encryption::get_random_hash( $this->logging );
		$hash_b = \TrustedLogin\Encryption::get_random_hash( $this->logging );

		$this->assertNotInstanceOf( \WP_Error::class, $hash_a );
		$this->assertNotInstanceOf( \WP_Error::class, $hash_b );
		$this->assertNotSame(
			$hash_a,
			$hash_b,
			'Two consecutive get_random_hash() calls must produce distinct values — concurrent grants depend on this for non-collision.'
		);

		// Pin the length too — production assumes 128-hex chars
		// (random_bytes(64) → bin2hex). A regression that returns
		// shorter values would shrink the entropy available for
		// concurrent-grant separation.
		$this->assertSame( 128, strlen( $hash_a ) );
		$this->assertSame( 128, strlen( $hash_b ) );
	}

	// -----------------------------------------------------------------
	//  Item 10 — endpoint URL replay after revoke
	// -----------------------------------------------------------------

	public function test_replaying_identifier_after_delete_does_not_resurrect_or_log_in(): void {
		$this->become_admin();
		$this->seed_support_user( self::IDENT_REPLAY );

		$this->assertNotNull(
			$this->support_user->get( self::IDENT_REPLAY ),
			'Sanity: support user exists before delete.'
		);

		$this->support_user->delete( self::IDENT_REPLAY, false, false );

		$this->assertNull(
			$this->support_user->get( self::IDENT_REPLAY ),
			'Sanity: support user is gone after delete.'
		);

		$admin_id_before = get_current_user_id();
		$this->assertGreaterThan( 0, $admin_id_before, 'Admin must be logged in before replay.' );

		$this->support_user->maybe_login( self::IDENT_REPLAY );

		$this->assertNull(
			$this->support_user->get( self::IDENT_REPLAY ),
			'Replayed identifier must not resurrect the deleted user.'
		);
		$this->assertSame(
			$admin_id_before,
			get_current_user_id(),
			'Replayed identifier must not switch the current user to a freshly-resurrected support user.'
		);
	}
}

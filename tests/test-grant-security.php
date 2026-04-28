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

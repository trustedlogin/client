<?php
/**
 * The Grant Access screen reads the support users once per render and
 * forgets them when the render ends, including when it throws.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

use WP_UnitTestCase;

class FormSupportUsersCacheTest extends WP_UnitTestCase {

	const NS = 'form-cache-vendor';

	/**
	 * Builds a Form for the test namespace.
	 *
	 * @return Form
	 */
	private function build_form() {
		$config  = new Config(
			array(
				'role'   => 'editor',
				'auth'   => array(
					'api_key' => '0123456789abcdef',
				),
				'vendor' => array(
					'namespace'   => self::NS,
					'title'       => 'Form Cache Vendor',
					'email'       => 'support+' . self::NS . '@example.test',
					'website'     => 'https://' . self::NS . '.example.test',
					'support_url' => 'https://' . self::NS . '.example.test/support/',
				),
			)
		);
		$logging = new Logging( $config );

		return new Form( $config, $logging, new SupportUser( $config, $logging ), new SiteAccess( $config, $logging ) );
	}

	/**
	 * The per-render support-user list.
	 *
	 * @return array<string, \WP_User[]>
	 */
	private function cached_support_users() {
		$property = new \ReflectionProperty( Form::class, 'support_users' );
		$property->setAccessible( true );

		return $property->getValue();
	}

	public function test_render_forgets_the_support_users_when_it_throws() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_option( $user_id, 'tl_' . self::NS . '_id', md5( 'form-cache' ), true );

		$thrower = function () {
			throw new \RuntimeException( 'template filter failed' );
		};
		add_filter( 'trustedlogin/' . self::NS . '/template/auth', $thrower );

		$caught = null;

		try {
			$this->build_form()->get_auth_screen();
		} catch ( \RuntimeException $exception ) {
			$caught = $exception;
		}

		remove_filter( 'trustedlogin/' . self::NS . '/template/auth', $thrower );

		$this->assertInstanceOf( \RuntimeException::class, $caught, 'fixture: the render must have thrown' );
		$this->assertArrayNotHasKey( self::NS, $this->cached_support_users(), 'a failed render must not leave its support-user list behind' );
	}

	public function test_render_forgets_the_support_users_when_it_returns() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_option( $user_id, 'tl_' . self::NS . '_id', md5( 'form-cache-ok' ), true );

		$this->build_form()->get_auth_screen();

		$this->assertArrayNotHasKey( self::NS, $this->cached_support_users() );
	}
}

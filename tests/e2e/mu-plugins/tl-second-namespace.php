<?php
/**
 * Plugin Name: TrustedLogin Second Namespace (e2e)
 * Description: Registers a SECOND TrustedLogin client (namespace
 *              "widget-master") on the same site so the
 *              two-namespace coexistence spec can verify the
 *              first-registered client doesn\'t pollute the second.
 *              Mirrors a real-world WP install where one vendor ships
 *              multiple plugins, each integrating TrustedLogin.
 *
 * Loads only inside the e2e docker stack — never ship this.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Defer to after the autoloader the main client.php registers — that
// runs at plugin-load time, well before "init". By "init" the autoload
// chain knows about TrustedLogin\Client and friends.
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( '\\TrustedLogin\\Client' ) ) {
		return;
	}

	try {
		$config = new \TrustedLogin\Config(
			array(
				'auth'   => array(
					'api_key' => '0123456789abcdef',
				),
				'vendor' => array(
					'namespace'   => 'widget-master',
					'title'       => 'Widget Master',
					'email'       => 'support+widget@example.test',
					'website'     => 'http://localhost:8002',
					'support_url' => 'http://localhost:8002/support',
				),
				// Distinct role + caps so we can assert the two installs
				// don\'t share role storage. SDK normalizes keys to the
				// namespace, so this should already be safe — the test
				// proves it stays that way.
				'role'   => 'editor',
			)
		);
		new \TrustedLogin\Client( $config );
	} catch ( \Throwable $e ) {
		// Don\'t hard-fail the whole site if the second namespace
		// fails to load — the e2e suite\'s primary path uses the first
		// namespace, and the coexistence spec will surface the issue.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[tl-second-namespace] init failed: ' . $e->getMessage() );
	}
} );

// Same fake-saas + SSL overrides as namespace #1\'s mu-plugin, scoped
// to the second namespace. Without these, the second namespace would
// try to reach app.trustedlogin.com on a real network.
add_filter( 'trustedlogin/widget-master/api_url', static function () {
	return 'http://fake-saas:8003/api/v1/';
} );
add_filter( 'trustedlogin/widget-master/vendor/public_key/website', static function () {
	return 'http://vendor-wp';
} );
add_filter( 'trustedlogin/widget-master/meets_ssl_requirement', '__return_true' );
add_filter( 'trustedlogin/widget-master/webhook_url', '__return_false' );

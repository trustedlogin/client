<?php
/**
 * The TrustedLogin drop-in class. Include this file and instantiate the class and you have secure support.
 *
 * @version 0.9.2
 * @copyright 2020 Katz Web Services, Inc.
 *
 * ###                    ###
 * ###   HEY DEVELOPER!   ###
 * ###                    ###
 * ###  (read me first)   ###
 *
 * Thanks for integrating TrustedLogin.
 *
 * 0. If you haven't already, sign up for a TrustedLogin account {@see https://www.trustedlogin.com}
 * 1. Prefix the namespace below with your own namespace (`namespace \ReplaceThisExample\TrustedLogin;`)
 * 2. Instantiate this class with a configuration array ({@see https://www.trustedlogin.com/configuration/} for more info)
 */
namespace TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

/**
 * The TrustedLogin all-in-one drop-in class.
 */
final class Client {

	/**
	 * @var string The current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.9.6';

	/**
	 * @var string The API url for the TrustedLogin SaaS Platform (with trailing slash)
	 * @since 0.4.0
	 */
	const saas_api_url = 'https://app.trustedlogin.com/api/';

	/**
	 * @var string The version of jQuery Confirm currently being used
	 * @internal Don't rely on jQuery Confirm existing!
	 */
	const jquery_confirm_version = '3.3.4';

	/**
	 * @var \TrustedLogin\Config
	 */
	private $config;

	/**
	 * @var null|\TrustedLogin\Logger
	 */
	private $logger = null;

	/**
	 * @var \TrustedLogin\SupportUser
	 */
	private $support_user;

	/**
	 * @var string $support_role The namespaced name of the new Role to be created for Support Agents
	 * @example '{vendor/namespace}-support'
	 * @since 0.1.0
	 */
	private $support_role;

	/**
	 * @var string $endpoint_option The namespaced setting name for storing part of the auto-login endpoint
	 * @example 'tl_{vendor/namespace}_endpoint'
	 * @since 0.3.0
	 */
	private $endpoint_option;

	/**
	 * @var string $identifier_meta_key The namespaced setting name for storing the unique identifier hash in user meta
	 * @example tl_{vendor/namespace}_id
	 * @since 0.7.0
	 */
	private $identifier_meta_key;

	/**
	 * @var int $expires_meta_key The namespaced setting name for storing the timestamp the user expires
	 * @example tl_{vendor/namespace}_expires
	 * @since 0.7.0
	 */
	private $expires_meta_key;

	/**
	 * @var int $created_by_meta_key The ID of the user who created the TrustedLogin access
	 * @since 0.9.7
	 */
	private $created_by_meta_key;

	/**
	 * @var bool $debug_mode Whether to output debug information to a debug text file
	 * @since 0.1.0
	 */
	private $debug_mode = false;

	/**
	 * @var string $ns Plugin's namespace (lowercase) for use in namespacing variables and strings. Sanitized using {@see sanitize_title_with_dashes()}
	 * @since 0.4.0
	 */
	private $ns;

	/**
	 * @var string $public_key_option Where the plugin should store the public key for encrypting data
	 * @since 0.5.0
	 */
	private $public_key_option;

	/**
	 * @var string $sharable_accesskey_option Where the plugin should store the shareable access key
	 * @since 0.9.2
	 */
	private $sharable_accesskey_option;

	/**
	 * TrustedLogin constructor.
	 *
	 * @see https://docs.trustedlogin.com/ for more information
	 *
	 * @param Config $config
	 *
	 * @throws Exception;
	 */
	public function __construct( Config $config ) {

		try {
			$config->validate();
		} catch ( Exception $exception ) {
			return;
		}

		$this->init_properties( $config );

		$this->logger = new \TrustedLogin\Logger( $config );

		$this->support_user = new \TrustedLogin\SupportUser( $config, $this->logger );

		$this->init_hooks();
	}

	/**
	 * @see https://github.com/php-fig/log/blob/master/Psr/Log/LogLevel.php for log levels
	 *
	 * @param string $method Method where the log was called
	 * @param string $level PSR-3 log level
	 *
	 * @param string $text Message to log
	 */
	public function log( $text = '', $method = '', $level = 'debug' ) {
		$this->logger->log( $text, $method, $level );
	}

	/**
	 * Initialise the action hooks required
	 *
	 * @since 0.2.0
	 */
	public function init_hooks() {

		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );

		add_action( 'trustedlogin/' . $this->ns . '/access/revoke', array( $this, 'cron_revoke_access' ) );

		add_action( 'wp_ajax_tl_' . $this->ns . '_gen_support', array( $this, 'ajax_generate_support' ) );

		if ( is_admin() ) {
			add_action( 'trustedlogin/' . $this->ns . '/button', array( $this, 'generate_button' ), 10, 2 );

			add_filter( 'user_row_actions', array( $this, 'user_row_action_revoke' ), 10, 2 );

			add_action( 'trustedlogin/' . $this->ns . '/users_table', array( $this, 'output_support_users' ), 20 );
		}

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_add_toolbar_items' ), 100 );
		add_action( 'admin_menu', array( $this, 'admin_menu_auth_link_page' ), $this->config->get_setting( 'menu/priority', 100 ) );

		add_action( 'admin_init', array( $this, 'admin_maybe_revoke_support' ), 100 );

		// Endpoint Hooks
		add_action( 'init', array( $this, 'add_support_endpoint' ), 10 );
		add_action( 'template_redirect', array( $this, 'maybe_login_support' ), 99 );

		add_action( 'trustedlogin/' . $this->ns . '/access/created', array( $this, 'maybe_send_webhook' ) );
		add_action( 'trustedlogin/' . $this->ns . '/access/revoked', array( $this, 'maybe_send_webhook' ) );
	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @since 0.3.0
	 */
	public function add_support_endpoint() {

		$endpoint = get_site_option( $this->endpoint_option );

		if ( ! $endpoint ) {
			return;
		}

		add_rewrite_endpoint( $endpoint, EP_ROOT );

		$this->log( "Endpoint {$endpoint} added.", __METHOD__, 'debug' );

		if ( $endpoint && ! get_site_option( 'tl_permalinks_flushed' ) ) {

			flush_rewrite_rules( false );

			update_option( 'tl_permalinks_flushed', 1 );

			$this->log( "Rewrite rules flushed.", __METHOD__, 'info' );
		}
	}

	/**
	 * Check if the endpoint is hit and has a valid identifier before automatically logging in support agent
	 *
	 * @since 0.3.0
	 */
	public function maybe_login_support() {

		$endpoint = get_site_option( $this->endpoint_option );

		$identifier = get_query_var( $endpoint, false );

		if ( empty( $identifier ) ) {
			return;
		}

		$users = $this->support_user->get( $identifier );

		if ( empty( $users ) ) {
			return;
		}

		$support_user = $users[0];

		$expires = get_user_option( $this->expires_meta_key, $support_user->ID );

		// This user has expired, but the cron didn't run...
		if ( $expires && time() > (int) $expires ) {
			$this->log( 'The user was supposed to expire on ' . $expires . '; revoking now.', __METHOD__, 'warning' );

			$identifier = get_user_option( $this->identifier_meta_key, $support_user->ID );

			$this->support_user->delete( $identifier );

			return;
		}

		wp_set_current_user( $support_user->ID, $support_user->user_login );
		wp_set_auth_cookie( $support_user->ID );

		do_action( 'wp_login', $support_user->user_login, $support_user );

		wp_safe_redirect( admin_url() );
		exit();
	}

	/**
	 * AJAX handler for maybe generating a Support User
	 *
	 * @since 0.2.0
	 *
	 * @return void Sends a JSON success or error message based on what happens
	 */
	public function ajax_generate_support() {

		if ( empty( $_POST['vendor'] ) ) {
			wp_send_json_error( array( 'message' => 'Vendor not defined in TrustedLogin configuration.' ) );
		}

		// There are multiple TrustedLogin instances, and this is not the one being called.
		if ( $this->ns !== $_POST['vendor'] ) {
			wp_send_json_error( array( 'message' => 'Vendor does not match.' ) );
			return;
		}

		if ( empty( $_POST['_nonce'] ) ) {
			wp_send_json_error( array( 'message' => 'Nonce not sent in the request.' ) );
		}

		if ( ! check_ajax_referer( 'tl_nonce-' . get_current_user_id(), '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Verification issue: Request could not be verified. Please reload the page.' ) );
		}

		if ( ! current_user_can( 'create_users' ) ) {
			wp_send_json_error( array( 'message' => 'Permissions issue: You do not have the ability to create users.' ) );
		}

		try {
			$support_user_id = $this->support_user->create();
		} catch ( Exception $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}

		if ( is_wp_error( $support_user_id ) ) {

			$this->log( sprintf( 'Support user not created: %s (%s)', $support_user_id->get_error_message(), $support_user_id->get_error_code() ), __METHOD__, 'error' );

			wp_send_json_error( array( 'message' => $support_user_id->get_error_message() ), 409 );
		}

		$identifier_hash = $this->generate_identifier_hash();

		if ( is_wp_error( $identifier_hash ) ) {
			wp_send_json_error( array( 'message' => 'Could not generate a secure secret.' ), 501 );
		}

		$endpoint = $this->get_endpoint_hash( $identifier_hash );

		$this->update_endpoint( $endpoint );

		$expiration_timestamp = $this->get_expiration_timestamp();

		// Add user meta, configure decay
		$did_setup = $this->support_user_setup( $support_user_id, $identifier_hash, $expiration_timestamp );

		if ( empty( $did_setup ) ) {
			wp_send_json_error( array( 'message' => 'Error updating user with identifier.' ), 503 );
		}

		$secret_id = $this->generate_secret_id( $identifier_hash, $endpoint );

		$return_data = array(
			'site_url'    => get_site_url(),
			'endpoint'   => $endpoint,
			'identifier' => $identifier_hash,
			'user_id'    => $support_user_id,
			'expiry'     => $expiration_timestamp,
			'access_key' => $secret_id,
			'is_ssl'     => is_ssl(),
		);

		if ( $this->config->meets_ssl_requirement() ){

			$created = false;

			try {

				$created = $this->create_secret( $secret_id, $identifier_hash );

			} catch ( Exception $e ) {

				$exception_error = new WP_Error( $e->getCode(), $e->getMessage() );

				wp_send_json_error( $exception_error, 500 );
			}

			if ( is_wp_error( $created ) ) {

				$this->log( sprintf( 'There was an issue creating access (%s): %s', $created->get_error_code(), $created->get_error_message() ), __METHOD__, 'error' );

				wp_send_json_error( $created, 503 );

			}

		}

		do_action( 'trustedlogin/' . $this->ns . '/access/created', array( 'url' => get_site_url(), 'action' => 'create' ) );

		wp_send_json_success( $return_data, 201 );

	}

	/**
	 * Returns a timestamp that is the current time + decay time setting
	 *
	 * Note: This is a server timestamp, not a WordPress timestamp
	 *
	 * @param int $decay_time If passed, override the `decay` setting
	 *
	 * @return int|false Timestamp in seconds. Default is WEEK_IN_SECONDS from creation (`time()` + 604800). False if no expiration.
	 */
	public function get_expiration_timestamp( $decay_time = null ) {

		if ( is_null( $decay_time ) ) {
			$decay_time = $this->config->get_setting( 'decay' );
		}

		if ( 0 === $decay_time ) {
			return false;
		}

		$expiration_timestamp = time() + (int) $decay_time;

		return $expiration_timestamp;
	}

	/**
	 * Updates the site's endpoint to listen for logins
	 *
	 * @param string $endpoint
	 *
	 * @return bool True: updated; False: didn't change, or didn't update
	 */
	public function update_endpoint( $endpoint ) {
		return update_option( $this->endpoint_option, $endpoint, true );
	}

	/**
	 * Generate a hash that is used to add two levels of security to the login URL:
	 * The hash is stored as usermeta, and is used when generating $secret_id.
	 * Both parts are required to access the site.
	 *
	 * @return string|WP_Error
	 */
	public function generate_identifier_hash() {

		$hash = false;

		if( function_exists( 'random_bytes' ) ) {
			try {
				$hash = random_bytes( 64 );
			} catch ( \TypeError $e ) {
				$this->logger->log( $e->getMessage(), __METHOD__, 'error' );
			} catch ( \Error $e ) {
				$this->logger->log( $e->getMessage(), __METHOD__, 'error' );
			} catch ( \Exception $e ) {
				$this->logger->log( $e->getMessage(), __METHOD__, 'error' );
			}
		} else {
			$this->logger->log( 'This site does not have the random_bytes() function.', __METHOD__, 'debug' );
		}

		if( $hash ) {
			return $hash;
		}

		if( ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return new WP_Error( 'generate_hash_failed', 'Could not generate a secure hash with random_bytes or openssl.' );
		}

		$crypto_strong = false;
		$hash = openssl_random_pseudo_bytes( 64, $crypto_strong );

		if ( ! $crypto_strong ) {
			return new WP_Error( 'openssl_not_strong_crypto', 'Site could not generate a secure hash with OpenSSL.' );
		}

		return $hash;
	}

	/**
	 * Schedules cron job to auto-revoke, adds user meta with unique ids
	 *
	 * @param int $user_id ID of generated support user
	 * @param string $identifier_hash Unique ID used by
	 * @param int $decay_timestamp Timestamp when user will be removed
	 *
	 * @return string Value of $identifier_meta_key if worked; empty string if not.
	 */
	public function support_user_setup( $user_id, $identifier_hash, $expiration_timestamp = null ) {

		if ( $expiration_timestamp ) {

			$scheduled_expiration = wp_schedule_single_event(
				$expiration_timestamp,
				'trustedlogin/' . $this->ns . '/access/revoke',
				array( $this->hash( $identifier_hash ) )
			);

			$this->log( 'Scheduled Expiration: ' . var_export( $scheduled_expiration, true ) . '; identifier: ' . $identifier_hash, __METHOD__, 'info' );

			update_user_option( $user_id, $this->expires_meta_key, $expiration_timestamp );
		}

		update_user_option( $user_id, $this->identifier_meta_key, $this->hash( $identifier_hash ), true );
		update_user_option( $user_id, $this->created_by_meta_key, get_current_user_id() );

		// Make extra sure that the identifier was saved. Otherwise, things won't work!
		return get_user_option( $this->identifier_meta_key, $user_id );
	}

	/**
	 * Register the required scripts and styles
	 *
	 * @since 0.2.0
	 */
	public function register_assets() {

		// TODO: Remove this if/when switching away from jQuery Confirm
		$default_asset_dir_url = plugin_dir_url( __FILE__ ) . 'assets/';

		$registered = array();

		$registered['jquery-confirm-css'] = wp_register_style(
			'jquery-confirm',
			$default_asset_dir_url . 'jquery-confirm/jquery-confirm.min.css',
			array(),
			self::jquery_confirm_version,
			'all'
		);

		$registered['jquery-confirm-js'] = wp_register_script(
			'jquery-confirm',
			$default_asset_dir_url . 'jquery-confirm/jquery-confirm.min.js',
			array( 'jquery' ),
			self::jquery_confirm_version,
			true
		);

		$registered['trustedlogin-js'] = wp_register_script(
			'trustedlogin',
			$this->config->get_setting( 'paths/js' ),
			array( 'jquery-confirm' ),
			self::version,
			true
		);

		$registered['trustedlogin-css'] = wp_register_style(
			'trustedlogin',
			$this->config->get_setting( 'paths/css' ),
			array( 'jquery-confirm' ),
			self::version,
			'all'
		);

		$registered = array_filter( $registered );

		if ( 4 !== count( $registered ) ) {
			$this->log( 'Not all scripts and styles were registered: ' . print_r( $registered, true ), __METHOD__, 'error' );
		}

	}

	/**
	 * Output the TrustedLogin Button and required scripts
	 *
	 * @since 0.2.0
	 *
	 * @param array $atts {@see get_button()} for configuration array
	 * @param bool $print Should results be printed and returned (true) or only returned (false)
	 *
	 * @return string the HTML output
	 */
	public function generate_button( $atts = array(), $print = true ) {

		if ( ! current_user_can( 'create_users' ) ) {
			return '';
		}

		if ( ! wp_script_is( 'trustedlogin', 'registered' ) ) {
			$this->log( 'JavaScript is not registered. Make sure `trustedlogin` handle is added to "no-conflict" plugin settings.', __METHOD__, 'error' );
		}

		if ( ! wp_style_is( 'trustedlogin', 'registered' ) ) {
			$this->log( 'Style is not registered. Make sure `trustedlogin` handle is added to "no-conflict" plugin settings.', __METHOD__, 'error' );
		}

		wp_enqueue_style( 'trustedlogin' );

		$button_settings = array(
			'vendor'   => $this->config->get_setting( 'vendor' ),
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'_nonce'   => wp_create_nonce( 'tl_nonce-' . get_current_user_id() ),
			'lang'     => array_merge( $this->output_tl_alert(), $this->output_secondary_alerts() ),
			'debug'    => $this->debug_mode,
			'selector' => '.trustedlogin–grant-access',
		);

		wp_localize_script( 'trustedlogin', 'tl_obj', $button_settings );

		wp_enqueue_script( 'trustedlogin' );

		$return = $this->get_button( $atts );

		if ( $print ) {
			echo $return;
		}

		return $return;
	}

	/**
	 * Generates HTML for a TrustedLogin Grant Access button
	 *
	 * @param array $atts {
	 *   @type string $text Button text to grant access. Sanitized using esc_html(). Default: "Grant %s Support Access"
	 *                      (%s replaced with vendor/title setting)
	 *   @type string $exists_text Button text when vendor already has a support account. Sanitized using esc_html().
	 *                      Default: "✅ %s Support Has An Account" (%s replaced with vendor/title setting)
	 *   @type string $size WordPress CSS button size. Options: 'small', 'normal', 'large', 'hero'. Default: "hero"
	 *   @type string $class CSS class added to the button. Default: "button-primary"
	 *   @type string $tag Tag used to display the button. Options: 'a', 'button', 'span'. Default: "a"
	 *   @type bool   $powered_by Whether to display the TrustedLogin badge on the button. Default: true
	 *   @type string $support_url The URL to use as a backup if JavaScript fails or isn't available. Sanitized using
	 *                      esc_url(). Default: `vendor/support_url` configuration setting URL.
	 * }
	 *
	 * @return string
	 */
	public function get_button( $atts = array() ) {

		$defaults = array(
			'text'        => sprintf( esc_html__( 'Grant %s Support Access', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) ),
			'exists_text' => sprintf( esc_html__( '✅ %s Support Has An Account', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) ),
			'size'        => 'hero',
			'class'       => 'button-primary',
			'tag'         => 'a', // "a", "button", "span"
			'powered_by'  => true,
			'support_url' => $this->config->get_setting( 'vendor/support_url' ),
		);

		$sizes = array( 'small', 'normal', 'large', 'hero' );

		$atts = wp_parse_args( $atts, $defaults );

		switch ( $atts['size'] ) {
			case '':
				$css_class = '';
				break;
			case 'normal':
				$css_class = 'button';
				break;
			default:
				if ( ! in_array( $atts['size'], $sizes ) ) {
					$atts['size'] = 'hero';
				}

				$css_class = 'trustedlogin–grant-access button button-' . $atts['size'];
		}

		$tags = array( 'a', 'button', 'span' );

		if ( ! in_array( $atts['tag'], $tags ) ) {
			$atts['tag'] = 'a';
		}

		$tag = empty( $atts['tag'] ) ? 'a' : $atts['tag'];

		$data_atts = array();

		if ( $this->support_user->get_all() ) {
			$text        			= esc_html( $atts['exists_text'] );
			$href 	     			= admin_url( 'users.php?role=' . $this->support_user->get_role_name() );
			$data_atts['accesskey'] = $this->get_accesskey(); // Add the shareable accesskey as a data attribute
		} else {
			$text      = esc_html( $atts['text'] );
			$href      = $atts['support_url'];
		}

		$css_class = implode( ' ', array( $css_class, $atts['class'] ) );

		$data_string = '';
		foreach ( $data_atts as $key => $value ){
			$data_string .= sprintf(' data-%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		$powered_by  = $atts['powered_by'] ? '<small><span class="trustedlogin-logo"></span>Powered by TrustedLogin</small>' : false;
		$anchor_html = $text . $powered_by;

		return sprintf(
			'<%1$s href="%2$s" class="%3$s button-trustedlogin" aria-role="button" %5$s>%4$s</%1$s>',
			$tag,
			esc_url( $href ),
			esc_attr( $css_class ),
			$anchor_html,
			$data_string
		);
	}

	/**
	 * Outputs table of created support users
	 *
	 * @since 0.2.1
	 *
	 * @param bool $print Whether to print and return (true) or return (false) the results. Default: true
	 *
	 * @return string HTML table of active support users for vendor. Empty string if current user can't `create_users`
	 */
	public function output_support_users( $print = true ) {

		if ( ! is_admin() || ! current_user_can( 'create_users' ) ) {
			return '';
		}

		// The `trustedlogin/{$ns}/button` action passes an empty string
		if ( '' === $print ) {
			$print = true;
		}

		$support_users = $this->support_user->get_all();

		if ( empty( $support_users ) ) {

			$return = '<h3>' . sprintf( esc_html__( 'No %s users exist.', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) ) . '</h3>';

			if ( $print ) {
				echo $return;
			}

			return $return;
		}

		$return = '';

		$return .= '<h3>' . sprintf( esc_html__( '%s users:', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) ) . '</h3>';

		$return .= '<table class="wp-list-table widefat plugins">';

		$table_header =
			sprintf( '
                <thead>
                    <tr>
                        <th scope="col">%1$s</th>
                        <th scope="col">%2$s</th>
                        <th scope="col">%3$s</th>
                        <th scope="col">%4$s</td>
                        <th scope="col">%5$s</th>
                    </tr>
                </thead>',
				esc_html__( 'User', 'trustedlogin' ),
				esc_html__( 'Created', 'trustedlogin' ),
				esc_html__( 'Expires', 'trustedlogin' ),
				esc_html__( 'Created By', 'trustedlogin' ),
				esc_html__( 'Revoke Access', 'trustedlogin' )
			);

		$return .= $table_header;

		$return .= '<tbody>';

		foreach ( $support_users as $support_user ) {

			$_user_creator = get_user_by( 'id', get_user_option( $this->created_by_meta_key, $support_user->ID ) );

			$return .= '<tr>';
			$return .= '<th scope="row"><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $support_user->ID ) ) . '">';
			$return .= sprintf( '%s (#%d)', esc_html( $support_user->display_name ), $support_user->ID );
			$return .= '</th>';

			$return .= '<td>' . sprintf( esc_html__( '%s ago', 'trustedlogin' ), human_time_diff( strtotime( $support_user->user_registered ) ) ) . '</td>';
			$return .= '<td>' . sprintf( esc_html__( 'In %s', 'trustedlogin' ), human_time_diff( get_user_option( $this->expires_meta_key, $support_user->ID ) ) ) . '</td>';

			if ( $_user_creator && $_user_creator->exists() ) {
				$return .= '<td>' . ( $_user_creator->exists() ? esc_html( $_user_creator->display_name ) : esc_html__( 'Unknown', 'trustedlogin' ) ) . '</td>';
			} else {
				$return .= '<td>' . esc_html__( 'Unknown', 'trustedlogin' ) . '</td>';
			}

			if ( $revoke_url = $this->helper_get_user_revoke_url( $support_user ) ) {
				$return .= '<td><a class="trustedlogin tl-revoke submitdelete" href="' . esc_url( $revoke_url ) . '">' . esc_html__( 'Revoke Access', 'trustedlogin' ) . '</a></td>';
			} else {
				$return .= '<td><a href="' . esc_url( admin_url( 'users.php?role=' . $this->support_user->get_role_name() ) ) . '">' . esc_html__( 'Manage from Users list', 'trustedlogin' ) . '</a></td>';
			}
			$return .= '</tr>';

		}

		$return .= '</tbody></table>';

		if ( $print ) {
			echo $return;
		}


		return $return;
	}

	/**
	 * Generates the HTML strings for the Confirmation dialogues
	 *
	 * @since 0.2.0
	 * @since 0.9.2 added excluded_caps output
	 *
	 * @return string[] Array containing 'intro', 'description' and 'detail' keys.
	 */
	public function output_tl_alert() {

		$result = array();

		$result['intro'] = sprintf(
			__( 'Grant %1$s Support access to your site.', 'trustedlogin' ),
			$this->config->get_setting( 'vendor/title' )
		);

		$result['description'] = sprintf( '<p class="description">%1$s</p>',
			__( 'By clicking Confirm, the following will happen automatically:', 'trustedlogin' )
		);

		// Roles
		$roles_output = '';
		$roles_output .= sprintf( '<li class="tl-role"><p>%1$s</p></li>',
			sprintf( esc_html__( 'A new user will be created with a custom role \'%1$s\' (with the same capabilities as %2$s).', 'trustedlogin' ),
				$this->support_user->get_role_name(),
				$this->config->get_setting( 'role' )
			)
		);

		$result['roles'] = $roles_output;

		// Extra Caps
		$caps_output = '';
		foreach ( (array) $this->config->get_setting( 'caps/add' ) as $cap => $reason ) {
			$caps_output .= sprintf( '<li class="caps-added"> %1$s <br /><small>%2$s</small></li>',
				sprintf( esc_html__( 'With the additional \'%1$s\' Capability.', 'trustedlogin' ),
					$cap
				),
				$reason
			);
		}
		foreach ( (array) $this->config->get_setting( 'caps/remove' ) as $cap => $reason ) {
			$caps_output .= sprintf( '<li class="caps-removed"> %1$s <br /><small>%2$s</small></li>',
				sprintf( esc_html__( 'The \'%1$s\' Capability will not be granted.', 'trustedlogin' ),
					$cap
				),
				$reason
			);
		}
		$result['caps'] = $caps_output;

		// Decay
		if ( $decay_time = $this->get_expiration_timestamp() ) {

			$decay_diff = human_time_diff( $decay_time );

			$decay_tag = apply_filters('trustedlogin/' . $this->ns . '/template/tags/decay','h4');
			$decay_output = '<'.$decay_tag.'>' . sprintf( esc_html__( 'Access will be granted for %1$s and can be revoked at any time.', 'trustedlogin' ), $decay_diff ) . '</'.$decay_tag.'>';
		} else {
			$decay_output = '';
		}

		$details_output = sprintf(
			wp_kses(
				apply_filters(
					'trustedlogin/' . $this->ns . '/template/details',
					'<ul class="tl-details tl-roles">%1$s</ul><ul class="tl-details tl-caps">%2$s</ul>%3$s'
				),
				array(
					'ul'    => array( 'class' => array(), 'id' => array() ),
					'li'    => array( 'class' => array(), 'id' => array() ),
					'p'     => array( 'class' => array(), 'id' => array() ),
					'h1'    => array( 'class' => array(), 'id' => array() ),
					'h2'    => array( 'class' => array(), 'id' => array() ),
					'h3'    => array( 'class' => array(), 'id' => array() ),
					'h4'    => array( 'class' => array(), 'id' => array() ),
					'h5'    => array( 'class' => array(), 'id' => array() ),
					'div'   => array( 'class' => array(), 'id' => array() ),
					'br'    => array(),
					'strong'=> array(),
					'em'    => array(),
				)
			),
			$roles_output,
			$caps_output,
			$decay_output
		);


		$result['details'] = $details_output;

		return $result;

	}

	/**
	 * Helper function: Build translate-able strings for alert messages
	 *
	 * @since 0.4.3
	 *
	 * @return array of Translations and strings to be localized to JS variables
	 */
	public function output_secondary_alerts() {

		$vendor_title = $this->config->get_setting( 'vendor/title' );

		/**
		 * Filter: Allow for adding into GET parameters on support_url
		 *
		 * @since 0.4.3
		 *
		 * ```
		 * $url_query_args = [
		 *   'message' => (string) What error should be sent to the support system.
		 * ];
		 * ```
		 *
		 * @param array $url_query_args {
		 *   @type string $message What error should be sent to the support system.
		 * }
		 */
		$query_args = apply_filters( 'trustedlogin/' . $this->ns . '/support_url/query_args',	array(
				'message' => __( 'Could not create TrustedLogin access.', 'trustedlogin' )
			)
		);

		$error_content = sprintf( '<p>%s</p><p>%s</p>',
			sprintf(
				esc_html__( 'Unfortunately, the Support User details could not be sent to %1$s automatically.', 'trustedlogin' ),
				$vendor_title
			),
			sprintf(
				__( 'Please <a href="%1$s" target="_blank">click here</a> to go to the %2$s Support Site', 'trustedlogin' ),
				esc_url( add_query_arg( $query_args, $this->config->get_setting( 'vendor/support_url' ) ) ),
				$vendor_title
			)
		);

		$secondary_alert_translations = array(
			'buttons' => array(
				'confirm' => esc_html__( 'Confirm', 'trustedlogin' ),
				'ok' => esc_html__( 'Ok', 'trustedlogin' ),
				'go_to_site' =>  sprintf( __( 'Go to %1$s support site', 'trustedlogin' ), $vendor_title ),
				'close' => esc_html__( 'Close', 'trustedlogin' ),
				'cancel' => esc_html__( 'Cancel', 'trustedlogin' ),
				'revoke' => sprintf( __( 'Revoke %1$s support access', 'trustedlogin' ), $vendor_title ),
			),
			'status' => array(
				'synced' => array(
					'title' => esc_html__( 'Support access granted', 'trustedlogin' ),
					'content' => sprintf(
						__( 'A temporary support user has been created, and sent to %1$s Support.', 'trustedlogin' ),
						$vendor_title
					),
				),
				'error' => array(
					'title' => sprintf( __( 'Error syncing Support User to %1$s', 'trustedlogin' ), $vendor_title ),
					'content' => wp_kses( $error_content, array( 'a' => array( 'href' => array() ), 'p' => array() ) ),
				),
				'cancel' => array(
					'title' => esc_html__( 'Action Cancelled', 'trustedlogin' ),
					'content' => sprintf(
						__( 'A support account for %1$s has NOT been created.', 'trustedlogin' ),
						$vendor_title
					),
				),
				'failed' => array(
					'title' => esc_html__( 'Support Access Was Not Granted', 'trustedlogin' ),
					'content' => esc_html__( 'Got this from the server: ', 'trustedlogin' ),
				),
				'accesskey' => array(
					'title' => esc_html__( 'TrustedLogin Key Created', 'trustedlogin' ),
					'content' => sprintf(
						__( 'Share this TrustedLogin Key with %1$s to give them secure access:', 'trustedlogin' ),
						$vendor_title
					),
					'revoke_link' => esc_url( add_query_arg( array( 'revoke-tl' => $this->ns ), admin_url( 'users.php' ) ) ),
				),
				'error409' => array(
					'title' => sprintf(
						__( '%1$s Support User already exists', 'trustedlogin' ),
						$vendor_title
					),
					'content' => sprintf(
						wp_kses(
							__( 'A support user for %1$s already exists. You can revoke this support access from your <a href="%2$s" target="_blank">Users list</a>.', 'trustedlogin' ),
							array( 'a' => array( 'href' => array(), 'target' => array() ) )
						),
						$vendor_title,
						esc_url( admin_url( 'users.php?role=' . $this->support_user->get_role_name() ) )
					),
				),
			),
		);

		return $secondary_alert_translations;
	}

	protected function init_properties( $config ) {

		$this->config = $config;


		$this->ns = $this->config->ns();

		/**
		 * Filter: Whether debug logging is enabled in TrustedLogin Client
		 *
		 * @since 0.4.2
		 *
		 * @param bool $debug_mode Default: false
		 */
		$this->debug_mode = apply_filters( 'trustedlogin/' . $this->ns . '/debug/enabled', $this->config->get_setting( 'debug' ) );


		/**
		 * Filter: Set endpoint setting name
		 *
		 * @since 0.3.0
		 *
		 * @param string
		 * @param Client $this
		 */
		$this->endpoint_option = apply_filters(
			'trustedlogin/' . $this->ns . '/options/endpoint',
			'tl_' . $this->ns . '_endpoint',
			$this
		);

		/**
		 * Filter: Sets the site option name for the Public Key for encryption functions
		 *
		 * @since 0.5.0
		 *
		 * @param string $public_key_option
		 * @param Client $this
		 */
		$this->public_key_option = apply_filters(
			'trustedlogin/' . $this->ns . '/options/public_key',
			'tl_' . $this->ns . '_public_key',
			$this
		);

		$this->identifier_meta_key = 'tl_' . $this->ns . '_id';
		$this->expires_meta_key    = 'tl_' . $this->ns . '_expires';
		$this->created_by_meta_key = 'tl_' . $this->ns . '_created_by';

		/**
		 * Filter: Sets the site option name for the Shareable accessKey if it's used
		 *
		 * @since 0.9.2
		 *
		 * @param string $sharable_accesskey_option
		 * @param Client $this
		 */
		$this->sharable_accesskey_option = apply_filters(
			'trustedlogin/' . $this->ns . '/options/sharable_accesskey',
			'tl_' . $this->ns . '_sharable_accesskey',
			$this
		);
	}

	/**
	 * Alias for Config::get_setting()
	 *
	 * @see \TrustedLogin\Config::get_setting()
	 *
	 * @param string $key The setting to fetch, nested results are delimited with forward slashes (eg vendor/name => settings['vendor']['name'])
	 * @param mixed $default - if no setting found or settings not init, return this value.
	 * @param array $settings Pass an array to fetch value for instead of using the default settings array
	 *
	 * @return string|array
	 */
	public function get_setting( $key, $default = null, $settings = array() ) {
		return $this->config->get_setting( $key, $default, $settings );
	}

	/**
	 * Get the ID of the best-guess appropriate admin user
	 *
	 * @since 0.7.0
	 *
	 * @return int|null User ID if there are admins, null if not
	 */
	private function get_reassign_user_id() {

		if( ! $this->config->get_setting( 'reassign_posts' ) ) {
			return null;
		}

		// TODO: Filter here?
		$admins = get_users( array(
			'role'    => 'administrator',
			'orderby' => 'registered',
			'order'   => 'DESC',
			'number'  => 1,
		) );

		$reassign_id = empty( $admins ) ? null : $admins[0]->ID;

		$this->log( 'Reassign user ID: ' . var_export( $reassign_id, true ), __METHOD__, 'info' );

		return $reassign_id;
	}



	/**
	 * Generate the endpoint parameter as a hash of the site URL with the identifier
	 *
	 * @param $identifier_hash
	 *
	 * @return string This hash will be used as the first part of the URL and also a part of $secret_id
	 */
	private function get_endpoint_hash( $identifier_hash ) {
		return $this->hash( get_site_url() . $identifier_hash );
	}

	/**
	 * Generate the secret_id parameter as a hash of the endpoint with the identifier
	 *
	 * @param string $identifier_hash
	 * @param string $endpoint_hash
	 *
	 * @return string This hash will be used as an identifier in TrustedLogin SaaS
	 */
	private function generate_secret_id( $identifier_hash, $endpoint_hash = '' ) {

		if ( empty( $endpoint_hash ) ) {
			$endpoint_hash = $this->get_endpoint_hash( $identifier_hash );
		}

		return $this->hash( $endpoint_hash . $identifier_hash );
	}

	/**
	 * @param $string
	 *
	 * @return string
	 */
	private function hash( $string ) {
		return md5( $string );
	}

	/**
	 * Hooked Action: Revokes access for a specific support user
	 *
	 * @since 0.2.1
	 *
	 * @param string $identifier_hash Identifier hash for the user associated with the cron job
	 *
	 * @return void
	 */
	public function cron_revoke_access( $identifier_hash ) {

		$this->log( 'Running cron job to disable user. ID: ' . $identifier_hash, __METHOD__, 'notice' );

		$this->support_user->delete( $identifier_hash );
	}


	/**
	 * Adds a "Revoke TrustedLogin" menu item to the admin toolbar
	 *
	 * @param WP_Admin_Bar $admin_bar
	 *
	 * @return void
	 */
	public function admin_bar_add_toolbar_items( $admin_bar ) {

		if ( ! current_user_can( $this->support_user->get_role_name() ) ) {
			return;
		}

		if ( ! $admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		$admin_bar->add_menu( array(
			'id'    => 'tl-' . $this->ns . '-revoke',
			'title' => esc_html__( 'Revoke TrustedLogin', 'trustedlogin' ),
			'href'  => admin_url( '/?revoke-tl=' . $this->ns ),
			'meta'  => array(
				'title' => esc_html__( 'Revoke TrustedLogin', 'trustedlogin' ),
				'class' => 'tl-destroy-session',
			),
		) );
	}

	/**
	 * Filter: Update the actions on the users.php list for our support users.
	 *
	 * @since 0.3.0
	 *
	 * @param array $actions
	 * @param WP_User $user_object
	 *
	 * @return array
	 */
	public function user_row_action_revoke( $actions, $user_object ) {

		if ( ! current_user_can( $this->support_user->get_role_name() ) && ! current_user_can( 'delete_users' ) ) {
			return $actions;
		}

		$revoke_url = $this->helper_get_user_revoke_url( $user_object );

		if ( ! $revoke_url ) {
			return $actions;
		}

		$actions = array(
			'revoke' => "<a class='trustedlogin tl-revoke submitdelete' href='" . esc_url( $revoke_url ) . "'>" . esc_html__( 'Revoke Access', 'trustedlogin' ) . "</a>",
		);

		return $actions;
	}

	/**
	 * Returns admin URL to revoke support user
	 *
	 * @param WP_User $user_object
	 *
	 * @return string|false Unsanitized URL to revoke support user. If $user_object is not WP_User, or no user meta exists, returns false.
	 */
	public function helper_get_user_revoke_url( $user_object ) {

		if ( ! $user_object instanceof WP_User ) {
			$this->log( '$user_object not a user object: ' . var_export( $user_object ), __METHOD__, 'warning' );

			return false;
		}

		if ( empty( $this->identifier_meta_key ) ) {
			$this->log( 'The meta key to identify users is not set.', __METHOD__, 'error' );

			return false;
		}

		$identifier = get_user_option( $this->identifier_meta_key, $user_object->ID );

		if ( empty( $identifier ) ) {
			return false;
		}

		$revoke_url = add_query_arg( array(
			'revoke-tl' => $this->ns,
			'tlid'      => $identifier,
		), admin_url( 'users.php' ) );

		$this->log( "revoke_url: $revoke_url", __METHOD__, 'debug' );

		return $revoke_url;
	}

	/**
	 * Hooked Action to maybe revoke support if $_GET['revoke-tl'] == {namespace}
	 * Can optionally check for _GET['tlid'] for revoking a specific user by their identifier
	 *
	 * @since 0.2.1
	 */
	public function admin_maybe_revoke_support() {

		if ( ! isset( $_GET['revoke-tl'] ) || $this->ns !== $_GET['revoke-tl'] ) {
			return;
		}

		// Allow support team to revoke user
		if ( ! current_user_can( $this->support_user->get_role_name() ) && ! current_user_can( 'delete_users' ) ) {
			return;
		}

		if ( isset( $_GET['tlid'] ) ) {
			$identifier = sanitize_text_field( $_GET['tlid'] );
		} else {
			$identifier = 'all';
		}

		$deleted_user = $this->support_user->delete( $identifier );

		if ( is_wp_error( $deleted_user ) ) {
			$this->log( 'Removing user failed: ' . $deleted_user->get_error_message(), __METHOD__, 'error' );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'delete_users' ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$support_user = $this->support_user->get( $identifier );

		if ( ! empty( $support_user ) ) {
			$this->log( 'User #' . $support_user[0]->ID . ' was not removed', __METHOD__, 'error' );

			return;
		}

		add_action( 'admin_notices', array( $this, 'admin_notice_revoked' ) );
	}

	/**
	 * POSTs to `webhook_url`, if defined in the configuration array
	 *
	 * @since 0.3.1
	 *
	 * @param array $data {
	 *   @type string $url The site URL as returned by get_site_url()
	 *   @type string $action "create" or "revoke"
	 * }
	 *
	 * @return void
	 */
	public function maybe_send_webhook( $data ) {

		$webhook_url = $this->config->get_setting( 'webhook_url' );

		if ( ! $webhook_url ) {
			return;
		}

		if ( ! wp_http_validate_url( $webhook_url ) ) {
			$this->log( 'An invalid `webhook_url` setting was passed to the TrustedLogin Client: ' . esc_attr( $webhook_url ), __METHOD__, 'error' );
			return;
		}

		wp_remote_post( $webhook_url, $data );
	}

	/**
	 * Handles the syncing of newly generated support access to the TrustedLogin servers.
	 *
	 * @param string $secret_id  The unique identifier for this TrustedLogin authorization.
	 * @param string $identifier The unique identifier for the WP_User created
	 *
	 * @return true|WP_Error True if successfully created secret on TrustedLogin servers; WP_Error if failed.
	 */
	public function create_secret( $secret_id, $identifier ) {

		// Ping SaaS and get back tokens.
		$envelope = $this->get_envelope( $secret_id, $identifier );

		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		$api_response = $this->api_send( 'sites', $envelope, 'POST' );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		$response_json = $this->handle_response( $api_response, array( 'success' ) );

		if ( is_wp_error( $response_json ) ) {
			return $response_json;
		}

		if ( empty( $response_json['success'] ) ) {
			return new WP_Error( 'sync_error', __( 'Could not sync to TrustedLogin server', 'trustedlogin' ) );
		}

		do_action( 'trustedlogin/' . $this->ns . '/secret/created', array( 'url' => get_site_url(), 'action' => 'create' ) );

		return true;
	}

	/**
	 * Revoke access to a site
	 *
	 * @param string $identifier Unique ID or "all"
	 *
	 * @return bool|WP_Error True: Synced to SaaS. False: empty identifier. WP_Error: failed to revoke site in SaaS.
	 */
	public function revoke_access( $identifier = '' ) {

		if ( empty( $identifier ) ) {
			$this->log( "Missing the revoke access identifier.", __METHOD__, 'error' );

			return false;
		}

		$endpoint_hash = $this->get_endpoint_hash( $identifier );

		// Revoke site in SaaS
		$site_revoked = $this->revoke_site( $endpoint_hash );

		if ( is_wp_error( $site_revoked ) ) {

			// Couldn't sync to SaaS, this should/could be extended to add a cron-task to delayed update of SaaS DB
			// TODO: extend to add a cron-task to delayed update of SaaS DB
			$this->log( "There was an issue syncing to SaaS. Failing silently.", __METHOD__, 'error' );

			return $site_revoked;
		}

		do_action( 'trustedlogin/' . $this->ns . '/access/revoked', array( 'url' => get_site_url(), 'action' => 'revoke' ) );

		return $site_revoked;
	}

	/**
	 * Get the license key for the current user.
	 *
	 * @since 0.7.0
	 *
	 * @return string
	 */
	function get_license_key() {

		// if no license key proivded, assume false, and then return accessKey
		$license_key = $this->config->get_setting( 'auth/license_key', false );

		if ( ! $license_key ){
			$license_key = $this->get_shareable_accesskey();
		}

		/**
		 * Filter: Allow for over-riding the 'accessKey' sent to SaaS platform
		 *
		 * @since 0.4.0
		 *
		 * @param string|null $license_key
		 */
		$license_key = apply_filters( 'trustedlogin/' . $this->ns . '/licence_key', $license_key );

		return $license_key;
	}

	/**
	 * Generates an accessKey that can be copy-pasted to support to give them access via TrustedLogin
	 *
	 * Access Keys can only be used by authenticated support agents to request logged access to a site via their TrustedLogin plugin.
	 *
	 * @since 0.9.2
	 *
	 * @return  string  Access Key prepended with TL.
	 */
	private function get_shareable_accesskey(){

		$hash = $this->hash( get_site_url() . $this->config->get_setting( 'auth/public_key' ) );

		/**
		 * Filter: Allow for over-riding the shareable 'accessKey' prefix
		 *
		 * @since 0.9.2
		 */
		$access_key_prefix  = apply_filters( 'trustedlogin/' . $this->ns . '/access_key_prefix' , 'TL.');

		$length 			= strlen( $access_key_prefix );
		$access_key 		= $access_key_prefix . substr( $hash, $length );

		update_site_option( $this->sharable_accesskey_option, $access_key );

		return $access_key;
	}

	/**
	 * Checks if a license key is a shareable accessKey
	 *
	 * @todo This isn't being used. Hector, what's this for?
	 *
	 * @since 0.9.2
	 *
	 * @param string  $license
	 *
	 * @return bool
	 */
	private function is_shareable_accesskey( $license ){

		/**
		 * Filter: Allow for over-riding the shareable 'accessKey' prefix
		 *
		 * @since 0.9.2
		 */
		$access_key_prefix  = apply_filters( 'trustedlogin/' . $this->ns . '/access_key_prefix' , 'TL.');
		$length 			= strlen( $access_key_prefix );

		return ( substr( $license , 0, $length ) === $access_key_prefix );

	}

	/**
	 * Gets the shareable accessKey, if it's been generated.
	 *
	 * For licensed plugins or themes, a customer's license key is the access key.
	 * For plugins or themes withouth license keys, the accessKey is generated for the site.
	 *
	 * @since 0.9.2
	 *
	 * @return string $access_key
	 */
	public function get_accesskey(){

		$access_key = get_site_option( $this->sharable_accesskey_option, false );

		if ( $access_key ){
			return $access_key;
		}

		return $this->config->get_setting( 'auth/license_key', false );

	}

	/**
	 * Creates a site in TrustedLogin using the $secret_id hash as the ID
	 *
	 * @uses get_encryption_key() to get the Public Key.
	 * @uses get_license_key() to get the current site's license key.
	 * @uses encrypt() to securely encrypt values before sending.
	 *
	 * @param string $secret_id  The Unique ID used across the site and TrustedLogin
	 * @param string $identifier Unique ID for the WP_User generated
	 *
	 * @return array|WP_Error Returns array of data to be sent to TL. If public key not fetched, returns WP_Error.
	 */
	public function get_envelope( $secret_id, $identifier ) {

		/**
		 * Filter: Override the public key functions.
		 *
		 * @since 0.5.0
		 *
		 * @param string $encryption_key
		 * @param Client $this
		 */
		$encryption_key = apply_filters( 'trustedlogin/' . $this->ns . '/public_key', $this->get_encryption_key(), $this );

		if ( is_wp_error( $encryption_key ) ) {
			return new WP_Error(
				'no_key',
				sprintf(
					'No public key has been provided by %1$s: %2$s',
					$this->config->get_setting( 'vendor/title' ),
					$encryption_key->get_error_message()
				)
			);
		}

		$e_identifier = $this->encrypt( $identifier, $encryption_key );

		if ( is_wp_error( $e_identifier ) ) {
			return $e_identifier;
		}

		$e_site_url = $this->encrypt( get_site_url(), $encryption_key );

		if( is_wp_error( $e_site_url ) ) {
			return $e_site_url;
		}

		$envelope = array(
			'secretId'   => $secret_id,
			'identifier' => $e_identifier,
			'publicKey'  => $this->config->get_setting( 'auth/public_key' ),
			'accessKey'  => $this->get_license_key(),
			'siteUrl'    => $e_site_url,
			'userId'     => get_current_user_id(),
			'version'    => self::version,
		);

		return $envelope;
	}

	/**
	 * Revoke a site in TrustedLogin
	 *
	 * @since 0.4.1
	 *
	 * @param string $identifier - the unique identifier of the entry in the Vault Keystore
	 *
	 * @return true|WP_Error Was the sync to TrustedLogin successful
	 */
	public function revoke_site( $identifier ) {

		if ( ! $this->config->meets_ssl_requirement() ){
			$this->log( 'Not notifying TrustedLogin about revoked site due to SSL requirements.', __METHOD__, 'info' );
			return true;
		}

		$body = array(
			'publicKey' => $this->config->get_setting( 'auth/public_key' ),
		);

		$api_response = $this->api_send(  'sites/' . $identifier, $body, 'DELETE' );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		$response = $this->handle_response( $api_response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		delete_site_option( $this->sharable_accesskey_option );

		return true;
	}

	/**
	 * API Response Handler - SaaS side
	 *
	 * @since 0.4.1
	 *
	 * @param array|WP_Error $api_response - the response from HTTP API
	 * @param array $required_keys If the response JSON must have specific keys in it, pass them here
	 *
	 * @return array|WP_Error - If successful response, returns array of JSON data. If failed, returns WP_Error.
	 */
	public function handle_response( $api_response, $required_keys = array() ) {

		if ( is_wp_error( $api_response ) ) {

			$this->log( sprintf( 'Request error (Code %s): %s', $api_response->get_error_code(), $api_response->get_error_message() ), __METHOD__, 'error' );

			return $api_response;
		}

		$this->log( "Response: " . print_r( $api_response, true ), __METHOD__, 'error' );

		$response_body = wp_remote_retrieve_body( $api_response );

		if ( empty( $response_body ) ) {
			$this->log( "Response body not set: " . print_r( $response_body, true ), __METHOD__, 'error' );

			return new WP_Error( 'missing_response_body', 'The response was invalid.', $api_response );
		}

		switch ( wp_remote_retrieve_response_code( $api_response ) ) {

			// Unauthenticated
			case 401:
				return new WP_Error( 'unauthenticated', 'Authentication failed.', $response_body );
				break;

			// Problem with Token
			case 403:
				return new WP_Error( 'invalid_token', 'Invalid tokens.', $response_body );
				break;

			// the KV store was not found, possible issue with endpoint
			case 404:
				return new WP_Error( 'not_found', 'The TrustedLogin site was not found.', $response_body );
				break;

			// Server issue
			case 500:
				return new WP_Error( 'unavailable', 'The TrustedLogin site is not currently available.', $response_body );
				break;

			// wp_remote_retrieve_response_code() couldn't parse the $api_response
			case '':
				return new WP_Error( 'invalid_response', 'Invalid response.', $response_body );
				break;
		}

		$response_json = json_decode( $response_body, true );

		if ( empty( $response_json ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response.', $response_body );
		}

		foreach ( (array) $required_keys as $required_key ) {
			if ( ! isset( $response_json[ $required_key ] ) ) {
				return new WP_Error( 'missing_required_key', 'Invalid response. Missing key: ' . $required_key, $response_body );
			}
		}

		return $response_json;
	}

	/**
	 * API Function: send the API request
	 *
	 * @since 0.4.0
	 *
	 * @param string $path - the path for the REST API request (no initial or trailing slash needed)
	 * @param array $data Data passed as JSON-encoded body for
	 * @param string $method
	 * @param array $additional_headers - any additional headers required for auth/etc
	 *
	 * @return array|WP_Error|false wp_remote_post() response, or false if `$method` isn't valid
	 */
	public function api_send( $path, $data, $method = 'POST', $additional_headers = array() ) {

		$method = strtoupper( $method );

		if ( ! in_array( $method, array( 'POST', 'PUT', 'GET', 'HEAD', 'PUSH', 'DELETE' ), true ) ) {
			$this->log( sprintf( 'Error: Method not in allowed array list (%s)', esc_attr( $method ) ), __METHOD__, 'critical' );

			return new WP_Error( 'invalid_method', sprintf( 'Error: HTTP method "%s "not in allowed', esc_attr( $method ) ) );
		}

		$headers = array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->config->get_setting( 'auth/public_key' ),
		);

		if ( ! empty( $additional_headers ) ) {
			$headers = array_merge( $headers, $additional_headers );
		}

		$request_options = array(
			'method'      => $method,
			'timeout'     => 45,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);

		if ( ! empty( $data ) && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$request_options['body'] = json_encode( $data );
		}

		$api_url = $this->build_api_url( $path );

		$this->log( sprintf( 'Sending to %s: %s', $api_url, print_r( $request_options, true ) ), __METHOD__, 'debug' );

		$response = wp_remote_request( $api_url, $request_options );

		$this->log( sprintf( 'Response: %s', print_r( $response, true ) ), __METHOD__, 'debug' );

		return $response;
	}

	/**
	 * Builds URL to API endpoints
	 *
	 * @since 0.9.3
	 *
	 * @param string $endpoint Endpoint to hit on the API; example "sites" or "sites/{$site_identifier}"
	 *
	 * @return string
	 */
	private function build_api_url( $endpoint = '' ) {

		/**
		 * Modifies the endpoint URL for the TrustedLogin service.
		 *
		 * @param string $url URL to TrustedLogin API
		 *
		 * @internal This allows pointing requests to testing servers
		 */
		$base_url = apply_filters( 'trustedlogin/' . $this->ns . '/api_url', self::saas_api_url );

		if ( is_string( $endpoint ) ) {
			$url = trailingslashit( $base_url ) . $endpoint;
		} else {
			$url = trailingslashit( $base_url );
		}

		return $url;
	}

	/**
	 * Notice: Shown when a support user is manually revoked by admin;
	 *
	 * @since 0.3.0
	 */
	public function admin_notice_revoked() {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( sprintf( __( 'Done! %s Support access revoked. ', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Generates the auth link page
	 *
	 * This simulates the addition of an admin submenu item with null as the menu location
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function admin_menu_auth_link_page() {

		$ns = $this->config->get_setting( 'vendor/namespace' );

		$slug = apply_filters( 'trustedlogin/' . $this->ns . '/admin/grantaccess/slug', 'grant-' . $ns . '-access', $ns );

		$parent_slug = $this->config->get_setting( 'menu/slug', null );

		$menu_title = $this->config->get_setting( 'menu/title', esc_html__( 'Grant Support Access', 'trustedlogin' ) );

		add_submenu_page(
			$parent_slug,
			$menu_title,
			$menu_title,
			'create_users',
			$slug,
			array( $this, 'print_auth_screen' )
		);
	}

	/**
	 * Outputs the TrustedLogin authorization screen
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function print_auth_screen() {
		echo $this->get_auth_screen();
	}

	/**
	 * Output the contents of the Auth Link Page in wp-admin
	 *
	 * @since 0.5.0
	 *
	 * @return string HTML of the Auth screen
	 */
	public function get_auth_screen() {

		$output_lang = $this->output_tl_alert();
		$ns          = $this->config->get_setting( 'vendor/namespace' );

		$logo_output = '';
		$logo_url = $this->config->get_setting( 'vendor/logo_url' );

		if ( ! empty( $logo_url ) ) {

			$logo_output = sprintf(
				'<a href="%1$s" title="%2$s" target="_blank" rel="noreferrer noopener"><img class="tl-auth-logo" src="%3$s" alt="%4$s" /></a>',
				esc_url( $this->config->get_setting( 'vendor/website' ) ),
				esc_attr( sprintf( __( 'Grant %1$s Support access to your site.', 'trustedlogin' ), $this->config->get_setting( 'vendor/title' ) ) ),
				esc_url( $this->config->get_setting( 'vendor/logo_url' ) ),
				esc_attr( $this->config->get_setting( 'vendor/title' ) )
			);
		}

		$intro_output = sprintf( '<div class="intro">%s</div>', $output_lang['intro'] );

		$description_output = $output_lang['description'];

		$details_output = '<div class="tl-details tl-roles">' . wpautop( $output_lang['roles'] ) . '</div>';

		if( $caps = $this->config->get_setting( 'caps' ) ) {
			$details_output .= sprintf(
				'<div class="tl-toggle-caps"><p>%2$s</p></div><ul class="tl-details caps hidden">%3$s</ul>',
				sprintf( '%s <span class="dashicons dashicons-arrow-down-alt2"></span>', __( 'With a few more capabilities', 'trustedlogin' ) ),
				$output_lang['caps']
			);
		}

		$actions_output = $this->generate_button( "size=hero&class=authlink button-primary", false );

		/**
		 * Filter trustedlogin/template/grantlink/footer-links
		 *
		 * Used to add/remove Footer Links on grantlink page
		 *
		 * @since 0.5.0
		 *
		 * @param array - Title (string) => Url (string) pairs for building links
		 * @param string $ns - the namespace of the plugin initializing TrustedLogin
		 **/
		$footer_links = apply_filters(
			'trustedlogin/' . $this->ns . '/template/grantlink/footer_links',
			array(
				__( 'Learn about TrustedLogin', 'trustedlogin' )                    => 'https://www.trustedlogin.com/about/easy-and-safe/',
				sprintf( 'Visit %s Support', $this->config->get_setting( 'vendor/title' ) ) => $this->config->get_setting( 'vendor/support_url' ),
			),
			$ns
		);


		$footer_links_output = '';
		foreach ( $footer_links as $text => $link ) {
			$footer_links_output .= sprintf( '<li class="tl-footer-link"><a href="%1$s">%2$s</a></li>',
				esc_url( $link ),
				esc_html( $text )
			);
		}

		if ( ! empty( $footer_links_output ) ) {
			$footer_output = sprintf( '<ul>%1$s</ul>', $footer_links_output );
		} else {
			$footer_output = '';
		}

		$output_html = '
            <{{outerTag}} id="trustedlogin-auth" class="%1$s">
                <{{innerTag}} class="tl-auth-header">
                    %2$s
                    <{{innerTag}} class="tl-auth-intro">%3$s</{{innerTag}}>
                </{{innerTag}}>
                <{{innerTag}} class="tl-auth-body">
                    %4$s
                    %5$s
                </{{innerTag}}>
                <{{innerTag}} class="tl-auth-actions">
                    %6$s
                </{{innerTag}}>
                <{{innerTag}} class="tl-auth-footer">
                    %7$s
                </{{innerTag}}>
            </{{outerTag}}>
        ';

		/**
		 * Filters trustedlogin/{$this->ns}/template/grantlink/outer_tag and /trustedlogin/template/grantlink/inner_tag
		 *
		 * Used to change the innerTags and outerTags of the grandlink template
		 *
		 * @since 0.5.0
		 *
		 * @param string the html tag to use for each tag, default: div
		 * @param string $ns - the namespace of the plugin. initializing TrustedLogin
		 **/
		$output_html = str_replace( '{{outerTag}}', apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/outer-tag', 'div', $ns ), $output_html );
		$output_html = str_replace( '{{innerTag}}', apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/inner-tag', 'div', $ns ), $output_html );

		$output_template = sprintf(
			wp_kses(
			/**
			 * Filter trustedlogin/template/grantlink and trustedlogin/template/grantlink/*
			 *
			 * Manipulate the output template used to display instructions and details to WP admins
			 * when they've clicked on a direct link to grant TrustedLogin access.
			 *
			 * @since 0.5.0
			 *
			 * @param string $output_html
			 * @param string $ns - the namespace of the plugin. initializing TrustedLogin
			 **/
				apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink', $output_html, $ns ),
				array(
					'ul'     => array( 'class' => array(), 'id' => array() ),
					'p'      => array( 'class' => array(), 'id' => array() ),
					'h1'     => array( 'class' => array(), 'id' => array() ),
					'h2'     => array( 'class' => array(), 'id' => array() ),
					'h3'     => array( 'class' => array(), 'id' => array() ),
					'h4'     => array( 'class' => array(), 'id' => array() ),
					'h5'     => array( 'class' => array(), 'id' => array() ),
					'div'    => array( 'class' => array(), 'id' => array() ),
					'br'     => array(),
					'strong' => array(),
					'em'     => array(),
					'a'      => array( 'class' => array(), 'id' => array(), 'href' => array(), 'title' => array() ),
				)
			),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/outer_class', '', $ns ),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/logo', $logo_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/intro', $intro_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/details', $description_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/details', $details_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/actions', $actions_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->ns . '/template/grantlink/footer', $footer_output, $ns )
		);

		return $output_template;
	}

	/**
	 * Saves the Public key from Vendor to the Local DB
	 *
	 * @since 0.5.0
	 *
	 * @param string $public_key
	 *
	 * @return true|WP_Error  True if successful, otherwise WP_Error
	 */
	private function update_encryption_key( $public_key ) {

		if ( empty( $public_key ) ) {
			return new WP_Error( 'no_public_key', 'No key provided.' );
		}

		$saved = update_site_option( $this->public_key_option, $public_key );

		if ( ! $saved ) {
			return new WP_Error( 'db_save_error', 'Could not save key to database' );
		}

		return true;
	}

	/**
	 * Fetches the Public Key from the `TrustedLogin-vendor` plugin on support website.
	 *
	 * @since 0.5.0
	 *
	 * @return string|WP_Error  If successful, will return the Public Key string. Otherwise WP_Error on failure.
	 */
	private function get_remote_encryption_key() {

		$vendor_url   = $this->config->get_setting( 'vendor/website' );

		/**
		 * @param string $key_endpoint Endpoint path on vendor (software vendor's) site
		 */
		$key_endpoint = apply_filters( 'trustedlogin/' . $this->config->ns() . '/vendor/public_key/endpoint', 'wp-json/trustedlogin/v1/public_key' );

		$url = trailingslashit( $vendor_url ) . $key_endpoint;

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		$request_options = array(
			'method'      => 'GET',
			'timeout'     => 45,
			'httpversion' => '1.1',
			'headers'     => $headers
		);

		$response = wp_remote_request( $url, $request_options );

		$response_json = $this->handle_response( $response, array( 'publicKey' ) );

		if ( is_wp_error( $response_json ) ) {
			return $response_json;
		}

		return $response_json['publicKey'];
	}

	/**
	 * Fetches the Public Key from the local DB, if it exists.
	 *
	 * @since 0.5.0
	 *
	 * @return string|WP_Error  The Public Key or a WP_Error if none is found.
	 */
	private function get_local_encryption_key() {

		$public_key = get_site_option( $this->public_key_option, false );

		if ( empty( $public_key ) ) {
			return new WP_Error( 'no_local_key', 'There is no public key stored in the DB' );
		}

		return $public_key;
	}


	/**
	 * Fetches the Public Key from local or db
	 *
	 * @since 0.5.0
	 *
	 * @return string|WP_Error  If found, it returns the publicKey, if not a WP_Error
	 */
	public function get_encryption_key() {

		// Already stored locally in options table
		$local_key = $this->get_local_encryption_key();

		if ( ! is_wp_error( $local_key ) ) {
			return $local_key;
		}

		// Fetch a key
		$remote_key = $this->get_remote_encryption_key();

		if ( is_wp_error( $remote_key ) ) {
			return $remote_key;
		}

		// Store it in the DB
		$saved = $this->update_encryption_key( $remote_key );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $remote_key;
	}

	/**
	 * Encrypts a string using the Public Key provided by the plugin/theme developers' server.
	 *
	 * @since 0.5.0
	 * @uses `openssl_public_encrypt()` for encryption.
	 *
	 * @param string $data Data to encrypt.
	 * @param string $public_key Key to use to encrypt the data.
	 *
	 * @return string|WP_Error  Encrypted envelope or WP_Error on failure.
	 */
	private function encrypt( $data, $public_key ) {

		if ( empty( $data ) || empty( $public_key ) ) {
			return new WP_Error( 'no_data', 'No data provided.' );
		}

		if ( ! function_exists( 'openssl_public_encrypt' ) ) {
			return new WP_Error( 'openssl_public_encrypt_not_available', 'OpenSSL not available' );
		}

		/**
		 * Note about encryption padding:
		 *
		 * Public Key Encryption (ie that can only be decrypted with a secret private_key) uses `OPENSSL_PKCS1_OAEP_PADDING`.
		 * Private Key Signing (ie verified by decrypting with known public_key) uses `OPENSSL_PKCS1_PADDING`
		 */
		openssl_public_encrypt( $data, $encrypted, $public_key, OPENSSL_PKCS1_OAEP_PADDING );

		if ( empty( $encrypted ) ) {

			$error_string = '';
			while ( $msg = openssl_error_string() ) {
				$error_string .= "\n" . $msg;
			}

			return new WP_Error (
				'encryption_failed',
				sprintf(
					'Could not encrypt envelope. Errors from openssl: %1$s',
					$error_string
				)
			);
		}

		$encrypted = base64_encode( $encrypted );

		return $encrypted;
	}

}

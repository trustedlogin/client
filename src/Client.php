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
 * 1. Rename the namespace below to something other than `TrustedLogin`
 * 2. Instantiate this class with a configuration array ({@see https://www.trustedlogin.com/configuration/} for more info)
 */
namespace TrustedLogin;

// If you're not using composer, you'll need to include one or more files.
// If using Composer, use Mozart to re-namespace

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
	 * @var string $version - the current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.9.2';

	/**
	 * @var string self::saas_api_url - the API url for the TrustedLogin SaaS Platform (with trailing slash)
	 * @since 0.4.0
	 */
	const saas_api_url = 'https://app.trustedlogin.com/api/';

	/**
	 * @var array $settings - instance of the initialised plugin config object
	 * @since 0.1.0
	 */
	private $settings;

	/**
	 * @var string $support_role - the namespaced name of the new Role to be created for Support Agents
	 * @example '{vendor/namespace}-support'
	 * @since 0.1.0
	 */
	private $support_role;

	/**
	 * @var string $endpoint_option - the namespaced setting name for storing part of the auto-login endpoint
	 * @example 'tl_{vendor/namespace}_endpoint'
	 * @since 0.3.0
	 */
	private $endpoint_option;

	/**
	 * @var string $identifier_meta_key - The namespaced setting name for storing the unique identifier hash in user meta
	 * @example tl_{vendor/namespace}_id
	 * @since 0.7.0
	 */
	private $identifier_meta_key;

	/**
	 * @var int $expires_meta_key - [Currently not used] The namespaced setting name for storing the timestamp the user expires
	 * @example tl_{vendor/namespace}_expires
	 * @since 0.7.0
	 */
	private $expires_meta_key;

	/**
	 * @var bool $debug_mode - whether to output debug information to a debug text file
	 * @since 0.1.0
	 */
	private $debug_mode;

	/**
	 * @var bool $is_initialized - if the settings have been initialized from the config object
	 * @since 0.1.0
	 */
	private $is_initialized = false;

	/**
	 * @var string $ns - plugin's namespace for use in namespacing variables and strings
	 * @since 0.4.0
	 */
	private $ns;

	/**
	 * @var string $public_key_option - where the plugin should store the public key for encrypting data
	 * @since 0.5.0
	 */
	private $public_key_option;

	/**
	 * @var string $shared_accesskey_option - where the plugin should store the shareable access key
	 * @since 0.9.2
	 */
	private $shared_accesskey_option;

	/**
	 * @var bool $is_ssl_checked - if settings allow syncing support access to Vendor's TrustedLogin account.
	 * @since 0.9.2
	 */
	private $is_ssl_checked = false;

	/**
	 * TrustedLogin constructor.
	 *
	 * @see https://docs.trustedlogin.com/ for more information
	 *
	 * Then you can get TrustedLogin running by using code:
	 *
	 * <code>
	 * $configuration_array = array(
	 *   'auth' => array(
	 *     'public_key' => '1a2b3c4d5e6f', // Get this from your TrustedLogin.com account page
	 *   ),
	 *   'vendor' => array(
	 *     'namespace' => 'example',
	 *   ),
	 * );
	 * new \TrustedLogin\TrustedLogin( $configuration_array );
	 * </code>
	 *
	 * @param array $config
	 *
	 * @throws Exception;
	 */
	public function __construct( $config ) {

		/**
		 * Filter: Whether debug logging is enabled in trustedlogin drop-in
		 *
		 * @since 0.4.2
		 *
		 * @param bool $debug_mode
		 */
		$this->debug_mode = apply_filters( 'trustedlogin_debug_enabled', true );

		// TODO: Show error when config hasn't happened.
		if ( empty( $config ) ) {

			$this->log( 'No config settings passed to constructor', __METHOD__, 'critical' );

			return;
		}

		if ( in_array( __NAMESPACE__, array( 'ReplaceMe', 'ReplaceMe\TrustedLogin' ) ) && ! defined('TL_DOING_TESTS') ) {
			throw new Exception( 'Developer: make sure to change the namespace for the TrustedLogin class. See https://trustedlogin.com/configuration/ for more information.' );
		}

		$this->is_initialized = $this->init_settings( $config );

		$this->init_hooks();

		/**
		 * Filter: Whether the plugin can sync to TrustedLogin, regardless of config-defined SSL Requirments.
		 *
		 * @param bool  $is_ssl_checked
		 */
		$this->is_ssl_checked = apply_filters( 'trustedlogin/' . $this->ns . '/init/is_ssl_checked', $this->check_ssl_requirements() );

	}

	/**
	 * @param string $text Message to log
	 * @param string $method Method where the log was called
	 * @param string $level PSR-3 log level
	 *
	 * @see https://github.com/php-fig/log/blob/master/Psr/Log/LogLevel.php for log levels
	 */
	private function log( $text = '', $method = '', $level = 'notice' ) {

		if ( ! $this->debug_mode ) {
			return;
		}

		$levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

		if ( ! in_array( $level, $levels ) ) {

			$this->log( sprintf( 'Invalid level passed by %s method: %s', $method, $level ), __METHOD__, 'error' );

			$level = 'notice'; // Continue processing original log
		}

		do_action( 'trustedlogin/log', $text, $method, $level );
		do_action( 'trustedlogin/log/' . $level, $text, $method );

		// If logging is in place, don't use the error_log
		// TODO: Rename actions to use namespacing
		if ( has_action( 'trustedlogin/log' ) || has_action( 'trustedlogin/log/' . $level ) ) {
			return;
		}

		if ( in_array( $level, array( 'emergency', 'alert', 'critical', 'error', 'warning' ) ) ) {
			// If WP_DEBUG and WP_DEBUG_LOG are enabled, by default, errors will be logged to that log file.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $method . ' (' . $level . '): ' . $text );
			}
		}
	}

	/**
	 * Returns whether class has been initialized
	 *
	 * @since 0.7.0
	 *
	 * @return bool Whether the class has initialized successfully (configuration settings were set)
	 */
	public function is_initialized() {
		return $this->is_initialized;
	}

	/**
	 * Returns whether SSL checks have passed
	 *
	 * @since 0.9.2
	 *
	 * @return bool Whether the Vendor's config-defined SSL requirements have been checked and passed.
	 */
	public function is_ssl_checked() {
		return $this->is_ssl_checked;
	}

	/**
	 * Checks whether SSL requirments are met.
	 *
	 * @since 0,9.2
	 *
	 * @return bool  Whether the vendor-defined SSL requirments are met.
	 */
	private function check_ssl_requirements(){

		if ( $this->get_setting( 'require_ssl', true ) && ! is_ssl() ){
			return false;
		}

		return true;
	}

	/**
	 * Initialise the action hooks required
	 *
	 * @since 0.2.0
	 */
	public function init_hooks() {

		add_action( 'trustedlogin_revoke_access', array( $this, 'support_user_decay' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );

		add_action( 'wp_ajax_tl_gen_support', array( $this, 'ajax_generate_support' ) );

		if ( is_admin() ) {
			add_action( 'trustedlogin_button', array( $this, 'generate_button' ), 10, 2 );

			add_filter( 'user_row_actions', array( $this, 'user_row_action_revoke' ), 10, 2 );

			add_action( 'trustedlogin_users_table', array( $this, 'output_support_users' ), 20 );
		}

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_add_toolbar_items' ), 100 );
		add_action( 'admin_menu', array( $this, 'admin_menu_auth_link_page' ), $this->get_setting( 'menu/priority', 100 ) );

		add_action( 'admin_init', array( $this, 'admin_maybe_revoke_support' ), 100 );

		// Endpoint Hooks
		add_action( 'init', array( $this, 'add_support_endpoint' ), 10 );
		add_action( 'template_redirect', array( $this, 'maybe_login_support' ), 99 );

		add_action( 'trustedlogin/access/created', array( $this, 'maybe_send_webhook' ) );
		add_action( 'trustedlogin/access/revoked', array( $this, 'maybe_send_webhook' ) );
	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @since 0.3.0
	 */
	public function add_support_endpoint() {

		$endpoint = get_option( $this->endpoint_option );

		if ( ! $endpoint ) {
			return;
		}

		add_rewrite_endpoint( $endpoint, EP_ROOT );

		$this->log( "Endpoint {$endpoint} added.", __METHOD__, 'debug' );

		if ( $endpoint && ! get_option( 'tl_permalinks_flushed' ) ) {

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

		$endpoint = get_option( $this->endpoint_option );

		$identifier = get_query_var( $endpoint, false );

		if ( empty( $identifier ) ) {
			return;
		}

		$users = $this->get_support_user( $identifier );

		if ( empty( $users ) ) {
			return;
		}

		$support_user = $users[0];

		$expires = get_user_meta( $support_user->ID, $this->expires_meta_key, true );

		// This user has expired, but the cron didn't run...
		if ( $expires && time() > (int) $expires ) {
			$this->log( 'The user was supposed to expire on ' . $expires . '; revoking now.', __METHOD__, 'warning' );

			$identifier = get_user_meta( $support_user->ID, $this->identifier_meta_key, true );

			$this->remove_support_user( $identifier );

			return;
		}

		wp_set_current_user( $support_user->ID, $support_user->user_login );
		wp_set_auth_cookie( $support_user->ID );

		do_action( 'wp_login', $support_user->user_login, $support_user );

		wp_redirect( admin_url() );
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

		$support_user_id = $this->create_support_user();

		if ( is_wp_error( $support_user_id ) ) {

			$this->log( sprintf( 'Support user not created: %s (%s)', $support_user_id->get_error_message(), $support_user_id->get_error_code() ), __METHOD__, 'error' );

			wp_send_json_error( array( 'message' => $support_user_id->get_error_message() ), 409 );
		}

		$identifier_hash = $this->generate_identifier_hash();

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
			'siteurl'    => get_site_url(),
			'endpoint'   => $endpoint,
			'identifier' => $identifier_hash,
			'user_id'    => $support_user_id,
			'expiry'     => $expiration_timestamp,
			'access_key' => $secret_id,
			'ssl_checked'=> $this->is_ssl_checked(),
		);

		if ( $this->is_ssl_checked() ){

			try {

				$created = $this->create_secret( $secret_id, $identifier_hash );

			} catch ( Exception $e ) {

				$exception_error = new WP_Error( $e->getCode(), $e->getMessage() );

				wp_send_json_error( $exception_error, 503 );
			}

			if ( is_wp_error( $created ) ) {

				$this->log( sprintf( 'There was an issue creating access (%s): %s', $created->get_error_code(), $created->get_error_message() ), __METHOD__, 'error' );

				wp_send_json_error( $created, 503 );

			}

		}

		wp_send_json_success( $return_data, 201 );

	}

	/**
	 * Returns a timestamp that is the current time + decay time setting
	 *
	 * Note: This is a server timestamp, not a WordPress timestamp
	 *
	 * @param int $decay_time If passed, override the `decay` setting
	 *
	 * @return int Timestamp in seconds. Default is 3 days in seconds from creation (`time()` + 259200)
	 */
	public function get_expiration_timestamp( $decay_time = null ) {

		if ( is_null( $decay_time ) ) {
			$decay_time = $this->get_setting( 'decay', 3 * DAY_IN_SECONDS );
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
	 * @return string
	 */
	public function generate_identifier_hash() {
		return wp_generate_password( 64, false, false );
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
				'trustedlogin_revoke_access',
				array( md5( $identifier_hash ) )
			);

			$this->log( 'Scheduled Expiration: ' . var_export( $scheduled_expiration, true ) . '; identifier: ' . $identifier_hash, __METHOD__, 'info' );

			add_user_meta( $user_id, $this->expires_meta_key, $expiration_timestamp );
		}

		add_user_meta( $user_id, $this->identifier_meta_key, md5( $identifier_hash ), true );
		add_user_meta( $user_id, 'tl_created_by', get_current_user_id() );

		// Make extra sure that the identifier was saved. Otherwise, things won't work!
		return get_user_meta( $user_id, $this->identifier_meta_key, true );
	}

	/**
	 * Register the required scripts and styles
	 *
	 * @since 0.2.0
	 */
	public function register_assets() {

		$jquery_confirm_version = '3.3.4';

		$default_asset_dir_url = plugin_dir_url( __FILE__ ) . 'assets/';

		wp_register_style(
			'jquery-confirm',
			$default_asset_dir_url . 'jquery-confirm/jquery-confirm.min.css',
			array(),
			$jquery_confirm_version,
			'all'
		);

		wp_register_script(
			'jquery-confirm',
			$default_asset_dir_url . 'jquery-confirm/jquery-confirm.min.js',
			array( 'jquery' ),
			$jquery_confirm_version,
			true
		);

		wp_register_script(
			'trustedlogin',
			trailingslashit( $this->get_setting( 'path/js_dir_url', $default_asset_dir_url ) ) . 'trustedlogin.js',
			array( 'jquery', 'jquery-confirm' ),
			self::version,
			true
		);

		wp_register_style(
			'trustedlogin',
			trailingslashit( $this->get_setting( 'path/css_dir_url', $default_asset_dir_url ) ) . 'trustedlogin.css',
			array( 'jquery-confirm' ),
			self::version,
			'all'
		);

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
			'vendor'   => $this->get_setting( 'vendor' ),
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
			'text'        => sprintf( esc_html__( 'Grant %s Support Access', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
			'exists_text' => sprintf( esc_html__( '✅ %s Support Has An Account', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
			'size'        => 'hero',
			'class'       => 'button-primary',
			'tag'         => 'a', // "a", "button", "span"
			'powered_by'  => true,
			'support_url' => $this->get_setting( 'vendor/support_url' ),
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

		if ( $this->get_support_users() ) {
			$text        			= esc_html( $atts['exists_text'] );
			$href 	     			= admin_url( 'users.php?role=' . $this->support_role );
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

		// The `trustedlogin_button` action passes an empty string
		if ( '' === $print ) {
			$print = true;
		}

		$support_users = $this->get_support_users();

		if ( empty( $support_users ) ) {

			$return = '<h3>' . sprintf( esc_html__( 'No %s users exist.', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) . '</h3>';

			if ( $print ) {
				echo $return;
			}

			return $return;
		}

		$return = '';

		$return .= '<h3>' . sprintf( esc_html__( '%s users:', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) . '</h3>';

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

			$_user_creator = get_user_by( 'id', get_user_meta( $support_user->ID, 'tl_created_by', true ) );

			$return .= '<tr>';
			$return .= '<th scope="row"><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $support_user->ID ) ) . '">';
			$return .= sprintf( '%s (#%d)', esc_html( $support_user->display_name ), $support_user->ID );
			$return .= '</th>';

			$return .= '<td>' . sprintf( esc_html__( '%s ago', 'trustedlogin' ), human_time_diff( strtotime( $support_user->user_registered ) ) ) . '</td>';
			$return .= '<td>' . sprintf( esc_html__( 'In %s', 'trustedlogin' ), human_time_diff( get_user_meta( $support_user->ID, $this->expires_meta_key, true ) ) ) . '</td>';

			if ( $_user_creator && $_user_creator->exists() ) {
				$return .= '<td>' . ( $_user_creator->exists() ? esc_html( $_user_creator->display_name ) : esc_html__( 'Unknown', 'trustedlogin' ) ) . '</td>';
			} else {
				$return .= '<td>' . esc_html__( 'Unknown', 'trustedlogin' ) . '</td>';
			}

			if ( $revoke_url = $this->helper_get_user_revoke_url( $support_user ) ) {
				$return .= '<td><a class="trustedlogin tl-revoke submitdelete" href="' . esc_url( $revoke_url ) . '">' . esc_html__( 'Revoke Access', 'trustedlogin' ) . '</a></td>';
			} else {
				$return .= '<td><a href="' . esc_url( admin_url( 'users.php?role=' . $this->support_role ) ) . '">' . esc_html__( 'Manage from Users list', 'trustedlogin' ) . '</a></td>';
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
			$this->get_setting( 'vendor/title' )
		);

		$result['description'] = sprintf( '<p class="description">%1$s</p>',
			__( 'By clicking Confirm, the following will happen automatically:', 'trustedlogin' )
		);



		// Roles
		$roles_output = '';
		foreach ( $this->get_setting( 'role' ) as $role => $reason ) {
			$roles_output .= sprintf( '<li class="tl-role"><p>%1$s<br /><small>%2$s</small></p></li>',
				sprintf( esc_html__( 'A new user will be created with a custom role \'%1$s\' (with the same capabilities as %2$s).', 'trustedlogin' ),
					$this->support_role,
					$role
				),
				esc_html($reason)
			);
		}
		$result['roles'] = $roles_output;

		// Extra Caps
		$caps_output = '';
		foreach ( $this->get_setting( 'extra_caps' ) as $cap => $reason ) {
			$caps_output .= sprintf( '<li class="extra-caps"> %1$s <br /><small>%2$s</small></li>',
				sprintf( esc_html__( 'With the additional \'%1$s\' Capability.', 'trustedlogin' ),
					$cap
				),
				$reason
			);
		}
		foreach ( $this->get_setting( 'excluded_caps' ) as $cap => $reason ) {
			$caps_output .= sprintf( '<li class="excluded-caps"> %1$s <br /><small>%2$s</small></li>',
				sprintf( esc_html__( 'The \'%1$s\' Capability will not be granted.', 'trustedlogin' ),
					$cap
				),
				$reason
			);
		}
		$result['caps'] = $caps_output;

		// Decay
		if ( $this->get_setting( 'decay' ) ) {

			$decay_time = $this->get_expiration_timestamp();

			$decay_diff = human_time_diff( $decay_time );

			$decay_tag = apply_filters('trustedlogin/tags/decay','h4');
			$decay_output = '<'.$decay_tag.'>' . sprintf( esc_html__( 'Access will be granted for %1$s and can be revoked at any time.', 'trustedlogin' ), $decay_diff ) . '</'.$decay_tag.'>';
		} else {
			$decay_output = '';
		}

		$details_output = sprintf(
			wp_kses(
				apply_filters(
					'trustedlogin/template/details',
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

		$vendor_title = $this->get_setting( 'vendor/title' );

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
		$query_args = apply_filters( 'trustedlogin/support_url/query_args',	array(
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
				esc_url( add_query_arg( $query_args, $this->get_setting( 'vendor/support_url' ) ) ),
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
						esc_url( admin_url( 'users.php?role=' . $this->support_role ) )
					),
				),
			),
		);

		return $secondary_alert_translations;
	}

	/**
	 * Init all the settings from the provided TL_Config array.
	 *
	 * @since 0.1.0
	 *
	 * @param array as per TL_Config specification
	 *
	 * @return bool Initialization succeeded
	 */
	protected function init_settings( $config ) {

		if ( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}

		if ( ! is_array( $config ) || empty( $config ) ) {
			return false;
		}

		/**
		 * Filter: Initilizing TrustedLogin settings
		 *
		 * @param array $config {
		 *
		 * @since 0.1.0
		 *
		 * @see trustedlogin-button.php for documentation of array parameters
		 * @todo Move the array documentation here
		 * }
		 */
		$this->settings = apply_filters( 'trustedlogin/init_settings', $config );

		$this->ns = $this->get_setting( 'vendor/namespace' );

		/**
		 * Filter: Set support_role value
		 *
		 * @param string
		 * @param Client $this
		 *
		 * @since 0.2.0
		 *
		 */
		$this->support_role = apply_filters(
			'trustedlogin/' . $this->ns . '/support_role/slug',
			$this->ns . '-support',
			$this
		);

		/**
		 * Filter: Set endpoint setting name
		 *
		 * @since 0.3.0
		 *
		 * @param string
		 * @param Client $this
		 */
		$this->endpoint_option = apply_filters(
			'trustedlogin_' . $this->ns . '_endpoint_option_title',
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
			'trustedlogin/' . $this->ns . '/public-key-option',
			$this->ns . '_public_key',
			$this
		);

		$this->identifier_meta_key = 'tl_' . $this->ns . '_id';
		$this->expires_meta_key    = 'tl_' . $this->ns . '_expires';

		/**
		 * Filter: Sets the site option name for the Shareable accessKey if it's used
		 *
		 * @since 0.9.2
		 *
		 * @param string $shared_accesskey_option
		 * @param Client $this
		 */
		$this->shared_accesskey_option = apply_filters(
			'trustedlogin/' . $this->ns . '/shareable-accesskey-option',
			'tl_' . $this->ns . '_shared_accesskey',
			$this
		);

		return true;
	}

	/**
	 * Helper Function: Get a specific setting or return a default value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug - the setting to fetch, nested results are delimited with periods (eg vendor/name => settings['vendor']['name']
	 * @param mixed $default - if no setting found or settings not init, return this value.
	 *
	 * @return string|array
	 */
	public function get_setting( $slug, $default = false ) {

		if ( ! isset( $this->settings ) || ! is_array( $this->settings ) ) {

			$this->log( 'Settings have not been configured, returning default value', __METHOD__, 'critical' );

			return $default;
		}

		$keys = explode( '/', $slug );

		if ( count( $keys ) > 1 ) {

			$array_ptr = $this->settings;

			$last_key = array_pop( $keys );

			while ( $arr_key = array_shift( $keys ) ) {
				if ( ! array_key_exists( $arr_key, $array_ptr ) ) {
					$this->log( 'Could not find multi-dimension setting. Keys: ' . print_r( $keys, true ), __METHOD__, 'error' );

					return $default;
				}

				$array_ptr = &$array_ptr[ $arr_key ];
			}

			if ( isset( $array_ptr[ $last_key ] ) ) {
				return $array_ptr[ $last_key ];
			}
		}

		if ( isset( $this->settings[ $slug ] ) ) {
			return $this->settings[ $slug ];
		}

		$this->log( 'Setting for slug ' . $slug . ' not found.', __METHOD__, 'error' );

		return $default;
	}

	/**
	 * Create the Support User with custom role.
	 *
	 * @since 0.1.0
	 *
	 * @return int|WP_Error - Array with login response information if created, or WP_Error object if there was an issue.
	 */
	public function create_support_user() {

		$user_name = sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) );

		if ( $user_id = username_exists( $user_name ) ) {
			$this->log( 'Support User not created; already exists: User #' . $user_id, __METHOD__, 'notice' );

			return new WP_Error( 'username_exists', sprintf( 'A user with the username %s already exists', $user_name ) );
		}

		$role_setting = $this->get_setting( 'role', array( 'editor' => '' ) );

		// Get the role value from the key
		$clone_role_slug = key( $role_setting );

		$role_exists = $this->support_user_create_role( $this->support_role, $clone_role_slug );

		if ( ! $role_exists ) {
			$this->log( 'Support role could not be created (based on ' . $clone_role_slug . ')', __METHOD__, 'error' );

			return new WP_Error( 'role_not_created', 'Support role could not be created' );
		}

		$user_email = $this->get_setting( 'vendor/email' );

		if ( email_exists( $user_email ) ) {
			$this->log( 'Support User not created; User with that email already exists: ' . $user_email, __METHOD__, 'warning' );

			return new WP_Error( 'user_email_exists', 'Support User not created; User with that email already exists' );
		}

		$user_data = array(
			'user_login'      => $user_name,
			'user_url'        => $this->get_setting( 'vendor/website' ),
			'user_pass'       => wp_generate_password( 64, true, true ),
			'user_email'      => $user_email,
			'role'            => $this->support_role,
			'first_name'      => $this->get_setting( 'vendor/first_name', '' ),
			'last_name'       => $this->get_setting( 'vendor/last_name', '' ),
			'user_registered' => date( 'Y-m-d H:i:s', time() ),
		);

		$new_user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $new_user_id ) ) {
			$this->log( 'Error: User not created because: ' . $new_user_id->get_error_message(), __METHOD__, 'error' );

			return $new_user_id;
		}

		$this->log( 'Support User #' . $new_user_id, __METHOD__, 'info' );

		return $new_user_id;
	}

	/**
	 * Get the ID of the best-guess appropriate admin user
	 *
	 * @since 0.7.0
	 *
	 * @return int|false User ID if there are admins, false if not
	 */
	private function get_reassign_user_id() {

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
	 *
	 * @param string $identifier - Unique Identifier of the user to delete, or 'all' to remove all support users.
	 *
	 * @since 0.1.0
	 *
	 * @todo get rid of "all" option, since it makes things confusing
	 *
	 * @return bool|WP_Error
	 */
	public function remove_support_user( $identifier = 'all' ) {

		if ( 'all' === $identifier ) {
			$users = $this->get_support_users();
		} else {
			$users = $this->get_support_user( $identifier );
		}

		if ( empty( $users ) ) {
			return false;
		}

		$this->log( count( $users ) . " support users found", __METHOD__, 'debug' );

		if ( $this->settings['reassign_posts'] ) {
			$reassign_id = $this->get_reassign_user_id();
		} else {
			$reassign_id = null;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		foreach ( $users as $_u ) {
			$this->log( "Processing user ID " . $_u->ID, __METHOD__, 'debug' );

			$tlid = get_user_meta( $_u->ID, $this->identifier_meta_key, true );

			// Remove auto-cleanup hook
			wp_clear_scheduled_hook( 'trustedlogin_revoke_access', array( $tlid ) );

			if ( wp_delete_user( $_u->ID, $reassign_id ) ) {
				$this->log( "User: " . $_u->ID . " deleted.", __METHOD__, 'info' );
			} else {
				$this->log( "User: " . $_u->ID . " NOT deleted.", __METHOD__, 'error' );
			}
		}

		if ( count( $users ) < 2 || $identifier == 'all' ) {

			if ( get_role( $this->support_role ) ) {
				remove_role( $this->support_role );
				$this->log( "Role " . $this->support_role . " removed.", __METHOD__, 'info' );
			}

			if ( get_option( $this->endpoint_option ) ) {

				delete_option( $this->endpoint_option );

				flush_rewrite_rules( false );

				update_option( 'tl_permalinks_flushed', 0 );

				$this->log( "Endpoint removed & rewrites flushed", __METHOD__, 'info' );
			}

		}

		return $this->revoke_access( $identifier );
	}

	/**
	 * Generate the endpoint parameter as a hash of the site URL with the identifier
	 *
	 * @param $identifier_hash
	 *
	 * @return string This hash will be used as the first part of the URL and also a part of $secret_id
	 */
	private function get_endpoint_hash( $identifier_hash ) {
		return md5( get_site_url() . $identifier_hash );
	}

	/**
	 * Generate the secret_id parameter as a hash of the endpoint with the identifier
	 *
	 * @param string $identifier_hash
	 * @param string $endpoint_hash
	 *
	 * @return string This hash will be used as an identifier in the Vault
	 */
	private function generate_secret_id( $identifier_hash, $endpoint_hash = '' ) {

		if ( empty( $endpoint_hash ) ) {
			$endpoint_hash = $this->get_endpoint_hash( $identifier_hash );
		}

		return md5( $endpoint_hash . $identifier_hash );
	}

	/**
	 * Hooked Action: Decays (deletes a specific support user)
	 *
	 * @since 0.2.1
	 *
	 * @param string $identifier_hash Identifier hash for the user associated with the cron job
	 *
	 * @return void
	 */
	public function support_user_decay( $identifier_hash ) {

		$this->log( 'Running cron job to disable user. ID: ' . $identifier_hash, __METHOD__, 'notice' );

		$this->remove_support_user( $identifier_hash );
	}

	/**
	 * Creates the custom Support Role if it doesn't already exist
	 *
	 * @since 0.1.0
	 * @since 0.9.2 removed excluded_caps from generated role
	 *
	 * @param string $new_role_slug    The slug for the new role.
	 * @param string $clone_role_slug  The slug for the role to clone, defaults to 'editor'.
	 *
	 * @return bool
	 */
	public function support_user_create_role( $new_role_slug, $clone_role_slug = 'editor' ) {

		if ( empty( $new_role_slug ) || empty( $clone_role_slug ) ) {
			return false;
		}

		$role_exists = get_role( $new_role_slug );

		if ( $role_exists ) {
			$this->log( 'Not creating user role; it already exists', __METHOD__, 'notice' );

			return true;
		}

		$this->log( 'New role slug: ' . $new_role_slug . ', Clone role slug: ' . $clone_role_slug, __METHOD__, 'debug' );

		$old_role = get_role( $clone_role_slug );

		if ( empty( $old_role ) ) {
			$this->log( 'Error: the role to clone does not exist: ' . $clone_role_slug, __METHOD__, 'critical' );

			return false;
		}

		$capabilities = $old_role->capabilities;

		$extra_caps = $this->get_setting( 'extra_caps' );

		foreach ( (array) $extra_caps as $extra_cap => $reason ) {
			$capabilities[ $extra_cap ] = true;
		}

		// These roles should never be assigned to TrustedLogin roles.
		$prevent_caps = array(
			'create_users',
			'delete_users',
			'edit_users',
			'promote_users',
			'delete_site',
			'remove_users',
		);

		foreach ( $prevent_caps as $prevent_cap ) {
			unset( $capabilities[ $prevent_cap ] );
		}

		/**
		 * @filter trustedlogin/{namespace}/support_role/display_name Modify the display name of the created support role
		 */
		$role_display_name = apply_filters( 'trustedlogin/' . $this->ns . '/support_role/display_name', sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ), $this );

		$new_role = add_role( $new_role_slug, $role_display_name, $capabilities );

		if ( ! $new_role ){

			$this->log( 'Error: the role was not created.', __METHOD__, 'critical' );
			$this->log( 'Role: ' . $new_role_slug , __METHOD__, 'info' );
			$this->log( 'Display Name: ' . $role_display_name , __METHOD__, 'info' );
			$this->log( 'Capabilities: ' . print_r( $capabilities, true ) , __METHOD__, 'info' );

			return false;

		}

		$excluded_caps = $this->get_setting( 'excluded_caps' );

		if ( ! empty( $excluded_caps ) ){

			foreach ( $excluded_caps as $excluded_cap => $description ){
				$new_role->remove_cap( $excluded_cap );
				$this->log( 'Capability '. $excluded_cap .' removed from role.', __METHOD__, 'info' );
			}

		}

		return true;
	}


	/**
	 * Get all users with the support role
	 *
	 * @since 0.7.0
	 *
	 * @return array
	 */
	public function get_support_users() {

		$args = array(
			'role' => $this->support_role,
		);

		return get_users( $args );
	}

	/**
	 * Helper Function: Get the generated support user(s).
	 *
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier
	 *
	 * @return array of WP_Users
	 */
	public function get_support_user( $identifier = '' ) {

		// When passed in the endpoint URL, the unique ID will be the raw value, not the md5 hash.
		if ( strlen( $identifier ) > 32 ) {
			$identifier = md5( $identifier );
		}

		$args = array(
			'role'       => $this->support_role,
			'number'     => 1,
			'meta_key'   => $this->identifier_meta_key,
			'meta_value' => $identifier,
		);

		return get_users( $args );
	}

	/**
	 * Adds a "Revoke TrustedLogin" menu item to the admin toolbar
	 *
	 * @param WP_Admin_Bar $admin_bar
	 *
	 * @return void
	 */
	public function admin_bar_add_toolbar_items( $admin_bar ) {

		if ( ! current_user_can( $this->support_role ) ) {
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

		if ( ! current_user_can( $this->support_role ) && ! current_user_can( 'delete_users' ) ) {
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

		$identifier = get_user_meta( $user_object->ID, $this->identifier_meta_key, true );

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
		if ( ! current_user_can( $this->support_role ) && ! current_user_can( 'delete_users' ) ) {
			return;
		}

		if ( isset( $_GET['tlid'] ) ) {
			$identifier = sanitize_text_field( $_GET['tlid'] );
		} else {
			$identifier = 'all';
		}

		$removed_user = $this->remove_support_user( $identifier );

		if ( is_wp_error( $removed_user ) ) {
			$this->log( 'Removing user failed: ' . $removed_user->get_error_message(), __METHOD__, 'error' );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'delete_users' ) ) {
			wp_redirect( home_url() );
			exit;
		}

		$support_user = $this->get_support_user( $identifier );

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

		$webhook_url = $this->get_setting( 'webhook_url' );

		if ( empty( $webhook_url ) || ! filter_var( $webhook_url, FILTER_VALIDATE_URL ) ) {
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

		do_action( 'trustedlogin/secret/created', array( 'url' => get_site_url(), 'action' => 'create' ) );

		return true;
	}

	/**
	 * Revoke access to a site
	 *
	 * @param string $identifier Unique ID or "all"
	 *
	 * @return bool Both saas and vault synced. False: either or both failed to sync.
	 */
	public function revoke_access( $identifier = '' ) {

		if ( empty( $identifier ) ) {
			$this->log( "Missing the revoke access identifier.", __METHOD__, 'error' );

			return false;
		}

		$endpoint_hash = $this->get_endpoint_hash( $identifier );

		// Ping SaaS to notify of revoke
		$site_revoked = $this->revoke_site( $endpoint_hash );

		if ( is_wp_error( $site_revoked ) ) {

			// Couldn't sync to SaaS, this should/could be extended to add a cron-task to delayed update of SaaS DB
			// TODO: extend to add a cron-task to delayed update of SaaS DB
			$this->log( "There was an issue syncing to SaaS. Failing silently.", __METHOD__, 'error' );

			return $site_revoked;
		}

		do_action( 'trustedlogin/access/revoked', array( 'url' => get_site_url(), 'action' => 'revoke' ) );

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
		$license_key = $this->get_setting( 'auth/license_key', false );

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
	 * @return  string  Access Key prepended with TL|
	 */
	private function get_shareable_accesskey(){

		$hash = md5( get_site_url() . $this->get_setting( 'auth/public_key' ) );

		/**
		 * Filter: Allow for over-riding the shareable 'accessKey' prefix
		 *
		 * @since 0.9.2
		 */
		$access_key_prefix  = apply_filters( 'trustedlogin/' . $this->ns . '/access_key_prefix' , 'TL|');

		$length 			= strlen( $access_key_prefix );
		$access_key 		= $access_key_prefix . substr( $hash, $length );

		update_site_option( $this->shared_accesskey_option, $access_key );

		return $access_key;
	}

	/**
	 * Checks if a license key is a shareable accessKey
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
		$access_key_prefix  = apply_filters( 'trustedlogin/' . $this->ns . '/access_key_prefix' , 'TL|');
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

		$access_key = get_site_option( $this->shared_accesskey_option, false );

		if ( $access_key ){
			return $access_key;
		}

		return $this->get_setting( 'auth/license_key', false );

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
					$this->get_setting( 'vendor/title' ),
					$encryption_key->get_error_message()
				)
			);
		}

		$envelope = array(
			'secretId'   => $secret_id,
			'identifier' => $this->encrypt( $identifier, $encryption_key ),
			'publicKey'  => $this->get_setting( 'auth/public_key' ),
			'accessKey'  => $this->get_license_key(),
			'siteUrl'    => $this->encrypt( get_site_url(), $encryption_key ),
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

		$body = array(
			'publicKey' => $this->get_setting( 'auth/public_key' ),
		);

		if ( ! $this->is_ssl_checked() ){

			$this->log( 'Not notifiying TrustedLogin about revoked site due to SSL requirements.', __METHOD__, 'info' );
			return true;
		}

		$api_response = $this->api_send(  'sites/' . $identifier, $body, 'DELETE' );

		$response = $this->handle_response( $api_response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		delete_site_option( $this->shared_accesskey_option );

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
	 * @param array $data
	 * @param array $addition_header - any additional headers required for auth/etc
	 *
	 * @return array|WP_Error|false wp_remote_post() response, or false if `$method` isn't valid
	 */
	public function api_send( $path, $data, $method, $additional_headers = array() ) {

		if ( ! in_array( $method, array( 'POST', 'PUT', 'GET', 'PUSH', 'DELETE' ) ) ) {
			$this->log( "Error: Method not in allowed array list ($method)", __METHOD__, 'critical' );

			return false;
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer '. $this->get_setting( 'auth/public_key' ),
		);

		if ( ! empty( $additional_headers ) ) {
			$headers = array_merge( $headers, $additional_headers );
		}

		$request_options = array(
			'method'      => $method,
			'timeout'     => 45,
			'httpversion' => '1.1',
			'headers'     => $headers,
			'body'        => ( $data ? json_encode( $data ) : null ),
		);

		/**
		 * Modifies the endpoint URL for the TrustedLogin service.
		 *
		 * @param string $url URL to TrustedLogin API
		 *
		 * @internal This allows pointing requests to testing servers
		 */
		$url = apply_filters( 'trustedlogin/api-url', self::saas_api_url ) . $path;

		$this->log( sprintf( 'Sending to %s: %s', $url, print_r( $request_options, true ) ), __METHOD__, 'debug' );

		$response = wp_remote_request( $url, $request_options );

		$this->log( sprintf( 'Response: %s', print_r( $response, true ) ), __METHOD__, 'debug' );

		return $response;
	}

	/**
	 * Notice: Shown when a support user is manually revoked by admin;
	 *
	 * @since 0.3.0
	 */
	public function admin_notice_revoked() {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( sprintf( __( 'Done! %s Support access revoked. ', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) ); ?></p>
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

		$ns = $this->get_setting( 'vendor/namespace' );

		$slug = apply_filters( 'trustedlogin/admin/grantaccess/slug', 'grant-' . $ns . '-access', $ns );

		$parent_slug = $this->get_setting( 'menu/slug', null );

		$menu_title = $this->get_setting( 'menu/title', esc_html__( 'Grant Support Access', 'trustedlogin' ) );

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
		$ns          = $this->get_setting( 'vendor/namespace' );

		$logo_output = '';

		if ( ! empty( $this->get_setting( 'vendor/logo_url' ) ) ) {

			$logo_output = sprintf(
				'<a href="%1$s" title="%2$s" target="_blank" rel="noreferrer noopener"><img class="tl-auth-logo" src="%3$s" alt="%4$s" /></a>',
				esc_url( $this->get_setting( 'vendor/website' ) ),
				esc_attr( sprintf( __( 'Grant %1$s Support access to your site.', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) ),
				esc_url( $this->get_setting( 'vendor/logo_url' ) ),
				esc_attr( $this->get_setting( 'vendor/title' ) )
			);
		}

		$intro_output = sprintf( '<div class="intro">%s</div>', $output_lang['intro'] );

		$description_output = $output_lang['description'];

		$details_output = sprintf(
			'<ul class="tl-details tl-roles">%1$s</ul><div class="tl-toggle-caps"><p>%2$s</p></div><ul class="tl-details caps hidden">%3$s</ul>',
			$output_lang['roles'],
			sprintf( '%s <span class="dashicons dashicons-arrow-down-alt2"></span>', __( 'With a few more capabilities', 'trustedlogin' ) ),
			$output_lang['caps']
		);

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
			'trustedlogin/template/grantlink/footer-links',
			array(
				__( 'Learn about TrustedLogin', 'trustedlogin' )                    => 'https://www.trustedlogin.com/about/easy-and-safe/',
				sprintf( 'Visit %s Support', $this->get_setting( 'vendor/title' ) ) => $this->get_setting( 'vendor/support_url' ),
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
		 * Filters trustedlogin/template/grantlink/outer-tag and /trustedlogin/template/grantlink/inner-tag
		 *
		 * Used to change the innerTags and outerTags of the grandlink template
		 *
		 * @since 0.5.0
		 *
		 * @param string the html tag to use for each tag, default: div
		 * @param string $ns - the namespace of the plugin. initializing TrustedLogin
		 **/
		$output_html = str_replace( '{{outerTag}}', apply_filters( 'trustedlogin/template/grantlink/outer-tag', 'div', $ns ), $output_html );
		$output_html = str_replace( '{{innerTag}}', apply_filters( 'trustedlogin/template/grantlink/inner-tag', 'div', $ns ), $output_html );

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
				apply_filters( 'trustedlogin/template/grantlink', $output_html, $ns ),
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
			apply_filters( 'trustedlogin/template/grantlink/outer-class', '', $ns ),
			apply_filters( 'trustedlogin/template/grantlink/logo', $logo_output, $ns ),
			apply_filters( 'trustedlogin/template/grantlink/intro', $intro_output, $ns ),
			apply_filters( 'trustedlogin/template/grantlink/details', $description_output, $ns ),
			apply_filters( 'trustedlogin/template/grantlink/details', $details_output, $ns ),
			apply_filters( 'trustedlogin/template/grantlink/actions', $actions_output, $ns ),
			apply_filters( 'trustedlogin/template/grantlink/footer', $footer_output, $ns )
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

		$vendor_url   = $this->get_setting( 'vendor/website' );

		$key_endpoint = apply_filters( 'trustedlogin/vendor/public-key-endpoint', 'wp-json/trustedlogin/v1/public_key' );

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

		$local_key = $this->get_local_encryption_key();
		if ( ! is_wp_error( $local_key ) ) {
			return $local_key;
		}

		$remote_key = $this->get_remote_encryption_key();
		if ( is_wp_error( $remote_key ) ) {
			return $remote_key;
		}

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

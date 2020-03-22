<?php

namespace TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \WP_User;
use \WP_Admin_Bar;

final class Admin {

	/**
	 * @var string The version of jQuery Confirm currently being used
	 * @internal Don't rely on jQuery Confirm existing!
	 */
	const jquery_confirm_version = '3.3.4';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var SiteAccess $site_access
	 */
	private $site_access;

	/**
	 * @var SupportUser $support_user
	 */
	private $support_user;

	/**
	 * @var null|Logging $logging
	 */
	private $logging;

	/**
	 * Admin constructor.
	 *
	 * @param Config $config
	 */
	public function __construct( Config $config, Logging $logging ) {
		$this->config = $config;

		$this->support_user = new SupportUser( $config, $logging );
	}


	public function init() {
		add_action( 'trustedlogin/' . $this->config->ns() . '/button', array( $this, 'generate_button' ), 10, 2 );
		add_action( 'trustedlogin/' . $this->config->ns() . '/users_table', array( $this, 'output_support_users' ), 20 );
		add_filter( 'user_row_actions', array( $this, 'user_row_action_revoke' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_add_toolbar_items' ), 100 );
		add_action( 'admin_menu', array( $this, 'admin_menu_auth_link_page' ), $this->config->get_setting( 'menu/priority', 100 ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
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

		if ( ! current_user_can( $this->support_user->role->get_name() ) && ! current_user_can( 'delete_users' ) ) {
			return $actions;
		}

		$revoke_url = $this->support_user->get_revoke_url( $user_object );

		if ( ! $revoke_url ) {
			return $actions;
		}

		$actions = array(
			'revoke' => "<a class='trustedlogin tl-revoke submitdelete' href='" . esc_url( $revoke_url ) . "'>" . esc_html__( 'Revoke Access', 'trustedlogin' ) . "</a>",
		);

		return $actions;
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
			Client::version,
			true
		);

		$registered['trustedlogin-css'] = wp_register_style(
			'trustedlogin',
			$this->config->get_setting( 'paths/css' ),
			array( 'jquery-confirm' ),
			Client::version,
			'all'
		);

		$registered = array_filter( $registered );

		if ( 4 !== count( $registered ) ) {
			$this->logging->log( 'Not all scripts and styles were registered: ' . print_r( $registered, true ), __METHOD__, 'error' );
		}

	}

	/**
	 * Adds a "Revoke TrustedLogin" menu item to the admin toolbar
	 *
	 * @param WP_Admin_Bar $admin_bar
	 *
	 * @return void
	 */
	public function admin_bar_add_toolbar_items( $admin_bar ) {

		if ( ! current_user_can( $this->support_user->role->get_name() ) ) {
			return;
		}

		if ( ! $admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		$admin_bar->add_menu( array(
			'id'    => 'tl-' . $this->config->ns() . '-revoke',
			'title' => esc_html__( 'Revoke TrustedLogin', 'trustedlogin' ),
			'href'  => admin_url( add_query_arg( array( Endpoint::revoke_support_query_param => $this->config->ns() ), 'users.php' ),
			'meta'  => array(
				'title' => esc_html__( 'Revoke TrustedLogin', 'trustedlogin' ),
				'class' => 'tl-destroy-session',
			),
		) );
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

		$ns = $this->config->ns();

		$slug = apply_filters( 'trustedlogin/' . $this->config->ns() . '/admin/grantaccess/slug', 'grant-' . $ns . '-access', $ns );

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

		$caps = $this->config->get_setting( 'caps' );

		if( $caps = array_filter( $caps ) ) {
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
			'trustedlogin/' . $this->config->ns() . '/template/grantlink/footer_links',
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
		$output_html = str_replace( '{{outerTag}}', apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/outer-tag', 'div', $ns ), $output_html );
		$output_html = str_replace( '{{innerTag}}', apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/inner-tag', 'div', $ns ), $output_html );

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
				apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink', $output_html, $ns ),
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
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/outer_class', '', $ns ),
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/logo', $logo_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/intro', $intro_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/details', $description_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/details', $details_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/actions', $actions_output, $ns ),
			apply_filters( 'trustedlogin/' . $this->config->ns() . '/template/grantlink/footer', $footer_output, $ns )
		);

		return $output_template;
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
			$this->logging->log( 'JavaScript is not registered. Make sure `trustedlogin` handle is added to "no-conflict" plugin settings.', __METHOD__, 'error' );
		}

		if ( ! wp_style_is( 'trustedlogin', 'registered' ) ) {
			$this->logging->log( 'Style is not registered. Make sure `trustedlogin` handle is added to "no-conflict" plugin settings.', __METHOD__, 'error' );
		}

		wp_enqueue_style( 'trustedlogin' );

		$button_settings = array(
			'vendor'   => $this->config->get_setting( 'vendor' ),
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'_nonce'   => wp_create_nonce( 'tl_nonce-' . get_current_user_id() ),
			'lang'     => array_merge( $this->output_tl_alert(), $this->output_secondary_alerts() ),
			'debug'    => $this->logging->is_enabled(),
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
			$href 	     			= admin_url( 'users.php?role=' . $this->support_user->role->get_name() );
			$data_atts['accesskey'] = $this->site_access->get_access_key(); // Add the shareable accesskey as a data attribute
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
				$this->support_user->role->get_name(),
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
		if ( $decay_time = $this->config->get_expiration_timestamp() ) {

			$decay_diff = human_time_diff( $decay_time );

			$decay_tag = apply_filters('trustedlogin/' . $this->config->ns() . '/template/tags/decay','h4');
			$decay_output = '<'.$decay_tag.'>' . sprintf( esc_html__( 'Access will be granted for %1$s and can be revoked at any time.', 'trustedlogin' ), $decay_diff ) . '</'.$decay_tag.'>';
		} else {
			$decay_output = '';
		}

		$details_output = sprintf(
			wp_kses(
				apply_filters(
					'trustedlogin/' . $this->config->ns() . '/template/details',
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
		$query_args = apply_filters( 'trustedlogin/' . $this->config->ns() . '/support_url/query_args',	array(
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
					'revoke_link' => esc_url( add_query_arg( array( 'revoke-tl' => $this->config->ns() ), admin_url( 'users.php' ) ) ),
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
						esc_url( admin_url( 'users.php?role=' . $this->support_user->role->get_name() ) )
					),
				),
			),
		);

		return $secondary_alert_translations;
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

			$_user_creator = get_user_by( 'id', get_user_option( $this->option_keys->created_by_meta_key, $support_user->ID ) );

			$return .= '<tr>';
			$return .= '<th scope="row"><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $support_user->ID ) ) . '">';
			$return .= sprintf( '%s (#%d)', esc_html( $support_user->display_name ), $support_user->ID );
			$return .= '</th>';

			$return .= '<td>' . sprintf( esc_html__( '%s ago', 'trustedlogin' ), human_time_diff( strtotime( $support_user->user_registered ) ) ) . '</td>';
			$return .= '<td>' . sprintf( esc_html__( 'In %s', 'trustedlogin' ), human_time_diff( get_user_option( $this->option_keys->expires_meta_key, $support_user->ID ) ) ) . '</td>';

			if ( $_user_creator && $_user_creator->exists() ) {
				$return .= '<td>' . ( $_user_creator->exists() ? esc_html( $_user_creator->display_name ) : esc_html__( 'Unknown', 'trustedlogin' ) ) . '</td>';
			} else {
				$return .= '<td>' . esc_html__( 'Unknown', 'trustedlogin' ) . '</td>';
			}

			if ( $revoke_url = $this->support_user->get_revoke_url( $support_user ) ) {
				$return .= '<td><a class="trustedlogin tl-revoke submitdelete" href="' . esc_url( $revoke_url ) . '">' . esc_html__( 'Revoke Access', 'trustedlogin' ) . '</a></td>';
			} else {
				$return .= '<td><a href="' . esc_url( admin_url( 'users.php?role=' . $this->support_user->role->get_name() ) ) . '">' . esc_html__( 'Manage from Users list', 'trustedlogin' ) . '</a></td>';
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
}

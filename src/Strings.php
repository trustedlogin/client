<?php
/**
 * Translatable strings + integrator overrides.
 *
 * The SDK has user-facing strings — consent screen labels, error banners,
 * post-redirect messages, branding. Integrators want to:
 *
 *   1. Localize them (German customer site → German consent screen).
 *   2. Re-brand them ("Acme Support" instead of "TrustedLogin").
 *
 * Two parts to the design:
 *
 * - {@see Strings::get()} at every call site, with the SDK's English
 *   default passed in as a literal so `wp i18n make-pot` extracts it
 *   for the SDK's own translation pipeline.
 *
 * - {@see Strings::load_translations()}, called once by the integrator
 *   on `plugins_loaded` or `init`. Routes the SDK's bundled `.mo` files
 *   through the integrator's own textdomain. Strauss-safe by design:
 *   each integrator's prefixed SDK image loads under a different
 *   textdomain, so no cross-plugin collision.
 *
 * @package TrustedLogin\Client
 */

namespace TrustedLogin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translatable strings registry, runtime override resolver, and integrator
 * translation router.
 *
 * @since 1.11.0
 */
class Strings {

	// -----------------------------------------------------------------
	// Public contract: every overrideable string gets a constant here.
	// Constants are stable across versions (renames are breaking).
	// -----------------------------------------------------------------

	const REVOKE_ACCESS                           = 'revoke_access';
	const YOU_ARE_LOGGED_IN_AS_A                  = 'you_are_logged_in_as_a';
	const GRANT_SUPPORT_ACCESS                    = 'grant_support_access';
	const VERIFICATION_ISSUE_REQUEST_COULD_NOT_BE = 'verification_issue_request_could_not_be';
	const YOU_DO_NOT_HAVE_THE_ABILITY             = 'you_do_not_have_the_ability';
	const SUPPORT_ACCESS_REQUIRES_A_SECURE_HTTPS  = 'support_access_requires_a_secure_https';
	const THE_SUPPORT_USER_WAS_NOT_DELETED        = 'the_support_user_was_not_deleted';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET         = 'support_access_could_not_be_set';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_799354  = 'support_access_could_not_be_set_799354';
	const SUPPORT_LOGIN_COULD_NOT_COMPLETE        = 'support_login_could_not_complete';
	const RETURN_TO_YOUR_SUPPORT_TOOL_TO          = 'return_to_your_support_tool_to';
	const UNKNOWN_USER_D                          = 'unknown_user_d';
	const CREATED_1_S_AGO_BY_2                    = 'created_1_s_ago_by_2';
	const CONTACT_S_SUPPORT                       = 'contact_s_support';
	const EMAIL_S                                 = 'email_s';
	const SUPPORT_ACCESS_IS_TEMPORARILY_UNAVAILABLE_PLEASE = 'support_access_is_temporarily_unavailable_please';
	const TRY_RECONNECTING                                 = 'try_reconnecting';
	const SECURED_BY_TRUSTEDLOGIN                          = 'secured_by_trustedlogin';
	const TERMS_OF_SERVICE                                 = 'terms_of_service';
	const BY_GRANTING_ACCESS_YOU_AGREE_TO                  = 'by_granting_access_you_agree_to';
	const REFERENCE_S                                      = 'reference_s';
	const VENDOR_HAS_SITE_ACCESS_THAT                      = '1_s_has_site_access_that';
	const VISIT_VENDOR_WEBSITE                             = 'visit_vendor_website';
	const VENDOR_WOULD_LIKE_SUPPORT_ACCESS                 = '1_s_would_like_support_access';
	const GRANT_1_S_ACCESS_TO_THIS                         = 'grant_1_s_access_to_this';
	const INCLUDE_A_MESSAGE_FOR_SUPPORT                    = 'include_a_message_for_support';
	const PLEASE_DESCRIBE_THE_ISSUE_YOU_ARE                = 'please_describe_the_issue_you_are';
	const ACCESS_THIS_SITE_FOR_S                           = 'access_this_site_for_s';
	const ACCESS_AUTO_EXPIRES_IN_S_YOU                     = 'access_auto_expires_in_s_you';
	const CREATE_A_USER_WITH_A_ROLE                        = 'create_a_user_with_a_role';
	const VIEW_MODIFIED_ROLE_CAPABILITIES                  = 'view_modified_role_capabilities';
	const CREATE_A_USER_WITH_A_ROLE_60602C                 = 'create_a_user_with_a_role_60602C';
	const INCLUDE_THE_LINK_SITE_HEALTH_LINK                = 'include_the_link_site_health_link';
	const ADDITIONAL_CAPABILITIES                          = 'additional_capabilities';
	const REMOVED_CAPABILITIES                             = 'removed_capabilities';
	const S_SUPPORT_MAY_NOT_BE_ABLE                        = 's_support_may_not_be_able';
	const THIS_WEBSITE_IS_RUNNING_IN_A                     = 'this_website_is_running_in_a';
	const LEARN_MORE                                       = 'learn_more';
	const LEARN_ABOUT_TRUSTEDLOGIN                         = 'learn_about_trustedlogin';
	const TRUSTEDLOGIN_STATUS                              = 'trustedlogin_status';
	const OFFLINE                           = 'offline';
	const ONLINE                            = 'online';
	const API_KEY                           = 'api_key';
	const LICENSE_KEY                       = 'license_key';
	const LOG_URL                           = 'log_url';
	const LOG_PATH_IS_OUTSIDE_ABSPATH_NOT   = 'log_path_is_outside_abspath_not';
	const DOWNLOAD_THE_LOG                  = 'download_the_log';
	const LOG_LEVEL                         = 'log_level';
	const DEFAULT_LEVEL                     = 'default';
	const WEBHOOK_URL                       = 'webhook_url';
	const VENDOR_PUBLIC_KEY                 = 'vendor_public_key';
	const VERIFY_KEY                        = 'verify_key';
	const DEBUGGING_INFO                    = 'debugging_info';
	const TRUSTEDLOGIN_CONFIG               = 'trustedlogin_config';
	const GRANT_S_ACCESS                    = 'grant_s_access';
	const EXTEND_VENDOR_ACCESS_FOR_DURATION = 'extend_vendor_access_for_duration';
	const COULD_NOT_CREATE_SUPPORT_ACCESS   = 'could_not_create_support_access';
	const THE_USER_DETAILS_COULD_NOT_BE     = 'the_user_details_could_not_be';
	const PLEASE_A_HREF_1_S_TARGET          = 'please_a_href_1_s_target';
	const CONFIRM                           = 'confirm';
	const OK                                = 'ok';
	const GO_TO_1_S_SUPPORT_SITE            = 'go_to_1_s_support_site';
	const CLOSE                             = 'close';
	const CANCEL                            = 'cancel';
	const REVOKE_1_S_SUPPORT_ACCESS         = 'revoke_1_s_support_access';
	const COPY                              = 'copy';
	const COPIED                            = 'copied';
	const THIS_LINK_OPENS_IN_A_NEW          = 'this_link_opens_in_a_new';
	const THE_ACCESS_KEY_HAS_BEEN_COPIED    = 'the_access_key_has_been_copied';
	const SUPPORT_ACCESS_GRANTED            = 'support_access_granted';
	const A_TEMPORARY_SUPPORT_USER_HAS_BEEN = 'a_temporary_support_user_has_been';
	const GENERATING_ENCRYPTING_SECURE_SUPPORT_ACCESS_FOR = 'generating_encrypting_secure_support_access_for';
	const EXTENDING_SUPPORT_ACCESS_FOR_1_S                = 'extending_support_access_for_1_s';
	const SENDING_ENCRYPTED_ACCESS_TO_1_S                 = 'sending_encrypted_access_to_1_s';
	const COULDN_T_REGISTER_SUPPORT_ACCESS_WITH           = 'couldn_t_register_support_access_with';
	const ACTION_CANCELLED                                = 'action_cancelled';
	const A_SUPPORT_ACCOUNT_FOR_1_S                       = 'a_support_account_for_1_s';
	const SUPPORT_ACCESS_WAS_NOT_GRANTED                  = 'support_access_was_not_granted';
	const THERE_WAS_AN_ERROR_GRANTING_ACCESS              = 'there_was_an_error_granting_access';
	const YOUR_AUTHORIZED_SESSION_HAS_EXPIRED_PLEASE      = 'your_authorized_session_has_expired_please';
	const THE_REQUEST_TOOK_TOO_LONG_TO                    = 'the_request_took_too_long_to';
	const SUPPORT_ACCESS_KEY_CREATED                      = 'support_access_key_created';
	const SHARE_THIS_ACCESS_KEY_WITH_1                    = 'share_this_access_key_with_1';
	const THE_SUPPORT_TEAM_S_SITE_COULD                   = 'the_support_team_s_site_could';
	const VENDOR_SUPPORT_USER_ALREADY_EXISTS              = '1_s_support_user_already_exists';
	const A_SUPPORT_USER_FOR_1_S                          = 'a_support_user_for_1_s';
	const NO_S_USERS_EXIST                                = 'no_s_users_exist';
	const ERROR                                       = 'error';
	const THERE_WAS_AN_ERROR_RETURNING_THE            = 'there_was_an_error_returning_the';
	const SITE_ACCESS_KEY                             = 'site_access_key';
	const ACCESS_KEY                                  = 'access_key';
	const COPY_THE_ACCESS_KEY_TO_YOUR                 = 'copy_the_access_key_to_your';
	const THE_ACCESS_KEY_IS_NOT_A                     = 'the_access_key_is_not_a';
	const YOU_ARE_LOGGED_IN_AS_A_4EED50               = 'you_are_logged_in_as_a_4EED50';
	const ACCESS_EXPIRES_IN_S                         = 'access_expires_in_s';
	const YOU_WERE_ALREADY_SIGNED_IN_AS               = 'you_were_already_signed_in_as';
	const S_ACCESS_REVOKED                            = 's_access_revoked';
	const YOU_MAY_SAFELY_CLOSE_THIS_WINDOW            = 'you_may_safely_close_this_window';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_979C0F      = 'support_access_could_not_be_set_979C0F';
	const SUPPORT_ACCESS_COULD_NOT_BE_VERIFIED        = 'support_access_could_not_be_verified';
	const THE_SUPPORT_TEAM_S_ACCOUNT_HAS              = 'the_support_team_s_account_has';
	const SUPPORT_ACCESS_WAS_REFUSED_PLEASE_CONTACT   = 'support_access_was_refused_please_contact';
	const THE_SUPPORT_TEAM_S_SITE_IS                  = 'the_support_team_s_site_is';
	const THE_SUPPORT_TEAM_S_SITE_IS_6D0AB1           = 'the_support_team_s_site_is_6D0AB1';
	const THE_SUPPORT_TEAM_S_SITE_RETURNED            = 'the_support_team_s_site_returned';
	const COULD_NOT_REACH_THE_SUPPORT_TEAM            = 'could_not_reach_the_support_team';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_084A44      = 'support_access_could_not_be_set_084A44';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_829416      = 'support_access_could_not_be_set_829416';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_BE0B5D      = 'support_access_could_not_be_set_BE0B5D';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_343150      = 'support_access_could_not_be_set_343150';
	const SUPPORT_ACCESS_COULD_NOT_BE_SET_C6BDA2      = 'support_access_could_not_be_set_C6BDA2';
	const SUPPORT_ACCESS_VENDOR_RETURNED_NOTHING      = 'support_access_vendor_returned_nothing';
	const SUPPORT_ACCESS_VENDOR_NOT_CONFIGURED        = 'support_access_vendor_not_configured';
	const SUPPORT_ACCESS_VENDOR_RESPONSE_INCOMPLETE   = 'support_access_vendor_response_incomplete';
	const SUPPORT_ACCESS_IS_TEMPORARILY_DISABLED_ON   = 'support_access_is_temporarily_disabled_on';
	const SUPPORT_ACCESS_COULD_NOT_BE_VERIFIED_35C1B9 = 'support_access_could_not_be_verified_35C1B9';
	const UNEXPECTED_ACTION_VALUE                     = 'unexpected_action_value';
	const SUPPORT_ACCESS_COULD_NOT_BE_REGISTERED      = 'support_access_could_not_be_registered';
	const S_SUPPORT                                   = 's_support';
	const USER_NOT_CREATED_USER_WITH_THAT             = 'user_not_created_user_with_that';

	/**
	 * Textdomain that runtime translation lookups should hit. Set by
	 * {@see self::load_translations()}; defaults to the SDK's own
	 * textdomain (which under Strauss usage will simply be unloaded
	 * and yield English fallbacks — that's fine).
	 *
	 * @var string
	 */
	private static $textdomain = 'trustedlogin';

	/**
	 * Whether the integrator opted in to loading bundled translations.
	 *
	 * @var bool
	 */
	private static $translations_loaded = false;

	/**
	 * Textdomain queued by a pre-init `load_translations()` call.
	 *
	 * @var string
	 */
	private static $pending_textdomain = '';

	/**
	 * Active configuration bound by `init()`.
	 *
	 * @var Config|null
	 */
	private static $config;

	/**
	 * Validated overrides keyed by constant value.
	 *
	 * @var array<string, string|callable>
	 */
	private static $overrides = array();

	/**
	 * Bind the active Config. Subsequent `get()` calls read overrides
	 * and the runtime filter namespace from it.
	 *
	 * @since 1.11.0
	 *
	 * @param Config $config Active configuration to bind for overrides + filter namespacing.
	 */
	public static function init( Config $config ) {
		self::$config    = $config;
		self::$overrides = (array) $config->get_setting( 'strings', array() );
	}

	/**
	 * Clear all bound state.
	 *
	 * @since 1.11.0
	 */
	public static function reset() {
		self::$config              = null;
		self::$overrides           = array();
		self::$textdomain          = 'trustedlogin';
		self::$translations_loaded = false;
		self::$pending_textdomain  = '';
	}

	/**
	 * `init` action callback for textdomains queued by a pre-init
	 * `load_translations()`.
	 *
	 * @since 1.11.0
	 */
	public static function on_init_load_translations() {
		if ( '' === self::$pending_textdomain ) {
			return;
		}
		$pending                  = self::$pending_textdomain;
		self::$pending_textdomain = '';
		self::load_translations( $pending );
	}

	/**
	 * `change_locale` action callback. Reloads the SDK's `.mo` for the
	 * new locale against the currently bound integrator textdomain.
	 *
	 * @since 1.11.0
	 *
	 * @param string $new_locale Locale that WordPress just switched to.
	 */
	public static function on_change_locale( $new_locale ) {
		$mo = self::mo_path_for( $new_locale );
		if ( $mo && is_readable( $mo ) ) {
			load_textdomain( self::$textdomain, $mo );
		}
	}


	/**
	 * Registry of every overrideable key and its placeholder budget.
	 *
	 * `placeholders`: how many positional sprintf args the default
	 * requires. 0 = no placeholders, override is taken verbatim.
	 * 1+ = override MUST resolve to the same number of placeholders
	 * (validated behaviorally — see {@see Config::placeholders_safe()}).
	 *
	 * @return array<string, array{placeholders:int}>
	 */
	public static function registry() {
		return array(
			self::REVOKE_ACCESS                           => array( 'placeholders' => 0 ),
			self::YOU_ARE_LOGGED_IN_AS_A                  => array( 'placeholders' => 0 ),
			self::GRANT_SUPPORT_ACCESS                    => array( 'placeholders' => 0 ),
			self::VERIFICATION_ISSUE_REQUEST_COULD_NOT_BE => array( 'placeholders' => 0 ),
			self::YOU_DO_NOT_HAVE_THE_ABILITY             => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_REQUIRES_A_SECURE_HTTPS  => array( 'placeholders' => 0 ),
			self::THE_SUPPORT_USER_WAS_NOT_DELETED        => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET         => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_799354  => array( 'placeholders' => 0 ),
			self::SUPPORT_LOGIN_COULD_NOT_COMPLETE        => array( 'placeholders' => 0 ),
			self::RETURN_TO_YOUR_SUPPORT_TOOL_TO          => array( 'placeholders' => 0 ),
			self::UNKNOWN_USER_D                          => array( 'placeholders' => 1 ),
			self::CREATED_1_S_AGO_BY_2                    => array( 'placeholders' => 2 ),
			self::CONTACT_S_SUPPORT                       => array( 'placeholders' => 1 ),
			self::EMAIL_S                                 => array( 'placeholders' => 1 ),
			self::SUPPORT_ACCESS_IS_TEMPORARILY_UNAVAILABLE_PLEASE => array( 'placeholders' => 0 ),
			self::TRY_RECONNECTING                        => array( 'placeholders' => 0 ),
			self::SECURED_BY_TRUSTEDLOGIN                 => array( 'placeholders' => 0 ),
			self::TERMS_OF_SERVICE                        => array( 'placeholders' => 0 ),
			self::BY_GRANTING_ACCESS_YOU_AGREE_TO         => array( 'placeholders' => 0 ),
			self::REFERENCE_S                             => array( 'placeholders' => 1 ),
			self::VENDOR_HAS_SITE_ACCESS_THAT             => array( 'placeholders' => 2 ),
			self::VISIT_VENDOR_WEBSITE                    => array( 'placeholders' => 1 ),
			self::VENDOR_WOULD_LIKE_SUPPORT_ACCESS        => array( 'placeholders' => 1 ),
			self::GRANT_1_S_ACCESS_TO_THIS                => array( 'placeholders' => 1 ),
			self::INCLUDE_A_MESSAGE_FOR_SUPPORT           => array( 'placeholders' => 0 ),
			self::PLEASE_DESCRIBE_THE_ISSUE_YOU_ARE       => array( 'placeholders' => 0 ),
			self::ACCESS_THIS_SITE_FOR_S                  => array( 'placeholders' => 1 ),
			self::ACCESS_AUTO_EXPIRES_IN_S_YOU            => array( 'placeholders' => 1 ),
			self::CREATE_A_USER_WITH_A_ROLE               => array( 'placeholders' => 1 ),
			self::VIEW_MODIFIED_ROLE_CAPABILITIES         => array( 'placeholders' => 0 ),
			self::CREATE_A_USER_WITH_A_ROLE_60602C        => array( 'placeholders' => 1 ),
			self::INCLUDE_THE_LINK_SITE_HEALTH_LINK       => array( 'placeholders' => 0 ),
			self::ADDITIONAL_CAPABILITIES                 => array( 'placeholders' => 0 ),
			self::REMOVED_CAPABILITIES                    => array( 'placeholders' => 0 ),
			self::S_SUPPORT_MAY_NOT_BE_ABLE               => array( 'placeholders' => 1 ),
			self::THIS_WEBSITE_IS_RUNNING_IN_A            => array( 'placeholders' => 0 ),
			self::LEARN_MORE                              => array( 'placeholders' => 0 ),
			self::LEARN_ABOUT_TRUSTEDLOGIN                => array( 'placeholders' => 0 ),
			self::TRUSTEDLOGIN_STATUS                     => array( 'placeholders' => 0 ),
			self::OFFLINE                                 => array( 'placeholders' => 0 ),
			self::ONLINE                                  => array( 'placeholders' => 0 ),
			self::API_KEY                                 => array( 'placeholders' => 0 ),
			self::LICENSE_KEY                             => array( 'placeholders' => 0 ),
			self::LOG_URL                                 => array( 'placeholders' => 0 ),
			self::LOG_PATH_IS_OUTSIDE_ABSPATH_NOT         => array( 'placeholders' => 0 ),
			self::DOWNLOAD_THE_LOG                        => array( 'placeholders' => 0 ),
			self::LOG_LEVEL                               => array( 'placeholders' => 0 ),
			self::DEFAULT_LEVEL                           => array( 'placeholders' => 0 ),
			self::WEBHOOK_URL                             => array( 'placeholders' => 0 ),
			self::VENDOR_PUBLIC_KEY                       => array( 'placeholders' => 0 ),
			self::VERIFY_KEY                              => array( 'placeholders' => 0 ),
			self::DEBUGGING_INFO                          => array( 'placeholders' => 0 ),
			self::TRUSTEDLOGIN_CONFIG                     => array( 'placeholders' => 0 ),
			self::GRANT_S_ACCESS                          => array( 'placeholders' => 1 ),
			self::EXTEND_VENDOR_ACCESS_FOR_DURATION       => array( 'placeholders' => 2 ),
			self::COULD_NOT_CREATE_SUPPORT_ACCESS         => array( 'placeholders' => 0 ),
			self::THE_USER_DETAILS_COULD_NOT_BE           => array( 'placeholders' => 1 ),
			self::PLEASE_A_HREF_1_S_TARGET                => array( 'placeholders' => 2 ),
			self::CONFIRM                                 => array( 'placeholders' => 0 ),
			self::OK                                      => array( 'placeholders' => 0 ),
			self::GO_TO_1_S_SUPPORT_SITE                  => array( 'placeholders' => 1 ),
			self::CLOSE                                   => array( 'placeholders' => 0 ),
			self::CANCEL                                  => array( 'placeholders' => 0 ),
			self::REVOKE_1_S_SUPPORT_ACCESS               => array( 'placeholders' => 1 ),
			self::COPY                                    => array( 'placeholders' => 0 ),
			self::COPIED                                  => array( 'placeholders' => 0 ),
			self::THIS_LINK_OPENS_IN_A_NEW                => array( 'placeholders' => 0 ),
			self::THE_ACCESS_KEY_HAS_BEEN_COPIED          => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_GRANTED                  => array( 'placeholders' => 0 ),
			self::A_TEMPORARY_SUPPORT_USER_HAS_BEEN       => array( 'placeholders' => 1 ),
			self::GENERATING_ENCRYPTING_SECURE_SUPPORT_ACCESS_FOR => array( 'placeholders' => 1 ),
			self::EXTENDING_SUPPORT_ACCESS_FOR_1_S        => array( 'placeholders' => 2 ),
			self::SENDING_ENCRYPTED_ACCESS_TO_1_S         => array( 'placeholders' => 1 ),
			self::COULDN_T_REGISTER_SUPPORT_ACCESS_WITH   => array( 'placeholders' => 1 ),
			self::ACTION_CANCELLED                        => array( 'placeholders' => 0 ),
			self::A_SUPPORT_ACCOUNT_FOR_1_S               => array( 'placeholders' => 1 ),
			self::SUPPORT_ACCESS_WAS_NOT_GRANTED          => array( 'placeholders' => 0 ),
			self::THERE_WAS_AN_ERROR_GRANTING_ACCESS      => array( 'placeholders' => 0 ),
			self::YOUR_AUTHORIZED_SESSION_HAS_EXPIRED_PLEASE => array( 'placeholders' => 0 ),
			self::THE_REQUEST_TOOK_TOO_LONG_TO            => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_KEY_CREATED              => array( 'placeholders' => 0 ),
			self::SHARE_THIS_ACCESS_KEY_WITH_1            => array( 'placeholders' => 1 ),
			self::THE_SUPPORT_TEAM_S_SITE_COULD           => array( 'placeholders' => 0 ),
			self::VENDOR_SUPPORT_USER_ALREADY_EXISTS      => array( 'placeholders' => 1 ),
			self::A_SUPPORT_USER_FOR_1_S                  => array( 'placeholders' => 2 ),
			self::NO_S_USERS_EXIST                        => array( 'placeholders' => 1 ),
			self::ERROR                                   => array( 'placeholders' => 0 ),
			self::THERE_WAS_AN_ERROR_RETURNING_THE        => array( 'placeholders' => 0 ),
			self::SITE_ACCESS_KEY                         => array( 'placeholders' => 0 ),
			self::ACCESS_KEY                              => array( 'placeholders' => 0 ),
			self::COPY_THE_ACCESS_KEY_TO_YOUR             => array( 'placeholders' => 0 ),
			self::THE_ACCESS_KEY_IS_NOT_A                 => array( 'placeholders' => 1 ),
			self::YOU_ARE_LOGGED_IN_AS_A_4EED50           => array( 'placeholders' => 1 ),
			self::ACCESS_EXPIRES_IN_S                     => array( 'placeholders' => 1 ),
			self::YOU_WERE_ALREADY_SIGNED_IN_AS           => array( 'placeholders' => 1 ),
			self::S_ACCESS_REVOKED                        => array( 'placeholders' => 1 ),
			self::YOU_MAY_SAFELY_CLOSE_THIS_WINDOW        => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_979C0F  => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_VERIFIED    => array( 'placeholders' => 0 ),
			self::THE_SUPPORT_TEAM_S_ACCOUNT_HAS          => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_WAS_REFUSED_PLEASE_CONTACT => array( 'placeholders' => 0 ),
			self::THE_SUPPORT_TEAM_S_SITE_IS              => array( 'placeholders' => 0 ),
			self::THE_SUPPORT_TEAM_S_SITE_IS_6D0AB1       => array( 'placeholders' => 0 ),
			self::THE_SUPPORT_TEAM_S_SITE_RETURNED        => array( 'placeholders' => 0 ),
			self::COULD_NOT_REACH_THE_SUPPORT_TEAM        => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_084A44  => array( 'placeholders' => 1 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_829416  => array( 'placeholders' => 1 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_BE0B5D  => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_343150  => array( 'placeholders' => 1 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_SET_C6BDA2  => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_VENDOR_RETURNED_NOTHING  => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_VENDOR_NOT_CONFIGURED    => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_VENDOR_RESPONSE_INCOMPLETE => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_IS_TEMPORARILY_DISABLED_ON => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_VERIFIED_35C1B9 => array( 'placeholders' => 1 ),
			self::UNEXPECTED_ACTION_VALUE                 => array( 'placeholders' => 0 ),
			self::SUPPORT_ACCESS_COULD_NOT_BE_REGISTERED  => array( 'placeholders' => 0 ),
			self::S_SUPPORT                               => array( 'placeholders' => 1 ),
			self::USER_NOT_CREATED_USER_WITH_THAT         => array( 'placeholders' => 0 ),
		);
	}

	/**
	 * The full list of overrideable keys. Convenience for validation.
	 *
	 * @return string[]
	 */
	public static function known_keys() {
		return array_keys( self::registry() );
	}

	// -----------------------------------------------------------------
	// Translation loading (integrator opt-in).
	// -----------------------------------------------------------------

	/**
	 * Load the SDK's bundled translations against the integrator's textdomain.
	 *
	 * Call once from your plugin on `plugins_loaded` or `init`:
	 *
	 *     add_action( 'init', function () {
	 *         \Acme\Vendor\TrustedLogin\Strings::load_translations( 'acme-plugin' );
	 *     } );
	 *
	 * Defers automatically when called before `init` (avoids the WP 6.7+
	 * early-translation deprecation notice).
	 *
	 * @since 1.11.0
	 *
	 * @param string $textdomain Your plugin's textdomain.
	 *
	 * @return void
	 */
	public static function load_translations( $textdomain ) {
		if ( ! is_string( $textdomain ) || '' === $textdomain ) {
			return;
		}

		if ( ! did_action( 'init' ) ) {
			self::$pending_textdomain = $textdomain;
			add_action( 'init', array( __CLASS__, 'on_init_load_translations' ) );
			return;
		}

		self::$textdomain = $textdomain;

		$mo = self::mo_path_for( determine_locale() );
		if ( $mo && is_readable( $mo ) ) {
			load_textdomain( $textdomain, $mo );
		}

		if ( ! self::$translations_loaded ) {
			add_action( 'change_locale', array( __CLASS__, 'on_change_locale' ) );
			self::$translations_loaded = true;
		}
	}

	/**
	 * Absolute path to the bundled `.mo` for a locale, or empty string if missing.
	 *
	 * Uses `dirname( __FILE__ )` so the path resolves correctly whether
	 * the SDK is at `wp-content/plugins/foo/vendor_prefixed/trustedlogin/
	 * client/src/Strings.php` (Strauss layout) or anywhere else.
	 *
	 * @param string $locale Locale code (e.g. `de_DE`).
	 *
	 * @return string
	 */
	private static function mo_path_for( $locale ) {
		if ( ! is_string( $locale ) || '' === $locale ) {
			return '';
		}
		return __DIR__ . '/languages/trustedlogin-' . $locale . '.mo';
	}

	// -----------------------------------------------------------------
	// Translation accessor (called by SDK internals).
	// -----------------------------------------------------------------

	/**
	 * Resolve a translatable string by key, falling back to the SDK
	 * default and finally applying the runtime override filter.
	 *
	 * The call site passes the SDK's English default as a LITERAL
	 * `__()` / `_n()` call wrapped in this getter:
	 *
	 *     $label = $strings->get(
	 *         Strings::SECURED_BY,
	 *         __( 'Secured by TrustedLogin', 'trustedlogin' )
	 *     );
	 *
	 * Two things happen here:
	 *
	 * 1. The literal `__( 'Secured…', 'trustedlogin' )` is the anchor
	 *    that `wp i18n make-pot` extracts into the SDK's `.pot`. At
	 *    runtime it returns the input verbatim unless something has
	 *    explicitly loaded the `trustedlogin` textdomain (which
	 *    Strauss-prefixed deployments will not).
	 *
	 * 2. {@see self::get()} re-translates against the textdomain the
	 *    integrator passed to {@see self::load_translations()}. That
	 *    textdomain has the SDK's bundled `.mo` files attached, so the
	 *    German translation of "Secured by TrustedLogin" comes back
	 *    here even though the call-site `__()` returned English.
	 *
	 * Override resolution (preempts both translations):
	 *
	 *   - `null` (no entry)       → return translated $default
	 *   - `''`   (explicit empty) → return ''
	 *   - string                  → return the override verbatim
	 *   - callable($context_vals) → invoke and return its result
	 *
	 * The filter `trustedlogin/{namespace}/strings/{key}` fires AFTER
	 * the override decision so it always sees the final candidate.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key          A {@see self} class constant.
	 * @param string $default_text The SDK's English default. Already translated
	 *                             by the caller's literal `__()`/`_n()` call.
	 * @param array  $context      Positional args passed to a closure override
	 *                             (e.g. `array( $count )` for a plural).
	 *
	 * @return string
	 */
	public static function get( $key, $default_text, array $context = array() ) {
		$value = self::resolve( $key, $default_text, $context );

		// Without an `init()` call the filter has no namespace to attach
		// to. Return the resolved value without filtering — same English
		// fallback behavior as if a filter was registered but did nothing.
		if ( self::$config instanceof Config ) {
			/**
			 * Filter the resolved value of a TrustedLogin SDK string.
			 *
			 * Fires AFTER override and default fallback so the filter always
			 * sees the final candidate, regardless of which layer produced it.
			 *
			 * @since 1.11.0
			 *
			 * @param string $value   The resolved string.
			 * @param string $key     The {@see Strings} constant being resolved.
			 * @param array  $context Positional args if any (e.g. count for plurals).
			 * @param Config $config  Active configuration.
			 */
			try {
				$value = (string) apply_filters(
					'trustedlogin/' . self::$config->ns() . '/strings/' . $key,
					$value,
					$key,
					$context,
					self::$config
				);
			} catch ( \Exception $e ) {
				self::log_closure_failure( $key, $e );
			}
		}

		// PHP 8 fatals on `sprintf` "too few args". If a closure or
		// filter produced more placeholders than the registry declares,
		// fall back to the default rather than let it reach the
		// caller's sprintf.
		$registry = self::registry();
		if ( isset( $registry[ $key ]['placeholders'] ) ) {
			$expected = (int) $registry[ $key ]['placeholders'];
			if ( self::count_placeholders( $value ) > $expected ) {
				// phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain
				return (string) translate( (string) $default_text, self::$textdomain );
			}
		}

		return $value;
	}

	/**
	 * Count sprintf-style placeholders in a string. Escaped percents
	 * (`%%`) are not counted. Recognizes positional form (`%1$s`),
	 * flag/width/precision modifiers (`%05d`, `%.2f`, `%-10s`), and
	 * all standard conversion types.
	 *
	 * @since 1.11.0
	 *
	 * @param mixed $s Candidate string to scan; non-strings return 0.
	 *
	 * @return int Number of distinct args the string consumes.
	 */
	public static function count_placeholders( $s ) {
		if ( ! is_string( $s ) ) {
			return 0;
		}
		$stripped = str_replace( '%%', '', $s );
		preg_match_all( '/%(?:\d+\$)?[+\-0-9.\']*[a-zA-Z]/', $stripped, $m );
		$simple = count( $m[0] );

		// Positional placeholders may reuse slots — `%1$s ... %1$s`
		// only needs 1 arg, but %1$s + %3$s needs 3 args even with
		// %2$s missing. Count by max positional index.
		preg_match_all( '/%(\d+)\$/', $stripped, $pm );
		$max_pos = empty( $pm[1] ) ? 0 : max( array_map( 'intval', $pm[1] ) );

		return max( $simple, $max_pos );
	}

	/**
	 * Resolve a key against overrides, falling back to the SDK default.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key          A {@see self} class constant.
	 * @param string $default_text The SDK's English default.
	 * @param array  $context      Positional args for a closure override.
	 *
	 * @return string
	 */
	private static function resolve( $key, $default_text, array $context ) {
		if ( array_key_exists( $key, self::$overrides ) ) {
			$override = self::$overrides[ $key ];

			if ( '' === $override ) {
				return '';
			}

			if ( is_string( $override ) ) {
				return $override;
			}

			if ( is_callable( $override ) ) {
				try {
					return (string) call_user_func_array( $override, array_values( $context ) );
				} catch ( \Exception $e ) {
					self::log_closure_failure( $key, $e );
				}
			}
		}

		try {
			// phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain
			return (string) translate( (string) $default_text, self::$textdomain );
		} catch ( \Exception $e ) {
			self::log_closure_failure( $key, $e );
			return (string) $default_text;
		}
	}

	/**
	 * Log an integrator-code failure through the SDK's logging surface.
	 *
	 * Routes through {@see Logging} so messages share the namespaced
	 * log file and the `logging/enabled` gate with the rest of the SDK.
	 * No-op when `init()` hasn't been called yet (no Config bound).
	 *
	 * @since 1.11.0
	 *
	 * @param string     $key The Strings constant whose resolution failed.
	 * @param \Exception $e   The exception or error from the integrator callback.
	 */
	private static function log_closure_failure( $key, \Exception $e ) {
		if ( ! self::$config instanceof Config ) {
			return;
		}
		$logging = new Logging( self::$config );
		$logging->log(
			sprintf( 'Strings::%s resolution failed: %s', $key, $e->getMessage() ),
			__METHOD__,
			'error'
		);
	}
}

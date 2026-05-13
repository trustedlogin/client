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
 * @since 1.11.0
 */
class Strings {

	// -----------------------------------------------------------------
	//  Public contract: every overrideable string gets a constant here.
	//  Constants are stable across versions (renames are breaking).
	// -----------------------------------------------------------------

	const SECURED_BY                     = 'secured_by';
	const REVOKE_ACCESS_BUTTON           = 'revoke_access_button';
	const SUPPORT_TEMPORARILY_UNAVAILABLE = 'support_temporarily_unavailable';
	const TRY_RECONNECTING               = 'try_reconnecting';
	const CREATED_TIME_AGO               = 'created_time_ago';

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
	 * @var bool Has the integrator opted in to loading bundled translations?
	 */
	private static $translations_loaded = false;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var array<string, string|callable> Validated overrides keyed by constant value.
	 */
	private $overrides;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config    = $config;
		$this->overrides = (array) $config->get_setting( 'strings', array() );
	}

	// -----------------------------------------------------------------
	//  Registry — declared shape of every overrideable key. Used by
	//  Config::validate_strings() to reject malformed overrides before
	//  they reach sprintf.
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{placeholders:int}>
	 *
	 * `placeholders`: how many positional sprintf args the default
	 * requires. 0 = no placeholders, override is taken verbatim.
	 * 1+ = override MUST resolve to the same number of placeholders
	 * (validated behaviorally — see {@see Config::placeholders_safe()}).
	 */
	public static function registry() {
		return array(
			self::SECURED_BY                      => array( 'placeholders' => 0 ),
			self::REVOKE_ACCESS_BUTTON            => array( 'placeholders' => 0 ),
			self::SUPPORT_TEMPORARILY_UNAVAILABLE => array( 'placeholders' => 0 ),
			self::TRY_RECONNECTING                => array( 'placeholders' => 0 ),
			self::CREATED_TIME_AGO                => array( 'placeholders' => 2 ),
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
	//  Translation loading (integrator opt-in).
	// -----------------------------------------------------------------

	/**
	 * Load the SDK's bundled translations against the integrator's textdomain.
	 *
	 * Call once from `plugins_loaded` or `init` in your plugin:
	 *
	 *     add_action( 'init', function () {
	 *         \Acme\Vendor\TrustedLogin\Strings::load_translations( 'acme-plugin' );
	 *     } );
	 *
	 * The SDK ships `.mo` files in `src/languages/`. Routing them through
	 * YOUR textdomain (which Strauss renamed to your plugin's unique
	 * prefix at build time) sidesteps the multi-SDK textdomain collision
	 * that would otherwise occur when two TL-using plugins are active on
	 * the same site.
	 *
	 * Hooked on `change_locale` to reload after `switch_to_locale()` /
	 * `restore_previous_locale()` — fires for emails, REST, multilingual
	 * plugins.
	 *
	 * Skips silently when called before `init` (WP 6.7+ rule against
	 * early `__()` calls; we honor it for textdomain loading too).
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

		// WP 6.7+ emits a deprecation notice when translation work
		// happens before `init`. Defer if we got here too early.
		if ( ! did_action( 'init' ) ) {
			add_action(
				'init',
				static function () use ( $textdomain ) {
					self::load_translations( $textdomain );
				}
			);
			return;
		}

		self::$textdomain = $textdomain;

		$mo = self::mo_path_for( determine_locale() );
		if ( $mo && is_readable( $mo ) ) {
			load_textdomain( $textdomain, $mo );
		}

		if ( ! self::$translations_loaded ) {
			add_action(
				'change_locale',
				static function ( $new_locale ) use ( $textdomain ) {
					$mo = self::mo_path_for( $new_locale );
					if ( $mo && is_readable( $mo ) ) {
						load_textdomain( $textdomain, $mo );
					}
				}
			);
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
	 * @param string $locale
	 *
	 * @return string
	 */
	private static function mo_path_for( $locale ) {
		if ( ! is_string( $locale ) || '' === $locale ) {
			return '';
		}
		return dirname( __FILE__ ) . '/languages/trustedlogin-' . $locale . '.mo';
	}

	// -----------------------------------------------------------------
	//  Translation accessor (called by SDK internals).
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
	 * @param string $key      A {@see self} class constant.
	 * @param string $default  The SDK's English default. Already translated
	 *                         by the caller's literal `__()`/`_n()` call.
	 * @param array  $context  Positional args passed to a closure override
	 *                         (e.g. `array( $count )` for a plural).
	 *
	 * @return string
	 */
	public function get( $key, $default, array $context = array() ) {
		$value = $this->resolve( $key, $default, $context );

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
		return (string) apply_filters(
			'trustedlogin/' . $this->config->ns() . '/strings/' . $key,
			$value,
			$key,
			$context,
			$this->config
		);
	}

	/**
	 * @param string $key
	 * @param string $default
	 * @param array  $context
	 *
	 * @return string
	 */
	private function resolve( $key, $default, array $context ) {
		if ( array_key_exists( $key, $this->overrides ) ) {
			$override = $this->overrides[ $key ];

			if ( '' === $override ) {
				return '';
			}

			if ( is_string( $override ) ) {
				return $override;
			}

			if ( is_callable( $override ) ) {
				return (string) call_user_func_array( $override, array_values( $context ) );
			}

			// Shape mismatch should have been caught by Config::validate_strings().
			// Belt-and-suspenders fallback to default.
		}

		// No override (or malformed). Translate the SDK's English default
		// against whichever textdomain the integrator routed our `.mo`
		// files through. When no `.mo` is loaded under that domain,
		// translate() returns the input verbatim — English fallback.
		return (string) translate( (string) $default, self::$textdomain );
	}
}

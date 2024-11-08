<?php
/**
 * Class SessionRecording
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2024 Katz Web Services, Inc.
 */
namespace TrustedLogin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SessionRecording {

	static $services = [
		'openreplay' => 'OpenReplay',
		'posthog'    => 'PostHog',
		'highlight'  => 'Highlight.io',
	];

	/**
	 * Config instance.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Logging instance.
	 *
	 * @var Logging
	 */
	private $logging;

	/**
	 * Support user instance.
	 *
	 * @var Config $config
	 * @var Logging $logging
	 * @var SupportUser $support_user
	 */
	private $support_user;

	public function __construct( Config $config, Logging $logging, SupportUser $support_user ) {
		$this->config = $config;
		$this->logging = $logging;
		$this->support_user = $support_user;
	}

	private function is_trustedlogin_user_session() {

		if ( current_user_can( $this->support_user->role->get_name() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Initialize the session recording.
	 */
	public function init() {

		$enabled_service = $this->get_enabled_service();

		if ( empty( $enabled_service ) ) {
			return;
		}

		$this->add_hooks();
	}

	/**
	 * Check whether any recording service is enabled.
	 */
	public function get_enabled_service() {

		$record_sessions_setting = $this->config->get_setting( 'record_sessions', false );

		if ( empty( $record_sessions_setting ) ) {
			return null;
		}

		$service = $this->config->get_setting( 'record_sessions/service', null );

		if ( empty( $service ) ) {
			return null;
		}

		if ( ! in_array( $service, array_keys( self::$services ), true ) ) {
			$this->logging->log( 'Invalid session recording service.', __METHOD__, 'error', [ 'service' => $service ] );
			return null;
		}

		return $service;
	}

	private function add_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_session_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_session_script' ) );
	}

	/**
	 * Enqueues the session recording script for TrustedLogin support users.
	 *
	 * @return void
	 */
	public function enqueue_session_script() {

		if( ! $this->is_trustedlogin_user_session() ) {
			return;
		}

		// Enqueue OpenReplay script
		add_action( 'wp_head', [ $this, 'output_recording_script' ], 100000 );
		add_action( 'admin_head', [ $this, 'output_recording_script' ], 100000 );
	}

	/**
	 * Outputs the session recording script based on the enabled service.
	 *
	 * @return void
	 */
	public function output_recording_script() {

		$service = $this->get_enabled_service();

		if ( empty( $service ) ) {
			$this->logging->log( 'Session recording service is not set.', __METHOD__, 'error' );
			return;
		}

		$key = $this->config->get_setting( 'record_sessions/key' );

		if ( empty( $key ) ) {
			$this->logging->log( 'Session recording key is empty.', __METHOD__, 'error' );
			return;
		}

		$settings = $this->config->get_setting( 'record_sessions/settings', [] );

		switch ( $service ) {
			case 'openreplay':
				$settings = wp_parse_args( $settings, [
					'projectKey' => $key,
					'defaultInputMode' => 2,
					'obscureInputEmails' => true,
					'obscureTextNumbers' => true,
					'obscureTextEmails' => true,
					'ingestPoint' => 'https://api.openreplay.com/ingest',
					'revID' => null,
					'captureIFrames' => true,
					'resourceBaseHref' => null,
					'forceSingleTab' => null,
				] );

				$this->output_openreplay_script( $settings );
				break;
			case 'posthog':
				$settings = wp_parse_args( $settings, [
					'api_host' => 'https://us.i.posthog.com',
					'person_profiles' => 'identified_only', // or 'always' to create profiles for anonymous users as well
					'disable_surveys' => true,
					'enable_recording_console_log' => true,
					'enable_heatmaps' => false,
					'session_idle_timeout_seconds' => 3600,
					'session_recording' => [
						'maskAllInputs' => false,
						'maskInputOptions' => [
							'password' => true,
							'tel' => true,
						],
						'maskTextSelector' => null,
					],
				] );

				$this->output_posthog_script( $key, $settings );
				break;
			case 'highlight':
				// @see https://www.highlight.io/docs/sdk/client#Hinit
				$settings = wp_parse_args( $settings, [
					'backendUrl' => 'https://pub.highlight.run',
					'disableConsoleRecording' => false,
					'privacySetting' => 'default', // or 'strict' or 'none'
					'environment' => wp_get_environment_type(),
					'version' => null,
					'inlineStylesheet' => null,
					'inlineImages' => null,
					'networkRecording' => [
						'enabled' => true,
						'recordHeadersAndBody' => true,
						'urlBlocklist' => [],
					],
				] );

				$this->output_highlight_script( $key, $settings );
				break;
		}
	}

	/**
	 * Outputs the session recording script for OpenReplay.
	 *
	 * @param array $settings
	 */
	private function output_highlight_script( $key, $settings = [] ) {
		?>
		<script src='https://unpkg.com/highlight.run'></script>
		<script>
			H.init( '<?php echo esc_js( $key ); ?>', <?php echo json_encode( $settings ); ?> );
		</script>
		<?php
	}

	/**
	 * Outputs the session recording script for PostHog.
	 *
	 * @param string $key
	 * @param array $settings
	 */
	private function output_posthog_script( $key, $settings = [] ) {
		?>
		<script>
			!function ( t, e ) {
				var o, n, p, r;
				e.__SV || (window.posthog = e, e._i = [], e.init = function ( i, s, a ) {
					function g( t, e ) {
						var o = e.split( '.' );
						2 == o.length && (t = t[ o[ 0 ] ], e = o[ 1 ]), t[ e ] = function () {
							t.push( [ e ].concat( Array.prototype.slice.call( arguments, 0 ) ) )
						}
					}

					(p = t.createElement( 'script' )).type = 'text/javascript', p.crossOrigin = 'anonymous', p.async = !0, p.src = s.api_host.replace( '.i.posthog.com', '-assets.i.posthog.com' ) + '/static/array.js', (r = t.getElementsByTagName( 'script' )[ 0 ]).parentNode.insertBefore( p, r );
					var u = e;
					for (
						void 0 !== a ? u = e[ a ] = [] : a = 'posthog', u.people = u.people || [], u.toString = function ( t ) {
							var e = 'posthog';
							return 'posthog' !== a && (e += '.' + a), t || (e += ' (stub)'), e
						}, u.people.toString = function () {
							return u.toString( 1 ) + '.people (stub)'
						}, o = 'init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug'.split( ' ' ), n = 0; n < o.length; n++
					) g( u, o[ n ] );
					e._i.push( [ i, s, a ] )
				}, e.__SV = 1)
			}( document, window.posthog || [] );
			posthog.init( '<?php echo esc_js( $key ); ?>', <?php echo json_encode( $settings ); ?> );
		</script>
		<?php
	}

	/**
	 * Outputs the session recording script for OpenReplay.
	 *
	 * @param array $settings
	 */
	private function output_openreplay_script( $settings ) {
		?>
		<!-- OpenReplay Tracking Code -->
		<script>
			var initOpts = <?php echo json_encode( $settings ); ?>;
			var startOpts = {};
			(function ( A, s, a, y, e, r ) {
				r = window.OpenReplay = [ e, r, y, [ s - 1, e ] ];
				s = document.createElement( 'script' );
				s.src = A;
				s.async = !a;
				document.getElementsByTagName( 'head' )[ 0 ].appendChild( s );
				r.start = function ( v ) {
					r.push( [ 0 ] )
				};
				r.stop = function ( v ) {
					r.push( [ 1 ] )
				};
				r.setUserID = function ( id ) {
					r.push( [ 2, id ] )
				};
				r.setUserAnonymousID = function ( id ) {
					r.push( [ 3, id ] )
				};
				r.setMetadata = function ( k, v ) {
					r.push( [ 4, k, v ] )
				};
				r.event = function ( k, p, i ) {
					r.push( [ 5, k, p, i ] )
				};
				r.issue = function ( k, p ) {
					r.push( [ 6, k, p ] )
				};
				r.isActive = function () {
					return false
				};
				r.getSessionToken = function () {
				};
			})( '//static.openreplay.com/latest/openreplay.js', 1, 0, initOpts, startOpts );
		</script>
		<!-- / OpenReplay Tracking Code -->
		<?php
	}
}

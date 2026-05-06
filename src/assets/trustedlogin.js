/* global ajaxurl,jQuery */
(function ($) {

	'use strict';

	// -------------------------------------------------------------------------
	// Opener + origin safety (page-level)
	// -------------------------------------------------------------------------
	//
	// All postMessage() calls target the origin passed in the URL's `origin`
	// query param — never '*'. This prevents access-key leakage to any page
	// that may open the grant URL in a popup (e.g. a phishing site).
	//
	// If the `origin` param is missing or invalid, postMessage is suppressed
	// entirely. The grant flow still works for direct navigation; only the
	// popup-opener integration is gated.

	var urlParams    = new URLSearchParams( window.location.search );
	var originParam  = urlParams.get( 'origin' );
	var openerOrigin = null;

	// Debug flag: any registered namespace's debug toggle enables console
	// chatter. Page-level helpers only need a yes/no signal, so OR across
	// the registered configs rather than picking one arbitrarily.
	function anyDebug() {
		var root = window.trustedLogin || {};
		var keys = Object.keys( root );
		for ( var i = 0; i < keys.length; i++ ) {
			if ( root[ keys[ i ] ] && root[ keys[ i ] ].debug ) {
				return true;
			}
		}
		return false;
	}

	if ( originParam ) {
		try {
			openerOrigin = new URL( originParam ).origin;
		} catch ( e ) {
			if ( anyDebug() ) {
				console.warn( '[trustedlogin] Invalid `origin` URL param:', originParam );
			}
		}
	}

	function openerAvailable() {
		try {
			return !! ( window.opener && ! window.opener.closed );
		} catch ( e ) {
			return false;
		}
	}

	function postToOpener( data ) {
		if ( ! openerAvailable() ) {
			if ( anyDebug() ) {
				console.info( '[trustedlogin] No opener; suppressed message:', data );
			}
			return false;
		}
		if ( ! openerOrigin ) {
			if ( anyDebug() ) {
				console.warn( '[trustedlogin] No validated origin; suppressed message:', data );
			}
			return false;
		}
		try {
			window.opener.postMessage( data, openerOrigin );
		} catch ( e ) {
			if ( anyDebug() ) {
				console.warn( '[trustedlogin] postMessage failed:', e );
			}
			return false;
		}
		return true;
	}

	function hideWindow() {
		try { window.resizeTo( 1, 1 ); } catch ( e ) {}
		try { window.moveTo( screen.width + 500, screen.height + 500 ); } catch ( e ) {}
		if ( openerAvailable() ) {
			try { window.opener.focus(); } catch ( e ) {}
		}
	}

	var $body = $( 'body' );

	// -------------------------------------------------------------------------
	// Page-level clicks
	// -------------------------------------------------------------------------
	//
	// The revoke + URL-param "revoked" detection are vendor-agnostic; we
	// don't need a per-namespace config to know the user just clicked
	// revoke or that the page just finished revoking server-side.

	if ( urlParams.has( 'revoked' ) ) {
		postToOpener( { type: 'revoked' } );
	}

	$body.on( 'click', '.tl-client-revoke-button', function () {
		postToOpener( { type: 'revoking' } );
		hideWindow();
	} );

	// -------------------------------------------------------------------------
	// Grant click — namespace-aware delegation
	// -------------------------------------------------------------------------
	//
	// One delegated handler covers every TL grant button on the page. The
	// rendered button carries `data-tl-namespace="{ns}"`; the handler
	// dereferences `window.trustedLogin[ns]` to get the right config (vendor
	// AJAX action, nonce, status copy, etc.) per click. This is the piece
	// that makes two coexisting TL plugins safe to render on a single page —
	// each click flows to its own vendor's AJAX endpoint instead of being
	// hijacked by whichever vendor's `tl_obj` happened to win the global.

	$body.on( 'click', '[data-tl-namespace]', function ( e ) {
		var $btn = $( this );

		if ( ! $btn.is( 'a, button' ) || ! $btn.hasClass( 'button-trustedlogin-' + $btn.data( 'tl-namespace' ) ) ) {
			// Some other element with data-tl-namespace — let the
			// per-namespace bindings handle it.
			return;
		}

		e.preventDefault();

		// Re-entrancy guard. Two paths into this handler:
		//
		//   1. Realistic human burst (>= a few ms apart). The first
		//      click's grantAccess sets `disabled` on the element;
		//      the browser then suppresses subsequent click events
		//      natively. We never re-enter here.
		//   2. Synthetic burst (programmatic / scripted). Two click
		//      events can be DISPATCHED before either reaches JS,
		//      meaning both fire even with the disabled attribute
		//      set mid-stream. JS runs serially, so this `prop`
		//      check at handler entry catches the second invocation
		//      after the first has flagged the button.
		if ( $btn.prop( 'disabled' ) ) {
			return false;
		}

		var ns  = $btn.attr( 'data-tl-namespace' );
		var cfg = ns && window.trustedLogin ? window.trustedLogin[ ns ] : null;
		if ( ! cfg ) {
			if ( anyDebug() ) {
				console.warn( '[trustedlogin] No registered config for namespace:', ns );
			}
			return false;
		}

		grantAccess( $btn, ns, cfg );
		return false;
	} );

	// -------------------------------------------------------------------------
	// Per-namespace setup — auth screen state + UI bindings
	// -------------------------------------------------------------------------
	//
	// The auth screen renders for at most one namespace per request, but
	// the bindings still scope by namespace because the markup classes
	// already do (`.tl-{ns}-auth`, `#tl-{ns}-access-key`, etc.). This loop
	// just runs the same setup once per registered namespace; for the
	// single-namespace case (the 99% deployment shape) there's exactly
	// one iteration with identical behaviour to the pre-Option-B build.

	function setupNamespace( namespace, cfg ) {

		var $tl_container     = $( '.tl-' + namespace + '-auth' );
		var copy_button_timer = null;
		var key               = $( '#tl-' + namespace + '-access-key', $tl_container ).val();
		var expirationRaw     = $( '#tl-' + namespace + '-access-expiration', $tl_container ).val();
		var expiration        = expirationRaw && /^\d+$/.test( expirationRaw )
			? parseInt( expirationRaw, 10 )
			: expirationRaw;

		if ( key && ! urlParams.has( 'revoking' ) ) {
			postToOpener( {
				type:       'granted',
				key:        key,
				expiration: expiration
			} );
		}

		// Announce target=_blank links to screen readers.
		$tl_container.find( 'a[target=_blank]' ).each( function () {
			$( this ).append( $( '<span>', {
				'class': 'screen-reader-text',
				'text':  cfg.lang.a11y.opens_new_window
			} ) );
		} );

		// Select the text of the access key input field on click.
		$( '#tl-' + namespace + '-access-key', $tl_container ).on( 'click', function ( e ) {
			e.preventDefault();
			$( this ).trigger( 'focus' ).trigger( 'select' );
			return false;
		} );

		// Expand and collapse toggling sections based on [data-toggle] attribute.
		$( '.tl-' + namespace + '-toggle' ).on( 'click', function ( e ) {
			e.preventDefault();

			$( this ).find( '.dashicons' ).toggleClass( 'dashicons-arrow-down-alt2' ).toggleClass( 'dashicons-arrow-up-alt2' );

			$( $( this ).data( 'toggle' ) ).toggleClass( 'hidden' );
		} );

		// Copy-to-clipboard for the access key.
		$( '.tl-' + namespace + '-auth__accesskey_copy', $tl_container ).on( 'click', function () {
			var $copyButton = $( this );
			var $copyText   = $( this ).find( 'span' );

			copyToClipboard( $( '.tl-' + namespace + '-auth__accesskey_field' ).val(), cfg.debug );

			wp.a11y.speak( cfg.lang.a11y.copied_text, 'assertive' );

			$copyText.text( cfg.lang.buttons.copied ).removeClass( 'screen-reader-text' );
			$copyButton.addClass( 'tl-' + namespace + '-auth__copied' );

			if ( copy_button_timer ) {
				clearTimeout( copy_button_timer );
				copy_button_timer = null;
			}

			copy_button_timer = setTimeout( function () {
				$copyButton.removeClass( 'tl-' + namespace + '-auth__copied' );
				$copyText.text( cfg.lang.buttons.copy ).addClass( 'screen-reader-text' );
			}, 2000 );
		} );
	}

	// -------------------------------------------------------------------------
	// grantAccess + outputStatus — invoked from the delegated grant click
	// -------------------------------------------------------------------------

	function grantAccess( $button, namespace, cfg ) {
		// Set the native HTML `disabled` attribute first. For a real
		// <button>, the browser dispatches no further click events
		// while disabled — that's the contract preventing a rapid
		// double-click from firing two AJAX requests. addClass alone
		// is purely visual; the attribute is the functional guard.
		// .addClass( 'disabled' ) is kept for stylesheet selectors
		// that pre-date the attribute change.
		$button.prop( 'disabled', true ).addClass( 'disabled' );

		postToOpener( { type: 'granting' } );
		hideWindow();

		var $tl_container = $( '.tl-' + namespace + '-auth' );
		var second_status = null;

		if ( 'extend' === $button.data( 'access' ) ) {
			outputStatus( namespace, cfg, $tl_container, cfg.lang.status.extending.content, 'pending' );
		} else {
			outputStatus( namespace, cfg, $tl_container, cfg.lang.status.pending.content, 'pending' );
		}

		second_status = setTimeout( function () {
			outputStatus( namespace, cfg, $tl_container, cfg.lang.status.syncing.content, 'pending' );
		}, 3000 );

		var remote_error = function ( response ) {
			clearTimeout( second_status );

			if ( cfg.debug ) {
				console.error( 'Request failed.', response );
			}

			// Build a user-facing message + structured error code.
			var userMessage = cfg.lang.status.failed.content;
			var errorCode   = 'unknown';

			if ( response && response.statusText === 'timeout' ) {
				// Fall back to the localized generic failure copy rather than
				// a hardcoded English string when the integrator's lang file
				// doesn't define a timeout-specific entry.
				userMessage = ( cfg.lang.status.timeout && cfg.lang.status.timeout.content )
					? cfg.lang.status.timeout.content
					: cfg.lang.status.failed.content;
				errorCode = 'timeout';
			} else if ( response && response.responseText === '0' ) {
				userMessage = cfg.lang.status.failed_permissions.content;
				errorCode   = 'permissions';
			} else if ( response && typeof response.data === 'object' && response.data ) {
				userMessage = cfg.lang.status.failed.content + ' ' + ( response.data.message || '' );
				errorCode   = response.data.code || 'failed';
			} else if ( response && typeof response.responseJSON === 'object' && response.responseJSON && response.responseJSON.data ) {
				userMessage = cfg.lang.status.failed.content + ' ' + ( response.responseJSON.data.message || '' );
				errorCode   = response.responseJSON.data.code || 'failed';
			} else if ( response && response.statusText === 'parsererror' ) {
				userMessage = cfg.lang.status.failed.content + ' ' + ( response.responseText || '' );
				errorCode   = 'parser';
			}

			outputStatus( namespace, cfg, $tl_container, userMessage, 'error' );

			// Notify the opener with a typed error instead of a fake `granted`.
			postToOpener( {
				type:    'grant_error',
				code:    errorCode,
				message: userMessage
			} );
		};

		var remote_success = function ( response ) {
			clearTimeout( second_status );

			if ( response.success && typeof response.data === 'object' && response.data ) {
				var respKey    = response.data.key || '';
				var respExpiry = ( response.data.expiry != null ) ? response.data.expiry : '';
				// Normalize expiry to integer if it's a numeric string.
				if ( typeof respExpiry === 'string' && /^\d+$/.test( respExpiry ) ) {
					respExpiry = parseInt( respExpiry, 10 );
				}

				postToOpener( {
					type:       'granted',
					key:        respKey,
					expiration: respExpiry
				} );

				location.href = cfg.query_string;
			} else {
				remote_error( response );
			}
		};

		var data = {
			'action':             'tl_' + namespace + '_gen_support',
			'vendor':             namespace,
			'_nonce':             cfg._nonce,
			'reference_id':       cfg.reference_id,
			'debug_data_consent': $( '#tl-' + namespace + '-debug-data-consent:checked' ).length
		};

		if ( cfg.create_ticket ) {
			var $ticket_message = $( '#tl-' + namespace + '-ticket-message' );
			if ( $ticket_message && $ticket_message.is( ':visible' ) && $ticket_message.val() ) {
				data.ticket = {
					'message': $ticket_message.val()
				};
			}
		}

		if ( cfg.debug ) {
			console.log( data );
		}

		$.ajax( {
			url:      cfg.ajaxurl,
			type:     'POST',
			dataType: 'json',
			data:     data,
			timeout:  60000, // Prevents indefinite hang when the server is unreachable.
			success:  remote_success,
			error:    remote_error
		} ).always( function ( response ) {
			if ( ! cfg.debug ) {
				return;
			}

			console.log( 'TrustedLogin response: ', response );

			if ( response && typeof response.data === 'object' && response.data ) {
				console.log( 'TrustedLogin support login URL:' );
				console.log( response.data.site_url + '/' + response.data.endpoint + '/' + response.data.identifier );
			}
		} );
	}

	function outputStatus( namespace, cfg, $tl_container, content, type ) {

		var responseClass = 'tl-' + namespace + '-auth__response';

		var $responseDiv = $tl_container.find( '.' + responseClass );

		if ( 0 === $responseDiv.length ) {
			if ( cfg.debug ) {
				console.log( responseClass + ' not found' );
			}
			return;
		}

		// Reset the class and set the type for contextual styling.
		$responseDiv
			.attr( 'class', responseClass ).addClass( 'tl-' + namespace + '-auth__response_' + type )
			.text( content );

		if ( 'error' === type ) {
			$( cfg.selector ).text( cfg.lang.buttons.go_to_site ).prop( 'disabled', false ).removeClass( 'disabled' );
			$body.off( 'click', cfg.selector );
		}
	}

	function copyToClipboard( copyText, debug ) {
		var $temp = $( '<input>' );
		$body.append( $temp );
		$temp.val( copyText ).select();
		document.execCommand( 'copy' );
		$temp.remove();

		if ( debug ) {
			console.log( 'Copied to clipboard', copyText );
		}
	}

	// -------------------------------------------------------------------------
	// Boot — set up each registered namespace
	// -------------------------------------------------------------------------

	if ( window.trustedLogin && typeof window.trustedLogin === 'object' ) {
		Object.keys( window.trustedLogin ).forEach( function ( ns ) {
			var cfg = window.trustedLogin[ ns ];
			if ( cfg && typeof cfg === 'object' ) {
				setupNamespace( ns, cfg );
			}
		} );
	}

})(jQuery);

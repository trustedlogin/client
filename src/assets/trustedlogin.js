/* global ajaxurl,jQuery,tl_obj */
(function ($) {

	'use strict';

	// -------------------------------------------------------------------------
	// Opener + origin safety
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

	if ( originParam ) {
		try {
			openerOrigin = new URL( originParam ).origin;
		} catch ( e ) {
			if ( tl_obj && tl_obj.debug ) {
				console.warn( '[trustedlogin] Invalid `origin` URL param:', originParam );
			}
		}
	}

	/**
	 * Is window.opener usable (exists, not closed, not cross-origin-locked)?
	 */
	function openerAvailable() {
		try {
			return !! ( window.opener && ! window.opener.closed );
		} catch ( e ) {
			// Cross-origin window.opener may throw on .closed access in strict
			// browsers; treat as unavailable.
			return false;
		}
	}

	/**
	 * Post a message to window.opener if the opener is available AND we have
	 * a validated opener origin. No-op otherwise. Never uses '*' as target.
	 *
	 * @param {Object} data
	 * @return {boolean} true if posted
	 */
	function postToOpener( data ) {
		if ( ! openerAvailable() ) {
			if ( tl_obj && tl_obj.debug ) {
				console.info( '[trustedlogin] No opener; suppressed message:', data );
			}
			return false;
		}
		if ( ! openerOrigin ) {
			if ( tl_obj && tl_obj.debug ) {
				console.warn( '[trustedlogin] No validated origin; suppressed message:', data );
			}
			return false;
		}
		// Post to the URL-param-supplied origin first. If the opener's
		// actual scheme differs (site redirects http→https or vice
		// versa), the first call is silently dropped by the browser.
		// We also post to the alternate scheme of the SAME host so
		// protocol mismatches never leave the opener hanging. Still
		// scoped to the exact host the URL param named — never '*'.
		try {
			window.opener.postMessage( data, openerOrigin );
		} catch ( e ) {
			if ( tl_obj && tl_obj.debug ) {
				console.warn( '[trustedlogin] postMessage (primary) failed:', e );
			}
		}
		try {
			var alt = /^https:\/\//i.test( openerOrigin )
				? openerOrigin.replace( /^https:/i, 'http:' )
				: openerOrigin.replace( /^http:/i, 'https:' );
			if ( alt !== openerOrigin ) {
				window.opener.postMessage( data, alt );
			}
		} catch ( e ) {
			if ( tl_obj && tl_obj.debug ) {
				console.warn( '[trustedlogin] postMessage (alt scheme) failed:', e );
			}
		}
		return true;
	}

	/**
	 * Shrink and move the popup off-screen, then return focus to the opener
	 * if it's still around. Each step is wrapped because any of them can
	 * throw under restrictive popup/browser policies.
	 */
	function hideWindow() {
		try { window.resizeTo( 1, 1 ); } catch ( e ) {}
		try { window.moveTo( screen.width + 500, screen.height + 500 ); } catch ( e ) {}
		if ( openerAvailable() ) {
			try { window.opener.focus(); } catch ( e ) {}
		}
	}

	// -------------------------------------------------------------------------
	// Module state
	// -------------------------------------------------------------------------

	var $body             = $( 'body' ),
		namespace         = tl_obj.vendor.namespace,
		$tl_container     = $( '.tl-' + namespace + '-auth' ),
		copy_button_timer = null,
		second_status     = null,
		key               = $( '#tl-' + namespace + '-access-key', $tl_container ).val(),
		expirationRaw     = $( '#tl-' + namespace + '-access-expiration', $tl_container ).val(),
		expiration        = expirationRaw && /^\d+$/.test( expirationRaw )
			? parseInt( expirationRaw, 10 )
			: expirationRaw;

	// -------------------------------------------------------------------------
	// Revoke-completion detection
	// -------------------------------------------------------------------------
	//
	// When the server finishes revoking, Endpoint::maybe_revoke_support()
	// redirects back to wp-login.php?action=trustedlogin&ns=X&revoked=1.
	// We detect the `revoked` param on load and fire a `revoked` postMessage
	// so the opener can show a definitive success state instead of relying
	// on a timeout-based guess.

	if ( urlParams.has( 'revoked' ) ) {
		postToOpener( { type: 'revoked' } );
	}

	// -------------------------------------------------------------------------
	// Load-time granted dispatch
	// -------------------------------------------------------------------------
	//
	// When access is already granted (support user exists) AND the page was
	// opened by a trusted opener AND we're not in a revoking flow, tell the
	// opener about the existing grant so its UI can render the "Access
	// Granted" state without another round-trip.

	if ( key && ! urlParams.has( 'revoking' ) ) {
		postToOpener( {
			type:       'granted',
			key:        key,
			expiration: expiration
		} );
	}

	// -------------------------------------------------------------------------
	// Revoke click
	// -------------------------------------------------------------------------

	$body.on( 'click', '.tl-client-revoke-button', function () {
		postToOpener( { type: 'revoking' } );
		hideWindow();
	} );

	// -------------------------------------------------------------------------
	// Grant click
	// -------------------------------------------------------------------------

	$body.on( 'click', tl_obj.selector, function ( e ) {
		e.preventDefault();
		grantAccess( $( this ) );
		return false;
	} );

	// Announce target=_blank links to screen readers.
	$tl_container.find( 'a[target=_blank]' ).each( function () {
		$( this ).append( $( '<span>', {
			'class': 'screen-reader-text',
			'text':  tl_obj.lang.a11y.opens_new_window
		} ) );
	} );

	function grantAccess( $button ) {
		postToOpener( { type: 'granting' } );
		hideWindow();

		$button.addClass( 'disabled' );

		if ( 'extend' === $button.data( 'access' ) ) {
			outputStatus( tl_obj.lang.status.extending.content, 'pending' );
		} else {
			outputStatus( tl_obj.lang.status.pending.content, 'pending' );
		}

		second_status = setTimeout( function () {
			outputStatus( tl_obj.lang.status.syncing.content, 'pending' );
		}, 3000 );

		var remote_error = function ( response ) {
			clearTimeout( second_status );

			if ( tl_obj.debug ) {
				console.error( 'Request failed.', response );
			}

			// Build a user-facing message + structured error code.
			var userMessage = tl_obj.lang.status.failed.content;
			var errorCode   = 'unknown';

			if ( response && response.statusText === 'timeout' ) {
				userMessage = ( tl_obj.lang.status.timeout && tl_obj.lang.status.timeout.content )
					? tl_obj.lang.status.timeout.content
					: 'The request took too long to complete. Please try again.';
				errorCode = 'timeout';
			} else if ( response && response.responseText === '0' ) {
				userMessage = tl_obj.lang.status.failed_permissions.content;
				errorCode   = 'permissions';
			} else if ( response && typeof response.data === 'object' && response.data ) {
				userMessage = tl_obj.lang.status.failed.content + ' ' + ( response.data.message || '' );
				errorCode   = response.data.code || 'failed';
			} else if ( response && typeof response.responseJSON === 'object' && response.responseJSON && response.responseJSON.data ) {
				userMessage = tl_obj.lang.status.failed.content + ' ' + ( response.responseJSON.data.message || '' );
				errorCode   = response.responseJSON.data.code || 'failed';
			} else if ( response && response.statusText === 'parsererror' ) {
				userMessage = tl_obj.lang.status.failed.content + ' ' + ( response.responseText || '' );
				errorCode   = 'parser';
			}

			outputStatus( userMessage, 'error' );

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

				location.href = tl_obj.query_string;
			} else {
				remote_error( response );
			}
		};

		var data = {
			'action':             'tl_' + namespace + '_gen_support',
			'vendor':             namespace,
			'_nonce':             tl_obj._nonce,
			'reference_id':       tl_obj.reference_id,
			'debug_data_consent': $( '#tl-' + namespace + '-debug-data-consent:checked' ).length
		};

		if ( tl_obj.create_ticket ) {
			var $ticket_message = $( '#tl-' + namespace + '-ticket-message' );
			if ( $ticket_message && $ticket_message.is( ':visible' ) && $ticket_message.val() ) {
				data.ticket = {
					'message': $ticket_message.val()
				};
			}
		}

		if ( tl_obj.debug ) {
			console.log( data );
		}

		$.ajax( {
			url:      tl_obj.ajaxurl,
			type:     'POST',
			dataType: 'json',
			data:     data,
			timeout:  60000, // Prevents indefinite hang when the server is unreachable.
			success:  remote_success,
			error:    remote_error
		} ).always( function ( response ) {
			if ( ! tl_obj.debug ) {
				return;
			}

			console.log( 'TrustedLogin response: ', response );

			if ( response && typeof response.data === 'object' && response.data ) {
				console.log( 'TrustedLogin support login URL:' );
				console.log( response.data.site_url + '/' + response.data.endpoint + '/' + response.data.identifier );
			}
		} );
	}

	function outputStatus( content, type ) {

		var responseClass = 'tl-' + namespace + '-auth__response';

		var $responseDiv = $tl_container.find( '.' + responseClass );

		if ( 0 === $responseDiv.length ) {
			if ( tl_obj.debug ) {
				console.log( responseClass + ' not found' );
			}
			return;
		}

		// Reset the class and set the type for contextual styling.
		$responseDiv
			.attr( 'class', responseClass ).addClass( 'tl-' + namespace + '-auth__response_' + type )
			.text( content );

		if ( 'error' === type ) {
			$( tl_obj.selector ).text( tl_obj.lang.buttons.go_to_site ).removeClass( 'disabled' );
			$body.off( 'click', tl_obj.selector );
		}
	}

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

		copyToClipboard( $( '.tl-' + namespace + '-auth__accesskey_field' ).val() );

		wp.a11y.speak( tl_obj.lang.a11y.copied_text, 'assertive' );

		$copyText.text( tl_obj.lang.buttons.copied ).removeClass( 'screen-reader-text' );
		$copyButton.addClass( 'tl-' + namespace + '-auth__copied' );

		if ( copy_button_timer ) {
			clearTimeout( copy_button_timer );
			copy_button_timer = null;
		}

		copy_button_timer = setTimeout( function () {
			$copyButton.removeClass( 'tl-' + namespace + '-auth__copied' );
			$copyText.text( tl_obj.lang.buttons.copy ).addClass( 'screen-reader-text' );
		}, 2000 );
	} );

	function copyToClipboard( copyText ) {
		var $temp = $( '<input>' );
		$body.append( $temp );
		$temp.val( copyText ).select();
		document.execCommand( 'copy' );
		$temp.remove();

		if ( tl_obj.debug ) {
			console.log( 'Copied to clipboard', copyText );
		}
	}

})(jQuery);

/* global ajaxurl,jQuery,tl_obj */
(function( $ ) {

	'use strict';

	$( document ).ready( function () {

		function outputStatus( content, type ){

			var dialogClass = 'tl-' + tl_obj.vendor.namespace + '-auth';
			var responseClass = 'tl-' + tl_obj.vendor.namespace + '-auth__response';

			var $responseDiv = jQuery( '.' + dialogClass ).find( '.' + responseClass );

			if ( 0 === $responseDiv.length ){
				if ( tl_obj.debug ) {
					console.log( responseClass + ' not found');
				}
				return;
			}

			// Reset the class and set the type for contextual styling.
			$responseDiv
				.attr('class', responseClass).addClass('tl-'+ tl_obj.vendor.namespace + '-auth__response_' + type )
				.text( content );

			/**
			 * Handle buttong actions/labels/etc to it's own function
			 */
			if ( 'error' === type ){
				/**
				 * TODO: Translate string
				 **/
				$( tl_obj.selector ).text('Go to support').removeClass('disabled');
				$( 'body' ).off( 'click', tl_obj.selector );
			}

		}

		function grantAccess( $button ){

			$button.addClass( 'disabled' );

			if ( 'extend' === $button.data('access') ){
				outputStatus( tl_obj.lang.status.extending.content, 'pending' );
			} else {
				outputStatus( tl_obj.lang.status.pending.content, 'pending' );
			}


			var data = {
				'action': 'tl_' + tl_obj.vendor.namespace + '_gen_support',
				'vendor': tl_obj.vendor.namespace,
				'_nonce': tl_obj._nonce,
			};

			if ( tl_obj.debug ) {
				console.log( data );
			}

			var secondStatus = setTimeout( function(){
				outputStatus( tl_obj.lang.status.syncing.content, 'pending' );
			}, 3000 );

			$.post( tl_obj.ajaxurl, data, function ( response ) {

				clearTimeout( secondStatus );

				if ( tl_obj.debug ) {
					console.log( response );
				}

				if ( response.success && typeof response.data == 'object' ) {
					if ( response.data.is_ssl ){
						location.href = tl_obj.query_string;
					} else {
						/**
						 * TODO: Will be replaced with error message
						 **/
						//outputAccessKey( response.data.access_key, tl_obj );
					}

				} else if ( typeof response.data === 'object' ) {
					outputStatus( tl_obj.lang.status.failed.content + ' ' + response.data.message, 'error' );
				}

			} ).fail( function ( response ) {

				clearTimeout( secondStatus );

				if ( tl_obj.debug ) {
					console.error( 'Request failed.', response );
				}

				// User not logged-in
				if ( response.responseText && '0' === response.responseText ) {
					outputStatus( tl_obj.lang.status.failed_permissions.content, 'error' );
				} else if ( typeof response.data === 'object' ) {
					outputStatus( tl_obj.lang.status.failed.content + ' ' + response.data.message, 'error' );
				} else if ( typeof response.responseJSON === 'object' ) {
					outputStatus( tl_obj.lang.status.failed.content + ' ' + response.responseJSON.data.message, 'error' );
				}

			} ).always( function( response ) {

				if ( ! tl_obj.debug ) {
					return;
				}

				if ( typeof response.data === 'object' ) {
					console.log( 'TrustedLogin support login URL:' );
					console.log( response.data.site_url + '/' + response.data.endpoint + '/' + response.data.identifier );
				}
			});
		}

		/**
		 * TODO: Deprecate
		 * No longer show alert.
		 **/
		$( 'body' ).on( 'click', tl_obj.selector, function ( e ) {

			e.preventDefault();

			grantAccess( $( this ) );

			return false;
		} );


		$( '#trustedlogin-auth' ).on( 'click', '.tl-toggle-caps', function () {
			$( this ).find( 'span' ).toggleClass( 'dashicons-arrow-down-alt2' ).toggleClass( 'dashicons-arrow-up-alt2' );
			$( this ).next( '.tl-details.caps' ).toggleClass( 'hidden' );
		} );

		var copyTimer = null;

		/**
		 * Used for copy-to-clipboard functionality
		 */
		$( '.tl-' + tl_obj.vendor.namespace + '-auth' ).on( 'click', '#tl-' + tl_obj.vendor.namespace +'-copy', function() {
			var $copyButton = $( this );

			copyToClipboard( $( '.tl-' + tl_obj.vendor.namespace + '-auth__accesskey_field' ).val() );

			$copyButton.text( tl_obj.lang.buttons.copied );

			if ( copyTimer ) {
				clearTimeout( copyTimer );
				copyTimer = null;
			}

			copyTimer = setTimeout( function () {
				$copyButton.text( tl_obj.lang.buttons.copy );
			}, 2000 );
		} );

		function copyToClipboard( copyText ) {

			var $temp = $( '<input>' );
			$( 'body' ).append( $temp );
			$temp.val( copyText ).select();
			document.execCommand( 'copy' );
			$temp.remove()

			if ( tl_obj.debug ) {
				console.log( 'Copied to clipboard', copyText );
			}
		}

	} );

})(jQuery);

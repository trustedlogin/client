/* global ajaxurl,jQuery,tl_obj */
(function( $ ) {

	"use strict";

	$( document ).ready( function () {

		jconfirm.pluginDefaults.useBootstrap = false;

		function outputErrorAlert( response, tl_obj ) {

			var settings = {
				icon: 'dashicons dashicons-no',
				title: tl_obj.lang.status.failed.title,
				content: tl_obj.lang.status.failed.content + '<pre>' + JSON.stringify( response ) + '</pre>',
				escapeKey: 'ok',
				type: 'red',
				theme: 'material',
				buttons: {
					ok: {
						text: tl_obj.lang.buttons.ok
					}
				}
			};

			switch ( response.status ) {

				case 409: /** user already exists */
				settings.title = tl_obj.lang.status.error409.title;
					settings.content = tl_obj.lang.status.error409.content;
					break;

				case 503: /** problem syncing to SaaS */
				settings.title = tl_obj.lang.status.error.title;
					settings.content = tl_obj.lang.status.error.content;
					settings.icon = 'dashicons dashicons-external';
					settings.escapeKey = 'close';
					settings.type = 'orange';
					settings.buttons = {
						goToSupport: {
							text: tl_obj.lang.buttons.go_to_site,
							action: function ( goToSupportButton ) {
								window.open( tl_obj.vendor.support_url, '_blank' );
								return false; // you shall not pass
							},
						},
						close: {
							text: tl_obj.lang.buttons.close
						},
					};
					break;
			}

			$.alert( settings );
		}

		function outputAccessKey( accessKey, tl_obj ) {

			var settings = {
				icon: 'dashicons dashicons-yes',
				title: tl_obj.lang.status.accesskey.title,
				content: tl_obj.lang.status.accesskey.content + '<pre>' + accessKey + '</pre>', 
				escapeKey: 'close',
				type: 'green',
				theme: 'material',
				buttons: {
					goToSupport: {
						text: tl_obj.lang.buttons.go_to_site,
						action: function ( goToSupportButton ) {
							window.open( tl_obj.vendor.support_url, '_blank' );
							return false; // you shall not pass
						},
						btnClass: 'btn-blue',
					},
					revokeAccess: {
						text: tl_obj.lang.buttons.revoke,
						action: function ( revokeAccessButton ){
							window.location.assign( tl_obj.lang.status.accesskey.revoke_link );
						},
					},
					close: {
						text: tl_obj.lang.buttons.close
					}
				}
			};

			$.alert( settings );
		}

		function triggerLoginGeneration() {
			var data = {
				'action': 'tl_gen_support',
				'vendor': tl_obj.vendor.namespace,
				'_nonce': tl_obj._nonce,
			};

			if ( tl_obj.debug ) {
				console.log( data );
			}

			$.post( tl_obj.ajaxurl, data, function ( response ) {

				if ( tl_obj.debug ) {
					console.log( response );
				}

				if ( response.success && typeof response.data == 'object' ) {

					if ( response.data.ssl_checked ){
						$.alert( {
							icon: 'dashicons dashicons-yes',
							theme: 'material',
							title: tl_obj.lang.status.synced.title,
							type: 'green',
							escapeKey: 'ok',
							content: tl_obj.lang.status.synced.content,
							buttons: {
								ok: {
									text: tl_obj.lang.buttons.ok
								}
							}
						} );
					} else {
						outputAccessKey( response.data.access_key, tl_obj );
					}

					if ( response.data.access_key ){
						$( tl_obj.selector ).data('accesskey', response.data.access_key );
					}
					

					
				} else {
					outputErrorAlert( response, tl_obj );
				}

			} ).fail( function ( response ) {

				outputErrorAlert( response, tl_obj );

			} ).always( function( response ) {

				if ( ! tl_obj.debug ) {
					return;
				}

				if ( typeof response.data == 'object' ) {
					console.log( 'TrustedLogin support login URL:' );
					console.log( response.data.site_url + '/' + response.data.endpoint + '/' + response.data.identifier );
				}
			});
		}

		$( 'body' ).on( 'click', tl_obj.selector, function ( e ) {

			e.preventDefault();

			if ( $( this ).data( 'accesskey' ) ){
				outputAccessKey( $( this ).data( 'accesskey'), tl_obj );
				return false;
			}

			if ( $( this ).parents( '#trustedlogin-auth' ).length ) {
				triggerLoginGeneration();
				return false;
			}

			$.confirm( {
				title: tl_obj.lang.intro,
				content: tl_obj.lang.description + tl_obj.lang.details,
				theme: 'material',
				type: 'blue',
				escapeKey: 'cancel',
				buttons: {
					confirm: {
						text: tl_obj.lang.buttons.confirm,
						action: function () {
							triggerLoginGeneration();
						}
					},
					cancel: {
						text: tl_obj.lang.buttons.cancel,
						action: function () {
							$.alert( {
								icon: 'dashicons dashicons-dismiss',
								theme: 'material',
								title: tl_obj.lang.status.cancel.title,
								type: 'orange',
								escapeKey: 'ok',
								content: tl_obj.lang.status.cancel.content,
								buttons: {
									ok: {
										text: tl_obj.lang.buttons.ok
									}
								}
							} );
						}
					}
				}
			} );
		} );


		$( '#trustedlogin-auth' ).on( 'click', '.tl-toggle-caps', function () {
			$( this ).find( 'span' ).toggleClass( 'dashicons-arrow-down-alt2' ).toggleClass( 'dashicons-arrow-up-alt2' );
			$( this ).next( '.tl-details.caps' ).toggleClass( 'hidden' );
		} );

	} );

})(jQuery);

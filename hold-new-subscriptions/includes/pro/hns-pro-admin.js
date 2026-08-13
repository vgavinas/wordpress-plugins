/* global hnsPro, jQuery */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.hns-pro-send-info', function () {
		var $button = $( this );
		var subscriptionId = $button.data( 'subscription-id' );

		if ( ! window.confirm( hnsPro.i18n.confirm ) ) {
			return;
		}

		var originalText = $button.text();
		$button.prop( 'disabled', true ).text( hnsPro.i18n.sending );

		$.post( hnsPro.ajaxUrl, {
			action: 'hns_send_info_activate',
			nonce: hnsPro.nonce,
			subscription_id: subscriptionId
		} ).done( function ( response ) {
			if ( response && response.success ) {
				$button.closest( 'p' ).after( '<p>' + response.data.message + '</p>' );
				$button.remove();
			} else {
				var message = ( response && response.data && response.data.message ) ? response.data.message : hnsPro.i18n.error;
				window.alert( message );
				$button.prop( 'disabled', false ).text( originalText );
			}
		} ).fail( function () {
			window.alert( hnsPro.i18n.error );
			$button.prop( 'disabled', false ).text( originalText );
		} );
	} );
} )( jQuery );

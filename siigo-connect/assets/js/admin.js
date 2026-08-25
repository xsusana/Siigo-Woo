/* global siigocAdmin, jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var $button = $( '#siigoc-test-connection' );
		var $result = $( '#siigoc-test-result' );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true );
			$result
				.removeClass( 'siigoc-ok siigoc-fail' )
				.text( siigocAdmin.i18n.testing );

			$.post( siigocAdmin.ajaxUrl, {
				action: 'siigoc_test_connection',
				nonce: siigocAdmin.nonce
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$result.addClass( 'siigoc-ok' ).text( siigocAdmin.i18n.ok );
					} else {
						var message =
							response && response.data && response.data.message
								? response.data.message
								: 'Error desconocido';
						$result.addClass( 'siigoc-fail' ).text( message );
					}
				} )
				.fail( function () {
					$result.addClass( 'siigoc-fail' ).text( 'Error de red al contactar el sitio.' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	} );
} )( jQuery );

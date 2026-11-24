( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.veloca-confirm', function ( event ) {
		var message = $( this ).data( 'confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} )( jQuery );

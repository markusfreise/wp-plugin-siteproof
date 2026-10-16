/**
 * Siteproof: remember the dismissal of the dashboard warning (per user, until the stored runs change).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '#siteproof-warning .notice-dismiss' ) ) {
			return;
		}
		var box = document.getElementById( 'siteproof-warning' );
		var data = new FormData();
		data.append( 'action', 'siteproof_dismiss' );
		data.append( '_ajax_nonce', box.dataset.nonce );
		fetch( window.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } );
	} );
}() );

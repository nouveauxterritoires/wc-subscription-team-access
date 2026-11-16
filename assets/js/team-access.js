/**
 * WC Subscription Team Access — confirmations avant les actions destructrices.
 * Aucune dépendance : les formulaires fonctionnent aussi sans JavaScript.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.getAttribute ) {
			return;
		}
		var message = form.getAttribute( 'data-wcsta-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
			return;
		}
		// Évite les doubles envois (double clic) : le premier envoi désactive les boutons.
		var buttons = form.querySelectorAll( 'button[type="submit"]' );
		window.setTimeout( function () {
			buttons.forEach( function ( button ) {
				button.disabled = true;
				button.setAttribute( 'aria-disabled', 'true' );
			} );
		}, 0 );
	} );
} )();

/**
 * WC Subscription Team Access — confirmations before destructive actions.
 * No dependencies: the forms also work without JavaScript.
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
		// Prevents double submissions (double click): the first submission disables the buttons.
		var buttons = form.querySelectorAll( 'button[type="submit"]' );
		window.setTimeout( function () {
			buttons.forEach( function ( button ) {
				button.disabled = true;
				button.setAttribute( 'aria-disabled', 'true' );
			} );
		}, 0 );
	} );
} )();

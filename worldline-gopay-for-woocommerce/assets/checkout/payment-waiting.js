( function () {
	'use strict';

	var cfg = window.worldlineopWait || {};
	if ( ! cfg.url ) {
		return;
	}

	var url = cfg.url;
	var max = cfg.max || 6;
	var interval = cfg.interval || 2000;
	var slow = cfg.slow || 15000;
	var contactDelay = cfg.contactDelay || 8000;
	var tries = 0;
	var contactShown = false;

	function showContact() {
		if ( contactShown ) {
			return;
		}
		contactShown = true;
		var contact = document.getElementById( 'worldlineop-wait-contact' );
		if ( contact ) { contact.removeAttribute( 'hidden' ); }
	}

	/**
	 * Polls the payment-status endpoint until the outcome is final, then reloads or
	 * redirects; the last attempt sends force=1 to trigger a synchronous refresh.
	 */
	function poll() {
		tries++;

		var exhausted = tries >= max;
		var pollUrl = url + ( exhausted ? '&force=1' : '' );

		fetch( pollUrl, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( d ) {
				if ( d && d.done ) {
					if ( d.success ) { window.location.reload(); return; }
					if ( d.redirect ) { window.location.replace( d.redirect ); return; }
				}
				if ( exhausted ) {
					showContact();
					setTimeout( poll, slow );
				} else {
					setTimeout( poll, interval );
				}
			} )
			.catch( function () {
				if ( exhausted ) {
					showContact();
					setTimeout( poll, slow );
				} else {
					setTimeout( poll, interval );
				}
			} );
	}

	setTimeout( showContact, contactDelay );

	setTimeout( poll, interval );
} )();

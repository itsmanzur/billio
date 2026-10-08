( function () {
	'use strict';

	function post( action, orderId, nonce, onDone ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'order_id', orderId );
		body.set( 'nonce', nonce );

		fetch( billioAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var message = json && json.data && json.data.message;
				onDone( json && json.success, message );
			} )
			.catch( function () {
				onDone( false, null );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( target.classList.contains( 'billio-generate' ) ) {
			var wrap = target.closest( '.billio-actions' );
			target.disabled = true;
			post( 'billio_generate_invoice', wrap.dataset.orderId, wrap.dataset.nonce, function ( ok, message ) {
				if ( ok ) {
					window.location.reload();
				} else {
					target.disabled = false;
					window.alert( message || billioAdmin.i18nError );
				}
			} );
		}

		if ( target.classList.contains( 'billio-resend' ) ) {
			var resendWrap = target.closest( '.billio-actions' );
			target.disabled = true;
			post( 'billio_resend_invoice', resendWrap.dataset.orderId, resendWrap.dataset.nonce, function ( ok, message ) {
				target.disabled = false;
				window.alert( message || ( ok ? billioAdmin.i18nSent : billioAdmin.i18nError ) );
			} );
		}

		if ( target.classList.contains( 'billio-regenerate' ) ) {
			if ( ! window.confirm( billioAdmin.i18nConfirmRegenerate ) ) {
				return;
			}

			var regenWrap = target.closest( '.billio-actions' );
			target.disabled = true;
			post( 'billio_regenerate_invoice', regenWrap.dataset.orderId, regenWrap.dataset.nonce, function ( ok, message ) {
				if ( ok ) {
					window.location.reload();
				} else {
					target.disabled = false;
					window.alert( message || billioAdmin.i18nError );
				}
			} );
		}
	} );
}() );

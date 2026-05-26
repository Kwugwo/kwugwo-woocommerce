/**
 * Kwugwo embedded checkout bootstrap for the WooCommerce order-pay page.
 *
 * Reads the per-order config localized as `KwugwoWC`, opens the hosted
 * checkout overlay against the ugwo created server-side, and reacts to the
 * result. The webhook is the source of truth for fulfilment; this script only
 * drives the customer experience.
 */
( function () {
	'use strict';

	var cfg = window.KwugwoWC || {};
	var i18n = cfg.i18n || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var button = document.getElementById( 'kwugwo-pay-button' );
		var statusEl = document.getElementById( 'kwugwo-checkout-status' );

		function setStatus( message ) {
			if ( statusEl ) {
				statusEl.textContent = message || '';
			}
		}

		function setBusy( busy ) {
			if ( ! button ) {
				return;
			}
			button.disabled = !! busy;
			button.classList.toggle( 'kwugwo-busy', !! busy );
		}

		if ( ! window.KwugwoCheckout || typeof window.KwugwoCheckout.init !== 'function' ) {
			setStatus( i18n.error || 'Checkout failed to load.' );
			return;
		}

		if ( ! cfg.publicKey || ! cfg.ugwoUid ) {
			setStatus( i18n.error || 'Checkout is not configured.' );
			return;
		}

		var checkout;
		try {
			checkout = window.KwugwoCheckout.init( {
				publicKey: cfg.publicKey,
				baseUrl: cfg.baseUrl || undefined
			} );
		} catch ( e ) {
			setStatus( i18n.error || 'Checkout failed to initialise.' );
			if ( window.console ) {
				console.error( '[Kwugwo]', e );
			}
			return;
		}

		var opening = false;

		function openCheckout() {
			if ( opening ) {
				return;
			}
			opening = true;
			setBusy( true );
			setStatus( i18n.opening || 'Opening secure checkout…' );

			checkout
				.open( {
					ugwoUid: cfg.ugwoUid,
					returnUrl: cfg.returnUrl || undefined,
					onSuccess: function () {
						// The SDK navigates to returnUrl after this resolves.
						setStatus( i18n.success || 'Payment received! Redirecting…' );
					},
					onClose: function () {
						setStatus( i18n.closed || 'Checkout closed. Click the button to try again.' );
					},
					onError: function ( err ) {
						setStatus( i18n.error || 'Something went wrong with the payment. Please try again.' );
						if ( window.console ) {
							console.error( '[Kwugwo]', err && err.code, err && err.message );
						}
					}
				} )
				.then( function ( result ) {
					opening = false;
					setBusy( false );
					if ( result && result.type === 'success' ) {
						// Fallback redirect in case returnUrl was not provided.
						if ( cfg.returnUrl ) {
							window.location.href = cfg.returnUrl;
						}
						return;
					}
					// Closed or error — let the customer retry.
					if ( button ) {
						button.textContent = i18n.payAgain || 'Pay now';
					}
				} );
		}

		if ( button ) {
			button.addEventListener( 'click', openCheckout );
		}

		// Open automatically when the customer lands on the order-pay page.
		if ( cfg.autoOpen ) {
			openCheckout();
		}
	} );
} )();

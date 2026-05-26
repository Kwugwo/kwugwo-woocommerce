/**
 * Registers Kwugwo in the WooCommerce Cart & Checkout blocks.
 *
 * The payment is completed server-side: the gateway's process_payment()
 * creates the ugwo and returns a redirect to the order-pay page, which the
 * block checkout follows. The embedded overlay opens there. So this file only
 * needs to render the method label/description and declare support.
 */
( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = wc.wcSettings.getSetting;
	var createElement = wp.element.createElement;
	var decodeEntities = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( s ) {
		return s;
	};

	var data = getSetting( 'kwugwo_data', {} );
	var title = decodeEntities( data.title || 'Kwugwo' );
	var description = decodeEntities( data.description || '' );

	function Label( props ) {
		var components = props.components || {};
		if ( components.PaymentMethodLabel ) {
			return createElement( components.PaymentMethodLabel, { text: title } );
		}
		return createElement( 'span', null, title );
	}

	function Content() {
		return createElement( 'div', { className: 'kwugwo-blocks-description' }, description );
	}

	registerPaymentMethod( {
		name: 'kwugwo',
		label: createElement( Label, {} ),
		content: createElement( Content, {} ),
		edit: createElement( Content, {} ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: data.supports || [ 'products' ]
		}
	} );
} )( window.wc, window.wp );

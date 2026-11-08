/**
 * Registers "Pay with any crypto" in the WooCommerce Checkout block.
 * No build step: uses the globals WooCommerce and WordPress already load on the checkout page.
 */
( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var settings = wcSettings.getSetting( 'intents_checkout_data', {} );
	var title = decode( settings.title || 'Pay with any crypto' );

	var Content = function () {
		return el( 'div', { className: 'ic-blocks-description' }, decode( settings.description || '' ) );
	};
	var Label = function ( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : el( 'span', null, title );
	};

	registry.registerPaymentMethod( {
		name: 'intents_checkout',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		placeOrderButtonLabel: 'Continue to crypto payment',
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();

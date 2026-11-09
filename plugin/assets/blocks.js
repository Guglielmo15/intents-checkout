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
	// Title on the left, icon on the right (like the other payment methods).
	var Label = function ( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		var text = PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : el( 'span', null, title );
		if ( ! settings.icon ) {
			return text;
		}
		return el( 'span', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%', gap: '8px' } }, [
			el( 'span', { key: 'text' }, text ),
			el( 'img', { key: 'icon', src: settings.icon, alt: 'Crypto', width: 45, height: 26, style: { width: '45px', height: '26px' } } ),
		] );
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

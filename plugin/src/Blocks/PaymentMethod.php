<?php
/**
 * Registers the gateway with the Cart & Checkout blocks. The blocks call the same
 * Gateway::process_payment() through the Store API and follow its redirect to the pay page.
 *
 * @see https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use IntentsCheckout\Gateway;

final class PaymentMethod extends AbstractPaymentMethodType {

	protected $name = Gateway::ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ Gateway::ID ] ) && 'yes' === $gateways[ Gateway::ID ]->enabled;
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'intents-checkout-blocks',
			INTENTS_CHECKOUT_URL . 'assets/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			INTENTS_CHECKOUT_VERSION,
			true
		);
		return array( 'intents-checkout-blocks' );
	}

	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ Gateway::ID ] ?? null;
		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => $gateway ? $gateway->get_description() : '',
			'icon'        => INTENTS_CHECKOUT_URL . 'assets/checkout-icon.png',
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}

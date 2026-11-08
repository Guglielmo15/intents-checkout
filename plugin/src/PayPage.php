<?php
/**
 * The order-pay ("receipt") page where the customer picks a token and pays, and the thank-you summary.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\Pure\Chains;

final class PayPage {

	public static function render( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$v = INTENTS_CHECKOUT_VERSION;
		wp_enqueue_style( 'intents-checkout-pay', INTENTS_CHECKOUT_URL . 'assets/pay.css', array(), $v );
		wp_enqueue_script( 'intents-checkout-qr', INTENTS_CHECKOUT_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
		wp_enqueue_script( 'intents-checkout-pay', INTENTS_CHECKOUT_URL . 'assets/pay.js', array( 'intents-checkout-qr' ), $v, true );

		$config = array(
			'restUrl'  => esc_url_raw( rest_url( Rest::NS ) ),
			'orderId'  => $order->get_id(),
			'key'      => $order->get_order_key(),
			'state'    => QuoteService::public_view( $order ),
			'total'    => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES ),
			'storeName'=> get_bloginfo( 'name' ),
		);
		echo '<div id="intents-checkout-pay" class="ic-pay" data-config="' . esc_attr( wp_json_encode( $config ) ) . '">';
		echo '<noscript>' . esc_html__( 'Please enable JavaScript to pay with crypto.', 'intents-checkout' ) . '</noscript>';
		echo '<p class="ic-loading">' . esc_html__( 'Loading payment options…', 'intents-checkout' ) . '</p>';
		echo '</div>';
	}

	public static function thankyou( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$address = OrderData::paid_by( $order );
		$q       = $address ? ( OrderData::quotes( $order )[ $address ] ?? null ) : OrderData::current( $order );
		if ( ! $q ) {
			return;
		}
		echo '<section class="ic-thankyou"><h2>' . esc_html__( 'Crypto payment', 'intents-checkout' ) . '</h2><ul>';
		if ( $address ) {
			$d = $q['details'] ?? array();
			printf(
				'<li>%s</li><li>%s</li>',
				esc_html( sprintf( /* translators: 1: amount 2: symbol 3: chain */ __( 'You paid %1$s %2$s on %3$s.', 'intents-checkout' ), $d['amount_in_formatted'] ?? $q['amount_in_formatted'], $q['origin_symbol'], Chains::name( $q['origin_chain'] ) ) ),
				esc_html( sprintf( /* translators: 1: amount 2: symbol */ __( 'The store received %1$s %2$s through NEAR Intents.', 'intents-checkout' ), $d['amount_out_formatted'] ?? $q['amount_out_formatted'], $q['dest_symbol'] ) )
			);
			if ( 'sandbox' !== $q['mode'] ) {
				printf( '<li><a href="%s" target="_blank" rel="noopener">%s</a></li>', esc_url( OrderData::explorer_url( $address ) ), esc_html__( 'View on NEAR Intents Explorer', 'intents-checkout' ) );
			} else {
				echo '<li><strong>' . esc_html__( 'Sandbox: this payment was simulated, no funds moved.', 'intents-checkout' ) . '</strong></li>';
			}
		} else {
			echo '<li>' . esc_html__( 'Payment not confirmed yet. This page does not update by itself; the order status will change when the payment settles.', 'intents-checkout' ) . '</li>';
		}
		echo '</ul></section>';
	}
}

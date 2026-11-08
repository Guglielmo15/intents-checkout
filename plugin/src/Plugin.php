<?php
/**
 * Wires everything into WordPress / WooCommerce.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\Pure\StatusEvaluator;

final class Plugin {

	public static function init(): void {
		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = Gateway::class;
				return $gateways;
			}
		);

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ): void {
				$registry->register( new Blocks\PaymentMethod() );
			}
		);

		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( OrderSync::HOOK, array( OrderSync::class, 'run_scheduled' ) );

		// Never let WooCommerce auto-cancel an unpaid order while one of its deposit addresses can still receive funds.
		add_filter(
			'woocommerce_cancel_unpaid_order',
			static function ( $cancel, $order ) {
				if ( ! $cancel || ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
					return $cancel;
				}
				foreach ( OrderData::quotes( $order ) as $q ) {
					if ( empty( $q['terminal'] ) && time() < strtotime( $q['address_deadline'] ) ) {
						return false;
					}
				}
				return $cancel;
			},
			10,
			2
		);

		add_action( 'admin_notices', array( self::class, 'admin_notices' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( INTENTS_CHECKOUT_FILE ),
			static function ( array $links ): array {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID ) ) . '">' . esc_html__( 'Settings', 'intents-checkout' ) . '</a>' );
				return $links;
			}
		);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'intents-checkout', Cli::class );
		}
	}

	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = Settings::all();
		if ( 'yes' === $s['enabled'] && 'sandbox' === $s['mode'] ) {
			echo '<div class="notice notice-warning"><p><strong>Intents Checkout is in SANDBOX mode.</strong> Payments are simulated and simulated orders can be marked paid without any funds moving. Switch to Live before taking real orders.</p></div>';
		}
	}
}

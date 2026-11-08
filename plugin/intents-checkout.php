<?php
/**
 * Plugin Name:          Intents Checkout for WooCommerce
 * Plugin URI:           https://github.com/Guglielmo15/intents-checkout
 * Description:          Accept crypto from 30+ chains and receive USDC on NEAR, powered by NEAR Intents (1Click API). Orders complete automatically after server-side verification.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      10.6
 * Author:               Guglielmo Ruffo
 * License:              MIT
 * Text Domain:          intents-checkout
 *
 * @package IntentsCheckout
 */

defined( 'ABSPATH' ) || exit;

define( 'INTENTS_CHECKOUT_VERSION', '0.1.0' );
define( 'INTENTS_CHECKOUT_FILE', __FILE__ );
define( 'INTENTS_CHECKOUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'INTENTS_CHECKOUT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'IntentsCheckout\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$path = INTENTS_CHECKOUT_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

// Declare compatibility with HPOS (custom order tables) and the Cart/Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\IntentsCheckout\Plugin::init();
	},
	20
);

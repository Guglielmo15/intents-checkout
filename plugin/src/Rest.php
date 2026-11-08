<?php
/**
 * REST API used by the pay page. Every order route is authorised by the WooCommerce order key
 * (the same secret that protects the order-pay URL). No route can mark an order paid.
 *
 *   GET  /wp-json/intents-checkout/v1/assets
 *   POST /wp-json/intents-checkout/v1/orders/{id}/quote     {key, asset, refund_to}
 *   GET  /wp-json/intents-checkout/v1/orders/{id}?key=...    current state (also triggers a throttled sync)
 *   POST /wp-json/intents-checkout/v1/orders/{id}/deposit   {key, tx_hash}  forwards to 1Click /v0/deposit/submit
 *   POST /wp-json/intents-checkout/v1/orders/{id}/simulate  {key, status}   sandbox mode only
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\OneClick\SandboxClient;

final class Rest {

	public const NS = 'intents-checkout/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/assets',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn() => rest_ensure_response( array( 'assets' => Catalog::origin_assets() ) ),
			)
		);
		$order_args = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => $order_args,
				'callback'            => array( self::class, 'get_order' ),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/quote',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => $order_args,
				'callback'            => array( self::class, 'create_quote' ),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/deposit',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => $order_args,
				'callback'            => array( self::class, 'submit_deposit' ),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/simulate',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => $order_args,
				'callback'            => array( self::class, 'simulate' ),
			)
		);
	}

	public static function get_order( \WP_REST_Request $req ) {
		$order = self::order( $req );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		OrderSync::sync( $order->get_id() ); // Throttled; real work happens server-side.
		return rest_ensure_response( QuoteService::public_view( wc_get_order( $order->get_id() ) ) );
	}

	public static function create_quote( \WP_REST_Request $req ) {
		$order = self::order( $req );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		try {
			return rest_ensure_response( QuoteService::create( $order, (string) $req->get_param( 'asset' ), (string) $req->get_param( 'refund_to' ) ) );
		} catch ( UserError $e ) {
			return new \WP_Error( 'intents_checkout', $e->getMessage(), array( 'status' => $e->http_status ) );
		}
	}

	public static function submit_deposit( \WP_REST_Request $req ) {
		$order = self::order( $req );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$q    = OrderData::current( $order );
		$hash = trim( (string) $req->get_param( 'tx_hash' ) );
		if ( ! $q || ! preg_match( '/^[A-Za-z0-9]{16,128}$/', $hash ) ) {
			return new \WP_Error( 'intents_checkout', 'Invalid transaction hash', array( 'status' => 400 ) );
		}
		try {
			QuoteService::client_for( $q['mode'] )->submit_deposit( $q['deposit_address'], $hash, (string) $q['memo'] );
		} catch ( \Throwable $e ) {
			Log::error( 'deposit/submit failed: ' . $e->getMessage() ); // Optional speed-up only; polling still finds the deposit.
		}
		/* translators: %s: tx hash */
		$order->add_order_note( QuoteService::tag( $q['mode'] ) . sprintf( __( 'Customer wallet broadcast deposit tx %s (reported by the browser, not trusted; waiting for 1Click).', 'intents-checkout' ), $hash ) );
		$order->save();
		OrderSync::schedule( $order->get_id(), 10 );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function simulate( \WP_REST_Request $req ) {
		$order = self::order( $req );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$q = OrderData::current( $order );
		if ( ! Settings::is_sandbox() || ! $q || 'sandbox' !== $q['mode'] ) {
			return new \WP_Error( 'intents_checkout', 'Simulation is only available in sandbox mode', array( 'status' => 403 ) );
		}
		try {
			SandboxClient::simulate( $q['deposit_address'], (string) $req->get_param( 'status' ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'intents_checkout', $e->getMessage(), array( 'status' => 400 ) );
		}
		OrderSync::sync( $order->get_id(), true );
		return rest_ensure_response( QuoteService::public_view( wc_get_order( $order->get_id() ) ) );
	}

	/** @return \WC_Order|\WP_Error */
	private static function order( \WP_REST_Request $req ) {
		$order = wc_get_order( (int) $req['id'] );
		$key   = (string) $req->get_param( 'key' );
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || Gateway::ID !== $order->get_payment_method() ) {
			return new \WP_Error( 'intents_checkout_not_found', 'Order not found', array( 'status' => 404 ) );
		}
		return $order;
	}
}

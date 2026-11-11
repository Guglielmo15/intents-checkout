<?php
/**
 * WP-CLI commands: `wp intents-checkout <command>`.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\Near\Rpc;
use IntentsCheckout\OneClick\Client;
use IntentsCheckout\OneClick\SandboxClient;
use IntentsCheckout\Pure\QuoteSignature;
use IntentsCheckout\Pure\StatusEvaluator;
use WP_CLI;

final class Cli {

	/**
	 * Check the configuration: 1Click reachable, merchant account valid, mode.
	 *
	 * ## EXAMPLES
	 *     wp intents-checkout check
	 */
	public function check( $args, $assoc ): void {
		$s = Settings::all();
		WP_CLI::log( 'Mode:              ' . $s['mode'] );
		WP_CLI::log( 'Enabled:           ' . $s['enabled'] );
		WP_CLI::log( 'Merchant account:  ' . ( $s['merchant_account'] ?: '(not set)' ) );
		WP_CLI::log( 'Settlement token:  ' . $s['destination_asset'] . ' -> ' . $s['recipient_type'] );
		WP_CLI::log( 'API key:           ' . ( Settings::api_key() ? 'set' : 'not set (1Click adds its unauthenticated fee)' ) );
		WP_CLI::log( 'Store currency:    ' . get_woocommerce_currency() );
		$tokens = Catalog::tokens();
		WP_CLI::log( sprintf( '1Click tokens:     %d assets on %d chains', count( $tokens ), count( array_unique( array_column( $tokens, 'blockchain' ) ) ) ) );
		$fx = Catalog::fx( get_woocommerce_currency(), $s['destination_asset'] );
		WP_CLI::log( 'Exchange rate:     ' . ( $fx ? $fx['rate'] . ' (' . $fx['source'] . ')' : 'UNAVAILABLE for this currency' ) );
		if ( $s['merchant_account'] ) {
			try {
				$exists = Rpc::account_exists( $s['merchant_account'] );
				WP_CLI::log( 'Account exists:    ' . ( $exists ? 'yes' : 'NO' ) );
				if ( $exists && 'DESTINATION_CHAIN' === $s['recipient_type'] ) {
					$contract = preg_replace( '/^nep141:/', '', $s['destination_asset'] );
					$storage  = Rpc::storage_balance_of( $contract, $s['merchant_account'] );
					WP_CLI::log( 'Token storage:     ' . ( $storage ? 'registered' : 'NOT REGISTERED (payments would be lost)' ) );
				}
			} catch ( \Throwable $e ) {
				WP_CLI::warning( 'NEAR RPC: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Create a pending order for a product, paid with Intents Checkout, and optionally a quote.
	 *
	 * ## OPTIONS
	 * [--product=<id>]
	 * : Product id. Default: the first published product.
	 * [--asset=<assetId>]
	 * : Also create a quote for this origin asset, e.g. nep141:base-0x833589fcd6edb6e08f4c7c32d4f71b54bda02913.omft.near (USDC on Base).
	 * [--refund-to=<address>]
	 * : Customer refund address on the origin chain. Default: refund to the merchant's Intents account.
	 *
	 * ## EXAMPLES
	 *     wp intents-checkout demo-order --asset=nep141:base-0x833589fcd6edb6e08f4c7c32d4f71b54bda02913.omft.near
	 *
	 * @subcommand demo-order
	 */
	public function demo_order( $args, $assoc ): void {
		$product_id = (int) ( $assoc['product'] ?? 0 );
		if ( ! $product_id ) {
			$ids        = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
			$product_id = (int) ( $ids[0] ?? 0 );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			WP_CLI::error( 'No product found.' );
		}
		$order = wc_create_order( array( 'status' => 'pending' ) );
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Demo' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_email( 'demo@example.com' );
		$order->set_payment_method( Gateway::ID );
		$order->set_payment_method_title( Settings::get( 'title' ) );
		$order->calculate_totals();
		$order->save();
		WP_CLI::log( sprintf( 'Order #%d: %s %s (%s)', $order->get_id(), $order->get_total(), $order->get_currency(), $product->get_name() ) );
		WP_CLI::log( 'Pay page: ' . $order->get_checkout_payment_url( true ) );
		if ( ! empty( $assoc['asset'] ) ) {
			try {
				$view = QuoteService::create( $order, (string) $assoc['asset'], (string) ( $assoc['refund-to'] ?? '' ) );
			} catch ( UserError $e ) {
				WP_CLI::error( $e->getMessage() );
			}
			$q = $view['quote'];
			WP_CLI::log( sprintf( 'Quote (%s): send %s %s on %s to %s', $q['mode'], $q['amountInFormatted'], $q['symbol'], $q['chainName'], $q['depositAddress'] ) );
			WP_CLI::log( sprintf( 'Merchant receives exactly %s %s at %s; price valid until %s', $q['amountOutFormatted'], $q['destSymbol'], $q['merchant'], $q['deadline'] ) );
			if ( $q['explorerUrl'] ) {
				WP_CLI::log( 'Explorer: ' . $q['explorerUrl'] );
			}
		}
		WP_CLI::success( 'Created order ' . $order->get_id() );
	}

	/**
	 * Show the quotes and payment state of an order.
	 *
	 * ## OPTIONS
	 * <order_id>
	 */
	public function status( $args, $assoc ): void {
		$order = $this->order( $args[0] );
		WP_CLI::log( sprintf( 'Order #%d  status=%s  paid=%s  total=%s %s', $order->get_id(), $order->get_status(), $order->is_paid() ? 'yes' : 'no', $order->get_total(), $order->get_currency() ) );
		$rows = array();
		foreach ( OrderData::quotes( $order ) as $addr => $q ) {
			$rows[] = array(
				'deposit_address' => $addr,
				'mode'            => $q['mode'],
				'pay'             => $q['amount_in_formatted'] . ' ' . $q['origin_symbol'] . '@' . $q['origin_chain'],
				'receive'         => $q['amount_out_formatted'] . ' ' . $q['dest_symbol'],
				'1click_status'   => $q['status'],
				'state'           => $q['state'],
				'problems'        => implode( '; ', $q['problems'] ?? array() ),
			);
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		} else {
			WP_CLI::log( 'No quotes yet.' );
		}
	}

	/**
	 * Poll 1Click now for every open quote of an order and apply the result.
	 *
	 * ## OPTIONS
	 * <order_id>
	 */
	public function sync( $args, $assoc ): void {
		$order = $this->order( $args[0] );
		$res   = OrderSync::sync( $order->get_id(), true );
		WP_CLI::log( 'sync: ' . $res );
		$this->status( $args, $assoc );
	}

	/**
	 * Sandbox only: set the simulated 1Click status of the order's current quote, then sync.
	 *
	 * ## OPTIONS
	 * <order_id>
	 * <status>
	 * : PENDING_DEPOSIT | KNOWN_DEPOSIT_TX | PROCESSING | SUCCESS | INCOMPLETE_DEPOSIT | REFUNDED | FAILED
	 */
	public function simulate( $args, $assoc ): void {
		$order = $this->order( $args[0] );
		$q     = OrderData::current( $order );
		if ( ! $q || 'sandbox' !== $q['mode'] ) {
			WP_CLI::error( 'The current quote of this order is not a sandbox quote.' );
		}
		SandboxClient::simulate( $q['deposit_address'], strtoupper( $args[1] ) );
		$this->sync( array( $order->get_id() ), $assoc );
	}

	/**
	 * Replay a real past mainnet payment: fetch its status from 1Click, create a fresh order bound to
	 * that deposit address, and run the normal verification on it. Needs no funds. The order is
	 * marked REPLAY in its notes.
	 *
	 * ## OPTIONS
	 * <deposit_address>
	 * [--memo=<memo>]
	 *
	 * ## EXAMPLES
	 *     wp intents-checkout replay 0xYourPastDepositAddress
	 */
	public function replay( $args, $assoc ): void {
		$address = (string) $args[0];
		try {
			$status = ( new Client() )->status( $address, (string) ( $assoc['memo'] ?? '' ) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$qr = $status['quoteResponse'] ?? array();
		if ( ! QuoteSignature::verify( $qr ) ) {
			WP_CLI::error( 'The quote returned for this address does not carry a valid 1Click signature.' );
		}
		$req    = $qr['quoteRequest'];
		$quote  = $qr['quote'];
		$origin = Catalog::token( $req['originAsset'] ) ?? array( 'symbol' => $req['originAsset'], 'blockchain' => '?', 'decimals' => 0, 'contractAddress' => null );
		$dest   = Catalog::token( $req['destinationAsset'] ) ?? array( 'symbol' => '?', 'decimals' => 6 );
		WP_CLI::log( sprintf( '1Click status: %s; quote: %s %s -> %s %s to %s', $status['status'], $quote['amountInFormatted'], $origin['symbol'], $quote['amountOutFormatted'], $dest['symbol'], $req['recipient'] ) );

		// Show the order in the store's currency: settled amount converted back at today's rate (an approximation; the real
		// amounts are the USDC figures in the notes). Falls back to the settled amount if the currency cannot be converted.
		$fx          = Catalog::fx( get_woocommerce_currency(), (string) $req['destinationAsset'] );
		$store_total = $fx && (float) $fx['rate'] > 0
			? wc_format_decimal( (float) $quote['amountOutFormatted'] / (float) $fx['rate'], wc_get_price_decimals() )
			: (string) $quote['amountOutFormatted'];

		$order = wc_create_order( array( 'status' => 'pending' ) );
		$fee   = new \WC_Order_Item_Fee();
		$fee->set_name( 'Replay of NEAR Intents payment ' . $address );
		$fee->set_total( $store_total );
		$order->add_item( $fee );
		$order->set_billing_first_name( 'Replay of' );
		$order->set_billing_last_name( 'mainnet payment' );
		$order->set_billing_email( 'replay@example.com' );
		$order->set_payment_method( Gateway::ID );
		$order->set_payment_method_title( Settings::get( 'title' ) );
		$order->calculate_totals( false );

		OrderData::put_quote(
			$order,
			array(
				'deposit_address'     => $address,
				'memo'                => (string) ( $assoc['memo'] ?? '' ),
				'mode'                => 'replay',
				'created'             => time(),
				'origin_asset'        => $req['originAsset'],
				'origin_symbol'       => $origin['symbol'],
				'origin_chain'        => $origin['blockchain'],
				'origin_decimals'     => (int) $origin['decimals'],
				'origin_contract'     => $origin['contractAddress'] ?? null,
				'amount_in'           => (string) $quote['amountIn'],
				'amount_in_formatted' => (string) $quote['amountInFormatted'],
				'min_amount_in'       => (string) ( $quote['minAmountIn'] ?? $quote['amountIn'] ),
				'amount_in_usd'       => (string) ( $quote['amountInUsd'] ?? '' ),
				'amount_out'          => 'EXACT_OUTPUT' === $req['swapType'] ? (string) $req['amount'] : (string) ( $quote['minAmountOut'] ?? $quote['amountOut'] ),
				'amount_out_formatted'=> (string) $quote['amountOutFormatted'],
				'dest_asset'          => $req['destinationAsset'],
				'dest_symbol'         => $dest['symbol'],
				'recipient'           => $req['recipient'],
				'recipient_type'      => $req['recipientType'],
				'refund_to'           => $req['refundTo'],
				'merchant_refund'     => 'INTENTS' === $req['refundType'],
				'deadline'            => (string) $req['deadline'],
				'address_deadline'    => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + HOUR_IN_SECONDS ), // Watch window for the replay only.
				'time_estimate'       => (int) ( $quote['timeEstimate'] ?? 0 ),
				'signature'           => (string) $qr['signature'],
				'fx_rate'             => '',
				'fx_source'           => 'replay',
				'state'               => StatusEvaluator::WAITING,
				'status'              => 'PENDING_DEPOSIT',
				'raw'                 => $qr,
			)
		);
		$order->add_order_note( QuoteService::tag( 'replay' ) . sprintf( 'Order created by `wp intents-checkout replay` to re-run server-side verification on a real past mainnet payment (deposit address %s, recipient %s). %s', $address, $req['recipient'], OrderData::explorer_url( $address ) ) );
		$order->save();
		OrderSync::sync( $order->get_id(), true );
		$this->status( array( $order->get_id() ), $assoc );
		WP_CLI::success( 'Replay order ' . $order->get_id() . ': ' . $order->get_edit_order_url() );
	}

	/**
	 * Switch the store's checkout page between the Checkout block and the classic shortcode.
	 *
	 * ## OPTIONS
	 * <type>
	 * : blocks | classic
	 *
	 * @subcommand checkout-page
	 */
	public function checkout_page( $args, $assoc ): void {
		$type   = $args[0] ?? '';
		$stored = (int) get_option( 'intents_checkout_blocks_checkout_page' );
		if ( 'classic' === $type ) {
			if ( ! $stored ) {
				update_option( 'intents_checkout_blocks_checkout_page', (int) get_option( 'woocommerce_checkout_page_id' ) );
			}
			$page = get_page_by_path( 'checkout-classic' );
			$id   = $page ? $page->ID : wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Checkout (classic)',
					'post_name'    => 'checkout-classic',
					'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
				)
			);
			update_option( 'woocommerce_checkout_page_id', (int) $id );
			WP_CLI::success( 'Checkout page is now the classic shortcode: ' . get_permalink( $id ) );
		} elseif ( 'blocks' === $type ) {
			if ( $stored ) {
				update_option( 'woocommerce_checkout_page_id', $stored );
			}
			WP_CLI::success( 'Checkout page is now the Checkout block: ' . get_permalink( (int) get_option( 'woocommerce_checkout_page_id' ) ) );
		} else {
			WP_CLI::error( 'Use: blocks | classic' );
		}
	}

	private function order( $id ): \WC_Order {
		$order = wc_get_order( (int) $id );
		if ( ! $order ) {
			WP_CLI::error( "Order $id not found." );
		}
		return $order;
	}
}

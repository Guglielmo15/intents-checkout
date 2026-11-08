<?php
/**
 * Creates 1Click quotes for orders. Server-side only: the browser chooses the asset and the refund
 * address, the server decides the amount, the recipient and the settlement token.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\OneClick\ApiException;
use IntentsCheckout\OneClick\Client;
use IntentsCheckout\OneClick\SandboxClient;
use IntentsCheckout\Pure\Chains;
use IntentsCheckout\Pure\Decimal;
use IntentsCheckout\Pure\QuoteSignature;
use IntentsCheckout\Pure\StatusEvaluator;

final class QuoteService {

	private const MAX_QUOTES_PER_ORDER = 25;

	public static function client_for( string $mode ): Client {
		return 'sandbox' === $mode ? new SandboxClient() : new Client();
	}

	/**
	 * @throws UserError With a message safe to show to the customer.
	 */
	public static function create( \WC_Order $order, string $asset_id, string $refund_to ): array {
		if ( Gateway::ID !== $order->get_payment_method() ) {
			throw new UserError( __( 'This order does not use Intents Checkout.', 'intents-checkout' ), 400 );
		}
		if ( $order->is_paid() || ! $order->needs_payment() ) {
			throw new UserError( __( 'This order does not need payment.', 'intents-checkout' ), 409 );
		}
		$current = OrderData::current( $order );
		if ( $current && in_array( $current['state'] ?? '', array( StatusEvaluator::DETECTED, StatusEvaluator::INCOMPLETE, StatusEvaluator::FAILED ), true ) ) {
			throw new UserError( __( 'A payment for this order is already being processed.', 'intents-checkout' ), 409 );
		}
		if ( count( OrderData::quotes( $order ) ) >= self::MAX_QUOTES_PER_ORDER ) {
			throw new UserError( __( 'Too many payment attempts for this order. Please contact the store.', 'intents-checkout' ), 429 );
		}

		$merchant = Settings::get( 'merchant_account' );
		if ( ! Chains::is_near_account( $merchant ) ) {
			throw new UserError( __( 'The store has not configured a NEAR account to receive payments.', 'intents-checkout' ), 503 );
		}
		$dest_id = Settings::get( 'destination_asset' );
		$dest    = Catalog::token( $dest_id );
		$origin  = Catalog::token( $asset_id );
		if ( ! $dest ) {
			throw new UserError( __( 'Payment service unavailable, please try again in a minute.', 'intents-checkout' ), 503 );
		}
		if ( ! $origin || $asset_id === $dest_id ) {
			throw new UserError( __( 'Please choose a supported token.', 'intents-checkout' ), 400 );
		}

		$refund_to      = trim( $refund_to );
		$merchant_refund = '' === $refund_to;
		if ( ! $merchant_refund && ! Chains::is_valid_address( $origin['blockchain'], $refund_to ) ) {
			/* translators: %s: chain name */
			throw new UserError( sprintf( __( 'That does not look like a valid %s address.', 'intents-checkout' ), Chains::name( $origin['blockchain'] ) ), 400 );
		}

		$fx = Catalog::fx( $order->get_currency(), $dest_id );
		if ( ! $fx ) {
			/* translators: %s: currency code */
			throw new UserError( sprintf( __( 'Payments in %s are not supported yet.', 'intents-checkout' ), $order->get_currency() ), 400 );
		}
		$total      = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
		$amount_out = Decimal::mul_rate( Decimal::to_units( $total, (int) $dest['decimals'] ), $fx['rate'] );
		if ( '0' === $amount_out ) {
			throw new UserError( __( 'Order total is zero.', 'intents-checkout' ), 400 );
		}

		$mode     = Settings::is_sandbox() ? 'sandbox' : 'live';
		$deadline = gmdate( 'Y-m-d\TH:i:s.000\Z', time() + Settings::quote_minutes() * MINUTE_IN_SECONDS );
		$request  = array(
			'dry'               => false,
			'swapType'          => 'EXACT_OUTPUT',
			'slippageTolerance' => Settings::slippage_bps(),
			'originAsset'       => $origin['assetId'],
			'depositType'       => 'ORIGIN_CHAIN',
			'destinationAsset'  => $dest_id,
			'amount'            => $amount_out,
			'refundTo'          => $merchant_refund ? $merchant : $refund_to,
			'refundType'        => $merchant_refund ? 'INTENTS' : 'ORIGIN_CHAIN',
			'recipient'         => $merchant,
			'recipientType'     => 'INTENTS' === Settings::get( 'recipient_type' ) ? 'INTENTS' : 'DESTINATION_CHAIN',
			'deadline'          => $deadline,
			'referral'          => 'intents-checkout',
		);

		try {
			$response = self::client_for( $mode )->quote( $request );
		} catch ( ApiException $e ) {
			Log::error( 'Quote failed for order ' . $order->get_id() . ': ' . $e->getMessage(), $request );
			$msg = preg_replace( '/^1Click [A-Z]+ \S+ returned \d+: /', '', $e->getMessage() );
			if ( $e->http_status >= 500 || 0 === $e->http_status ) {
				$msg = __( 'The payment network could not create a quote right now. Please try again or pick another token.', 'intents-checkout' );
			}
			throw new UserError( $msg, 502 );
		}

		self::check_quote( $response, $request, $mode );

		$quote  = $response['quote'];
		$record = array(
			'deposit_address'     => (string) $quote['depositAddress'],
			'memo'                => (string) ( $quote['depositMemo'] ?? '' ),
			'mode'                => $mode,
			'created'             => time(),
			'origin_asset'        => $origin['assetId'],
			'origin_symbol'       => $origin['symbol'],
			'origin_chain'        => $origin['blockchain'],
			'origin_decimals'     => (int) $origin['decimals'],
			'origin_contract'     => $origin['contractAddress'] ?? null,
			'amount_in'           => (string) $quote['amountIn'],
			'amount_in_formatted' => (string) $quote['amountInFormatted'],
			'min_amount_in'       => (string) ( $quote['minAmountIn'] ?? $quote['amountIn'] ),
			'amount_in_usd'       => (string) ( $quote['amountInUsd'] ?? '' ),
			'amount_out'          => $amount_out,
			'amount_out_formatted'=> Decimal::from_units( $amount_out, (int) $dest['decimals'] ),
			'dest_asset'          => $dest_id,
			'dest_symbol'         => $dest['symbol'],
			'recipient'           => $merchant,
			'recipient_type'      => $request['recipientType'],
			'refund_to'           => $request['refundTo'],
			'merchant_refund'     => $merchant_refund,
			'deadline'            => $deadline,
			'address_deadline'    => (string) ( $quote['deadline'] ?? $deadline ),
			'time_estimate'       => (int) ( $quote['timeEstimate'] ?? 0 ),
			'signature'           => (string) ( $response['signature'] ?? '' ),
			'fx_rate'             => $fx['rate'],
			'fx_source'           => $fx['source'],
			'state'               => StatusEvaluator::WAITING,
			'status'              => 'PENDING_DEPOSIT',
			'raw'                 => $response,
		);
		OrderData::put_quote( $order, $record );
		$order->add_order_note(
			self::tag( $mode ) . sprintf(
				/* translators: 1: amount 2: symbol 3: chain 4: deposit address 5: amount out 6: settlement symbol 7: merchant 8: deadline 9: explorer url */
				__( 'Quote created: customer sends %1$s %2$s on %3$s to %4$s. Merchant receives exactly %5$s %6$s at %7$s. Price valid until %8$s. %9$s', 'intents-checkout' ),
				$record['amount_in_formatted'],
				$record['origin_symbol'],
				Chains::name( $record['origin_chain'] ),
				$record['deposit_address'],
				$record['amount_out_formatted'],
				$record['dest_symbol'],
				$merchant,
				$deadline,
				'sandbox' === $mode ? '' : 'Explorer: ' . OrderData::explorer_url( $record['deposit_address'] )
			)
		);
		$order->save();
		OrderSync::schedule( $order->get_id(), 20 );
		return self::public_view( $order );
	}

	/** Server-side checks on a fresh quote before showing its deposit address to anyone. */
	private static function check_quote( array $response, array $request, string $mode ): void {
		$quote = $response['quote'] ?? array();
		$req   = $response['quoteRequest'] ?? array();
		$bad   = array();
		if ( empty( $quote['depositAddress'] ) ) {
			$bad[] = 'no depositAddress';
		}
		foreach ( array( 'recipient', 'destinationAsset', 'amount', 'originAsset', 'swapType' ) as $k ) {
			if ( ( $req[ $k ] ?? null ) !== $request[ $k ] ) {
				$bad[] = "quoteRequest.$k changed";
			}
		}
		$min_out = (string) ( $quote['minAmountOut'] ?? $quote['amountOut'] ?? '0' );
		if ( ! Decimal::is_units( $min_out ) || Decimal::cmp( $min_out, $request['amount'] ) < 0 ) {
			$bad[] = 'minAmountOut below the order amount';
		}
		if ( 'live' === $mode ) {
			try {
				if ( ! QuoteSignature::verify( $response ) ) {
					$bad[] = 'invalid 1Click signature';
				}
			} catch ( \Throwable $e ) {
				$bad[] = 'signature could not be verified: ' . $e->getMessage();
			}
		}
		if ( $bad ) {
			Log::error( 'Rejected quote: ' . implode( ', ', $bad ), array( 'response' => $response ) );
			throw new UserError( __( 'The payment quote failed a security check and was discarded. Please try again.', 'intents-checkout' ), 502 );
		}
	}

	/** What the pay page needs. Never includes the raw quote or internal problems. */
	public static function public_view( \WC_Order $order ): array {
		$q    = OrderData::current( $order );
		$view = array(
			'order'      => array(
				'id'          => $order->get_id(),
				'total'       => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'currency'    => $order->get_currency(),
				'status'      => $order->get_status(),
				'paid'        => $order->is_paid(),
				'thankYouUrl' => $order->get_checkout_order_received_url(),
			),
			'sandbox'    => Settings::is_sandbox(),
			'quote'      => null,
			'canRequote' => ! $order->is_paid() && $order->needs_payment(),
		);
		if ( ! $q ) {
			return $view;
		}
		$deadline_ts           = strtotime( $q['deadline'] );
		$view['canRequote']    = $view['canRequote'] && ! in_array( $q['state'], array( StatusEvaluator::DETECTED, StatusEvaluator::INCOMPLETE, StatusEvaluator::FAILED ), true );
		$details               = $q['details'] ?? array();
		$view['quote']         = array(
			'depositAddress'    => $q['deposit_address'],
			'memo'              => $q['memo'],
			'mode'              => $q['mode'],
			'symbol'            => $q['origin_symbol'],
			'chain'             => $q['origin_chain'],
			'chainName'         => Chains::name( $q['origin_chain'] ),
			'amountIn'          => $q['amount_in'],
			'amountInFormatted' => $q['amount_in_formatted'],
			'minAmountInFormatted' => Decimal::from_units( $q['min_amount_in'], (int) $q['origin_decimals'] ),
			'amountInUsd'       => $q['amount_in_usd'],
			'amountOutFormatted'=> $q['amount_out_formatted'],
			'destSymbol'        => $q['dest_symbol'],
			'merchant'          => $q['recipient'],
			'refundTo'          => $q['refund_to'],
			'merchantRefund'    => (bool) $q['merchant_refund'],
			'deadline'          => $q['deadline'],
			'secondsLeft'       => max( 0, $deadline_ts - time() ),
			'expired'           => time() > $deadline_ts && StatusEvaluator::WAITING === $q['state'],
			'timeEstimate'      => $q['time_estimate'],
			'fxRate'            => $q['fx_rate'],
			'fxSource'          => $q['fx_source'],
			'state'             => $q['state'],
			'status'            => $q['status'],
			'explorerUrl'       => SandboxClient::is_sandbox_address( $q['deposit_address'] ) ? '' : OrderData::explorer_url( $q['deposit_address'] ),
			'evm'               => array(
				'chainId'  => Chains::evm_chain_id( $q['origin_chain'] ),
				'contract' => $q['origin_contract'],
			),
			'originTx'          => $details['origin_tx'] ?? array(),
			'destinationTx'     => $details['destination_tx'] ?? array(),
			'depositedFormatted'=> $details['deposited_formatted'] ?? null,
			'refundedFormatted' => $details['refunded_formatted'] ?? null,
			'refundReason'      => $details['refund_reason'] ?? null,
		);
		return $view;
	}

	public static function tag( string $mode ): string {
		if ( 'sandbox' === $mode ) {
			return '[SANDBOX: simulated, no funds moved] ';
		}
		if ( 'replay' === $mode ) {
			return '[REPLAY of a past mainnet payment] ';
		}
		return '';
	}
}

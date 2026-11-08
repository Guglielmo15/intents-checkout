<?php
/**
 * Sandbox: real prices, simulated money.
 *
 * NEAR Intents has no testnet (https://docs.near-intents.org/resources/faqs). In sandbox mode the
 * token list and the quote amounts are real (a dry-run quote from 1Click), but the deposit address
 * is fake ("sandbox-...") and its status is whatever the tester sets with simulate(). Every order
 * note written for a sandbox order says so. Never enable sandbox mode on a store that takes real
 * orders: a simulated SUCCESS marks the order paid.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\OneClick;

final class SandboxClient extends Client {

	public const PREFIX = 'sandbox-';

	public const STATUSES = array( 'PENDING_DEPOSIT', 'KNOWN_DEPOSIT_TX', 'PROCESSING', 'SUCCESS', 'INCOMPLETE_DEPOSIT', 'REFUNDED', 'FAILED' );

	public function quote( array $request ): array {
		$request['dry'] = true;
		$response       = parent::quote( $request ); // Real pricing from the live solver network.

		$address = self::PREFIX . bin2hex( random_bytes( 16 ) );
		$deadline = gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $request['deadline'] ) + 72 * HOUR_IN_SECONDS );

		$response['quoteRequest']['dry']        = false;
		$response['quote']['depositAddress']    = $address;
		$response['quote']['deadline']          = $deadline;
		$response['quote']['timeWhenInactive']  = $deadline;
		$response['sandbox']                    = true;

		update_option( self::key( $address ), array( 'status' => 'PENDING_DEPOSIT', 'quote' => $response, 'updated' => time() ), false );
		return $response;
	}

	public function status( string $deposit_address, string $memo = '' ): array {
		$sim = get_option( self::key( $deposit_address ) );
		if ( ! is_array( $sim ) ) {
			throw new ApiException( 'Deposit address not found (sandbox)', 404 );
		}
		$quote  = $sim['quote']['quote'];
		$status = $sim['status'];
		$swap   = array(
			'intentHashes'             => array(),
			'nearTxHashes'             => array(),
			'originChainTxHashes'      => array(),
			'destinationChainTxHashes' => array(),
		);
		$fake = static fn( string $tag ): string => 'SIMULATED-' . $tag . '-' . substr( md5( $deposit_address . $tag ), 0, 12 );

		if ( in_array( $status, array( 'KNOWN_DEPOSIT_TX', 'PROCESSING', 'SUCCESS' ), true ) ) {
			$swap['depositedAmount']          = $quote['amountIn'];
			$swap['depositedAmountFormatted'] = $quote['amountInFormatted'];
			$swap['originChainTxHashes']      = array( array( 'hash' => $fake( 'origin' ), 'explorerUrl' => '' ) );
		}
		if ( 'SUCCESS' === $status ) {
			$swap['amountIn']                 = $quote['amountIn'];
			$swap['amountInFormatted']        = $quote['amountInFormatted'];
			$swap['amountOut']                = $quote['amountOut'];
			$swap['amountOutFormatted']       = $quote['amountOutFormatted'];
			$swap['nearTxHashes']             = array( $fake( 'near' ) );
			$swap['destinationChainTxHashes'] = array( array( 'hash' => $fake( 'dest' ), 'explorerUrl' => '' ) );
		}
		if ( 'INCOMPLETE_DEPOSIT' === $status || 'REFUNDED' === $status ) {
			$half                             = (string) intdiv( (int) substr( $quote['minAmountIn'], 0, 15 ), 2 ) . substr( $quote['minAmountIn'], 15 );
			$swap['depositedAmount']          = $half;
			$swap['depositedAmountFormatted'] = 'about half of ' . $quote['amountInFormatted'];
		}
		if ( 'REFUNDED' === $status ) {
			$swap['refundedAmount']          = $swap['depositedAmount'];
			$swap['refundedAmountFormatted'] = $swap['depositedAmountFormatted'];
			$swap['refundReason']            = 'Simulated: deposit below minAmountIn';
			$swap['originChainTxHashes']     = array( array( 'hash' => $fake( 'refund' ), 'explorerUrl' => '' ) );
		}

		return array(
			'correlationId' => 'sandbox',
			'quoteResponse' => $sim['quote'],
			'status'        => $status,
			'updatedAt'     => gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $sim['updated'] ),
			'swapDetails'   => $swap,
			'sandbox'       => true,
		);
	}

	public function submit_deposit( string $deposit_address, string $tx_hash, string $memo = '' ): array {
		return array( 'sandbox' => true );
	}

	public static function simulate( string $deposit_address, string $status ): void {
		$sim = get_option( self::key( $deposit_address ) );
		if ( ! is_array( $sim ) || ! in_array( $status, self::STATUSES, true ) ) {
			throw new ApiException( 'Unknown sandbox deposit address or status', 400 );
		}
		$sim['status']  = $status;
		$sim['updated'] = time();
		update_option( self::key( $deposit_address ), $sim, false );
	}

	public static function is_sandbox_address( string $address ): bool {
		return str_starts_with( $address, self::PREFIX );
	}

	private static function key( string $address ): string {
		return 'intents_checkout_sim_' . md5( $address );
	}
}

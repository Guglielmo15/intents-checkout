<?php
/**
 * Turns a 1Click GET /v0/status response into a decision for one WooCommerce order.
 *
 * This is the only place that decides whether an order is paid. It is pure (no WordPress) so it
 * can be unit-tested against recorded API responses.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Pure;

final class StatusEvaluator {

	public const WAITING    = 'waiting';     // PENDING_DEPOSIT: nothing received yet.
	public const DETECTED   = 'detected';    // KNOWN_DEPOSIT_TX / PROCESSING: deposit seen, settling.
	public const INCOMPLETE = 'incomplete';  // INCOMPLETE_DEPOSIT: less than minAmountIn received.
	public const PAID       = 'paid';        // SUCCESS and every check passed.
	public const REFUNDED   = 'refunded';    // REFUNDED: 1Click returned the funds to refundTo.
	public const FAILED     = 'failed';      // FAILED: needs a human; never treated as paid.
	public const REVIEW     = 'review';      // SUCCESS but a check failed, or an unknown status.

	public const TERMINAL = array( self::PAID, self::REFUNDED, self::REVIEW );

	/**
	 * @param array      $status       Decoded /v0/status response.
	 * @param array      $expected     deposit_address, recipient, destination_asset, amount_out (base units), signature (optional).
	 * @param bool|null  $signature_ok Result of QuoteSignature::verify on $status['quoteResponse'], or null when not checked (sandbox).
	 * @return array{state:string,status:string,terminal:bool,problems:string[],txid:?string,details:array}
	 */
	public static function evaluate( array $status, array $expected, ?bool $signature_ok ): array {
		$code    = (string) ( $status['status'] ?? '' );
		$details = self::details( $status );
		$result  = array(
			'state'    => self::WAITING,
			'status'   => $code,
			'terminal' => false,
			'problems' => array(),
			'txid'     => null,
			'details'  => $details,
		);

		// Identity checks apply to every status: the response must be about *our* quote.
		$problems = self::identity_problems( $status, $expected, $signature_ok );

		switch ( $code ) {
			case 'PENDING_DEPOSIT':
				$result['state'] = self::WAITING;
				break;
			case 'KNOWN_DEPOSIT_TX':
			case 'PROCESSING':
				$result['state'] = self::DETECTED;
				break;
			case 'INCOMPLETE_DEPOSIT':
				$result['state'] = self::INCOMPLETE;
				break;
			case 'REFUNDED':
				$result['state'] = self::REFUNDED;
				break;
			case 'FAILED':
				$result['state'] = self::FAILED;
				break;
			case 'SUCCESS':
				$received = $details['amount_out'];
				if ( ! Decimal::is_units( $received ) ) {
					$problems[] = 'SUCCESS without a settled amountOut in swapDetails';
				} elseif ( Decimal::is_units( $expected['amount_out'] ?? null ) && Decimal::cmp( $received, $expected['amount_out'] ) < 0 ) {
					$problems[] = sprintf( 'settled amountOut %s is below the expected %s', $received, $expected['amount_out'] );
				}
				$result['state'] = self::PAID;
				$result['txid']  = $details['destination_tx'][0]['hash'] ?? ( $details['near_tx'][0] ?? ( $expected['deposit_address'] ?? null ) );
				break;
			default:
				$problems[]      = 'unknown 1Click status "' . $code . '"';
				$result['state'] = self::REVIEW;
		}

		if ( $problems ) {
			$result['problems'] = $problems;
			// Any mismatch blocks automatic completion. Non-final states are only reported.
			if ( self::PAID === $result['state'] || self::REVIEW === $result['state'] ) {
				$result['state'] = self::REVIEW;
				$result['txid']  = null;
			}
		}
		$result['terminal'] = in_array( $result['state'], self::TERMINAL, true );
		return $result;
	}

	private static function identity_problems( array $status, array $expected, ?bool $signature_ok ): array {
		$problems = array();
		$qr       = $status['quoteResponse'] ?? null;
		if ( ! is_array( $qr ) || ! isset( $qr['quote'], $qr['quoteRequest'] ) ) {
			return array( 'status response has no quoteResponse' );
		}
		if ( false === $signature_ok ) {
			$problems[] = 'quote signature does not verify against the 1Click public key';
		}
		if ( ! empty( $expected['signature'] ) && ( $qr['signature'] ?? '' ) !== $expected['signature'] ) {
			$problems[] = 'quote signature differs from the quote stored on the order';
		}
		$addr = (string) ( $qr['quote']['depositAddress'] ?? '' );
		if ( ! self::same_address( $addr, (string) ( $expected['deposit_address'] ?? '' ) ) ) {
			$problems[] = sprintf( 'deposit address %s does not match the order', $addr );
		}
		$req = $qr['quoteRequest'];
		if ( isset( $expected['recipient'] ) && ( $req['recipient'] ?? null ) !== $expected['recipient'] ) {
			$problems[] = sprintf( 'recipient %s is not the merchant account %s', $req['recipient'] ?? '?', $expected['recipient'] );
		}
		if ( isset( $expected['destination_asset'] ) && ( $req['destinationAsset'] ?? null ) !== $expected['destination_asset'] ) {
			$problems[] = sprintf( 'destination asset %s is not %s', $req['destinationAsset'] ?? '?', $expected['destination_asset'] );
		}
		if ( isset( $expected['amount_out'] ) ) {
			$quoted = (string) ( $qr['quote']['minAmountOut'] ?? $qr['quote']['amountOut'] ?? '' );
			if ( Decimal::is_units( $quoted ) && Decimal::cmp( $quoted, $expected['amount_out'] ) < 0 ) {
				$problems[] = sprintf( 'quoted amount %s is below the order amount %s', $quoted, $expected['amount_out'] );
			}
		}
		return $problems;
	}

	/** EVM addresses are case-insensitive (checksum casing); everything else is compared exactly. */
	private static function same_address( string $a, string $b ): bool {
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( str_starts_with( $a, '0x' ) && str_starts_with( $b, '0x' ) ) {
			return 0 === strcasecmp( $a, $b );
		}
		return $a === $b;
	}

	/** Normalised subset of swapDetails used in order notes and the pay page. */
	public static function details( array $status ): array {
		$d    = is_array( $status['swapDetails'] ?? null ) ? $status['swapDetails'] : array();
		$list = static function ( $v ): array {
			$out = array();
			foreach ( is_array( $v ) ? $v : array() as $item ) {
				if ( is_array( $item ) && ! empty( $item['hash'] ) ) {
					$out[] = array( 'hash' => (string) $item['hash'], 'url' => (string) ( $item['explorerUrl'] ?? '' ) );
				} elseif ( is_string( $item ) && '' !== $item ) {
					$out[] = array( 'hash' => $item, 'url' => '' );
				}
			}
			return $out;
		};
		return array(
			'amount_in'           => $d['amountIn'] ?? null,
			'amount_in_formatted' => $d['amountInFormatted'] ?? null,
			'amount_out'          => $d['amountOut'] ?? null,
			'amount_out_formatted'=> $d['amountOutFormatted'] ?? null,
			'deposited'           => $d['depositedAmount'] ?? null,
			'deposited_formatted' => $d['depositedAmountFormatted'] ?? null,
			'refunded'            => $d['refundedAmount'] ?? null,
			'refunded_formatted'  => $d['refundedAmountFormatted'] ?? null,
			'refund_reason'       => $d['refundReason'] ?? null,
			'origin_tx'           => $list( $d['originChainTxHashes'] ?? array() ),
			'destination_tx'      => $list( $d['destinationChainTxHashes'] ?? array() ),
			'near_tx'             => array_values( array_filter( array_map( 'strval', is_array( $d['nearTxHashes'] ?? null ) ? $d['nearTxHashes'] : array() ) ) ),
			'updated_at'          => $status['updatedAt'] ?? null,
		);
	}
}

<?php
/**
 * Order meta storage for quotes (HPOS-safe: only WC_Order getters/setters).
 *
 * One order can have several quotes (the customer may switch asset or re-quote after expiry).
 * All of them are kept and watched until their deposit address expires, so a late payment to an
 * older address is still noticed.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

final class OrderData {

	private const QUOTES  = '_intents_checkout_quotes';
	private const CURRENT = '_intents_checkout_current';
	private const PAID_BY = '_intents_checkout_paid_by';

	/** @return array<string,array> deposit address => quote record */
	public static function quotes( \WC_Order $order ): array {
		$q = $order->get_meta( self::QUOTES );
		return is_array( $q ) ? $q : array();
	}

	public static function current_address( \WC_Order $order ): string {
		return (string) $order->get_meta( self::CURRENT );
	}

	public static function current( \WC_Order $order ): ?array {
		$addr = self::current_address( $order );
		return '' !== $addr ? ( self::quotes( $order )[ $addr ] ?? null ) : null;
	}

	public static function put_quote( \WC_Order $order, array $record, bool $make_current = true ): void {
		$quotes                               = self::quotes( $order );
		$quotes[ $record['deposit_address'] ] = $record;
		$order->update_meta_data( self::QUOTES, $quotes );
		if ( $make_current ) {
			$order->update_meta_data( self::CURRENT, $record['deposit_address'] );
		}
	}

	public static function update_quote( \WC_Order $order, string $address, array $changes ): void {
		$quotes = self::quotes( $order );
		if ( isset( $quotes[ $address ] ) ) {
			$quotes[ $address ] = array_merge( $quotes[ $address ], $changes );
			$order->update_meta_data( self::QUOTES, $quotes );
		}
	}

	public static function paid_by( \WC_Order $order ): string {
		return (string) $order->get_meta( self::PAID_BY );
	}

	public static function set_paid_by( \WC_Order $order, string $address ): void {
		$order->update_meta_data( self::PAID_BY, $address );
	}

	public static function explorer_url( string $deposit_address ): string {
		return 'https://explorer.near-intents.org/transactions/' . rawurlencode( $deposit_address );
	}
}

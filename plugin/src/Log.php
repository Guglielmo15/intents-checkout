<?php
/**
 * Thin wrapper around the WooCommerce logger (WooCommerce > Status > Logs, source "intents-checkout").
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

final class Log {

	public static function info( string $message, array $context = array() ): void {
		if ( 'yes' === Settings::get( 'debug' ) ) {
			wc_get_logger()->info( $message . self::ctx( $context ), array( 'source' => 'intents-checkout' ) );
		}
	}

	public static function error( string $message, array $context = array() ): void {
		wc_get_logger()->error( $message . self::ctx( $context ), array( 'source' => 'intents-checkout' ) );
	}

	private static function ctx( array $context ): string {
		return $context ? ' ' . wp_json_encode( $context ) : '';
	}
}

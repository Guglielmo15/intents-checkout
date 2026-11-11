<?php
/**
 * Read-only access to the gateway settings, with environment overrides for secrets.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

final class Settings {

	public const OPTION = 'woocommerce_intents_checkout_settings';

	/** USDC on NEAR (native Circle USDC, 6 decimals). Verified via GET /v0/tokens on 2026-11-07. */
	public const USDC_NEAR = 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';
	public const USDT_NEAR = 'nep141:usdt.tether-token.near';

	public const DEFAULTS = array(
		'enabled'           => 'no',
		'title'             => 'Pay with any crypto',
		'description'       => 'Pay from 30+ blockchains (Ethereum, Base, Solana, Bitcoin, Tron, NEAR, ...). Settled through NEAR Intents.',
		'mode'              => 'live',
		'merchant_account'  => '',
		'recipient_type'    => 'INTENTS',
		'destination_asset' => self::USDC_NEAR,
		'api_key'           => '',
		'quote_minutes'     => '30',
		'slippage_bps'      => '100',
		'fx_rate'           => '',
		'visibility'        => 'everyone',
		'debug'             => 'no',
	);

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ): string {
		return (string) ( self::all()[ $key ] ?? '' );
	}

	public static function is_sandbox(): bool {
		return 'sandbox' === self::get( 'mode' );
	}

	/** 1Click partner JWT: constant or environment variable wins over the database. */
	public static function api_key(): string {
		if ( defined( 'INTENTS_CHECKOUT_JWT' ) && INTENTS_CHECKOUT_JWT ) {
			return (string) INTENTS_CHECKOUT_JWT;
		}
		$env = getenv( 'INTENTS_CHECKOUT_JWT' );
		if ( $env ) {
			return (string) $env;
		}
		return self::get( 'api_key' );
	}

	public static function quote_minutes(): int {
		return max( 5, min( 120, (int) self::get( 'quote_minutes' ) ?: 30 ) );
	}

	public static function slippage_bps(): int {
		return max( 10, min( 500, (int) self::get( 'slippage_bps' ) ?: 100 ) );
	}

	public static function near_rpc_url(): string {
		if ( defined( 'INTENTS_CHECKOUT_NEAR_RPC' ) ) {
			return (string) INTENTS_CHECKOUT_NEAR_RPC;
		}
		return (string) ( getenv( 'INTENTS_CHECKOUT_NEAR_RPC' ) ?: 'https://rpc.mainnet.near.org' );
	}
}

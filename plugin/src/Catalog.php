<?php
/**
 * Supported tokens (from GET /v0/tokens, cached) and the store-currency -> settlement-token rate.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\OneClick\Client;
use IntentsCheckout\Pure\Chains;
use IntentsCheckout\Pure\Decimal;

final class Catalog {

	private const CACHE     = 'intents_checkout_tokens';
	private const LAST_GOOD = 'intents_checkout_tokens_last';

	/** EURe on Gnosis: the only EUR stablecoin listed by 1Click; its USD price is used as EUR/USD. */
	public const EURE = 'nep141:gnosis-0x420ca0f9b9b604ce0fd9c18ef134c705e5fa3430.omft.near';

	/** @return array<string,array> assetId => token */
	public static function tokens(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		try {
			$list   = ( new Client() )->tokens();
			$tokens = array();
			foreach ( $list as $t ) {
				if ( isset( $t['assetId'], $t['blockchain'], $t['symbol'], $t['decimals'] ) ) {
					$tokens[ $t['assetId'] ] = $t;
				}
			}
			if ( $tokens ) {
				set_transient( self::CACHE, $tokens, 5 * MINUTE_IN_SECONDS );
				update_option( self::LAST_GOOD, $tokens, false );
				return $tokens;
			}
		} catch ( \Throwable $e ) {
			Log::error( 'Could not load 1Click tokens: ' . $e->getMessage() );
		}
		$last = get_option( self::LAST_GOOD );
		return is_array( $last ) ? $last : array();
	}

	public static function token( string $asset_id ): ?array {
		return self::tokens()[ $asset_id ] ?? null;
	}

	/** Assets a customer can pay with, ordered for the picker (popular chains, stablecoins and native coins first). */
	public static function origin_assets(): array {
		$dest  = Settings::get( 'destination_asset' );
		$out   = array();
		foreach ( self::tokens() as $t ) {
			if ( $t['assetId'] === $dest || empty( $t['price'] ) || false !== stripos( $t['symbol'], 'deprecated' ) ) {
				continue;
			}
			$chain = (string) $t['blockchain'];
			$out[] = array(
				'assetId'  => $t['assetId'],
				'symbol'   => $t['symbol'],
				'chain'    => $chain,
				'chainName'=> Chains::name( $chain ),
				'decimals' => (int) $t['decimals'],
				'price'    => (float) $t['price'],
				'contract' => $t['contractAddress'] ?? null,
				'evmChainId' => Chains::evm_chain_id( $chain ),
				'evm'      => Chains::is_evm( $chain ),
			);
		}
		$chain_rank = array_flip( Chains::POPULAR );
		$sym_rank   = array_flip( array( 'USDC', 'USDT', 'ETH', 'BTC', 'SOL', 'NEAR', 'wNEAR', 'TRX', 'BNB', 'POL' ) );
		usort(
			$out,
			static function ( $a, $b ) use ( $chain_rank, $sym_rank ) {
				return array( $chain_rank[ $a['chain'] ] ?? 99, $a['chainName'], $sym_rank[ $a['symbol'] ] ?? 99, $a['symbol'] )
					<=> array( $chain_rank[ $b['chain'] ] ?? 99, $b['chainName'], $sym_rank[ $b['symbol'] ] ?? 99, $b['symbol'] );
			}
		);
		return $out;
	}

	/**
	 * Rate to convert 1 unit of the store currency into the settlement token, as a decimal string.
	 *
	 * - A manual rate in the settings always wins.
	 * - USD store + USD stablecoin settlement: 1 (stablecoin parity, the usual merchant convention).
	 * - EUR store: price(EURe) / price(settlement token), both from 1Click's own token prices.
	 *
	 * @return array{rate:string,source:string}|null null when the currency cannot be converted.
	 */
	public static function fx( string $currency, string $destination_asset ): ?array {
		$manual = trim( Settings::get( 'fx_rate' ) );
		if ( '' !== $manual && is_numeric( $manual ) && (float) $manual > 0 ) {
			return array( 'rate' => Decimal::rate_from_float( (float) $manual ), 'source' => 'manual rate from the plugin settings' );
		}
		$dest = self::token( $destination_asset );
		if ( ! $dest || empty( $dest['price'] ) ) {
			return null;
		}
		$is_usd_stable = in_array( strtoupper( $dest['symbol'] ), array( 'USDC', 'USDT', 'USDT0', 'DAI' ), true );
		if ( 'USD' === $currency && $is_usd_stable ) {
			return array( 'rate' => '1', 'source' => '1 USD = 1 ' . $dest['symbol'] );
		}
		if ( 'USD' === $currency ) {
			return array( 'rate' => Decimal::rate_from_float( 1 / (float) $dest['price'] ), 'source' => 'NEAR Intents price of ' . $dest['symbol'] );
		}
		if ( 'EUR' === $currency ) {
			$eure = self::token( self::EURE );
			if ( ! $eure || empty( $eure['price'] ) ) {
				return null;
			}
			return array(
				'rate'   => Decimal::rate_from_float( (float) $eure['price'] / (float) $dest['price'] ),
				'source' => sprintf( 'NEAR Intents prices: EURe %s USD, %s %s USD', $eure['price'], $dest['symbol'], $dest['price'] ),
			);
		}
		return null;
	}
}

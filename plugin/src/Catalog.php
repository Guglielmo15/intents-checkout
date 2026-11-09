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
use IntentsCheckout\Pure\EcbRates;

final class Catalog {

	private const CACHE         = 'intents_checkout_tokens';
	private const LAST_GOOD     = 'intents_checkout_tokens_last';
	private const ECB_CACHE     = 'intents_checkout_ecb';
	private const ECB_LAST_GOOD = 'intents_checkout_ecb_last';

	/** EURe on Gnosis: the only EUR stablecoin listed by 1Click; its USD price is the EUR/USD fallback. */
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
	 * 1. A manual rate in the settings always wins.
	 * 2. Store currency -> USD: 1 for USD; otherwise the ECB daily reference rate (about 30 currencies).
	 *    Fallback for EUR only, if the ECB file cannot be fetched: NEAR Intents' EURe price, which
	 *    1Click rounds to 2 decimals and is therefore less precise.
	 * 3. USD -> settlement token: 1 USD = 1 USDC/USDT (stablecoin parity, the usual merchant
	 *    convention); any other token uses its NEAR Intents USD price.
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

		if ( 'USD' === $currency ) {
			$usd    = '1';
			$source = '';
		} else {
			$ecb = self::ecb();
			$usd = $ecb ? EcbRates::usd_per_unit( $ecb, $currency ) : null;
			if ( null !== $usd ) {
				$source = sprintf( 'ECB reference rate of %s: 1 %s = %s USD', $ecb['date'], $currency, $usd );
			} elseif ( 'EUR' === $currency && ( $eure = self::token( self::EURE ) ) && ! empty( $eure['price'] ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
				$usd    = Decimal::rate_from_float( (float) $eure['price'] );
				$source = sprintf( 'NEAR Intents EURe price (ECB rate unavailable): 1 EUR = %s USD', $usd );
			} else {
				return null;
			}
		}

		if ( in_array( strtoupper( $dest['symbol'] ), array( 'USDC', 'USDT', 'USDT0', 'DAI' ), true ) ) {
			$rate   = $usd;
			$source = ltrim( $source . '; 1 USD = 1 ' . $dest['symbol'], '; ' );
		} else {
			$rate   = Decimal::rate_from_float( (float) $usd / (float) $dest['price'] );
			$source = ltrim( $source . '; NEAR Intents price of ' . $dest['symbol'] . ': ' . $dest['price'] . ' USD', '; ' );
		}
		return array( 'rate' => $rate, 'source' => $source );
	}

	/** Store currencies the gateway can price without a manual rate. */
	public static function supported_currencies(): array {
		return EcbRates::CURRENCIES;
	}

	/** ECB daily rates, cached for an hour; the last good copy is used if a refresh fails. */
	public static function ecb(): ?array {
		$cached = get_transient( self::ECB_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get( EcbRates::URL, array( 'timeout' => 10 ) );
		$data     = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = EcbRates::parse( (string) wp_remote_retrieve_body( $response ) );
		}
		if ( $data ) {
			set_transient( self::ECB_CACHE, $data, HOUR_IN_SECONDS );
			update_option( self::ECB_LAST_GOOD, $data, false );
			return $data;
		}
		Log::error( 'Could not load ECB reference rates; using the last good copy if any.' );
		$last = get_option( self::ECB_LAST_GOOD );
		if ( is_array( $last ) ) {
			set_transient( self::ECB_CACHE, $last, 10 * MINUTE_IN_SECONDS ); // Retry in 10 minutes.
			return $last;
		}
		return null;
	}
}

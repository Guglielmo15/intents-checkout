<?php
/**
 * Chain metadata: display names, EVM chain ids (for the wallet button) and address validation.
 *
 * Chain codes are the `blockchain` values returned by GET https://1click.chaindefuser.com/v0/tokens.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Pure;

final class Chains {

	/** EVM chains with a well-known chain id. Others still work through copy/QR. */
	public const EVM_CHAIN_IDS = array(
		'eth'     => 1,
		'op'      => 10,
		'bsc'     => 56,
		'gnosis'  => 100,
		'pol'     => 137,
		'xlayer'  => 196,
		'abs'     => 2741,
		'base'    => 8453,
		'arb'     => 42161,
		'avax'    => 43114,
		'bera'    => 80094,
		'scroll'  => 534352,
	);

	/** Chains shown first in the picker. */
	public const POPULAR = array( 'base', 'eth', 'arb', 'sol', 'near', 'tron', 'btc', 'op', 'pol', 'bsc', 'avax', 'ton', 'xrp', 'sui', 'doge', 'ltc', 'zec' );

	public const NAMES = array(
		'eth'       => 'Ethereum',
		'base'      => 'Base',
		'arb'       => 'Arbitrum',
		'op'        => 'Optimism',
		'pol'       => 'Polygon',
		'bsc'       => 'BNB Chain',
		'avax'      => 'Avalanche',
		'gnosis'    => 'Gnosis',
		'scroll'    => 'Scroll',
		'bera'      => 'Berachain',
		'xlayer'    => 'X Layer',
		'abs'       => 'Abstract',
		'monad'     => 'Monad',
		'plasma'    => 'Plasma',
		'hood'      => 'Robinhood Chain',
		'near'      => 'NEAR',
		'sol'       => 'Solana',
		'btc'       => 'Bitcoin',
		'tron'      => 'Tron',
		'ton'       => 'TON',
		'xrp'       => 'XRP Ledger',
		'doge'      => 'Dogecoin',
		'ltc'       => 'Litecoin',
		'bch'       => 'Bitcoin Cash',
		'zec'       => 'Zcash',
		'dash'      => 'Dash',
		'sui'       => 'Sui',
		'aptos'     => 'Aptos',
		'cardano'   => 'Cardano',
		'stellar'   => 'Stellar',
		'starknet'  => 'Starknet',
		'hypercore' => 'Hyperliquid',
		'aleo'      => 'Aleo',
		'movement'  => 'Movement',
		'fogo'      => 'Fogo',
		'miden'     => 'Miden',
		'adi'       => 'ADI',
		'prl'       => 'PRL',
		'qtc'       => 'QTC',
	);

	public static function name( string $chain ): string {
		return self::NAMES[ $chain ] ?? strtoupper( $chain );
	}

	public static function is_evm( string $chain ): bool {
		return isset( self::EVM_CHAIN_IDS[ $chain ] ) || in_array( $chain, array( 'monad', 'plasma', 'hood' ), true );
	}

	public static function evm_chain_id( string $chain ): ?int {
		return self::EVM_CHAIN_IDS[ $chain ] ?? null;
	}

	/** NEAR account id rule used by nearhackathon.com and NEAR itself. */
	public static function is_near_account( string $v ): bool {
		return strlen( $v ) >= 2 && strlen( $v ) <= 64
			&& 1 === preg_match( '/^(([a-z\d]+[-_])*[a-z\d]+\.)*([a-z\d]+[-_])*[a-z\d]+$/', $v );
	}

	/**
	 * Best-effort format check for a refund address on the origin chain. 1Click validates again;
	 * this only catches obvious mistakes (wrong chain, typos, whitespace) before a quote is made.
	 */
	public static function is_valid_address( string $chain, string $address ): bool {
		$address = trim( $address );
		if ( '' === $address || preg_match( '/\s/', $address ) ) {
			return false;
		}
		if ( self::is_evm( $chain ) ) {
			return 1 === preg_match( '/^0x[0-9a-fA-F]{40}$/', $address );
		}
		switch ( $chain ) {
			case 'near':
				return self::is_near_account( $address );
			case 'sol':
			case 'fogo':
				return 1 === preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address );
			case 'btc':
				return 1 === preg_match( '/^(bc1[02-9ac-hj-np-z]{8,87}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/', $address );
			case 'tron':
				return 1 === preg_match( '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address );
			default:
				return strlen( $address ) >= 10 && strlen( $address ) <= 128;
		}
	}
}

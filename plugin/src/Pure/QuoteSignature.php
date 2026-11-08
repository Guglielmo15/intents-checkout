<?php
/**
 * Verifies 1Click quote signatures.
 *
 * A PHP port of verifyQuoteSignature() from @defuse-protocol/one-click-sdk-typescript 0.1.26
 * (dist/index.js: buildSignedQuoteRequest, buildSignedQuote, hashQuote). The signed message is
 * base58(sha256(json-stable-stringify({...signedRequest, ...signedQuote, timestamp}))), signed with
 * ed25519 by the 1Click manager key.
 *
 * @see https://docs.near-intents.org/integration/distribution-channels/1click-api/verify-quote-signature
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Pure;

final class QuoteSignature {

	/** 1Click manager public key, from the official TypeScript SDK (ONE_CLICK_MANAGER_PUB_KEY). */
	public const MANAGER_PUBLIC_KEY = 'ed25519:reYaWhvwu8Jzo3WUM3zhn6VrhuMEF4eADL17qtRVifc';

	/** Marker for JavaScript `undefined` (dropped by JSON serialisation). */
	private const UNDEF = "\0__undefined__\0";

	/**
	 * @param array $response Decoded quote response: {quote, quoteRequest, signature, timestamp, ...}.
	 */
	public static function verify( array $response, string $public_key = self::MANAGER_PUBLIC_KEY ): bool {
		if ( empty( $response['signature'] ) || ! is_string( $response['signature'] ) || ! isset( $response['quote'], $response['quoteRequest'] ) ) {
			return false;
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			throw new \RuntimeException( 'sodium (or sodium_compat) is required to verify quote signatures' );
		}
		try {
			$signature = Base58::decode( self::strip_prefix( $response['signature'] ) );
			$key       = Base58::decode( self::strip_prefix( $public_key ) );
			if ( 64 !== strlen( $signature ) || 32 !== strlen( $key ) ) {
				return false;
			}
			return sodium_crypto_sign_verify_detached( $signature, self::message( $response ), $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** The exact message string that 1Click signs. */
	public static function message( array $response ): string {
		$request = self::signed_request( $response['quoteRequest'] );
		$quote   = self::signed_quote( $response['quote'], ! empty( $response['quoteRequest']['dry'] ) );
		$merged  = array_merge( $request, $quote, array( 'timestamp' => $response['timestamp'] ?? self::UNDEF ) );
		return Base58::encode( hash( 'sha256', self::stable_stringify( $merged ), true ) );
	}

	private static function signed_request( array $r ): array {
		$direct  = array( 'dry', 'swapType', 'slippageTolerance', 'originAsset', 'depositType', 'destinationAsset', 'amount', 'refundTo', 'refundType', 'recipient', 'recipientType', 'deadline' );
		$guarded = array( 'quoteWaitingTimeMs', 'referral', 'virtualChainRecipient', 'virtualChainRefundRecipient', 'customRecipientMsg' );
		$out     = array();
		foreach ( $direct as $k ) {
			$out[ $k ] = array_key_exists( $k, $r ) ? $r[ $k ] : self::UNDEF;
		}
		foreach ( $guarded as $k ) {
			$out[ $k ] = self::js_truthy( $r[ $k ] ?? null ) ? $r[ $k ] : self::UNDEF;
		}
		return $out;
	}

	private static function signed_quote( array $q, bool $dry ): array {
		$direct = array( 'amountIn', 'amountInFormatted', 'amountInUsd', 'minAmountIn', 'amountOut', 'amountOutFormatted', 'amountOutUsd', 'minAmountOut' );
		$out    = array();
		foreach ( $direct as $k ) {
			$out[ $k ] = array_key_exists( $k, $q ) ? $q[ $k ] : self::UNDEF;
		}
		if ( $dry ) {
			return $out;
		}
		foreach ( array( 'depositAddress', 'depositMemo', 'deadline', 'timeWhenInactive', 'timeEstimate', 'refundFee', 'withdrawFee' ) as $k ) {
			$out[ $k ] = self::js_truthy( $q[ $k ] ?? null ) ? $q[ $k ] : self::UNDEF;
		}
		return $out;
	}

	/** JavaScript truthiness: note that the string "0" is truthy in JS but falsy in PHP. */
	private static function js_truthy( $v ): bool {
		if ( null === $v || false === $v || '' === $v ) {
			return false;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return 0 != $v && ! is_nan( (float) $v ); // phpcs:ignore Universal.Operators.StrictComparisons
		}
		return true;
	}

	/** json-stable-stringify for a flat map of scalars: sorted keys, undefined dropped, JS escaping. */
	public static function stable_stringify( array $map ): string {
		$map = array_filter( $map, static fn( $v ) => self::UNDEF !== $v );
		ksort( $map, SORT_STRING );
		$parts = array();
		foreach ( $map as $k => $v ) {
			$parts[] = self::json( (string) $k ) . ':' . self::json( $v );
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	private static function json( $v ): string {
		return json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR );
	}

	private static function strip_prefix( string $v ): string {
		return str_starts_with( $v, 'ed25519:' ) ? substr( $v, 8 ) : $v;
	}
}

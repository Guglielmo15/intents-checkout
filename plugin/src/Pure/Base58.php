<?php
/**
 * Base58 (Bitcoin alphabet) encode/decode without GMP or BCMath.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Pure;

final class Base58 {

	private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	public static function encode( string $bytes ): string {
		$zeros = strspn( $bytes, "\0" );
		$rest  = substr( $bytes, $zeros );
		if ( '' === $rest ) {
			return str_repeat( '1', $zeros );
		}
		$digits = array(); // Little-endian base58 digits.
		$len    = strlen( $rest );
		for ( $i = 0; $i < $len; $i++ ) {
			$carry = ord( $rest[ $i ] );
			$count = count( $digits );
			for ( $j = 0; $j < $count; $j++ ) {
				$carry       += $digits[ $j ] << 8;
				$digits[ $j ] = $carry % 58;
				$carry        = intdiv( $carry, 58 );
			}
			while ( $carry > 0 ) {
				$digits[] = $carry % 58;
				$carry    = intdiv( $carry, 58 );
			}
		}
		$out = str_repeat( '1', $zeros );
		for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
			$out .= self::ALPHABET[ $digits[ $i ] ];
		}
		return $out;
	}

	public static function decode( string $text ): string {
		$ones = strspn( $text, '1' );
		$rest = substr( $text, $ones );
		if ( '' === $rest ) {
			return str_repeat( "\0", $ones );
		}
		$bytes = array(); // Little-endian bytes.
		$len   = strlen( $rest );
		for ( $i = 0; $i < $len; $i++ ) {
			$value = strpos( self::ALPHABET, $rest[ $i ] );
			if ( false === $value ) {
				throw new \InvalidArgumentException( 'Invalid base58 character' );
			}
			$carry = $value;
			$count = count( $bytes );
			for ( $j = 0; $j < $count; $j++ ) {
				$carry      += $bytes[ $j ] * 58;
				$bytes[ $j ] = $carry & 0xff;
				$carry     >>= 8;
			}
			while ( $carry > 0 ) {
				$bytes[] = $carry & 0xff;
				$carry >>= 8;
			}
		}
		$out = str_repeat( "\0", $ones );
		for ( $i = count( $bytes ) - 1; $i >= 0; $i-- ) {
			$out .= chr( $bytes[ $i ] );
		}
		return $out;
	}
}

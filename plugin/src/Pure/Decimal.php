<?php
/**
 * Arbitrary-precision decimal helpers on numeric strings (no BCMath/GMP needed).
 * Token amounts are integers in base units ("smallest unit"), kept as strings.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Pure;

final class Decimal {

	/** "1.5" with 6 decimals -> "1500000". Extra fraction digits are rounded half-up. */
	public static function to_units( string $decimal, int $decimals ): string {
		$decimal = trim( $decimal );
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $decimal, $m ) ) {
			throw new \InvalidArgumentException( "Not a non-negative decimal: {$decimal}" );
		}
		$int  = $m[1];
		$frac = $m[2] ?? '';
		$all  = $int . str_pad( $frac, $decimals, '0' );
		$keep = strlen( $int ) + $decimals;
		$out  = substr( $all, 0, $keep );
		if ( strlen( $all ) > $keep && (int) $all[ $keep ] >= 5 ) {
			$out = self::add( $out, '1' );
		}
		return self::normalize( $out );
	}

	/** "1500000" with 6 decimals -> "1.5" (trailing zeros trimmed, at least $min_fraction digits). */
	public static function from_units( string $units, int $decimals, int $min_fraction = 0 ): string {
		$units = self::normalize( $units );
		if ( 0 === $decimals ) {
			return $units;
		}
		$padded = str_pad( $units, $decimals + 1, '0', STR_PAD_LEFT );
		$int    = substr( $padded, 0, -$decimals );
		$frac   = rtrim( substr( $padded, -$decimals ), '0' );
		$frac   = str_pad( $frac, $min_fraction, '0' );
		return '' === $frac ? $int : $int . '.' . $frac;
	}

	public static function add( string $a, string $b ): string {
		$a = strrev( self::normalize( $a ) );
		$b = strrev( self::normalize( $b ) );
		$n = max( strlen( $a ), strlen( $b ) );
		$out   = '';
		$carry = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			$s      = (int) ( $a[ $i ] ?? 0 ) + (int) ( $b[ $i ] ?? 0 ) + $carry;
			$out   .= (string) ( $s % 10 );
			$carry  = intdiv( $s, 10 );
		}
		if ( $carry ) {
			$out .= (string) $carry;
		}
		return self::normalize( strrev( $out ) );
	}

	public static function mul( string $a, string $b ): string {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( '0' === $a || '0' === $b ) {
			return '0';
		}
		$res = array_fill( 0, strlen( $a ) + strlen( $b ), 0 );
		for ( $i = strlen( $a ) - 1; $i >= 0; $i-- ) {
			for ( $j = strlen( $b ) - 1; $j >= 0; $j-- ) {
				$p               = (int) $a[ $i ] * (int) $b[ $j ] + $res[ $i + $j + 1 ];
				$res[ $i + $j + 1 ] = $p % 10;
				$res[ $i + $j ]    += intdiv( $p, 10 );
			}
		}
		return self::normalize( implode( '', $res ) );
	}

	/** Divide an integer string by 10^$places, rounding half-up. */
	public static function shift_round( string $units, int $places ): string {
		$units = self::normalize( $units );
		if ( $places <= 0 ) {
			return $units . str_repeat( '0', -$places );
		}
		$padded = str_pad( $units, $places + 1, '0', STR_PAD_LEFT );
		$head   = substr( $padded, 0, -$places );
		$next   = (int) substr( $padded, -$places, 1 );
		return $next >= 5 ? self::add( $head, '1' ) : self::normalize( $head );
	}

	/** Multiply base units by a decimal rate (e.g. "1.1083"), rounding half-up. */
	public static function mul_rate( string $units, string $rate ): string {
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $rate, $m ) ) {
			throw new \InvalidArgumentException( "Bad rate: {$rate}" );
		}
		$frac = $m[2] ?? '';
		return self::shift_round( self::mul( $units, $m[1] . $frac ), strlen( $frac ) );
	}

	/** Compare two non-negative integer strings: -1, 0, 1. */
	public static function cmp( string $a, string $b ): int {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) <=> strlen( $b );
		}
		return strcmp( $a, $b ) <=> 0;
	}

	public static function is_units( $v ): bool {
		return is_string( $v ) && 1 === preg_match( '/^\d+$/', $v );
	}

	/** Format a float rate as a plain decimal string (no exponent). */
	public static function rate_from_float( float $rate, int $digits = 8 ): string {
		$s = rtrim( rtrim( sprintf( '%.' . $digits . 'F', $rate ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	private static function normalize( string $v ): string {
		$v = ltrim( $v, '0' );
		return '' === $v ? '0' : $v;
	}
}

<?php
/**
 * European Central Bank euro foreign exchange reference rates.
 *
 * Source: https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml (free, no API key).
 * Published every TARGET working day around 16:00 CET. All rates are "1 EUR = x currency".
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Pure;

final class EcbRates {

	public const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/** Currencies in the ECB daily file (checked 2026-11-09), plus EUR. Used without a network call to decide gateway availability. */
	public const CURRENCIES = array( 'EUR', 'USD', 'JPY', 'CZK', 'DKK', 'GBP', 'HUF', 'PLN', 'RON', 'SEK', 'CHF', 'ISK', 'NOK', 'TRY', 'AUD', 'BRL', 'CAD', 'CNY', 'HKD', 'IDR', 'ILS', 'INR', 'KRW', 'MXN', 'MYR', 'NZD', 'PHP', 'SGD', 'THB', 'ZAR' );

	/**
	 * @return array{date:string,rates:array<string,string>}|null rates keyed by currency, "1 EUR = rate", EUR included as "1".
	 */
	public static function parse( string $xml ): ?array {
		if ( ! preg_match( '/time=[\'"](\d{4}-\d{2}-\d{2})[\'"]/', $xml, $date ) ) {
			return null;
		}
		preg_match_all( '/currency=[\'"]([A-Z]{3})[\'"]\s+rate=[\'"](\d+(?:\.\d+)?)[\'"]/', $xml, $m, PREG_SET_ORDER );
		$rates = array( 'EUR' => '1' );
		foreach ( $m as $row ) {
			if ( (float) $row[2] > 0 ) {
				$rates[ $row[1] ] = $row[2];
			}
		}
		if ( ! isset( $rates['USD'] ) ) {
			return null;
		}
		return array( 'date' => $date[1], 'rates' => $rates );
	}

	/** How many USD one unit of $currency is worth, as a decimal string (8 digits), or null if unknown. */
	public static function usd_per_unit( array $data, string $currency ): ?string {
		if ( 'USD' === $currency ) {
			return '1';
		}
		$usd   = (float) ( $data['rates']['USD'] ?? 0 );
		$other = (float) ( $data['rates'][ $currency ] ?? 0 );
		if ( $usd <= 0 || $other <= 0 ) {
			return null;
		}
		return Decimal::rate_from_float( $usd / $other, 8 );
	}
}

<?php
/**
 * Fixture: the real ECB daily reference-rate file, downloaded 2026-11-09 (rates dated 2026-11-06).
 */

use IntentsCheckout\Pure\Decimal;
use IntentsCheckout\Pure\EcbRates;

final class EcbRatesTest extends TestCase {

	private function data(): array {
		$data = EcbRates::parse( (string) file_get_contents( __DIR__ . '/../fixtures/ecb-eurofxref-daily.xml' ) );
		$this->assertTrue( is_array( $data ) );
		return $data;
	}

	public function testParsesRealFile(): void {
		$d = $this->data();
		$this->assertSame( '2026-11-06', $d['date'] );
		$this->assertSame( '1.1165', $d['rates']['USD'] );
		$this->assertSame( '1', $d['rates']['EUR'] );
		$this->assertSame( 30, count( $d['rates'] ), '29 currencies + EUR' );
		foreach ( array_keys( $d['rates'] ) as $c ) {
			$this->assertTrue( in_array( $c, EcbRates::CURRENCIES, true ), "$c in the static list" );
		}
	}

	public function testUsdPerUnit(): void {
		$d = $this->data();
		$this->assertSame( '1.1165', EcbRates::usd_per_unit( $d, 'EUR' ) );
		$this->assertSame( '1', EcbRates::usd_per_unit( $d, 'USD' ) );
		$gbp = (float) $d['rates']['GBP'];
		$this->assertSame( Decimal::rate_from_float( 1.1165 / $gbp, 8 ), EcbRates::usd_per_unit( $d, 'GBP' ), 'cross rate via EUR' );
		$this->assertSame( null, EcbRates::usd_per_unit( $d, 'XYZ' ) );
	}

	public function testEuroOrderToUsdc(): void {
		// EUR 100.00 at the ECB rate, USDC 6 decimals, 1 USD = 1 USDC -> exactly 111.65 USDC.
		$rate = EcbRates::usd_per_unit( $this->data(), 'EUR' );
		$this->assertSame( '111650000', Decimal::mul_rate( Decimal::to_units( '100.00', 6 ), $rate ) );
	}

	public function testRejectsGarbage(): void {
		$this->assertSame( null, EcbRates::parse( '<html>maintenance</html>' ) );
		$this->assertSame( null, EcbRates::parse( "<Cube time='2026-11-06'><Cube currency='GBP' rate='0.85'/></Cube>" ), 'no USD' );
	}
}

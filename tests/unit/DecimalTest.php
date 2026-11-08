<?php

use IntentsCheckout\Pure\Chains;
use IntentsCheckout\Pure\Decimal;

final class DecimalTest extends TestCase {

	public function testToUnits(): void {
		$this->assertSame( '1000000', Decimal::to_units( '1', 6 ) );
		$this->assertSame( '1500000', Decimal::to_units( '1.5', 6 ) );
		$this->assertSame( '1', Decimal::to_units( '0.0000005', 6 ), 'half-up rounding' );
		$this->assertSame( '0', Decimal::to_units( '0.0000004', 6 ) );
		$this->assertSame( '100000000000000000000000', Decimal::to_units( '0.1', 24 ), 'yocto' );
	}

	public function testFromUnits(): void {
		$this->assertSame( '1.5', Decimal::from_units( '1500000', 6 ) );
		$this->assertSame( '0.000001', Decimal::from_units( '1', 6 ) );
		$this->assertSame( '1.10', Decimal::from_units( '1100000', 6, 2 ) );
		$this->assertSame( '0.002260702075335067', Decimal::from_units( '2260702075335067', 18 ) );
	}

	public function testEuroToUsdcConversion(): void {
		// EUR 1.00 at 1.1083 USDC/EUR, USDC has 6 decimals -> 1.108300 USDC.
		$units = Decimal::mul_rate( Decimal::to_units( '1.00', 6 ), '1.1083' );
		$this->assertSame( '1108300', $units );
		// Large order does not overflow: EUR 1,234,567.89 at 1.11.
		$this->assertSame( '1370370357900', Decimal::mul_rate( Decimal::to_units( '1234567.89', 6 ), '1.11' ) );
		$this->assertSame( '1.10830001', Decimal::rate_from_float( 1.108300012345 ) );
	}

	public function testCompare(): void {
		$this->assertSame( 0, Decimal::cmp( '0005', '5' ) );
		$this->assertSame( -1, Decimal::cmp( '999', '1000' ) );
		$this->assertSame( 1, Decimal::cmp( '5000001', '5000000' ) );
	}

	public function testAddressValidation(): void {
		$this->assertTrue( Chains::is_valid_address( 'base', '0x2527D02599Ba641c19FEa793cD0F167589a0f10D' ) );
		$this->assertFalse( Chains::is_valid_address( 'base', '0x2527D02599Ba641c19FEa793cD0F167589a0f10' ), 'too short' );
		$this->assertFalse( Chains::is_valid_address( 'base', 'alice.near' ), 'NEAR account on an EVM chain' );
		$this->assertTrue( Chains::is_valid_address( 'near', 'alice.near' ) );
		$this->assertTrue( Chains::is_valid_address( 'sol', '13QkxhNMrTPxoCkRdYdJ65tFuwXPhL5gLS2Z5Nr6gjRK' ) );
		$this->assertTrue( Chains::is_valid_address( 'btc', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq' ) );
		$this->assertTrue( Chains::is_valid_address( 'tron', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t' ) );
		$this->assertFalse( Chains::is_valid_address( 'near', 'Alice.near' ), 'uppercase not allowed' );
		$this->assertSame( 8453, Chains::evm_chain_id( 'base' ) );
		$this->assertSame( null, Chains::evm_chain_id( 'sol' ) );
	}
}

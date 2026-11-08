<?php
/**
 * Fixtures are real 1Click mainnet responses recorded on 2026-11-07 (see tests/fixtures/README.md).
 * The official TypeScript SDK (verifyQuoteSignature, v0.1.26) returns true for all of them.
 */

use IntentsCheckout\Pure\Base58;
use IntentsCheckout\Pure\QuoteSignature;

final class QuoteSignatureTest extends TestCase {

	public function testBase58RoundTrip(): void {
		foreach ( array( '', "\0", "\0\0\x01", 'hello world', random_bytes( 32 ), "\0" . random_bytes( 64 ) ) as $bytes ) {
			$this->assertSame( bin2hex( $bytes ), bin2hex( Base58::decode( Base58::encode( $bytes ) ) ) );
		}
		// Known vector (Bitcoin wiki): "Hello World!" -> 2NEpo7TZRRrLZSi2U
		$this->assertSame( '2NEpo7TZRRrLZSi2U', Base58::encode( 'Hello World!' ) );
		$this->assertSame( 32, strlen( Base58::decode( substr( QuoteSignature::MANAGER_PUBLIC_KEY, 8 ) ) ) );
	}

	public function testStableStringifyMatchesJsonStableStringify(): void {
		$json = QuoteSignature::stable_stringify( array( 'b' => 1, 'a' => 'x/y', 'c' => true, 'd' => null ) );
		$this->assertSame( '{"a":"x/y","b":1,"c":true,"d":null}', $json );
	}

	public function testRealQuotesVerify(): void {
		$this->requireSodium();
		foreach ( array( 'quote-live-base-eth.json', 'quote-live-base-usdc.json', 'quote-dry-base-eth.json' ) as $f ) {
			$this->assertTrue( QuoteSignature::verify( $this->fixture( $f ) ), $f );
		}
	}

	public function testQuoteInsideStatusResponseVerifies(): void {
		$this->requireSodium();
		$status = $this->fixture( 'status-live-pending.json' );
		$this->assertTrue( QuoteSignature::verify( $status['quoteResponse'] ) );
	}

	public function testTamperedQuoteFails(): void {
		$this->requireSodium();
		$q = $this->fixture( 'quote-live-base-usdc.json' );

		$changed                            = $q;
		$changed['quoteRequest']['recipient'] = 'attacker.near';
		$this->assertFalse( QuoteSignature::verify( $changed ), 'recipient swapped' );

		$changed                          = $q;
		$changed['quote']['depositAddress'] = '0x0000000000000000000000000000000000000001';
		$this->assertFalse( QuoteSignature::verify( $changed ), 'deposit address swapped' );

		$changed                     = $q;
		$changed['quote']['amountOut'] = '1';
		$this->assertFalse( QuoteSignature::verify( $changed ), 'amount changed' );

		$changed              = $q;
		$changed['signature'] = 'ed25519:' . str_repeat( '1', 10 );
		$this->assertFalse( QuoteSignature::verify( $changed ), 'garbage signature' );
	}

	private function requireSodium(): void {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->skip( 'no sodium; set SODIUM_COMPAT' );
		}
	}
}

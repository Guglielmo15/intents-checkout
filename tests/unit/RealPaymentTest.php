<?php
/**
 * The first real mainnet payment through the plugin (guglielmoruffo.eu, order #164, 2026-11-10):
 * €0.50 paid with 0.56648 USDC on Base from MetaMask, settled as exactly 0.5607 USDC to
 * netrunner15.near's NEAR Intents balance. Fixture: the unmodified GET /v0/status response.
 */

use IntentsCheckout\Pure\QuoteSignature;
use IntentsCheckout\Pure\StatusEvaluator as E;

final class RealPaymentTest extends TestCase {

	private const USDC_NEAR = 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';

	/** What the plugin stored on order #164 when it created the quote. */
	private function expected(): array {
		$s = $this->fixture( 'status-live-success.json' );
		return array(
			'deposit_address'   => '0x1de8f6f26e801FbF477871AE1ea7D2fbE4e58D6b',
			'recipient'         => 'netrunner15.near',
			'destination_asset' => self::USDC_NEAR,
			'amount_out'        => '560700',
			'signature'         => $s['quoteResponse']['signature'],
		);
	}

	public function testRealSuccessIsPaid(): void {
		$r = E::evaluate( $this->fixture( 'status-live-success.json' ), $this->expected(), true );
		$this->assertSame( E::PAID, $r['state'] );
		$this->assertSame( array(), $r['problems'] );
		$this->assertTrue( $r['terminal'] );
		$this->assertSame( 'BAsBcCTUYvHk5GjeDd6EAZyDDFNE6jsT8SjbDcQZ1wEV', $r['txid'], 'first settlement tx becomes the WooCommerce transaction id' );
		$this->assertSame( '560700', $r['details']['amount_out'] );
		$this->assertSame( '0x01f33e382803651939cdcdcc2c202396b9359a576c2fcbaf6a8080dca2b5de3c', $r['details']['origin_tx'][0]['hash'] );
		$this->assertSame( '0.003265', $r['details']['refunded_formatted'], 'unused slippage buffer refunded to the customer' );
	}

	public function testRealSuccessCarriesValid1ClickSignature(): void {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->skip( 'no sodium' );
		}
		$s = $this->fixture( 'status-live-success.json' );
		$this->assertTrue( QuoteSignature::verify( $s['quoteResponse'] ) );
		$this->assertSame( E::PAID, E::evaluate( $s, $this->expected(), QuoteSignature::verify( $s['quoteResponse'] ) )['state'] );
	}

	public function testSameRealPaymentIsRejectedForAnotherMerchant(): void {
		$exp              = $this->expected();
		$exp['recipient'] = 'someone-else.near';
		$r                = E::evaluate( $this->fixture( 'status-live-success.json' ), $exp, true );
		$this->assertSame( E::REVIEW, $r['state'], 'a real SUCCESS for a different merchant never completes this order' );
	}

	public function testSameRealPaymentIsRejectedForALargerOrder(): void {
		$exp               = $this->expected();
		$exp['amount_out'] = '1000000'; // A 1.00 USDC order cannot be completed by a 0.5607 USDC settlement.
		$r                 = E::evaluate( $this->fixture( 'status-live-success.json' ), $exp, true );
		$this->assertSame( E::REVIEW, $r['state'] );
	}
}

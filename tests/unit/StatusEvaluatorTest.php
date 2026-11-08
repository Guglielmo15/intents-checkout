<?php
/**
 * Every 1Click status mapped to an order decision.
 *
 * Base fixture: a real PENDING_DEPOSIT response (mainnet, 2026-11-07) whose quoteResponse is
 * signed by 1Click. The other statuses are derived from it by changing only `status` and
 * `swapDetails` (synthetic, clearly marked), so the signed quote stays genuine.
 */

use IntentsCheckout\Pure\QuoteSignature;
use IntentsCheckout\Pure\StatusEvaluator as E;

final class StatusEvaluatorTest extends TestCase {

	private const USDC_NEAR = 'nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1';

	private function expected(): array {
		$s = $this->fixture( 'status-live-pending.json' );
		return array(
			'deposit_address'   => $s['quoteResponse']['quote']['depositAddress'],
			'recipient'         => 'near',
			'destination_asset' => self::USDC_NEAR,
			'amount_out'        => '5000000',
			'signature'         => $s['quoteResponse']['signature'],
		);
	}

	private function status( string $code, array $swap = array() ): array {
		$s                = $this->fixture( 'status-live-pending.json' );
		$s['status']      = $code;
		$s['swapDetails'] = array_merge( $s['swapDetails'], $swap );
		return $s;
	}

	private function success_swap(): array {
		return array(
			'amountIn'                 => '2240000000000000',
			'amountOut'                => '5000000',
			'amountOutFormatted'       => '5.0',
			'depositedAmount'          => '2260702075335067',
			'originChainTxHashes'      => array( array( 'hash' => '0xorigin', 'explorerUrl' => 'https://basescan.org/tx/0xorigin' ) ),
			'destinationChainTxHashes' => array( array( 'hash' => 'NEARdest', 'explorerUrl' => 'https://nearblocks.io/txns/NEARdest' ) ),
			'nearTxHashes'             => array( 'NEARsettle' ),
		);
	}

	public function testRealPendingIsWaiting(): void {
		$r = E::evaluate( $this->fixture( 'status-live-pending.json' ), $this->expected(), true );
		$this->assertSame( E::WAITING, $r['state'] );
		$this->assertSame( array(), $r['problems'] );
		$this->assertFalse( $r['terminal'] );
	}

	public function testDetectedStates(): void {
		foreach ( array( 'KNOWN_DEPOSIT_TX', 'PROCESSING' ) as $code ) {
			$r = E::evaluate( $this->status( $code ), $this->expected(), true );
			$this->assertSame( E::DETECTED, $r['state'], $code );
			$this->assertFalse( $r['terminal'] );
		}
	}

	public function testSuccessCompletesWithDestinationTxAsId(): void {
		$r = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $this->expected(), true );
		$this->assertSame( E::PAID, $r['state'] );
		$this->assertSame( 'NEARdest', $r['txid'] );
		$this->assertTrue( $r['terminal'] );
		$this->assertSame( 'https://basescan.org/tx/0xorigin', $r['details']['origin_tx'][0]['url'] );
	}

	public function testSuccessWithRealSignatureCheck(): void {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->skip( 'no sodium' );
		}
		$s = $this->status( 'SUCCESS', $this->success_swap() );
		$r = E::evaluate( $s, $this->expected(), QuoteSignature::verify( $s['quoteResponse'] ) );
		$this->assertSame( E::PAID, $r['state'] );
	}

	public function testUnderSettledSuccessGoesToReview(): void {
		$swap              = $this->success_swap();
		$swap['amountOut'] = '4999999';
		$r                 = E::evaluate( $this->status( 'SUCCESS', $swap ), $this->expected(), true );
		$this->assertSame( E::REVIEW, $r['state'] );
		$this->assertSame( null, $r['txid'] );
		$this->assertContains( 'below the expected', $r['problems'] );
	}

	public function testWrongRecipientNeverPays(): void {
		$exp              = $this->expected();
		$exp['recipient'] = 'my-shop.near';
		$r                = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $exp, true );
		$this->assertSame( E::REVIEW, $r['state'] );
		$this->assertContains( 'is not the merchant account', $r['problems'] );
	}

	public function testWrongDepositAddressNeverPays(): void {
		$exp                    = $this->expected();
		$exp['deposit_address'] = '0x1111111111111111111111111111111111111111';
		$r                      = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $exp, true );
		$this->assertSame( E::REVIEW, $r['state'] );
	}

	public function testDepositAddressComparedCaseInsensitivelyForEvm(): void {
		$exp                    = $this->expected();
		$exp['deposit_address'] = strtolower( $exp['deposit_address'] );
		$r                      = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $exp, true );
		$this->assertSame( E::PAID, $r['state'] );
	}

	public function testBadSignatureNeverPays(): void {
		$r = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $this->expected(), false );
		$this->assertSame( E::REVIEW, $r['state'] );
		$this->assertContains( 'signature does not verify', $r['problems'] );
	}

	public function testSwappedQuoteSignatureNeverPays(): void {
		$exp              = $this->expected();
		$exp['signature'] = 'ed25519:somethingElse';
		$r                = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $exp, true );
		$this->assertSame( E::REVIEW, $r['state'] );
	}

	public function testLargerOrderThanQuoteNeverPays(): void {
		$exp               = $this->expected();
		$exp['amount_out'] = '9000000'; // Order total is higher than what the quote delivers.
		$r                 = E::evaluate( $this->status( 'SUCCESS', $this->success_swap() ), $exp, true );
		$this->assertSame( E::REVIEW, $r['state'] );
		$this->assertContains( 'quoted amount', $r['problems'] );
	}

	public function testIncompleteDeposit(): void {
		$r = E::evaluate( $this->status( 'INCOMPLETE_DEPOSIT', array( 'depositedAmount' => '1000000000000000', 'depositedAmountFormatted' => '0.001' ) ), $this->expected(), true );
		$this->assertSame( E::INCOMPLETE, $r['state'] );
		$this->assertSame( '0.001', $r['details']['deposited_formatted'] );
		$this->assertFalse( $r['terminal'], 'customer may still top up before the deadline' );
	}

	public function testRefunded(): void {
		$r = E::evaluate( $this->status( 'REFUNDED', array( 'refundedAmount' => '999440000000000', 'refundedAmountFormatted' => '0.00099944', 'refundReason' => 'Deposit below minAmountIn' ) ), $this->expected(), true );
		$this->assertSame( E::REFUNDED, $r['state'] );
		$this->assertSame( 'Deposit below minAmountIn', $r['details']['refund_reason'] );
		$this->assertTrue( $r['terminal'] );
	}

	public function testFailedIsNotTerminalAndNotPaid(): void {
		$r = E::evaluate( $this->status( 'FAILED' ), $this->expected(), true );
		$this->assertSame( E::FAILED, $r['state'] );
		$this->assertFalse( $r['terminal'], 'keep watching: FAILED can still turn into REFUNDED' );
		$this->assertSame( null, $r['txid'] );
	}

	public function testUnknownStatusGoesToReview(): void {
		$r = E::evaluate( $this->status( 'SOMETHING_NEW' ), $this->expected(), true );
		$this->assertSame( E::REVIEW, $r['state'] );
	}

	public function testMissingQuoteResponseNeverPays(): void {
		$s = $this->status( 'SUCCESS', $this->success_swap() );
		unset( $s['quoteResponse'] );
		$r = E::evaluate( $s, $this->expected(), null );
		$this->assertSame( E::REVIEW, $r['state'] );
	}
}

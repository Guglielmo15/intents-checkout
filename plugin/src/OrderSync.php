<?php
/**
 * Polls 1Click for every open quote of an order and applies the result to the order.
 *
 * This is the only code path that changes an order's payment state. It runs from Action Scheduler
 * (background), from the pay page's status polling (throttled), and from WP-CLI. 1Click has no
 * webhooks, so polling is the only option. The browser never tells the server that an order is paid.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\OneClick\ApiException;
use IntentsCheckout\Pure\Chains;
use IntentsCheckout\Pure\QuoteSignature;
use IntentsCheckout\Pure\StatusEvaluator as E;

final class OrderSync {

	public const HOOK  = 'intents_checkout_sync';
	public const GROUP = 'intents-checkout';

	/**
	 * @return string 'locked' | 'throttled' | 'done'
	 */
	public static function sync( int $order_id, bool $force = false, bool $from_scheduler = false ): string {
		if ( ! $force && get_transient( 'intents_checkout_recent_' . $order_id ) ) {
			return 'throttled';
		}
		if ( ! self::lock( $order_id ) ) {
			return 'locked';
		}
		try {
			set_transient( 'intents_checkout_recent_' . $order_id, 1, 8 );
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
				return 'done';
			}
			foreach ( OrderData::quotes( $order ) as $address => $record ) {
				if ( ! empty( $record['terminal'] ) || self::expired_for_good( $record ) ) {
					continue;
				}
				self::sync_quote( $order, $address, $record );
			}
			$order->save();
			$next = self::next_delay( $order );
			if ( null !== $next ) {
				self::schedule( $order_id, $next, $from_scheduler );
			}
			return 'done';
		} finally {
			delete_option( 'intents_checkout_lock_' . $order_id );
		}
	}

	private static function sync_quote( \WC_Order $order, string $address, array $record ): void {
		try {
			$status = QuoteService::client_for( $record['mode'] )->status( $address, (string) $record['memo'] );
		} catch ( ApiException $e ) {
			Log::error( "Status check failed for order {$order->get_id()} ($address): " . $e->getMessage() );
			OrderData::update_quote( $order, $address, array( 'checked_at' => time(), 'last_error' => $e->getMessage() ) );
			return;
		}

		$signature_ok = null;
		if ( 'sandbox' !== $record['mode'] ) {
			try {
				$signature_ok = QuoteSignature::verify( (array) ( $status['quoteResponse'] ?? array() ) );
			} catch ( \Throwable $e ) {
				$signature_ok = false;
			}
		}
		$r = E::evaluate(
			$status,
			array(
				'deposit_address'   => $address,
				'recipient'         => $record['recipient'],
				'destination_asset' => $record['dest_asset'],
				'amount_out'        => $record['amount_out'],
				'signature'         => 'sandbox' === $record['mode'] ? null : $record['signature'],
			),
			$signature_ok
		);
		Log::info( "Order {$order->get_id()} $address: {$r['status']} -> {$r['state']}", $r['problems'] );

		$previous = (string) ( $record['state'] ?? E::WAITING );
		OrderData::update_quote(
			$order,
			$address,
			array(
				'state'      => $r['state'],
				'status'     => $r['status'],
				'terminal'   => $r['terminal'],
				'problems'   => $r['problems'],
				'details'    => $r['details'],
				'checked_at' => time(),
				'last_error' => null,
			)
		);
		if ( $previous !== $r['state'] || ( E::PAID === $r['state'] && '' === OrderData::paid_by( $order ) ) ) {
			self::apply( $order, $address, array_merge( $record, array( 'details' => $r['details'] ) ), $r );
		}
	}

	private static function apply( \WC_Order $order, string $address, array $q, array $r ): void {
		$tag     = QuoteService::tag( $q['mode'] );
		$d       = $r['details'];
		$current = OrderData::current_address( $order ) === $address;
		$chain   = Chains::name( $q['origin_chain'] );
		$links   = self::tx_list( $d['origin_tx'] );

		switch ( $r['state'] ) {
			case E::DETECTED:
				/* translators: 1: amount 2: symbol 3: chain 4: status 5: tx list */
				$note = $tag . sprintf( __( 'Deposit detected: %1$s %2$s on %3$s (1Click: %4$s). Origin tx: %5$s. Waiting for settlement.', 'intents-checkout' ), $d['deposited_formatted'] ?? $q['amount_in_formatted'], $q['origin_symbol'], $chain, $r['status'], $links );
				self::hold( $order, $note );
				break;

			case E::INCOMPLETE:
				/* translators: 1: received 2: symbol 3: required 4: deadline 5: refund address */
				$note = $tag . sprintf( __( 'Underpaid: received %1$s %2$s, at least %3$s %2$s is needed. The customer can send the difference to the same address before %4$s; otherwise 1Click refunds it to %5$s.', 'intents-checkout' ), $d['deposited_formatted'] ?? '?', $q['origin_symbol'], \IntentsCheckout\Pure\Decimal::from_units( $q['min_amount_in'], (int) $q['origin_decimals'] ), $q['deadline'], $q['refund_to'] );
				self::hold( $order, $note );
				break;

			case E::PAID:
				$received = $d['amount_out_formatted'] ?? $q['amount_out_formatted'];
				$checks   = 'sandbox' === $q['mode']
					? __( 'Checked server-side: status SUCCESS, recipient, asset, amount (sandbox: no signature to verify).', 'intents-checkout' )
					: __( 'Verified server-side: status SUCCESS, 1Click signature, recipient, asset, amount.', 'intents-checkout' );
				/* translators: 1: amount in 2: symbol 3: chain 4: amount out 5: settlement symbol 6: merchant 7: checks 8: origin txs 9: destination txs */
				$note = $tag . sprintf( __( 'Payment settled through NEAR Intents: %1$s %2$s on %3$s -> %4$s %5$s delivered to %6$s. %7$s Origin tx: %8$s. Settlement tx: %9$s.', 'intents-checkout' ), $d['amount_in_formatted'] ?? $q['amount_in_formatted'], $q['origin_symbol'], $chain, $received, $q['dest_symbol'], $q['recipient'], $checks, $links, self::tx_list( $d['destination_tx'] ) );
				if ( 'sandbox' !== $q['mode'] ) {
					$note .= ' Explorer: ' . OrderData::explorer_url( $address );
				}
				$paid_by = OrderData::paid_by( $order );
				if ( '' === $paid_by && ! $order->is_paid() ) {
					OrderData::set_paid_by( $order, $address );
					$order->set_transaction_id( (string) $r['txid'] );
					$order->add_order_note( $note );
					$order->save(); // Persist paid_by before payment_complete() so a parallel run cannot pay twice.
					$order->payment_complete( (string) $r['txid'] );
				} elseif ( $paid_by !== $address ) {
					/* translators: %s: settlement note */
					$order->add_order_note( $tag . sprintf( __( 'A second payment settled for this already-paid order. Refund the customer manually. %s', 'intents-checkout' ), $note ) );
					self::alert_admin( $order, 'Second payment received for an already-paid order' );
				}
				break;

			case E::REFUNDED:
				/* translators: 1: amount 2: symbol 3: refund address 4: reason 5: tx list */
				$note = $tag . sprintf( __( 'NEAR Intents refunded %1$s %2$s to %3$s. Reason: %4$s. Refund tx: %5$s', 'intents-checkout' ), $d['refunded_formatted'] ?? '?', $q['origin_symbol'], $q['refund_to'], $d['refund_reason'] ?? 'n/a', $links );
				if ( $current && ! $order->is_paid() ) {
					$order->update_status( 'failed', $note );
				} else {
					$order->add_order_note( $note );
				}
				break;

			case E::FAILED:
				$note = $tag . __( '1Click reports FAILED for this payment. Funds may need manual recovery: do not refund or cancel until it is resolved. The signed quote is stored on the order for support.', 'intents-checkout' ) . ' ' . OrderData::explorer_url( $address );
				self::hold( $order, $note );
				self::alert_admin( $order, '1Click payment FAILED' );
				break;

			case E::REVIEW:
				/* translators: %s: list of problems */
				$note = $tag . sprintf( __( 'Payment NOT accepted automatically, manual review needed: %s', 'intents-checkout' ), implode( '; ', $r['problems'] ) ) . ' ' . OrderData::explorer_url( $address );
				self::hold( $order, $note );
				self::alert_admin( $order, 'Payment needs manual review' );
				break;
		}
	}

	private static function hold( \WC_Order $order, string $note ): void {
		if ( ! $order->is_paid() && $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	private static function alert_admin( \WC_Order $order, string $subject ): void {
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] %s: order #%s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject, $order->get_order_number() ),
			sprintf( "%s\n\n%s", $subject, $order->get_edit_order_url() )
		);
	}

	private static function tx_list( array $txs ): string {
		if ( ! $txs ) {
			return 'n/a';
		}
		return implode( ', ', array_map( static fn( $t ) => $t['url'] ?: $t['hash'], $txs ) );
	}

	/** A quote whose deposit address has been inactive for over an hour cannot receive funds any more. */
	private static function expired_for_good( array $record ): bool {
		return time() > strtotime( $record['address_deadline'] ) + HOUR_IN_SECONDS;
	}

	/** Back-off: fast while the customer is on the pay page, slower later, until the address expires. */
	private static function next_delay( \WC_Order $order ): ?int {
		$open = array_filter( OrderData::quotes( $order ), static fn( $q ) => empty( $q['terminal'] ) && ! self::expired_for_good( $q ) );
		if ( ! $open ) {
			return null;
		}
		$newest = max( array_map( static fn( $q ) => (int) $q['created'], $open ) );
		$age    = time() - $newest;
		$active = array_filter( $open, static fn( $q ) => in_array( $q['state'] ?? '', array( E::DETECTED, E::INCOMPLETE ), true ) );
		if ( $active || $age < 15 * MINUTE_IN_SECONDS ) {
			return 20;
		}
		if ( $age < 2 * HOUR_IN_SECONDS ) {
			return MINUTE_IN_SECONDS;
		}
		if ( $age < DAY_IN_SECONDS ) {
			return 10 * MINUTE_IN_SECONDS;
		}
		return HOUR_IN_SECONDS;
	}

	public static function schedule( int $order_id, int $delay, bool $from_scheduler = false ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array( 'order_id' => $order_id );
		// Inside the running action as_next_scheduled_action() returns true; the runner reschedules itself.
		if ( ! $from_scheduler && false !== as_next_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
	}

	public static function run_scheduled( $order_id ): void {
		if ( 'locked' === self::sync( (int) $order_id, true, true ) ) {
			self::schedule( (int) $order_id, 15, true ); // Another process holds the lock; try again shortly.
		}
	}

	private static function lock( int $order_id ): bool {
		$key = 'intents_checkout_lock_' . $order_id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		if ( (int) get_option( $key ) < time() - 120 ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		return false;
	}
}

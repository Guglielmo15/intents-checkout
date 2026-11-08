<?php
/**
 * Minimal NEAR JSON-RPC client used to validate the merchant account before going live.
 *
 * Why: 1Click answers HTTP 500 (not a validation error) for a non-existent NEAR recipient, and
 * withdrawals of NEP-141 tokens to an account without storage on the token contract are lost:
 * "No refund will be made in case of insufficient storage_deposit" (1Click OpenAPI, ft_withdraw).
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\Near;

use IntentsCheckout\Settings;

final class Rpc {

	public static function account_exists( string $account_id ): bool {
		$result = self::query(
			array(
				'request_type' => 'view_account',
				'finality'     => 'final',
				'account_id'   => $account_id,
			)
		);
		return isset( $result['amount'] );
	}

	/**
	 * NEP-145 storage_balance_of. Returns null when the account is not registered on the contract.
	 */
	public static function storage_balance_of( string $contract_id, string $account_id ): ?array {
		$result = self::query(
			array(
				'request_type' => 'call_function',
				'finality'     => 'final',
				'account_id'   => $contract_id,
				'method_name'  => 'storage_balance_of',
				'args_base64'  => base64_encode( wp_json_encode( array( 'account_id' => $account_id ) ) ),
			)
		);
		if ( ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
			throw new \RuntimeException( 'Unexpected storage_balance_of response' );
		}
		$json    = implode( '', array_map( 'chr', $result['result'] ) );
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function query( array $params ): ?array {
		$response = wp_remote_post(
			Settings::near_rpc_url(),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 'intents-checkout',
						'method'  => 'query',
						'params'  => $params,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'NEAR RPC unreachable: ' . $response->get_error_message() );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['error'] ) ) {
			$cause = $body['error']['cause']['name'] ?? '';
			if ( 'UNKNOWN_ACCOUNT' === $cause ) {
				return null;
			}
			throw new \RuntimeException( 'NEAR RPC error: ' . ( $cause ?: ( $body['error']['message'] ?? 'unknown' ) ) );
		}
		return $body['result'] ?? null;
	}
}

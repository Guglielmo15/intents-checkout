<?php
/**
 * NEAR Intents 1Click Swap API client.
 *
 * Endpoints (OpenAPI: https://1click.chaindefuser.com/docs/v0/openapi.yaml):
 *   GET  /v0/tokens            supported assets with USD prices
 *   POST /v0/quote             quote; with dry=false returns a one-time deposit address
 *   GET  /v0/status            swap status by deposit address (there is no webhook)
 *   POST /v0/deposit/submit    optional: tell 1Click about a deposit tx to speed it up
 *
 * An API key (partner JWT) is optional; without it 1Click adds a platform fee.
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout\OneClick;

use IntentsCheckout\Settings;

class Client {

	public const BASE_URL = 'https://1click.chaindefuser.com';

	public function tokens(): array {
		return $this->request( 'GET', '/v0/tokens' );
	}

	public function quote( array $request ): array {
		return $this->request( 'POST', '/v0/quote', $request );
	}

	public function status( string $deposit_address, string $memo = '' ): array {
		$query = array( 'depositAddress' => $deposit_address );
		if ( '' !== $memo ) {
			$query['depositMemo'] = $memo;
		}
		return $this->request( 'GET', '/v0/status?' . http_build_query( $query ) );
	}

	public function submit_deposit( string $deposit_address, string $tx_hash, string $memo = '' ): array {
		$body = array( 'depositAddress' => $deposit_address, 'txHash' => $tx_hash );
		if ( '' !== $memo ) {
			$body['memo'] = $memo;
		}
		return $this->request( 'POST', '/v0/deposit/submit', $body );
	}

	protected function request( string $method, string $path, ?array $body = null ): array {
		$base    = defined( 'INTENTS_CHECKOUT_1CLICK_URL' ) ? INTENTS_CHECKOUT_1CLICK_URL : self::BASE_URL;
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'intents-checkout-woocommerce/' . INTENTS_CHECKOUT_VERSION,
		);
		$key = Settings::api_key();
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new ApiException( '1Click request failed: ' . $response->get_error_message() );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && ! empty( $decoded['message'] ) ? $decoded['message'] : substr( $raw, 0, 200 );
			throw new ApiException( sprintf( '1Click %s %s returned %d: %s', $method, strtok( $path, '?' ), $code, is_array( $message ) ? implode( '; ', $message ) : $message ), $code );
		}
		if ( ! is_array( $decoded ) ) {
			throw new ApiException( '1Click returned invalid JSON', $code );
		}
		return $decoded;
	}
}

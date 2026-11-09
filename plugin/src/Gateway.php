<?php
/**
 * WooCommerce payment gateway: "Pay with any crypto", settled through NEAR Intents.
 *
 * Flow: process_payment() leaves the order pending and redirects to the order-pay page, where
 * PayPage renders the asset picker. Payment is confirmed only by OrderSync (server-side polling).
 *
 * @package IntentsCheckout
 */

namespace IntentsCheckout;

use IntentsCheckout\Near\Rpc;
use IntentsCheckout\Pure\Chains;

final class Gateway extends \WC_Payment_Gateway {

	public const ID = 'intents_checkout';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Intents Checkout (NEAR Intents)', 'intents-checkout' );
		$this->method_description = __( 'Customers pay with any token on 30+ chains; you receive one settlement token (default USDC) on your NEAR account. Settlement is done by NEAR Intents (1Click API); no custodian, no keys on this server.', 'intents-checkout' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = INTENTS_CHECKOUT_URL . 'assets/checkout-icon.png';

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		if ( 'sandbox' === $this->get_option( 'mode' ) ) {
			$this->title .= ' ' . __( '(sandbox)', 'intents-checkout' );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( PayPage::class, 'render' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( PayPage::class, 'thankyou' ) );
	}

	public function init_form_fields(): void {
		$d                 = Settings::DEFAULTS;
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable', 'intents-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept crypto payments through NEAR Intents', 'intents-checkout' ),
				'default' => $d['enabled'],
			),
			'mode'              => array(
				'title'       => __( 'Mode', 'intents-checkout' ),
				'type'        => 'select',
				'options'     => array(
					'live'    => __( 'Live (NEAR mainnet, real funds)', 'intents-checkout' ),
					'sandbox' => __( 'Sandbox (real prices, simulated payments)', 'intents-checkout' ),
				),
				'description' => __( 'NEAR Intents has no testnet. Sandbox uses real quotes but fake deposit addresses whose status you set by hand. Never use sandbox on a store that takes real orders.', 'intents-checkout' ),
				'default'     => $d['mode'],
			),
			'title'             => array(
				'title'   => __( 'Title', 'intents-checkout' ),
				'type'    => 'text',
				'default' => $d['title'],
			),
			'description'       => array(
				'title'   => __( 'Description', 'intents-checkout' ),
				'type'    => 'textarea',
				'default' => $d['description'],
			),
			'merchant_account'  => array(
				'title'       => __( 'Your NEAR account', 'intents-checkout' ),
				'type'        => 'text',
				'placeholder' => 'yourshop.near',
				'description' => __( 'Mainnet account that receives the payments, e.g. yourshop.near or a 64-character implicit account. Checked on save: it must exist and be registered on the settlement token contract.', 'intents-checkout' ),
				'default'     => $d['merchant_account'],
			),
			'destination_asset' => array(
				'title'   => __( 'Receive', 'intents-checkout' ),
				'type'    => 'select',
				'options' => array(
					Settings::USDC_NEAR => 'USDC on NEAR',
					Settings::USDT_NEAR => 'USDT on NEAR',
				),
				'default' => $d['destination_asset'],
			),
			'recipient_type'    => array(
				'title'       => __( 'Deliver to', 'intents-checkout' ),
				'type'        => 'select',
				'options'     => array(
					'DESTINATION_CHAIN' => __( 'My NEAR wallet (NEP-141 transfer)', 'intents-checkout' ),
					'INTENTS'           => __( 'My NEAR Intents balance (intents.near)', 'intents-checkout' ),
				),
				'description' => __( 'NEAR wallet: the token arrives in your NEAR wallet like any other token; your account must be registered once on the token contract (about 0.00125 NEAR), and saving is refused until it is. NEAR Intents balance: the token is credited to your account inside NEAR Intents (intents.near), with no registration and no withdrawal fee; keep, swap or withdraw it from the NEAR Intents app.', 'intents-checkout' ),
				'default'     => $d['recipient_type'],
			),
			'api_key'           => array(
				'title'       => __( '1Click API key (optional)', 'intents-checkout' ),
				'type'        => 'password',
				'description' => __( 'Partner JWT from partners.near-intents.org. Without it 1Click adds a platform fee. Can also be set with the INTENTS_CHECKOUT_JWT constant or environment variable.', 'intents-checkout' ),
				'default'     => '',
			),
			'quote_minutes'     => array(
				'title'             => __( 'Price valid for (minutes)', 'intents-checkout' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 5, 'max' => 120 ),
				'description'       => __( 'Quote deadline. The deposit address itself stays watched for 72 hours after it, so late payments are still picked up.', 'intents-checkout' ),
				'default'           => $d['quote_minutes'],
			),
			'slippage_bps'      => array(
				'title'             => __( 'Slippage buffer (basis points)', 'intents-checkout' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 10, 'max' => 500 ),
				'description'       => __( '100 = 1%. Added to the amount the customer sends; any unused part is refunded by NEAR Intents.', 'intents-checkout' ),
				'default'           => $d['slippage_bps'],
			),
			'fx_rate'           => array(
				'title'       => __( 'Manual exchange rate (optional)', 'intents-checkout' ),
				'type'        => 'text',
				'description' => __( 'Settlement tokens per 1 unit of store currency. Leave empty (recommended) to convert automatically: 1 USD = 1 USDC, and the European Central Bank daily reference rate for EUR, GBP, CHF and about 25 other currencies.', 'intents-checkout' ),
				'default'     => '',
			),
			'visibility'        => array(
				'title'   => __( 'Show to', 'intents-checkout' ),
				'type'    => 'select',
				'options' => array(
					'everyone' => __( 'Everyone', 'intents-checkout' ),
					'admins'   => __( 'Shop managers only (testing)', 'intents-checkout' ),
				),
				'default' => $d['visibility'],
			),
			'debug'             => array(
				'title'   => __( 'Debug log', 'intents-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log API calls to WooCommerce > Status > Logs (source: intents-checkout)', 'intents-checkout' ),
				'default' => $d['debug'],
			),
		);
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! Chains::is_near_account( (string) $this->get_option( 'merchant_account' ) ) ) {
			return false;
		}
		if ( 'admins' === $this->get_option( 'visibility' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		return in_array( get_woocommerce_currency(), Catalog::supported_currencies(), true ) || is_numeric( $this->get_option( 'fx_rate' ) );
	}

	/** Checkout icon, sized like the other gateways' icons whatever the theme's CSS does. */
	public function get_icon() {
		$html = sprintf(
			'<img src="%s" alt="%s" width="24" height="24" class="intents-checkout-icon" style="width:auto;height:24px;max-height:24px;" />',
			esc_url( $this->icon ),
			esc_attr__( 'Crypto', 'intents-checkout' )
		);
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( ! $order->has_status( 'pending' ) ) {
			$order->update_status( 'pending' );
		}
		$order->add_order_note( QuoteService::tag( Settings::is_sandbox() ? 'sandbox' : 'live' ) . __( 'Customer chose Intents Checkout. Waiting for them to pick a token on the pay page.', 'intents-checkout' ) );
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/** Validate the merchant account on save: a wrong account loses funds (see Near\Rpc). */
	public function process_admin_options() {
		$saved    = parent::process_admin_options();
		$account  = trim( (string) $this->get_option( 'merchant_account' ) );
		$errors   = array();
		$warnings = array();

		if ( '' === $account ) {
			$errors[] = __( 'Enter the NEAR account that should receive payments.', 'intents-checkout' );
		} elseif ( ! Chains::is_near_account( $account ) ) {
			$errors[] = __( 'The NEAR account is not a valid account id (lowercase, e.g. yourshop.near).', 'intents-checkout' );
		} elseif ( 'live' === $this->get_option( 'mode' ) ) {
			try {
				if ( ! Rpc::account_exists( $account ) ) {
					/* translators: %s: account */
					$errors[] = sprintf( __( 'NEAR account %s does not exist on mainnet.', 'intents-checkout' ), $account );
				} elseif ( 'DESTINATION_CHAIN' === $this->get_option( 'recipient_type' ) ) {
					$contract = preg_replace( '/^nep141:/', '', (string) $this->get_option( 'destination_asset' ) );
					if ( null === Rpc::storage_balance_of( $contract, $account ) ) {
						$errors[] = sprintf(
							/* translators: 1: account 2: contract */
							__( '%1$s is not registered on the token contract %2$s, and tokens sent to it would be lost. Register it once (≈0.00125 NEAR), e.g. with near-cli: near call %2$s storage_deposit \'{"account_id":"%1$s"}\' --accountId %1$s --deposit 0.00125. Or choose "My NEAR Intents balance".', 'intents-checkout' ),
							$account,
							$contract
						);
					}
				}
			} catch ( \Throwable $e ) {
				$warnings[] = __( 'Could not reach NEAR RPC to check the account: ', 'intents-checkout' ) . $e->getMessage();
			}
		}

		if ( $errors && 'yes' === $this->get_option( 'enabled' ) && 'live' === $this->get_option( 'mode' ) ) {
			$this->update_option( 'enabled', 'no' );
			$errors[] = __( 'Intents Checkout has been disabled until this is fixed.', 'intents-checkout' );
		}
		foreach ( $errors as $e ) {
			\WC_Admin_Settings::add_error( $e );
		}
		foreach ( $warnings as $w ) {
			\WC_Admin_Settings::add_error( $w );
		}
		if ( ! $errors && 'live' === $this->get_option( 'mode' ) && '' !== $account ) {
			\WC_Admin_Settings::add_message( sprintf( /* translators: %s: account */ __( 'NEAR account %s checked: it exists and can receive the settlement token.', 'intents-checkout' ), $account ) );
		}
		delete_transient( 'intents_checkout_tokens' );
		return $saved;
	}
}

<?php
/**
 * Cart & Checkout Blocks integration for the Kwugwo gateway.
 *
 * Registers Kwugwo as a payment method in the block-based checkout. The
 * payment itself runs through the gateway's server-side process_payment(),
 * whose returned redirect the block checkout follows to the order-pay page
 * where the embedded overlay opens — same flow as the classic checkout.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Kwugwo_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * @var string
	 */
	protected $name = KWUGWO_WC_GATEWAY_ID;

	/**
	 * Load the gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . KWUGWO_WC_GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Whether the method should be available in the block checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->get_gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register and return the script handle(s) for the block integration.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'kwugwo-blocks';

		wp_register_script(
			$handle,
			KWUGWO_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			KWUGWO_WC_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'kwugwo-woocommerce' );
		}

		return array( $handle );
	}

	/**
	 * Data passed to the block integration (read client-side via getSetting).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->get_gateway();

		return array(
			'title'       => $gateway ? $gateway->get_option( 'title', __( 'Kwugwo', 'kwugwo-woocommerce' ) ) : __( 'Kwugwo', 'kwugwo-woocommerce' ),
			'description' => $gateway ? $gateway->get_option( 'description', '' ) : '',
			'icon'        => apply_filters( 'kwugwo_wc_icon', KWUGWO_WC_URL . 'assets/images/kwugwo-logo.jpg' ),
			'supports'    => $this->get_supported_features(),
		);
	}

	/**
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateway = $this->get_gateway();
		return $gateway ? array_values( $gateway->supports ) : array( 'products' );
	}

	/**
	 * @return WC_Gateway_Kwugwo|null
	 */
	private function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ KWUGWO_WC_GATEWAY_ID ] ) ? $gateways[ KWUGWO_WC_GATEWAY_ID ] : null;
	}
}

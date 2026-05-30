<?php
/**
 * Plugin Name: Kwugwo for WooCommerce
 * Plugin URI:  https://kwugwo.africa
 * Description: Accept payments across Africa's PSPs with Kwugwo. Uses the Kwugwo embedded checkout overlay; supports sandbox and live keys with a one-click environment toggle.
 * Version:     1.0.0
 * Author:      Kwugwo
 * Author URI:  https://kwugwo.africa
 * License:     GPL-2.0-or-later
 * Text Domain: kwugwo-for-woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KWUGWO_WC_VERSION', '1.0.0' );
define( 'KWUGWO_WC_FILE', __FILE__ );
define( 'KWUGWO_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'KWUGWO_WC_URL', plugin_dir_url( __FILE__ ) );

/**
 * The gateway id used everywhere (settings key, order meta prefix, REST slug).
 */
define( 'KWUGWO_WC_GATEWAY_ID', 'kwugwo' );

/**
 * Default origin of the Kwugwo hosted checkout used by the embed SDK.
 * Override per-store from the gateway settings if you run against staging.
 */
define( 'KWUGWO_WC_CHECKOUT_BASE_URL', 'https://checkout.kwugwo.africa' );

/**
 * Boot the plugin once all plugins are loaded so we can be sure WooCommerce
 * is available before we extend it.
 */
add_action( 'plugins_loaded', 'kwugwo_wc_init', 11 );

function kwugwo_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'kwugwo_wc_missing_wc_notice' );
		return;
	}

	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-logger.php';
	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-api.php';
	require_once KWUGWO_WC_PATH . 'includes/class-wc-gateway-kwugwo.php';
	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-webhook.php';

	// Register the gateway with WooCommerce.
	add_filter( 'woocommerce_payment_gateways', 'kwugwo_wc_add_gateway' );

	// Boot the webhook listener (registers the wc-api endpoint).
	Kwugwo_Webhook::instance();
}

/**
 * Register the Kwugwo gateway class with WooCommerce.
 *
 * @param string[] $gateways Registered gateway class names.
 * @return string[]
 */
function kwugwo_wc_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_Kwugwo';
	return $gateways;
}

/**
 * Convenience accessor for the configured gateway instance.
 *
 * @return WC_Gateway_Kwugwo|null
 */
function kwugwo_wc_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways[ KWUGWO_WC_GATEWAY_ID ] ) ? $gateways[ KWUGWO_WC_GATEWAY_ID ] : null;
}

/**
 * Admin notice when WooCommerce is not active.
 */
function kwugwo_wc_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Kwugwo for WooCommerce requires WooCommerce to be installed and active.', 'kwugwo-woocommerce' );
	echo '</p></div>';
}

/**
 * Declare compatibility with High-Performance Order Storage (HPOS) and the
 * Cart & Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Register the Cart & Checkout block integration.
 */
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
				$registry->register( new Kwugwo_Blocks_Support() );
			}
		);
	}
);

/**
 * Add a settings shortcut to the plugins list.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . KWUGWO_WC_GATEWAY_ID );
		$links = array_merge(
			array( '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'kwugwo-woocommerce' ) . '</a>' ),
			$links
		);
		return $links;
	}
);

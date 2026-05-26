<?php
/**
 * Thin wrapper around the WooCommerce logger, gated on the gateway's debug
 * setting. Logs land under WooCommerce → Status → Logs with the `kwugwo` source.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Kwugwo_Logger {

	/**
	 * @var WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Whether debug logging is enabled in the gateway settings.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Write a line to the Kwugwo log if debug logging is on.
	 *
	 * @param string $message Message; arrays/objects are JSON-encoded.
	 * @param string $level   One of the WC_Log_Levels constants.
	 */
	public static function log( $message, $level = 'info' ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( null === self::$logger && function_exists( 'wc_get_logger' ) ) {
			self::$logger = wc_get_logger();
		}

		if ( ! self::$logger ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = wp_json_encode( $message, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		self::$logger->log( $level, (string) $message, array( 'source' => 'kwugwo' ) );
	}

	/**
	 * Read the debug flag from the gateway options (cached per request).
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		if ( null === self::$enabled ) {
			$settings      = get_option( 'woocommerce_' . KWUGWO_WC_GATEWAY_ID . '_settings', array() );
			self::$enabled = isset( $settings['debug'] ) && 'yes' === $settings['debug'];
		}
		return self::$enabled;
	}
}

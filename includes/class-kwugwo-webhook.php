<?php
/**
 * Kwugwo webhook listener.
 *
 * Endpoint: {site}/?wc-api=kwugwo_webhook
 *
 * Verifies the HMAC signature against the raw body, de-duplicates on the
 * stable event uid, then re-fetches the ugwo from the merchant API (the
 * source of truth, per the docs) before updating the WooCommerce order.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Kwugwo_Webhook {

	/**
	 * @var Kwugwo_Webhook|null
	 */
	private static $instance = null;

	/**
	 * @return Kwugwo_Webhook
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_api_kwugwo_webhook', array( $this, 'handle' ) );
	}

	/**
	 * Handle an incoming webhook delivery and always answer with a status code.
	 */
	public function handle() {
		$raw_body  = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_KWUGWO_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_KWUGWO_SIGNATURE'] ) : '';

		if ( '' === $raw_body ) {
			$this->respond( 400, 'empty body' );
		}

		if ( ! $this->verify( $raw_body, $signature ) ) {
			Kwugwo_Logger::log( 'Webhook signature verification failed.', 'error' );
			$this->respond( 401, 'invalid signature' );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || empty( $payload['event'] ) ) {
			$this->respond( 400, 'malformed payload' );
		}

		$event_uid = isset( $payload['uid'] ) ? $payload['uid'] : '';
		$event     = $payload['event'];
		$data      = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		Kwugwo_Logger::log( sprintf( 'Webhook received: %s (%s)', $event, $event_uid ) );

		// Idempotency: the event uid is stable across redelivery attempts.
		if ( $event_uid && $this->already_processed( $event_uid ) ) {
			Kwugwo_Logger::log( 'Webhook already processed, acking: ' . $event_uid );
			$this->respond( 200, 'duplicate' );
		}

		// We only act on ugwo lifecycle events; everything else is acked and skipped.
		if ( 0 !== strpos( $event, 'ugwo.' ) ) {
			$this->mark_processed( $event_uid );
			$this->respond( 200, 'ignored' );
		}

		$ugwo_uid = $this->extract_ugwo_uid( $event, $data );
		if ( ! $ugwo_uid ) {
			Kwugwo_Logger::log( 'Webhook had no resolvable ugwo uid; acking.', 'warning' );
			$this->mark_processed( $event_uid );
			$this->respond( 200, 'no ugwo' );
		}

		$order = $this->find_order( $ugwo_uid, isset( $data['metadata']['order_id'] ) ? $data['metadata']['order_id'] : null );
		if ( ! $order ) {
			Kwugwo_Logger::log( 'No matching order for ugwo ' . $ugwo_uid . '; acking.', 'warning' );
			$this->mark_processed( $event_uid );
			$this->respond( 200, 'no order' );
		}

		// Re-fetch the ugwo for the authoritative status.
		$test_mode = $this->is_sandbox_id( $ugwo_uid );
		$secret    = $this->secret_for_env( $test_mode );
		$ugwo      = ( new Kwugwo_API( $secret, $test_mode ) )->get_ugwo( $ugwo_uid );

		if ( is_wp_error( $ugwo ) ) {
			Kwugwo_Logger::log( 'Re-fetch of ugwo failed: ' . $ugwo->get_error_message(), 'error' );
			// 500 so Kwugwo retries the delivery later.
			$this->respond( 500, 'refetch failed' );
		}

		$this->apply_status( $order, $ugwo, $event );
		$this->mark_processed( $event_uid );

		$this->respond( 200, 'ok' );
	}

	/* ---------------------------------------------------------------------
	 * Signature
	 * ------------------------------------------------------------------- */

	/**
	 * Verify the signature against every configured secret. If no secret is
	 * configured at all, the endpoint was registered without one and the
	 * signature is not enforced (matching Kwugwo's documented behaviour).
	 *
	 * @param string $raw_body  Raw bytes.
	 * @param string $signature Header value.
	 * @return bool
	 */
	private function verify( $raw_body, $signature ) {
		$settings = $this->settings();
		$secrets  = array_filter(
			array(
				isset( $settings['live_webhook_secret'] ) ? $settings['live_webhook_secret'] : '',
				isset( $settings['test_webhook_secret'] ) ? $settings['test_webhook_secret'] : '',
			),
			static function ( $s ) {
				return '' !== (string) $s;
			}
		);

		if ( empty( $secrets ) ) {
			return true; // No secret set anywhere; accept (no enforcement).
		}

		foreach ( $secrets as $secret ) {
			if ( Kwugwo_API::verify_signature( $raw_body, $signature, $secret ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Order resolution + status mapping
	 * ------------------------------------------------------------------- */

	/**
	 * Pull the ugwo uid out of the event payload.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Resource snapshot.
	 * @return string
	 */
	private function extract_ugwo_uid( $event, array $data ) {
		// ugwo.created / ugwo.updated → data is an ugwo.
		if ( 0 === strpos( $event, 'ugwo.activity' ) ) {
			// Activity snapshot; the parent ugwo uid may be present under `ugwo`.
			if ( ! empty( $data['ugwo'] ) ) {
				return is_array( $data['ugwo'] ) && ! empty( $data['ugwo']['uid'] ) ? $data['ugwo']['uid'] : ( is_string( $data['ugwo'] ) ? $data['ugwo'] : '' );
			}
			return '';
		}

		return ! empty( $data['uid'] ) ? $data['uid'] : '';
	}

	/**
	 * Find the WooCommerce order linked to this ugwo.
	 *
	 * @param string      $ugwo_uid       ugw.…
	 * @param string|null $hint_order_id  order_id from the event metadata, if any.
	 * @return WC_Order|null
	 */
	private function find_order( $ugwo_uid, $hint_order_id ) {
		// Fast path: we stamped the order id into the ugwo metadata ourselves.
		if ( $hint_order_id ) {
			$order = wc_get_order( (int) $hint_order_id );
			if ( $order && $order->get_meta( WC_Gateway_Kwugwo::META_UGWO_UID ) === $ugwo_uid ) {
				return $order;
			}
		}

		// Fallback: look the order up by stored ugwo uid.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => WC_Gateway_Kwugwo::META_UGWO_UID,
				'meta_value' => $ugwo_uid,
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		return null;
	}

	/**
	 * Transition the order to match the ugwo status.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $ugwo  Authoritative ugwo from the API.
	 * @param string   $event Triggering event type (for the note).
	 */
	private function apply_status( $order, array $ugwo, $event ) {
		// The API returns enum fields as a [value, translation_key] pair, e.g.
		// "status": ["ugwo_successful", "enum.ugwo_status.ugwo_successful"].
		$status   = $this->enum_value( isset( $ugwo['status'] ) ? $ugwo['status'] : '' );
		$ugwo_uid = isset( $ugwo['uid'] ) ? $ugwo['uid'] : '';

		Kwugwo_Logger::log( sprintf( 'Order #%d: ugwo %s status=%s (via %s)', $order->get_id(), $ugwo_uid, $status, $event ) );

		switch ( $status ) {
			case 'ugwo_successful':
				// Complete unless the order is already in a paid state (avoids
				// re-firing completion on a redelivery). Pending *and* on-hold
				// orders both proceed here.
				if ( ! $order->has_status( wc_get_is_paid_statuses() ) ) {
					$activity_uid = $this->latest_successful_activity( $ugwo );
					$order->add_order_note(
						sprintf(
							/* translators: 1: ugwo id, 2: activity id. */
							__( 'Kwugwo payment confirmed (ugwo %1$s, activity %2$s).', 'kwugwo-woocommerce' ),
							$ugwo_uid,
							$activity_uid ? $activity_uid : '—'
						)
					);
					// Records the transaction id and moves to processing/completed.
					$order->payment_complete( $ugwo_uid );
				}
				break;

			case 'processing':
				if ( $order->has_status( 'pending' ) ) {
					$order->update_status( 'on-hold', __( 'Kwugwo: payment is processing at the PSP.', 'kwugwo-woocommerce' ) );
				}
				break;

			case 'cancelled':
				if ( $order->needs_payment() ) {
					$order->update_status( 'cancelled', __( 'Kwugwo: payment request was cancelled.', 'kwugwo-woocommerce' ) );
				}
				break;

			case 'refunded':
				$order->add_order_note( __( 'Kwugwo: payment was fully refunded. Review and reconcile in WooCommerce if needed.', 'kwugwo-woocommerce' ) );
				break;

			case 'partially_refunded':
				$order->add_order_note( __( 'Kwugwo: payment was partially refunded. Review and reconcile in WooCommerce if needed.', 'kwugwo-woocommerce' ) );
				break;

			default:
				// requires_ugwo or anything new: nothing to do.
				break;
		}

		$order->save();
	}

	/**
	 * Find the uid of a successful charge activity, if the snapshot carries one.
	 * Used only for the order note; never relied on for the decision to complete.
	 *
	 * @param array $ugwo Ugwo.
	 * @return string
	 */
	private function latest_successful_activity( array $ugwo ) {
		// Newer responses expose the latest activity as a single object.
		// (Note the upstream field spelling, "lastest_ugwo_activity".)
		foreach ( array( 'lastest_ugwo_activity', 'latest_ugwo_activity' ) as $key ) {
			if ( ! empty( $ugwo[ $key ] ) && is_array( $ugwo[ $key ] ) ) {
				$activity = $ugwo[ $key ];
				if ( 'successful' === $this->enum_value( isset( $activity['status'] ) ? $activity['status'] : '' ) && ! empty( $activity['uid'] ) ) {
					return $activity['uid'];
				}
			}
		}

		// Older shape: an array of activities.
		if ( ! empty( $ugwo['activities'] ) && is_array( $ugwo['activities'] ) ) {
			foreach ( array_reverse( $ugwo['activities'] ) as $activity ) {
				if ( 'successful' === $this->enum_value( isset( $activity['status'] ) ? $activity['status'] : '' ) && ! empty( $activity['uid'] ) ) {
					return $activity['uid'];
				}
			}
		}

		return '';
	}

	/**
	 * Normalise an enum field. The API returns enums as a two-element pair,
	 * [machine_value, translation_key]; older/simple fields are plain strings.
	 *
	 * @param mixed $value Raw field value.
	 * @return string The machine value.
	 */
	private function enum_value( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value[0] ) ? (string) $value[0] : '';
		}
		return (string) $value;
	}

	/* ---------------------------------------------------------------------
	 * Idempotency
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $event_uid evt.…
	 * @return bool
	 */
	private function already_processed( $event_uid ) {
		return (bool) get_transient( $this->event_key( $event_uid ) );
	}

	/**
	 * @param string $event_uid evt.…
	 */
	private function mark_processed( $event_uid ) {
		if ( $event_uid ) {
			set_transient( $this->event_key( $event_uid ), 1, WEEK_IN_SECONDS );
		}
	}

	/**
	 * @param string $event_uid evt.…
	 * @return string
	 */
	private function event_key( $event_uid ) {
		return 'kwugwo_evt_' . md5( $event_uid );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @return array Gateway settings option.
	 */
	private function settings() {
		return get_option( 'woocommerce_' . KWUGWO_WC_GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Sandbox ids end in `_t` (see docs: IDs & prefixes).
	 *
	 * @param string $id Any Kwugwo id.
	 * @return bool
	 */
	private function is_sandbox_id( $id ) {
		return is_string( $id ) && '_t' === substr( $id, -2 );
	}

	/**
	 * @param bool $test_mode Whether to return the sandbox secret.
	 * @return string
	 */
	private function secret_for_env( $test_mode ) {
		$settings = $this->settings();
		$key      = $test_mode ? 'test_secret_key' : 'live_secret_key';
		return isset( $settings[ $key ] ) ? trim( $settings[ $key ] ) : '';
	}

	/**
	 * Send a status code + tiny JSON body and stop. Kwugwo treats any 2xx as
	 * success; non-2xx (including our 500) triggers its retry schedule.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Short reason.
	 */
	private function respond( $code, $message ) {
		status_header( $code );
		wp_send_json( array( 'message' => $message ), $code );
		// wp_send_json calls wp_die() internally; execution stops here.
	}
}

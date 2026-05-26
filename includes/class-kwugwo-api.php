<?php
/**
 * Kwugwo merchant REST API client (secret-key, server-to-server).
 *
 * Wraps the `/v1/*` endpoints documented at https://kwugwo.africa/docs.
 * Every method returns the decoded JSON body as an associative array, or a
 * WP_Error describing the transport/HTTP/validation failure.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Kwugwo_API {

	const LIVE_BASE    = 'https://api.kwugwo.africa';
	const SANDBOX_BASE = 'https://sandbox-api.kwugwo.africa';

	/**
	 * Secret key (sk.…) for the active environment.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * API base URL for the active environment.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * @param string $secret_key Secret key for the chosen environment.
	 * @param bool   $test_mode  Whether to target the sandbox base URL.
	 */
	public function __construct( $secret_key, $test_mode = false ) {
		$this->secret_key = trim( (string) $secret_key );
		$this->base_url   = $test_mode ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	/**
	 * Create an ugwo (payment request).
	 *
	 * @param array $args {
	 *     @type int    $amount      Smallest currency unit (kobo for NGN). Required.
	 *     @type string $currency    ISO-4217 code, e.g. NGN. Required.
	 *     @type string $ref         Your reference (order number). Optional.
	 *     @type string $description Shown on the hosted checkout. Optional.
	 *     @type string $onye        Customer id (ony.…). Optional.
	 *     @type string $checkout    Checkout id (chk.…). Optional.
	 *     @type array  $metadata    { string: string } tag map. Optional.
	 * }
	 * @return array|WP_Error
	 */
	public function create_ugwo( array $args ) {
		$body = array_filter(
			array(
				'amount'      => isset( $args['amount'] ) ? (int) $args['amount'] : null,
				'currency'    => isset( $args['currency'] ) ? $args['currency'] : null,
				'ref'         => isset( $args['ref'] ) ? $args['ref'] : null,
				'description' => isset( $args['description'] ) ? $args['description'] : null,
				'onye'        => isset( $args['onye'] ) ? $args['onye'] : null,
				'checkout'    => isset( $args['checkout'] ) ? $args['checkout'] : null,
				'metadata'    => isset( $args['metadata'] ) ? $args['metadata'] : null,
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		return $this->request( 'POST', '/v1/ugwo', $body );
	}

	/**
	 * Fetch a single ugwo by id (the source of truth for reconciliation).
	 *
	 * @param string $ugwo_uid ugw.…
	 * @return array|WP_Error
	 */
	public function get_ugwo( $ugwo_uid ) {
		return $this->request( 'GET', '/v1/ugwo/' . rawurlencode( $ugwo_uid ) );
	}

	/**
	 * Create an onye (customer) record.
	 *
	 * @param array $args See https://kwugwo.africa/docs (email required).
	 * @return array|WP_Error
	 */
	public function create_onye( array $args ) {
		$body = array_filter(
			$args,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);

		return $this->request( 'POST', '/v1/onye', $body );
	}

	/**
	 * Whether the client has a non-empty key (cheap pre-flight).
	 *
	 * @return bool
	 */
	public function has_key() {
		return '' !== $this->secret_key;
	}

	/**
	 * Perform an authenticated request and normalise the response.
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Path starting with /v1.
	 * @param array|null $body   Request body for write calls.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		if ( '' === $this->secret_key ) {
			return new WP_Error( 'kwugwo_no_key', __( 'No Kwugwo secret key is configured for the active environment.', 'kwugwo-woocommerce' ) );
		}

		$url = $this->base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'kwugwo-woocommerce/' . KWUGWO_WC_VERSION . '; ' . home_url( '/' ),
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		Kwugwo_Logger::log( sprintf( '→ %s %s %s', $method, $path, null !== $body ? wp_json_encode( $body ) : '' ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Kwugwo_Logger::log( '✗ transport error: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		Kwugwo_Logger::log( sprintf( '← %d %s', $status, $raw ) );

		if ( $status < 200 || $status >= 300 ) {
			$detail = '';
			if ( is_array( $data ) ) {
				if ( ! empty( $data['violations'] ) && is_array( $data['violations'] ) ) {
					$messages = array();
					foreach ( $data['violations'] as $violation ) {
						$messages[] = isset( $violation['title'] ) ? $violation['title'] : '';
					}
					$detail = implode( ' ', array_filter( $messages ) );
				} elseif ( ! empty( $data['detail'] ) ) {
					$detail = $data['detail'];
				} elseif ( ! empty( $data['title'] ) ) {
					$detail = $data['title'];
				}
			}

			return new WP_Error(
				'kwugwo_http_' . $status,
				$detail ? $detail : sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Kwugwo API returned HTTP %d.', 'kwugwo-woocommerce' ),
					$status
				),
				array(
					'status' => $status,
					'body'   => $data,
				)
			);
		}

		if ( null === $data && '' !== $raw ) {
			return new WP_Error( 'kwugwo_bad_json', __( 'Could not decode the Kwugwo API response.', 'kwugwo-woocommerce' ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Constant-time verification of a webhook signature.
	 *
	 * Computes HMAC-SHA256 over the raw request body and compares it against
	 * the `X-Kwugwo-Signature` header. An empty secret means the endpoint was
	 * registered without one, in which case the signature is not enforced.
	 *
	 * @param string $raw_body Raw request body bytes.
	 * @param string $header   Hex-encoded signature from the header.
	 * @param string $secret   Endpoint signing secret.
	 * @return bool
	 */
	public static function verify_signature( $raw_body, $header, $secret ) {
		if ( '' === (string) $secret ) {
			return true;
		}
		if ( '' === (string) $header ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		return hash_equals( $expected, (string) $header );
	}
}

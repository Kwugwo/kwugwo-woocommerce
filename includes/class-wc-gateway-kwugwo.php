<?php
/**
 * Kwugwo payment gateway for WooCommerce.
 *
 * Flow: when the customer places an order we create an ugwo (payment request)
 * server-side with the secret key, then send the customer to the order-pay
 * page where the Kwugwo embedded checkout overlay opens against that ugwo.
 * Fulfilment is confirmed asynchronously by the webhook (see Kwugwo_Webhook).
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Kwugwo extends WC_Payment_Gateway {

	/**
	 * Minimum chargeable amount for NGN, in kobo (₦200.00). Enforced
	 * server-side by Kwugwo; we mirror it for a friendlier message.
	 */
	const NGN_MIN_KOBO = 20000;

	/** Order meta keys. */
	const META_UGWO_UID = '_kwugwo_ugwo_uid';
	const META_ONYE_UID = '_kwugwo_onye_uid';
	const META_TEST_MODE = '_kwugwo_test_mode';

	public function __construct() {
		$this->id                 = KWUGWO_WC_GATEWAY_ID;
		$this->method_title       = __( 'Kwugwo', 'kwugwo-woocommerce' );
		$this->method_description = __( 'Accept bank transfer, USSD and more across Africa with the Kwugwo embedded checkout. Switch between sandbox and live with one toggle.', 'kwugwo-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'kwugwo_wc_icon', KWUGWO_WC_URL . 'assets/images/kwugwo-logo.jpg' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Kwugwo', 'kwugwo-woocommerce' ) );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Render the embed overlay on the order-pay (receipt) page.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	/**
	 * Admin settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'kwugwo-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Kwugwo', 'kwugwo-woocommerce' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'kwugwo-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title the customer sees at checkout.', 'kwugwo-woocommerce' ),
				'default'     => __( 'Kwugwo', 'kwugwo-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'       => __( 'Description', 'kwugwo-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description the customer sees at checkout.', 'kwugwo-woocommerce' ),
				'default'     => __( 'Pay securely with bank transfer, USSD and more. A secure Kwugwo checkout window will open to complete your payment.', 'kwugwo-woocommerce' ),
			),

			'environment'       => array(
				'title'       => __( 'Environment', 'kwugwo-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Kwugwo runs two fully isolated environments. Use sandbox while you build, then untick it to go live. Each environment has its own keys.', 'kwugwo-woocommerce' ),
			),
			'testmode'          => array(
				'title'       => __( 'Sandbox mode', 'kwugwo-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable sandbox (test) mode', 'kwugwo-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'When enabled, all requests use your sandbox keys and the sandbox API; no real money moves. Untick to take live payments.', 'kwugwo-woocommerce' ),
			),

			'live_keys'         => array(
				'title'       => __( 'Live API keys', 'kwugwo-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Issued from the live dashboard. Used when sandbox mode is off.', 'kwugwo-woocommerce' ),
			),
			'live_public_key'   => array(
				'title'       => __( 'Live public key', 'kwugwo-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Starts with pk. — used by the checkout overlay in the browser.', 'kwugwo-woocommerce' ),
				'default'     => '',
				'placeholder' => 'pk.XXXX.…',
				'desc_tip'    => true,
			),
			'live_secret_key'   => array(
				'title'       => __( 'Live secret key', 'kwugwo-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Starts with sk. — used by your server. Never shared with the browser.', 'kwugwo-woocommerce' ),
				'default'     => '',
				'placeholder' => 'sk.XXXX.…',
				'desc_tip'    => true,
			),
			'live_webhook_secret' => array(
				'title'       => __( 'Live webhook secret', 'kwugwo-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Signing secret of the live webhook endpoint you registered in the dashboard. Leave blank if you did not set one.', 'kwugwo-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),

			'sandbox_keys'      => array(
				'title'       => __( 'Sandbox API keys', 'kwugwo-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Issued from the sandbox dashboard. Used when sandbox mode is on.', 'kwugwo-woocommerce' ),
			),
			'test_public_key'   => array(
				'title'       => __( 'Sandbox public key', 'kwugwo-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'pk.XXXX.…',
			),
			'test_secret_key'   => array(
				'title'       => __( 'Sandbox secret key', 'kwugwo-woocommerce' ),
				'type'        => 'password',
				'default'     => '',
				'placeholder' => 'sk.XXXX.…',
			),
			'test_webhook_secret' => array(
				'title'       => __( 'Sandbox webhook secret', 'kwugwo-woocommerce' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Signing secret of the sandbox webhook endpoint. Leave blank if you did not set one.', 'kwugwo-woocommerce' ),
				'desc_tip'    => true,
			),

			'advanced'          => array(
				'title'       => __( 'Advanced', 'kwugwo-woocommerce' ),
				'type'        => 'title',
				'description' => '',
			),
			'checkout_uid'      => array(
				'title'       => __( 'Checkout ID (optional)', 'kwugwo-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Pin payments to a specific dashboard checkout (chk.…) to override default routing. Leave blank to use workspace defaults.', 'kwugwo-woocommerce' ),
				'default'     => '',
				'placeholder' => 'chk.XXXX.…',
				'desc_tip'    => true,
			),
			'debug'             => array(
				'title'       => __( 'Debug log', 'kwugwo-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API requests and webhook events', 'kwugwo-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: log location. */
					__( 'Saved under WooCommerce → Status → Logs (source: %s). Disable on production once verified.', 'kwugwo-woocommerce' ),
					'<code>kwugwo</code>'
				),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Environment helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @return bool Whether sandbox mode is on.
	 */
	public function is_test_mode() {
		return 'yes' === $this->get_option( 'testmode', 'yes' );
	}

	/**
	 * @return string Public key for the active environment.
	 */
	public function get_public_key() {
		return trim( $this->get_option( $this->is_test_mode() ? 'test_public_key' : 'live_public_key', '' ) );
	}

	/**
	 * @return string Secret key for the active environment.
	 */
	public function get_secret_key() {
		return trim( $this->get_option( $this->is_test_mode() ? 'test_secret_key' : 'live_secret_key', '' ) );
	}

	/**
	 * @return string Webhook signing secret for the active environment.
	 */
	public function get_webhook_secret() {
		return $this->get_option( $this->is_test_mode() ? 'test_webhook_secret' : 'live_webhook_secret', '' );
	}

	/**
	 * Hosted checkout origin used by the embed SDK. Filterable for staging.
	 *
	 * @return string
	 */
	public function get_checkout_base_url() {
		return untrailingslashit( apply_filters( 'kwugwo_wc_checkout_base_url', KWUGWO_WC_CHECKOUT_BASE_URL ) );
	}

	/**
	 * @return Kwugwo_API Client for the active environment.
	 */
	public function get_api() {
		return new Kwugwo_API( $this->get_secret_key(), $this->is_test_mode() );
	}

	/**
	 * The webhook URL the merchant registers in the Kwugwo dashboard.
	 *
	 * @return string
	 */
	public function get_webhook_url() {
		return add_query_arg( 'wc-api', 'kwugwo_webhook', home_url( '/' ) );
	}

	/* ---------------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------------- */

	/**
	 * Only offer Kwugwo when it is configured for a supported currency.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! in_array( get_woocommerce_currency(), $this->get_supported_currencies(), true ) ) {
			return false;
		}

		if ( '' === $this->get_public_key() || '' === $this->get_secret_key() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * @return string[] Currencies Kwugwo can charge today.
	 */
	public function get_supported_currencies() {
		return apply_filters( 'kwugwo_wc_supported_currencies', array( 'NGN' ) );
	}

	/**
	 * Whether a Kwugwo key/id belongs to the sandbox environment.
	 *
	 * Sandbox keys and resource ids alike end in the `_t` marker (see the
	 * "IDs & prefixes" docs); live values never do.
	 *
	 * @param string $value A key or id (sk.… / pk.… / ugw.… etc).
	 * @return bool
	 */
	public function is_sandbox_value( $value ) {
		return is_string( $value ) && '_t' === substr( $value, -2 );
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	/**
	 * Save settings, then warn if any key was entered in the wrong
	 * environment field (sandbox keys end in `_t`, live keys do not).
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->validate_key_environments();
		return $saved;
	}

	/**
	 * Flag keys that look like they belong to the other environment.
	 */
	private function validate_key_environments() {
		if ( ! class_exists( 'WC_Admin_Settings' ) ) {
			return;
		}

		$fields = array(
			'test_public_key' => array( true, __( 'Sandbox public key', 'kwugwo-woocommerce' ) ),
			'test_secret_key' => array( true, __( 'Sandbox secret key', 'kwugwo-woocommerce' ) ),
			'live_public_key' => array( false, __( 'Live public key', 'kwugwo-woocommerce' ) ),
			'live_secret_key' => array( false, __( 'Live secret key', 'kwugwo-woocommerce' ) ),
		);

		foreach ( $fields as $field => $meta ) {
			list( $expect_sandbox, $label ) = $meta;
			$value = trim( $this->get_option( $field, '' ) );
			if ( '' === $value ) {
				continue;
			}

			if ( $this->is_sandbox_value( $value ) !== $expect_sandbox ) {
				WC_Admin_Settings::add_error(
					sprintf(
						/* translators: 1: field label, 2: expected environment. */
						__( 'Kwugwo: the value in “%1$s” does not look like a %2$s key. Sandbox keys end in “_t”; live keys do not. Check that you pasted the key into the right field.', 'kwugwo-woocommerce' ),
						$label,
						$expect_sandbox ? __( 'sandbox', 'kwugwo-woocommerce' ) : __( 'live', 'kwugwo-woocommerce' )
					)
				);
			}
		}
	}

	/**
	 * Show the webhook URL and any configuration warnings above the settings.
	 */
	public function admin_options() {
		echo '<h2>' . esc_html__( 'Kwugwo', 'kwugwo-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html( $this->method_description ) . '</p>';

		echo '<div class="notice notice-info inline"><p>';
		echo '<strong>' . esc_html__( 'Webhook URL', 'kwugwo-woocommerce' ) . ':</strong><br/>';
		echo '<code>' . esc_html( $this->get_webhook_url() ) . '</code><br/>';
		echo esc_html__( 'Register this URL as a webhook endpoint in your Kwugwo dashboard (for both sandbox and live), then paste the endpoint signing secret into the matching field below.', 'kwugwo-woocommerce' );
		echo '</p></div>';

		if ( ! in_array( get_woocommerce_currency(), $this->get_supported_currencies(), true ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: 1: store currency, 2: supported list. */
				esc_html__( 'Your store currency is %1$s. Kwugwo currently supports %2$s only, so the gateway will be hidden at checkout.', 'kwugwo-woocommerce' ),
				esc_html( get_woocommerce_currency() ),
				esc_html( implode( ', ', $this->get_supported_currencies() ) )
			);
			echo '</p></div>';
		}

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/* ---------------------------------------------------------------------
	 * Payment
	 * ------------------------------------------------------------------- */

	/**
	 * Create the ugwo and hand off to the order-pay page for the overlay.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'We could not find your order. Please try again.', 'kwugwo-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$currency = $order->get_currency();
		if ( ! in_array( $currency, $this->get_supported_currencies(), true ) ) {
			wc_add_notice( __( 'This currency is not supported by Kwugwo.', 'kwugwo-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = (int) round( (float) $order->get_total() * 100 );

		if ( 'NGN' === $currency && $amount < self::NGN_MIN_KOBO ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: formatted minimum amount. */
					__( 'The minimum amount Kwugwo can charge is %s.', 'kwugwo-woocommerce' ),
					wc_price( self::NGN_MIN_KOBO / 100, array( 'currency' => 'NGN' ) )
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$api = $this->get_api();

		// Attach a customer record so the dashboard groups payments. Reuses the
		// customer's existing onye when we have one for this environment.
		$onye_from_cache = false;
		$onye_uid        = $this->resolve_onye( $api, $order, $onye_from_cache );

		$ugwo_args = array(
			'amount'      => $amount,
			'currency'    => $currency,
			'ref'         => $order->get_order_number(),
			'description' => $this->build_description( $order ),
			'metadata'    => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
				'source'    => 'woocommerce',
			),
		);

		if ( $onye_uid ) {
			$ugwo_args['onye'] = $onye_uid;
		}

		$checkout_uid = trim( $this->get_option( 'checkout_uid', '' ) );
		if ( $checkout_uid ) {
			$ugwo_args['checkout'] = $checkout_uid;
		}

		$ugwo = $api->create_ugwo( $ugwo_args );

		// A reused onye may have been deleted on the dashboard since we cached
		// it. If creation failed with a cached onye, forget it, create a fresh
		// one, and retry once.
		if ( is_wp_error( $ugwo ) && $onye_from_cache ) {
			Kwugwo_Logger::log( 'create_ugwo failed with cached onye; recreating and retrying.', 'warning' );
			$this->forget_onye_uid( $order );

			$fresh_onye = $this->create_onye_record( $api, $order );
			if ( $fresh_onye ) {
				$this->save_onye_uid( $order, $fresh_onye );
				$ugwo_args['onye'] = $fresh_onye;
			} else {
				unset( $ugwo_args['onye'] );
			}

			$onye_uid = $fresh_onye;
			$ugwo     = $api->create_ugwo( $ugwo_args );
		}

		if ( is_wp_error( $ugwo ) ) {
			Kwugwo_Logger::log( 'create_ugwo failed: ' . $ugwo->get_error_message(), 'error' );
			wc_add_notice(
				__( 'We could not start your payment with Kwugwo. Please try again or use another method.', 'kwugwo-woocommerce' ),
				'error'
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message. */
					__( 'Kwugwo: failed to create payment request — %s', 'kwugwo-woocommerce' ),
					$ugwo->get_error_message()
				)
			);
			return array( 'result' => 'failure' );
		}

		if ( empty( $ugwo['uid'] ) ) {
			wc_add_notice( __( 'Kwugwo returned an unexpected response. Please try again.', 'kwugwo-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Persist the linkage for the overlay and for webhook reconciliation.
		$order->update_meta_data( self::META_UGWO_UID, $ugwo['uid'] );
		$order->update_meta_data( self::META_TEST_MODE, $this->is_test_mode() ? 'yes' : 'no' );
		if ( $onye_uid ) {
			$order->update_meta_data( self::META_ONYE_UID, $onye_uid );
		}

		$order->update_status(
			'pending',
			sprintf(
				/* translators: %s: ugwo id. */
				__( 'Kwugwo payment request created (%s). Awaiting customer payment.', 'kwugwo-woocommerce' ),
				$ugwo['uid']
			)
		);

		$order->save();

		// Send the customer to the order-pay page; the receipt hook opens the overlay.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Render the order-pay page: a button plus the embed overlay bootstrap.
	 *
	 * @param int $order_id Order id.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$ugwo_uid = $order->get_meta( self::META_UGWO_UID );
		if ( ! $ugwo_uid ) {
			echo '<p>' . esc_html__( 'This order has no Kwugwo payment session. Please return to checkout and try again.', 'kwugwo-woocommerce' ) . '</p>';
			return;
		}

		$this->enqueue_checkout_assets( $order, $ugwo_uid );

		?>
		<div id="kwugwo-checkout" class="kwugwo-checkout">
			<p class="kwugwo-checkout__intro">
				<?php esc_html_e( 'Complete your payment in the secure Kwugwo window.', 'kwugwo-woocommerce' ); ?>
			</p>
			<button type="button" id="kwugwo-pay-button" class="button alt">
				<?php
				printf(
					/* translators: %s: formatted order total. */
					esc_html__( 'Pay %s now', 'kwugwo-woocommerce' ),
					wp_kses_post( $order->get_formatted_order_total() )
				);
				?>
			</button>
			<p id="kwugwo-checkout-status" class="kwugwo-checkout__status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Enqueue the Kwugwo embed SDK and our bootstrap, passing the order config.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $ugwo_uid ugw.…
	 */
	private function enqueue_checkout_assets( $order, $ugwo_uid ) {
		// Official embed SDK, loaded from the CDN. Exposes window.KwugwoCheckout.
		wp_enqueue_script(
			'kwugwo-checkout-sdk',
			'https://cdn.jsdelivr.net/npm/@kwugwo/checkout',
			array(),
			null, // CDN pins the version; let it manage caching.
			true
		);

		wp_enqueue_script(
			'kwugwo-pay',
			KWUGWO_WC_URL . 'assets/js/kwugwo-pay.js',
			array( 'kwugwo-checkout-sdk' ),
			KWUGWO_WC_VERSION,
			true
		);

		wp_enqueue_style(
			'kwugwo-pay',
			KWUGWO_WC_URL . 'assets/css/kwugwo-pay.css',
			array(),
			KWUGWO_WC_VERSION
		);

		wp_localize_script(
			'kwugwo-pay',
			'KwugwoWC',
			array(
				'publicKey'    => $this->get_public_key(),
				'baseUrl'      => $this->get_checkout_base_url(),
				'ugwoUid'      => $ugwo_uid,
				'returnUrl'    => $order->get_checkout_order_received_url(),
				'cancelUrl'    => $order->get_cancel_order_url_raw(),
				'autoOpen'     => true,
				'i18n'         => array(
					'opening'  => __( 'Opening secure checkout…', 'kwugwo-woocommerce' ),
					'success'  => __( 'Payment received! Redirecting…', 'kwugwo-woocommerce' ),
					'closed'   => __( 'Checkout closed. Click the button to try again.', 'kwugwo-woocommerce' ),
					'error'    => __( 'Something went wrong with the payment. Please try again.', 'kwugwo-woocommerce' ),
					'payAgain' => __( 'Pay now', 'kwugwo-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Build a short, customer-facing description (max 150 chars).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function build_description( $order ) {
		$description = sprintf(
			/* translators: 1: order number, 2: site name. */
			__( 'Order %1$s at %2$s', 'kwugwo-woocommerce' ),
			$order->get_order_number(),
			get_bloginfo( 'name' )
		);
		return mb_substr( $description, 0, 150 );
	}

	/**
	 * Resolve the onye uid to attach to the ugwo.
	 *
	 * Reuses the registered customer's stored onye (for the active
	 * environment) when one exists, so we don't create a fresh customer
	 * record on every payment. Otherwise it creates one and caches the uid
	 * against the WooCommerce customer for next time.
	 *
	 * @param Kwugwo_API $api        Client.
	 * @param WC_Order   $order      Order.
	 * @param bool       $from_cache Set by reference to true when a stored onye was reused.
	 * @return string
	 */
	private function resolve_onye( Kwugwo_API $api, $order, &$from_cache ) {
		$from_cache = false;

		$saved = $this->get_saved_onye_uid( $order );
		if ( $saved ) {
			$from_cache = true;
			Kwugwo_Logger::log( 'Reusing stored onye ' . $saved . ' for customer #' . $order->get_customer_id() );
			return $saved;
		}

		$uid = $this->create_onye_record( $api, $order );
		if ( $uid ) {
			$this->save_onye_uid( $order, $uid );
		}
		return $uid;
	}

	/**
	 * Meta key under which the customer's onye uid is stored, namespaced by
	 * environment (sandbox and live onyes are not interchangeable).
	 *
	 * @return string
	 */
	private function onye_meta_key() {
		return $this->is_test_mode() ? '_kwugwo_onye_uid_test' : '_kwugwo_onye_uid_live';
	}

	/**
	 * Read the stored onye uid for this customer (registered customers only;
	 * guests have no persistent customer record to attach it to).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_saved_onye_uid( $order ) {
		$customer_id = $order->get_customer_id();
		if ( ! $customer_id ) {
			return '';
		}
		$uid = get_user_meta( $customer_id, $this->onye_meta_key(), true );
		return is_string( $uid ) ? $uid : '';
	}

	/**
	 * Persist the onye uid against the WooCommerce customer for reuse.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $uid   onye uid.
	 */
	private function save_onye_uid( $order, $uid ) {
		$customer_id = $order->get_customer_id();
		if ( $customer_id && $uid ) {
			update_user_meta( $customer_id, $this->onye_meta_key(), $uid );
		}
	}

	/**
	 * Forget a stored onye uid (e.g. it was deleted on the dashboard).
	 *
	 * @param WC_Order $order Order.
	 */
	private function forget_onye_uid( $order ) {
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			delete_user_meta( $customer_id, $this->onye_meta_key() );
		}
	}

	/**
	 * Create an onye from the order's billing details (best effort).
	 *
	 * Returns the new onye uid, or '' if creation failed or there was no email.
	 * A failure here is non-fatal: ugwo creation can proceed without an onye
	 * when the workspace has a default payment email configured.
	 *
	 * @param Kwugwo_API $api   Client.
	 * @param WC_Order   $order Order.
	 * @return string
	 */
	private function create_onye_record( Kwugwo_API $api, $order ) {
		$email = $order->get_billing_email();
		if ( ! $email || ! is_email( $email ) ) {
			return '';
		}

		$args = array(
			'email'      => $email,
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
		);

		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			$args['ref'] = 'wp_user_' . $customer_id;
		}

		// Attach a billing address only when the API's required fields are
		// present (address, city, country). `address2` and `zip` are always
		// included — as empty strings when the order has none.
		$address1 = (string) $order->get_billing_address_1();
		$city     = (string) $order->get_billing_city();
		$country  = (string) $order->get_billing_country();

		if ( '' !== $address1 && '' !== $city && '' !== $country ) {
			$args['billing_address'] = array(
				'address'  => $address1,
				'address2' => (string) $order->get_billing_address_2(),
				'city'     => $city,
				'state'    => (string) $order->get_billing_state(),
				'zip'      => (string) $order->get_billing_postcode(),
				'country'  => $country,
			);
		}

		$onye = $api->create_onye( $args );

		if ( is_wp_error( $onye ) ) {
			Kwugwo_Logger::log( 'create_onye failed (continuing without): ' . $onye->get_error_message(), 'warning' );
			return '';
		}

		return ! empty( $onye['uid'] ) ? $onye['uid'] : '';
	}
}

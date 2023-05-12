<?php
/**
 * Class UPE_Payment_Gateway
 *
 * @package WCPay\Payment_Methods
 */

namespace WCPay\Payment_Methods;

use WCPay\Constants\Payment_Method;
use WCPay\Core\Server\Request\Create_Intention;
use WCPay\Core\Server\Request\Create_Setup_Intention;
use WCPay\Core\Server\Request\Get_Charge;
use WCPay\Core\Server\Request\Get_Intention;
use WCPay\Core\Server\Request\Update_Intention;
use WCPay\Constants\Order_Status;
use WCPay\Constants\Payment_Intent_Status;
use WCPay\Exceptions\Amount_Too_Small_Exception;
use WCPay\Exceptions\Add_Payment_Method_Exception;
use WCPay\Exceptions\Process_Payment_Exception;
use WCPay\Fraud_Prevention\Fraud_Prevention_Service;
use WCPay\Logger;
use WCPay\Constants\Payment_Type;
use WCPay\Session_Rate_Limiter;
use Exception;
use WC_Order;
use WC_Payments;
use WC_Payments_Account;
use WC_Payments_Action_Scheduler_Service;
use WC_Payments_API_Client;
use WC_Payments_Customer_Service;
use WC_Payment_Gateway_WCPay;
use WC_Payments_Order_Service;
use WC_Payment_Token_CC;
use WC_Payments_Token_Service;
use WC_Payment_Token_WCPay_SEPA;
use WC_Payments_Utils;
use WC_Payments_Features;
use WCPay\Payment\Flags;
use WCPay\Payment\Manager;
use WCPay\Payment\Payment;
use WCPay\Payment\Order_Payment;
use WCPay\Payment\Order_Payment_Factory;
use WCPay\Payment\Payment_Factory;
use WCPay\Payment\Payment_Method\New_Payment_Method;
use WCPay\Payment\Payment_Method\Payment_Method_Factory;
use WCPay\Payment\Payment_Method\Saved_Payment_Method;
use WCPay\Payment\State\Intent_Without_Order_State;
use WCPay\Payment\State\Processed_State;
use WCPay\Payment\Storage\Filesystem_Order_Storage;
use WCPay\Payment\Storage\Filesystem_Storage;
use WCPay\Payment\Strategy\Standard_Payment_Strategy;
use WCPay\Payment\Strategy\UPE_Update_Intent_Strategy;
use WP_User;


/**
 * UPE Payment method extended from WCPay generic Gateway.
 */
class UPE_Payment_Gateway extends WC_Payment_Gateway_WCPay {
	/**
	 * Internal ID of the payment gateway.
	 *
	 * @type string
	 */
	const GATEWAY_ID = 'woocommerce_payments';

	const METHOD_ENABLED_KEY = 'enabled';

	const UPE_APPEARANCE_TRANSIENT = 'wcpay_upe_appearance';

	const WC_BLOCKS_UPE_APPEARANCE_TRANSIENT = 'wcpay_wc_blocks_upe_appearance';

	const KEY_UPE_PAYMENT_INTENT = 'wcpay_upe_payment_intent';

	const KEY_UPE_SETUP_INTENT = 'wcpay_upe_setup_intent';

	/**
	 * Array mapping payment method string IDs to classes
	 *
	 * @var UPE_Payment_Method[]
	 */
	protected $payment_methods = [];

	/**
	 * Generic gateway title to be displayed at checkout, if more than one payment method is enabled.
	 *
	 * @var string
	 */
	protected $checkout_title;

	/**
	 * UPE Constructor same parameters as WC_Payment_Gateway_WCPay constructor.
	 *
	 * @param WC_Payments_API_Client               $payments_api_client             - WooCommerce Payments API client.
	 * @param WC_Payments_Account                  $account                         - Account class instance.
	 * @param WC_Payments_Customer_Service         $customer_service                - Customer class instance.
	 * @param WC_Payments_Token_Service            $token_service                   - Token class instance.
	 * @param WC_Payments_Action_Scheduler_Service $action_scheduler_service        - Action Scheduler service instance.
	 * @param array                                $payment_methods                 - Array of UPE payment methods.
	 * @param Session_Rate_Limiter                 $failed_transaction_rate_limiter - Session Rate Limiter instance.
	 * @param WC_Payments_Order_Service            $order_service                   - Order class instance.
	 */
	public function __construct(
		WC_Payments_API_Client $payments_api_client,
		WC_Payments_Account $account,
		WC_Payments_Customer_Service $customer_service,
		WC_Payments_Token_Service $token_service,
		WC_Payments_Action_Scheduler_Service $action_scheduler_service,
		array $payment_methods,
		Session_Rate_Limiter $failed_transaction_rate_limiter,
		WC_Payments_Order_Service $order_service
	) {
		parent::__construct( $payments_api_client, $account, $customer_service, $token_service, $action_scheduler_service, $failed_transaction_rate_limiter, $order_service );
		$this->title           = __( 'WooCommerce Payments', 'woocommerce-payments' );
		$this->description     = '';
		$this->checkout_title  = __( 'Popular payment methods', 'woocommerce-payments' );
		$this->payment_methods = $payment_methods;
		if ( ! is_admin() ) {
			add_filter( 'woocommerce_gateway_title', [ $this, 'maybe_filter_gateway_title' ], 10, 2 );
		}
	}

	/**
	 * Registers all scripts, necessary for the gateway.
	 */
	public function register_scripts() {
		// Register Stripe's JavaScript using the same ID as the Stripe Gateway plugin. This prevents this JS being
		// loaded twice in the event a site has both plugins enabled. We still run the risk of different plugins
		// loading different versions however. If Stripe release a v4 of their JavaScript, we could consider
		// changing the ID to stripe_v4. This would allow older plugins to keep using v3 while we used any new
		// feature in v4. Stripe have allowed loading of 2 different versions of stripe.js in the past (
		// https://stripe.com/docs/stripe-js/elements/migrating).
		wp_register_script(
			'stripe',
			'https://js.stripe.com/v3/',
			[],
			'3.0',
			true
		);

		$script_dependencies = [ 'stripe', 'wc-checkout', 'wp-i18n' ];

		if ( $this->supports( 'tokenization' ) ) {
			$script_dependencies[] = 'woocommerce-tokenization-form';
		}
		WC_Payments::register_script_with_dependencies( 'wcpay-upe-checkout', 'dist/upe_checkout', $script_dependencies );

	}

	/**
	 * Displays HTML tags for WC payment gateway radio button.
	 */
	public function display_gateway_html() {
		?>
			<div id="wcpay-upe-element" class="wcpay-upe-element"></div>
			<div id="wcpay-upe-errors" role="alert"></div>
			<input id="wcpay-payment-method-upe" type="hidden" name="wcpay-payment-method-upe" />
			<input id="wcpay_selected_upe_payment_type" type="hidden" name="wcpay_selected_upe_payment_type" />
			<input id="wcpay_payment_country" type="hidden" name="wcpay_payment_country" />
		<?php
	}

	/**
	 * Gets UPE_Payment_Method instance from ID.
	 *
	 * @param string $payment_method_type Stripe payment method type ID.
	 * @return UPE_Payment_Method|false UPE payment method instance.
	 */
	public function get_selected_payment_method( $payment_method_type ) {
		if ( ! $payment_method_type ) {
			return false;
		}

		if ( ! isset( $this->payment_methods[ $payment_method_type ] ) ) {
			return false;
		}
		return $this->payment_methods[ $payment_method_type ];
	}

	/**
	 * Handle AJAX request for updating a payment intent for Stripe UPE.
	 *
	 * @throws Process_Payment_Exception - If nonce or setup intent is invalid.
	 */
	public function update_payment_intent_ajax() {
		try {
			$is_nonce_valid = check_ajax_referer( 'wcpay_update_payment_intent_nonce', false, false );
			if ( ! $is_nonce_valid ) {
				throw new Process_Payment_Exception(
					__( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce-payments' ),
					'wcpay_upe_intent_error'
				);
			}

			$order_id                  = isset( $_POST['wcpay_order_id'] ) ? absint( $_POST['wcpay_order_id'] ) : null;
			$payment_intent_id         = isset( $_POST['wc_payment_intent_id'] ) ? wc_clean( wp_unslash( $_POST['wc_payment_intent_id'] ) ) : '';
			$fingerprint               = isset( $_POST['wcpay-fingerprint'] ) ? wc_clean( wp_unslash( $_POST['wcpay-fingerprint'] ) ) : '';
			$save_payment_method       = isset( $_POST['save_payment_method'] ) ? 'yes' === wc_clean( wp_unslash( $_POST['save_payment_method'] ) ) : false;
			$selected_upe_payment_type = ! empty( $_POST['wcpay_selected_upe_payment_type'] ) ? wc_clean( wp_unslash( $_POST['wcpay_selected_upe_payment_type'] ) ) : '';
			$payment_country           = ! empty( $_POST['wcpay_payment_country'] ) ? wc_clean( wp_unslash( $_POST['wcpay_payment_country'] ) ) : null;

			wp_send_json_success( $this->update_payment_intent( $payment_intent_id, $order_id, $save_payment_method, $selected_upe_payment_type, $payment_country, $fingerprint ), 200 );
		} catch ( Exception $e ) {
			// Send back error so it can be displayed to the customer.
			wp_send_json_error(
				[
					'error' => [
						'message' => WC_Payments_Utils::get_filtered_error_message( $e ),
					],
				]
			);
		}
	}

	/**
	 * Updates payment intent to be able to save payment method.
	 *
	 * @param string  $payment_intent_id         The id of the payment intent to update.
	 * @param int     $order_id                  The id of the order if intent created from Order.
	 * @param boolean $save_payment_method       True if saving the payment method.
	 * @param string  $selected_upe_payment_type The name of the selected UPE payment type or empty string.
	 * @param ?string $payment_country           The payment two-letter iso country code or null.
	 * @param ?string $fingerprint               Fingerprint data.
	 *
	 * @return array|null An array with result of the update, or nothing
	 */
	public function update_payment_intent( $payment_intent_id = '', $order_id = null, $save_payment_method = false, $selected_upe_payment_type = '', $payment_country = null, $fingerprint = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$check_session_order = $this->check_against_session_processing_order( $order );
		if ( is_array( $check_session_order ) ) {
			return $check_session_order;
		}
		$this->maybe_update_session_processing_order( $order_id );

		$check_existing_intention = $this->check_payment_intent_attached_to_order_succeeded( $order );
		if ( is_array( $check_existing_intention ) ) {
			return $check_existing_intention;
		}

		$amount   = $order->get_total();
		$currency = $order->get_currency();

		if ( $payment_intent_id ) {
			list( $user, $customer_id ) = $this->manage_customer_details_for_order( $order );
			$payment_type               = $this->is_payment_recurring( $order_id ) ? Payment_Type::RECURRING() : Payment_Type::SINGLE();
			$payment_methods            = $this->get_selected_upe_payment_methods( (string) $selected_upe_payment_type, $this->get_payment_method_ids_enabled_at_checkout( null, true ) ?? [] );
			$request                    = Update_Intention::create( $payment_intent_id );
			$request->set_currency_code( strtolower( $currency ) );
			$request->set_amount( WC_Payments_Utils::prepare_amount( $amount, $currency ) );
			$request->set_metadata( $this->get_metadata_from_order( $order, $payment_type ) );
			$request->set_level3( $this->get_level3_data_from_order( $order ) );
			$request->set_payment_method_types( $payment_methods );
			$request->set_fingerprint( $fingerprint );
			if ( $payment_country ) {
				$request->set_payment_country( $payment_country );
			}
			if ( true === $save_payment_method ) {
				$request->setup_future_usage();
			}
			if ( $customer_id ) {
				$request->set_customer( $customer_id );
			}

			$request->send( 'wcpay_update_intention_request', $order, $payment_intent_id );
		}

		return [
			'success' => true,
		];
	}

	/**
	 * Handle AJAX request for creating a payment intent for Stripe UPE.
	 *
	 * @throws Process_Payment_Exception - If nonce or setup intent is invalid.
	 */
	public function create_payment_intent_ajax() {
		try {
			$is_nonce_valid = check_ajax_referer( 'wcpay_create_payment_intent_nonce', false, false );
			if ( ! $is_nonce_valid ) {
				throw new Process_Payment_Exception(
					__( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce-payments' ),
					'wcpay_upe_intent_error'
				);
			}

			// If paying from order, we need to get the total from the order instead of the cart.
			$order_id    = isset( $_POST['wcpay_order_id'] ) ? absint( $_POST['wcpay_order_id'] ) : null;
			$fingerprint = isset( $_POST['wcpay-fingerprint'] ) ? wc_clean( wp_unslash( $_POST['wcpay-fingerprint'] ) ) : '';

			$enabled_payment_methods = $this->get_payment_method_ids_enabled_at_checkout( $order_id, true );

			$response = $this->create_payment_intent( $enabled_payment_methods, $order_id, $fingerprint );

			// Encrypt client secret before exposing it to the browser.
			if ( $response['client_secret'] ) {
				$response['client_secret'] = WC_Payments_Utils::encrypt_client_secret( $this->account->get_stripe_account_id(), $response['client_secret'] );
			}

			if ( strpos( $response['id'], 'pi_' ) === 0 ) { // response is a payment intent (could possibly be a setup intent).
				$this->add_upe_payment_intent_to_session( $response['id'], $response['client_secret'], $response['payment_id'] );
			}

			wp_send_json_success( $response, 200 );
		} catch ( Exception $e ) {
			// Send back error so it can be displayed to the customer.
			wp_send_json_error(
				[
					'error' => [
						'message' => WC_Payments_Utils::get_filtered_error_message( $e ),
					],
				]
			);
		}
	}

	/**
	 * Creates payment intent using current cart or order and store details.
	 *
	 * @param array    $displayed_payment_methods Array of enabled payment methods to display in payment element.
	 * @param int|null $order_id The id of the order if intent created from Order.
	 * @param string   $fingerprint User fingerprint.
	 *
	 * @return array
	 */
	public function create_payment_intent( $displayed_payment_methods, $order_id = null, $fingerprint = '' ) {
		$manager = new Manager();

		if ( $order_id ) {
			$order   = wc_get_order( $order_id );
			$payment = $manager->load_or_create_payment( $order );
		} else {
			$payment = $manager->instantiate_payment();
		}

		// Transition to the right state.
		$payment->switch_state( new Intent_Without_Order_State( $payment ) );

		// Setup the payment object.
		$manual_capture = ! empty( $this->settings['manual_capture'] ) && 'yes' === $this->settings['manual_capture'];
		if ( $manual_capture ) {
			$payment->set_flag( Flags::MANUAL_CAPTURE );
		}
		$payment->set_fingerprint( $fingerprint );
		$payment->set_payment_method_types( array_values( $displayed_payment_methods ) );

		// Load the intent.
		$response = $payment->get_or_create_intent();

		// Save the prepared payment before trying to process it.
		if ( $order_id ) {
			$payment->save_to_order( $order );
		} else {
			$payment->save_without_order();
		}

		// Let the payment be processed. The process should yield the response array.
		$response['payment_id'] = $payment->get_id(); // Used to store the payment in session.
		return $response;
	}

	/**
	 * Handle AJAX request for creating a setup intent without confirmation for Stripe UPE.
	 *
	 * @throws Add_Payment_Method_Exception - If nonce or setup intent is invalid.
	 */
	public function init_setup_intent_ajax() {
		try {
			$is_nonce_valid = check_ajax_referer( 'wcpay_create_setup_intent_nonce', false, false );
			if ( ! $is_nonce_valid ) {
				throw new Add_Payment_Method_Exception(
					__( "We're not able to add this payment method. Please refresh the page and try again.", 'woocommerce-payments' ),
					'invalid_referrer'
				);
			}

			$enabled_payment_methods = array_filter( $this->get_upe_enabled_payment_method_ids(), [ $this, 'is_enabled_for_saved_payments' ] );
			$response                = $this->create_setup_intent( $enabled_payment_methods );

			// Encrypt client secret before exposing it to the browser.
			if ( $response['client_secret'] ) {
				$response['client_secret'] = WC_Payments_Utils::encrypt_client_secret( $this->account->get_stripe_account_id(), $response['client_secret'] );
			}

			$this->add_upe_setup_intent_to_session( $response['id'], $response['client_secret'] );

			wp_send_json_success( $response, 200 );
		} catch ( Exception $e ) {
			// Send back error so it can be displayed to the customer.
			wp_send_json_error(
				[
					'error' => [
						'message' => WC_Payments_Utils::get_filtered_error_message( $e ),
					],
				]
			);
		}
	}

	/**
	 * Creates setup intent without confirmation.
	 *
	 * @param array $displayed_payment_methods Array of enabled payment methods to display on element.
	 * @return array
	 */
	public function create_setup_intent( $displayed_payment_methods ) {
		// Determine the customer managing the payment methods, create one if we don't have one already.
		$user        = wp_get_current_user();
		$customer_id = $this->customer_service->get_customer_id_by_user_id( $user->ID );
		if ( null === $customer_id ) {
			$customer_data = WC_Payments_Customer_Service::map_customer_data( null, new \WC_Customer( $user->ID ) );
			$customer_id   = $this->customer_service->create_customer_for_user( $user, $customer_data );
		}

		$request = Create_Setup_Intention::create();
		$request->set_customer( $customer_id );
		$request->set_payment_method_types( array_values( $displayed_payment_methods ) );
		$setup_intent = $request->send( 'wcpay_create_setup_intention_request' );

		return [
			'id'            => $setup_intent['id'],
			'client_secret' => $setup_intent['client_secret'],
		];
	}

	/**
	 * Create and confirm payment intent. Function used to route any payments that do not use the UPE flow through the parent process payment.
	 *
	 * @param int $order_id Order ID to process the payment for.
	 *
	 * @return array|null An array with result of payment and redirect URL, or nothing.
	 */
	public function parent_process_payment( $order_id ) {
		return parent::process_payment( $order_id );
	}

	/**
	 * Renders the credit card input fields needed to get the user's payment information on the checkout page.
	 *
	 * We also add the JavaScript which drives the UI.
	 */
	public function payment_fields() {
		do_action( 'wc_payments_add_upe_payment_fields' );
	}

	/**
	 * Retrieves the ID of an existing intent from session.
	 *
	 * @return string|null
	 */
	protected function get_payment_from_session() {
		$session_data = $this->get_payment_intent_data_from_session();

		if ( ! $session_data ) {
			return null;
		}

		$session_data = explode( '-', $session_data );
		if ( 4 > count( $session_data ) ) {
			return null;
		}

		$payment_id = $session_data['3'];
		if ( empty( $payment_id ) ) {
			return null;
		}

		return $payment_id;
	}

	/**
	 * Update payment intent for completed checkout and return redirect URL for Stripe to confirm payment.
	 *
	 * @param int $order_id Order ID to process the payment for.
	 *
	 * @return array|null An array with result of payment and redirect URL, or nothing.
	 * @throws Exception Error processing the payment.
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$manager = new Manager();

		$existing_id = $this->get_payment_from_session( $order );
		if ( $existing_id ) {
			$payment = $manager->load_payment_by_id( $existing_id );
			$payment->set_order( $order );
			$payment->save_to_order( $order );
		} else {
			$payment = $manager->load_or_create_payment( $order );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Setup the payment object.
		$payment_method = ( new Payment_Method_Factory() )->from_request( $_POST, true );
		$payment->set_payment_method( $payment_method );
		if ( ! $payment_method instanceof Saved_Payment_Method && New_Payment_Method::should_be_saved( $_POST ) ) {
			$payment->set_flag( Flags::SAVE_PAYMENT_METHOD_TO_STORE );
		}

		// Empty string to force checks. Null means skip.
		$payment->set_fraud_prevention_token( $_POST['wcpay-fraud-prevention-token'] ?? '' );

		$payment_intent_id = isset( $_POST['wc_payment_intent_id'] ) ? wc_clean( wp_unslash( $_POST['wc_payment_intent_id'] ) ) : null;
		$payment->set_intent_id( $payment_intent_id );

		// @todo: Some of those could be method parameters.
		$selected_upe_payment_type = ! empty( $_POST['wcpay_selected_upe_payment_type'] ) ? wc_clean( wp_unslash( $_POST['wcpay_selected_upe_payment_type'] ) ) : '';
		$payment->set_selected_upe_payment_type( $selected_upe_payment_type );

		$payment_method_types = $this->get_selected_upe_payment_methods( (string) $selected_upe_payment_type, $this->get_payment_method_ids_enabled_at_checkout( null, true ) ?? [] );
		$payment->set_payment_method_types( $payment_method_types );

		if ( ! empty( $_POST['wcpay_payment_country'] ) ) {
			$payment->set_payment_country( wc_clean( wp_unslash( $_POST['wcpay_payment_country'] ) ) );
		}

		if ( $payment_method instanceof Saved_Payment_Method ) {
			$strategy = new Standard_Payment_Strategy();
		} else {
			$strategy = new UPE_Update_Intent_Strategy();
		}

		$response = $manager->process( $payment, $strategy );
		$payment->save_to_order();

		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return $response;
	}

	/**
	 * Returns true when viewing payment methods page.
	 *
	 * @return bool
	 */
	private function is_payment_methods_page() {
		global $wp;

		$page_id = wc_get_page_id( 'myaccount' );

		return ( $page_id && is_page( $page_id ) && ( isset( $wp->query_vars['payment-methods'] ) ) );
	}

	/**
	 * Get selected UPE payment methods.
	 *
	 * @param string $selected_upe_payment_type Selected payment methods.
	 * @param array  $enabled_payment_methods Enabled payment methods.
	 *
	 * @return array
	 */
	public function get_selected_upe_payment_methods( string $selected_upe_payment_type, array $enabled_payment_methods ) {
		$payment_methods = [];
		if ( '' !== $selected_upe_payment_type ) {
			// Only update the payment_method_types if we have a reference to the payment type the customer selected.
			$payment_methods[] = $selected_upe_payment_type;

			if ( CC_Payment_Method::PAYMENT_METHOD_STRIPE_ID === $selected_upe_payment_type ) {
				$is_link_enabled = in_array(
					Link_Payment_Method::PAYMENT_METHOD_STRIPE_ID,
					$enabled_payment_methods,
					true
				);
				if ( $is_link_enabled ) {
					$payment_methods[] = Link_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
				}
			}
		}
		return $payment_methods;
	}
	/**
	 * Check for a redirect payment method on order received page or setup intent on payment methods page.
	 */
	public function maybe_process_upe_redirect() {
		if ( $this->is_payment_methods_page() ) {
			// If a payment method was added using UPE, we need to clear the cache and notify the user.
			if ( $this->is_setup_intent_success_creation_redirection() ) {
					wc_add_notice( __( 'Payment method successfully added.', 'woocommerce-payments' ) );
					$user = wp_get_current_user();
					$this->customer_service->clear_cached_payment_methods_for_user( $user->ID );
			}
			return;
		}

		if ( ! is_order_received_page() ) {
			return;
		}

		$payment_method = isset( $_GET['wc_payment_method'] ) ? wc_clean( wp_unslash( $_GET['wc_payment_method'] ) ) : '';
		if ( self::GATEWAY_ID !== $payment_method ) {
			return;
		}

		$is_nonce_valid = check_admin_referer( 'wcpay_process_redirect_order_nonce' );
		if ( ! $is_nonce_valid || empty( $_GET['wc_payment_method'] ) ) {
			return;
		}

		if ( ! empty( $_GET['payment_intent_client_secret'] ) ) {
			$intent_id = isset( $_GET['payment_intent'] ) ? wc_clean( wp_unslash( $_GET['payment_intent'] ) ) : '';
		} elseif ( ! empty( $_GET['setup_intent_client_secret'] ) ) {
			$intent_id = isset( $_GET['setup_intent'] ) ? wc_clean( wp_unslash( $_GET['setup_intent'] ) ) : '';
		} else {
			return;
		}

		$order_id            = isset( $_GET['order_id'] ) ? wc_clean( wp_unslash( $_GET['order_id'] ) ) : '';
		$save_payment_method = isset( $_GET['save_payment_method'] ) ? 'yes' === wc_clean( wp_unslash( $_GET['save_payment_method'] ) ) : false;

		if ( empty( $intent_id ) || empty( $order_id ) ) {
			return;
		}

		$this->process_redirect_payment( $order_id, $intent_id, $save_payment_method );
	}

	/**
	 * Processes redirect payments.
	 *
	 * @param int|string $order_id The order ID being processed.
	 * @param string     $intent_id The Stripe setup/payment intent ID for the order payment.
	 * @param bool       $save_payment_method Boolean representing whether payment method for order should be saved.
	 *
	 * @throws Process_Payment_Exception When the payment intent has an error.
	 */
	public function process_redirect_payment( $order_id, $intent_id, $save_payment_method ) {
		$manager = new Manager();

		// Load the order and the payment.
		$order   = wc_get_order( $order_id );
		$payment = $manager->load_payment( $order );

		// Setup the payment. @todo: Compare this with the existing intent ID.
		$payment->set_intent_id( $intent_id );

		if ( $save_payment_method ) {
			$payment->set_flag( Flags::SAVE_PAYMENT_METHOD_TO_STORE );
		}

		// restore the remove-upe-payment-intent-from-session step.

		try {
			// Try loading the intent and advancing to the next stage.
			$payment->load_intent_after_confirmation( $intent_id );
			$response = $manager->process( $payment );
			wp_safe_redirect( $response['redirect_url'] );
		} catch ( Exception $e ) {
			echo $e->getMessage();
			exit;
		}

		try {

		} catch ( Exception $e ) {
			Logger::log( 'Error: ' . $e->getMessage() );

			// Confirm our needed variables are set before using them due to there could be a server issue during the get_intent process.
			$status    = $status ?? null;
			$charge_id = $charge_id ?? null;

			/* translators: localized exception message */
			$message = sprintf( __( 'UPE payment failed: %s', 'woocommerce-payments' ), $e->getMessage() );
			$this->order_service->mark_payment_failed( $order, $intent_id, $status, $charge_id, $message );

			self::remove_upe_payment_intent_from_session();

			wc_add_notice( WC_Payments_Utils::get_filtered_error_message( $e ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Generates the configuration values, needed for UPE payment fields.
	 *
	 * @deprecated 5.0.0
	 *
	 * @return array
	 */
	public function get_payment_fields_js_config() {
		wc_deprecated_function( __FUNCTION__, '5.0.0', 'WC_Payments_UPE_Checkout::get_payment_fields_js_config' );
		return WC_Payments::get_wc_payments_checkout()->get_payment_fields_js_config();
	}

	/**
	 * True if the request contains the values that indicates a redirection after a successful setup intent creation.
	 *
	 * @return bool
	 */
	public function is_setup_intent_success_creation_redirection() {
		return ! empty( $_GET['setup_intent_client_secret'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			! empty( $_GET['setup_intent'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			! empty( $_GET['redirect_status'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'succeeded' === $_GET['redirect_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Adds a token to current user from a setup intent id.
	 *
	 * @param string  $setup_intent_id ID of the setup intent.
	 * @param WP_User $user            User to add token to.
	 *
	 * @return WC_Payment_Token_CC|WC_Payment_Token_WCPay_SEPA|null The added token.
	 */
	public function create_token_from_setup_intent( $setup_intent_id, $user ) {
		try {
			$setup_intent      = $this->payments_api_client->get_setup_intent( $setup_intent_id );
			$payment_method_id = $setup_intent['payment_method'];
			// TODO: When adding SEPA and Sofort, we will need a new API call to get the payment method and from there get the type.
			// Leaving 'card' as a hardcoded value for now to avoid the extra API call.
			$payment_method = $this->payment_methods['card'];

			return $payment_method->get_payment_token_for_user( $user, $payment_method_id );
		} catch ( Exception $e ) {
			wc_add_notice( WC_Payments_Utils::get_filtered_error_message( $e ), 'error', [ 'icon' => 'error' ] );
			Logger::log( 'Error when adding payment method: ' . $e->getMessage() );
		}
	}

	/**
	 * Set formatted readable payment method title for order,
	 * using payment method details from accompanying charge.
	 *
	 * @param WC_Order   $order WC Order being processed.
	 * @param string     $payment_method_type Stripe payment method key.
	 * @param array|bool $payment_method_details Array of payment method details from charge or false.
	 */
	public function set_payment_method_title_for_order( $order, $payment_method_type, $payment_method_details ) {
		$payment_method = $this->get_selected_payment_method( $payment_method_type );
		if ( ! $payment_method ) {
			return;
		}

		$payment_method_title = $payment_method->get_title( $payment_method_details );

		$order->set_payment_method( self::GATEWAY_ID );
		$order->set_payment_method_title( $payment_method_title );
		$order->save();
	}

	/**
	 * Returns the list of enabled payment method types that will function with the current checkout.
	 *
	 * @param string $order_id optional Order ID.
	 * @param bool   $force_currency_check optional Whether the currency check is required even if is_admin().
	 *
	 * @return string[]
	 */
	public function get_payment_method_ids_enabled_at_checkout( $order_id = null, $force_currency_check = false ) {
		$automatic_capture = empty( $this->get_option( 'manual_capture' ) ) || $this->get_option( 'manual_capture' ) === 'no';
		if ( $automatic_capture ) {
			$upe_enabled_payment_methods = $this->get_upe_enabled_payment_method_ids();
		} else {
			$upe_enabled_payment_methods = array_intersect( $this->get_upe_enabled_payment_method_ids(), [ Payment_Method::CARD, Payment_Method::LINK ] );
		}

		$enabled_payment_methods = [];
		$active_payment_methods  = $this->get_upe_enabled_payment_method_statuses();
		foreach ( $upe_enabled_payment_methods as $payment_method_id ) {
			$payment_method_capability_key = $this->payment_method_capability_key_map[ $payment_method_id ] ?? 'undefined_capability_key';
			if ( isset( $this->payment_methods[ $payment_method_id ] ) ) {
				// When creating a payment intent, we need to ensure the currency is matching
				// with the payment methods which are sent with the payment intent request, otherwise
				// Stripe returns an error.

				// force_currency_check = 0 is_admin = 0 currency_is_checked = 1.
				// force_currency_check = 0 is_admin = 1 currency_is_checked = 0.
				// force_currency_check = 1 is_admin = 0 currency_is_checked = 1.
				// force_currency_check = 1 is_admin = 1 currency_is_checked = 1.

				$skip_currency_check       = ! $force_currency_check && is_admin();
				$processing_payment_method = $this->payment_methods[ $payment_method_id ];
				if ( $processing_payment_method->is_enabled_at_checkout() && ( $skip_currency_check || $processing_payment_method->is_currency_valid() ) ) {
					$status = $active_payment_methods[ $payment_method_capability_key ]['status'] ?? null;
					if ( 'active' === $status ) {
						$enabled_payment_methods[] = $payment_method_id;
					}
				}
			}
		}

		// if credit card payment method is not enabled, we don't use stripe link.
		if (
			! in_array( CC_Payment_Method::PAYMENT_METHOD_STRIPE_ID, $enabled_payment_methods, true ) &&
			in_array( Link_Payment_Method::PAYMENT_METHOD_STRIPE_ID, $enabled_payment_methods, true ) ) {
			$enabled_payment_methods = array_filter(
				$enabled_payment_methods,
				static function( $method ) {
					return Link_Payment_Method::PAYMENT_METHOD_STRIPE_ID !== $method;
				}
			);
		}

		return $enabled_payment_methods;
	}

	/**
	 * Returns the list of enabled payment method types that will function with the current checkout filtered by fees.
	 *
	 * @param string $order_id optional Order ID.
	 * @param bool   $force_currency_check optional Whether the currency check is required even if is_admin().
	 *
	 * @return string[]
	 */
	public function get_payment_method_ids_enabled_at_checkout_filtered_by_fees( $order_id = null, $force_currency_check = false ) {
		$enabled_payment_methods = $this->get_payment_method_ids_enabled_at_checkout( $order_id, $force_currency_check );
		$methods_with_fees       = array_keys( $this->account->get_fees() );

		return array_values( array_intersect( $enabled_payment_methods, $methods_with_fees ) );
	}

	/**
	 * Returns the list of available payment method types for UPE.
	 * Filtering out those without configured fees, this will prevent a payment method not supported by the Stripe account's country from being returned.
	 * Note that we are not taking into account capabilities, which are taken into account when managing payment methods in settings.
	 * See https://stripe.com/docs/stripe-js/payment-element#web-create-payment-intent for a complete list.
	 *
	 * @return string[]
	 */
	public function get_upe_available_payment_methods() {
		$available_methods = parent::get_upe_available_payment_methods();

		$available_methods[] = Becs_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Bancontact_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Eps_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Giropay_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Ideal_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Sofort_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Sepa_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = P24_Payment_Method::PAYMENT_METHOD_STRIPE_ID;
		$available_methods[] = Link_Payment_Method::PAYMENT_METHOD_STRIPE_ID;

		$available_methods = array_values(
			apply_filters(
				'wcpay_upe_available_payment_methods',
				$available_methods
			)
		);
		$methods_with_fees = array_keys( $this->account->get_fees() );

		return array_values( array_intersect( $available_methods, $methods_with_fees ) );
	}

	/**
	 * Handle AJAX request for saving UPE appearance value to transient.
	 *
	 * @throws Exception - If nonce or setup intent is invalid.
	 */
	public function save_upe_appearance_ajax() {
		try {
			$is_nonce_valid = check_ajax_referer( 'wcpay_save_upe_appearance_nonce', false, false );
			if ( ! $is_nonce_valid ) {
				throw new Exception(
					__( 'Unable to update UPE appearance values at this time.', 'woocommerce-payments' )
				);
			}

			$is_blocks_checkout = isset( $_POST['is_blocks_checkout'] ) ? rest_sanitize_boolean( wc_clean( wp_unslash( $_POST['is_blocks_checkout'] ) ) ) : false;
			$appearance         = isset( $_POST['appearance'] ) ? json_decode( wc_clean( wp_unslash( $_POST['appearance'] ) ) ) : null;

			$appearance_transient = $is_blocks_checkout ? self::WC_BLOCKS_UPE_APPEARANCE_TRANSIENT : self::UPE_APPEARANCE_TRANSIENT;

			if ( null !== $appearance ) {
				set_transient( $appearance_transient, $appearance, DAY_IN_SECONDS );
			}

			wp_send_json_success( $appearance, 200 );
		} catch ( Exception $e ) {
			// Send back error so it can be displayed to the customer.
			wp_send_json_error(
				[
					'error' => [
						'message' => WC_Payments_Utils::get_filtered_error_message( $e ),
					],
				]
			);
		}
	}

	/**
	 * Clear the saved UPE appearance transient value.
	 */
	public function clear_upe_appearance_transient() {
		delete_transient( self::UPE_APPEARANCE_TRANSIENT );
		delete_transient( self::WC_BLOCKS_UPE_APPEARANCE_TRANSIENT );
	}

	/**
	 * Sets the title on checkout correctly before the title is displayed.
	 *
	 * @param string $title The title of the gateway being filtered.
	 * @param string $id    The id of the gateway being filtered.
	 *
	 * @return string Filtered gateway title.
	 */
	public function maybe_filter_gateway_title( $title, $id ) {
		if ( ! WC_Payments_Features::is_upe_split_enabled() && self::GATEWAY_ID === $id && $this->title === $title ) {
			$title                   = $this->checkout_title;
			$enabled_payment_methods = $this->get_payment_method_ids_enabled_at_checkout();

			if ( 1 === count( $enabled_payment_methods ) ) {
				$title = $this->payment_methods[ $enabled_payment_methods[0] ]->get_title();
			}

			if ( 0 === count( $enabled_payment_methods ) ) {
				$title = $this->payment_methods['card']->get_title();
			}
		}
		return $title;
	}

	/**
	 * Gets payment method settings to pass to client scripts
	 *
	 * @deprecated 5.0.0
	 *
	 * @return array
	 */
	private function get_enabled_payment_method_config() {
		wc_deprecated_function( __FUNCTION__, '5.0.0', 'WC_Payments_UPE_Checkout::get_enabled_payment_method_config' );
		return WC_Payments::get_wc_payments_checkout()->get_enabled_payment_method_config();
	}

	/**
	 * Function to be used with array_filter
	 * to filter UPE payment methods that support saved payments
	 *
	 * @param string $payment_method_id Stripe payment method.
	 *
	 * @return bool
	 */
	public function is_enabled_for_saved_payments( $payment_method_id ) {
		$payment_method = $this->get_selected_payment_method( $payment_method_id );
		if ( ! $payment_method ) {
			return false;
		}
		return $payment_method->is_reusable()
			&& ( is_admin() || $payment_method->is_currency_valid() );
	}

	/**
	 * Returns boolean for whether payment gateway supports saved payments.
	 *
	 * @return bool True, if gateway supports saved payments. False, otherwise.
	 */
	public function should_support_saved_payments() {
		$methods_enabled_for_saved_payments = array_filter( $this->get_upe_enabled_payment_method_ids(), [ $this, 'is_enabled_for_saved_payments' ] );
		return ! empty( $methods_enabled_for_saved_payments );
	}

	/**
	 * Log UPE Payment Errors on Checkout.
	 *
	 * @throws Exception If nonce is not present or invalid or charge ID is empty or order not found.
	 */
	public function log_payment_error_ajax() {
		try {
			$is_nonce_valid = check_ajax_referer( 'wcpay_log_payment_error_nonce', false, false );
			if ( ! $is_nonce_valid ) {
				throw new Exception( 'Invalid request.' );
			}

			$charge_id = isset( $_POST['charge_id'] ) ? wc_clean( wp_unslash( $_POST['charge_id'] ) ) : '';
			if ( empty( $charge_id ) ) {
				throw new Exception( 'Charge ID cannot be empty.' );
			}

			// Get charge data from WCPay Server.
			$request     = Get_Charge::create( $charge_id );
			$charge_data = $request->send( 'wcpay_get_charge_request', $charge_id );
			$order_id    = $charge_data['metadata']['order_id'];

			// Validate Order ID and proceed with logging errors and updating order status.
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( 'Order not found. Unable to log error.' );
			}

			$intent_id = $charge_data['payment_intent'] ?? $order->get_meta( '_intent_id' );

			$request = Get_Intention::create( $intent_id );
			$intent  = $request->send( 'wcpay_get_intent_request', $order );

			$intent_status = $intent->get_status();
			$error_message = esc_html( rtrim( $charge_data['failure_message'], '.' ) );

			$this->order_service->mark_payment_failed( $order, $intent_id, $intent_status, $charge_id, $error_message );

			self::remove_upe_payment_intent_from_session();

			wp_send_json_success();
		} catch ( Exception $e ) {
			self::remove_upe_payment_intent_from_session();

			wp_send_json_error(
				[
					'error' => [
						'message' => $e->getMessage(),
					],
				]
			);
		}
	}

	/**
	 * Returns payment intent session data.
	 *
	 * @param false|string $payment_method Stripe payment method.
	 * @return array|string value of session variable
	 */
	public function get_payment_intent_data_from_session( $payment_method = false ) {
		return WC()->session->get( self::KEY_UPE_PAYMENT_INTENT );
	}

	/**
	 * Returns setup intent session data.
	 *
	 * @param false|string $payment_method Stripe payment method.
	 * @return array|string value of session variable
	 */
	public function get_setup_intent_data_from_session( $payment_method = false ) {
		return WC()->session->get( self::KEY_UPE_SETUP_INTENT );
	}

	/**
	 * Adds the id and client secret of payment intent needed to mount the UPE element in frontend to WC session.
	 *
	 * @param string $intent_id     The payment intent id.
	 * @param string $client_secret The payment intent client secret.
	 * @param string $payment_id    The ID of a payment.
	 */
	private function add_upe_payment_intent_to_session( string $intent_id = '', string $client_secret = '', string $payment_id ) {
		$cart_hash = 'undefined';

		if ( isset( $_COOKIE['woocommerce_cart_hash'] ) ) {
			$cart_hash = sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) );
		}

		$value = $cart_hash . '-' . $intent_id . '-' . $client_secret . '-' . $payment_id;

		WC()->session->set( self::KEY_UPE_PAYMENT_INTENT, $value );
	}

	/**
	 * Removes the payment intent created for UPE from WC session.
	 */
	public static function remove_upe_payment_intent_from_session() {
		if ( isset( WC()->session ) ) {
			WC()->session->__unset( self::KEY_UPE_PAYMENT_INTENT );
		}
	}

	/**
	 * Adds the id and client secret of setup intent needed to mount the UPE element in frontend to WC session.
	 *
	 * @param string $intent_id     The setup intent id.
	 * @param string $client_secret The setup intent client secret.
	 * @param string $payment_id    The ID of a payment.
	 */
	private function add_upe_setup_intent_to_session( string $intent_id = '', string $client_secret = '', string $payment_id ) {
		$value = $intent_id . '-' . $client_secret . '-' . $payment_id;

		WC()->session->set( self::KEY_UPE_SETUP_INTENT, $value );
	}

	/**
	 * Removes the setup intent created for UPE from WC session.
	 */
	public function remove_upe_setup_intent_from_session() {
		WC()->session->__unset( self::KEY_UPE_SETUP_INTENT );
	}

	/**
	 * Returns the checkout tile.
	 *
	 * @return string Checkout title.
	 */
	public function get_checkout_title() {
		return $this->checkout_title;
	}

	/**
	 * Returns the payment methods for this gateway.
	 *
	 * @return array|UPE_Payment_Method[]
	 */
	public function get_payment_methods() {
		return $this->payment_methods;
	}

	/**
	 * Returns the UPE payment method for the gateway (default card gateway).
	 *
	 * @return UPE_Payment_Method
	 */
	public function get_payment_method() {
		return $this->payment_methods['card'];
	}

	/**
	 * This function wraps WC_Payments::get_payment_gateway_by_id, useful for unit testing.
	 *
	 * @param string $payment_method_id Stripe payment method type ID.
	 * @return false|UPE_Payment_Gateway Matching UPE Payment Gateway instance.
	 */
	public function wc_payments_get_payment_gateway_by_id( $payment_method_id ) {
		return $this;
	}

	/**
	 * This function wraps WC_Payments::get_payment_method_by_id, useful for unit testing.
	 *
	 * @param string $payment_method_id Stripe payment method type ID.
	 * @return false|UPE_Payment_Method Matching UPE Payment Method instance.
	 */
	public function wc_payments_get_payment_method_by_id( $payment_method_id ) {
		return $this->payment_methods[ $payment_method_id ];
	}
}

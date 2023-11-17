<?php
/**
 * Class InitialState
 *
 * @package WooCommerce\Payments
 */

namespace WCPay\Internal\Payment\State;

use WC_Payments_Customer_Service;
use WCPay\Constants\Intent_Status;
use WCPay\Core\Exceptions\Server\Request\Extend_Request_Exception;
use WCPay\Core\Exceptions\Server\Request\Immutable_Parameter_Exception;
use WCPay\Core\Exceptions\Server\Request\Invalid_Request_Parameter_Exception;
use WCPay\Internal\Service\PaymentFraudPreventionService;
use WCPay\Internal\Service\PaymentRequestService;
use WCPay\Internal\Service\DuplicatePaymentPreventionService;
use WCPay\Vendor\League\Container\Exception\ContainerException;
use WCPay\Internal\Payment\Exception\StateTransitionException;
use WCPay\Internal\Service\OrderService;
use WCPay\Internal\Service\Level3Service;
use WCPay\Exceptions\Order_Not_Found_Exception;
use WCPay\Internal\Payment\PaymentRequest;
use WCPay\Internal\Payment\PaymentRequestException;

/**
 * Initial state, representing a freshly created payment.
 */
class InitialState extends AbstractPaymentState {
	/**
	 * Order service.
	 *
	 * @var OrderService
	 */
	private $order_service;

	/**
	 * Customer service.
	 *
	 * @var WC_Payments_Customer_Service
	 */
	private $customer_service;

	/**
	 * Level3 Data service.
	 *
	 * @var Level3Service
	 */
	private $level3_service;

	/**
	 * Payment request service.
	 *
	 * @var PaymentRequestService
	 */
	private $payment_request_service;

	/**
	 * Duplicate Payment Prevention service.
	 *
	 * @var DuplicatePaymentPreventionService
	 */
	private $dpps;

	/**
	 * PaymentFraudPreventionService instance.
	 *
	 * @var PaymentFraudPreventionService
	 */
	private $fraud_prevention_service;

	/**
	 * Class constructor, only meant for storing dependencies.
	 *
	 * @param StateFactory                      $state_factory            Factory for payment states.
	 * @param OrderService                      $order_service            Service for order-related actions.
	 * @param WC_Payments_Customer_Service      $customer_service         Service for managing remote customers.
	 * @param Level3Service                     $level3_service           Service for Level3 Data.
	 * @param PaymentRequestService             $payment_request_service  Connection with the server.
	 * @param DuplicatePaymentPreventionService $dpps                     Service for preventing duplicate payments.
	 * @param PaymentFraudPreventionService     $fraud_prevention_service Service for preventing fraud payments.
	 */
	public function __construct(
		StateFactory $state_factory,
		OrderService $order_service,
		WC_Payments_Customer_Service $customer_service,
		Level3Service $level3_service,
		PaymentRequestService $payment_request_service,
		DuplicatePaymentPreventionService $dpps,
		PaymentFraudPreventionService $fraud_prevention_service
	) {
		parent::__construct( $state_factory );

		$this->order_service            = $order_service;
		$this->customer_service         = $customer_service;
		$this->level3_service           = $level3_service;
		$this->payment_request_service  = $payment_request_service;
		$this->dpps                     = $dpps;
		$this->fraud_prevention_service = $fraud_prevention_service;
	}

	/**
	 * Initiates the payment process.
	 *
	 * @param PaymentRequest $request The incoming payment processing request.
	 *
	 * @return AbstractPaymentState      The next state.
	 * @throws StateTransitionException  In case the completed state could not be initialized.
	 * @throws ContainerException        When the dependency container cannot instantiate the state.
	 * @throws Order_Not_Found_Exception Order could not be found.
	 * @throws PaymentRequestException   When data is not available or invalid.
	 */
	public function start_processing( PaymentRequest $request ) {
		// Populate basic details from the request.
		$this->populate_context_from_request( $request );

		// Populate further details from the order.
		$this->populate_context_from_order();

		// Start multiple verification checks.
		$this->process_order_phone_number();

		if ( $this->fraud_prevention_service->is_enabled()
			&& ! $this->fraud_prevention_service->verify_token( $this->get_context()->get_fraud_prevention_token() ) ) {
			throw new StateTransitionException(
				__( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce-payments' )
			);
		}

		$duplicate_order_result = $this->process_duplicate_order();
		if ( null !== $duplicate_order_result ) {
			return $duplicate_order_result;
		}

		$duplicate_payment_result = $this->process_duplicate_payment();
		if ( null !== $duplicate_payment_result ) {
			return $duplicate_payment_result;
		}
		// End multiple verification checks.

		// Payments are currently based on intents, request one from the API.
		try {
			$context = $this->get_context();
			$intent  = $this->payment_request_service->create_intent( $context );
			$context->set_intent( $intent );
		} catch ( Invalid_Request_Parameter_Exception | Extend_Request_Exception | Immutable_Parameter_Exception $e ) {
			return $this->create_state( SystemErrorState::class );
		}

		// Intent requires authorization (3DS check).
		if ( Intent_Status::REQUIRES_ACTION === $intent->get_status() ) {
			$this->order_service->update_order_from_intent_that_requires_action( $context->get_order_id(), $intent, $context );
			return $this->create_state( AuthenticationRequiredState::class );
		}

		// All good. Proceed to processed state.
		$next_state = $this->create_state( ProcessedState::class );

		return $next_state->complete_processing();
	}

	/**
	 * Populates the payment context before processing a payment.
	 *
	 * This method is the link between the payment request, and the payment process.
	 * Use it to make sure that all necessary parameters are provided in advance,
	 * or throw an exception otherwise. Once done, the payment process would rely
	 * on all needed parameters being in place.
	 *
	 * @param PaymentRequest $request The request to use.
	 * @throws PaymentRequestException When data is not available or invalid.
	 */
	protected function populate_context_from_request( PaymentRequest $request ) {
		$context = $this->get_context();

		$context->set_payment_method( $request->get_payment_method() );

		$cvc_confirmation = $request->get_cvc_confirmation();
		if ( ! is_null( $cvc_confirmation ) ) {
			$context->set_cvc_confirmation( $cvc_confirmation );
		}

		$fingerprint = $request->get_fingerprint();
		if ( ! is_null( $fingerprint ) ) {
			$context->set_fingerprint( $fingerprint );
		}

		$fraud_prevention_token = $request->get_fraud_prevention_token();
		if ( ! is_null( $fraud_prevention_token ) ) {
			$context->set_fraud_prevention_token( $fraud_prevention_token );
		}
	}

	/**
	 * Populates the context with details, available in the order.
	 * This includes the update/creation of a customer.
	 *
	 * @throws Order_Not_Found_Exception In case the order could not be found.
	 */
	protected function populate_context_from_order() {
		$context  = $this->get_context();
		$order_id = $context->get_order_id();

		// Start by setting up all local objects.
		$this->order_service->import_order_data_to_payment_context( $order_id, $context );
		$context->set_metadata(
			array_merge(
				$this->order_service->get_payment_metadata( $order_id ),
				[ 'gateway_type' => 'src' ]
			)
		);
		$context->set_level3_data( $this->level3_service->get_data_from_order( $order_id ) );

		// Customer management involves a remote call.
		$customer_id = $this->customer_service->get_or_create_customer_id_from_order(
			$context->get_user_id(),
			$this->order_service->_deprecated_get_order( $order_id )
		);
		$context->set_customer_id( $customer_id );
	}

	/**
	 * Validates the order phone number.
	 *
	 * @return void If valid, do nothing. Otherwise, throw an exception.
	 * @throws Order_Not_Found_Exception
	 * @throws StateTransitionException
	 * @throws ContainerException
	 */
	protected function process_order_phone_number(): void {
		$context  = $this->get_context();
		$order_id = $context->get_order_id();

		if ( ! $this->order_service->is_valid_phone_number( $order_id ) ) {
			throw new StateTransitionException(
				__(
					'Please enter a valid phone number, whose length is less than 20.',
					'woocommerce-payments'
				)
			);
		}
	}

	/**
	 * Detects duplicate orders, and run the necessary actions if one is detected.
	 *
	 * @return DuplicateOrderDetectedState|null The next state, or null if no duplicate order is detected.
	 * @throws Order_Not_Found_Exception
	 * @throws StateTransitionException
	 * @throws ContainerException        When the dependency container cannot instantiate the state.
	 */
	protected function process_duplicate_order(): ?DuplicateOrderDetectedState {
		$context          = $this->get_context();
		$current_order_id = $context->get_order_id();

		$duplicate_order_id = $this->dpps->get_previous_paid_duplicate_order_id( $current_order_id );
		if ( null === $duplicate_order_id ) {
			$this->dpps->update_session_processing_order( $current_order_id );
			return null;
		}

		$this->dpps->clean_up_when_detecting_duplicate_order( $duplicate_order_id, $current_order_id );
		$context->set_duplicate_order_id( $duplicate_order_id );
		return $this->create_state( DuplicateOrderDetectedState::class );
	}

	/**
	 * Detects duplicate payment, and run the necessary actions if one is detected.
	 *
	 * @return CompletedState|null The next state, or null if duplicate payment is detected.
	 * @throws Order_Not_Found_Exception
	 * @throws StateTransitionException
	 * @throws ContainerException        When the dependency container cannot instantiate the state.
	 */
	protected function process_duplicate_payment(): ?CompletedState {
		$context  = $this->get_context();
		$order_id = $context->get_order_id();

		$authorized_intent = $this->dpps->get_authorized_payment_intent_attached_to_order( $order_id );
		if ( null === $authorized_intent ) {
			return null;
		}

		$context->set_intent( $authorized_intent );
		$context->set_detected_authorized_intent();

		$new_state = $this->create_state( ProcessedState::class );
		return $new_state->complete_processing();
	}
}

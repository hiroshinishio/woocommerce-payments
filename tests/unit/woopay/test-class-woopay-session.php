<?php
/**
 * Class WooPay_Session_Test
 *
 * @package WooCommerce\Payments\Tests
 */

use WCPay\WooPay\WooPay_Session;
use WCPay\Platform_Checkout\WooPay_Store_Api_Token;
use WCPay\Platform_Checkout\SessionHandler;

/**
 * WooPay_Session unit tests.
 */
class WooPay_Session_Test extends WCPAY_UnitTestCase {
	public function test_get_user_id_from_cart_token_with_guest_user() {
		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN'] = $guest_cart_token;

		$this->setup_session( 0 );
		$this->setup_adapted_extensions();

		$this->assertEquals( WooPay_Session::get_user_id_from_cart_token(), 0 );
	}

	public function test_get_user_id_from_cart_token_with_non_numeric_customer_id() {
		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN'] = $guest_cart_token;

		$this->setup_session( 'abc' );
		$this->setup_adapted_extensions();

		$this->assertNull( WooPay_Session::get_user_id_from_cart_token() );
	}

	public function test_get_user_id_from_cart_token_with_logged_in_user() {
		$user = self::factory()->user->create_and_get();

		wp_set_current_user( $user->ID );

		$woopay_store_api_token   = WooPay_Store_Api_Token::init();
		$authenticated_cart_token = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN'] = $authenticated_cart_token;

		$this->setup_session( $user->ID );
		$this->setup_adapted_extensions();

		$this->assertEquals( WooPay_Session::get_user_id_from_cart_token(), $user->ID );

		wp_set_current_user( 0 );
	}

	public function test_get_user_id_from_cart_token_with_verified_user_email_address_header_without_email_in_session() {
		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN']                      = $guest_cart_token;
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'test@example.com';

		$this->setup_session( 0 );
		$this->setup_adapted_extensions();

		$this->assertEquals( WooPay_Session::get_user_id_from_cart_token(), 0 );
	}

	public function test_get_user_id_from_cart_token_with_verified_user_store_api_token_without_adapted_extensions() {
		$verified_user = self::factory()->user->create_and_get();

		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN']                      = $guest_cart_token;
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $verified_user->user_email;

		$this->setup_session(
			0,
			$verified_user->user_email
		);

		$this->assertNull( WooPay_Session::get_user_id_from_cart_token() );
	}

	public function test_get_user_id_from_cart_token_with_verified_user_store_api_token() {
		$verified_user = self::factory()->user->create_and_get();

		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN']                      = $guest_cart_token;
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $verified_user->user_email;

		$this->setup_session(
			0,
			$verified_user->user_email
		);
		$this->setup_adapted_extensions();

		$this->assertEquals( WooPay_Session::get_user_id_from_cart_token(), $verified_user->ID );
	}

	public function test_remove_order_customer_id_on_requests_with_verified_email_with_verified_user_store_api_token_without_adapted_extensions() {
		$verified_user = self::factory()->user->create_and_get();

		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN']                      = $guest_cart_token;
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $verified_user->user_email;

		$order = \WC_Helper_Order::create_order( $verified_user->ID );
		$order->set_billing_email( $verified_user->user_email );
		WooPay_Session::remove_order_customer_id_on_requests_with_verified_email( $order );

		$this->assertEquals( $order->get_customer_id(), $verified_user->ID );
	}

	public function test_remove_order_customer_id_on_requests_with_verified_email_with_verified_user_store_api_token_with_non_matching_order_billing_email() {
		$verified_user = self::factory()->user->create_and_get();

		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN']                      = $guest_cart_token;
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $verified_user->user_email;

		$this->setup_adapted_extensions();

		$order = \WC_Helper_Order::create_order( $verified_user->ID );
		$order->set_billing_email( 'test@example.com' );
		WooPay_Session::remove_order_customer_id_on_requests_with_verified_email( $order );

		$this->assertEquals( $order->get_customer_id(), $verified_user->ID );
	}

	public function test_remove_order_customer_id_on_requests_with_verified_email_with_verified_user_store_api_token() {
		$verified_user = self::factory()->user->create_and_get();

		$woopay_store_api_token = WooPay_Store_Api_Token::init();
		$guest_cart_token       = $woopay_store_api_token->get_cart_token();

		$_SERVER['HTTP_CART_TOKEN']                      = $guest_cart_token;
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $verified_user->user_email;

		$this->setup_adapted_extensions();

		$order = \WC_Helper_Order::create_order( $verified_user->ID );
		$order->set_billing_email( $verified_user->user_email );
		WooPay_Session::remove_order_customer_id_on_requests_with_verified_email( $order );

		$this->assertEquals( $order->get_customer_id(), 0 );
	}

	private function setup_session( $customer_id, $customer_email = null ) {
		$session_handler = new SessionHandler();

		$session_handler->init();
		$session_handler->set( 'cart', 'fake cart' );
		$session_handler->set(
			'customer',
			[
				'id'    => $customer_id,
				'email' => $customer_email,
			]
		);

		$session_handler->save_data();
	}

	private function setup_adapted_extensions() {
		add_filter(
			'active_plugins',
			function ( $args ) {
				return array_merge( $args, [ 'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php' ] );
			}
		);
	}
}
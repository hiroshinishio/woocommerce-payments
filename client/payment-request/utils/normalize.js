/**
 * Normalizes incoming cart total items for use as a displayItems with the Stripe api.
 *
 * @param {Array} displayItems Items to normalize.
 * @param {boolean} pending Whether to mark items as pending or not.
 *
 * @return {Array} An array of PaymentItems
 */
export const normalizeLineItems = ( displayItems, pending = false ) => {
	return displayItems
		.filter( ( displayItem ) => {
			return !! displayItem.value;
		} )
		.map( ( displayItem ) => {
			return {
				amount: displayItem.value,
				label: displayItem.label,
				pending,
			};
		} );
};

/**
 * Normalize order data from Stripe's object to the expected format for WC.
 *
 * @param {Object} paymentData Stripe's order object.
 *
 * @return {Object} Order object in the format WooCommerce expects.
 */
export const normalizeOrderData = ( paymentData ) => {
	const name =
		paymentData?.paymentMethod?.billing_details?.name ??
		paymentData.payerName;
	const email = paymentData?.paymentMethod?.billing_details?.email ?? '';
	const billing = paymentData?.paymentMethod?.billing_details?.address ?? {};
	const shipping = paymentData?.shippingAddress ?? {};
	const fraudPreventionTokenValue = window.wcpayFraudPreventionToken ?? '';

	let paymentRequestType = 'payment_request_api';
	if ( paymentData?.walletName === 'applePay' ) {
		paymentRequestType = 'apple_pay';
	} else if ( paymentData?.walletName === 'googlePay' ) {
		paymentRequestType = 'google_pay';
	}

	const phone =
		paymentData?.paymentMethod?.billing_details?.phone ??
		paymentData?.payerPhone?.replace( '/[() -]/g', '' ) ??
		'';
	return {
		billing_first_name:
			name?.split( ' ' )?.slice( 0, 1 )?.join( ' ' ) ?? '',
		billing_last_name: name?.split( ' ' )?.slice( 1 )?.join( ' ' ) || '-',
		billing_company: billing?.organization ?? '',
		billing_email: email ?? paymentData?.payerEmail ?? '',
		billing_phone: phone,
		billing_country: billing?.country ?? '',
		billing_address_1: billing?.line1 ?? '',
		billing_address_2: billing?.line2 ?? '',
		billing_city: billing?.city ?? '',
		billing_state: billing?.state ?? '',
		billing_postcode: billing?.postal_code ?? '',
		shipping_first_name:
			shipping?.recipient?.split( ' ' )?.slice( 0, 1 )?.join( ' ' ) ?? '',
		shipping_last_name:
			shipping?.recipient?.split( ' ' )?.slice( 1 )?.join( ' ' ) ?? '',
		shipping_company: shipping?.organization ?? '',
		shipping_phone: phone,
		shipping_country: shipping?.country ?? '',
		shipping_address_1: shipping?.addressLine?.[ 0 ] ?? '',
		shipping_address_2: shipping?.addressLine?.[ 1 ] ?? '',
		shipping_city: shipping?.city ?? '',
		shipping_state: shipping?.region ?? '',
		shipping_postcode: shipping?.postalCode ?? '',
		shipping_method: [ paymentData?.shippingOption?.id ?? null ],
		order_comments: '',
		payment_method: 'woocommerce_payments',
		ship_to_different_address: 1,
		terms: 1,
		'wcpay-payment-method': paymentData?.paymentMethod?.id,
		payment_request_type: paymentRequestType,
		'wcpay-fraud-prevention-token': fraudPreventionTokenValue,
	};
};

/**
 * Normalize shipping address information from Stripe's address object to
 * the cart shipping address object shape.
 *
 * @param {Object} shippingAddress Stripe's shipping address item
 *
 * @return {Object} The shipping address in the shape expected by the cart.
 */
export const normalizeShippingAddress = ( shippingAddress ) => {
	return {
		first_name:
			shippingAddress?.recipient
				?.split( ' ' )
				?.slice( 0, 1 )
				?.join( ' ' ) ?? '',
		last_name:
			shippingAddress?.recipient?.split( ' ' )?.slice( 1 )?.join( ' ' ) ??
			'',
		company: '',
		address_1: shippingAddress?.addressLine?.[ 0 ] ?? '',
		address_2: shippingAddress?.addressLine?.[ 1 ] ?? '',
		city: shippingAddress?.city ?? '',
		state: shippingAddress?.region ?? '',
		country: shippingAddress?.country ?? '',
		postcode: shippingAddress?.postalCode?.replace( ' ', '' ) ?? '',
	};
};

export const normalizePayForOrderData = ( paymentData ) => {
	let paymentRequestType = 'payment_request_api';
	if ( paymentData?.walletName === 'applePay' ) {
		paymentRequestType = 'apple_pay';
	} else if ( paymentData?.walletName === 'googlePay' ) {
		paymentRequestType = 'google_pay';
	}

	return {
		payment_method: 'woocommerce_payments',
		'wcpay-payment-method': paymentData?.paymentMethod?.id,
		payment_request_type: paymentRequestType,
	};
};

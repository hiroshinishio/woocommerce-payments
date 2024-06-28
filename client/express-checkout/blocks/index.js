/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME_EXPRESS_CHECKOUT_ELEMENT } from 'wcpay/checkout/constants';
import { getConfig } from 'wcpay/utils/checkout';
import ApplePayPreview from './components/apple-pay-preview';
import ExpressCheckoutContainer from './components/express-checkout-container';

const expressCheckoutElementApplePay = ( api ) => ( {
	name: PAYMENT_METHOD_NAME_EXPRESS_CHECKOUT_ELEMENT + '_applePay',
	content: (
		<ExpressCheckoutContainer api={ api } expressPaymentMethod="applePay" />
	),
	edit: <ApplePayPreview />,
	paymentMethodId: PAYMENT_METHOD_NAME_EXPRESS_CHECKOUT_ELEMENT + '_applePay',
	supports: {
		features: getConfig( 'features' ),
	},
	canMakePayment: () => {
		if ( typeof wcpayExpressCheckoutParams === 'undefined' ) {
			return false;
		}

		return true;
	},
} );

const expressCheckoutElementGooglePay = ( api ) => ( {
	name: PAYMENT_METHOD_NAME_EXPRESS_CHECKOUT_ELEMENT + '_googlePay',
	content: (
		<ExpressCheckoutContainer
			api={ api }
			expressPaymentMethod="googlePay"
		/>
	),
	edit: <ApplePayPreview />,
	paymentMethodId:
		PAYMENT_METHOD_NAME_EXPRESS_CHECKOUT_ELEMENT + '_googlePay',
	supports: {
		features: getConfig( 'features' ),
	},
	canMakePayment: () => {
		if ( typeof wcpayExpressCheckoutParams === 'undefined' ) {
			return false;
		}

		return true;
	},
} );

export { expressCheckoutElementApplePay, expressCheckoutElementGooglePay };

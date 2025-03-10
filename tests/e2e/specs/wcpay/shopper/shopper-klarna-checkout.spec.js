/**
 * External dependencies
 */
const { shopper, merchant } = require( '@woocommerce/e2e-utils' );
import config from 'config';
import { uiUnblocked } from '@woocommerce/e2e-utils/build/page-utils';
/**
 * Internal dependencies
 */
import { merchantWCP, shopperWCP } from '../../../utils';
import { setupProductCheckout } from '../../../utils/payments';

const UPE_METHOD_CHECKBOXES = [
	"//label[contains(text(), 'Klarna')]/preceding-sibling::span/input[@type='checkbox']",
];

describe( 'Klarna checkout', () => {
	let wasMulticurrencyEnabled;
	beforeAll( async () => {
		await merchant.login();
		wasMulticurrencyEnabled = await merchantWCP.activateMulticurrency();
		await merchantWCP.deactivateMulticurrency();
		await merchantWCP.enablePaymentMethod( UPE_METHOD_CHECKBOXES );
		await merchant.logout();
		await shopper.login();
	} );

	afterAll( async () => {
		await shopperWCP.emptyCart();
		await shopperWCP.logout();
		await merchant.login();
		if ( wasMulticurrencyEnabled ) {
			await merchantWCP.activateMulticurrency();
		}
		await merchantWCP.disablePaymentMethod( UPE_METHOD_CHECKBOXES );
		await merchant.logout();
	} );

	it( 'should show the product messaging on the product page', async () => {
		await shopperWCP.goToProductPageBySlug( 'belt' );

		// waiting for the "product messaging" component to be rendered, so we can click on it.
		const paymentMethodMessageFrameHandle = await page.waitForSelector(
			'#payment-method-message iframe'
		);
		const paymentMethodMessageIframe = await paymentMethodMessageFrameHandle.contentFrame();

		// Click on Klarna link to open the modal.
		await paymentMethodMessageIframe.evaluate( ( selector ) => {
			const element = document.querySelector( selector );
			if ( element ) {
				element.click();
			}
		}, 'a[aria-label="Open Learn More Modal"]' );

		// Wait for the iframe to be added by Stripe JS after clicking on the element.
		await page.waitForTimeout( 1000 );

		const paymentMethodMessageModalIframeHandle = await page.waitForSelector(
			'iframe[src*="js.stripe.com/v3/elements-inner-payment-method-messaging-modal"]'
		);
		const paymentMethodMessageModalIframe = await paymentMethodMessageModalIframeHandle.contentFrame();

		await expect( paymentMethodMessageModalIframe ).toMatchElement(
			'[data-testid="ModalHeader"] > p',
			{
				text: 'Buy Now. Pay Later.',
			}
		);

		await expect( paymentMethodMessageModalIframe ).toMatchElement(
			'[data-testid="ModalDescription"] > p',
			{
				text:
					'Select Klarna as your payment method at checkout to pay in installments.',
			}
		);
	} );

	it( `should successfully place an order with Klarna`, async () => {
		await setupProductCheckout(
			{
				...config.get( 'addresses.customer.billing' ),
				// these are Klarna-specific values:
				// https://docs.klarna.com/resources/test-environment/sample-customer-data/#united-states-of-america
				email: 'customer@email.us',
				phone: '+13106683312',
				firstname: 'Test',
				lastname: 'Person-us',
			},
			[ [ 'Beanie', 3 ] ]
		);

		await uiUnblocked();

		await page.evaluate( async () => {
			const paymentMethodLabel = document.querySelector(
				'label[for="payment_method_woocommerce_payments_klarna"]'
			);
			if ( paymentMethodLabel ) {
				paymentMethodLabel.click();
			}
		} );

		await shopper.placeOrder();

		await page.waitForSelector( '#phone' );

		await page.waitForTimeout( 2000 );

		await page
			.waitForSelector( '#onContinue' )
			.then( ( button ) => button.click() );

		// This is where the OTP code is entered.
		await page.waitForSelector( '#phoneOtp' );

		await page.waitForTimeout( 2000 ); // Wait for animations

		await expect( page ).toFill( 'input#otp_field', '123456' );

		await page.waitForSelector( '[role="heading"]', {
			visible: true,
			text: /(Confirm and pay)|(Choose how to pay)/i,
		} );

		let readyToBuy;
		try {
			await page.waitForSelector( 'button#buy_button', {
				visible: true,
				timeout: 10000,
			} );
			readyToBuy = true;
		} catch ( err ) {
			readyToBuy = false;
		}

		if ( ! readyToBuy ) {
			// Select Payment Plan - 4 weeks & click continue.
			await page
				.waitForSelector(
					'[id="payinparts_kp.0-ui"] input[type="radio"]'
				)
				.then( ( radio ) => radio.click() );

			await page.waitForTimeout( 2000 );

			await expect( page ).toClick( 'button', {
				text: 'Continue',
			} );
		}

		// Confirm payment.
		await page.waitForSelector( 'button#buy_button' );
		await page.waitForTimeout( 2000 ); // We need to wait a bit for the button to become clickable.
		await expect( page ).toClick( 'button#buy_button' );

		// Wait for the order confirmation page to load.
		await page.waitForNavigation( {
			waitUntil: 'networkidle0',
		} );

		await page.waitForSelector( 'h1.entry-title' );

		await expect( page ).toMatchTextContent( 'Order received' );
	} );
} );

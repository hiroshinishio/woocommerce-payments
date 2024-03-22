/**
 * External dependencies
 */
import React, { useEffect } from 'react';

/**
 * Internal dependencies
 */
import Page from 'components/page';
import { OnboardingContextProvider, useOnboardingContext } from './context';
import { Stepper } from 'components/stepper';
import { OnboardingForm } from './form';
import Step from './step';
import PersonalDetails from './steps/personal-details';
import BusinessDetails from './steps/business-details';
import StoreDetails from './steps/store-details';
import LoadingStep from './steps/loading';
import { trackStarted } from './tracking';
import './style.scss';
import { persistFlowState } from './utils';

const OnboardingStepper = () => {
	const { data } = useOnboardingContext();

	const handleExit = () => {
		if (
			window.history.length > 1 &&
			document.referrer.includes( wcSettings.adminUrl )
		)
			return window.history.back();
		window.location.href = wcSettings.adminUrl;
	};

	const handleStepChange = ( step: string ) => {
		window.scroll( 0, 0 );
		persistFlowState( step, data );
	};

	const initialStep = () => {
		// since mode step is not part of the stepper anymore, we need to overwrite it
		// Remove it in a future version, once enough time has passed that people won't be likely to have mode or personal saved as this value.
		const currentStep = wcpaySettings.onboardingFlowState?.current_step;
		if (
			currentStep &&
			( currentStep === 'mode' || currentStep === 'personal' )
		) {
			return 'business';
		}
		return currentStep;
	};

	return (
		<Stepper
			initialStep={ initialStep() }
			onStepChange={ handleStepChange }
			onExit={ handleExit }
		>
			<Step name="personal">
				<OnboardingForm>
					<PersonalDetails />
				</OnboardingForm>
			</Step>
			<Step name="business">
				<OnboardingForm>
					<BusinessDetails />
				</OnboardingForm>
			</Step>
			<Step name="store">
				<OnboardingForm>
					<StoreDetails />
				</OnboardingForm>
			</Step>
			<LoadingStep name="loading" />
		</Stepper>
	);
};

const initialData = wcpaySettings.onboardingFlowState?.data ?? {
	business_name: wcSettings?.siteTitle,
	url:
		location.hostname === 'localhost'
			? 'https://wcpay.test'
			: wcSettings?.homeUrl,
	country: wcpaySettings?.connect?.country,
};

const OnboardingPage: React.FC = () => {
	useEffect( () => {
		const urlParams = new URLSearchParams( window.location.search );
		const source = urlParams.get( 'source' ) || '';
		trackStarted( source.replace( /[^\w-]+/g, '' ) );

		// Remove loading class and add those required for full screen.
		document.body.classList.remove( 'woocommerce-admin-is-loading' );
		document.body.classList.add( 'woocommerce-admin-full-screen' );
		document.body.classList.add( 'is-wp-toolbar-disabled' );
		document.body.classList.add( 'wcpay-onboarding__body' );

		// Remove full screen classes on unmount.
		return () => {
			document.body.classList.remove( 'woocommerce-admin-full-screen' );
			document.body.classList.remove( 'is-wp-toolbar-disabled' );
			document.body.classList.remove( 'wcpay-onboarding__body' );
		};
	}, [] );

	return (
		<Page className="wcpay-onboarding-prototype">
			<OnboardingContextProvider initialData={ initialData }>
				<OnboardingStepper />
			</OnboardingContextProvider>
		</Page>
	);
};

export default OnboardingPage;

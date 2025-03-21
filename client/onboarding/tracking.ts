/**
 * External dependencies
 */
import { useEffect } from 'react';

/**
 * Internal dependencies
 */

import { useStepperContext } from 'components/stepper';
import { useOnboardingContext } from './context';
import { OnboardingFields } from './types';
import { recordEvent } from 'tracks';

const trackedSteps: Set< string > = new Set();
let startTime: number;
let stepStartTime: number;

const elapsed = ( time: number ) => Math.round( ( Date.now() - time ) / 1000 );
const stepElapsed = () => {
	const result = elapsed( stepStartTime );
	stepStartTime = Date.now();
	return result;
};

export const trackStarted = ( source: string ): void => {
	startTime = stepStartTime = Date.now();

	recordEvent( 'wcpay_onboarding_flow_started', {
		source,
	} );
};

export const trackStepCompleted = ( step: string ): void => {
	// We only track a completed step once.
	if ( trackedSteps.has( step ) ) return;

	recordEvent( 'wcpay_onboarding_flow_step_completed', {
		step,
		elapsed: stepElapsed(),
	} );
	trackedSteps.add( step );
};

export const trackRedirected = (
	isEligible: boolean,
	source: string
): void => {
	recordEvent( 'wcpay_onboarding_flow_redirected', {
		is_po_eligible: isEligible,
		elapsed: elapsed( startTime ),
		source,
	} );
};

export const trackAccountReset = (): void =>
	recordEvent( 'wcpay_onboarding_flow_reset' );

export const trackEligibilityModalClosed = (
	action: 'dismiss' | 'setup_deposits' | 'enable_payments_only',
	source: string
): void =>
	recordEvent( 'wcpay_onboarding_flow_eligibility_modal_closed', {
		action,
		source,
	} );

export const useTrackAbandoned = (): {
	trackAbandoned: ( method: 'hide' | 'exit', source: string ) => void;
	removeTrackListener: () => void;
} => {
	const { errors, touched } = useOnboardingContext();
	const { currentStep: step } = useStepperContext();

	const trackEvent = ( method = 'hide', source = 'unknown' ) => {
		const event =
			method === 'hide'
				? 'wcpay_onboarding_flow_hidden'
				: 'wcpay_onboarding_flow_exited';
		const errored = Object.keys( errors ).filter(
			( field ) => touched[ field as keyof OnboardingFields ]
		);

		recordEvent( event, {
			step,
			errored,
			elapsed: elapsed( startTime ),
			source,
		} );
	};

	const listener = () => {
		if ( document.visibilityState === 'hidden' ) {
			const urlParams = new URLSearchParams( window.location.search );
			const source =
				urlParams.get( 'source' )?.replace( /[^\w-]+/g, '' ) ||
				'unknown';
			trackEvent( 'hide', source );
		}
	};

	useEffect( () => {
		document.addEventListener( 'visibilitychange', listener );
		return () => {
			document.removeEventListener( 'visibilitychange', listener );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step, errors, touched ] );

	return {
		trackAbandoned: ( method: string, source = 'unknown' ) => {
			trackEvent( method, source );
			document.removeEventListener( 'visibilitychange', listener );
		},
		removeTrackListener: () =>
			document.removeEventListener( 'visibilitychange', listener ),
	};
};

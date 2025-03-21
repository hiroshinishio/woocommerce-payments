/** @format */

/**
 * External dependencies
 */
import * as React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { updateQueryString } from '@woocommerce/navigation';

/**
 * Internal dependencies
 */
import TransactionsPage from '../';
import {
	useAuthorizationsSummary,
	useFraudOutcomeTransactionsSummary,
	useManualCapture,
	useSettings,
	useTransactions,
	useTransactionsSummary,
	useReportingExportLanguage,
} from 'data/index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Workaround for mocking @wordpress/data.
// See https://github.com/WordPress/gutenberg/issues/15031
jest.mock( '@wordpress/data', () => ( {
	createRegistryControl: jest.fn(),
	dispatch: jest.fn( () => ( {
		setIsMatching: jest.fn(),
		onLoad: jest.fn(),
		onHistoryChange: jest.fn(),
	} ) ),
	registerStore: jest.fn(),
	select: jest.fn(),
	useDispatch: jest.fn( () => ( { createNotice: jest.fn() } ) ),
	withDispatch: jest.fn( () => jest.fn() ),
	withSelect: jest.fn( () => jest.fn() ),
} ) );

jest.mock( 'data/index', () => ( {
	useTransactions: jest.fn(),
	useTransactionsSummary: jest.fn(),
	useFraudOutcomeTransactionsSummary: jest.fn(),
	useManualCapture: jest.fn(),
	useSettings: jest.fn(),
	useAuthorizationsSummary: jest.fn(),
	useReportingExportLanguage: jest.fn( () => [ 'en', jest.fn() ] ),
} ) );

const mockUseTransactions = useTransactions as jest.MockedFunction<
	typeof useTransactions
>;

const mockUseTransactionsSummary = useTransactionsSummary as jest.MockedFunction<
	typeof useTransactionsSummary
>;

const mockUseSettings = useSettings as jest.MockedFunction<
	typeof useSettings
>;

const mockUseManualCapture = useManualCapture as jest.MockedFunction<
	typeof useManualCapture
>;

const mockUseAuthorizationsSummary = useAuthorizationsSummary as jest.MockedFunction<
	typeof useAuthorizationsSummary
>;

const mockUseFraudOutcomeTransactionsSummary = useFraudOutcomeTransactionsSummary as jest.MockedFunction<
	typeof useFraudOutcomeTransactionsSummary
>;

const mockUseReportingExportLanguage = useReportingExportLanguage as jest.MockedFunction<
	typeof useReportingExportLanguage
>;

declare const global: {
	wcpaySettings: {
		featureFlags: {
			customSearch: boolean;
			isAuthAndCaptureEnabled: boolean;
		};
		zeroDecimalCurrencies: string[];
		connect: {
			country: string;
		};
		accountStatus: {
			status: boolean;
		};
	};
};

describe( 'TransactionsPage', () => {
	beforeEach( () => {
		jest.clearAllMocks();

		mockUseReportingExportLanguage.mockReturnValue( [ 'en', jest.fn() ] );

		// the query string is preserved across tests, so we need to reset it
		updateQueryString( {}, '/', {} );

		mockUseSettings.mockReturnValue( {
			isLoading: false,
			isSaving: false,
			saveSettings: ( a ) => a,
		} );

		mockUseTransactions.mockReturnValue( {
			isLoading: false,
			transactions: [],
		} );

		mockUseTransactionsSummary.mockReturnValue( {
			isLoading: false,
			transactionsSummary: {
				count: 10,
				total: 15,
			},
		} );

		mockUseFraudOutcomeTransactionsSummary.mockReturnValue( {
			isLoading: false,
			transactionsSummary: {},
		} );

		global.wcpaySettings = {
			featureFlags: {
				customSearch: true,
				isAuthAndCaptureEnabled: true,
			},
			zeroDecimalCurrencies: [],
			connect: {
				country: 'US',
			},
			accountStatus: {
				status: true,
			},
		};
	} );

	const renderTransactionsPage = async () => {
		const renderResult = render( <TransactionsPage /> );

		await waitFor( () => {
			expect( mockUseAuthorizationsSummary ).toHaveBeenCalled();
		} );

		return renderResult;
	};

	test( 'renders uncaptured tab if auth&capture is DISABLED but authorizations are present', async () => {
		mockUseManualCapture.mockReturnValue( [ false ] );
		mockUseAuthorizationsSummary.mockReturnValue( {
			authorizationsSummary: {
				total: 5,
			},
			isLoading: false,
		} );

		await renderTransactionsPage();
		expect( screen.queryByText( /uncaptured/i ) ).toBeInTheDocument();
	} );

	test( 'renders uncaptured tab if auth&capture is ENABLED and authorizations are present', async () => {
		mockUseManualCapture.mockReturnValue( [ true ] );
		mockUseAuthorizationsSummary.mockReturnValue( {
			authorizationsSummary: {
				total: 5,
			},
			isLoading: false,
		} );

		await renderTransactionsPage();
		expect( screen.queryByText( /uncaptured/i ) ).toBeInTheDocument();
	} );

	test( 'renders uncaptured tab if auth&capture is ENABLED and no authorizations are present', async () => {
		mockUseManualCapture.mockReturnValue( [ true ] );
		mockUseAuthorizationsSummary.mockReturnValue( {
			authorizationsSummary: {
				total: 0,
			},
			isLoading: false,
		} );

		await renderTransactionsPage();
		expect( screen.queryByText( /uncaptured/i ) ).toBeInTheDocument();
	} );

	test( 'do not render uncaptured tab if auth&capture is DISABLED and no authorizations are present', async () => {
		mockUseManualCapture.mockReturnValue( [ false ] );
		mockUseAuthorizationsSummary.mockReturnValue( {
			authorizationsSummary: {
				total: 0,
			},
			isLoading: false,
		} );

		await renderTransactionsPage();
		expect( screen.queryByText( /uncaptured/i ) ).not.toBeInTheDocument();
	} );

	test( 'renders fraud outcome tabs', async () => {
		mockUseManualCapture.mockReturnValue( [ false ] );
		mockUseAuthorizationsSummary.mockReturnValue( {
			authorizationsSummary: {
				total: 0,
			},
			isLoading: false,
		} );

		await renderTransactionsPage();
		expect( screen.queryByText( /blocked/i ) ).toBeInTheDocument();
	} );
} );

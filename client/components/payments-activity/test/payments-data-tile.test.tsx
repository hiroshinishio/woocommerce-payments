/**
 * External dependencies
 */
import React from 'react';
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PaymentsDataTile from '../payments-data-tile';

declare const global: {
	wcpaySettings: {
		accountDefaultCurrency: string;
		zeroDecimalCurrencies: string[];
		currencyData: Record< string, any >;
		connect: {
			country: string;
		};
	};
};

describe( 'PaymentsDataTile', () => {
	global.wcpaySettings = {
		accountDefaultCurrency: 'USD',
		zeroDecimalCurrencies: [],
		connect: {
			country: 'US',
		},
		currencyData: {
			US: {
				code: 'USD',
				symbol: '$',
				symbolPosition: 'left',
				thousandSeparator: ',',
				decimalSeparator: '.',
				precision: 2,
			},
		},
	};

	test( 'renders correctly', () => {
		const { container } = render(
			<PaymentsDataTile
				id="total-payments"
				currencyCode="USD"
				label="Total Payments"
			/>
		);
		expect( container ).toMatchSnapshot();
	} );

	test( 'renders label correctly', () => {
		const label = 'Total Payments';
		render(
			<PaymentsDataTile
				id="total-payments"
				currencyCode="USD"
				label={ label }
			/>
		);
		const labelElement = screen.getByText( label );
		expect( labelElement ).toBeInTheDocument();
	} );

	test( 'renders amount correctly', () => {
		const amount = 10000;
		const currencyCode = 'USD';
		render(
			<PaymentsDataTile
				id="charges-test-tile"
				label="Charges"
				amount={ amount }
				currencyCode={ currencyCode }
			/>
		);
		const amountElement = screen.getByText( '$100.00' );
		expect( amountElement ).toBeInTheDocument();
	} );

	test( 'renders report link correctly', () => {
		const reportLink = 'https://example.com/report';
		render(
			<PaymentsDataTile
				id="charges-test-tile"
				label="Charges"
				amount={ 10000 }
				currencyCode="USD"
				reportLink={ reportLink }
			/>
		);
		const reportLinkElement = screen.getByRole( 'link', {
			name: 'View report',
		} );
		expect( reportLinkElement ).toBeInTheDocument();
		expect( reportLinkElement ).toHaveAttribute( 'href', reportLink );
	} );
} );
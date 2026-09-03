/**
 * Tests for the shared bulk-confirmation title list.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import { _n } from '@wordpress/i18n';
import ConfirmTitleList from '@/components/ConfirmTitleList';

vi.mock( '@wordpress/i18n', async ( importOriginal ) => ( {
	...( await importOriginal< typeof import('@wordpress/i18n') >() ),
	_n: vi.fn( ( singular: string, plural: string, count: number ) =>
		1 === count ? singular : plural
	),
} ) );

/**
 * Builds enough titles to leave the given count beyond the visible limit.
 *
 * @param {number} hiddenCount Number of titles hidden by the list.
 *
 * @return {string[]} Visible and hidden title fixtures.
 */
function buildTitles( hiddenCount: number ): string[] {
	return Array.from( { length: 10 + hiddenCount }, ( _, index ) =>
		`Post ${ index + 1 }`
	);
}

describe( 'ConfirmTitleList overflow count', () => {
	beforeEach( () => {
		vi.mocked( _n ).mockClear();
	} );

	it( 'Verifies that a single hidden title uses the singular translation', () => {
		// ARRANGE + ACT: Eleven titles leave one beyond the visible limit.
		render(
			<ConfirmTitleList heading="Affected posts" titles={ buildTitles( 1 ) } />
		);

		// ASSERT: The overflow goes through the plural API with a count of one.
		expect( screen.getByText( '…and 1 more' ) ).toBeInTheDocument();
		expect( _n ).toHaveBeenCalledWith(
			'…and %d more',
			'…and %d more',
			1,
			'safe-publish'
		);
	} );

	it( 'Verifies that multiple hidden titles use the plural translation', () => {
		// ARRANGE + ACT: Twelve titles leave two beyond the visible limit.
		render(
			<ConfirmTitleList heading="Affected posts" titles={ buildTitles( 2 ) } />
		);

		// ASSERT: The overflow gives the plural API the actual hidden count.
		expect( screen.getByText( '…and 2 more' ) ).toBeInTheDocument();
		expect( _n ).toHaveBeenCalledWith(
			'…and %d more',
			'…and %d more',
			2,
			'safe-publish'
		);
	} );
} );

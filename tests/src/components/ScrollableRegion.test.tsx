/**
 * Tests for the keyboard-operable overflow region.
 */
import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import ScrollableRegion from '@/components/ScrollableRegion';

describe( 'ScrollableRegion', () => {
	function renderRegion(): HTMLElement {
		render(
			<ScrollableRegion ariaLabel="Import results">
				Results
			</ScrollableRegion>
		);
		const region = screen.getByRole( 'region', { name: 'Import results' } );
		Object.defineProperties( region, {
			clientHeight: { value: 200 },
			scrollHeight: { value: 600 },
		} );
		return region;
	}

	it( 'Verifies that scrolling keys move within the region bounds', () => {
		// ARRANGE: Render an overflow region at its initial position.
		const region = renderRegion();

		// ACT + ASSERT: Page Down advances one viewport and End reaches the end.
		expect( fireEvent.keyDown( region, { key: 'PageDown' } ) ).toBe( false );
		expect( region.scrollTop ).toBe( 200 );
		expect( fireEvent.keyDown( region, { key: 'End' } ) ).toBe( false );
		expect( region.scrollTop ).toBe( 400 );
	} );

	it( 'Verifies that boundary keys remain available to the page', () => {
		// ARRANGE: Render an overflow region positioned at its end.
		const region = renderRegion();
		region.scrollTop = 400;

		// ACT + ASSERT: End cannot move the region, so it is not consumed.
		expect( fireEvent.keyDown( region, { key: 'End' } ) ).toBe( true );
		expect( region.scrollTop ).toBe( 400 );
	} );
} );

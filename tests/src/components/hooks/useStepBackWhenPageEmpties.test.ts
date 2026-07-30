/**
 * Tests for the useStepBackWhenPageEmpties hook.
 *
 * The hook is the shared guard that keeps a server-paginated listing from
 * stranding the user on an empty trailing page after a row removal. Multiple
 * listings lean on it, so the step-back decision is pinned here, independent
 * of any single consumer.
 */
import { describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import {
	useStepBackWhenPageEmpties,
} from '@/components/hooks/useStepBackWhenPageEmpties';

type Options = Parameters< typeof useStepBackWhenPageEmpties >[ 0 ];

/**
 * Mounts the hook with step-back-eligible defaults, applies any overrides, and
 * returns the setPage spy.
 */
function renderStepBack(
	overrides: Partial< Omit< Options, 'setPage' > > = {}
): Options[ 'setPage' ] {
	const setPage = vi.fn();
	renderHook( () =>
		useStepBackWhenPageEmpties( {
			hasFetchedOnce: true,
			isLoading: false,
			fetchError: null,
			isEmpty: true,
			page: 2,
			...overrides,
			setPage,
		} )
	);
	return setPage;
}

describe( 'useStepBackWhenPageEmpties', () => {
	it( 'steps back one page when a later page settles empty', () => {
		// ARRANGE + ACT: An empty page 2 settles with no error.
		const setPage = renderStepBack();

		// ASSERT: the listing drops to page 1, exactly once.
		expect( setPage ).toHaveBeenCalledTimes( 1 );
		expect( setPage ).toHaveBeenCalledWith( 1 );
	} );

	it( 'steps back a single page at a time', () => {
		// ARRANGE + ACT: An empty page 3 settles.
		const setPage = renderStepBack( { page: 3 } );

		// ASSERT: it retreats to page 2, not all the way to page 1.
		expect( setPage ).toHaveBeenCalledWith( 2 );
	} );

	it( 'stays put on the first page', () => {
		// ARRANGE + ACT: An empty first page, explicit and as the default.
		const explicit = renderStepBack( { page: 1 } );
		const implicit = renderStepBack( { page: undefined } );

		// ASSERT: page 1 is the floor; no step-back fires.
		expect( explicit ).not.toHaveBeenCalled();
		expect( implicit ).not.toHaveBeenCalled();
	} );

	it( 'waits while a fetch is still in flight', () => {
		// ARRANGE + ACT: The empty result is not yet settled.
		const setPage = renderStepBack( { isLoading: true } );

		// ASSERT: no step-back until the fetch settles.
		expect( setPage ).not.toHaveBeenCalled();
	} );

	it( 'holds position when the fetch errored', () => {
		// ARRANGE + ACT: The page is empty only because the fetch failed.
		const setPage = renderStepBack( { fetchError: 'Network error.' } );

		// ASSERT: no step-back fires on a failed fetch.
		expect( setPage ).not.toHaveBeenCalled();
	} );

	it( 'holds position when the page still has rows', () => {
		// ARRANGE + ACT: A settled page that is not empty.
		const setPage = renderStepBack( { isEmpty: false } );

		// ASSERT: there is nothing to step back from.
		expect( setPage ).not.toHaveBeenCalled();
	} );

	it( 'does nothing before the first fetch settles', () => {
		// ARRANGE + ACT: Initial mount, no fetch has completed yet.
		const setPage = renderStepBack( { hasFetchedOnce: false } );

		// ASSERT: the guard waits for real data before moving the page.
		expect( setPage ).not.toHaveBeenCalled();
	} );
} );

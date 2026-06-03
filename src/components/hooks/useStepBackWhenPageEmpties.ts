/**
 * Steps a server-paginated listing back one page when a fetch lands empty
 * past page 1, so a row removal (or upstream change) on the last page
 * doesn't strand the user on an empty grid.
 *
 * @file This file defines the useStepBackWhenPageEmpties hook.
 */

import { useEffect } from '@wordpress/element';

/**
 * Reduces the current page by 1 when the latest settled fetch returned no
 * rows past page 1. Boolean derivation lives in the hook so callers don't
 * pay the cyclomatic complexity of the gating `&&` chain.
 *
 * @param {Object}      options                Hook arguments.
 * @param {boolean}     options.hasFetchedOnce True once the first fetch settled.
 * @param {boolean}     options.isLoading      True while a fetch is in flight.
 * @param {string|null} options.fetchError     Last fetch's error, or null.
 * @param {boolean}     options.isEmpty        True when the page rendered no rows.
 * @param {number}      options.page           Current page; undefined treated as 1.
 * @param {Function}    options.setPage        Setter for the next page number.
 */
export function useStepBackWhenPageEmpties( options: {
	hasFetchedOnce: boolean;
	isLoading: boolean;
	fetchError: string | null;
	isEmpty: boolean;
	page: number | undefined;
	setPage: ( next: number ) => void;
} ): void {
	const { hasFetchedOnce, isLoading, fetchError, isEmpty, page, setPage } = options;
	const currentPage = page ?? 1;

	useEffect( () => {
		if (
			hasFetchedOnce &&
			! isLoading &&
			null === fetchError &&
			isEmpty &&
			currentPage > 1
		) {
			setPage( Math.max( 1, currentPage - 1 ) );
		}
	}, [ hasFetchedOnce, isLoading, fetchError, isEmpty, currentPage, setPage ] );
}

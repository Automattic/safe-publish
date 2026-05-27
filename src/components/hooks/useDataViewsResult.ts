/**
 * Custom hook for deriving DataViews data from view state.
 *
 * Wraps @wordpress/dataviews' filterSortAndPaginate in useMemo so the
 * search/sort/pagination result re-derives whenever inputs change. Use this
 * in components that operate DataViews in controlled mode — i.e. those
 * that pass paginationInfo to <DataViews>.
 *
 * @file This file defines the useDataViewsResult hook.
 */

import { filterSortAndPaginate, View } from '@wordpress/dataviews';
import { useMemo } from '@wordpress/element';

import type { DataViewsField } from '../../types';

/**
 * Derives the data slice and pagination info <DataViews> needs from raw
 * data plus the current view state.
 *
 * @template T Item type of the raw data array.
 *
 * @param {T[]}                 data   Raw items to filter/sort/paginate.
 * @param {View}                view   Current DataViews view state.
 * @param {DataViewsField<T>[]} fields Field config.
 *
 * @return Object with `data` (current page slice) and `paginationInfo`.
 */
export function useDataViewsResult< T >(
	data: T[],
	view: View,
	fields: DataViewsField< T >[]
) {
	return useMemo(
		() => filterSortAndPaginate( data, view, fields ),
		// `fields` is recreated each render; getValue/sort must be pure
		// so the memo can safely omit it from deps.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ data, view ]
	);
}

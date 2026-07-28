/**
 * Clears a lifted DataViews selection whenever the listing query changes.
 *
 * @file This file defines the useResetSelectionOnQueryChange hook.
 */

import { useEffect, useRef } from '@wordpress/element';

/**
 * Runs reset once each time queryKey changes, never on mount. Guarding on the
 * key keeps an unstable reset identity from clearing between real changes.
 *
 * @param {string}   queryKey Serialized query inputs; excludes page and sort.
 * @param {Function} reset    Clears the current selection.
 */
export function useResetSelectionOnQueryChange(
	queryKey: string,
	reset: () => void
): void {
	const previousKey = useRef( queryKey );

	useEffect( () => {
		if ( previousKey.current === queryKey ) {
			return;
		}
		previousKey.current = queryKey;
		reset();
	}, [ queryKey, reset ] );
}

/**
 * Defers a boolean to true once its source has been true for a delay.
 *
 * Flips back to `false` immediately when the source drops. Useful for refetch
 * indicators that would otherwise flash and vanish on fast requests.
 *
 * @file This file defines the useDelayedFlag hook.
 */

import { useEffect, useState } from '@wordpress/element';

/**
 * Returns true once the condition has stayed true for the given delay.
 *
 * @param {boolean} condition Source flag to defer.
 * @param {number}  delayMs   Minimum sustained duration before returning true.
 * @return {boolean} The deferred flag.
 */
export function useDelayedFlag( condition: boolean, delayMs: number ): boolean {
	const [ flag, setFlag ] = useState( false );

	useEffect( () => {
		if ( ! condition ) {
			setFlag( false );
			return;
		}
		const timer = setTimeout( () => setFlag( true ), delayMs );
		return () => clearTimeout( timer );
	}, [ condition, delayMs ] );

	return flag;
}

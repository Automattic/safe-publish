/**
 * Fires a refresh callback on cleanup once a gate flag has flipped.
 *
 * Lets modals refresh the parent on any dismiss path (X, Esc, backdrop, Close)
 * rather than wiring `onRefresh` into a single onClick handler.
 *
 * Gate on the attempt, not the outcome, wherever a failure can also leave the
 * listing stale.
 *
 * Requires a stable `onRefresh` and a monotonic flag so the cleanup runs once.
 *
 * @file This file defines the useRefreshOnUnmount hook.
 */

import { useEffect } from '@wordpress/element';

/**
 * Calls the refresh callback on cleanup when the gate flag is true.
 *
 * @param {boolean}  shouldRefresh Whether a refresh is owed on dismissal.
 * @param {Function} onRefresh     Refresh callback; no-op when undefined.
 */
export function useRefreshOnUnmount(
	shouldRefresh: boolean,
	onRefresh: ( () => void ) | undefined
): void {
	useEffect( () => {
		if ( ! shouldRefresh ) {
			return;
		}
		return () => {
			onRefresh?.();
		};
	}, [ shouldRefresh, onRefresh ] );
}

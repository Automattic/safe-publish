/**
 * Fires a refresh callback on cleanup once a success flag has flipped.
 *
 * Lets modals trigger a parent refresh on any dismiss path (X, Esc, backdrop,
 * Close button) rather than wiring `onRefresh` into a single onClick handler.
 *
 * Requires a stable `onRefresh` reference and a monotonic `succeeded` flag
 * so the cleanup runs once, on unmount.
 *
 * @file This file defines the useRefreshOnUnmount hook.
 */

import { useEffect } from '@wordpress/element';

/**
 * Calls the refresh callback on cleanup when the success flag is true.
 *
 * @param {boolean}  succeeded Whether the success condition was reached.
 * @param {Function} onRefresh Refresh callback; no-op when undefined.
 */
export function useRefreshOnUnmount(
	succeeded: boolean,
	onRefresh: ( () => void ) | undefined
): void {
	useEffect( () => {
		if ( ! succeeded ) {
			return;
		}
		return () => {
			onRefresh?.();
		};
	}, [ succeeded, onRefresh ] );
}

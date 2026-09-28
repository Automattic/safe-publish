/**
 * Keeps DataViews row actions reachable on narrow viewports.
 *
 * @file This file defines the useRowActions hook.
 */

import { useViewportMatch } from '@wordpress/compose';

import type { Action } from '@wordpress/dataviews/build-types';

/**
 * Clears isPrimary below the `medium` breakpoint, where DataViews hides
 * inline primary actions but only gives an overflow menu to rows that still
 * have a non-primary one. Demoting all of them puts the full set in the menu.
 *
 * Reads the same viewport hook DataViews does, so both flip together. Drop
 * once the bundled dataviews carries the upstream fix from 11.3.0.
 *
 * @param {Array} actions Row actions to adapt.
 * @return {Array} Actions adapted to the current viewport.
 */
export function useRowActions< Item >(
	actions: Action< Item >[]
): Action< Item >[] {
	const isMobileViewport = useViewportMatch( 'medium', '<' );

	if ( ! isMobileViewport ) {
		return actions;
	}

	return actions.map( ( action ) => ( { ...action, isPrimary: false } ) );
}

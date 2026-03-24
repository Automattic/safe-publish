/**
 * Constants for the Safe Publish plugin.
 *
 * Defines layout types, sorting constants, and other shared values used across
 * the plugin's frontend components.
 *
 * @file This file defines shared constants for the Safe Publish plugin.
 */

/**
 * WordPress dependencies
 */
import { arrowDown, arrowUp } from '@wordpress/icons';

import { __ } from '@wordpress/i18n';

// View layouts.
export const LAYOUT_TABLE = 'table';
export const LAYOUT_GRID = 'grid';
export const LAYOUT_LIST = 'list';

// Posts control defaults and bounds.
export const DEFAULT_POSTS_PER_PAGE = 10;
export const MIN_POSTS_COUNT = 1;
export const MAX_POSTS_COUNT = 100;

// Debounce delay (ms) for the posts-count input.
export const NUMBER_POSTS_DEBOUNCE_DELAY = 600;

// Sorting constants (kept for potential future use).
export const SORTING_DIRECTIONS = [ 'asc', 'desc' ] as const;
export const sortArrows = { asc: '↑', desc: '↓' };
export const sortValues = { asc: 'ascending', desc: 'descending' } as const;
export const sortLabels = {
	asc: __( 'Sort ascending' ),
	desc: __( 'Sort descending' ),
};
export const sortIcons = {
	asc: arrowUp,
	desc: arrowDown,
};

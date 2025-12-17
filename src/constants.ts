/**
 * Constants for the Compliant Content Publisher plugin.
 *
 * Defines layout types, sorting constants, and other shared values used across
 * the plugin's frontend components.
 *
 * @file This file defines shared constants for the CCP plugin.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { arrowDown, arrowUp } from '@wordpress/icons';

// View layouts.
export const LAYOUT_TABLE = 'table';
export const LAYOUT_GRID = 'grid';
export const LAYOUT_LIST = 'list';

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

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
import { __ } from '@wordpress/i18n';
import { arrowDown, arrowUp } from '@wordpress/icons';

// View layouts.
export const LAYOUT_TABLE = 'table';

// Catalog page size; matches the source controller's default.
export const DEFAULT_ITEMS_PER_PAGE = 20;

// Debounce window for the search box. Long enough to avoid firing on
// every keystroke; short enough to feel responsive on URL paste.
export const SEARCH_DEBOUNCE_MS = 400;

// Delay before the "Retrying…" notice appears, so retries that resolve
// quickly clear without flashing a transient banner first.
export const RETRY_PENDING_DELAY_MS = 400;

// Mirrors the server's RETRY_ATTENTION_BATCH_MAX: The most degradations one
// bulk-retry request accepts.
export const RETRY_ATTENTION_BATCH_MAX = 25;

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

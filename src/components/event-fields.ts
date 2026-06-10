/**
 * Pure helpers for rendering ExportEvent columns in DataViews.
 *
 * Centralized so the export-history column config (in ExportHistory)
 * can share the same text between `getValue` (search) and `render`
 * (display), and so the helpers can be unit-tested in isolation from
 * the React tree.
 *
 * @file This file defines ExportEvent column helpers for DataViews.
 */

import { __, sprintf } from '@wordpress/i18n';

import type { ActorSource, ExportEvent } from '../types';

/**
 * Subset of fields any audit-derived event surfaces so callers can render
 * a consistent actor label whether the source is an ExportEvent or a
 * generic AuditEvent.
 */
interface ActorFields {
	actor_user_id: number;
	actor_display_name: string;
	actor_source: ActorSource;
}

/**
 * Returns the display label for the user column.
 *
 * Used by both `getValue` and `render` so search matches displayed text.
 *
 * @param {ActorFields} item Event carrying actor fields.
 *
 * @return {string} Human-readable actor label.
 */
export function getUserLabel( item: ActorFields ): string {
	if ( item.actor_user_id > 0 ) {
		return item.actor_display_name || sprintf(
			/* translators: %d is the WordPress user ID. */
			__( 'User #%d', 'safe-publish' ),
			item.actor_user_id
		);
	}

	return sprintf(
		/* translators: %s is the actor source (e.g. cli, cron) */
		__( 'System (%s)', 'safe-publish' ),
		item.actor_source
	);
}

/**
 * Returns the display label for the destination column.
 *
 * @param {ExportEvent} item Export event.
 *
 * @return {string} Destination URL or fallback label.
 */
export function getDestinationLabel( item: ExportEvent ): string {
	return item.destination_site_url ||
		__( 'Unknown destination', 'safe-publish' );
}

/**
 * Returns the display label for the status column.
 *
 * @param {ExportEvent} item Export event.
 *
 * @return {string} Localized status label.
 */
export function getStatusLabel( item: ExportEvent ): string {
	return 'error' === item.level
		? __( 'Failed', 'safe-publish' )
		: __( 'Exported', 'safe-publish' );
}

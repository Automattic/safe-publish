/**
 * Utility functions for the Safe Publish plugin.
 *
 * Provides helper functions for date formatting, post validation, status
 * labels, warning rendering, and URL manipulation.
 *
 * @file This file defines utility functions for the Safe Publish plugin.
 */

import { dateI18n, getSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { AttentionIssue, JsonValue, Warning } from './types';

/**
 * Extracts a human-readable error message from an API response.
 *
 * @param {Object}    response         The API response object.
 * @param {boolean}   response.success Always false for error responses.
 * @param {JsonValue} [response.data]  Optional error data from WordPress.
 * @param {string}    [response.error] Optional custom error message.
 * @param {string}    [fallback]       Fallback message if no error found.
 *
 * @return {string} The extracted error message.
 */
export function getErrorMessage(
	response: { success: false; data?: JsonValue; error?: string },
	fallback: string = __( 'An unknown error occurred', 'safe-publish' )
): string {
	if ( typeof response.error === 'string' && response.error ) {
		return response.error;
	}

	// Check WordPress wp_send_json_error data field.
	if ( response.data !== undefined && response.data !== null ) {
		if ( typeof response.data === 'string' ) {
			return response.data;
		}

		// If it's an object with a message property, extract that.
		if (
			typeof response.data === 'object' &&
			! Array.isArray( response.data ) &&
			'message' in response.data &&
			typeof response.data.message === 'string'
		) {
			return response.data.message;
		}

		// For other objects/arrays, serialize to JSON.
		try {
			return JSON.stringify( response.data );
		} catch {
			return String( response.data );
		}
	}

	return fallback;
}

/**
 * Formats a date string with time for display.
 *
 * Renders in the site's configured timezone using the WordPress `date_format`
 * and `time_format` options, matching how WP admin displays dates elsewhere.
 *
 * @param {string} dateString UTC ISO 8601 date string (trailing `Z`).
 *
 * @return {string} Formatted date/time string, or 'Invalid Date' on failure.
 */
export function formatDateTime( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Invalid Date', 'safe-publish' );
	}

	const { formats } = getSettings();
	return dateI18n( `${ formats.date } ${ formats.time }`, dateString );
}

/**
 * Compact date/time format for tight admin columns and inline badges.
 *
 * Uses an abbreviated month + day to keep the column tight, and omits the
 * year when it matches the current year (compared in the site's timezone
 * so the year shown and the year compared agree). Time uses the WP
 * `time_format` setting.
 *
 * @param {string} dateString UTC ISO 8601 date string (trailing `Z`).
 *
 * @return {string} Formatted timestamp, or 'Invalid Date' on failure.
 */
export function formatBadgeTimestamp( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Invalid Date', 'safe-publish' );
	}

	const { formats } = getSettings();
	const currentYear = dateI18n( 'Y', new Date().toISOString() );
	const targetYear  = dateI18n( 'Y', dateString );
	const dateFormat  = currentYear === targetYear ? 'M j' : 'M j, Y';

	return dateI18n( `${ dateFormat } ${ formats.time }`, dateString );
}

/**
 * Display labels for publish statuses.
 */
export const PUBLISH_STATUS_LABELS: Record< string, string > = {
	publish: __( 'Published', 'safe-publish' ),
	draft:   __( 'Draft', 'safe-publish' ),
	pending: __( 'Pending Review', 'safe-publish' ),
	private: __( 'Private', 'safe-publish' ),
	future:  __( 'Scheduled', 'safe-publish' ),
};

/**
 * Renders an import warning as a long-form, user-facing message.
 *
 * @param {Warning} warning Warning to render.
 *
 * @return {string} Localized message text.
 */
export function renderWarningMessage( warning: Warning ): string {
	switch ( warning.type ) {
		case 'author_fallback_applied':
			return warning.fallback_user_id !== null
				? sprintf(
					/* translators: 1: display name, 2: email */
					__(
						'Original author %1$s (%2$s) was not found on this site. Post attributed to the current user.',
						'safe-publish'
					),
					warning.source.display_name,
					warning.source.email
				)
				: sprintf(
					/* translators: %s: email */
					__(
						'Source author %s was not found on this site. Keeping the current author.',
						'safe-publish'
					),
					warning.source.email
				);
		case 'parent_orphaned':
			if ( warning.reason === 'failed_in_batch' && warning.source.parent_title !== null ) {
				return sprintf(
					/* translators: 1: parent post title, 2: parent post ID */
					__(
						'Source parent post "%1$s" (ID %2$d) failed to import earlier in this batch. Imported as top-level.',
						'safe-publish'
					),
					warning.source.parent_title,
					warning.source.parent_id
				);
			}
			return sprintf(
				/* translators: %d: parent post ID */
				__(
					'Source parent post (ID %d) is not on this site. Imported as top-level.',
					'safe-publish'
				),
				warning.source.parent_id
			);
		case 'unmapped_block_reference':
			if ( warning.block === 'core/block' ) {
				return sprintf(
					/* translators: %d: source reusable block (wp_block) ID */
					__(
						"Reusable block %d isn't on this site. Import it under Patterns, then re-import this post.",
						'safe-publish'
					),
					warning.source_id
				);
			}
			return warning.kind === 'term'
				? sprintf(
					/* translators: %d: source term ID */
					__(
						"Source term %d isn't on this site. Re-save the nav once it's available.",
						'safe-publish'
					),
					warning.source_id
				)
				: sprintf(
					/* translators: %d: source post ID */
					__(
						"Source post %d isn't on this site. Import it, then re-save the nav.",
						'safe-publish'
					),
					warning.source_id
				);
		case 'nav_ref_rewrite_failed':
			return sprintf(
				/* translators: 1: number of posts, 2: comma-separated post IDs */
				_n(
					'Imported this menu, but %1$d page that references it could not be updated automatically (post ID: %2$s). Re-import the menu to retry.',
					'Imported this menu, but %1$d pages that reference it could not be updated automatically (post IDs: %2$s). Re-import the menu to retry.',
					warning.failed_post_ids.length,
					'safe-publish'
				),
				warning.failed_post_ids.length,
				warning.failed_post_ids.join( ', ' )
			);
		case 'unmapped_shortcode_reference':
			return sprintf(
				/* translators: %d: source attachment ID */
				__(
					"Gallery/playlist media %d isn't on this site. Its shortcode keeps the source ID.",
					'safe-publish'
				),
				warning.source_id
			);
		default: {
			const _exhaustive: never = warning;
			return String( _exhaustive );
		}
	}
}

/**
 * Renders an import warning as a short label for the bulk results modal.
 *
 * Labels are joined inline with commas, so they should be lowercase phrases.
 *
 * @param {Warning} warning Warning to render.
 *
 * @return {string} Localized short label.
 */
export function renderWarningShortLabel( warning: Warning ): string {
	switch ( warning.type ) {
		case 'author_fallback_applied':
			return __( 'author fallback', 'safe-publish' );
		case 'parent_orphaned':
			return __( 'parent orphaned', 'safe-publish' );
		case 'unmapped_block_reference':
			return warning.block === 'core/block'
				? __( 'reusable block reference', 'safe-publish' )
				: __( 'unmapped block reference', 'safe-publish' );
		case 'nav_ref_rewrite_failed':
			return __( 'nav reference update failed', 'safe-publish' );
		case 'unmapped_shortcode_reference':
			return __( 'unmapped shortcode reference', 'safe-publish' );
		default: {
			const _exhaustive: never = warning;
			return String( _exhaustive );
		}
	}
}

/**
 * Renders an open attention issue as a user-facing sentence pointing at the
 * fix: import the referenced content, then Retry.
 *
 * @param {AttentionIssue} issue Issue to render.
 *
 * @return {string} Localized message text.
 */
export function renderIssueMessage( issue: AttentionIssue ): string {
	switch ( issue.issue_type ) {
		case 'unmapped_block_reference':
			if ( issue.target_is_reusable_block ) {
				return sprintf(
					/* translators: %d: source reusable block (wp_block) ID */
					__(
						"Reusable block %d isn't on this site yet. Import it under Patterns, then Retry.",
						'safe-publish'
					),
					issue.target_ref
				);
			}
			return issue.target_kind === 'term'
				? sprintf(
					/* translators: %d: source term ID */
					__(
						"Source term %d isn't on this site yet. Import it, then Retry.",
						'safe-publish'
					),
					issue.target_ref
				)
				: sprintf(
					/* translators: %d: source post ID */
					__(
						"Source post %d isn't on this site yet. Import it, then Retry.",
						'safe-publish'
					),
					issue.target_ref
				);
		case 'parent_orphaned':
			return sprintf(
				/* translators: %d: source parent post ID */
				__(
					"This page's parent (source ID %d) isn't on this site yet. Import it, then Retry.",
					'safe-publish'
				),
				issue.target_ref
			);
		case 'nav_ref_rewrite_failed':
			return sprintf(
				/* translators: %d: source navigation menu ID */
				__(
					"This page's link to menu %d couldn't be updated automatically. Retry to re-attempt it.",
					'safe-publish'
				),
				issue.target_ref
			);
		default: {
			const _exhaustive: never = issue.issue_type;
			return String( _exhaustive );
		}
	}
}

/**
 * Stable client id for an attention issue, matching its full identity key so a
 * post and a term reference sharing a target_ref stay distinct.
 *
 * @param {AttentionIssue} issue Issue row.
 *
 * @return {string} Composite identifier.
 */
export function attentionIssueId( issue: AttentionIssue ): string {
	return [
		issue.affected_post_id,
		issue.issue_type,
		issue.target_ref,
		issue.target_kind,
	].join( ':' );
}

/**
 * Display label for an issue's affected content: its title, or a post-id
 * fallback when the title is empty.
 *
 * @param {AttentionIssue} issue Issue row.
 *
 * @return {string} Human-readable content label.
 */
export function attentionIssueLabel( issue: AttentionIssue ): string {
	return '' !== issue.affected_title
		? issue.affected_title
		: sprintf(
			/* translators: %d: post ID */
			__( '#%d', 'safe-publish' ),
			issue.affected_post_id
		);
}

/**
 * Extracts the path from a URL for display.
 *
 * @param {string} url Full URL to extract the path from.
 *
 * @return {string} Path portion of the URL.
 */
export function extractUrlPath( url: string ): string {
	try {
		const urlObj = new URL( url );
		return urlObj.pathname;
	} catch {
		// If URL parsing fails, try to extract path manually.
		const match = url.match( /https?:\/\/[^/]+(.*)/ );
		return match ? match[1] || '/' : url;
	}
}

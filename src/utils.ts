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

import type { AttentionIssue, JsonValue, LocalState, Warning } from './types';

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
			return fallback;
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
 * Display labels for built-in publish statuses; access via statusLabel().
 */
const PUBLISH_STATUS_LABELS: Record< string, string > = {
	publish: __( 'Published', 'safe-publish' ),
	draft:   __( 'Draft', 'safe-publish' ),
	pending: __( 'Pending Review', 'safe-publish' ),
	private: __( 'Private', 'safe-publish' ),
	future:  __( 'Scheduled', 'safe-publish' ),
};

/**
 * Own-key lookup that ignores inherited Object.prototype members, so a
 * source-controlled key like `__proto__` or `constructor` resolves to
 * undefined instead of a prototype object or function.
 *
 * @param {Record<string, string>} table Lookup table.
 * @param {string}                 key   Candidate key.
 *
 * @return {string|undefined} Mapped value, or undefined when not an own key.
 */
function ownLookup(
	table: Record< string, string >,
	key: string
): string | undefined {
	return Object.prototype.hasOwnProperty.call( table, key )
		// eslint-disable-next-line security/detect-object-injection -- key is an own property, verified by hasOwnProperty above.
		? table[ key ]
		: undefined;
}

/**
 * Human label for a post status: The built-in label, else the slug
 * titlecased (`in-progress` -> `In Progress`), since the destination can't
 * know a custom status's registered label.
 *
 * @param {string} status Post status slug.
 *
 * @return {string} Display label.
 */
export function statusLabel( status: string ): string {
	const builtin = ownLookup( PUBLISH_STATUS_LABELS, status );
	if ( builtin !== undefined ) {
		return builtin;
	}
	return status
		.split( /[-_]/ )
		.filter( ( word ) => word !== '' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
}

/**
 * Badge color modifier per built-in status. Selecting the class from this
 * table of literals keeps a source-provided status out of the className; a
 * status not listed here renders as a neutral badge.
 */
const STATUS_BADGE_MODIFIERS: Record< string, string > = {
	publish: 'safe-publish-status-badge--publish',
	draft:   'safe-publish-status-badge--draft',
	pending: 'safe-publish-status-badge--pending',
	private: 'safe-publish-status-badge--private',
	future:  'safe-publish-status-badge--future',
};

/**
 * Badge color modifier class for a status, or '' when it has no dedicated
 * color (custom statuses render neutral).
 *
 * @param {string} status Post status slug.
 *
 * @return {string} Modifier class name, or empty string.
 */
export function statusBadgeModifier( status: string ): string {
	return ownLookup( STATUS_BADGE_MODIFIERS, status ) ?? '';
}

/**
 * Renders a term the import could not reconcile, pointing at the destination
 * change that unblocks it.
 *
 * @param {string} term   Destination term name.
 * @param {string} reason What blocked the write.
 *
 * @return {string} Localized message text.
 */
function renderTermConflictMessage( term: string, reason: string ): string {
	switch ( reason ) {
		case 'name_taken':
			return sprintf(
				/* translators: %s: destination term name */
				__(
					'Term "%s" kept its current name: another term on this site already uses the source\'s new one. Rename or remove that term, then re-import this post.',
					'safe-publish'
				),
				term
			);
		case 'parent_unresolved':
			return sprintf(
				/* translators: %s: destination term name */
				__(
					'Term "%s" kept its current parent: the parent it moved under on the source is not on this site yet. Re-import this post once it is.',
					'safe-publish'
				),
				term
			);
		case 'parent_loop':
			return sprintf(
				/* translators: %s: destination term name */
				__(
					'Term "%s" kept its current parent: the source moves it under one of its own children. Fix the hierarchy on this site, then re-import this post.',
					'safe-publish'
				),
				term
			);
		default:
			return sprintf(
				/* translators: %s: destination term name */
				__(
					'Term "%s" could not be updated to match the source. Check it on this site, then re-import this post.',
					'safe-publish'
				),
				term
			);
	}
}

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
		case 'unmapped_gallery_reference':
			return sprintf(
				/* translators: %d: source post ID */
				__(
					"Gallery/playlist source post %d isn't on this site. Import it, then retry from Needs attention.",
					'safe-publish'
				),
				warning.source_id
			);
		case 'unregistered_taxonomy':
			return warning.terms.length > 0
				? sprintf(
					/* translators: 1: taxonomy slug, 2: comma-separated term names */
					__(
						'Taxonomy "%1$s" is not registered on this site, so these terms were not attached: %2$s. Register it, then re-import this post.',
						'safe-publish'
					),
					warning.taxonomy,
					warning.terms.join( ', ' )
				)
				: sprintf(
					/* translators: %s: taxonomy slug */
					__(
						'Taxonomy "%s" is not registered on this site, so its terms were not attached. Register it, then re-import this post.',
						'safe-publish'
					),
					warning.taxonomy
				);
		case 'term_field_conflict':
			return renderTermConflictMessage( warning.term, warning.reason );
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
		case 'unmapped_gallery_reference':
			return __( 'unmapped gallery reference', 'safe-publish' );
		case 'unregistered_taxonomy':
			return __( 'unregistered taxonomy', 'safe-publish' );
		case 'term_field_conflict':
			return __( 'term not reconciled', 'safe-publish' );
		default: {
			const _exhaustive: never = warning;
			return String( _exhaustive );
		}
	}
}

/**
 * Renders an open attention issue as a user-facing sentence pointing at the
 * fix: Import the referenced content, then Retry — or, where no import can
 * resolve it, the change the destination site needs.
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
		case 'unmapped_gallery_reference':
			return sprintf(
				/* translators: %d: source post ID */
				__(
					"Gallery/playlist source post %d isn't on this site yet. Import it, then Retry.",
					'safe-publish'
				),
				issue.target_ref
			);
		case 'unregistered_taxonomy':
			return issue.target_terms.length > 0
				? sprintf(
					/* translators: 1: taxonomy slug, 2: comma-separated term names */
					__(
						'Taxonomy "%1$s" is not registered on this site, so these terms were not attached: %2$s. Register it, then re-import this post.',
						'safe-publish'
					),
					issue.target_slug,
					issue.target_terms.join( ', ' )
				)
				: sprintf(
					/* translators: %s: taxonomy slug */
					__(
						'Taxonomy "%s" is not registered on this site, so its terms were not attached. Register it, then re-import this post.',
						'safe-publish'
					),
					issue.target_slug
				);
		case 'term_field_conflict':
			return renderTermConflictMessage(
				issue.target_terms[ 0 ] ?? issue.target_slug,
				issue.target_reason
			);
		default: {
			const _exhaustive: never = issue.issue_type;
			return String( _exhaustive );
		}
	}
}

/**
 * Stable client id for an attention issue, matching its full identity key so a
 * post and a term reference sharing a target_ref — or two slug-keyed issues of
 * one type on one post — stay distinct.
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
		issue.target_slug,
	].join( ':' );
}

/**
 * Display label for an issue's affected content: Its title, or a post-id
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

/**
 * Predicts whether importing a row overwrites an existing destination post
 * rather than creating a draft. Mirrors the server, which resolves the source
 * id to a live post before deciding.
 *
 * @param {LocalState} localState Row's routing state.
 *
 * @return {boolean} True when the import updates an existing post.
 */
export function isImportUpdate( localState: LocalState ): boolean {
	return 'up-to-date' === localState || 'outdated' === localState;
}

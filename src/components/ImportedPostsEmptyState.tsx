/**
 * Empty-state panel for the Imports → Posts tab.
 *
 * Renders the "no imports yet" copy with a primary CTA back to the Source
 * Posts page. When the listing endpoint reports a non-zero failure count, an
 * inline link surfaces the Failures tab so the operator knows where their
 * errored attempts went instead of assuming nothing happened.
 *
 * @file This file defines the ImportedPostsEmptyState component.
 */
import { Button } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Props for the ImportedPostsEmptyState component.
 *
 * @property {string|undefined} sourcePostsUrl URL of the Source Posts admin page; the primary
 *                                             CTA is omitted when unset.
 * @property {number|null}      failedCount    Number of failed imports across all sessions, or
 *                                             null while the listing hasn't returned a count.
 * @property {string}           failuresHref   URL of the Imports → Failures tab.
 */
interface ImportedPostsEmptyStateProps {
	sourcePostsUrl: string | undefined;
	failedCount: number | null;
	failuresHref: string;
}

/**
 * ImportedPostsEmptyState component.
 *
 * @param {ImportedPostsEmptyStateProps} props Component props.
 * @return {JSX.Element} Rendered empty-state panel.
 */
export function ImportedPostsEmptyState( {
	sourcePostsUrl,
	failedCount,
	failuresHref,
}: ImportedPostsEmptyStateProps ): JSX.Element {
	return (
		<div
			className="safe-publish-no-data safe-publish-no-data--cta"
			role="status"
			aria-live="polite"
		>
			<p>{ __( 'No posts have been imported yet.', 'safe-publish' ) }</p>
			{ undefined !== sourcePostsUrl && (
				<Button variant="primary" href={ sourcePostsUrl }>
					{ __( 'Import posts from Source Posts', 'safe-publish' ) }
				</Button>
			) }
			{ null !== failedCount && failedCount > 0 && (
				<p className="safe-publish-no-data__hint">
					{ createInterpolateElement(
						sprintf(
							/* translators: %d: number of failed imports. */
							_n(
								'<link>%d import</link> failed earlier — fix the source and try again.',
								'<link>%d imports</link> failed earlier — fix the source and try again.',
								failedCount,
								'safe-publish'
							),
							failedCount
						),
						{
							// createInterpolateElement swaps "link" for the
							// matching <link> token's inner text at render time;
							// the literal is just a jsx-a11y placeholder.
							link: <a href={ failuresHref }>link</a>,
						}
					) }
				</p>
			) }
		</div>
	);
}

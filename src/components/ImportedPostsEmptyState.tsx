/**
 * Empty-state panel for the Imports → Posts tab.
 *
 * Renders the "no imports yet" copy with a primary CTA back to the Source
 * Posts page.
 *
 * @file This file defines the ImportedPostsEmptyState component.
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Props for the ImportedPostsEmptyState component.
 *
 * @property {string|undefined} sourcePostsUrl URL of the Source Posts admin page; the primary
 *                                             CTA is omitted when unset.
 */
interface ImportedPostsEmptyStateProps {
	sourcePostsUrl: string | undefined;
}

/**
 * ImportedPostsEmptyState component.
 *
 * @param {ImportedPostsEmptyStateProps} props Component props.
 * @return {JSX.Element} Rendered empty-state panel.
 */
export function ImportedPostsEmptyState( {
	sourcePostsUrl,
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
		</div>
	);
}

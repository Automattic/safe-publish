/**
 * ImportsApp — tabbed root component for the Imports admin page.
 *
 * Hosts the Posts and Failures tabs and keeps the active tab in sync with the
 * `?tab=...` URL param so navigation back to the page restores the last view.
 *
 * @file This file defines the ImportsApp component.
 */
import { FailedImportsDataView } from './FailedImportsDataView';
import { ImportedPostsDataView } from './ImportedPostsDataView';
import { TabPanel } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Identifier for the Posts tab.
 */
const TAB_POSTS = 'posts';

/**
 * Identifier for the Failures tab.
 */
const TAB_FAILURES = 'failures';

/**
 * Tab definitions in display order.
 */
const TABS = [
	{ name: TAB_POSTS, title: __( 'Posts', 'safe-publish' ) },
	{ name: TAB_FAILURES, title: __( 'Failures', 'safe-publish' ) },
];

/**
 * ImportsApp component.
 *
 * @return {JSX.Element} Tab panel hosting the Posts and Failures views.
 */
export function ImportsApp(): JSX.Element {
	const initialTab = window.safePublishAdminData.initialTab ?? TAB_POSTS;

	const handleSelect = useCallback( ( tabName: string ): void => {
		const url = new URL( window.location.href );
		if ( TAB_POSTS === tabName ) {
			url.searchParams.delete( 'tab' );
		} else {
			url.searchParams.set( 'tab', tabName );
		}
		window.history.replaceState( null, '', url.toString() );
	}, [] );

	return (
		<TabPanel
			className="safe-publish-imports-tabs"
			tabs={ TABS }
			initialTabName={ initialTab }
			onSelect={ handleSelect }
		>
			{ ( tab ) =>
				TAB_FAILURES === tab.name ? (
					<FailedImportsDataView />
				) : (
					<ImportedPostsDataView />
				)
			}
		</TabPanel>
	);
}

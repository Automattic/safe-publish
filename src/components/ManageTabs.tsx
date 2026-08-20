/**
 * Manage page tabs: The Posts listing and the Needs attention inbox. The inbox
 * tab label shows the live count of failures plus open degradations, kept fresh
 * by both tabs. Deep links open the inbox via ?tab=needs-attention or the
 * legacy ?state=failed.
 *
 * @file This file defines the ManageTabs component.
 */
import { TabPanel } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import NeedsAttentionInbox from './NeedsAttentionInbox';
import { PostsDataView } from './PostsDataView';

const POSTS_TAB = 'posts';
const NEEDS_ATTENTION_TAB = 'needs-attention';

/**
 * Manage page tab container.
 *
 * @param {Object} props               Component props.
 * @param {string} props.sourceSiteUrl Source site URL for the Posts listing.
 * @return {JSX.Element} Rendered tab panel.
 */
export function ManageTabs( {
	sourceSiteUrl,
}: {
	sourceSiteUrl: string;
} ): JSX.Element {
	const [ count, setCount ] = useState(
		window.safePublishAdminData.needsAttentionCount ?? 0
	);

	// Read the deep-linked tab once, before the strip effect runs.
	const [ initialTab ] = useState( (): string => {
		const params = new URLSearchParams( window.location.search );
		return NEEDS_ATTENTION_TAB === params.get( 'tab' )
			|| 'failed' === params.get( 'state' )
			? NEEDS_ATTENTION_TAB
			: POSTS_TAB;
	} );

	// Strip the deep-link params so a reload doesn't re-pin the tab. Only the
	// legacy state=failed is cleared here; PostsDataView owns other ?state=.
	useEffect( () => {
		const url = new URL( window.location.href );
		let changed = false;
		if ( url.searchParams.has( 'tab' ) ) {
			url.searchParams.delete( 'tab' );
			changed = true;
		}
		if ( 'failed' === url.searchParams.get( 'state' ) ) {
			url.searchParams.delete( 'state' );
			changed = true;
		}
		if ( changed ) {
			window.history.replaceState( null, '', url.toString() );
		}
	}, [] );

	const onCountChange = useCallback(
		( next: number ) => setCount( next ),
		[]
	);

	const tabs = [
		{ name: POSTS_TAB, title: __( 'Posts', 'safe-publish' ) },
		{
			name: NEEDS_ATTENTION_TAB,
			title: sprintf(
				/* translators: %d: number of items needing attention */
				__( 'Needs attention (%d)', 'safe-publish' ),
				count
			),
		},
	];

	return (
		<TabPanel
			className="safe-publish-manage-tabs"
			tabs={ tabs }
			initialTabName={ initialTab }
		>
			{ /* Both panels stay mounted and toggle with `hidden` so switching
			tabs preserves the Posts filters, search, selection, and page. */ }
			{ ( tab ) => (
				<>
					<div hidden={ POSTS_TAB !== tab.name }>
						<PostsDataView
							sourceSiteUrl={ sourceSiteUrl }
							onNeedsAttentionCountChange={ onCountChange }
						/>
					</div>
					<div hidden={ NEEDS_ATTENTION_TAB !== tab.name }>
						<NeedsAttentionInbox
							ajaxurl={ window.safePublishAdminData.ajaxurl }
							nonce={ window.safePublishAdminData.nonce }
							onCountChange={ onCountChange }
						/>
					</div>
				</>
			) }
		</TabPanel>
	);
}

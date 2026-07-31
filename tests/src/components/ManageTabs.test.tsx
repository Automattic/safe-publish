/**
 * Tests for the ManageTabs component.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';

import type { AdminData } from '@/types';

// Stub the heavy panels so this focuses on tab wiring: both must stay mounted
// (toggled via `hidden`), and either may drive the count.
let reportPostsCount: ( ( n: number ) => void ) | undefined;
vi.mock( '@/components/PostsDataView', () => ( {
	PostsDataView: ( {
		onNeedsAttentionCountChange,
	}: {
		onNeedsAttentionCountChange?: ( n: number ) => void;
	} ): JSX.Element => {
		reportPostsCount = onNeedsAttentionCountChange;
		return <div>posts-panel</div>;
	},
} ) );
vi.mock( '@/components/NeedsAttentionInbox', () => ( {
	default: (): JSX.Element => <div>inbox-panel</div>,
} ) );

import { ManageTabs } from '@/components/ManageTabs';

beforeEach( () => {
	reportPostsCount = undefined;
	window.safePublishAdminData = {
		ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		sourceSiteUrl: 'https://source.example',
		settingsUrl: '',
		containerId: 'safe-publish-posts-container',
		needsAttentionCount: 0,
	} as AdminData;
} );

afterEach( () => {
	vi.clearAllMocks();
} );

describe( 'ManageTabs', () => {
	it( 'Verifies that both panels stay mounted and toggle with hidden', () => {
		// ARRANGE + ACT: Render the tabs (Posts is the default tab).
		render( <ManageTabs sourceSiteUrl="https://source.example" /> );

		// ASSERT: Both panels are in the DOM; only the inbox is hidden.
		const posts = screen.getByText( 'posts-panel' );
		const inbox = screen.getByText( 'inbox-panel' );
		expect( posts.parentElement ).not.toHaveAttribute( 'hidden' );
		expect( inbox.parentElement ).toHaveAttribute( 'hidden' );

		// ACT: Switch to the Needs attention tab.
		fireEvent.click(
			screen.getByRole( 'tab', { name: /Needs attention/ } )
		);

		// ASSERT: Both remain mounted; the hidden flag flips, not the mount.
		expect( screen.getByText( 'posts-panel' ) ).toBe( posts );
		expect( screen.getByText( 'inbox-panel' ) ).toBe( inbox );
		expect( posts.parentElement ).toHaveAttribute( 'hidden' );
		expect( inbox.parentElement ).not.toHaveAttribute( 'hidden' );
	} );

	it( 'Verifies that a panel-reported count drives the tab label', () => {
		// ARRANGE: Render with a seed count of zero.
		render( <ManageTabs sourceSiteUrl="https://source.example" /> );
		expect(
			screen.getByRole( 'tab', { name: 'Needs attention (0)' } )
		).toBeInTheDocument();

		// ACT: The Posts panel reports a fresh count.
		act( () => reportPostsCount?.( 3 ) );

		// ASSERT: The tab label reflects the reported count.
		expect(
			screen.getByRole( 'tab', { name: 'Needs attention (3)' } )
		).toBeInTheDocument();
	} );
} );

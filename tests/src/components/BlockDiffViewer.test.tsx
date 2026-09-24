/**
 * Tests for the BlockDiffViewer component's changes-only default.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import BlockDiffViewer from '@/components/BlockDiffViewer';
import type { BlockDiff } from '@/api/diff';

const buildBlock = ( overrides: Partial< BlockDiff > ): BlockDiff => ( {
	index: 0,
	status: 'unchanged',
	current: { name: 'core/paragraph', rendered: '<p>Same body.</p>' },
	incoming: { name: 'core/paragraph', rendered: '<p>Same body.</p>' },
	...overrides,
} );

describe( 'BlockDiffViewer', () => {
	it( 'omits unchanged blocks by default and shows an empty-state line', () => {
		// ARRANGE: All blocks are unchanged.
		const blocks: BlockDiff[] = [
			buildBlock( { index: 0 } ),
			buildBlock( { index: 1 } ),
		];

		// ACT: Render with the default (showUnchanged off).
		render( <BlockDiffViewer blocks={ blocks } /> );

		// ASSERT: The empty-state line is the only signal; no block cards.
		expect(
			screen.getByText( /no block changes detected/i )
		).toBeInTheDocument();
		expect( screen.queryByText( 'core/paragraph' ) ).toBeNull();
	} );

	it( 'renders unchanged blocks when showUnchanged is enabled', () => {
		// ARRANGE: One unchanged block.
		const blocks: BlockDiff[] = [ buildBlock( { index: 0 } ) ];

		// ACT: Reveal unchanged content.
		render( <BlockDiffViewer blocks={ blocks } showUnchanged /> );

		// ASSERT: The block card surfaces with the block name and unchanged badge.
		expect( screen.getByText( 'core/paragraph' ) ).toBeInTheDocument();
		expect( screen.getByText( 'unchanged' ) ).toBeInTheDocument();
	} );

	it( 'always renders modified blocks regardless of the toggle', () => {
		// ARRANGE: One modified block with a meaningful diff.
		const blocks: BlockDiff[] = [
			buildBlock( {
				status: 'modified',
				current: { name: 'core/paragraph', rendered: '<p>Old body.</p>' },
				incoming: { name: 'core/paragraph', rendered: '<p>New body.</p>' },
			} ),
		];

		// ACT: Render with the default (showUnchanged off).
		render( <BlockDiffViewer blocks={ blocks } /> );

		// ASSERT: The modified card is visible — the changes-only filter only
		// strips unchanged entries.
		expect( screen.getByText( 'modified' ) ).toBeInTheDocument();
	} );

	it( 'highlights punctuation without corrupting the block markup', () => {
		// ARRANGE: One modified block with a punctuation-only change that diff
		// version 5 incorrectly grouped with the closing tag.
		const blocks: BlockDiff[] = [
			buildBlock( {
				status: 'modified',
				current: {
					name: 'core/paragraph',
					rendered: '<p>Publish now.</p>',
				},
				incoming: {
					name: 'core/paragraph',
					rendered: '<p>Publish now!</p>',
				},
			} ),
		];

		// ACT: Render the inline diff.
		const { container } = render( <BlockDiffViewer blocks={ blocks } /> );

		// ASSERT: Only the punctuation is marked, and the paragraph remains
		// well-formed after the highlighted HTML is parsed.
		const modifiedColumns = container.querySelectorAll(
			'.safe-publish-block-diff__col'
		);
		const highlightedParagraph = modifiedColumns[ 1 ].querySelector( 'p' );
		expect( highlightedParagraph ).toHaveTextContent( 'Publish now.!' );
		expect(
			highlightedParagraph?.querySelector(
				'.safe-publish-inline-removed'
			)
		).toHaveTextContent( '.' );
		expect(
			highlightedParagraph?.querySelector( '.safe-publish-inline-added' )
		).toHaveTextContent( '!' );
	} );

	it( 'renders added and removed blocks from the side that carries them', () => {
		// ARRANGE: One block dropped by the incoming content and one added.
		const blocks: BlockDiff[] = [
			buildBlock( {
				index: 0,
				status: 'removed',
				current: { name: 'core/paragraph', rendered: '<p>Dropped.</p>' },
				incoming: null,
			} ),
			buildBlock( {
				index: 1,
				status: 'added',
				current: null,
				incoming: { name: 'core/paragraph', rendered: '<p>Fresh.</p>' },
			} ),
		];

		// ACT: Render with the default (showUnchanged off).
		const { container } = render( <BlockDiffViewer blocks={ blocks } /> );

		// ASSERT: Each card reads from the side that holds the block.
		expect(
			container.querySelector( '.safe-publish-block-diff__removed' )
		).toHaveTextContent( 'Dropped.' );
		expect(
			container.querySelector( '.safe-publish-block-diff__added' )
		).toHaveTextContent( 'Fresh.' );
	} );

	it( 'notes that a modified block with identical previews cannot be previewed', () => {
		// ARRANGE: A modified block whose previews match because the change
		// lives in markup the server filtered out of both.
		const blocks: BlockDiff[] = [
			buildBlock( {
				status: 'modified',
				current: { name: 'core/html', rendered: '' },
				incoming: { name: 'core/html', rendered: '' },
			} ),
		];

		// ACT: Render with the default (showUnchanged off).
		render( <BlockDiffViewer blocks={ blocks } /> );

		// ASSERT: The card stays visible and explains why it shows nothing,
		// rather than being dropped as unchanged.
		expect( screen.getByText( 'modified' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /not visible in the preview/i )
		).toBeInTheDocument();
	} );
} );

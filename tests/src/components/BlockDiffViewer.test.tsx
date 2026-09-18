/**
 * Tests for the BlockDiffViewer component's changes-only default and its
 * inline diff markers.
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

const renderModified = (
	currentHtml: string,
	incomingHtml: string,
	name = 'core/paragraph',
	showLabels = true
) =>
	render(
		<BlockDiffViewer
			showLabels={ showLabels }
			blocks={ [
				buildBlock( {
					status: 'modified',
					current: { name, rendered: currentHtml },
					incoming: { name, rendered: incomingHtml },
				} ),
			] }
		/>
	);

const columns = ( container: HTMLElement ): HTMLElement[] =>
	Array.from(
		container.querySelectorAll< HTMLElement >(
			'.safe-publish-block-diff__col'
		)
	);

const incomingColumn = ( container: HTMLElement ): HTMLElement =>
	columns( container )[ 1 ];

const MARKER_SELECTOR = [
	'.safe-publish-inline-added',
	'.safe-publish-inline-removed',
	'.safe-publish-inline-attr-changed',
].join( ', ' );

const markerCount = ( column: HTMLElement ): number =>
	column.querySelectorAll( MARKER_SELECTOR ).length;

const badge = (): HTMLElement | null =>
	screen.queryByText( /changed \(no inline marker\)/i );

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
		const highlightedParagraph =
			incomingColumn( container ).querySelector( 'p' );
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

	it( 'keeps an anchor href intact when the link target changes', () => {
		// ARRANGE: One modified block whose only change is the link target,
		// the case that used to splice a span inside the href value.
		const { container } = renderModified(
			'<p><a href="https://example.com/old">Read this</a></p>',
			'<p><a href="https://example.com/new">Read this</a></p>'
		);

		// ACT: Read the anchor the incoming column renders.
		const link = incomingColumn( container ).querySelector( 'a' );

		// ASSERT: The href is exactly what will be imported, the link text
		// survives, and the element carries the attribute-change marker.
		expect( link?.getAttribute( 'href' ) ).toBe(
			'https://example.com/new'
		);
		expect( link?.textContent ).toBe( 'Read this' );
		expect( link ).toHaveClass( 'safe-publish-inline-attr-changed' );
		expect( link?.querySelector( 'span' ) ).toBeNull();
	} );

	it( 'keeps the href intact when the link text changes with it', () => {
		// ARRANGE: One modified block that edits both the target and the
		// anchor text, which is the common real-world link edit.
		const { container } = renderModified(
			'<p><a href="https://example.com/old">Read the old post</a></p>',
			'<p><a href="https://example.com/new">Read the new post</a></p>'
		);

		// ACT: Read the anchor the incoming column renders.
		const link = incomingColumn( container ).querySelector( 'a' );

		// ASSERT: The target is untouched markup, and the word change is
		// marked inside the anchor rather than around it.
		expect( link?.getAttribute( 'href' ) ).toBe(
			'https://example.com/new'
		);
		expect( link ).toHaveClass( 'safe-publish-inline-attr-changed' );
		expect(
			link?.querySelector( '.safe-publish-inline-added' )
		).toHaveTextContent( 'new' );
		expect(
			link?.querySelector( '.safe-publish-inline-removed' )
		).toHaveTextContent( 'old' );
	} );

	it( 'marks an attribute-only change on the element that changed', () => {
		// ARRANGE: One modified block whose text is identical on both sides.
		const { container } = renderModified(
			'<p class="has-text-align-left">Body copy.</p>',
			'<p class="has-text-align-right">Body copy.</p>'
		);

		// ACT: Read the paragraph the incoming column renders.
		const paragraph = incomingColumn( container ).querySelector( 'p' );

		// ASSERT: The incoming class is kept, the marker rides along with
		// it, and no word-level marker is invented for unchanged text.
		expect( paragraph ).toHaveClass( 'has-text-align-right' );
		expect( paragraph ).toHaveClass( 'safe-publish-inline-attr-changed' );
		expect( paragraph?.textContent ).toBe( 'Body copy.' );
		expect(
			incomingColumn( container ).querySelector(
				'.safe-publish-inline-added, .safe-publish-inline-removed'
			)
		).toBeNull();
	} );

	it( 'leaves the current column free of markers', () => {
		// ARRANGE: One modified block with both a word and an attribute edit.
		const { container } = renderModified(
			'<p class="a"><a href="https://example.com/old">Old body.</a></p>',
			'<p class="b"><a href="https://example.com/new">New body.</a></p>'
		);

		// ACT: Read both columns.
		const [ current, incoming ] = columns( container );

		// ASSERT: Only the incoming column is marked, so the left side still
		// shows the destination exactly as it stands today.
		expect( markerCount( incoming ) ).toBeGreaterThan( 0 );
		expect( markerCount( current ) ).toBe( 0 );
		expect( current.querySelector( 'a' )?.getAttribute( 'href' ) ).toBe(
			'https://example.com/old'
		);
	} );

	it( 'marks a deleted list item as a list item', () => {
		// ARRANGE: One modified list block that loses its second item.
		const { container } = renderModified(
			'<ul class="wp-block-list"><li>First</li><li>Second</li></ul>',
			'<ul class="wp-block-list"><li>First</li></ul>',
			'core/list'
		);

		// ACT: Read the list the incoming column renders.
		const list = incomingColumn( container ).querySelector( 'ul' );

		// ASSERT: The deletion is a struck-through list item, so every child
		// of the list is still a list item.
		const removed = list?.querySelector(
			':scope > li.safe-publish-inline-removed'
		);
		expect( removed?.textContent ).toBe( 'Second' );
		expect( list?.querySelectorAll( ':scope > li' ) ).toHaveLength( 2 );
		expect( list?.querySelector( ':scope > span' ) ).toBeNull();
	} );

	it( 'keeps a cell edit marked inside the cell it belongs to', () => {
		// ARRANGE: One modified table block with an edited cell. Rows are
		// separated by whitespace, as rendered table markup is.
		const { container } = renderModified(
			'<table><tbody><tr><td>Old</td></tr>\n' +
				'<tr><td>Keep</td></tr></tbody></table>',
			'<table><tbody><tr><td>New</td></tr>\n' +
				'<tr><td>Keep</td></tr></tbody></table>',
			'core/table'
		);

		// ACT: Read the table the incoming column renders.
		const table = incomingColumn( container ).querySelector( 'table' );

		// ASSERT: Both markers sit in the edited cell, and nothing was
		// fostered out of the table by the parser.
		const cell = table?.querySelector( 'td' );
		expect(
			cell?.querySelector( '.safe-publish-inline-added' )
		).toHaveTextContent( 'New' );
		expect(
			cell?.querySelector( '.safe-publish-inline-removed' )
		).toHaveTextContent( 'Old' );
		expect(
			incomingColumn( container ).querySelector( ':scope > span' )
		).toBeNull();
		expect( table?.querySelectorAll( 'tr' ) ).toHaveLength( 2 );
	} );

	it( 'marks a deleted table row as a row', () => {
		// ARRANGE: One modified table block that loses its second row.
		const { container } = renderModified(
			'<table><tbody><tr><td>Keep</td></tr>\n' +
				'<tr><td>Drop</td></tr></tbody></table>',
			'<table><tbody><tr><td>Keep</td></tr>\n</tbody></table>',
			'core/table'
		);

		// ACT: Read the table body the incoming column renders.
		const body = incomingColumn( container ).querySelector( 'tbody' );

		// ASSERT: The deletion stays a row inside the table body.
		const removed = body?.querySelector(
			':scope > tr.safe-publish-inline-removed'
		);
		expect( removed?.textContent ).toBe( 'Drop' );
		expect( body?.querySelectorAll( ':scope > tr' ) ).toHaveLength( 2 );
		expect(
			incomingColumn( container ).querySelector( ':scope > span' )
		).toBeNull();
	} );

	it( 'marks a paragraph emptied of its text', () => {
		// ARRANGE: One modified block whose whole text is deleted.
		const { container } = renderModified( '<p>Hello world</p>', '<p></p>' );

		// ACT: Read the paragraph the incoming column renders.
		const paragraph = incomingColumn( container ).querySelector( 'p' );

		// ASSERT: The deleted text is marked inside the paragraph it left.
		expect(
			paragraph?.querySelector( '.safe-publish-inline-removed' )
		).toHaveTextContent( 'Hello world' );
		expect( badge() ).toBeNull();
	} );

	it( 'marks text replaced by whitespace, but not the whitespace', () => {
		// ARRANGE: One modified block whose text is replaced by a space.
		const { container } = renderModified(
			'<p>Hello world</p>',
			'<p> </p>'
		);

		// ACT: Read the paragraph the incoming column renders.
		const paragraph = incomingColumn( container ).querySelector( 'p' );

		// ASSERT: The deleted words are marked and the space that replaced
		// them is left plain, since whitespace shows no state of its own.
		expect(
			paragraph?.querySelector( '.safe-publish-inline-removed' )
		).toHaveTextContent( 'Hello world' );
		expect(
			paragraph?.querySelector( '.safe-publish-inline-added' )
		).toBeNull();
	} );

	it( 'marks a block that changed its tag on both sides', () => {
		// ARRANGE: One modified block promoted from paragraph to heading.
		const { container } = renderModified(
			'<p>Section title</p>',
			'<h2>Section title</h2>',
			'core/heading'
		);

		// ACT: Read the incoming column.
		const column = incomingColumn( container );

		// ASSERT: The old tag is marked as removed and the new tag as added.
		expect( column.querySelector( 'p' ) ).toHaveClass(
			'safe-publish-inline-removed'
		);
		expect( column.querySelector( 'h2' ) ).toHaveClass(
			'safe-publish-inline-added'
		);
	} );

	it( 'leaves an ordinary text edit free of the unmarked badge', () => {
		// ARRANGE: A plain word change, which the walk marks in place.
		const current = '<p>Old body.</p>';
		const incoming = '<p>New body.</p>';

		// ACT: Render the inline diff.
		renderModified( current, incoming );

		// ASSERT: The word markers carry the signal on their own, so the
		// card does not claim the change went unmarked.
		expect( screen.getByText( 'Old' ) ).toHaveClass(
			'safe-publish-inline-removed'
		);
		expect( badge() ).toBeNull();
	} );

	it( 'draws no marker and no badge for a whitespace-only difference', () => {
		// ARRANGE: The same list, pretty-printed on one side and compact on
		// the other. Whitespace between tags does not survive rendering.
		const { container } = renderModified(
			'<ul>\n<li>First</li>\n<li>Second</li>\n</ul>',
			'<ul><li>First</li><li>Second</li></ul>',
			'core/list'
		);

		// ACT: Read the incoming column.
		const column = incomingColumn( container );

		// ASSERT: Nothing is marked and nothing is claimed — the preview does
		// not invent a change out of layout.
		expect( markerCount( column ) ).toBe( 0 );
		expect( badge() ).toBeNull();
		expect( column ).not.toHaveClass(
			'safe-publish-block-diff__col--unmarked'
		);
	} );

	it( 'marks an attribute reorder on the element that carries it', () => {
		// ARRANGE: One modified block whose attributes only swapped places.
		// The bytes the import stores change, so the block is not unchanged.
		const { container } = renderModified(
			'<p class="lead" id="intro">Body copy.</p>',
			'<p id="intro" class="lead">Body copy.</p>'
		);

		// ACT: Read the paragraph the incoming column renders.
		const paragraph = incomingColumn( container ).querySelector( 'p' );

		// ASSERT: The incoming attribute order is preserved and the element
		// is marked, so the card never reads as modified with nothing shown.
		expect( paragraph?.getAttributeNames().slice( 0, 2 ) ).toStrictEqual( [
			'id',
			'class',
		] );
		expect( paragraph ).toHaveClass( 'safe-publish-inline-attr-changed' );
		expect( badge() ).toBeNull();
	} );

	it( 'reports a source-syntax change that the preview cannot show', () => {
		// ARRANGE: One modified block whose only change is how an ampersand
		// is written. Both sides parse to the same character, so no marker
		// has anywhere to go.
		const { container } = renderModified(
			'<p>Terms &amp; conditions</p>',
			'<p>Terms &#38; conditions</p>'
		);

		// ACT: Read the incoming column.
		const column = incomingColumn( container );

		// ASSERT: The card says the block changed rather than showing two
		// identical columns with no explanation.
		expect( markerCount( column ) ).toBe( 0 );
		expect( badge() ).toBeInTheDocument();
		expect( column ).toHaveClass(
			'safe-publish-block-diff__col--unmarked'
		);
		expect( column.textContent ).toBe( 'Terms & conditions' );
	} );

	it( 'reports a change it cannot mark instead of showing nothing', () => {
		// ARRANGE: One modified block whose only change is in a comment,
		// which has no visible position to carry a marker.
		const { container } = renderModified(
			'<p>Body copy.<!-- note: one --></p>',
			'<p>Body copy.<!-- note: two --></p>'
		);

		// ACT: Read the incoming column.
		const column = incomingColumn( container );

		// ASSERT: The card says the block changed and the column is flagged,
		// so the change is never presented as no change at all.
		expect( badge() ).toBeInTheDocument();
		expect( column ).toHaveClass(
			'safe-publish-block-diff__col--unmarked'
		);
		expect( column.textContent ).toBe( 'Body copy.' );
	} );

	it( 'shows the unmarked badge even when labels are turned off', () => {
		// ARRANGE: A change with no inline marker, rendered with the compare
		// modal's label toggle off.
		const { container } = renderModified(
			'<p>Body copy.<!-- note: one --></p>',
			'<p>Body copy.<!-- note: two --></p>',
			'core/paragraph',
			false
		);

		// ACT: Read the card.
		const column = incomingColumn( container );

		// ASSERT: The block name and status are gone, but the block does not
		// lose its only remaining signal with them.
		expect( screen.queryByText( 'core/paragraph' ) ).toBeNull();
		expect( screen.queryByText( 'modified' ) ).toBeNull();
		expect( badge() ).toBeInTheDocument();
		expect( column ).toHaveClass(
			'safe-publish-block-diff__col--unmarked'
		);
	} );

	it( 'copies field content instead of marking inside it', () => {
		// ARRANGE: One modified block whose only change is inside a textarea.
		// A marker spliced into raw text would become part of the value.
		const { container } = renderModified(
			'<div><textarea>old note</textarea><p>Body copy.</p></div>',
			'<div><textarea>new note</textarea><p>Body copy.</p></div>',
			'core/html'
		);

		// ACT: Read the field the incoming column renders.
		const field = incomingColumn( container ).querySelector( 'textarea' );

		// ASSERT: The value is exactly the incoming one, and the card reports
		// the change it could not mark.
		expect( field?.innerHTML ).toBe( 'new note' );
		expect( badge() ).toBeInTheDocument();
	} );

	it( 'reports an added field it cannot mark', () => {
		// ARRANGE: One modified block that gains a textarea, whose rendering
		// would not show a marker class.
		const { container } = renderModified(
			'<div><p>Body copy.</p></div>',
			'<div><textarea>note</textarea><p>Body copy.</p></div>',
			'core/html'
		);

		// ACT: Read the field the incoming column renders.
		const field = incomingColumn( container ).querySelector( 'textarea' );

		// ASSERT: The value arrives untouched and the card reports the change
		// rather than leaving the block looking unchanged.
		expect( field?.innerHTML ).toBe( 'note' );
		expect( field ).not.toHaveClass( 'safe-publish-inline-added' );
		expect( badge() ).toBeInTheDocument();
	} );

	it( 'drops a removed field rather than showing it as incoming', () => {
		// ARRANGE: One modified block that loses a textarea. It cannot carry
		// a struck-through marker, so copying it would misread as arriving.
		const { container } = renderModified(
			'<div><textarea>note</textarea><p>Body copy.</p></div>',
			'<div><p>Body copy.</p></div>',
			'core/html'
		);

		// ACT: Read the incoming column.
		const column = incomingColumn( container );

		// ASSERT: The departing field is absent from the incoming preview and
		// the card reports the change instead.
		expect( column.querySelector( 'textarea' ) ).toBeNull();
		expect( column.innerHTML ).toBe( '<div><p>Body copy.</p></div>' );
		expect( badge() ).toBeInTheDocument();
	} );

	it( 'exercises the table-scope guard against a misplaced marker', () => {
		// ARRANGE: Text held directly by a table. This covers the table-scope
		// guard: happy-dom keeps the text inside the table, where a browser
		// parser moves it out, and a marker placed there would be relocated
		// when the preview markup is parsed again.
		const { container } = renderModified(
			'<table>old stray<tbody><tr><td>Cell</td></tr></tbody></table>',
			'<table>new stray<tbody><tr><td>Cell</td></tr></tbody></table>',
			'core/table'
		);

		// ACT: Read the incoming column.
		const column = incomingColumn( container );

		// ASSERT: The incoming text is kept whole, no marker was placed where
		// the parser would move it, and the card reports the change.
		expect( column.textContent ).toContain( 'new stray' );
		expect( column.querySelector( 'span' ) ).toBeNull();
		expect( badge() ).toBeInTheDocument();
	} );
} );
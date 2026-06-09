/**
 * Tests for the NonContentDiffSections changes-only default.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import NonContentDiffSections from '@/components/NonContentDiffSections';

describe( 'NonContentDiffSections', () => {
	it( 'hides empty sections by default and renders only the ones with changes', () => {
		// ARRANGE: Title has a diff; all other sections are empty.
		const nonContentDiffs = {
			title: '<table>title diff</table>',
			excerpt: '',
			taxonomies: '',
			meta: '',
			featuredMedia: '',
		};

		// ACT: Render with the default (showUnchanged off).
		render( <NonContentDiffSections nonContentDiffs={ nonContentDiffs } /> );

		// ASSERT: Only the Title section appears.
		expect( screen.getByRole( 'heading', { name: 'Title' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'Excerpt' } ) ).toBeNull();
		expect( screen.queryByRole( 'heading', { name: 'Taxonomies' } ) ).toBeNull();
		expect( screen.queryByRole( 'heading', { name: 'Meta / Custom Fields' } ) ).toBeNull();
		expect( screen.queryByRole( 'heading', { name: 'Featured Image' } ) ).toBeNull();
	} );

	it( 'returns null when every section is empty', () => {
		// ARRANGE: All sections empty.
		const nonContentDiffs = {
			title: '',
			excerpt: '',
			taxonomies: '',
			meta: '',
			featuredMedia: '',
		};

		// ACT: Render with the default (showUnchanged off).
		const { container } = render(
			<NonContentDiffSections nonContentDiffs={ nonContentDiffs } />
		);

		// ASSERT: Nothing rendered — the parent decides the empty-state copy.
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders placeholders for empty sections when showUnchanged is on', () => {
		// ARRANGE: Only the title differs; the rest are empty.
		const nonContentDiffs = {
			title: '<table>title diff</table>',
			excerpt: '',
			taxonomies: '',
			meta: '',
			featuredMedia: '',
		};

		// ACT: Reveal unchanged sections.
		render(
			<NonContentDiffSections
				nonContentDiffs={ nonContentDiffs }
				showUnchanged
			/>
		);

		// ASSERT: Empty sections render their "No X changes detected." placeholder.
		expect( screen.getByText( /no excerpt changes detected/i ) ).toBeInTheDocument();
		expect( screen.getByText( /no taxonomy changes detected/i ) ).toBeInTheDocument();
		expect( screen.getByText( /no meta changes detected/i ) ).toBeInTheDocument();
		expect(
			screen.getByText( /no featured image changes detected/i )
		).toBeInTheDocument();
	} );
} );

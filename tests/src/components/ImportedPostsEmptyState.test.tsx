/**
 * Tests for the Imports → Posts tab empty-state panel.
 *
 * The CTA is the only signal on this surface when nothing has been imported
 * yet, so each branch (CTA shown/hidden) gets a focused render-level check.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { ImportedPostsEmptyState } from '@/components/ImportedPostsEmptyState';

const SOURCE_POSTS_URL = 'https://example.com/wp-admin/admin.php?page=safe-publish';

describe( 'ImportedPostsEmptyState', () => {
	it( 'renders the CTA button when a Source Posts URL is provided', () => {
		// ARRANGE + ACT: Render with a valid Source Posts URL.
		render( <ImportedPostsEmptyState sourcePostsUrl={ SOURCE_POSTS_URL } /> );

		// ASSERT: The Source Posts CTA links back to the admin page.
		const cta = screen.getByRole( 'link', {
			name: /import posts from source posts/i,
		} );
		expect( cta ).toHaveAttribute( 'href', SOURCE_POSTS_URL );
	} );

	it( 'omits the CTA button when no Source Posts URL is available', () => {
		// ARRANGE + ACT: Render without the URL prop.
		render( <ImportedPostsEmptyState sourcePostsUrl={ undefined } /> );

		// ASSERT: No CTA renders — guards against an unconfigured admin link.
		expect(
			screen.queryByRole( 'link', {
				name: /import posts from source posts/i,
			} )
		).toBeNull();
	} );
} );

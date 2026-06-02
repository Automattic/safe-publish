/**
 * Tests for the Imports → Posts tab empty-state panel.
 *
 * The CTA + failures hint are the only signals on this surface when nothing
 * has been imported yet, so each branch (CTA shown/hidden, hint shown/hidden,
 * pluralization) gets a focused render-level check.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { ImportedPostsEmptyState } from '@/components/ImportedPostsEmptyState';

const SOURCE_POSTS_URL = 'https://example.com/wp-admin/admin.php?page=safe-publish';
const FAILURES_HREF =
	'https://example.com/wp-admin/admin.php?page=safe-publish-imports&tab=failures';

describe( 'ImportedPostsEmptyState', () => {
	it( 'renders the CTA button when a Source Posts URL is provided', () => {
		// ARRANGE + ACT: Render with both URL and zero-failure baseline.
		render(
			<ImportedPostsEmptyState
				sourcePostsUrl={ SOURCE_POSTS_URL }
				failedCount={ 0 }
				failuresHref={ FAILURES_HREF }
			/>
		);

		// ASSERT: The Source Posts CTA is the only link rendered, since no
		// failures hint is shown when failedCount is zero.
		const cta = screen.getByRole( 'link', {
			name: /import posts from source posts/i,
		} );
		expect( cta ).toHaveAttribute( 'href', SOURCE_POSTS_URL );
		expect(
			screen.queryByRole( 'link', { name: /^\d+ imports?$/i } )
		).toBeNull();
	} );

	it( 'omits the CTA button when no Source Posts URL is available', () => {
		// ARRANGE + ACT: Same baseline minus the URL prop.
		render(
			<ImportedPostsEmptyState
				sourcePostsUrl={ undefined }
				failedCount={ 0 }
				failuresHref={ FAILURES_HREF }
			/>
		);

		// ASSERT: No CTA renders — guards against an unconfigured admin link.
		expect(
			screen.queryByRole( 'link', {
				name: /import posts from source posts/i,
			} )
		).toBeNull();
	} );

	it( 'links the singular label to the Failures tab when one has failed', () => {
		// ARRANGE + ACT: Single failure should pluralize as "1 import".
		render(
			<ImportedPostsEmptyState
				sourcePostsUrl={ SOURCE_POSTS_URL }
				failedCount={ 1 }
				failuresHref={ FAILURES_HREF }
			/>
		);

		// ASSERT: Hint links to the Failures tab; label respects pluralization.
		const hint = screen.getByRole( 'link', { name: /1 import/i } );
		expect( hint ).toHaveAttribute( 'href', FAILURES_HREF );
		expect( hint.textContent ).toBe( '1 import' );
	} );

	it( 'pluralizes the failures hint when more than one has failed', () => {
		// ARRANGE + ACT: Multiple failures should pluralize as "N imports".
		render(
			<ImportedPostsEmptyState
				sourcePostsUrl={ SOURCE_POSTS_URL }
				failedCount={ 5 }
				failuresHref={ FAILURES_HREF }
			/>
		);

		// ASSERT: Plural label and Failures-tab link.
		const hint = screen.getByRole( 'link', { name: /5 imports/i } );
		expect( hint.textContent ).toBe( '5 imports' );
	} );

	it( 'omits the hint while the failure count is still unknown', () => {
		// ARRANGE + ACT: null failedCount represents an in-flight first load.
		render(
			<ImportedPostsEmptyState
				sourcePostsUrl={ SOURCE_POSTS_URL }
				failedCount={ null }
				failuresHref={ FAILURES_HREF }
			/>
		);

		// ASSERT: No hint shown until the listing endpoint reports a count.
		expect(
			screen.queryByRole( 'link', { name: /^\d+ imports?$/i } )
		).toBeNull();
	} );
} );

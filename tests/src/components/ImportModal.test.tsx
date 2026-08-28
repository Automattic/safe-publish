/**
 * Tests for the ImportModal. An import that lands as a draft has no
 * confirmation step and starts on mount; one that overwrites a published post
 * waits for an explicit confirmation.
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { StrictMode } from '@wordpress/element';

import ImportModal from '@/components/ImportModal';

const BASE_PROPS = {
	sourcePostId: 1,
	title: 'A Post',
	sourceLink: 'https://example.com/a-post',
	postType: 'post',
	isUpdate: false,
	isLive: false,
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

const LIVE_PROPS = { ...BASE_PROPS, isUpdate: true, isLive: true };

/**
 * Stubs fetch with a single JSON response body.
 *
 * @param {Record<string, unknown>} body Response envelope to resolve with.
 */
function stubFetch( body: Record< string, unknown > ): void {
	vi.stubGlobal(
		'fetch',
		vi.fn().mockResolvedValue(
			new Response( JSON.stringify( body ), { status: 200 } )
		)
	);
}

describe( 'ImportModal', () => {
	beforeEach( () => {
		stubFetch( {
			success: true,
			data: { edit_url: 'https://example.com/edit' },
		} );
	} );

	afterEach( () => {
		vi.unstubAllGlobals();
		vi.restoreAllMocks();
	} );

	it( 'Verifies that a draft import starts on mount with no confirmation', async () => {
		// ARRANGE + ACT: Render for a row with no published destination post.
		render( <ImportModal { ...BASE_PROPS } /> );

		// ASSERT: The import ran unprompted and reports its outcome.
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );
		expect(
			await screen.findByText( '"A Post" has been imported as a draft.' )
		).toBeInTheDocument();
	} );

	it( 'Verifies that a re-render does not start a second import', async () => {
		// ARRANGE: A mounted draft import that has already fired.
		const { rerender } = render( <ImportModal { ...BASE_PROPS } /> );
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );

		// ACT: Re-render with a changed prop, so the mount effect re-evaluates.
		rerender( <ImportModal { ...BASE_PROPS } skippedCount={ 2 } /> );

		// ASSERT: Still one request; the submit is guarded, not repeated.
		expect( fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a successful draft import refreshes only on dismiss', async () => {
		// ARRANGE: A successful auto-submitted draft import.
		const onRefresh = vi.fn();
		const { unmount } = render(
			<ImportModal { ...BASE_PROPS } onRefresh={ onRefresh } />
		);

		// ACT: Let the successful attempt settle.
		await screen.findByText( '"A Post" has been imported as a draft.' );

		// ASSERT: Completion does not refresh while the result remains open.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the result.
		unmount();

		// ASSERT: Dismissal refreshes the listing once.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a double-invoked mount effect starts only one import', async () => {
		// ARRANGE + ACT: StrictMode runs mount effects twice. The app does not
		// enable it today, so this pins the ref guard rather than the dep list:
		// a second submit would import the post and its media twice.
		render(
			<StrictMode>
				<ImportModal { ...BASE_PROPS } />
			</StrictMode>
		);

		// ASSERT: One request, despite the repeated effect.
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );
	} );

	it( 'Verifies that overwriting a published post is confirmed before it runs', () => {
		// ARRANGE + ACT: Render for a row whose destination post is published.
		render( <ImportModal { ...LIVE_PROPS } /> );

		// ASSERT: The consequence leads the copy, the button carries the
		// warning, and nothing was submitted.
		expect(
			screen.getByText(
				'"A Post" is live — this update publishes immediately'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( /Importing overwrites the published content/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Overwrite live post' } )
		).toBeInTheDocument();
		expect( fetch ).not.toHaveBeenCalled();
	} );

	it( 'Verifies that the overwrite button is described by the live warning', () => {
		// ARRANGE + ACT: Render the live-overwrite confirmation.
		render( <ImportModal { ...LIVE_PROPS } /> );

		// ASSERT: Focus lands on Cancel, so the warning is only announced if
		// the confirm button points at it.
		const describedBy = screen
			.getByRole( 'button', { name: 'Overwrite live post' } )
			.getAttribute( 'aria-describedby' );
		expect( describedBy ).not.toBeNull();

		const described = ( describedBy as string )
			.split( ' ' )
			.map( ( id ) => document.getElementById( id )?.textContent ?? '' )
			.join( ' ' );
		expect( described ).toContain( 'is live' );
		expect( described ).toContain(
			'Importing overwrites the published content'
		);
	} );

	it( 'Verifies that a failed draft import surfaces the reason and offers a retry', async () => {
		// ARRANGE: The endpoint refuses the import.
		stubFetch( { success: false, data: 'Source site unreachable' } );

		// ACT: Render for a draft import, which submits on mount.
		render( <ImportModal { ...BASE_PROPS } /> );

		// ASSERT: The reason reaches an alert region with a retry beside it,
		// replacing the confirmation that used to host errors.
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Source site unreachable'
		);
		expect(
			screen.getByRole( 'button', { name: 'Retry' } )
		).toBeInTheDocument();
	} );

	it.each( [
		[ 'draft import', BASE_PROPS, false ],
		[ 'live overwrite', LIVE_PROPS, true ],
	] )( 'Verifies that a source reason is isolated during %s', async (
		_label,
		props,
		requiresConfirmation
	) => {
		// ARRANGE: The source reason contains directional controls.
		const reason = '\u202eSource refused the request.\u202c';
		stubFetch( {
			success: false,
			data: {
				message: `Source site returned HTTP error 401. ${ reason }`,
				source_error: {
					message: reason,
					template:
						'Source site returned HTTP error 401. <reason />',
				},
			},
		} );

		// ACT: Start the import if this path requires confirmation.
		render( <ImportModal { ...props } /> );
		if ( requiresConfirmation ) {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Overwrite live post' } )
			);
		}
		const isolatedReason = await screen.findByText( reason );

		// ASSERT: The source text cannot reorder the surrounding modal copy.
		expect( isolatedReason.tagName ).toBe( 'BDI' );
		expect( isolatedReason ).toHaveAttribute( 'dir', 'auto' );
	} );

	it( 'Verifies that a failed draft import refreshes the listing on dismiss', async () => {
		// ARRANGE: The endpoint refuses an auto-submitted draft import.
		stubFetch( { success: false, data: 'Source site unreachable' } );
		const onRefresh = vi.fn();
		const { unmount } = render(
			<ImportModal { ...BASE_PROPS } onRefresh={ onRefresh } />
		);

		// ACT: Let the failed attempt settle.
		await screen.findByRole( 'alert' );

		// ASSERT: Failure does not refresh while the error remains open.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the error.
		unmount();

		// ASSERT: The attempt can have written before being refused, so the
		// listing is refreshed even though the import returned no edit URL.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( "Verifies that the endpoint's update prompt is not reported as an import", async () => {
		// ARRANGE: A stale row offered Import for a post already imported, so
		// the endpoint answers with its confirmation prompt.
		stubFetch( {
			success: true,
			data: {
				existing: true,
				edit_url: 'https://example.com/edit',
				confirm_action: 'update_existing',
			},
		} );

		// ACT: Render for a draft import.
		render( <ImportModal { ...BASE_PROPS } /> );

		// ASSERT: The stale listing is named, no success copy renders, and
		// there is nothing to retry.
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'This post is already imported. Refresh the listing to see its current state.'
		);
		expect(
			screen.queryByText( '"A Post" has been imported as a draft.' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Retry' } )
		).not.toBeInTheDocument();
	} );

	it( 'Verifies that dismissing a draft import in flight still refreshes the listing', () => {
		// ARRANGE: A request that never settles, dismissed before it returns.
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );
		const onRefresh = vi.fn();

		// ACT: Unmount while the import is still running.
		const { unmount } = render(
			<ImportModal { ...BASE_PROPS } onRefresh={ onRefresh } />
		);
		unmount();

		// ASSERT: The import continues server-side, so the stale row has to be
		// refetched even though no success was observed.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a failed live overwrite still refreshes the listing on dismiss', async () => {
		// ARRANGE: A confirmed overwrite that the endpoint then rejects. The
		// attempt can still have written a history row.
		stubFetch( { success: false, data: 'Source site unreachable' } );
		const onRefresh = vi.fn();
		const { unmount } = render(
			<ImportModal { ...LIVE_PROPS } onRefresh={ onRefresh } />
		);

		// ACT: Confirm the overwrite and let it fail.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Overwrite live post' } )
		);
		await screen.findByRole( 'alert' );

		// ASSERT: Failure does not refresh while the error remains open.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the error.
		unmount();

		// ASSERT: Starting the import is what obliges the refetch, not
		// succeeding at it.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that cancelling a live overwrite does not refresh the listing', () => {
		// ARRANGE: A published destination post, so nothing submits on mount.
		const onRefresh = vi.fn();

		// ACT: Dismiss the confirmation without confirming.
		const { unmount } = render(
			<ImportModal { ...LIVE_PROPS } onRefresh={ onRefresh } />
		);
		unmount();

		// ASSERT: Nothing changed, so no refetch is triggered.
		expect( onRefresh ).not.toHaveBeenCalled();
	} );

	it( 'Verifies that a mixed selection shows a plural skipped notice on the confirmation', () => {
		// ARRANGE + ACT: Confirm one live overwrite while three rows are dropped.
		render( <ImportModal { ...LIVE_PROPS } skippedCount={ 3 } /> );

		// ASSERT: The plural skipped note shows beside the warning.
		expect(
			screen.getByText(
				'3 selected posts are already up to date or cannot be imported, so they are not included.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that a single skipped row reads in the singular and outlives the import', async () => {
		// ARRANGE + ACT: A draft import with a single dropped selection, run to
		// completion — the removed confirmation was the note's only home.
		render( <ImportModal { ...BASE_PROPS } skippedCount={ 1 } /> );
		await screen.findByText( '"A Post" has been imported as a draft.' );

		// ASSERT: The note reads in the singular and still shows once done.
		expect(
			screen.getByText(
				'1 selected post is already up to date or cannot be imported, so it is not included.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that an import in flight keeps a focusable control', async () => {
		// ARRANGE: A request that never settles, holding the in-flight state.
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );

		// ACT: Render for a draft import.
		render( <ImportModal { ...BASE_PROPS } /> );

		// ASSERT: The action hides the modal header, so without this button the
		// state has nothing tabbable and keyboard focus is stranded outside.
		expect( await screen.findByText( 'Importing…' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Close' } )
		).toBeEnabled();
	} );

	it( 'Verifies that no skipped notice shows when nothing was dropped', () => {
		// ARRANGE + ACT: A lone eligible row with no dropped selections.
		render( <ImportModal { ...LIVE_PROPS } /> );

		// ASSERT: No skipped copy renders.
		expect(
			screen.queryByText( /is not included\.|are not included\./ )
		).not.toBeInTheDocument();
	} );
} );

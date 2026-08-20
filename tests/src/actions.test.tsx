/**
 * Tests for the unified Posts and Needs attention action factories.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	createNeedsAttentionActions,
	createPostsActions,
	type NeedsAttentionActionsContext,
	type PostsActionsContext,
} from '@/actions';
import {
	RETRY_ATTENTION_BATCH_MAX,
	RETRY_PENDING_DELAY_MS,
} from '@/constants';
import BulkImportFlow from '@/components/BulkImportFlow';
import BulkRollbackPostModal from '@/components/BulkRollbackPostModal';
import DeleteFailedImportsModal from '@/components/DeleteFailedImportsModal';
import ImportModal from '@/components/ImportModal';
import RollbackPostModal from '@/components/RollbackPostModal';
import type {
	BulkRetryAttentionResponse,
	ImportSyncStatus,
	InboxDegradation,
	InboxFailure,
	LocalState,
	NeedsAttentionRow,
	UnifiedPostRow,
} from '@/types';
import type {
	Action,
	ActionButton,
	ActionModal,
} from '@wordpress/dataviews/build-types';

const CONTEXT: PostsActionsContext = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

/**
 * Builds a UnifiedPostRow fixture; tests override fields to match the case.
 */
function buildRow( overrides: Partial< UnifiedPostRow > = {} ): UnifiedPostRow {
	return {
		id: 10,
		source_post_id: 10,
		title: 'Test',
		link: 'https://source.example/test',
		date_gmt: '2024-03-15T10:30:00Z',
		modified_gmt: '2024-03-15T10:30:00Z',
		post_type: 'post',
		status: 'publish',
		local_state: 'available',
		is_imported: false,
		wp_post_status: null,
		item_id: null,
		post_id: null,
		import_date_gmt: null,
		has_previous_content: false,
		edit_url: '',
		...overrides,
	};
}

/**
 * Builds an imported row with eligible defaults.
 */
function buildImportedRow(
	overrides: Partial< UnifiedPostRow > = {}
): UnifiedPostRow {
	return buildRow( {
		local_state: 'up-to-date' as LocalState,
		is_imported: true,
		wp_post_status: null,
		item_id: 100,
		post_id: 1024,
		import_date_gmt: '2024-03-15 10:30:00',
		edit_url: 'https://destination.example/wp-admin/post.php?post=1024',
		...overrides,
	} );
}

/**
 * Returns a modal action by id, throwing if absent or non-modal.
 */
function getModalAction(
	actions: Action< UnifiedPostRow >[],
	id: string
): ActionModal< UnifiedPostRow > {
	const action = actions.find( ( a ) => a.id === id );
	if ( ! action || ! ( 'RenderModal' in action ) ) {
		throw new Error( `Expected modal action with id "${ id }"` );
	}
	return action;
}

/**
 * Returns a button action by id, throwing if absent or modal-based.
 */
function getButtonAction(
	actions: Action< UnifiedPostRow >[],
	id: string
): ActionButton< UnifiedPostRow > {
	const action = actions.find( ( candidate ) => candidate.id === id );
	if ( ! action || ! ( 'callback' in action ) ) {
		throw new Error( `Expected button action with id "${ id }"` );
	}
	return action;
}

describe( 'createPostsActions per-state eligibility', () => {
	const actions = createPostsActions( undefined, true, CONTEXT, {} );

	it( 'Verifies that Import covers available and outdated rows', () => {
		// ARRANGE: The merged Import action and rows in each eligible state.
		const importAction = actions.find( ( a ) => a.id === 'import' );

		// ACT + ASSERT: Available and outdated both show Import. Outdated needs
		// a non-up-to-date verdict to stay eligible; the default action set is
		// built with an empty sync-statuses map.
		expect( importAction?.isEligible?.( buildRow() ) ).toBe( true );
		expect(
			importAction?.isEligible?.(
				buildImportedRow( { local_state: 'outdated' } )
			)
		).toBe( true );
	} );

	it( 'Verifies that Import hides on up-to-date imported rows', () => {
		// ARRANGE: A sync-statuses map that reports up-to-date.
		const statuses: Record< number, { status: ImportSyncStatus } > = {
			10: { status: 'up-to-date' },
		};
		const importAction = createPostsActions(
			undefined,
			true,
			CONTEXT,
			statuses
		).find( ( a ) => a.id === 'import' );

		// ACT + ASSERT: An up-to-date imported row hides Import.
		expect( importAction?.isEligible?.( buildImportedRow() ) ).toBe( false );
	} );

	it( 'Verifies that Import is gated by authorization', () => {
		// ARRANGE: An unauthorized action set.
		const unauthorized = createPostsActions( undefined, false, CONTEXT, {} );
		const importAction = unauthorized.find( ( a ) => a.id === 'import' );

		// ACT + ASSERT: Even an Available row hides Import when unauthorized.
		expect( importAction?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that Edit appears only on imported/outdated rows with an edit_url', () => {
		// ARRANGE: The Edit action under test.
		const editAction = actions.find( ( a ) => a.id === 'edit-post' );

		// ACT + ASSERT: Imported and outdated rows expose Edit; missing
		// edit_url or other states hide it.
		expect( editAction?.isEligible?.( buildImportedRow() ) ).toBe( true );
		expect(
			editAction?.isEligible?.(
				buildImportedRow( { local_state: 'outdated' } )
			)
		).toBe( true );
		expect(
			editAction?.isEligible?.( buildImportedRow( { edit_url: '' } ) )
		).toBe( false );
		expect( editAction?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that View source opens a usable source permalink', () => {
		// ARRANGE: The View source action and a spy for new-tab navigation.
		const viewSource = getButtonAction( actions, 'view-source' );
		const open = vi.spyOn( window, 'open' ).mockImplementation( () => null );
		const row = buildRow();

		// ACT: Run the action for a row carrying a source permalink.
		viewSource.callback( [ row ], { registry: undefined } );

		// ASSERT: The row is eligible and opens its source URL safely.
		expect( viewSource.supportsBulk ).toBe( false );
		expect( viewSource.isEligible?.( row ) ).toBe( true );
		expect( open ).toHaveBeenCalledWith(
			row.link,
			'_blank',
			'noreferrer'
		);

		open.mockRestore();
	} );

	it( 'Verifies that View source hides without a post permalink', () => {
		// ARRANGE: The View source action and rows without a usable post path.
		const viewSource = getButtonAction( actions, 'view-source' );

		// ACT + ASSERT: Empty and source-homepage URLs do not expose the action.
		expect( viewSource.isEligible?.( buildRow( { link: '' } ) ) ).toBe(
			false
		);
		expect(
			viewSource.isEligible?.(
				buildRow( { link: 'https://source.example.com/' } )
			)
		).toBe( false );
	} );

	it( 'Verifies that Compare on Imported only shows on a confirmed outdated verdict', () => {
		// ARRANGE: Imported rows shouldn't surface Compare until the async
		// sync check confirms a divergence — otherwise the action flashes
		// then vanishes once the up-to-date verdict lands.
		const compareLoading = actions.find( ( a ) => a.id === 'compare-post' );
		const compareOutdatedVerdict = createPostsActions(
			undefined,
			true,
			CONTEXT,
			{ 10: { status: 'outdated' } }
		).find( ( a ) => a.id === 'compare-post' );
		const compareUpToDate = createPostsActions( undefined, true, CONTEXT, {
			10: { status: 'up-to-date' },
		} ).find( ( a ) => a.id === 'compare-post' );

		// ACT + ASSERT: Imported + (no status / up-to-date) hides; imported +
		// outdated verdict shows.
		expect( compareLoading?.isEligible?.( buildImportedRow() ) ).toBe(
			false
		);
		expect( compareUpToDate?.isEligible?.( buildImportedRow() ) ).toBe(
			false
		);
		expect(
			compareOutdatedVerdict?.isEligible?.( buildImportedRow() )
		).toBe( true );
	} );

	it( 'Verifies that Compare always shows on server-confirmed outdated rows', () => {
		// ARRANGE: A local_state='outdated' row needs no client verdict —
		// the server already flagged the divergence.
		const compare = actions.find( ( a ) => a.id === 'compare-post' );

		// ACT + ASSERT: Outdated shows regardless of empty syncStatuses.
		expect(
			compare?.isEligible?.(
				buildImportedRow( { local_state: 'outdated' } )
			)
		).toBe( true );
	} );

	it( 'Verifies that Rollback requires imported/outdated state and an item_id', () => {
		// ARRANGE: The Rollback action.
		const rollback = actions.find( ( a ) => a.id === 'rollback' );

		// ACT + ASSERT: Imported with an item_id shows; missing item_id
		// or wrong state hides.
		expect( rollback?.isEligible?.( buildImportedRow() ) ).toBe( true );
		expect(
			rollback?.isEligible?.( buildImportedRow( { item_id: null } ) )
		).toBe( false );
		expect( rollback?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that single vs bulk rollback selects the matching modal', () => {
		// ARRANGE: The Rollback action.
		const rollback = getModalAction( actions, 'rollback' );

		// ACT + ASSERT: A single selection routes to RollbackPostModal.
		const single = rollback.RenderModal( {
			items: [ buildImportedRow() ],
		} );
		expect( single.type ).toBe( RollbackPostModal );

		// ACT + ASSERT: A multi selection routes to BulkRollbackPostModal.
		const bulk = rollback.RenderModal( {
			items: [
				buildImportedRow( { id: 1, source_post_id: 1 } ),
				buildImportedRow( { id: 2, source_post_id: 2 } ),
			],
		} );
		expect( bulk.type ).toBe( BulkRollbackPostModal );
	} );

	it( 'Verifies that the notice sink reaches only the single-item rollback modal', () => {
		// ARRANGE: An action set whose context carries a notice sink.
		const onNotice = vi.fn();
		const rollback = getModalAction(
			createPostsActions(
				undefined,
				true,
				{ ...CONTEXT, onNotice },
				{}
			),
			'rollback'
		);

		// ACT: Render the single-item and bulk rollback modals.
		const single = rollback.RenderModal( { items: [ buildImportedRow() ] } );
		const bulk = rollback.RenderModal( {
			items: [
				buildImportedRow( { id: 1, source_post_id: 1 } ),
				buildImportedRow( { id: 2, source_post_id: 2 } ),
			],
		} );

		// ASSERT: The single modal receives the sink; bulk is left untouched.
		expect( ( single.props as { onNotice?: unknown } ).onNotice ).toBe(
			onNotice
		);
		expect(
			( bulk.props as { onNotice?: unknown } ).onNotice
		).toBeUndefined();
	} );

	it( 'Verifies that bulk Import reports the skipped count to the batch modal', () => {
		// ARRANGE: Five rows selected, three of them handed to the modal as
		// import-eligible.
		const importAction = getModalAction(
			createPostsActions( undefined, true, CONTEXT, {}, 5 ),
			'import'
		);

		// ACT: Render the batch modal for the three eligible rows.
		const modal = importAction.RenderModal( {
			items: [
				buildRow( { id: 1, source_post_id: 1 } ),
				buildRow( { id: 2, source_post_id: 2 } ),
				buildRow( { id: 3, source_post_id: 3 } ),
			],
		} );

		// ASSERT: The batch modal is told the two remaining rows were skipped.
		expect( modal.type ).toBe( BulkImportFlow );
		expect(
			( modal.props as { skippedCount?: number } ).skippedCount
		).toBe( 2 );
	} );

	it( 'Verifies that bulk Import clamps the skipped count at zero', () => {
		// ARRANGE: A stale selected count lower than the eligible rows handed
		// to the modal, which must not yield a negative skipped count.
		const importAction = getModalAction(
			createPostsActions( undefined, true, CONTEXT, {}, 1 ),
			'import'
		);

		// ACT: Render the batch modal for two eligible rows.
		const modal = importAction.RenderModal( {
			items: [
				buildRow( { id: 1, source_post_id: 1 } ),
				buildRow( { id: 2, source_post_id: 2 } ),
			],
		} );

		// ASSERT: The skipped count floors at zero rather than going negative.
		expect( modal.type ).toBe( BulkImportFlow );
		expect(
			( modal.props as { skippedCount?: number } ).skippedCount
		).toBe( 0 );
	} );

	it( 'Verifies that a lone eligible row routes to the single modal with the skipped count', () => {
		// ARRANGE: Four rows selected but only one is import-eligible.
		const importAction = getModalAction(
			createPostsActions( undefined, true, CONTEXT, {}, 4 ),
			'import'
		);

		// ACT: Render the modal for the single eligible row.
		const modal = importAction.RenderModal( { items: [ buildRow() ] } );

		// ASSERT: The single-post modal is used and reports the three dropped rows.
		expect( modal.type ).toBe( ImportModal );
		expect(
			( modal.props as { skippedCount?: number } ).skippedCount
		).toBe( 3 );
	} );
} );

/**
 * Builds a degradation inbox row; tests override fields to match the case.
 */
function buildDegradation(
	overrides: Partial< InboxDegradation > = {}
): InboxDegradation {
	return {
		kind: 'degradation',
		row_id: 'degradation:1024:nav_ref_rewrite_failed:8300:post:',
		affected_post_id: 1024,
		issue_type: 'nav_ref_rewrite_failed',
		target_ref: 8300,
		target_kind: 'post',
		target_slug: '',
		target_is_reusable_block: false,
		target_terms: [],
		target_reason: '',
		severity: 'error',
		source_site_url: 'https://source.example.com',
		first_detected_gmt: '2024-03-15 10:30:00',
		last_seen_gmt: '2024-03-15 10:30:00',
		affected_title: 'Primary Menu',
		affected_edit_url: '',
		retryable: true,
		resolvable: false,
		...overrides,
	};
}

/**
 * Builds a failure inbox row; tests override fields to match the case.
 */
function buildFailure( overrides: Partial< InboxFailure > = {} ): InboxFailure {
	return {
		kind: 'failure',
		row_id: 'failure:42',
		item_id: 42,
		source_post_id: 10,
		title: 'Broken import',
		error_message: 'Boom',
		import_date_gmt: '2024-03-15 10:30:00',
		source_site_url: 'https://source.example.com',
		edit_url: '',
		...overrides,
	};
}

/**
 * Invokes a DataViews action callback regardless of its declared arity.
 */
function runCallback(
	action: Action< NeedsAttentionRow >,
	items: NeedsAttentionRow[]
): void {
	(
		action as unknown as { callback: ( rows: NeedsAttentionRow[] ) => void }
	).callback( items );
}

/**
 * Builds a bulk-retry response payload; tests override individual counts.
 */
function bulkCounts(
	over: Partial< BulkRetryAttentionResponse > = {}
): BulkRetryAttentionResponse {
	return {
		resolved: 0,
		target_absent: 0,
		write_failed: 0,
		unresolved: 0,
		skipped: 0,
		...over,
	};
}

describe( 'createNeedsAttentionActions', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
		vi.useRealTimers();
	} );

	const RETRY_CONTEXT: NeedsAttentionActionsContext = {
		ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
	};

	it( 'Verifies that Remove targets failures, not degradations', () => {
		// ARRANGE: The Remove action.
		const remove = createNeedsAttentionActions(
			undefined,
			RETRY_CONTEXT,
			'open'
		).find( ( a ) => a.id === 'remove-failure' );

		// ACT + ASSERT: A failure row is eligible; a degradation is not.
		expect( remove?.isEligible?.( buildFailure() ) ).toBe( true );
		expect( remove?.isEligible?.( buildDegradation() ) ).toBe( false );
	} );

	it( 'Verifies that Remove maps orphan and source-linked failures to their scopes', () => {
		// ARRANGE: The Remove modal action and one orphan + one source-linked
		// failure.
		const remove = createNeedsAttentionActions(
			undefined,
			RETRY_CONTEXT,
			'open'
		).find( ( a ) => a.id === 'remove-failure' ) as ActionModal<
			NeedsAttentionRow
		>;

		// ACT: Render the confirmation modal for both.
		const modal = remove.RenderModal( {
			items: [
				buildFailure( { item_id: 7, source_post_id: null } ),
				buildFailure( { item_id: 8, source_post_id: 20 } ),
			],
		} );

		// ASSERT: It hands the modal each failure with its item id and source.
		expect( modal.type ).toBe( DeleteFailedImportsModal );
		expect(
			( modal.props as { items: unknown } ).items
		).toEqual( [
			{ itemId: 7, sourcePostId: null, title: 'Broken import' },
			{ itemId: 8, sourcePostId: 20, title: 'Broken import' },
		] );
	} );

	it( 'Verifies that Retry shows only for retryable degradations', () => {
		// ARRANGE: The Retry action.
		const retry = createNeedsAttentionActions(
			undefined,
			RETRY_CONTEXT,
			'open'
		).find( ( a ) => a.id === 'retry-degradation' );

		// ACT + ASSERT: A retryable degradation is eligible; a guidance-only
		// one and a failure are not.
		expect( retry?.isEligible?.( buildDegradation() ) ).toBe( true );
		expect(
			retry?.isEligible?.(
				buildDegradation( {
					issue_type: 'unmapped_block_reference',
					retryable: false,
				} )
			)
		).toBe( false );
		expect( retry?.isEligible?.( buildFailure() ) ).toBe( false );
	} );

	/**
	 * Returns the Retry action from a fresh inbox action set.
	 */
	function retryAction(
		onRefresh: ( () => void ) | undefined,
		context: NeedsAttentionActionsContext = RETRY_CONTEXT
	): Action< NeedsAttentionRow > {
		return createNeedsAttentionActions( onRefresh, context, 'open' ).find(
			( a ) => a.id === 'retry-degradation'
		) as Action< NeedsAttentionRow >;
	}

	it( 'posts the retry request and refreshes on success', async () => {
		// ARRANGE: A succeeding endpoint and a refresh spy.
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( { success: true, data: { resolved: true } } ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();

		// ACT: Run the Retry callback for one degradation.
		runCallback( retryAction( onRefresh ), [ buildDegradation() ] );

		// ASSERT: It posts the issue identity and refreshes the listing.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const body = fetchMock.mock.calls[ 0 ][ 1 ].body as FormData;
		expect( body.get( 'action' ) ).toBe(
			'safe_publish_retry_attention_issue'
		);
		expect( body.get( 'affected_post_id' ) ).toBe( '1024' );
		expect( body.get( 'issue_type' ) ).toBe( 'nav_ref_rewrite_failed' );
		expect( body.get( 'target_ref' ) ).toBe( '8300' );
		expect( body.get( 'target_kind' ) ).toBe( 'post' );
	} );

	it( 'surfaces an error notice and still refreshes on a failed retry', async () => {
		// ARRANGE: An endpoint that rejects the retry and a notice sink.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () => Promise.resolve( { success: false, error: 'Nope.' } ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Run the Retry callback.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation(),
		] );

		// ASSERT: An error notice is surfaced and the listing still refreshes.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( onNotice ).toHaveBeenCalledWith( {
			status: 'error',
			message: 'Nope.',
		} );
	} );

	it( 'maps an unresolved outcome to a still-needs-attention warning', async () => {
		// ARRANGE: A reconciliation that ran but left the issue open.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							resolved: false,
							outcome: 'unresolved',
							detail: '',
						},
					} ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry an unmapped reference the run couldn't clear.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( {
				issue_type: 'unmapped_block_reference',
				target_ref: 5,
				target_kind: 'post',
			} ),
		] );

		// ASSERT: A still-needs-attention warning is surfaced and the listing
		// still refreshes.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( onNotice ).toHaveBeenCalledWith(
			expect.objectContaining( {
				status: 'warning',
				message: expect.stringContaining( 'Still needs attention.' ),
			} )
		);
	} );

	it( 'maps a target_absent outcome to actionable import guidance', async () => {
		// ARRANGE: A reconciliation whose target still isn't on this site.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							resolved: false,
							outcome: 'target_absent',
							detail: 'Internal diagnostic.',
						},
					} ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry an unmapped reference whose dependency isn't imported yet.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( {
				issue_type: 'unmapped_block_reference',
				target_ref: 5,
				target_kind: 'post',
			} ),
		] );

		// ASSERT: The import-then-retry guidance is surfaced as a warning,
		// distinct from the generic still-needs-attention copy.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const notice = onNotice.mock.calls.at( -1 )?.[ 0 ];
		expect( notice.status ).toBe( 'warning' );
		expect( notice.message ).toContain( 'Import it, then Retry' );
		expect( notice.message ).not.toContain( 'Still needs attention' );
	} );

	it( 'maps a write_failed outcome to an error notice', async () => {
		// ARRANGE: A reconciliation whose write failed.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							resolved: false,
							outcome: 'write_failed',
							detail: 'Internal diagnostic.',
						},
					} ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry a degradation whose reconciliation write failed.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( { affected_title: 'Primary Menu' } ),
		] );

		// ASSERT: A hard error notice is surfaced, not a soft warning.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( onNotice ).toHaveBeenCalledWith( {
			status: 'error',
			message: 'Failed to retry Primary Menu.',
		} );
	} );

	it( 'holds the in-flight notice back until the delay elapses', () => {
		// ARRANGE: A retry whose response never settles, so it stays in flight.
		vi.useFakeTimers();
		vi.stubGlobal(
			'fetch',
			vi.fn().mockReturnValue( new Promise( () => {} ) )
		);
		const onNotice = vi.fn();

		// ACT: Run the Retry callback.
		runCallback( retryAction( vi.fn(), { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation(),
		] );

		// ASSERT: The banner clears at once, but "Retrying…" waits out the
		// delay so a fast retry never flashes it.
		expect( onNotice ).toHaveBeenCalledWith( null );
		expect( onNotice ).not.toHaveBeenCalledWith(
			expect.objectContaining( { status: 'info' } )
		);

		// ACT: Let the delay elapse.
		vi.advanceTimersByTime( RETRY_PENDING_DELAY_MS );

		// ASSERT: Only now does the in-flight notice appear.
		expect( onNotice ).toHaveBeenCalledWith( {
			status: 'info',
			message: 'Retrying…',
		} );
	} );

	it( 'ignores a second retry for the same issue while one is in flight', () => {
		// ARRANGE: A retry that stays in flight, plus a shared in-flight set.
		vi.useFakeTimers();
		const fetchMock = vi.fn().mockReturnValue( new Promise( () => {} ) );
		vi.stubGlobal( 'fetch', fetchMock );
		const inFlight = new Set< string >();

		const retry = retryAction( vi.fn(), { ...RETRY_CONTEXT, inFlight } );

		// ACT: Click twice before the first request settles.
		runCallback( retry, [ buildDegradation() ] );
		runCallback( retry, [ buildDegradation() ] );

		// ASSERT: Only the first submit fired a request.
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'confirms resolution with a success notice naming the content', async () => {
		// ARRANGE: A retry that resolves the issue.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( { success: true, data: { resolved: true } } ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry a degradation that clears.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( { affected_title: 'Primary Menu' } ),
		] );

		// ASSERT: The outcome confirms the fix by name, and the quick resolve
		// never flashed the in-flight notice.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( onNotice ).toHaveBeenLastCalledWith( {
			status: 'success',
			message: 'Resolved: Primary Menu',
		} );
		expect( onNotice ).not.toHaveBeenCalledWith(
			expect.objectContaining( { status: 'info' } )
		);
	} );

	it( 'Verifies that Retry opts into bulk selection', () => {
		// ACT + ASSERT: The Retry action supports bulk.
		expect( retryAction( undefined ).supportsBulk ).toBe( true );
	} );

	it( 'posts a bulk retry and surfaces the aggregate outcome', async () => {
		// ARRANGE: A bulk endpoint reporting one resolved and one still waiting.
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: {
						resolved: 1,
						target_absent: 1,
						write_failed: 0,
						unresolved: 0,
						skipped: 0,
					},
				} ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry two selected degradations at once.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( { affected_post_id: 1 } ),
			buildDegradation( { affected_post_id: 2 } ),
		] );

		// ASSERT: It hits the bulk endpoint with both, then reports the
		// aggregate as a warning because one target is still waiting.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const body = fetchMock.mock.calls[ 0 ][ 1 ].body as FormData;
		expect( body.get( 'action' ) ).toBe(
			'safe_publish_bulk_retry_attention_issues'
		);
		expect(
			JSON.parse( body.get( 'items' ) as string )
		).toHaveLength( 2 );
		expect( onNotice ).toHaveBeenLastCalledWith( {
			status: 'warning',
			message: '1 resolved, 1 waiting on import, 0 failed.',
		} );
	} );

	it( 'ignores a second bulk retry while one is in flight', () => {
		// ARRANGE: A bulk retry that never settles, plus a shared in-flight set.
		vi.useFakeTimers();
		const fetchMock = vi.fn().mockReturnValue( new Promise( () => {} ) );
		vi.stubGlobal( 'fetch', fetchMock );
		const inFlight = new Set< string >();
		const selection = [
			buildDegradation( { affected_post_id: 1 } ),
			buildDegradation( { affected_post_id: 2 } ),
		];

		// ACT: Click the bulk retry twice before the first request settles.
		const retry = retryAction( vi.fn(), { ...RETRY_CONTEXT, inFlight } );
		runCallback( retry, selection );
		runCallback( retry, selection );

		// ASSERT: Only the first submit fired a request.
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports rows dropped as non-retryable in the bulk notice', async () => {
		// ARRANGE: A bulk endpoint that resolves both retried degradations.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: bulkCounts( { resolved: 2 } ),
					} ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Select two retryable degradations plus a failure, then Retry.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( { affected_post_id: 1 } ),
			buildDegradation( { affected_post_id: 2 } ),
			buildFailure(),
		] );

		// ASSERT: The failure surfaces as skipped, not silently dropped.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const notice = onNotice.mock.calls.at( -1 )?.[ 0 ];
		expect( notice.status ).toBe( 'warning' );
		expect( notice.message ).toContain( '1 skipped' );
	} );

	it( 'slices a bulk retry over the cap into sequential batches', async () => {
		// ARRANGE: A bulk endpoint answering each batch with its own count.
		const fetchMock = vi
			.fn()
			.mockResolvedValueOnce( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: bulkCounts( {
							resolved: RETRY_ATTENTION_BATCH_MAX,
						} ),
					} ),
				} )
			.mockResolvedValueOnce( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: bulkCounts( { resolved: 1 } ),
					} ),
				} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry one degradation past the per-request cap.
		const many = Array.from(
			{ length: RETRY_ATTENTION_BATCH_MAX + 1 },
			( _, index ) => buildDegradation( { affected_post_id: index + 1 } )
		);
		runCallback(
			retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ),
			many
		);

		// ASSERT: Two requests, sized cap then 1, with the counts summed.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
		const batch = ( call: number ): unknown[] =>
			JSON.parse(
				( fetchMock.mock.calls[ call ][ 1 ].body as FormData ).get(
					'items'
				) as string
			);
		expect( batch( 0 ) ).toHaveLength( RETRY_ATTENTION_BATCH_MAX );
		expect( batch( 1 ) ).toHaveLength( 1 );
		expect( onNotice ).toHaveBeenLastCalledWith( {
			status: 'success',
			message: `${ RETRY_ATTENTION_BATCH_MAX + 1 } resolved, 0 waiting on import, 0 failed.`,
		} );
	} );

	it( 'routes a lone retryable degradation with skipped rows to the bulk path', async () => {
		// ARRANGE: A bulk endpoint that resolves the one retried degradation.
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: bulkCounts( { resolved: 1 } ),
				} ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Select one retryable degradation plus a failure, then Retry.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation(),
			buildFailure(),
		] );

		// ASSERT: It takes the bulk path and reports the failure as skipped,
		// rather than the single retry that would drop it silently.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const body = fetchMock.mock.calls[ 0 ][ 1 ].body as FormData;
		expect( body.get( 'action' ) ).toBe(
			'safe_publish_bulk_retry_attention_issues'
		);
		const notice = onNotice.mock.calls.at( -1 )?.[ 0 ];
		expect( notice.status ).toBe( 'warning' );
		expect( notice.message ).toContain( '1 skipped' );
	} );

	it( 'surfaces an error when a later batch is rejected after earlier ones ran', async () => {
		// ARRANGE: The first batch resolves; the server rejects the second.
		const fetchMock = vi
			.fn()
			.mockResolvedValueOnce( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: bulkCounts( {
							resolved: RETRY_ATTENTION_BATCH_MAX,
						} ),
					} ),
			} )
			.mockResolvedValueOnce( {
				json: () =>
					Promise.resolve( {
						success: false,
						data: 'Retry is limited.',
					} ),
			} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry one past the cap, so it splits into two batches.
		const many = Array.from(
			{ length: RETRY_ATTENTION_BATCH_MAX + 1 },
			( _, index ) => buildDegradation( { affected_post_id: index + 1 } )
		);
		runCallback(
			retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ),
			many
		);

		// ASSERT: The first batch ran, and the rejected second surfaces an error.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
		expect( onNotice.mock.calls.at( -1 )?.[ 0 ].status ).toBe( 'error' );
	} );

	it( 'flags a bulk retry as an error when a write failed', async () => {
		// ARRANGE: A bulk endpoint reporting one write failure.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: bulkCounts( { resolved: 1, write_failed: 1 } ),
					} ),
			} )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry two degradations; one write fails server-side.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( { affected_post_id: 1 } ),
			buildDegradation( { affected_post_id: 2 } ),
		] );

		// ASSERT: A write failure makes the aggregate an error, not a warning.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( onNotice.mock.calls.at( -1 )?.[ 0 ].status ).toBe( 'error' );
	} );

	it( 'surfaces a network error when the bulk request throws', async () => {
		// ARRANGE: A bulk endpoint whose request rejects before reaching the server.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockRejectedValue( new Error( 'offline' ) )
		);
		const onRefresh = vi.fn();
		const onNotice = vi.fn();

		// ACT: Retry two degradations while the network is down.
		runCallback( retryAction( onRefresh, { ...RETRY_CONTEXT, onNotice } ), [
			buildDegradation( { affected_post_id: 1 } ),
			buildDegradation( { affected_post_id: 2 } ),
		] );

		// ASSERT: A network-error notice is surfaced, and it still refreshes.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		expect( onNotice ).toHaveBeenLastCalledWith( {
			status: 'error',
			message: 'Network error while retrying.',
		} );
	} );

	/**
	 * Returns an inbox action by id from the given view's action set.
	 */
	function inboxAction(
		id: string,
		view: 'open' | 'ignored',
		onRefresh: ( () => void ) | undefined = undefined,
		context: NeedsAttentionActionsContext = RETRY_CONTEXT
	): Action< NeedsAttentionRow > | undefined {
		return createNeedsAttentionActions( onRefresh, context, view ).find(
			( a ) => a.id === id
		);
	}

	it( 'Verifies that Ignore is Open-only and eligible for both kinds', () => {
		// ARRANGE: The Ignore action from each view.
		const openIgnore = inboxAction( 'ignore-needs-attention', 'open' );
		const ignoredIgnore = inboxAction(
			'ignore-needs-attention',
			'ignored'
		);

		// ACT + ASSERT: Present in Open for both kinds; absent in Ignored.
		expect( openIgnore?.isEligible?.( buildFailure() ) ).toBe( true );
		expect( openIgnore?.isEligible?.( buildDegradation() ) ).toBe( true );
		expect( ignoredIgnore ).toBeUndefined();
	} );

	it( 'Verifies that the Ignored view offers Un-ignore and Remove but hides Retry', () => {
		// ARRANGE: The Ignored view's action ids.
		const ids = createNeedsAttentionActions(
			undefined,
			RETRY_CONTEXT,
			'ignored'
		).map( ( a ) => a.id );

		// ASSERT: Un-ignore and Remove remain; Ignore and Retry are gone.
		expect( ids ).toContain( 'unignore-needs-attention' );
		expect( ids ).toContain( 'remove-failure' );
		expect( ids ).not.toContain( 'retry-degradation' );
		expect( ids ).not.toContain( 'ignore-needs-attention' );
	} );

	it( 'posts ignore descriptors for a mixed selection and refreshes', async () => {
		// ARRANGE: A succeeding endpoint and a refresh spy.
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( { success: true, data: { updated: 3 } } ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();

		// ACT: Ignore an orphan failure, a source-linked failure, and a
		// degradation together.
		runCallback(
			inboxAction(
				'ignore-needs-attention',
				'open',
				onRefresh
			) as Action< NeedsAttentionRow >,
			[
				buildFailure( { item_id: 7, source_post_id: null } ),
				buildFailure( { item_id: 8, source_post_id: 20 } ),
				buildDegradation(),
			]
		);

		// ASSERT: It posts each descriptor with ignored=1 and refreshes.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const body = fetchMock.mock.calls[ 0 ][ 1 ].body as FormData;
		expect( body.get( 'action' ) ).toBe(
			'safe_publish_set_needs_attention_ignored'
		);
		expect( body.get( 'ignored' ) ).toBe( '1' );
		expect( JSON.parse( body.get( 'items' ) as string ) ).toEqual( [
			{ kind: 'failure', item_id: 7, source_post_id: null },
			{ kind: 'failure', item_id: 8, source_post_id: 20 },
			{
				kind: 'degradation',
				affected_post_id: 1024,
				issue_type: 'nav_ref_rewrite_failed',
				target_ref: 8300,
				target_kind: 'post',
				target_slug: '',
			},
		] );
	} );

	it( 'posts un-ignore with ignored=0 from the Ignored view', async () => {
		// ARRANGE: A succeeding endpoint and a refresh spy.
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( { success: true, data: { updated: 1 } } ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const onRefresh = vi.fn();

		// ACT: Un-ignore one degradation.
		runCallback(
			inboxAction(
				'unignore-needs-attention',
				'ignored',
				onRefresh
			) as Action< NeedsAttentionRow >,
			[ buildDegradation() ]
		);

		// ASSERT: It posts with ignored=0 and refreshes.
		await vi.waitFor( () => expect( onRefresh ).toHaveBeenCalled() );
		const body = fetchMock.mock.calls[ 0 ][ 1 ].body as FormData;
		expect( body.get( 'ignored' ) ).toBe( '0' );
	} );

	it( 'reports a no-op ignore as info, not a false success', async () => {
		// ARRANGE: An ignore endpoint that matched no rows (a stale selection).
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: () =>
					Promise.resolve( { success: true, data: { updated: 0 } } ),
			} )
		);
		const onNotice = vi.fn();
		const ignore = inboxAction( 'ignore-needs-attention', 'open', vi.fn(), {
			...RETRY_CONTEXT,
			onNotice,
		} ) as Action< NeedsAttentionRow >;

		// ACT: Ignore a row the server no longer finds.
		runCallback( ignore, [ buildDegradation() ] );

		// ASSERT: An info notice, not a success that overstates the no-op.
		await vi.waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith( {
				status: 'info',
				message: 'No matching items to ignore.',
			} )
		);
	} );
} );

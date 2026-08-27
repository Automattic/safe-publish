/**
 * Confirmation, progress, and results modal for the Source Posts bulk
 * Import action. POSTs to `safe_publish_bulk_import`, which inserts new
 * posts and updates existing ones based on whether the source id resolves
 * to a local post.
 *
 * @file This file defines the BulkImportFlow component.
 */

import {
	Button,
	ProgressBar,
	Spinner,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import ConfirmTitleList from './ConfirmTitleList';
import ScrollableRegion from './ScrollableRegion';
import { MAX_VISIBLE_MODAL_TITLES } from '../constants';
import {
	getErrorMessage,
	isImportUpdate,
	renderWarningShortLabel,
} from '../utils';
import { useRefreshOnUnmount } from './hooks/useRefreshOnUnmount';

import type {
	ApiResponse,
	BulkImportResponse,
	BulkImportResult,
	LocalState,
} from '../types';

/**
 * A selected row, carrying the routing state the confirmation groups by. The
 * unified Posts listing maps each UnifiedPostRow down to this.
 */
export interface BulkImportFlowPost {
	id: number;
	post_type: string;
	title: string;
	local_state: LocalState;
}

/**
 * Props for the BulkImportFlow modal body.
 *
 * @property {BulkImportFlowPost[]} posts        Pre-mapped row payload.
 * @property {number?}              skippedCount Ineligible selected rows dropped before import.
 * @property {Object}               context      Admin-ajax URL + nonce.
 * @property {Function}             onClose      Modal close callback.
 * @property {Function?}            onRefresh    Called after a successful run.
 */
export interface BulkImportFlowProps {
	posts: BulkImportFlowPost[];
	skippedCount?: number;
	context: { ajaxurl: string; nonce: string };
	onClose: () => void;
	onRefresh?: () => void;
}

/**
 * Returns true when the result is a successful import that carries warnings.
 *
 * @param {BulkImportResult} result Per-post result entry.
 *
 * @return {boolean} True when warnings were attached to a successful import.
 */
const hasWarnings = ( result: BulkImportResult ): boolean =>
	Boolean( result.success && result.warnings && result.warnings.length > 0 );

/**
 * Posts the row payload to safe_publish_bulk_import and normalizes the
 * response into the BulkImportResponse shape.
 *
 * @param {BulkImportFlowPost[]} posts           Row payload.
 * @param {Object}               context         Admin-ajax auth bundle.
 * @param {string}               context.ajaxurl Admin-ajax endpoint URL.
 * @param {string}               context.nonce   Nonce for the bulk action.
 *
 * @return {Promise<BulkImportResponse>} Bulk run results.
 */
async function postBulk(
	posts: BulkImportFlowPost[],
	context: { ajaxurl: string; nonce: string }
): Promise< BulkImportResponse > {
	const formData = new FormData();
	formData.append( 'action', 'safe_publish_bulk_import' );
	formData.append( 'nonce', context.nonce );
	// local_state drives the confirmation only; send what the endpoint reads.
	formData.append(
		'posts_data',
		JSON.stringify(
			posts.map( ( post ) => ( {
				id: post.id,
				post_type: post.post_type,
				title: post.title,
			} ) )
		)
	);

	const response = await fetch( context.ajaxurl, {
		method: 'POST',
		body: formData,
		headers: { Accept: 'application/json; charset=utf-8' },
	} );

	const result = ( await response.json() ) as ApiResponse< BulkImportResponse >;

	if ( ! result.success ) {
		throw new Error(
			getErrorMessage( result, __( 'Bulk operation failed', 'safe-publish' ) )
		);
	}

	const bulkResult = result.data;
	return {
		total: bulkResult.total || posts.length,
		successful: bulkResult.successful || 0,
		failed: bulkResult.failed || 0,
		results: bulkResult.results || [],
	};
}

/**
 * BulkImportFlow component.
 *
 * @param {BulkImportFlowProps} props Component props.
 *
 * @return {JSX.Element} Confirmation/progress/results modal body.
 */
export default function BulkImportFlow( {
	posts,
	skippedCount = 0,
	context,
	onClose,
	onRefresh,
}: BulkImportFlowProps ): JSX.Element {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ progress, setProgress ] = useState( 0 );
	const [ results, setResults ] = useState< BulkImportResponse | null >( null );
	const [ attempted, setAttempted ] = useState( false );
	const closeButtonRef = useRef< HTMLButtonElement >( null );

	const handleRun = async (): Promise< void > => {
		setAttempted( true );
		setIsLoading( true );
		setError( null );
		setProgress( 0 );
		setResults( null );

		// Single-request endpoint; fake progress so the bar moves while
		// the server works, then snap to 100% on completion.
		const interval = setInterval( () => {
			setProgress( ( prev ) => ( prev >= 90 ? 90 : prev + 9 ) );
		}, 200 );

		try {
			const next = await postBulk( posts, context );
			setResults( next );
			setProgress( 100 );
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Unknown error occurred', 'safe-publish' )
			);
		} finally {
			clearInterval( interval );
			setIsLoading( false );
		}
	};

	useRefreshOnUnmount( attempted, onRefresh );

	useEffect( () => {
		if ( null !== results && ! isLoading ) {
			closeButtonRef.current?.focus();
		}
	}, [ isLoading, results ] );

	const allFailed =
		null !== results && 0 === results.successful && results.failed > 0;
	const partialFailure =
		null !== results && results.successful > 0 && results.failed > 0;

	let summaryHeading: string = __( 'Import completed!', 'safe-publish' );
	let summaryColor = 'var(--safe-publish-status-success)';
	if ( allFailed ) {
		summaryHeading = __( 'Import failed', 'safe-publish' );
		summaryColor = 'var(--safe-publish-status-error)';
	} else if ( partialFailure ) {
		summaryHeading = __( 'Import completed with errors', 'safe-publish' );
		summaryColor = 'var(--safe-publish-status-warning)';
	}

	const updateTitles = posts
		.filter( ( post ) => isImportUpdate( post.local_state ) )
		.map( ( post ) => post.title );
	const createTitles = posts
		.filter( ( post ) => ! isImportUpdate( post.local_state ) )
		.map( ( post ) => post.title );

	// A single-row selection routes to ImportModal, so posts.length is always
	// plural here; the group counts below can be 1 and use _n().
	const confirmHeading =
		skippedCount > 0
			? sprintf(
					/* translators: 1: importable count, 2: total selected count */
					__(
						'Import %1$d of %2$d selected posts from the source?',
						'safe-publish'
					),
					posts.length,
					posts.length + skippedCount
			  )
			: sprintf(
					/* translators: %d is the number of selected posts */
					__(
						'Import %d selected posts from the source?',
						'safe-publish'
					),
					posts.length
			  );

	return (
		<VStack
			spacing="5"
			style={ { minWidth: '400px' } }
			className="safe-publish-bulk-import-modal"
		>
			{ ! results && ! isLoading && (
				<>
					<Text>{ confirmHeading }</Text>
					{ skippedCount > 0 && (
						<Text style={ { color: 'var(--safe-publish-status-warning)' } }>
							{ sprintf(
								/* translators: %d is the number of skipped posts */
								_n(
									'%d selected post is already up to date or cannot be imported, so it will be skipped.',
									'%d selected posts are already up to date or cannot be imported, so they will be skipped.',
									skippedCount,
									'safe-publish'
								),
								skippedCount
							) }
						</Text>
					) }
					<ConfirmTitleList
						heading={ sprintf(
							/* translators: %d is the number of posts that will be updated */
							_n(
								'%d post will be updated with the latest source content:',
								'%d posts will be updated with the latest source content:',
								updateTitles.length,
								'safe-publish'
							),
							updateTitles.length
						) }
						titles={ updateTitles }
					/>
					<ConfirmTitleList
						heading={ sprintf(
							/* translators: %d is the number of posts that will be created */
							_n(
								'%d post will be imported as a new draft:',
								'%d posts will be imported as new drafts:',
								createTitles.length,
								'safe-publish'
							),
							createTitles.length
						) }
						titles={ createTitles }
					/>
					<Text style={ { fontSize: '0.9em', color: 'var(--safe-publish-text-muted)' } }>
						{ __(
							'This pulls each post from the source — content, images, links, and formatting.',
							'safe-publish'
						) }
					</Text>
				</>
			) }

			{ isLoading && (
				<VStack spacing="3" className="safe-publish-bulk-import-progress">
					<Text>{ __( 'Importing posts as a batch…', 'safe-publish' ) }</Text>
					<ProgressBar value={ progress } />
					<Text style={ { fontSize: '0.8em', color: 'var(--safe-publish-text-muted)' } }>
						{ 100 === progress
							? __( 'Batch completed!', 'safe-publish' )
							: sprintf(
									/* translators: %d is the percentage complete */
									__( 'Processing… %d%% complete', 'safe-publish' ),
									Math.round( progress )
							  ) }
					</Text>
					<Text style={ { fontSize: '0.75em', color: 'var(--safe-publish-text-muted)' } }>
						{ sprintf(
							/* translators: %d is the number of selected posts */
							__(
								'All %d posts will be imported in a single session',
								'safe-publish'
							),
							posts.length
						) }
					</Text>
				</VStack>
			) }

			{ results &&
				( () => {
					const withWarnings = results.results.filter( hasWarnings );
					const visibleWarnings = withWarnings.slice(
						0,
						MAX_VISIBLE_MODAL_TITLES
					);
					const hiddenWarnings = withWarnings.length - visibleWarnings.length;

					return (
						<VStack spacing="3">
							<Text
								role="status"
								style={ { color: summaryColor, fontWeight: 'bold' } }
							>
								{ summaryHeading }
							</Text>
							<Text>
								{ sprintf(
									/* translators: 1: successful count, 2: total count */
									__( 'Imported: %1$d of %2$d posts', 'safe-publish' ),
									results.successful,
									results.total
								) }
							</Text>
							{ results.successful > 0 && (
								<Text style={ { fontSize: '0.9em', color: 'var(--safe-publish-text-muted)' } }>
									{ ( () => {
										const created = results.results.filter(
											( entry ) => entry.success && ! entry.existing
										).length;
										const updated = results.results.filter(
											( entry ) => entry.success && entry.existing
										).length;
										const parts: string[] = [];
										if ( created > 0 ) {
											parts.push(
												sprintf(
													/* translators: %d is the number of posts created */
													__( '%d created', 'safe-publish' ),
													created
												)
											);
										}
										if ( updated > 0 ) {
											parts.push(
												sprintf(
													/* translators: %d is the number of posts updated */
													__(
														'%d updated with latest content',
														'safe-publish'
													),
													updated
												)
											);
										}
										return parts.join( ', ' );
									} )() }
								</Text>
							) }
							{ withWarnings.length > 0 && (
								<div className="safe-publish-import-warnings-list">
									<Text className="safe-publish-warning-list-heading">
										{ sprintf(
											/* translators: %d is the number of imports with warnings */
											__( 'Completed with warnings (%d):', 'safe-publish' ),
											withWarnings.length
										) }
									</Text>
									<ul>
										{ visibleWarnings.map( ( result, index ) => {
											const reasons = ( result.warnings ?? [] )
												.map( renderWarningShortLabel )
												.join( ', ' );
											return (
												<li key={ index }>
													{ result.edit_url ? (
														<a
															href={ result.edit_url }
															target="_blank"
															rel="noreferrer"
														>
															{ result.title }
														</a>
													) : (
														<span>{ result.title }</span>
													) }
													{ ' — ' }
													{ reasons }
												</li>
											);
										} ) }
										{ hiddenWarnings > 0 && (
											<li>
												{ sprintf(
													/* translators: %d is the number of additional posts with warnings */
													__( '…and %d more', 'safe-publish' ),
													hiddenWarnings
												) }
											</li>
										) }
									</ul>
								</div>
							) }
							{ results.failed > 0 && (
								<Text style={ { color: 'var(--safe-publish-status-error)' } }>
									{ sprintf(
										/* translators: %d is the number of failed entries */
										__( 'Failed: %d', 'safe-publish' ),
										results.failed
									) }
								</Text>
							) }

							{ results.results.length > 0 && (
								<ScrollableRegion
									className="safe-publish-import-results"
									ariaLabel={ __( 'Import results', 'safe-publish' ) }
								>
									{ results.results.map( ( result, index ) => {
										const warned = hasWarnings( result );
										let titleClass: 'success' | 'warning' | 'error';
										if ( ! result.success ) {
											titleClass = 'error';
										} else if ( warned ) {
											titleClass = 'warning';
										} else {
											titleClass = 'success';
										}

										let status: string;
										if ( ! result.success ) {
											status = __( 'Failed', 'safe-publish' );
										} else if ( result.existing ) {
											status = warned
												? __( 'Updated (warning)', 'safe-publish' )
												: __( 'Updated', 'safe-publish' );
										} else {
											status = warned
												? __( 'Created (warning)', 'safe-publish' )
												: __( 'Created', 'safe-publish' );
										}

										return (
											<div
												key={ index }
												className="safe-publish-import-result-item"
											>
												<div className="safe-publish-result-text">
													<span
														className={ `safe-publish-result-title ${ titleClass }` }
													>
														{ result.title }
													</span>
													{ ! result.success && result.error && (
														<span className="safe-publish-result-error">
															{ result.error }
														</span>
													) }
												</div>
												<span className="safe-publish-result-status">
													{ status }
												</span>
											</div>
										);
									} ) }
								</ScrollableRegion>
							) }
						</VStack>
					);
				} )() }

			{ error && <Text role="alert" style={ { color: 'var(--safe-publish-status-error)' } }>{ error }</Text> }

			<HStack justify="right">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
					disabled={ isLoading }
					accessibleWhenDisabled
					ref={ closeButtonRef }
				>
					{ results
						? __( 'Close', 'safe-publish' )
						: __( 'Cancel', 'safe-publish' ) }
				</Button>
				{ ! results && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => void handleRun() }
						disabled={ isLoading }
						accessibleWhenDisabled
						data-action-id="import"
					>
						{ isLoading ? (
							<>
								<Spinner />
								{ __( 'Importing…', 'safe-publish' ) }
							</>
						) : (
							sprintf(
								/* translators: %d is the number of selected posts */
								__( 'Import %d posts', 'safe-publish' ),
								posts.length
							)
						) }
					</Button>
				) }
			</HStack>
		</VStack>
	);
}

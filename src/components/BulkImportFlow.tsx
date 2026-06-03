/**
 * Shared confirmation + progress + results modal for bulk import-shaped
 * operations. Backs the Source Posts bulk Import action and the Imports
 * tab bulk Update action; both ultimately POST to
 * `safe_publish_bulk_import`, which inserts new posts and updates
 * existing ones based on whether the source id resolves to a local post.
 *
 * @file This file defines the BulkImportFlow component.
 */

import { getErrorMessage, renderWarningShortLabel } from '../utils';
import {
	Button,
	ProgressBar,
	Spinner,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	BulkImportResponse,
	BulkImportResult,
} from '../types';

/**
 * Maximum number of warning entries shown in the bulk results titles list
 * before collapsing the remainder into a "…and N more" line.
 */
const MAX_VISIBLE_WARNING_TITLES = 10;

/**
 * Shape the bulk endpoint reads. Source Posts Imports pass the row's
 * full Post payload; the Imports tab maps ImportedPost down to this
 * minimum.
 */
export interface BulkImportFlowPost {
	id: number;
	post_type: string;
	title: string;
}

/**
 * Labels driving the modal's copy. Defaults are import-flavored; the
 * Update flow overrides verb-specific strings.
 */
export interface BulkImportFlowLabels {
	confirmQuestion: string;
	confirmDescription: string;
	processingHeading: string;
	processingFootnote: string;
	completedHeading: string;
	failedHeading: string;
	partialHeading: string;
	totalSummary: string;
	primaryButton: string;
	loadingButton: string;
	primaryActionId: string;
}

/**
 * Props for the BulkImportFlow modal body.
 *
 * @property {BulkImportFlowPost[]}  posts     Pre-mapped row payload.
 * @property {Object}                context   Admin-ajax URL + nonce.
 * @property {Function}              onClose   Modal close callback.
 * @property {Function?}             onRefresh Called after a successful run.
 * @property {BulkImportFlowLabels?} labels    Optional copy overrides.
 */
export interface BulkImportFlowProps {
	posts: BulkImportFlowPost[];
	context: { ajaxurl: string; nonce: string };
	onClose: () => void;
	onRefresh?: () => void;
	labels?: Partial< BulkImportFlowLabels >;
}

const DEFAULT_LABELS: BulkImportFlowLabels = {
	/* translators: %d is the number of selected posts */
	confirmQuestion: __( 'Import %d selected posts as drafts?', 'safe-publish' ),
	confirmDescription: __(
		'This will import all selected posts including their content, images, links, and formatting.',
		'safe-publish'
	),
	processingHeading: __( 'Importing posts as a batch…', 'safe-publish' ),
	/* translators: %d is the number of selected posts */
	processingFootnote: __(
		'All %d posts will be imported in a single session',
		'safe-publish'
	),
	completedHeading: __( 'Import completed!', 'safe-publish' ),
	failedHeading: __( 'Import failed', 'safe-publish' ),
	partialHeading: __( 'Import completed with errors', 'safe-publish' ),
	/* translators: 1: successful count, 2: total count */
	totalSummary: __( 'Imported: %1$d of %2$d posts', 'safe-publish' ),
	/* translators: %d is the number of selected posts */
	primaryButton: __( 'Import %d Posts', 'safe-publish' ),
	loadingButton: __( 'Importing…', 'safe-publish' ),
	primaryActionId: 'import',
};

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
	formData.append( 'posts_data', JSON.stringify( posts ) );

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
	context,
	onClose,
	onRefresh,
	labels: labelOverrides,
}: BulkImportFlowProps ): JSX.Element {
	const labels = { ...DEFAULT_LABELS, ...labelOverrides };

	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ progress, setProgress ] = useState( 0 );
	const [ results, setResults ] = useState< BulkImportResponse | null >( null );

	const handleRun = async (): Promise< void > => {
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

	const handleClose = (): void => {
		if ( results && results.successful > 0 ) {
			onRefresh?.();
		}
		onClose();
	};

	const allFailed =
		null !== results && 0 === results.successful && results.failed > 0;
	const partialFailure =
		null !== results && results.successful > 0 && results.failed > 0;

	let summaryHeading = labels.completedHeading;
	let summaryColor = '#008a20';
	if ( allFailed ) {
		summaryHeading = labels.failedHeading;
		summaryColor = '#d63638';
	} else if ( partialFailure ) {
		summaryHeading = labels.partialHeading;
		summaryColor = '#996800';
	}

	return (
		<VStack
			spacing="5"
			style={ { minWidth: '400px' } }
			className="safe-publish-bulk-import-modal"
		>
			{ ! results && (
				<>
					<Text>
						{
							// eslint-disable-next-line @wordpress/valid-sprintf -- format string is a translated literal passed as a label.
							sprintf( labels.confirmQuestion, posts.length )
						}
					</Text>
					<Text style={ { fontSize: '0.9em', color: '#666' } }>
						{ labels.confirmDescription }
					</Text>
				</>
			) }

			{ isLoading && (
				<VStack spacing="3" className="safe-publish-bulk-import-progress">
					<Text>{ labels.processingHeading }</Text>
					<ProgressBar value={ progress } />
					<Text style={ { fontSize: '0.8em', color: '#666' } }>
						{ 100 === progress
							? __( 'Batch completed!', 'safe-publish' )
							: sprintf(
									/* translators: %d is the percentage complete */
									__( 'Processing… %d%% complete', 'safe-publish' ),
									Math.round( progress )
							  ) }
					</Text>
					<Text style={ { fontSize: '0.75em', color: '#999' } }>
						{
							// eslint-disable-next-line @wordpress/valid-sprintf -- format string is a translated literal passed as a label.
							sprintf( labels.processingFootnote, posts.length )
						}
					</Text>
				</VStack>
			) }

			{ results &&
				( () => {
					const withWarnings = results.results.filter( hasWarnings );
					const visibleWarnings = withWarnings.slice(
						0,
						MAX_VISIBLE_WARNING_TITLES
					);
					const hiddenWarnings = withWarnings.length - visibleWarnings.length;

					return (
						<VStack spacing="3">
							<Text style={ { color: summaryColor, fontWeight: 'bold' } }>
								{ summaryHeading }
							</Text>
							<Text>
								{
									// eslint-disable-next-line @wordpress/valid-sprintf -- format string is a translated literal passed as a label.
									sprintf(
										labels.totalSummary,
										results.successful,
										results.total
									)
								}
							</Text>
							{ results.successful > 0 && (
								<Text style={ { fontSize: '0.9em', color: '#666' } }>
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
								<Text style={ { color: '#d63638' } }>
									{ sprintf(
										/* translators: %d is the number of failed entries */
										__( 'Failed: %d', 'safe-publish' ),
										results.failed
									) }
								</Text>
							) }

							{ results.results.length > 0 && (
								<div className="safe-publish-import-results">
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
								</div>
							) }
						</VStack>
					);
				} )() }

			{ error && <Text style={ { color: '#d63638' } }>{ error }</Text> }

			<HStack justify="right">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ handleClose }
					disabled={ isLoading }
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
						data-action-id={ labels.primaryActionId }
					>
						{ isLoading ? (
							<>
								<Spinner />
								{ labels.loadingButton }
							</>
						) : (
							// eslint-disable-next-line @wordpress/valid-sprintf -- format string is a translated literal passed as a label.
							sprintf( labels.primaryButton, posts.length )
						) }
					</Button>
				) }
			</HStack>
		</VStack>
	);
}

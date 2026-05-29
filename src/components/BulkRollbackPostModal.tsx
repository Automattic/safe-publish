/**
 * Bulk Rollback Post Modal component.
 *
 * Confirms and runs a rollback across multiple Imported Posts rows. A mixed
 * selection may both permanently delete newly created posts and restore
 * updated ones, so the confirmation summarizes the counts. Each row is rolled
 * back with its own request and the result reports which titles failed.
 *
 * @file This file defines the BulkRollbackPostModal component.
 */

import {
	isRollbackRestore,
	rollbackItems,
	type BulkRollbackResult,
} from '../api/rollback';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
	ProgressBar,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { ImportedPost } from '../types';

/**
 * Props for the BulkRollbackPostModal component.
 *
 * @property {ImportedPost[]} items        Eligible rows to roll back (length > 1).
 * @property {Function}       [closeModal] Callback to close the modal.
 * @property {Function}       [onRefresh]  Callback to refresh the listing.
 */
interface BulkRollbackPostModalProps {
	items: ImportedPost[];
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Confirmation and progress modal for rolling back several import events.
 *
 * @param {BulkRollbackPostModalProps} props Component props.
 */
const BulkRollbackPostModal = ( {
	items,
	closeModal,
	onRefresh,
}: BulkRollbackPostModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ completed, setCompleted ] = useState( 0 );
	const [ result, setResult ] = useState< BulkRollbackResult | null >( null );

	const total = items.length;
	const restoreCount = items.filter( isRollbackRestore ).length;
	const deleteCount = total - restoreCount;

	const handleRollback = () => {
		setIsLoading( true );
		setCompleted( 0 );

		void rollbackItems( items, done => setCompleted( done ) ).then(
			bulkResult => {
				setResult( bulkResult );
				setIsLoading( false );
			}
		);
	};

	const handleClose = () => {
		if ( result && result.successful > 0 ) {
			onRefresh?.();
		}
		closeModal?.();
	};

	const deletedLabel = ( count: number ): string =>
		sprintf(
			/* translators: %d: number of posts that will be permanently deleted */
			_n(
				'%d post will be permanently deleted',
				'%d posts will be permanently deleted',
				count,
				'safe-publish'
			),
			count
		);

	const restoredLabel = ( count: number ): string =>
		sprintf(
			/* translators: %d: number of posts that will be restored */
			_n(
				'%d post will be restored to its previous version',
				'%d posts will be restored to their previous version',
				count,
				'safe-publish'
			),
			count
		);

	const failures = result?.entries.filter( entry => ! entry.outcome.success );

	let summaryHeading = __( 'Roll back completed!', 'safe-publish' );
	let summaryColor = '#008a20';
	if ( result && 0 === result.successful ) {
		summaryHeading = __( 'Roll back failed', 'safe-publish' );
		summaryColor = '#d63638';
	} else if ( result && result.failed > 0 ) {
		summaryHeading = __( 'Roll back completed with errors', 'safe-publish' );
		summaryColor = '#996800';
	}

	return (
		<VStack spacing="5" style={ { minWidth: '400px' } }>
			{ ! result && ! isLoading && (
				<>
					<Text>
						{ sprintf(
							/* translators: %d: number of selected posts */
							_n(
								'Roll back %d selected post?',
								'Roll back %d selected posts?',
								total,
								'safe-publish'
							),
							total
						) }
					</Text>
					<VStack spacing="2">
						{ deleteCount > 0 && (
							<Text style={ { fontSize: '0.9em', color: '#666' } }>
								{ deletedLabel( deleteCount ) }
							</Text>
						) }
						{ restoreCount > 0 && (
							<Text style={ { fontSize: '0.9em', color: '#666' } }>
								{ restoredLabel( restoreCount ) }
							</Text>
						) }
					</VStack>
				</>
			) }

			{ isLoading && (
				<VStack spacing="3">
					<Text>{ __( 'Rolling back…', 'safe-publish' ) }</Text>
					<ProgressBar value={ Math.round( ( completed / total ) * 100 ) } />
					<Text style={ { fontSize: '0.8em', color: '#666' } }>
						{ sprintf(
							/* translators: 1: completed count, 2: total count */
							__( 'Rolled back %1$d of %2$d', 'safe-publish' ),
							completed,
							total
						) }
					</Text>
				</VStack>
			) }

			{ result && (
				<VStack spacing="3" role="status">
					<Text style={ { color: summaryColor, fontWeight: 'bold' } }>
						{ summaryHeading }
					</Text>
					<Text>
						{ sprintf(
							/* translators: 1: successful count, 2: total count */
							__( 'Rolled back %1$d of %2$d', 'safe-publish' ),
							result.successful,
							result.total
						) }
					</Text>
					{ failures && failures.length > 0 && (
						<div className="safe-publish-import-results">
							{ failures.map( ( entry, index ) => (
								<div
									key={ index }
									className="safe-publish-import-result-item"
								>
									<div className="safe-publish-result-text">
										<span className="safe-publish-result-title error">
											{ entry.item.title }
										</span>
										{ ! entry.outcome.success && (
											<span className="safe-publish-result-error">
												{ entry.outcome.error }
											</span>
										) }
									</div>
									<span className="safe-publish-result-status">
										{ __( 'Failed', 'safe-publish' ) }
									</span>
								</div>
							) ) }
						</div>
					) }
				</VStack>
			) }

			<HStack justify="right">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ handleClose }
					disabled={ isLoading }
				>
					{ result
						? __( 'Close', 'safe-publish' )
						: __( 'Cancel', 'safe-publish' ) }
				</Button>
				{ ! result && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						isDestructive={ deleteCount > 0 }
						onClick={ handleRollback }
						disabled={ isLoading }
					>
						{ isLoading ? (
							<>
								<Spinner />
								{ __( 'Rolling back…', 'safe-publish' ) }
							</>
						) : (
							sprintf(
								/* translators: %d: number of selected posts */
								_n(
									'Roll back %d post',
									'Roll back %d posts',
									total,
									'safe-publish'
								),
								total
							)
						) }
					</Button>
				) }
			</HStack>
		</VStack>
	);
};

export default BulkRollbackPostModal;

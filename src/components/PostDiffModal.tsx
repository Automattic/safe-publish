/**
 * Post Diff Modal component.
 *
 * Displays a modal with a visual comparison between the current post content
 * and incoming source content.
 *
 * @file This file defines the PostDiffModal component.
 */

import BlockDiffViewer from './BlockDiffViewer';
import DiffViewSelector from './DiffViewSelector';
import NonContentDiffSections from './NonContentDiffSections';
import { useDiffPreview } from './hooks/useDiffPreview';
import { useImportPost } from './hooks/useImportPost';
import { useRefreshOnUnmount } from './hooks/useRefreshOnUnmount';
import { ImportedPost, ImportSyncStatus } from '../types';
import { renderWarningMessage } from '../utils';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Props for the PostDiffModal component.
 *
 * @property {ImportedPost[]}               items      Array containing the single row to diff.
 * @property {string}                       restNonce  REST API nonce for the diff endpoint.
 * @property {string}                       ajaxurl    WordPress admin-ajax URL (for Update Post).
 * @property {string}                       nonce      AJAX nonce for the create-draft endpoint.
 * @property {ImportSyncStatus | undefined} syncStatus Row's sync verdict; gates the Update Post button.
 * @property {Function}                     closeModal Callback to close the modal.
 * @property {Function}                     onRefresh  Callback to refresh the listing after an update.
 */
interface PostDiffModalProps {
	items: ImportedPost[];
	restNonce: string;
	ajaxurl: string;
	nonce: string;
	syncStatus: ImportSyncStatus | undefined;
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Post Diff Modal component.
 *
 * Fetches and displays a diff preview for the selected post, showing content
 * changes, block-level diffs, and non-content field changes.
 *
 * @param {PostDiffModalProps} props Component props.
 *
 * @return {JSX.Element} Rendered modal content.
 */
export default function PostDiffModal( {
	items,
	restNonce,
	ajaxurl,
	nonce,
	syncStatus,
	closeModal,
	onRefresh,
}: PostDiffModalProps ): JSX.Element {
	const firstItem = items[ 0 ];

	const {
		diffHtml,
		renderedDiffHtml,
		blockDiffs,
		nonContentDiffs,
		isLoading,
		error,
	} = useDiffPreview( {
		postId: firstItem.source_post_id,
		postType: firstItem.post_type,
		restNonce,
	} );

	const {
		isLoading: isUpdating,
		error: updateError,
		editUrl,
		warnings,
		submit: submitUpdate,
	} = useImportPost( {
		sourcePostId: firstItem.source_post_id,
		title: firstItem.title,
		sourceLink: firstItem.source_link,
		postType: firstItem.post_type,
		isUpdate: true,
		ajaxurl,
		nonce,
	} );

	const [ showBlockView, setShowBlockView ] = useState( true );
	const [ showRenderedDiff, setShowRenderedDiff ] = useState( true );

	const updateSucceeded = null !== editUrl;
	// Mirror the Update menu item's gating in actions.tsx.
	const isUpToDate = 'up-to-date' === syncStatus;

	useRefreshOnUnmount( updateSucceeded, onRefresh );

	return (
		<VStack>
			<Text>{ `Comparing "${ firstItem.title }"` }</Text>

			{ isLoading && (
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading diff…', 'safe-publish' ) }</Text>
				</HStack>
			) }

			{ error && <Text style={ { color: '#d63638' } }>{ error }</Text> }

			<Text as="h2">{ __( 'Content Diff', 'safe-publish' ) }</Text>

			<DiffViewSelector
				showBlockView={ showBlockView }
				showRenderedDiff={ showRenderedDiff }
				hasRenderedDiffHtml={ Boolean( renderedDiffHtml ) }
				hasDiffHtml={ Boolean( diffHtml ) }
				onViewChange={ ( blockView, renderedDiff ) => {
					setShowBlockView( blockView );
					setShowRenderedDiff( renderedDiff );
				} }
			/>

			{ showBlockView && (
				<div style={ { marginTop: 12, maxHeight: '55vh', overflowY: 'auto' } }>
					<BlockDiffViewer blocks={ blockDiffs } />
				</div>
			) }

			{ ! showBlockView && showRenderedDiff && renderedDiffHtml && (
				<div
					style={ {
						marginTop: 12,
						maxHeight: '50vh',
						overflowY: 'auto',
						background: '#f6faff',
						border: '1px solid #c3d8ff',
						padding: 16,
					} }
					dangerouslySetInnerHTML={ { __html: renderedDiffHtml } }
				/>
			) }

			{ ! showBlockView && ! showRenderedDiff && diffHtml && (
				<div
					style={ {
						marginTop: 12,
						maxHeight: '50vh',
						overflowY: 'auto',
						background: '#fafafa',
						border: '1px solid #eee',
						padding: 16,
					} }
					dangerouslySetInnerHTML={ { __html: diffHtml } }
				/>
			) }

			<NonContentDiffSections nonContentDiffs={ nonContentDiffs } />

			{ updateSucceeded && (
				<VStack spacing="2">
					<Text style={ { color: '#008a20' } }>
						{ sprintf(
							/* translators: %s is the post title */
							__(
								'"%s" has been updated — the changes shown above are now live.',
								'safe-publish'
							),
							firstItem.title
						) }
					</Text>
					{ warnings.length > 0 && (
						<VStack spacing="2" className="safe-publish-import-warnings" role="status">
							{ warnings.map( ( warning, index ) => (
								<Text key={ index } className="safe-publish-import-warning">
									{ renderWarningMessage( warning ) }
								</Text>
							) ) }
						</VStack>
					) }
				</VStack>
			) }

			<HStack justify="right">
				{ updateError && <Text style={ { color: '#d63638' } }>{ updateError }</Text> }
				{ ! error && ! updateSucceeded && ! isUpToDate && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ submitUpdate }
						disabled={ isUpdating || isLoading }
						style={ { marginLeft: 8 } }
					>
						{ isUpdating ? <Spinner /> : __( 'Update Post', 'safe-publish' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					disabled={ isUpdating }
				>
					{ __( 'Close', 'safe-publish' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

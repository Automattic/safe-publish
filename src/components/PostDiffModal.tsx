/**
 * Post Diff Modal component.
 *
 * Displays a modal with a visual comparison between the current post content
 * and incoming external content, with options to update.
 *
 * @file This file defines the PostDiffModal component.
 */

import BlockDiffViewer from './BlockDiffViewer';
import DiffViewSelector from './DiffViewSelector';
import NonContentDiffSections from './NonContentDiffSections';
import UpdateOptionsPanel from './UpdateOptionsPanel';
import { useDiffPreview } from './hooks/useDiffPreview';
import { usePostUpdate } from './hooks/usePostUpdate';
import { Post } from '../types';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Props for the PostDiffModal component.
 *
 * @property {Post[]}   items      Array containing the post to diff.
 * @property {Function} closeModal Callback to close the modal.
 */
interface PostDiffModalProps {
	items: Post[];
	closeModal?: () => void;
}

/**
 * Post Diff Modal component.
 *
 * Fetches and displays a diff preview for the selected post, showing content
 * changes, block-level diffs, and non-content field changes.
 *
 * @param {Object}   props            Component props.
 * @param {Post[]}   props.items      Array containing the post to diff.
 * @param {Function} props.closeModal Callback to close the modal.
 * @return {JSX.Element} Rendered modal content.
 */
export default function PostDiffModal( { items, closeModal }: PostDiffModalProps ): JSX.Element {
	const firstItem = items[ 0 ];

	const {
		diffHtml,
		renderedDiffHtml,
		blockDiffs,
		nonContentDiffs,
		incoming,
		localPostId,
		isLoading,
		error,
	} = useDiffPreview( {
		postId: firstItem.id,
		postType: firstItem.post_type,
		content: firstItem.content,
		excerpt: firstItem.excerpt,
	} );

	const {
		updateOpts,
		setUpdateOpts,
		isUpdating,
		updateError,
		updateSuccess,
		handleUpdatePost,
	} = usePostUpdate( {
		localPostId,
		content: firstItem.content || firstItem.excerpt || '',
		featuredMediaId: firstItem.featured_media,
		incoming,
	} );

	const [ showBlockView, setShowBlockView ] = useState( true );
	const [ showRenderedDiff, setShowRenderedDiff ] = useState( true );

	useEffect( () => {
		if ( updateSuccess ) {
			const timer = setTimeout( () => {
				closeModal?.();
			}, 500 );
			return () => clearTimeout( timer );
		}
	}, [ updateSuccess, closeModal ] );

	return (
		<VStack>
			<Text>{ `Diff for "${ items[ 0 ].title }"` }</Text>

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

			<UpdateOptionsPanel updateOpts={ updateOpts } onChange={ setUpdateOpts } />

			<HStack justify="right">
				{ updateError && <Text style={ { color: '#d63638' } }>{ updateError }</Text> }
				{ updateSuccess && <Text style={ { color: '#008a20' } }>{ updateSuccess }</Text> }
				{ ! error && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => void handleUpdatePost() }
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

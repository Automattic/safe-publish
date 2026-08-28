/**
 * Post Diff Modal component.
 *
 * Displays a modal with a visual comparison between the current post content
 * and incoming source content.
 *
 * @file This file defines the PostDiffModal component.
 */

import {
	Button,
	ToggleControl,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import BlockDiffViewer, { resolveStatus } from './BlockDiffViewer';
import DiffViewSelector from './DiffViewSelector';
import NonContentDiffSections from './NonContentDiffSections';
import { UnifiedPostRow, ImportSyncStatus, Warning } from '../types';
import { renderWarningMessage } from '../utils';
import { useDiffPreview } from './hooks/useDiffPreview';
import { useImportPost } from './hooks/useImportPost';
import { useRefreshOnUnmount } from './hooks/useRefreshOnUnmount';

import type { BlockDiff, DiffPreviewResult } from '../api/diff';

/**
 * Props for the PostDiffModal component.
 *
 * @property {UnifiedPostRow[]}             items      Array containing the single row to diff.
 * @property {string}                       ajaxurl    WordPress admin-ajax URL (for the Update button).
 * @property {string}                       nonce      AJAX nonce for the create-draft endpoint.
 * @property {ImportSyncStatus | undefined} syncStatus Row's sync verdict; gates the Update button.
 * @property {Function}                     onRefresh  Callback to refresh the listing after an update.
 */
interface PostDiffModalProps {
	items: UnifiedPostRow[];
	ajaxurl: string;
	nonce: string;
	syncStatus: ImportSyncStatus | undefined;
	onRefresh?: () => void;
}

/**
 * Props for the header bar subcomponent.
 *
 * @property {string | null} diffHtml         Source diff HTML.
 * @property {boolean}       showBlockView    View selection state.
 * @property {boolean}       showUnchanged    Whether unchanged content is revealed.
 * @property {boolean}       showFullSize     Whether the image-height cap is dropped.
 * @property {boolean}       showLabels       Whether block-card headers are visible.
 * @property {boolean}       hasImages        Whether the diff contains any images.
 * @property {boolean}       showViewOptions  Whether the view-option toggles should render.
 * @property {boolean}       showUpdateButton Whether the Update button should render.
 * @property {boolean}       isUpdating       Update submit in progress.
 * @property {boolean}       isLoading        Diff fetch in progress.
 * @property {boolean}       updateSucceeded  Update has completed successfully.
 * @property {string | null} updateError      Error from the Update submit, if any.
 * @property {Function}      onViewChange     Selector change callback.
 * @property {Function}      onShowUnchanged  Toggle change callback.
 * @property {Function}      onShowFullSize   Toggle change callback.
 * @property {Function}      onShowLabels     Toggle change callback.
 * @property {Function}      onSubmitUpdate   Update click handler.
 */
interface HeaderBarProps {
	diffHtml: string | null;
	showBlockView: boolean;
	showUnchanged: boolean;
	showFullSize: boolean;
	showLabels: boolean;
	hasImages: boolean;
	showViewOptions: boolean;
	showUpdateButton: boolean;
	isUpdating: boolean;
	isLoading: boolean;
	updateSucceeded: boolean;
	updateError: string | null;
	onViewChange: ( showBlockView: boolean ) => void;
	onShowUnchanged: ( value: boolean ) => void;
	onShowFullSize: ( value: boolean ) => void;
	onShowLabels: ( value: boolean ) => void;
	onSubmitUpdate: () => void;
}

/**
 * Single sticky header bar with view selector, view toggles, and the
 * Update action with inline status text.
 *
 * @param {HeaderBarProps} props Component props.
 *
 * @return {JSX.Element} Rendered header bar.
 */
function HeaderBar( {
	diffHtml,
	showBlockView,
	showUnchanged,
	showFullSize,
	showLabels,
	hasImages,
	showViewOptions,
	showUpdateButton,
	isUpdating,
	isLoading,
	updateSucceeded,
	updateError,
	onViewChange,
	onShowUnchanged,
	onShowFullSize,
	onShowLabels,
	onSubmitUpdate,
}: HeaderBarProps ): JSX.Element {
	const successMessage = showViewOptions
		? __( 'Update applied. Some differences remain.', 'safe-publish' )
		: __( 'Update applied.', 'safe-publish' );

	return (
		<HStack
			className="safe-publish-compare-modal__toggles"
			justify="flex-start"
			alignment="center"
			spacing={ 6 }
			expanded={ false }
		>
			{ showViewOptions && (
				<>
					<DiffViewSelector
						showBlockView={ showBlockView }
						hasDiffHtml={ Boolean( diffHtml ) }
						onViewChange={ onViewChange }
					/>
					{ showBlockView && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={
								<span style={ { whiteSpace: 'nowrap' } }>
									{ __( 'Show labels', 'safe-publish' ) }
								</span>
							}
							checked={ showLabels }
							onChange={ onShowLabels }
						/>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={
							<span style={ { whiteSpace: 'nowrap' } }>
								{ __( 'Show unchanged', 'safe-publish' ) }
							</span>
						}
						checked={ showUnchanged }
						onChange={ onShowUnchanged }
					/>
					{ hasImages && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={
								<span style={ { whiteSpace: 'nowrap' } }>
									{ __( 'Larger images', 'safe-publish' ) }
								</span>
							}
							checked={ showFullSize }
							onChange={ onShowFullSize }
						/>
					) }
				</>
			) }
			{ updateError && (
				<Text style={ { color: 'var(--safe-publish-status-error)', whiteSpace: 'nowrap' } }>
					{ updateError }
				</Text>
			) }
			{ showUpdateButton && (
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ onSubmitUpdate }
					disabled={ isUpdating || isLoading }
					accessibleWhenDisabled
				>
					{ isUpdating ? (
						<Spinner style={ { margin: 0 } } />
					) : (
						__( 'Update', 'safe-publish' )
					) }
				</Button>
			) }
			{ updateSucceeded && ! isUpdating && (
				<Text style={ { color: 'var(--safe-publish-status-success)', whiteSpace: 'nowrap' } }>
					{ successMessage }
				</Text>
			) }
		</HStack>
	);
}

/**
 * Props for the diff body subcomponent.
 *
 * @property {string | null}                        diffHtml        Source diff HTML.
 * @property {BlockDiff[]}                          blockDiffs      Block-level diffs.
 * @property {DiffPreviewResult['nonContentDiffs']} nonContentDiffs Non-content field diffs.
 * @property {boolean}                              showBlockView   View selection state.
 * @property {boolean}                              showUnchanged   Whether unchanged content is revealed.
 * @property {boolean}                              showLabels      Whether block-card headers are visible.
 */
interface DiffBodyProps {
	diffHtml: string | null;
	blockDiffs: BlockDiff[];
	nonContentDiffs: DiffPreviewResult['nonContentDiffs'];
	showBlockView: boolean;
	showUnchanged: boolean;
	showLabels: boolean;
}

/**
 * Diff body — the active view plus non-content sections.
 *
 * @param {DiffBodyProps} props Component props.
 *
 * @return {JSX.Element} Rendered diff body.
 */
function DiffBody( {
	diffHtml,
	blockDiffs,
	nonContentDiffs,
	showBlockView,
	showUnchanged,
	showLabels,
}: DiffBodyProps ): JSX.Element {
	const blockHasChanges = blockDiffs.some(
		( block ) => resolveStatus( block ) !== 'unchanged'
	);
	const showPostDetails =
		hasAnyNonContentDiff( nonContentDiffs ) || showUnchanged;
	const showContentSection = showBlockView
		? blockHasChanges || showUnchanged
		: Boolean( diffHtml );

	return (
		<>
			{ showPostDetails && (
				<section className="safe-publish-compare-modal__section safe-publish-compare-modal__section--details">
					<Text as="h2">
						{ __( 'Post Details', 'safe-publish' ) }
					</Text>
					<NonContentDiffSections
						nonContentDiffs={ nonContentDiffs }
						showUnchanged={ showUnchanged }
					/>
				</section>
			) }

			{ showContentSection && (
				<section className="safe-publish-compare-modal__section safe-publish-compare-modal__section--content">
					<Text as="h2">{ __( 'Post Content', 'safe-publish' ) }</Text>
					{ showBlockView ? (
						<BlockDiffViewer
							blocks={ blockDiffs }
							showUnchanged={ showUnchanged }
							showLabels={ showLabels }
						/>
					) : (
						diffHtml && (
							<div
								style={ {
									background: 'var(--safe-publish-diff-surface-bg)',
									border: '1px solid var(--safe-publish-diff-surface-border)',
									padding: 16,
								} }
								dangerouslySetInnerHTML={ {
									__html: diffHtml,
								} }
							/>
						)
					) }
				</section>
			) }
		</>
	);
}

/**
 * Props for the warnings banner.
 *
 * @property {Warning[]} warnings Non-fatal warnings raised by the backend.
 */
interface WarningsBannerProps {
	warnings: Warning[];
}

/**
 * Renders the post-Update warnings list. Only rendered when warnings exist;
 * the short success message itself lives in the sticky header.
 *
 * @param {WarningsBannerProps} props Component props.
 *
 * @return {JSX.Element} Rendered warnings list.
 */
function WarningsBanner( { warnings }: WarningsBannerProps ): JSX.Element {
	return (
		<VStack
			spacing="2"
			className="safe-publish-import-warnings"
			role="status"
		>
			{ warnings.map( ( warning, index ) => (
				<Text key={ index } className="safe-publish-import-warning">
					{ renderWarningMessage( warning ) }
				</Text>
			) ) }
		</VStack>
	);
}

/**
 * True when any non-content section carries diff HTML.
 *
 * @param {DiffPreviewResult['nonContentDiffs']} nonContentDiffs Diff data.
 *
 * @return {boolean} Whether at least one section has changes.
 */
function hasAnyNonContentDiff(
	nonContentDiffs: DiffPreviewResult['nonContentDiffs']
): boolean {
	if ( ! nonContentDiffs ) {
		return false;
	}
	return Boolean(
		nonContentDiffs.title ||
			nonContentDiffs.excerpt ||
			nonContentDiffs.taxonomies ||
			nonContentDiffs.meta ||
			nonContentDiffs.featuredMedia
	);
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
// eslint-disable-next-line complexity -- modal aggregates many independent rendering branches.
export default function PostDiffModal( {
	items,
	ajaxurl,
	nonce,
	syncStatus,
	onRefresh,
}: PostDiffModalProps ): JSX.Element {
	const firstItem = items[ 0 ];
	// PostDiffModal is only opened for rows that resolved a source_post_id;
	// the null fallback is unreachable but keeps the type aligned with the
	// unified row's nullable column shape.
	const sourcePostId = firstItem.source_post_id ?? 0;

	const {
		diffHtml,
		blockDiffs,
		nonContentDiffs,
		isLoading,
		error,
		refetch,
	} = useDiffPreview( {
		postId: sourcePostId,
		postType: firstItem.post_type,
	} );

	const {
		isLoading: isUpdating,
		error: updateError,
		editUrl,
		warnings,
		submit: submitUpdate,
	} = useImportPost( {
		sourcePostId,
		title: firstItem.title,
		sourceLink: firstItem.link,
		postType: firstItem.post_type,
		isUpdate: true,
		ajaxurl,
		nonce,
		onSuccess: refetch,
	} );

	const [ showBlockView, setShowBlockView ] = useState( true );
	const [ showUnchanged, setShowUnchanged ] = useState( false );
	const [ showFullSize, setShowFullSize ] = useState( false );
	const [ showLabels, setShowLabels ] = useState( true );
	const [ attempted, setAttempted ] = useState( false );

	const updateSucceeded = null !== editUrl;
	// Mirror the Update menu item's gating in actions.tsx.
	const isUpToDate = 'up-to-date' === syncStatus;

	useRefreshOnUnmount( attempted, onRefresh );

	const handleSubmitUpdate = (): void => {
		setAttempted( true );
		submitUpdate();
	};

	const hasAnyChanges =
		Boolean( diffHtml ) ||
		blockDiffs.some( ( block ) => resolveStatus( block ) !== 'unchanged' ) ||
		hasAnyNonContentDiff( nonContentDiffs );
	const hasImages =
		Boolean( nonContentDiffs?.featuredMedia ) ||
		blockDiffs.some(
			( block ) =>
				/<img\s/i.test( block.current?.rendered || '' ) ||
				/<img\s/i.test( block.incoming?.rendered || '' )
		);
	// Keep the previous diff visible during refetch so the body doesn't
	// flicker between updates.
	const hasPreviousData =
		blockDiffs.length > 0 ||
		nonContentDiffs !== undefined ||
		diffHtml !== null;
	const ready = ! error && ( ! isLoading || hasPreviousData );
	const showEmptyState = ready && ! hasAnyChanges;
	const showDiffBody = ready && hasAnyChanges;

	const modalClassName = showFullSize
		? 'safe-publish-compare-modal'
		: 'safe-publish-compare-modal safe-publish-compare-modal--capped';

	return (
		<VStack className={ modalClassName }>
			<HeaderBar
				diffHtml={ diffHtml }
				showBlockView={ showBlockView }
				showUnchanged={ showUnchanged }
				showFullSize={ showFullSize }
				showLabels={ showLabels }
				hasImages={ hasImages }
				showViewOptions={ showDiffBody }
				showUpdateButton={
					! error && ! isUpToDate && hasAnyChanges
				}
				isUpdating={ isUpdating }
				isLoading={ isLoading }
				updateSucceeded={ updateSucceeded }
				updateError={ updateError }
				onViewChange={ setShowBlockView }
				onShowUnchanged={ setShowUnchanged }
				onShowFullSize={ setShowFullSize }
				onShowLabels={ setShowLabels }
				onSubmitUpdate={ handleSubmitUpdate }
			/>

			{ isLoading && ! hasPreviousData && (
				<HStack justify="flex-start">
					<Spinner />
					<Text>{ __( 'Loading diff…', 'safe-publish' ) }</Text>
				</HStack>
			) }

			{ error && <Text style={ { color: 'var(--safe-publish-status-error)' } }>{ error }</Text> }

			{ showDiffBody && (
				<DiffBody
					diffHtml={ diffHtml }
					blockDiffs={ blockDiffs }
					nonContentDiffs={ nonContentDiffs }
					showBlockView={ showBlockView }
					showUnchanged={ showUnchanged }
					showLabels={ showLabels }
				/>
			) }

			{ showEmptyState && (
				<Text>
					{ __( 'No differences detected.', 'safe-publish' ) }
				</Text>
			) }

			{ updateSucceeded && warnings.length > 0 && (
				<WarningsBanner warnings={ warnings } />
			) }
		</VStack>
	);
}

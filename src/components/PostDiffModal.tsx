/**
 * Post Diff Modal component.
 *
 * Displays a modal with a visual comparison between the current post content
 * and incoming external content, with options to update.
 *
 * @file This file defines the PostDiffModal component.
 */

import BlockDiffViewer from './BlockDiffViewer';
import { fetchDiffPreview, updatePostContent } from '../api/diff';
import { Post } from '../types';
import { getErrorMessage } from '../utils';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
	CheckboxControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { BlockDiff, DiffPreviewResult } from '../api/diff';

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
 *
 * @return {JSX.Element} Rendered modal content.
 */
export default function PostDiffModal( { items, closeModal }: PostDiffModalProps ): JSX.Element {
	const [ diffHtml, setDiffHtml ] = useState< string | null >( null ); // Will hold contentDiffHtml.
	const [ nonContentDiffs, setNonContentDiffs ] = useState< DiffPreviewResult['nonContentDiffs'] >( undefined );
	const [ incoming, setIncoming ] = useState< DiffPreviewResult['incoming'] >( undefined );
	const [ localPostId, setLocalPostId ] = useState< number >( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ isUpdating, setIsUpdating ] = useState( false );
	const [ updateError, setUpdateError ] = useState< string | null >( null );
	const [ updateSuccess, setUpdateSuccess ] = useState< string | null >( null );
	const [ renderedDiffHtml, setRenderedDiffHtml ] = useState< string | null >( null );
	const [ showRenderedDiff, setShowRenderedDiff ] = useState< boolean >( true );

	const [ blockDiffs, setBlockDiffs ] = useState< BlockDiff[] >( [] );
	const [ showBlockView, setShowBlockView ] = useState< boolean >( true );

	const [ updateOpts, setUpdateOpts ] = useState< {
		title: boolean;
		excerpt: boolean;
		meta: boolean;
		terms: boolean;
		featuredMedia: boolean;
	} >( {
        title: true,
        excerpt: true,
        meta: true,
        terms: true,
        featuredMedia: true,
    } );

	const firstItemId = items[ 0 ].id;
	const firstItemPostType = items[ 0 ].post_type;
	const firstItemContent = items[ 0 ].content;
	const firstItemExcerpt = items[ 0 ].excerpt;

	useEffect( () => {
		let mounted = true;
		const fetchDiff = async () => {
			setIsLoading( true );
			setError( null );
			const result = await fetchDiffPreview( {
				postId: firstItemId,
				postType: firstItemPostType,
				content: firstItemContent || firstItemExcerpt || '',
				mode: 'split',
				cleanup: true,
			} );
			if ( ! mounted ) { return; }
			if ( result.error ) {
				setError( result.error );
			} else if ( ( result.contentDiffHtml || result.html ) && result.localPostId ) {
				// Prefer new contentDiffHtml, fallback to legacy html.
				setDiffHtml( result.contentDiffHtml ?? result.html ?? null );
				setLocalPostId( result.localPostId ?? 0 );

				// Structured incoming data for updates.
				setIncoming( result.incoming ?? undefined );

				// Non-content diffs (title/excerpt/tax/meta).
				setNonContentDiffs( result.nonContentDiffs ?? undefined );

				setRenderedDiffHtml( result.renderedContentDiffHtml ?? null );

				setBlockDiffs( result.blockDiffs || [] );
			} else {
				setError( __( 'No diff available.', 'ccp' ) );
			}
			if ( mounted ) { setIsLoading( false ); }
		};
		void fetchDiff();
		return () => {
			mounted = false;
		};
	}, [ firstItemId, firstItemPostType, firstItemContent, firstItemExcerpt ] );

	/**
	 * Handles updating the local post with incoming content.
	 *
	 * Sends the selected content fields (title, excerpt, meta, terms, featured
	 * media) to the REST API to update the local post.
	 */
	const handleUpdatePost = async () => {
		setIsUpdating( true );
        setUpdateError( null );
        setUpdateSuccess( null );

        // Build payload conditionally.
        const maybeMeta = updateOpts.meta ? ( incoming?.meta ?? undefined ) : undefined;
        const maybeTerms = updateOpts.terms ? ( incoming?.terms ?? undefined ) : undefined;
        const maybeTitle = updateOpts.title ? ( incoming?.title ?? undefined ) : undefined;
        const maybeExcerpt = updateOpts.excerpt ? ( incoming?.excerpt ?? undefined ) : undefined;
        const maybeFeaturedId =
            updateOpts.featuredMedia && typeof items[ 0 ].featured_media === 'number'
                ? items[ 0 ].featured_media
                : undefined;

        // Optional: strip internal meta keys on send (safety).
        const metaToSend =
            maybeMeta && typeof maybeMeta === 'object'
                ? Object.fromEntries(
                        Object.entries( maybeMeta ).filter(
                            ( [ key ] ) => ! key.startsWith( 'ccp_' ) && ! key.startsWith( '_' )
                        )
                  )
                : undefined;

        const result = await updatePostContent(
            localPostId,
            // Content is always sent (required by the REST route).
            items[ 0 ].content || items[ 0 ].excerpt || '',
            window?.ccpAdminData?.restNonce,
            metaToSend,
            maybeTerms,
            maybeTitle,
            maybeExcerpt,
            maybeFeaturedId
        );

        if ( result.success ) {
            setUpdateSuccess( __( 'Post updated successfully.', 'ccp' ) );
            setTimeout( () => {
                closeModal?.();
            }, 500 );
        } else {
            setUpdateError( getErrorMessage( result, __( 'Failed to update post.', 'ccp' ) ) );
        }
        setIsUpdating( false );
	};

	return (
		<VStack>
			<Text>{ `Diff for "${ items[ 0 ].title }"` }</Text>

			{ isLoading && (
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading diff…', 'ccp' ) }</Text>
				</HStack>
			) }

			{ error && <Text style={ { color: '#d63638' } }>{ error }</Text> }

			<Text as="h2">{ __( 'Content Diff', 'ccp' ) }</Text>

			{ /* Content-only diff */ }
			<HStack style={ { gap: 8, marginTop: 12 } }>
				<Button
					variant={ showBlockView ? 'primary' : 'tertiary' }
					onClick={ () => setShowBlockView( true ) }
					size="small"
				>
					{ __( 'Block View', 'ccp' ) }
				</Button>
				{ renderedDiffHtml && (
					<Button
						variant={ ! showBlockView && showRenderedDiff ? 'primary' : 'tertiary' }
						onClick={ () => {
							setShowBlockView( false );
							setShowRenderedDiff( true );
						} }
						size="small"
					>
						{ __( 'Rendered Table Diff', 'ccp' ) }
					</Button>
				) }
				{ diffHtml && (
					<Button
						variant={ ! showBlockView && ! showRenderedDiff ? 'primary' : 'tertiary' }
						onClick={ () => {
							setShowBlockView( false );
							setShowRenderedDiff( false );
						} }
						size="small"
					>
						{ __( 'Source Diff', 'ccp' ) }
					</Button>
				) }
			</HStack>

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

			{ /* Title + excerpt diff shown separately (not part of content diff) */ }
			{ nonContentDiffs?.title && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Title Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.title } } />
				</section>
			) }

			{ nonContentDiffs?.excerpt && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Excerpt Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.excerpt } } />
				</section>
			) }

			{ /* Taxonomies diff */ }
			{ nonContentDiffs?.taxonomies && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Taxonomies Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.taxonomies } } />
				</section>
			) }

			{ /* Meta diff */ }
			{ nonContentDiffs?.meta && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Meta / Custom Fields Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.meta } } />
				</section>
			) }

			{ /* Featured image diff */ }
			{ nonContentDiffs?.featuredMedia && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Featured Image Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.featuredMedia } } />
				</section>
			) }

			<section style={ { marginTop: 16, borderTop: '1px solid #eee', paddingTop: 12 } }>
                <Text as="h2">{ __( 'What to update', 'ccp' ) }</Text>
                <VStack spacing="2" style={ { marginTop: 6 } }>
                    <CheckboxControl
                        label={ __( 'Title', 'ccp' ) }
                        checked={ updateOpts.title }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, title: Boolean(val) } ) ) }
                    />
                    <CheckboxControl
                        label={ __( 'Excerpt', 'ccp' ) }
                        checked={ updateOpts.excerpt }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, excerpt: Boolean(val) } ) ) }
                    />
                    <CheckboxControl
                        label={ __( 'Meta (custom fields)', 'ccp' ) }
                        checked={ updateOpts.meta }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, meta: Boolean(val) } ) ) }
                    />
                    <CheckboxControl
                        label={ __( 'Terms (taxonomies)', 'ccp' ) }
                        checked={ updateOpts.terms }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, terms: Boolean(val) } ) ) }
                    />
                    <CheckboxControl
                        label={ __( 'Featured Image', 'ccp' ) }
                        checked={ updateOpts.featuredMedia }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, featuredMedia: Boolean(val) } ) ) }
                    />
                    <Text style={ { fontSize: 12, color: '#666' } }>
                        { __( 'Content is always updated. Uncheck items above to skip updating them.', 'ccp' ) }
                    </Text>
                </VStack>
            </section>

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
						{ isUpdating ? <Spinner /> : __( 'Update Post', 'ccp' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					disabled={ isUpdating }
				>
					{ __( 'Close', 'ccp' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

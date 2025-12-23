/**
 * Post Diff Modal component.
 *
 * Displays a modal with a visual comparison between the current post content
 * and incoming external content, with options to update.
 *
 * @file This file defines the PostDiffModal component.
 */
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
	CheckboxControl,
} from '@wordpress/components';
import React, { useEffect, useState } from 'react';

import { fetchDiffPreview, updatePostContent } from '../api/diff';
import { Post } from '../types';
import BlockDiffViewer from './BlockDiffViewer';

/**
 * Props for the PostDiffModal component.
 *
 * @property {Post[]}   items      Array containing the post to diff.
 * @property {Function} closeModal Callback to close the modal.
 */
interface PostDiffModalProps {
	items: Post[];
	closeModal: () => void;
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
	const [ nonContentDiffs, setNonContentDiffs ] = useState< any >( null );
	const [ incoming, setIncoming ] = useState< any >( null );
	const [ current, setCurrent ] = useState< any >( null );
	const [ localPostId, setLocalPostId ] = useState< number >( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ isUpdating, setIsUpdating ] = useState( false );
	const [ updateError, setUpdateError ] = useState< string | null >( null );
	const [ updateSuccess, setUpdateSuccess ] = useState< string | null >( null );
	const [ rendered, setRendered ] = useState< { incoming?: string; current?: string } >( {} );
	const [ renderedDiffHtml, setRenderedDiffHtml ] = useState< string | null >( null );
	const [ showRenderedDiff, setShowRenderedDiff ] = useState< boolean >( true );

	const [ blockDiffs, setBlockDiffs ] = useState< any[] >( [] ); // Refine type later.
	const [ showBlockView, setShowBlockView ] = useState< boolean >( true );

	const [ updateOpts, setUpdateOpts ] = useState({
        title: true,
        excerpt: true,
        meta: true,
        terms: true,
        featuredMedia: true,
    });

	useEffect( () => {
		let mounted = true;
		const fetchDiff = async () => {
			setIsLoading( true );
			setError( null );
			const result = await fetchDiffPreview( {
				postId: items[ 0 ].id,
				postType: items[ 0 ].post_type,
				content: items[ 0 ].content || items[ 0 ].excerpt || '',
				mode: 'split',
				cleanup: true,
			} );
			if ( ! mounted ) return;
			if ( result.error ) {
				setError( result.error );
			} else if ( ( result.contentDiffHtml || result.html ) && result.localPostId ) {
				// Prefer new contentDiffHtml, fallback to legacy html.
				setDiffHtml( result.contentDiffHtml ?? result.html ?? null );
				setLocalPostId( result.localPostId ?? 0 );

				// Structured incoming/current.
				setIncoming( result.incoming ?? null );
				setCurrent( result.current ?? null );

				// Non-content diffs (title/excerpt/tax/meta).
				setNonContentDiffs( result.nonContentDiffs ?? null );

				setRendered( {
					incoming: result.incomingRenderedHtml,
					current: result.currentRenderedHtml,
				} );

				setRenderedDiffHtml( result.renderedContentDiffHtml ?? null );

				setBlockDiffs( result.blockDiffs || [] );
			} else {
				setError( 'No diff available.' );
			}
			if ( mounted ) setIsLoading( false );
		};
		fetchDiff();
		return () => {
			mounted = false;
		};
	}, [ items[ 0 ].id, items[ 0 ].post_type, items[ 0 ].content, items[ 0 ].excerpt ] );

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
            setUpdateSuccess( 'Post updated successfully.' );
            setTimeout( () => {
                closeModal();
            }, 500 );
        } else {
            setUpdateError( result.error || 'Failed to update post.' );
        }
        setIsUpdating( false );
	};

	return (
		<VStack>
			<Text>{ `Diff for "${ items[ 0 ].title }"` }</Text>

			{ isLoading && (
				<HStack>
					<Spinner />
					<Text>Loading diff...</Text>
				</HStack>
			) }

			{ error && <Text style={ { color: '#d63638' } }>{ error }</Text> }

			<Text as="h2">Content Diff</Text>

			{ /* Content-only diff */ }
			<HStack style={ { gap: 8, marginTop: 12 } }>
				<Button
					variant={ showBlockView ? 'primary' : 'tertiary' }
					onClick={ () => setShowBlockView( true ) }
					size="small"
				>
					Block View
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
						Rendered Table Diff
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
						Source Diff
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
					<Text as="h2">Title Diff</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.title } } />
				</section>
			) }

			{ nonContentDiffs?.excerpt && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">Excerpt Diff</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.excerpt } } />
				</section>
			) }

			{ /* Taxonomies diff */ }
			{ nonContentDiffs?.taxonomies && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">Taxonomies Diff</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.taxonomies } } />
				</section>
			) }

			{ /* Meta diff */ }
			{ nonContentDiffs?.meta && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">Meta / Custom Fields Diff</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.meta } } />
				</section>
			) }

			{ /* Featured image diff */ }
			{ nonContentDiffs?.featuredMedia && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">Featured Image Diff</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.featuredMedia } } />
				</section>
			) }

			<section style={ { marginTop: 16, borderTop: '1px solid #eee', paddingTop: 12 } }>
                <Text as="h2">What to update</Text>
                <VStack spacing="2" style={ { marginTop: 6 } }>
                    <CheckboxControl
                        label="Title"
                        checked={ updateOpts.title }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, title: !! val } ) ) }
                    />
                    <CheckboxControl
                        label="Excerpt"
                        checked={ updateOpts.excerpt }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, excerpt: !! val } ) ) }
                    />
                    <CheckboxControl
                        label="Meta (custom fields)"
                        checked={ updateOpts.meta }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, meta: !! val } ) ) }
                    />
                    <CheckboxControl
                        label="Terms (taxonomies)"
                        checked={ updateOpts.terms }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, terms: !! val } ) ) }
                    />
                    <CheckboxControl
                        label="Featured Image"
                        checked={ updateOpts.featuredMedia }
                        onChange={ ( val ) => setUpdateOpts( prev => ( { ...prev, featuredMedia: !! val } ) ) }
                    />
                    <Text style={ { fontSize: 12, color: '#666' } }>
                        Content is always updated. Uncheck items above to skip updating them.
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
						onClick={ handleUpdatePost }
						disabled={ isUpdating || isLoading }
						style={ { marginLeft: 8 } }
					>
						{ isUpdating ? <Spinner /> : 'Update Post' }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					disabled={ isUpdating }
				>
					Close
				</Button>
			</HStack>
		</VStack>
	);
}

/**
 * Block Diff Viewer component.
 *
 * Displays a visual comparison of Gutenberg blocks between the current and
 * incoming post content with inline highlighting of changes.
 *
 * @file This file defines the BlockDiffViewer component.
 */
import { Change, diffWords } from 'diff';

import { __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { BlockDiff } from '../api/diff';

/**
 * Props for the BlockDiffViewer component.
 *
 * @property {BlockDiff[]} [blocks]        Block diff objects to display.
 * @property {boolean}     [highlight]     Enable inline word-level highlighting.
 * @property {boolean}     [showUnchanged] When false, omit unchanged blocks.
 * @property {boolean}     [showLabels]    When false, omit each card's block-name + status header.
 */
interface Props {
	blocks?: BlockDiff[];
	highlight?: boolean;
	showUnchanged?: boolean;
	showLabels?: boolean;
}

/**
 * Highlights differences between two HTML strings.
 *
 * Uses word-level diffing to insert span elements with CSS classes
 * for added and removed content.
 *
 * @param {string} original Original HTML string.
 * @param {string} changed  Changed HTML string.
 *
 * @return {string} HTML string with diff highlighting spans.
 */
function highlightHtml( original: string, changed: string ): string {
	if ( original === changed ) { return changed; }
	const parts: Change[] = diffWords( original, changed );
	return parts
		.map( part => {
			let cls = '';
			if ( part.added ) {
				cls = 'safe-publish-inline-added';
			} else if ( part.removed ) {
				cls = 'safe-publish-inline-removed';
			}

			if ( ! cls ) { return part.value; }
			return `<span class="${ cls }">${ part.value }</span>`;
		} )
		.join( '' );
}

/**
 * Normalizes HTML for comparison.
 *
 * Removes loading attributes, decoding hints, and normalizes whitespace to
 * reduce false positives when comparing HTML content.
 *
 * @param {string} html HTML string to normalize.
 *
 * @return {string} Normalized HTML string.
 */
function normalizeHtml( html: string ): string {
    return html
        .trim()
        .replace(/\sloading=("|')lazy\1/gi, '')
        .replace(/\sdecoding=("|')async\1/gi, '')
        .replace(/\sfetchpriority=("|')high\1/gi, '')
        .replace(/wp-image-\d+/g, 'wp-image-XXX')
        .replace(/\s+/g, ' ')
        .replace(/\s+\/>/g, '/>')
        .trim();
}

/**
 * Wraps each image in rendered HTML with a new-tab anchor pointing at its
 * source URL. Images already nested inside an anchor are left alone.
 *
 * @param {string} html Rendered block HTML.
 *
 * @return {string} HTML with image links applied.
 */
function linkifyImages( html: string ): string {
    if ( ! html || typeof DOMParser === 'undefined' ) { return html; }

    const doc = new DOMParser().parseFromString( html, 'text/html' );
    doc.querySelectorAll( 'img' ).forEach( ( img ) => {
        const src = img.getAttribute( 'src' );
        if ( ! src || img.parentElement?.tagName === 'A' ) { return; }

        const link = doc.createElement( 'a' );
        link.setAttribute( 'href', src );
        link.setAttribute( 'target', '_blank' );
        link.setAttribute( 'rel', 'noopener noreferrer' );
        img.parentNode?.insertBefore( link, img );
        link.appendChild( img );
    } );

    return doc.body.innerHTML;
}

/**
 * Resolves the effective status for a block diff, downgrading false modified
 * flags to unchanged when normalized HTML matches.
 *
 * @param {BlockDiff} block Block diff entry.
 *
 * @return {BlockDiff['status']} Effective status.
 */
export function resolveStatus( block: BlockDiff ): BlockDiff['status'] {
    if ( block.status !== 'modified' ) {
        return block.status;
    }
    const currentHtml = block.current?.rendered || '';
    const incomingHtml = block.incoming?.rendered || '';
    if ( normalizeHtml( currentHtml ) === normalizeHtml( incomingHtml ) ) {
        return 'unchanged';
    }
    return 'modified';
}

/**
 * Block Diff Viewer component.
 *
 * Renders a visual comparison of Gutenberg blocks. By default, omits
 * unchanged blocks so the diff scans cleanly; pass showUnchanged to reveal
 * them. Pass showLabels=false to drop each card's block-name + status
 * header and reclaim the vertical space for content.
 *
 * @param {Object}      props                 Component props.
 * @param {BlockDiff[]} [props.blocks]        Block diff objects to display.
 * @param {boolean}     [props.highlight]     Enable inline word-level highlighting.
 * @param {boolean}     [props.showUnchanged] When false, omit unchanged blocks.
 * @param {boolean}     [props.showLabels]    When false, omit each card's block-name + status header.
 *
 * @return {JSX.Element} Rendered block diff viewer.
 */
export default function BlockDiffViewer( {
    blocks = [],
    highlight = true,
    showUnchanged = false,
    showLabels = true,
}: Props ): JSX.Element {
    const resolved = blocks.map( ( block ) => ( {
        block,
        status: resolveStatus( block ),
    } ) );

    const visible = showUnchanged
        ? resolved
        : resolved.filter( ( entry ) => entry.status !== 'unchanged' );

    if ( visible.length === 0 ) {
        return (
            <div className="safe-publish-block-diff-viewer">
                <Text>{ __( 'No block changes detected.', 'safe-publish' ) }</Text>
            </div>
        );
    }

    return (
        <div className="safe-publish-block-diff-viewer">
            { visible.map( ( { block, status } ) => {
                const key = `${ block.index }-${ block.status }`;
                const title = block.incoming?.name || block.current?.name || __( 'Block', 'safe-publish' );
                const rawCurrentHtml = block.current?.rendered || '';
                const rawIncomingHtml = block.incoming?.rendered || '';

                const hasImage =
                    /<img\s/i.test( rawCurrentHtml ) ||
                    /<img\s/i.test( rawIncomingHtml );

                // Linkify after highlight — diffing linkified HTML would
                // surface anchor wrappers as changes.
                let modifiedIncoming = rawIncomingHtml;
                if ( highlight && status === 'modified' && ! hasImage ) {
                    modifiedIncoming = highlightHtml( rawCurrentHtml, rawIncomingHtml );
                }

                const currentHtml = linkifyImages( rawCurrentHtml );
                const incomingHtml = linkifyImages( rawIncomingHtml );
                modifiedIncoming = linkifyImages( modifiedIncoming );

                return (
                    <div key={ key } className="safe-publish-block-diff">
                        { showLabels && (
                            <div className="safe-publish-block-diff__header">
                                <Text>{ title }</Text>
                                <span className={ `safe-publish-badge safe-publish-${ status }` }>{ status }</span>
                                { hasImage && block.status === 'modified' && status !== 'unchanged' && (
                                    <span className="safe-publish-badge safe-publish-badge--neutral">
                                        image (no inline diff)
                                    </span>
                                ) }
                            </div>
                        ) }
                        { status === 'removed' && (
                            <div className="safe-publish-block-diff__removed" dangerouslySetInnerHTML={ { __html: currentHtml } } />
                        ) }
                        { status === 'added' && (
                            <div className="safe-publish-block-diff__added" dangerouslySetInnerHTML={ { __html: incomingHtml } } />
                        ) }
                        { status === 'unchanged' && (
                            <div className="safe-publish-block-diff__unchanged" dangerouslySetInnerHTML={ { __html: currentHtml } } />
                        ) }
                        { status === 'modified' && (
                            <div className="safe-publish-block-diff__modified">
                                <div className="safe-publish-block-diff__col" dangerouslySetInnerHTML={ { __html: currentHtml } } />
                                <div className="safe-publish-block-diff__col" dangerouslySetInnerHTML={ { __html: modifiedIncoming } } />
                            </div>
                        ) }
                    </div>
                );
            } ) }
        </div>
    );
}

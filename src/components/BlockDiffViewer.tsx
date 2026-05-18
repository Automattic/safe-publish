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
 * @property {BlockDiff[]} [blocks]    Block diff objects to display.
 * @property {boolean}     [highlight] Enable inline word-level highlighting.
 */
interface Props {
	blocks?: BlockDiff[];
	highlight?: boolean;
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
 * Block Diff Viewer component.
 *
 * Renders a visual comparison of Gutenberg blocks showing added, removed,
 * modified, and unchanged blocks with inline highlighting.
 *
 * @param {Object}      props             Component props.
 * @param {BlockDiff[]} [props.blocks]    Block diff objects to display.
 * @param {boolean}     [props.highlight] Enable inline word-level highlighting.
 *
 * @return {JSX.Element} Rendered block diff viewer.
 */
export default function BlockDiffViewer( { blocks = [], highlight = true }: Props ): JSX.Element {
    return (
        <div className="safe-publish-block-diff-viewer">
            { blocks.map( block => {
                let status = block.status;
                const key = `${ block.index }-${ block.status }`;
                const title = block.incoming?.name || block.current?.name || __( 'unknown', 'safe-publish' );
                const currentHtml = block.current?.rendered || '';
                const incomingHtml = block.incoming?.rendered || '';

                // Client-side recheck to avoid false modified flags (whitespace/attr noise).
                if ( status === 'modified' ) {
                    if ( normalizeHtml( currentHtml ) === normalizeHtml( incomingHtml ) ) {
                        status = 'unchanged';
                    }
                }

                const hasImage = /<img\s/i.test( currentHtml ) || /<img\s/i.test( incomingHtml );

                let modifiedIncoming = incomingHtml;
                if ( highlight && status === 'modified' && ! hasImage ) {
                    modifiedIncoming = highlightHtml( currentHtml, incomingHtml );
                }

                return (
                    <div key={ key } className="safe-publish-block-diff">
                        <div className="safe-publish-block-diff__header">
                            <Text>
                                { title || __( 'Block', 'safe-publish' ) }
                                <span className={ `safe-publish-badge safe-publish-${ status }` }>{ status }</span>
                                { hasImage && block.status === 'modified' && status !== 'unchanged' && (
                                    <span className="safe-publish-badge" style={ { background: '#6b7280', color: 'var(--safe-publish-text-on-fill)' } }>
                                        image (no inline diff)
                                    </span>
                                ) }
                            </Text>
                        </div>
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

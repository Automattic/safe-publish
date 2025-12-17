import { Diff, diffWords } from 'diff';
import type { BlockDiff } from '../api/diff';
import { __experimentalText as Text } from '@wordpress/components';

interface Props {
	blocks?: BlockDiff[];
	highlight?: boolean;
}

function highlightHtml( orig: string, changed: string ): string {
	if ( orig === changed ) return changed;
	const parts: Diff[] = diffWords( orig, changed );
	return parts
		.map( part => {
			const cls = part.added ? 'ccp-inline-added' : part.removed ? 'ccp-inline-removed' : '';
			if ( ! cls ) return part.value;
			return `<span class="${ cls }">${ part.value }</span>`;
		} )
		.join( '' );
}

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

export default function BlockDiffViewer( { blocks = [], highlight = true }: Props ): JSX.Element {
    return (
        <div className="ccp-block-diff-viewer">
            { blocks.map( b => {
                let status = b.status;
                const key = `${ b.index }-${ b.status }`;
                const title = b.incoming?.name || b.current?.name || 'unknown';
                const currentHtml = b.current?.rendered || '';
                const incomingHtml = b.incoming?.rendered || '';

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
                    <div key={ key } className={ `ccp-block-diff ccp-block-${ status }` }>
                        <div className="ccp-block-diff__header">
                            <Text>
                                { title || 'Block' }{' '}
                                <span className={ `ccp-badge ccp-${ status }` }>{ status }</span>
                                { hasImage && b.status === 'modified' && status !== 'unchanged' && (
                                    <span className="ccp-badge" style={ { background: '#6b7280', color: '#fff' } }>
                                        image (no inline diff)
                                    </span>
                                ) }
                            </Text>
                        </div>
                        { status === 'removed' && (
                            <div className="ccp-block-diff__removed" dangerouslySetInnerHTML={ { __html: currentHtml } } />
                        ) }
                        { status === 'added' && (
                            <div className="ccp-block-diff__added" dangerouslySetInnerHTML={ { __html: incomingHtml } } />
                        ) }
                        { status === 'unchanged' && (
                            <div className="ccp-block-diff__unchanged" dangerouslySetInnerHTML={ { __html: currentHtml } } />
                        ) }
                        { status === 'modified' && (
                            <div className="ccp-block-diff__modified">
                                <div className="ccp-block-diff__col ccp-before" dangerouslySetInnerHTML={ { __html: currentHtml } } />
                                <div className="ccp-block-diff__col ccp-after" dangerouslySetInnerHTML={ { __html: modifiedIncoming } } />
                            </div>
                        ) }
                    </div>
                );
            } ) }
        </div>
    );
}

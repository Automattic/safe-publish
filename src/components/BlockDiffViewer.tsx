/**
 * Block Diff Viewer component.
 *
 * Displays a visual comparison of Gutenberg blocks between the current and
 * incoming post content with inline highlighting of changes.
 *
 * @file This file defines the BlockDiffViewer component.
 */

import { __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { diffArrays, diffWords } from 'diff';

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

const ELEMENT_NODE = 1;
const TEXT_NODE = 3;

const ADDED_CLASS = 'safe-publish-inline-added';
const REMOVED_CLASS = 'safe-publish-inline-removed';
const ATTRS_CLASS = 'safe-publish-inline-attr-changed';

/**
 * Elements whose children the HTML parser restricts to table content. It
 * moves anything else out of the table, so a marker only goes inside one
 * of these when the marker is table content itself.
 */
const TABLE_SCOPE = new Set( [
	'COLGROUP',
	'TABLE',
	'TBODY',
	'TFOOT',
	'THEAD',
	'TR',
] );

/** Elements the HTML parser keeps inside a table-scope element. */
const TABLE_CONTENT = new Set( [
	'CAPTION',
	'COL',
	'COLGROUP',
	'SCRIPT',
	'STYLE',
	'TABLE',
	'TBODY',
	'TD',
	'TEMPLATE',
	'TFOOT',
	'TH',
	'THEAD',
	'TR',
] );

/**
 * Elements whose children are raw text or an inert fragment. A marker put
 * inside one of them would read as content, not as markup. Rendered block
 * HTML reaches this component through wp_kses_post, which keeps textarea
 * and title and drops the rest; the rest stay listed so a caller that
 * passes unfiltered HTML is handled the same way.
 */
const OPAQUE_TAGS = new Set( [
	'NOSCRIPT',
	'SCRIPT',
	'STYLE',
	'TEMPLATE',
	'TEXTAREA',
	'TITLE',
	'XMP',
] );

/**
 * Result of an inline diff pass.
 *
 * @property {string}  html     Incoming HTML carrying the diff markers.
 * @property {boolean} unmarked True when a change could not be marked.
 */
interface InlineDiff {
	html: string;
	unmarked: boolean;
}

/**
 * Running state of one inline diff pass.
 *
 * @property {boolean}              marked   True once a marker has been placed.
 * @property {boolean}              unmarked True once a change was left unmarked.
 * @property {Map<string, string>}  shapes   Subtree shape to its shared identity.
 * @property {WeakMap<Node,string>} keys     Node to its subtree identity.
 */
interface DiffState {
	marked: boolean;
	unmarked: boolean;
	shapes: Map< string, string >;
	keys: WeakMap< Node, string >;
}

/**
 * Reports whether a string holds nothing but whitespace.
 *
 * @param {string} value Value to test.
 *
 * @return {boolean} True when the value has no visible characters.
 */
function isBlank( value: string ): boolean {
	return value.trim() === '';
}

/**
 * Removes every whitespace character from a string.
 *
 * Used to compare two sides for a difference that is more than layout.
 * Whitespace between tags and around words does not survive rendering,
 * and diffWords does not mark it, so it is not a change the preview can
 * show either way.
 *
 * @param {string} value Value to strip.
 *
 * @return {string} Value with all whitespace removed.
 */
function stripWhitespace( value: string ): string {
	return value.replace( /\s+/g, '' );
}

/**
 * Lists a node's children as an array.
 *
 * @param {Node} node Node to read.
 *
 * @return {Node[]} Child nodes.
 */
function childNodesOf( node: Node ): Node[] {
	return Array.from( node.childNodes );
}

/**
 * Builds the comparison key for a node and its subtree.
 *
 * Equal keys mean the node is the same on both sides and needs no diffing.
 * A subtree is described by its tag, its attributes in document order and
 * the keys of its children, and each distinct description is interned to
 * one short identity that stands in for it inside its parent. Keys are
 * therefore built once per node and stay short however deeply the markup
 * nests, where serializing each subtree in full would re-read every
 * descendant at every level.
 *
 * @param {Node}      node  Node to key.
 * @param {DiffState} state Running diff state, holding the key tables.
 *
 * @return {string} Comparison key.
 */
function subtreeKey( node: Node, state: DiffState ): string {
	const cached = state.keys.get( node );
	if ( cached !== undefined ) {
		return cached;
	}

	let shape: string;
	if ( node.nodeType === ELEMENT_NODE ) {
		const element = node as Element;
		const children = childNodesOf( node )
			.map( ( child ) => subtreeKey( child, state ) )
			.join( '' );
		shape = `e${ element.tagName }${ serializeAttributes(
			element
		) }${ children }`;
	} else if ( node.nodeType === TEXT_NODE ) {
		shape = `t${ node.nodeValue ?? '' }`;
	} else {
		shape = `${ node.nodeName }${ node.nodeValue ?? '' }`;
	}

	let key = state.shapes.get( shape );
	if ( key === undefined ) {
		key = `#${ state.shapes.size }`;
		state.shapes.set( shape, key );
	}
	state.keys.set( node, key );
	return key;
}

/**
 * Serializes an element's attributes in document order.
 *
 * Order is kept, because the order attributes are written in is part of
 * the markup the import will store. The separators are control characters
 * so that a name or a value cannot imitate one.
 *
 * @param {Element} element Element to read.
 *
 * @return {string} Attribute names and values, in document order.
 */
function serializeAttributes( element: Element ): string {
	return element
		.getAttributeNames()
		.map(
			( name ) => `${ name }${ element.getAttribute( name ) ?? '' }`
		)
		.join( '' );
}

/**
 * Reports whether two elements carry different attributes.
 *
 * @param {Element} original Original element.
 * @param {Element} changed  Changed element.
 *
 * @return {boolean} True when an attribute was added, dropped, edited or moved.
 */
function attributesDiffer( original: Element, changed: Element ): boolean {
	return serializeAttributes( original ) !== serializeAttributes( changed );
}

/**
 * Reports whether a parent keeps a given tag where we put it.
 *
 * @param {Node}   parent  Parent the marker would go into.
 * @param {string} tagName Uppercase tag name of the marker.
 *
 * @return {boolean} True when the parser leaves the tag in place.
 */
function canHost( parent: Node, tagName: string ): boolean {
	if ( parent.nodeType !== ELEMENT_NODE ) {
		return true;
	}
	if ( ! TABLE_SCOPE.has( parent.nodeName ) ) {
		return true;
	}
	return TABLE_CONTENT.has( tagName );
}

/**
 * Marks a copied element, or reports it as unmarked.
 *
 * An element with no visible rendering cannot show a marker class, so the
 * change it carries is reported on the card instead.
 *
 * @param {Element}   element   Element copied into the output.
 * @param {string}    className Marker class to apply.
 * @param {DiffState} state     Running diff state.
 */
function markElement(
	element: Element,
	className: string,
	state: DiffState
): void {
	if ( OPAQUE_TAGS.has( element.nodeName ) ) {
		state.unmarked = true;
		return;
	}

	element.classList.add( className );
	state.marked = true;
}

/**
 * Appends text wrapped in a marker span.
 *
 * A parent that would not keep the span gets the plain text instead, which
 * keeps the serialized markup stable when it is parsed again.
 *
 * @param {string}    value     Text to append.
 * @param {string}    className Marker class to apply.
 * @param {Document}  doc       Document that owns the output.
 * @param {Node}      parent    Parent to append to.
 * @param {DiffState} state     Running diff state.
 */
function appendMarkedText(
	value: string,
	className: string,
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	if ( ! canHost( parent, 'SPAN' ) ) {
		if ( className === ADDED_CLASS ) {
			parent.appendChild( doc.createTextNode( value ) );
		}
		state.unmarked = true;
		return;
	}

	const span = doc.createElement( 'span' );
	span.className = className;
	span.appendChild( doc.createTextNode( value ) );
	parent.appendChild( span );
	state.marked = true;
}

/**
 * Appends a node that only the incoming side has.
 *
 * An element keeps its own tag and gains the marker class, so a new list
 * item stays a list item and remains a valid child of its parent.
 *
 * @param {Node}      node   Node from the incoming tree.
 * @param {Document}  doc    Document that owns the output.
 * @param {Node}      parent Parent to append to.
 * @param {DiffState} state  Running diff state.
 */
function appendAdded(
	node: Node,
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	if ( node.nodeType === TEXT_NODE ) {
		const value = node.nodeValue ?? '';
		if ( isBlank( value ) ) {
			parent.appendChild( doc.createTextNode( value ) );
			return;
		}
		appendMarkedText( value, ADDED_CLASS, doc, parent, state );
		return;
	}

	const clone = doc.importNode( node, true );
	parent.appendChild( clone );
	if ( clone.nodeType === ELEMENT_NODE ) {
		markElement( clone as Element, ADDED_CLASS, state );
		return;
	}

	// Comments and other invisible nodes have nowhere to show a marker.
	state.unmarked = true;
}

/**
 * Appends a node that only the current side has.
 *
 * A node the incoming side does not have is shown struck through. One that
 * cannot carry a marker is left out instead of copied, so the preview never
 * shows departing content as if it were arriving.
 *
 * @param {Node}      node   Node from the current tree.
 * @param {Document}  doc    Document that owns the output.
 * @param {Node}      parent Parent to append to.
 * @param {DiffState} state  Running diff state.
 */
function appendRemoved(
	node: Node,
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	if ( node.nodeType === TEXT_NODE ) {
		const value = node.nodeValue ?? '';
		if ( isBlank( value ) ) {
			return;
		}
		appendMarkedText( value, REMOVED_CLASS, doc, parent, state );
		return;
	}

	if (
		node.nodeType !== ELEMENT_NODE ||
		OPAQUE_TAGS.has( node.nodeName ) ||
		! canHost( parent, node.nodeName )
	) {
		state.unmarked = true;
		return;
	}

	const clone = doc.importNode( node, true ) as Element;
	parent.appendChild( clone );
	markElement( clone, REMOVED_CLASS, state );
}

/**
 * Appends the word-level diff of one text node pair.
 *
 * @param {string}    original Original text.
 * @param {string}    changed  Changed text.
 * @param {Document}  doc      Document that owns the output.
 * @param {Node}      parent   Parent to append to.
 * @param {DiffState} state    Running diff state.
 */
function appendTextDiff(
	original: string,
	changed: string,
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	if ( isBlank( original ) && isBlank( changed ) ) {
		parent.appendChild( doc.createTextNode( changed ) );
		return;
	}

	for ( const part of diffWords( original, changed ) ) {
		const isAdded = part.added === true;
		if ( ! isAdded && part.removed !== true ) {
			parent.appendChild( doc.createTextNode( part.value ) );
			continue;
		}

		// Whitespace runs move with the words around them and have no
		// visible state of their own.
		if ( isBlank( part.value ) ) {
			if ( isAdded ) {
				parent.appendChild( doc.createTextNode( part.value ) );
			}
			continue;
		}

		appendMarkedText(
			part.value,
			isAdded ? ADDED_CLASS : REMOVED_CLASS,
			doc,
			parent,
			state
		);
	}
}

/**
 * Appends the diff of two nodes that sit in the same place.
 *
 * @param {Node}      original Node from the current tree.
 * @param {Node}      changed  Node from the incoming tree.
 * @param {Document}  doc      Document that owns the output.
 * @param {Node}      parent   Parent to append to.
 * @param {DiffState} state    Running diff state.
 */
function appendPair(
	original: Node,
	changed: Node,
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	if ( original.nodeType === TEXT_NODE && changed.nodeType === TEXT_NODE ) {
		appendTextDiff(
			original.nodeValue ?? '',
			changed.nodeValue ?? '',
			doc,
			parent,
			state
		);
		return;
	}

	const sameElement =
		original.nodeType === ELEMENT_NODE &&
		changed.nodeType === ELEMENT_NODE &&
		original.nodeName === changed.nodeName;
	if ( ! sameElement ) {
		appendRemoved( original, doc, parent, state );
		appendAdded( changed, doc, parent, state );
		return;
	}

	// Raw text is content, not markup: a marker put inside it would be
	// imported as part of the script, style sheet or field value.
	if ( OPAQUE_TAGS.has( changed.nodeName ) ) {
		parent.appendChild( doc.importNode( changed, true ) );
		state.unmarked = true;
		return;
	}

	// The incoming element is copied without its children, so its own tag
	// and attributes reach the preview as the import will write them. Only
	// a marker class is ever added to it.
	const clone = doc.importNode( changed, false ) as Element;
	if ( attributesDiffer( original as Element, changed as Element ) ) {
		markElement( clone, ATTRS_CLASS, state );
	}
	parent.appendChild( clone );
	appendChildDiff(
		childNodesOf( original ),
		childNodesOf( changed ),
		doc,
		clone,
		state
	);
}

/**
 * Appends one run of removals paired with the run of additions next to it.
 *
 * Pairing lets an edited node be diffed against its counterpart instead of
 * being reported as an unrelated removal and addition.
 *
 * @param {Node[]}    removedNodes Nodes only the current side has.
 * @param {Node[]}    addedNodes   Nodes only the incoming side has.
 * @param {Document}  doc          Document that owns the output.
 * @param {Node}      parent       Parent to append to.
 * @param {DiffState} state        Running diff state.
 */
function appendRuns(
	removedNodes: Node[],
	addedNodes: Node[],
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	const removals = [ ...removedNodes ];
	const additions = [ ...addedNodes ];

	while ( removals.length > 0 && additions.length > 0 ) {
		const [ removal ] = removals.splice( 0, 1 );
		const [ addition ] = additions.splice( 0, 1 );
		appendPair( removal, addition, doc, parent, state );
	}

	removals.forEach( ( node ) => appendRemoved( node, doc, parent, state ) );
	additions.forEach( ( node ) => appendAdded( node, doc, parent, state ) );
}

/**
 * Appends the diff of two child node lists.
 *
 * Children are aligned on their subtree keys, so a node that is equal on
 * both sides is copied straight through and only the rest is diffed. Every
 * replacement jsdiff reports puts its removed run before the added run, so
 * a pending removal is always still in hand when its addition arrives.
 *
 * @param {Node[]}    originalNodes Children of the current node.
 * @param {Node[]}    changedNodes  Children of the incoming node.
 * @param {Document}  doc           Document that owns the output.
 * @param {Node}      parent        Parent to append to.
 * @param {DiffState} state         Running diff state.
 */
function appendChildDiff(
	originalNodes: Node[],
	changedNodes: Node[],
	doc: Document,
	parent: Node,
	state: DiffState
): void {
	const parts = diffArrays(
		originalNodes.map( ( node ) => subtreeKey( node, state ) ),
		changedNodes.map( ( node ) => subtreeKey( node, state ) )
	);
	const original = [ ...originalNodes ];
	const changed = [ ...changedNodes ];
	let removed: Node[] = [];

	for ( const part of parts ) {
		const size = part.value.length;

		if ( part.removed === true ) {
			appendRuns( removed, [], doc, parent, state );
			removed = original.splice( 0, size );
			continue;
		}

		const taken = changed.splice( 0, size );

		if ( part.added === true ) {
			appendRuns( removed, taken, doc, parent, state );
			removed = [];
			continue;
		}

		appendRuns( removed, [], doc, parent, state );
		removed = [];
		original.splice( 0, size );
		taken.forEach( ( node ) =>
			parent.appendChild( doc.importNode( node, true ) )
		);
	}

	appendRuns( removed, [], doc, parent, state );
}

/**
 * Marks the differences between two HTML strings.
 *
 * Both sides are parsed and walked together, and every marker is placed on
 * the node that carries the change: text changes are word-diffed inside
 * their own text node, an element whose attributes changed is marked on the
 * element itself, and an element that exists on one side only is copied
 * with a marker class. The incoming markup is therefore rebuilt from the
 * parsed document and carries only added marker classes, never a span
 * spliced into a tag.
 *
 * @param {string} original Original HTML string.
 * @param {string} changed  Changed HTML string.
 *
 * @return {InlineDiff} Marked HTML, and whether a change escaped marking.
 */
function highlightHtml( original: string, changed: string ): InlineDiff {
	if ( original === changed || typeof DOMParser === 'undefined' ) {
		return { html: changed, unmarked: false };
	}

	const parser = new DOMParser();
	const originalBody = parser.parseFromString( original, 'text/html' ).body;
	const changedDoc = parser.parseFromString( changed, 'text/html' );
	const container = changedDoc.createElement( 'div' );
	const state: DiffState = {
		marked: false,
		unmarked: false,
		shapes: new Map(),
		keys: new WeakMap(),
	};

	let html: string;
	try {
		appendChildDiff(
			childNodesOf( originalBody ),
			childNodesOf( changedDoc.body ),
			changedDoc,
			container,
			state
		);
		html = container.innerHTML;
	} catch {
		// Both the walk and the serializer recurse as deeply as the markup
		// nests. Markup deep enough to exhaust the stack is reported rather
		// than dropped.
		return { html: changed, unmarked: true };
	}

	// Nothing marked, yet the two sides differ by more than layout: say so
	// rather than show the block as if nothing had moved.
	const missedChange =
		state.marked !== true &&
		stripWhitespace( original ) !== stripWhitespace( changed );

	return { html, unmarked: state.unmarked || missedChange };
}

/**
 * Builds the class list for the incoming column.
 *
 * @param {boolean} unmarked True when a change has no inline marker.
 *
 * @return {string} Column class list.
 */
function incomingColumnClass( unmarked: boolean ): string {
	const base = 'safe-publish-block-diff__col';
	return unmarked ? `${ base } ${ base }--unmarked` : base;
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
 * Header row for one block diff card.
 *
 * The unmarked badge is rendered whether or not labels are shown, so
 * turning labels off never leaves an outlined column as the only cue that
 * the block changed.
 *
 * @param {Object}            props            Component props.
 * @param {string}            props.title      Block name to display.
 * @param {BlockDiff[status]} props.status     Effective status of the block.
 * @param {BlockDiff[status]} props.rawStatus  Status as reported by the API.
 * @param {boolean}           props.hasImage   True when either side has an image.
 * @param {boolean}           props.showLabels When false, omit name and status.
 * @param {boolean}           props.unmarked   True when a change has no inline marker.
 *
 * @return {JSX.Element|null} Header, or null when it would be empty.
 */
function BlockDiffHeader( {
    title,
    status,
    rawStatus,
    hasImage,
    showLabels,
    unmarked,
}: {
    title: string;
    status: BlockDiff[ 'status' ];
    rawStatus: BlockDiff[ 'status' ];
    hasImage: boolean;
    showLabels: boolean;
    unmarked: boolean;
} ): JSX.Element | null {
    if ( ! showLabels && ! unmarked ) {
        return null;
    }

    const showImageBadge =
        hasImage && rawStatus === 'modified' && status !== 'unchanged';

    return (
        <div className="safe-publish-block-diff__header">
            { showLabels && (
                <>
                    <Text>{ title }</Text>
                    <span className={ `safe-publish-badge safe-publish-${ status }` }>{ status }</span>
                    { showImageBadge && (
                        <span className="safe-publish-badge safe-publish-badge--neutral">
                            image (no inline diff)
                        </span>
                    ) }
                </>
            ) }
            { unmarked && (
                <span className="safe-publish-badge safe-publish-badge--neutral">
                    { __( 'changed (no inline marker)', 'safe-publish' ) }
                </span>
            ) }
        </div>
    );
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
                let unmarkedChange = false;
                if ( highlight && status === 'modified' && ! hasImage ) {
                    const inlineDiff = highlightHtml( rawCurrentHtml, rawIncomingHtml );
                    modifiedIncoming = inlineDiff.html;
                    unmarkedChange = inlineDiff.unmarked;
                }

                const columnClass = incomingColumnClass( unmarkedChange );
                const currentHtml = linkifyImages( rawCurrentHtml );
                const incomingHtml = linkifyImages( rawIncomingHtml );
                modifiedIncoming = linkifyImages( modifiedIncoming );

                return (
                    <div key={ key } className="safe-publish-block-diff">
                        <BlockDiffHeader
                            title={ title }
                            status={ status }
                            rawStatus={ block.status }
                            hasImage={ hasImage }
                            showLabels={ showLabels }
                            unmarked={ unmarkedChange }
                        />
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
                                <div className={ columnClass } dangerouslySetInnerHTML={ { __html: modifiedIncoming } } />
                            </div>
                        ) }
                    </div>
                );
            } ) }
        </div>
    );
}
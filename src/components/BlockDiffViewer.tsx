/**
 * Block Diff Viewer component.
 *
 * Displays a visual comparison of Gutenberg blocks between the current and
 * incoming post content with inline highlighting of changes.
 *
 * @file This file defines the BlockDiffViewer component.
 */

import { __experimentalText as Text } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
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
 * Most node comparisons that pairing two runs may take.
 */
const MAX_PAIRING_COMPARISONS = 10000;

/**
 * Most word lookups that pairing two runs may take.
 */
const MAX_PAIRING_WORDS = 1000000;

/**
 * Matches a word. Each Han or kana character counts as a word, since those
 * scripts put no spaces between words.
 */
const WORD = new RegExp(
	'[\\p{sc=Han}\\p{sc=Hiragana}\\p{sc=Katakana}]' +
		'|(?:(?![\\p{sc=Han}\\p{sc=Hiragana}\\p{sc=Katakana}])[\\p{L}\\p{N}])+',
	'gu'
);

/**
 * Elements whose children are raw text or an inert fragment, so a marker inside
 * one would read as content. Block HTML passes kses with the post allowlist,
 * which drops all but textarea and title; the rest cover unfiltered callers.
 */
const OPAQUE_TAGS = new Set( [
	'IFRAME',
	'NOEMBED',
	'NOFRAMES',
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
 * @property {string}  html        Incoming HTML carrying the diff markers.
 * @property {boolean} unmarked    True when a change could not be marked.
 * @property {boolean} attrChanged True when an element's attributes changed.
 */
interface InlineDiff {
	html: string;
	unmarked: boolean;
	attrChanged: boolean;
}

/**
 * Distinct words of a node's text and of its whole markup.
 *
 * @property {Set<string>} text   Words of the text.
 * @property {Set<string>} markup Words of the markup.
 */
interface NodeWords {
	text: Set< string >;
	markup: Set< string >;
}

/**
 * Running state of one inline diff pass.
 *
 * @property {boolean}                 marked      True once a marker has been placed.
 * @property {boolean}                 unmarked    True once a change was left unmarked.
 * @property {boolean}                 attrChanged True once an attribute change was marked.
 * @property {Map<string, string>}     shapes      Subtree shape to its shared identity.
 * @property {WeakMap<Node,string>}    keys        Node to its subtree identity.
 * @property {WeakMap<Node,NodeWords>} words       Node to its words.
 */
interface DiffState {
	marked: boolean;
	unmarked: boolean;
	attrChanged: boolean;
	shapes: Map< string, string >;
	keys: WeakMap< Node, string >;
	words: WeakMap< Node, NodeWords >;
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
 * Used to compare two sides for a difference beyond whitespace, which the diff
 * never marks.
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
 * Equal keys mean the node is the same on both sides. Each distinct subtree is
 * interned to one short identity, so each key is built once and stays short;
 * serializing every subtree would be quadratic in depth.
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
	if ( className === ATTRS_CLASS ) {
		// The outline is the only cue for an attribute change, so the card
		// carries a text equivalent for anyone who cannot see it.
		state.attrChanged = true;
	}
}

/**
 * Appends text wrapped in a marker span.
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

	if ( node.nodeType !== ELEMENT_NODE || OPAQUE_TAGS.has( node.nodeName ) ) {
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
 * Reads the words of a node's text and of its whole markup.
 *
 * @param {Node}      node  Node to read.
 * @param {DiffState} state Running diff state, holding the word cache.
 *
 * @return {NodeWords} Words of the node.
 */
function wordsOf( node: Node, state: DiffState ): NodeWords {
	let words = state.words.get( node );
	if ( words === undefined ) {
		const markup =
			node.nodeType === ELEMENT_NODE
				? ( node as Element ).outerHTML
				: node.nodeValue ?? '';
		words = {
			text: new Set( ( node.textContent ?? '' ).match( WORD ) ),
			markup: new Set( markup.match( WORD ) ),
		};
		state.words.set( node, words );
	}
	return words;
}

/**
 * Rates how alike two word sets are, from 0 to 1, as the Dice coefficient:
 * Twice the shared words over all words.
 *
 * @param {Set<string>} first  Words.
 * @param {Set<string>} second Words.
 *
 * @return {number} Dice coefficient.
 */
function dice( first: Set< string >, second: Set< string > ): number {
	const total = first.size + second.size;
	if ( total === 0 ) {
		return 0;
	}

	let shared = 0;
	first.forEach( ( word ) => {
		if ( second.has( word ) ) {
			shared++;
		}
	} );
	return ( 2 * shared ) / total;
}

/**
 * Rates how alike two nodes are, from 0 to 1.
 *
 * Text counts twice as much as markup, so a node keeps its pair across a
 * change of tag, while markup tells apart nodes with the same text.
 *
 * @param {NodeWords} first  Words of one node.
 * @param {NodeWords} second Words of the other node.
 *
 * @return {number} Similarity.
 */
function similarity( first: NodeWords, second: NodeWords ): number {
	const text = dice( first.text, second.text );
	const markup = dice( first.markup, second.markup );
	return ( 2 * text + markup ) / 3;
}

/**
 * Places each node of a run with a node of a longer run, in order, so that
 * the pairs are as alike as possible in total.
 *
 * Runs of equal length or too large to compare pair by position, and ties
 * take the earliest place, so runs without a telling difference do too.
 * Mirrors Diff_Renderer::best_places(), which places blocks.
 *
 * @param {Node[]}    short Nodes of the shorter run.
 * @param {Node[]}    long  Nodes of the longer run.
 * @param {DiffState} state Running diff state.
 *
 * @return {number[]} Index in the longer run of each node's pair.
 */
function bestPlaces( short: Node[], long: Node[], state: DiffState ): number[] {
	const skips = long.length - short.length;
	const places = short.map( ( _node, index ) => index );

	// With nothing to choose, or too much to compare, skip the search.
	if (
		short.length === 0 ||
		skips === 0 ||
		short.length * ( skips + 1 ) > MAX_PAIRING_COMPARISONS
	) {
		return places;
	}

	const shortWords = short.map( ( node ) => wordsOf( node, state ) );
	const lookups = shortWords.reduce(
		( sum, words ) => sum + words.text.size + words.markup.size,
		0
	);
	if ( lookups * ( skips + 1 ) > MAX_PAIRING_WORDS ) {
		return places;
	}
	const longWords = long.map( ( node ) => wordsOf( node, state ) );

	/* eslint-disable security/detect-object-injection -- numeric indexes. */
	// best[ index ][ offset ] is the highest total for nodes index onward once
	// offset nodes of the longer run are skipped, and
	// skipNext[ index ][ offset ] whether one more skip does strictly better.
	const best = Array.from( { length: short.length + 1 }, () =>
		new Array< number >( skips + 1 ).fill( 0 )
	);
	const skipNext = short.map( () =>
		new Array< boolean >( skips + 1 ).fill( false )
	);
	for ( let index = short.length - 1; index >= 0; index-- ) {
		const words = shortWords[ index ];
		for ( let offset = skips; offset >= 0; offset-- ) {
			const place =
				similarity( words, longWords[ index + offset ] ) +
				best[ index + 1 ][ offset ];
			const later = offset < skips ? best[ index ][ offset + 1 ] : -1;

			skipNext[ index ][ offset ] = later > place;
			best[ index ][ offset ] = Math.max( place, later );
		}
	}

	let offset = 0;
	return places.map( ( index ) => {
		while ( skipNext[ index ][ offset ] ) {
			offset++;
		}
		return index + offset;
	} );
	/* eslint-enable security/detect-object-injection */
}

/**
 * Appends one run of removals paired with the run of additions next to it.
 *
 * Pairing lets an edited node be diffed against its counterpart instead of
 * being reported as an unrelated removal and addition. Each node of the
 * shorter run pairs with the node bestPlaces() picks, and the other nodes of
 * the longer run stand alone, in document order. Mirrors
 * Diff_Renderer::pair_runs(), which pairs blocks.
 *
 * @param {Node[]}               removedNodes   Nodes only the current side has.
 * @param {Node[]}               addedNodes     Nodes only the incoming side has.
 * @param {Document}             doc            Document that owns the output.
 * @param {Node}                 parent         Parent to append to.
 * @param {DiffState}            state          Running diff state.
 * @param {(node: Node) => void} beforeIncoming Runs before an incoming node is appended.
 */
function appendRuns(
	removedNodes: Node[],
	addedNodes: Node[],
	doc: Document,
	parent: Node,
	state: DiffState,
	beforeIncoming: ( node: Node ) => void
): void {
	const removedShort = removedNodes.length <= addedNodes.length;
	const short = removedShort ? removedNodes : addedNodes;
	const long = removedShort ? addedNodes : removedNodes;
	const appendAlone = ( node: Node ): void => {
		if ( removedShort ) {
			beforeIncoming( node );
			appendAdded( node, doc, parent, state );
		} else {
			appendRemoved( node, doc, parent, state );
		}
	};

	let next = 0;
	/* eslint-disable security/detect-object-injection -- numeric indexes. */
	bestPlaces( short, long, state ).forEach( ( place, index ) => {
		long.slice( next, place ).forEach( appendAlone );
		const pair = [ short[ index ], long[ place ] ];
		const [ removal, addition ] = removedShort ? pair : pair.reverse();
		beforeIncoming( addition );
		appendPair( removal, addition, doc, parent, state );
		next = place + 1;
	} );
	/* eslint-enable security/detect-object-injection */
	long.slice( next ).forEach( appendAlone );
}

/**
 * Appends the diff of two child node lists.
 *
 * Children are aligned on their subtree keys, so a node that is equal on
 * both sides is copied straight through and only the rest is diffed. Every
 * replacement jsdiff reports puts its removed run before the added run, so
 * a pending removal is always still in hand when its addition arrives.
 * Rendered markup spaces children unevenly, so whitespace-only text takes no
 * part in the alignment; the incoming copy stays in place.
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
	const isContent = ( node: Node ): boolean =>
		node.nodeType !== TEXT_NODE || ! isBlank( node.nodeValue ?? '' );
	const original = originalNodes.filter( isContent );
	const changed = changedNodes.filter( isContent );
	const parts = diffArrays(
		original.map( ( node ) => subtreeKey( node, state ) ),
		changed.map( ( node ) => subtreeKey( node, state ) )
	);
	let removed: Node[] = [];

	// Copies the incoming whitespace up to the given node, or to the end.
	const incoming = changedNodes.values();
	const copyWhitespace = ( node?: Node ): void => {
		for (
			let next = incoming.next();
			next.done !== true && next.value !== node;
			next = incoming.next()
		) {
			parent.appendChild( doc.importNode( next.value, true ) );
		}
	};

	for ( const part of parts ) {
		const size = part.value.length;

		if ( part.removed === true ) {
			appendRuns( removed, [], doc, parent, state, copyWhitespace );
			removed = original.splice( 0, size );
			continue;
		}

		const taken = changed.splice( 0, size );

		if ( part.added === true ) {
			appendRuns( removed, taken, doc, parent, state, copyWhitespace );
			removed = [];
			continue;
		}

		appendRuns( removed, [], doc, parent, state, copyWhitespace );
		removed = [];
		original.splice( 0, size );
		taken.forEach( ( node ) => {
			copyWhitespace( node );
			parent.appendChild( doc.importNode( node, true ) );
		} );
	}

	appendRuns( removed, [], doc, parent, state, copyWhitespace );
	copyWhitespace();
}

/**
 * Marks the differences between two HTML strings.
 *
 * Both sides are parsed and walked together. Text is word-diffed inside its own
 * text node, an attribute change marks its element, and an element on one side
 * only is copied with a marker class. The incoming markup is rebuilt from the
 * parsed document, so markers are the only additions.
 *
 * @param {string} original Original HTML string.
 * @param {string} changed  Changed HTML string.
 *
 * @return {InlineDiff} Marked HTML, and whether a change escaped marking.
 */
function highlightHtml( original: string, changed: string ): InlineDiff {
	if ( original === changed || typeof DOMParser === 'undefined' ) {
		return { html: changed, unmarked: false, attrChanged: false };
	}

	const parser = new DOMParser();
	const originalBody = parser.parseFromString( original, 'text/html' ).body;
	const changedDoc = parser.parseFromString( changed, 'text/html' );
	const container = changedDoc.createElement( 'div' );
	const state: DiffState = {
		marked: false,
		unmarked: false,
		attrChanged: false,
		shapes: new Map(),
		keys: new WeakMap(),
		words: new WeakMap(),
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
		// The walk and the serializer both recurse, so pathological nesting
		// can exhaust the stack. Any failure falls back to the unmarked
		// incoming markup, which the card reports, rather than breaking the
		// modal: nothing here renders an error boundary.
		return { html: changed, unmarked: true, attrChanged: false };
	}

	// Nothing marked, yet the two sides differ by more than layout: say so
	// rather than show the block as if nothing had moved.
	const missedChange =
		state.marked !== true &&
		stripWhitespace( original ) !== stripWhitespace( changed );

	return {
		html,
		unmarked: state.unmarked || missedChange,
		attrChanged: state.attrChanged,
	};
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
 * Reports whether a modified block's two previews render identically.
 *
 * Filtering for safe output drops the markup a change can be confined to.
 *
 * @param {BlockDiff} block Block diff entry.
 *
 * @return {boolean} True when neither preview can show the change.
 */
export function isPreviewUnavailable( block: BlockDiff ): boolean {
    if ( block.status !== 'modified' ) {
        return false;
    }
    return (
        normalizeHtml( block.current?.rendered || '' ) ===
        normalizeHtml( block.incoming?.rendered || '' )
    );
}

/**
 * Props for the BlockDiffHeader component.
 *
 * @property {string}              title              Block name to display.
 * @property {BlockDiff['status']} status             Status of the block.
 * @property {boolean}             hasImage           Whether either side renders an image.
 * @property {boolean}             previewUnavailable Whether neither preview can show the change.
 * @property {boolean}             showLabels         When false, omit name and status.
 * @property {boolean}             unmarked           Whether a change has no inline marker.
 * @property {boolean}             attrChanged        Whether an element's attributes changed.
 */
interface HeaderProps {
	title: string;
	status: BlockDiff[ 'status' ];
	hasImage: boolean;
	previewUnavailable: boolean;
	showLabels: boolean;
	unmarked: boolean;
	attrChanged: boolean;
}

/**
 * Header row for one block diff card.
 *
 * The unmarked badge shows even with labels off, so the dashed outline is never
 * the only cue.
 *
 * @param {HeaderProps} props Component props.
 *
 * @return {JSX.Element|null} Header, or null when it would be empty.
 */
function BlockDiffHeader( {
	title,
	status,
	hasImage,
	previewUnavailable,
	showLabels,
	unmarked,
	attrChanged,
}: HeaderProps ): JSX.Element | null {
	if ( ! showLabels && ! unmarked && ! attrChanged ) {
		return null;
	}

	const showImageBadge =
		hasImage && status === 'modified' && ! previewUnavailable;

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
					{ __( 'changed — see Source Diff', 'safe-publish' ) }
				</span>
			) }
			{ attrChanged && (
				<span className="safe-publish-sr-only">
					{ __( 'Attributes changed', 'safe-publish' ) }
				</span>
			) }
		</div>
	);
}

/**
 * Props for the PreviewPane component.
 *
 * @property {string} className Class list of the preview wrapper.
 * @property {string} html      Rendered HTML to show.
 * @property {string} label     Accessible name while the preview scrolls.
 */
interface PaneProps {
	className: string;
	html: string;
	label: string;
}

/**
 * Renders one preview, a labeled Tab stop while its content overflows.
 *
 * @param {PaneProps} props Component props.
 *
 * @return {JSX.Element} Rendered preview.
 */
function PreviewPane( { className, html, label }: PaneProps ): JSX.Element {
	const ref = useRef< HTMLDivElement >( null );
	const [ overflows, setOverflows ] = useState( false );

	// Re-run on new content, which can overflow without resizing the preview.
	useEffect( () => {
		const element = ref.current;
		if ( element === null || typeof ResizeObserver === 'undefined' ) {
			return;
		}

		const observer = new ResizeObserver( () =>
			setOverflows( element.scrollWidth > element.clientWidth )
		);
		observer.observe( element );
		return () => observer.disconnect();
	}, [ html ] );

	return (
		<div
			ref={ ref }
			className={ className }
			tabIndex={ overflows ? 0 : undefined }
			role={ overflows ? 'region' : undefined }
			aria-label={ overflows ? label : undefined }
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}

/**
 * Props for the BlockDiffBody component.
 *
 * @property {BlockDiff} block              Block diff entry to render.
 * @property {boolean}   previewUnavailable Whether neither preview can show the change.
 * @property {string}    incomingHtml       Incoming HTML, carrying inline markers when highlighted.
 * @property {boolean}   unmarked           Whether a change has no inline marker.
 */
interface BodyProps {
	block: BlockDiff;
	previewUnavailable: boolean;
	incomingHtml: string;
	unmarked: boolean;
}

/**
 * Renders the body a block's status calls for.
 *
 * @param {BodyProps} props Component props.
 *
 * @return {JSX.Element} Rendered block body.
 */
function BlockDiffBody( {
	block,
	previewUnavailable,
	incomingHtml,
	unmarked,
}: BodyProps ): JSX.Element {
	const currentHtml = linkifyImages( block.current?.rendered || '' );
	const currentLabel = __( 'Current preview', 'safe-publish' );
	const incomingLabel = __( 'Incoming preview', 'safe-publish' );

	if ( block.status === 'removed' ) {
		return <PreviewPane className="safe-publish-block-diff__removed" html={ currentHtml } label={ currentLabel } />;
	}
	if ( block.status === 'added' ) {
		const addedHtml = linkifyImages( block.incoming?.rendered || '' );
		return <PreviewPane className="safe-publish-block-diff__added" html={ addedHtml } label={ incomingLabel } />;
	}
	if ( block.status === 'unchanged' ) {
		return <PreviewPane className="safe-publish-block-diff__unchanged" html={ currentHtml } label={ currentLabel } />;
	}
	if ( previewUnavailable ) {
		return (
			<div className="safe-publish-block-diff__no-preview">
				<Text>
					{ __( 'This change is not visible in the preview — see Source Diff.', 'safe-publish' ) }
				</Text>
			</div>
		);
	}

	return (
		<div className="safe-publish-block-diff__modified">
			<PreviewPane className="safe-publish-block-diff__col" html={ currentHtml } label={ currentLabel } />
			<PreviewPane className={ incomingColumnClass( unmarked ) } html={ linkifyImages( incomingHtml ) } label={ incomingLabel } />
		</div>
	);
}

/**
 * Props for the BlockDiffCard component.
 *
 * @property {BlockDiff} block      Block diff entry to render.
 * @property {boolean}   highlight  Enable inline word-level highlighting.
 * @property {boolean}   showLabels When false, omit the block-name + status header.
 */
interface CardProps {
	block: BlockDiff;
	highlight: boolean;
	showLabels: boolean;
}

/**
 * Renders one block's card: its header and its status-specific body.
 *
 * @param {CardProps} props Component props.
 *
 * @return {JSX.Element} Rendered block card.
 */
function BlockDiffCard( { block, highlight, showLabels }: CardProps ): JSX.Element {
	const status = block.status;
	const title = block.incoming?.name || block.current?.name || __( 'Block', 'safe-publish' );
	const previewUnavailable = isPreviewUnavailable( block );
	const rawCurrentHtml = block.current?.rendered || '';
	const rawIncomingHtml = block.incoming?.rendered || '';
	const hasImage =
		/<img\s/i.test( rawCurrentHtml ) || /<img\s/i.test( rawIncomingHtml );

	// Diff before linkifying — diffing linkified HTML would surface anchor
	// wrappers as changes. A preview that cannot show the change skips it.
	const inlineDiff: InlineDiff =
		highlight && status === 'modified' && ! hasImage && ! previewUnavailable
			? highlightHtml( rawCurrentHtml, rawIncomingHtml )
			: { html: rawIncomingHtml, unmarked: false, attrChanged: false };

	return (
		<div className="safe-publish-block-diff">
			<BlockDiffHeader
				title={ title }
				status={ status }
				hasImage={ hasImage }
				previewUnavailable={ previewUnavailable }
				showLabels={ showLabels }
				unmarked={ inlineDiff.unmarked }
				attrChanged={ inlineDiff.attrChanged }
			/>
			<BlockDiffBody
				block={ block }
				previewUnavailable={ previewUnavailable }
				incomingHtml={ inlineDiff.html }
				unmarked={ inlineDiff.unmarked }
			/>
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
    const visible = showUnchanged
        ? blocks
        : blocks.filter( ( block ) => block.status !== 'unchanged' );

    if ( visible.length === 0 ) {
        return (
            <div className="safe-publish-block-diff-viewer">
                <Text>{ __( 'No block changes detected.', 'safe-publish' ) }</Text>
            </div>
        );
    }

    return (
        <div className="safe-publish-block-diff-viewer">
            { visible.map( ( block ) => (
                <BlockDiffCard
                    key={ `${ block.index }-${ block.status }` }
                    block={ block }
                    highlight={ highlight }
                    showLabels={ showLabels }
                />
            ) ) }
        </div>
    );
}

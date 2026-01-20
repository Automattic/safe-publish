/**
 * Non-Content Diff Sections component.
 *
 * Renders diff sections for title, excerpt, taxonomies, meta, and featured media.
 *
 * @file This file defines the NonContentDiffSections component.
 */

import { __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { DiffPreviewResult } from '../api/diff';

/**
 * Props for the NonContentDiffSections component.
 *
 * @property {DiffPreviewResult['nonContentDiffs']} nonContentDiffs Non-content field diffs.
 */
interface NonContentDiffSectionsProps {
	nonContentDiffs: DiffPreviewResult['nonContentDiffs'];
}

/**
 * Renders non-content diff sections.
 *
 * @param {Object}                               props                 Component props.
 * @param {DiffPreviewResult['nonContentDiffs']} props.nonContentDiffs Non-content diff data.
 *
 * @return {JSX.Element | null}                  Rendered diff sections or null if no diffs.
 */
export default function NonContentDiffSections( {
	nonContentDiffs,
}: NonContentDiffSectionsProps ): JSX.Element | null {
	if ( ! nonContentDiffs ) {
		return null;
	}

	return (
		<>
			{ nonContentDiffs.title && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Title Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.title } } />
				</section>
			) }
			{ nonContentDiffs.excerpt && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Excerpt Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.excerpt } } />
				</section>
			) }
			{ nonContentDiffs.taxonomies && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Taxonomies Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.taxonomies } } />
				</section>
			) }
			{ nonContentDiffs.meta && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Meta / Custom Fields Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.meta } } />
				</section>
			) }
			{ nonContentDiffs.featuredMedia && (
				<section style={ { marginTop: 12 } }>
					<Text as="h2">{ __( 'Featured Image Diff', 'ccp' ) }</Text>
					<div dangerouslySetInnerHTML={ { __html: nonContentDiffs.featuredMedia } } />
				</section>
			) }
		</>
	);
}

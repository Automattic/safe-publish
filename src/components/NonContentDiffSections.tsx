/**
 * Non-Content Diff Sections component.
 *
 * Renders diff sections for title, excerpt, taxonomies, meta, and featured
 * media. By default omits sections whose server-side diff is empty; when
 * showUnchanged is on, surfaces a small placeholder for each empty section.
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
 * @property {boolean}                              [showUnchanged] Show empty-section placeholders.
 */
interface NonContentDiffSectionsProps {
	nonContentDiffs: DiffPreviewResult['nonContentDiffs'];
	showUnchanged?: boolean;
}

/**
 * Section descriptor used to render the diff list.
 */
interface SectionConfig {
	html: string | undefined;
	heading: string;
	unchangedLabel: string;
}

/**
 * Renders non-content diff sections.
 *
 * @param {Object}                               props                 Component props.
 * @param {DiffPreviewResult['nonContentDiffs']} props.nonContentDiffs Non-content diff data.
 * @param {boolean}                              [props.showUnchanged] When true, render placeholders for empty sections.
 *
 * @return {JSX.Element | null}                  Rendered diff sections or null when nothing to show.
 */
export default function NonContentDiffSections( {
	nonContentDiffs,
	showUnchanged = false,
}: NonContentDiffSectionsProps ): JSX.Element | null {
	if ( ! nonContentDiffs ) {
		return null;
	}

	const sections: SectionConfig[] = [
		{
			html: nonContentDiffs.title,
			heading: __( 'Title', 'safe-publish' ),
			unchangedLabel: __( 'No title changes detected.', 'safe-publish' ),
		},
		{
			html: nonContentDiffs.excerpt,
			heading: __( 'Excerpt', 'safe-publish' ),
			unchangedLabel: __( 'No excerpt changes detected.', 'safe-publish' ),
		},
		{
			html: nonContentDiffs.taxonomies,
			heading: __( 'Taxonomies', 'safe-publish' ),
			unchangedLabel: __( 'No taxonomy changes detected.', 'safe-publish' ),
		},
		{
			html: nonContentDiffs.meta,
			heading: __( 'Meta / Custom Fields', 'safe-publish' ),
			unchangedLabel: __( 'No meta changes detected.', 'safe-publish' ),
		},
		{
			html: nonContentDiffs.featuredMedia,
			heading: __( 'Featured Image', 'safe-publish' ),
			unchangedLabel: __( 'No featured image changes detected.', 'safe-publish' ),
		},
	];

	const rendered = sections
		.map( ( section ) => {
			const hasChanges = Boolean( section.html );
			if ( ! hasChanges && ! showUnchanged ) {
				return null;
			}
			return (
				<section
					key={ section.heading }
					style={ { marginTop: 12 } }
				>
					<Text as="h3">{ section.heading }</Text>
					{ hasChanges ? (
						<div
							dangerouslySetInnerHTML={ {
								__html: section.html as string,
							} }
						/>
					) : (
						<Text>{ section.unchangedLabel }</Text>
					) }
				</section>
			);
		} )
		.filter( Boolean );

	if ( rendered.length === 0 ) {
		return null;
	}

	return <>{ rendered }</>;
}

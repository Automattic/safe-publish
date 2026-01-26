/**
 * Update Options Panel component.
 *
 * Renders checkboxes for selecting what post fields to update.
 *
 * @file This file defines the UpdateOptionsPanel component.
 */

import {
	__experimentalText as Text,
	__experimentalVStack as VStack,
	CheckboxControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Options for which post fields to update.
 *
 * @property {boolean} title         Update post title.
 * @property {boolean} excerpt       Update post excerpt.
 * @property {boolean} meta          Update post meta fields.
 * @property {boolean} terms         Update post taxonomies/terms.
 * @property {boolean} featuredMedia Update featured image.
 */
interface UpdateOptions {
	title: boolean;
	excerpt: boolean;
	meta: boolean;
	terms: boolean;
	featuredMedia: boolean;
}

/**
 * Props for the UpdateOptionsPanel component.
 *
 * @property {UpdateOptions} updateOpts Current update options.
 * @property {Function}      onChange   Callback when options change.
 */
interface UpdateOptionsPanelProps {
	updateOpts: UpdateOptions;
	onChange: ( opts: UpdateOptions ) => void;
}

/**
 * Renders update options panel.
 *
 * @param {Object}        props            Component props.
 * @param {UpdateOptions} props.updateOpts Current update options.
 * @param {Function}      props.onChange   Callback when options change.
 *
 * @return {JSX.Element}  Rendered panel.
 */
export default function UpdateOptionsPanel( {
	updateOpts,
	onChange,
}: UpdateOptionsPanelProps ): JSX.Element {
	/**
	 * Handles change events for individual checkboxes.
	 *
	 * @param {keyof UpdateOptions} field Field name to update.
	 * @param {boolean}             value New value for the field.
	 */
	const handleChange = ( field: keyof UpdateOptions, value: boolean ) => {
		onChange( { ...updateOpts, [ field ]: value } );
	};

	return (
		<section style={ { marginTop: 16, borderTop: '1px solid #eee', paddingTop: 12 } }>
			<Text as="h2">{ __( 'What to update', 'safe-publish' ) }</Text>
			<VStack spacing="2" style={ { marginTop: 6 } }>
				<CheckboxControl
					label={ __( 'Title', 'safe-publish' ) }
					checked={ updateOpts.title }
					onChange={ ( val ) => handleChange( 'title', Boolean( val ) ) }
				/>
				<CheckboxControl
					label={ __( 'Excerpt', 'safe-publish' ) }
					checked={ updateOpts.excerpt }
					onChange={ ( val ) => handleChange( 'excerpt', Boolean( val ) ) }
				/>
				<CheckboxControl
					label={ __( 'Meta (custom fields)', 'safe-publish' ) }
					checked={ updateOpts.meta }
					onChange={ ( val ) => handleChange( 'meta', Boolean( val ) ) }
				/>
				<CheckboxControl
					label={ __( 'Terms (taxonomies)', 'safe-publish' ) }
					checked={ updateOpts.terms }
					onChange={ ( val ) => handleChange( 'terms', Boolean( val ) ) }
				/>
				<CheckboxControl
					label={ __( 'Featured Image', 'safe-publish' ) }
					checked={ updateOpts.featuredMedia }
					onChange={ ( val ) => handleChange( 'featuredMedia', Boolean( val ) ) }
				/>
				<Text style={ { fontSize: 12, color: '#666' } }>
					{ __( 'Content is always updated. Uncheck items above to skip updating them.', 'safe-publish' ) }
				</Text>
			</VStack>
		</section>
	);
}

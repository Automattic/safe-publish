/**
 * Titles a bulk confirmation group affects, under a heading naming what
 * happens to them.
 *
 * @file This file defines the ConfirmTitleList component.
 */

import {
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { MAX_VISIBLE_MODAL_TITLES } from '../constants';

/**
 * Props for the ConfirmTitleList component.
 *
 * @property {string}   heading       Sentence naming what happens to the group.
 * @property {string[]} titles        Affected titles, in listing order.
 * @property {boolean?} isDestructive Render with destructive emphasis.
 */
export interface ConfirmTitleListProps {
	heading: string;
	titles: string[];
	isDestructive?: boolean;
}

/**
 * Renders a confirmation group, or nothing when no title falls into it.
 *
 * @param {ConfirmTitleListProps} props Component props.
 *
 * @return {JSX.Element|null} Group heading and title list.
 */
export default function ConfirmTitleList( {
	heading,
	titles,
	isDestructive = false,
}: ConfirmTitleListProps ): JSX.Element | null {
	const headingId = useId();

	if ( 0 === titles.length ) {
		return null;
	}

	const visible = titles.slice( 0, MAX_VISIBLE_MODAL_TITLES );
	const hidden = titles.length - visible.length;
	const className = isDestructive
		? 'safe-publish-confirm-titles safe-publish-confirm-titles--destructive'
		: 'safe-publish-confirm-titles';

	return (
		<VStack spacing="1" className={ className }>
			<Text id={ headingId } className="safe-publish-confirm-titles__heading">
				{ heading }
			</Text>
			<ul aria-labelledby={ headingId }>
				{ visible.map( ( title, index ) => (
					<li key={ index }>{ title }</li>
				) ) }
				{ hidden > 0 && (
					<li className="safe-publish-confirm-titles__more">
						{ sprintf(
							/* translators: %d is the number of further affected posts */
							__( '…and %d more', 'safe-publish' ),
							hidden
						) }
					</li>
				) }
			</ul>
		</VStack>
	);
}

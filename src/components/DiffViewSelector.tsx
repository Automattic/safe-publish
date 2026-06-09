/**
 * Diff View Selector component.
 *
 * Renders a ToggleGroupControl to switch between the Block View and
 * Source Diff views.
 *
 * @file This file defines the DiffViewSelector component.
 */

import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Props for the DiffViewSelector component.
 *
 * @property {boolean}  showBlockView Whether block view is currently shown.
 * @property {boolean}  hasDiffHtml   Whether source diff HTML is available.
 * @property {Function} onViewChange  Callback when view changes; receives the new showBlockView.
 */
interface DiffViewSelectorProps {
	showBlockView: boolean;
	hasDiffHtml: boolean;
	onViewChange: ( showBlockView: boolean ) => void;
}

/**
 * Renders the diff view segmented control.
 *
 * @param {Object}   props               Component props.
 * @param {boolean}  props.showBlockView Whether block view is currently shown.
 * @param {boolean}  props.hasDiffHtml   Whether source diff HTML is available.
 * @param {Function} props.onViewChange  Callback when view changes.
 *
 * @return {JSX.Element}                 Rendered view selector.
 */
export default function DiffViewSelector( {
	showBlockView,
	hasDiffHtml,
	onViewChange,
}: DiffViewSelectorProps ): JSX.Element {
	return (
		<ToggleGroupControl
			__nextHasNoMarginBottom
			isBlock
			hideLabelFromVision
			label={ __( 'Diff view', 'safe-publish' ) }
			value={ showBlockView ? 'block' : 'source' }
			onChange={ ( value ) => onViewChange( value === 'block' ) }
		>
			<ToggleGroupControlOption
				value="block"
				label={ __( 'Block View', 'safe-publish' ) }
			/>
			{ hasDiffHtml && (
				<ToggleGroupControlOption
					value="source"
					label={ __( 'Source Diff', 'safe-publish' ) }
				/>
			) }
		</ToggleGroupControl>
	);
}

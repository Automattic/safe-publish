/**
 * Diff View Selector component.
 *
 * Renders buttons to switch between different diff views.
 *
 * @file This file defines the DiffViewSelector component.
 */

import { Button, __experimentalHStack as HStack } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Props for the DiffViewSelector component.
 *
 * @property {boolean}  showBlockView       Whether block view is currently shown.
 * @property {boolean}  showRenderedDiff    Whether rendered diff is currently shown.
 * @property {boolean}  hasRenderedDiffHtml Whether rendered diff HTML is available.
 * @property {boolean}  hasDiffHtml         Whether source diff HTML is available.
 * @property {Function} onViewChange        Callback when view changes.
 */
interface DiffViewSelectorProps {
	showBlockView: boolean;
	showRenderedDiff: boolean;
	hasRenderedDiffHtml: boolean;
	hasDiffHtml: boolean;
	onViewChange: ( blockView: boolean, renderedDiff: boolean ) => void;
}

/**
 * Renders diff view selector buttons.
 *
 * @param {Object}   props                     Component props.
 * @param {boolean}  props.showBlockView       Whether block view is currently shown.
 * @param {boolean}  props.showRenderedDiff    Whether rendered diff is currently shown.
 * @param {boolean}  props.hasRenderedDiffHtml Whether rendered diff HTML is available.
 * @param {boolean}  props.hasDiffHtml         Whether source diff HTML is available.
 * @param {Function} props.onViewChange        Callback when view changes.
 *
 * @return {JSX.Element}                       Rendered view selector.
 */
export default function DiffViewSelector( {
	showBlockView,
	showRenderedDiff,
	hasRenderedDiffHtml,
	hasDiffHtml,
	onViewChange,
}: DiffViewSelectorProps ): JSX.Element {
	return (
		<HStack style={ { gap: 8, marginTop: 12 } }>
			<Button
				variant={ showBlockView ? 'primary' : 'tertiary' }
				onClick={ () => onViewChange( true, showRenderedDiff ) }
				size="small"
			>
				{ __( 'Block View', 'safe-publish' ) }
			</Button>
			{ hasRenderedDiffHtml && (
				<Button
					variant={ ! showBlockView && showRenderedDiff ? 'primary' : 'tertiary' }
					onClick={ () => onViewChange( false, true ) }
					size="small"
				>
					{ __( 'Rendered Table Diff', 'safe-publish' ) }
				</Button>
			) }
			{ hasDiffHtml && (
				<Button
					variant={ ! showBlockView && ! showRenderedDiff ? 'primary' : 'tertiary' }
					onClick={ () => onViewChange( false, false ) }
					size="small"
				>
					{ __( 'Source Diff', 'safe-publish' ) }
				</Button>
			) }
		</HStack>
	);
}

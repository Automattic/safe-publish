/**
 * Renders a translated error while isolating its source-provided reason.
 */

import { createInterpolateElement } from '@wordpress/element';

import type { DisplayError } from '../types';

interface IsolatedErrorMessageProps {
	error: DisplayError;
}

export default function IsolatedErrorMessage( {
	error,
}: IsolatedErrorMessageProps ): JSX.Element {
	if ( typeof error === 'string' ) {
		return <>{ error }</>;
	}

	return createInterpolateElement( error.template, {
		reason: <bdi dir="auto">{ error.message }</bdi>,
	} );
}

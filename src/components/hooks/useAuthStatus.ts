/**
 * Custom hook that probes the source-site auth status on mount.
 *
 * Used by the admin pages that surface AuthStatusNotice so the banner and
 * downstream import buttons reflect whether the source site will accept
 * signed requests before any user action. Backed by the server-cached
 * `safe_publish_auth_status` admin-ajax endpoint.
 *
 * @file This file defines the useAuthStatus hook.
 */

import { useEffect, useState } from '@wordpress/element';

import type { ApiResponse, AuthStatusData, AuthStatusView } from '../../types';

/**
 * Probes the auth status once and returns the latest verdict.
 *
 * @return {AuthStatusView|null} The probe result, or null while in flight.
 */
export function useAuthStatus(): AuthStatusView | null {
	const [ status, setStatus ] = useState< AuthStatusView | null >( null );

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_auth_status' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< AuthStatusData > >
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				// Only the server can report unreachable; a failed request says
				// nothing about the source site.
				setStatus( result.success ? result.data.status : 'probe_failed' );
			} )
			.catch( () => {
				if ( controller.signal.aborted ) {
					return;
				}
				setStatus( 'probe_failed' );
			} );

		return () => {
			controller.abort();
		};
	}, [] );

	return status;
}

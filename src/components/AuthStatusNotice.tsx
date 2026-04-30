/**
 * Auth status notice component.
 *
 * Renders a banner explaining the current authentication state with the
 * source site so admins know whether the import flow can proceed before
 * clicking anything. Hides itself when the source authorizes the request.
 *
 * @file This file defines the AuthStatusNotice component.
 */

import { AuthStatus } from '../types';
import { Notice } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Props for the AuthStatusNotice component.
 *
 * @property {AuthStatus|null} status        Current probe status, or null while loading.
 * @property {string}          [settingsUrl] URL of the plugin settings page.
 */
interface AuthStatusNoticeProps {
	status: AuthStatus | null;
	settingsUrl?: string;
}

/**
 * Renders an inline notice describing the current auth probe status.
 *
 * @param {AuthStatusNoticeProps} props Component props.
 * @return {JSX.Element|null} Rendered notice, or null when no banner is needed.
 */
function AuthStatusNotice( {
	status,
	settingsUrl,
}: AuthStatusNoticeProps ): JSX.Element | null {
	if ( null === status || 'authorized' === status ) {
		return null;
	}

	if ( 'unauthorized' === status ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Source site rejected the shared secret. Set SAFE_PUBLISH_SHARED_SECRET in wp-config.php on both sites to the same value (at least 16 characters).',
					'safe-publish'
				) }
			</Notice>
		);
	}

	if ( 'unreachable' === status ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Source site could not be reached. Verify the connected site URL and that the source site is online.',
					'safe-publish'
				) }
			</Notice>
		);
	}

	// status === 'url_unset'
	const url = settingsUrl ?? '/wp-admin/admin.php?page=safe-publish-settings';

	return (
		<Notice status="warning" isDismissible={ false }>
			{ createInterpolateElement(
				__(
					'Source site URL is not configured. Set it on the <link>settings page</link> to enable imports.',
					'safe-publish'
				),
				{
					link: <a href={ url }>link</a>,
				}
			) }
		</Notice>
	);
}

export default AuthStatusNotice;

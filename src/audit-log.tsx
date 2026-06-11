/**
 * Audit Log page entry point.
 *
 * Mounts the AuditLogDataView component into the Audit Log container when
 * the DOM is ready. The component fetches paginated, filtered events from
 * `safe_publish_get_audit_events`.
 *
 * @file This file is the entry point for the Audit Log admin page.
 */
import { createRoot } from 'react-dom/client';

import { AuditLogDataView } from './components/AuditLogDataView';

import './style.scss';

document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'safe-publish-audit-log-container' );

	if ( ! container ) {
		return;
	}

	container.innerHTML = '';

	createRoot( container ).render( <AuditLogDataView /> );
} );

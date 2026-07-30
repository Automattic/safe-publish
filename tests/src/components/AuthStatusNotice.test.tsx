/**
 * Tests for the AuthStatusNotice status-to-banner mapping.
 *
 * The banner is the only signal an admin gets about the source-site auth state
 * before importing, so each status must map to its own message and level, and
 * an unrecognized status must never borrow another status' copy.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import AuthStatusNotice from '@/components/AuthStatusNotice';
import type { AuthStatus } from '@/types';

describe( 'AuthStatusNotice', () => {
	it( 'renders an error notice for a blocked probe, not the URL-unset copy', () => {
		// ARRANGE + ACT: Render the banner for an upstream block.
		const { container } = render( <AuthStatusNotice status="blocked" /> );

		// ASSERT: The block message shows at error level, and the URL-unset copy
		// (the former fall-through) is absent.
		expect( container.textContent ).toContain( 'blocked the request' );
		expect( container.textContent ).not.toContain( 'is not configured' );
		expect( container.querySelector( '.is-error' ) ).not.toBeNull();
	} );

	it( 'renders the settings link when the source URL is unset', () => {
		// ARRANGE + ACT: Render the banner for the url_unset status.
		render( <AuthStatusNotice status="url_unset" /> );

		// ASSERT: The notice points the admin at the settings page.
		expect(
			screen.getByRole( 'link', { name: 'settings page' } )
		).toBeInTheDocument();
	} );

	it( 'renders nothing when the source authorizes the request', () => {
		// ARRANGE + ACT: Render the banner for the authorized status.
		const { container } = render(
			<AuthStatusNotice status="authorized" />
		);

		// ASSERT: No banner is shown.
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders nothing for an unrecognized status', () => {
		// ARRANGE + ACT: Render with a status outside the known set.
		const { container } = render(
			<AuthStatusNotice status={ 'degraded' as AuthStatus } />
		);

		// ASSERT: Falls through to no banner rather than a wrong message.
		expect( container.firstChild ).toBeNull();
	} );
} );

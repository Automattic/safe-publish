/**
 * Keyboard-operable overflow region.
 *
 * @file This file defines the ScrollableRegion component.
 */

import type { KeyboardEvent, ReactNode } from 'react';

interface ScrollableRegionProps {
	ariaLabel: string;
	children: ReactNode;
	className?: string;
}

/**
 * Renders a named overflow region that can be scrolled from the keyboard.
 *
 * @param {ScrollableRegionProps} props           Component props.
 * @param {string}                props.ariaLabel Accessible region name.
 * @param {ReactNode}             props.children  Region contents.
 * @param {string}                props.className Optional CSS class.
 *
 * @return {JSX.Element} Scrollable region.
 */
export default function ScrollableRegion( {
	ariaLabel,
	children,
	className,
}: ScrollableRegionProps ): JSX.Element {
	const handleKeyDown = ( event: KeyboardEvent< HTMLDivElement > ): void => {
		const region = event.currentTarget;
		let nextScrollTop: number;

		switch ( event.key ) {
			case 'ArrowDown':
				nextScrollTop = region.scrollTop + 40;
				break;
			case 'ArrowUp':
				nextScrollTop = region.scrollTop - 40;
				break;
			case 'PageDown':
				nextScrollTop = region.scrollTop + region.clientHeight;
				break;
			case 'PageUp':
				nextScrollTop = region.scrollTop - region.clientHeight;
				break;
			case 'Home':
				nextScrollTop = 0;
				break;
			case 'End':
				nextScrollTop = region.scrollHeight;
				break;
			case ' ':
				nextScrollTop =
					region.scrollTop +
					( event.shiftKey
						? -region.clientHeight
						: region.clientHeight );
				break;
			default:
				return;
		}

		const maxScrollTop = Math.max(
			0,
			region.scrollHeight - region.clientHeight
		);
		nextScrollTop = Math.min( maxScrollTop, Math.max( 0, nextScrollTop ) );
		if ( nextScrollTop === region.scrollTop ) {
			return;
		}

		event.preventDefault();
		region.scrollTop = nextScrollTop;
	};

	return (
		// Firefox does not route scrolling keys to focused overflow regions.
		// This handler supplies the expected keyboard behavior.
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className={ className }
			role="region"
			aria-label={ ariaLabel }
			tabIndex={ 0 }
			onKeyDown={ handleKeyDown }
		>
			{ children }
		</div>
	);
}

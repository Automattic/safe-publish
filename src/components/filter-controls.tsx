/**
 * Shared filter toolbar primitives used by the Source Posts catalog and the
 * Imports → Posts/Failures tabs. Lifted out of the per-page DataView modules
 * so each page renders the same controls with identical styling and i18n.
 *
 * @file This file defines shared filter toolbar primitives.
 */
import { chevronDown } from '@wordpress/icons';

import {
	BaseControl,
	Button,
	DatePicker,
	Dropdown,
} from '@wordpress/components';
import { dateI18n, getDate, getSettings } from '@wordpress/date';
import { useMemo } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Regex matching pasted URLs or absolute paths. Catches the "I have this
 * post's link" workflow without needing to scope to a specific host.
 */
export const URL_OR_PATH_RE = /^(https?:\/\/[^\s]+|\/[^\s]+)/;

/**
 * Extracts a slug from a pasted URL or path.
 *
 * Drops query/hash, strips trailing slashes, and returns the last
 * non-empty path segment. Returns null when the input isn't URL-shaped,
 * no slug can be recovered, or the URL host doesn't match `validationUrl` —
 * pasting a URL from a different site would otherwise query for a slug it
 * doesn't have and silently return zero results.
 *
 * @param {string} raw           User input from the search box.
 * @param {string} validationUrl URL whose host must match the pasted URL
 *                               (e.g. the source URL for the catalog page,
 *                               the destination's home URL for Imports).
 *
 * @return {string|null} Slug suitable for `name=` lookup, or null.
 */
export function detectSlugFromInput(
	raw: string,
	validationUrl: string
): string | null {
	const trimmed = raw.trim();
	if ( ! URL_OR_PATH_RE.test( trimmed ) ) {
		return null;
	}

	const PLACEHOLDER_HOST = 'placeholder.example';
	let path = trimmed;
	try {
		const url = new URL( trimmed, `https://${ PLACEHOLDER_HOST }` );
		// Bare paths inherit the placeholder host; only validate when the
		// input was a full URL with its own host.
		if ( url.host !== PLACEHOLDER_HOST ) {
			try {
				const validationHost = new URL( validationUrl ).host;
				if ( url.host !== validationHost ) {
					return null;
				}
			} catch {
				// Validation URL unparseable; skip validation rather than block.
			}
		}
		path = url.pathname;
	} catch {
		// Already a bare path; fall through with `trimmed`.
	}

	const segments = path.split( '/' ).filter( ( seg ) => '' !== seg );
	const last = segments.pop();
	return last && '' !== last ? last : null;
}

/**
 * Serializes a Date to its local calendar day ("YYYY-MM-DD") for
 * lexicographic comparison against ISO date strings the toolbar stores.
 * Uses local parts (not toISOString) so a user picking April 30 doesn't
 * get the previous day in negative-UTC-offset timezones.
 *
 * @param {Date} date Date instance to serialize.
 * @return {string} Calendar day in YYYY-MM-DD form.
 */
export function toCalendarDay( date: Date ): string {
	return `${ date.getFullYear() }-${ String( date.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( date.getDate() ).padStart( 2, '0' ) }`;
}

/**
 * Converts a picker's calendar-day bounds ("YYYY-MM-DD") to UTC ISO 8601
 * datetimes anchored in the site timezone. The lower bound is site-local
 * 00:00; the upper bound is site-local 23:59:59. Picking site time matches
 * the admin columns, which already render in site time.
 *
 * @param {string|null} after  Lower-bound calendar day or null.
 * @param {string|null} before Upper-bound calendar day or null.
 * @return {Object} `{ afterUtc, beforeUtc }` with UTC ISO strings or null.
 */
export function calendarRangeToUtcBounds(
	after: string | null,
	before: string | null
): { afterUtc: string | null; beforeUtc: string | null } {
	return {
		afterUtc: after ? siteDayBoundaryToUtcIso( after, 'start' ) : null,
		beforeUtc: before ? siteDayBoundaryToUtcIso( before, 'end' ) : null,
	};
}

/**
 * Builds the UTC ISO string for either site-local midnight (start of day)
 * or site-local 23:59:59 (end of day) of the given calendar day. Defers
 * to @wordpress/date's site-zone-aware `getDate` so moment-timezone's
 * named-zone data carries the DST adjustment for us.
 *
 * @param {string}        calendarDay YYYY-MM-DD picked from the calendar.
 * @param {'start'|'end'} boundary    Which edge of the day to return.
 * @return {string} UTC ISO 8601 datetime.
 */
function siteDayBoundaryToUtcIso(
	calendarDay: string,
	boundary: 'start' | 'end'
): string {
	const time = 'start' === boundary ? '00:00:00' : '23:59:59';
	// Strip the .sss that toISOString always emits — the backend's
	// DATE_ATOM `P` token only accepts Z right after :s, not .sss.
	return getDate( `${ calendarDay }T${ time }` )
		.toISOString()
		.replace( /\.\d{3}Z$/, 'Z' );
}

/**
 * Formats a date-range toggle's label so the user can read the active
 * range at a glance.
 *
 * @param {string|null} after  ISO date for the after bound, or null.
 * @param {string|null} before ISO date for the before bound, or null.
 *
 * @return {string} Translated label for the toggle button.
 */
export function formatDateRangeLabel(
	after: string | null,
	before: string | null
): string {
	if ( null === after && null === before ) {
		return __( 'All dates', 'safe-publish' );
	}

	// Append `T00:00:00Z` so moment parses the calendar-day string as UTC
	// midnight (bare date-only strings are parsed as *local* midnight).
	// Combined with the `true` timezone arg (which formats in UTC), the
	// label round-trips the picked calendar day in any browser timezone.
	const fmt = ( iso: string ): string =>
		dateI18n( getSettings().formats.date, `${ iso }T00:00:00Z`, true );

	if ( null !== after && null !== before ) {
		// Collapse a same-day range to the single date rather than the
		// redundant "<date> – <date>".
		if ( after === before ) {
			return fmt( after );
		}
		return sprintf(
			/* translators: 1: start date, 2: end date */
			_x( '%1$s – %2$s', 'date range', 'safe-publish' ),
			fmt( after ),
			fmt( before )
		);
	}

	if ( null !== after ) {
		/* translators: %s: start date */
		return sprintf( __( 'From %s', 'safe-publish' ), fmt( after ) );
	}

	/* translators: %s: end date */
	return sprintf( __( 'To %s', 'safe-publish' ), fmt( before as string ) );
}

/**
 * One side of the date-range popover: a labeled DatePicker with a Clear
 * button that only renders when the bound is set.
 *
 * @param {Object}    props               Component props.
 * @param {string}    props.label         Heading shown above the picker.
 * @param {string?}   props.value         Currently selected ISO date, or null.
 * @param {Function}  props.onChange      Called with the new ISO date or null.
 * @param {Function?} props.isInvalidDate Optional predicate that disables
 *                                        dates the caller considers invalid.
 *
 * @return {JSX.Element} Labeled picker with conditional Clear button.
 */
function DateRangeColumn( {
	label,
	value,
	onChange,
	isInvalidDate,
}: {
	label: string;
	value: string | null;
	onChange: ( next: string | null ) => void;
	isInvalidDate?: ( date: Date ) => boolean;
} ): JSX.Element {
	// Honor WP's Settings → General → "Week Starts On" so the calendar
	// matches the rest of the admin.
	const startOfWeek = getSettings().l10n.startOfWeek;

	return (
		// role="group" with aria-label so screen readers can tell the two
		// DatePickers apart — both render their own "Calendar" label that
		// would otherwise read identically.
		<div
			className="safe-publish-date-picker-panel__group"
			role="group"
			aria-label={ label }
		>
			<p className="safe-publish-date-picker-panel__heading">{ label }</p>
			<DatePicker
				currentDate={ value ?? undefined }
				onChange={ ( next: string ) =>
					onChange( next ? next.slice( 0, 10 ) : null )
				}
				isInvalidDate={ isInvalidDate }
				startOfWeek={ startOfWeek }
			/>
			{ null !== value && (
				<Button variant="tertiary" onClick={ () => onChange( null ) }>
					{ __( 'Clear', 'safe-publish' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Date-range filter: a labeled dropdown whose popover holds two date
 * pickers, one for the after bound and one for the before bound. Each
 * column constrains the other so the range can't invert.
 *
 * @param {Object}      props          Component props.
 * @param {string}      props.label    Field label.
 * @param {string}      props.id       Element id for the toggle button.
 * @param {string|null} props.after    Current after bound, or null.
 * @param {string|null} props.before   Current before bound, or null.
 * @param {Function}    props.onChange Called when either bound changes.
 *
 * @return {JSX.Element} Rendered dropdown filter.
 */
export function DateRangeFilter( {
	label,
	id,
	after,
	before,
	onChange,
}: {
	label: string;
	id: string;
	after: string | null;
	before: string | null;
	onChange: (
		next: { after: string | null; before: string | null }
	) => void;
} ): JSX.Element {
	const dateLabel = useMemo(
		() => formatDateRangeLabel( after, before ),
		[ after, before ]
	);

	return (
		<div className="safe-publish-control safe-publish-control--dates">
			<BaseControl __nextHasNoMarginBottom label={ label } id={ id }>
				<Dropdown
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							id={ id }
							__next40pxDefaultSize
							variant="secondary"
							icon={ chevronDown }
							iconPosition="right"
							aria-expanded={ isOpen }
							onClick={ onToggle }
						>
							{ dateLabel }
						</Button>
					) }
					renderContent={ () => (
						<div className="safe-publish-date-picker-panel">
							<DateRangeColumn
								label={ __( 'From', 'safe-publish' ) }
								value={ after }
								isInvalidDate={
									before
										? ( date ) =>
												toCalendarDay( date ) > before
										: undefined
								}
								onChange={ ( next ) =>
									onChange( { after: next, before } )
								}
							/>
							<DateRangeColumn
								label={ __( 'To', 'safe-publish' ) }
								value={ before }
								isInvalidDate={
									after
										? ( date ) =>
												toCalendarDay( date ) < after
										: undefined
								}
								onChange={ ( next ) =>
									onChange( { after, before: next } )
								}
							/>
						</div>
					) }
				/>
			</BaseControl>
		</div>
	);
}

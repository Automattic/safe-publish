/**
 * Shared filter toolbar primitives used by the Manage listing and the
 * orphan-failures drawer.
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
 * Which site a pasted URL points at; 'unknown' when the host can't tell them
 * apart (a bare path, or a shared source/destination host).
 */
export type SlugOrigin = 'source' | 'destination' | 'unknown';

/**
 * A slug extracted from user input, tagged with the site it came from.
 */
export type SlugDetection = { slug: string; origin: SlugOrigin };

/**
 * Host of a URL, or '' when it can't be parsed.
 *
 * @param {string} url URL to parse.
 * @return {string} Host, or '' on failure.
 */
const hostOf = ( url: string ): string => {
	try {
		return new URL( url ).host;
	} catch {
		return '';
	}
};

/**
 * Extracts a slug from a pasted URL or path, tagged with its origin.
 *
 * Drops query/hash and returns the last path segment. Returns null for
 * non-URL input, an empty path, or a full URL whose host matches neither
 * connected site. Origin is 'source'/'destination' by matching host, else
 * 'unknown' — a bare path, an unparseable host pair, or source and
 * destination sharing a host (subdirectory multisite).
 *
 * @param {string} raw                 User input from the search box.
 * @param {Object} urls                Connected site URLs for attribution.
 * @param {string} urls.sourceUrl      Source site URL.
 * @param {string} urls.destinationUrl Destination site URL.
 *
 * @return {SlugDetection|null} Slug with its origin, or null.
 */
export function detectSlugFromInput(
	raw: string,
	{ sourceUrl, destinationUrl }: { sourceUrl: string; destinationUrl: string }
): SlugDetection | null {
	const trimmed = raw.trim();
	if ( ! URL_OR_PATH_RE.test( trimmed ) ) {
		return null;
	}

	const PLACEHOLDER_HOST = 'placeholder.example';
	let path = trimmed;
	let origin: SlugOrigin = 'unknown';
	try {
		const url = new URL( trimmed, `https://${ PLACEHOLDER_HOST }` );
		// Bare paths inherit the placeholder host; only attribute an origin
		// for a full URL with its own host.
		if ( url.host !== PLACEHOLDER_HOST ) {
			const sourceHost = hostOf( sourceUrl );
			const destHost = hostOf( destinationUrl );
			const matchesSource = '' !== sourceHost && url.host === sourceHost;
			const matchesDest = '' !== destHost && url.host === destHost;

			if ( matchesSource && ! matchesDest ) {
				origin = 'source';
			} else if ( matchesDest && ! matchesSource ) {
				origin = 'destination';
			} else if (
				! matchesSource
				&& ! matchesDest
				&& ( '' !== sourceHost || '' !== destHost )
			) {
				// A resolvable host matching neither site — reject the paste.
				return null;
			}
			// Both match → shared host → origin stays 'unknown'.
		}
		path = url.pathname;
	} catch {
		// Already a bare path; fall through with `trimmed`.
	}

	const segments = path.split( '/' ).filter( ( seg ) => '' !== seg );
	const last = segments.pop();
	return last && '' !== last ? { slug: last, origin } : null;
}

/**
 * Whether a detected slug's origin matches the active chip's slug column.
 *
 * Catalog-primary chips (All, Available) match the source slug; local-primary
 * chips (Up to date, Outdated) match the destination slug. An 'unknown' origin
 * matches any chip so bare paths stay best-effort.
 *
 * @param {SlugOrigin} origin           Detected origin of the pasted URL.
 * @param {boolean}    isCatalogPrimary Whether the active chip is catalog-primary.
 *
 * @return {boolean} True when the slug can be looked up on this chip.
 */
export function slugMatchesChip(
	origin: SlugOrigin,
	isCatalogPrimary: boolean
): boolean {
	if ( 'unknown' === origin ) {
		return true;
	}
	return origin === ( isCatalogPrimary ? 'source' : 'destination' );
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

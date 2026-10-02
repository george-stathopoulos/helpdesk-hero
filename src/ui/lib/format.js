/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
import { __, _n, sprintf } from '@wordpress/i18n';

const locale = document.documentElement.lang || undefined;

/**
 * USD amount with precision that adapts to size, so $0.0042 calls stay readable.
 *
 * @param {number}  value        Amount.
 * @param {Object}  [opts]       Options.
 * @param {boolean} [opts.short] Compact large values ($12.4K).
 * @return {string} Formatted amount.
 */
export function money( value, { short = false } = {} ) {
	const v = Number( value ) || 0;
	const abs = Math.abs( v );
	if ( short && abs >= 10000 ) {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency: 'USD',
			notation: 'compact',
			maximumFractionDigits: 1,
		} ).format( v );
	}
	if ( abs > 0 && abs < 0.0001 ) {
		return `<${ money( 0.0001 ) }`;
	}
	let digits = 2;
	if ( abs > 0 && abs < 0.01 ) {
		digits = 4;
	}
	if ( abs >= 1000 ) {
		digits = 0;
	}
	return new Intl.NumberFormat( locale, {
		style: 'currency',
		currency: 'USD',
		minimumFractionDigits: digits,
		maximumFractionDigits: digits,
	} ).format( v );
}

/**
 * Money split into whole and fractional parts for the hero figure.
 *
 * @param {number} value Amount.
 * @return {{whole: string, fraction: string}} Parts.
 */
export function moneyParts( value ) {
	const parts = new Intl.NumberFormat( locale, {
		style: 'currency',
		currency: 'USD',
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	} ).formatToParts( Number( value ) || 0 );
	let whole = '';
	let fraction = '';
	let afterDecimal = false;
	parts.forEach( ( p ) => {
		if ( p.type === 'decimal' ) {
			afterDecimal = true;
		}
		if ( afterDecimal ) {
			fraction += p.value;
		} else {
			whole += p.value;
		}
	} );
	return { whole, fraction };
}

/**
 * Compact integer: 1,284 / 12.9K / 4.2M.
 *
 * @param {number} value Number.
 * @return {string} Formatted.
 */
export function compact( value ) {
	const v = Number( value ) || 0;
	if ( Math.abs( v ) < 10000 ) {
		return new Intl.NumberFormat( locale ).format( Math.round( v ) );
	}
	return new Intl.NumberFormat( locale, {
		notation: 'compact',
		maximumFractionDigits: 1,
	} ).format( v );
}

/**
 * Full integer with grouping.
 *
 * @param {number} value Number.
 * @return {string} Formatted.
 */
export function integer( value ) {
	return new Intl.NumberFormat( locale ).format(
		Math.round( Number( value ) || 0 )
	);
}

/**
 * Milliseconds as "820 ms" or "4.2 s".
 *
 * @param {number} ms Milliseconds.
 * @return {string} Formatted.
 */
export function duration( ms ) {
	const v = Number( ms ) || 0;
	if ( v < 1000 ) {
		/* translators: %d: milliseconds. */
		return sprintf( __( '%d ms', 'helpdesk-hero' ), Math.round( v ) );
	}

	return sprintf(
		/* translators: %s: seconds. */ __( '%s s', 'helpdesk-hero' ),
		( v / 1000 ).toFixed( v < 10000 ? 1 : 0 )
	);
}

/**
 * Percent change between two values, or null when there is no baseline.
 *
 * @param {number} current  Current value.
 * @param {number} previous Previous value.
 * @return {number|null} Change in percent.
 */
export function change( current, previous ) {
	if ( ! previous ) {
		return null;
	}
	return ( ( current - previous ) / previous ) * 100;
}

/**
 * Parse a `YYYY-MM-DD HH:MM:SS` site-time string as a local Date.
 *
 * @param {string} value Date string.
 * @return {Date} Date.
 */
export function parseSiteDate( value ) {
	const [ d, t = '00:00:00' ] = String( value ).split( ' ' );
	const [ y, m, day ] = d.split( '-' ).map( Number );
	const [ hh, mm, ss ] = t.split( ':' ).map( Number );
	return new Date( y, m - 1, day, hh, mm, ss );
}

/**
 * Short day label: "Sep 14".
 *
 * @param {string} ymd `YYYY-MM-DD`.
 * @return {string} Label.
 */
export function dayLabel( ymd ) {
	return new Intl.DateTimeFormat( locale, {
		month: 'short',
		day: 'numeric',
	} ).format( parseSiteDate( ymd ) );
}

/**
 * Long day label: "Mon, Sep 14".
 *
 * @param {string} ymd `YYYY-MM-DD`.
 * @return {string} Label.
 */
export function dayLabelLong( ymd ) {
	return new Intl.DateTimeFormat( locale, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
	} ).format( parseSiteDate( ymd ) );
}

/**
 * Date and time: "Sep 14, 14:05".
 *
 * @param {string} value Site-time string.
 * @return {string} Label.
 */
export function dateTime( value ) {
	return new Intl.DateTimeFormat( locale, {
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} ).format( parseSiteDate( value ) );
}

/**
 * Relative time against the site clock: "3 min ago".
 *
 * @param {string} value   Site-time string.
 * @param {Date}   siteNow Current site time.
 * @return {string} Label.
 */
export function relative( value, siteNow ) {
	const diff = Math.max( 0, ( siteNow - parseSiteDate( value ) ) / 1000 );
	if ( diff < 60 ) {
		return __( 'just now', 'helpdesk-hero' );
	}
	const mins = Math.floor( diff / 60 );
	if ( mins < 60 ) {
		return sprintf(
			/* translators: %d: minutes. */ _n(
				'%d min ago',
				'%d min ago',
				mins,
				'helpdesk-hero'
			),
			mins
		);
	}
	const hours = Math.floor( mins / 60 );
	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: hours. */ _n(
				'%d hour ago',
				'%d hours ago',
				hours,
				'helpdesk-hero'
			),
			hours
		);
	}
	const days = Math.floor( hours / 24 );
	if ( days < 7 ) {
		return sprintf(
			/* translators: %d: days. */ _n(
				'%d day ago',
				'%d days ago',
				days,
				'helpdesk-hero'
			),
			days
		);
	}
	return dateTime( value );
}

/**
 * Initials for a source avatar.
 *
 * @param {string} label Name.
 * @return {string} One or two letters.
 */
export function initials( label ) {
	const words = String( label )
		.replace( /[^\p{L}\p{N} ]/gu, ' ' )
		.trim()
		.split( /\s+/ );
	if ( ! words[ 0 ] ) {
		return '?';
	}
	return (
		words[ 0 ][ 0 ] + ( words[ 1 ] ? words[ 1 ][ 0 ] : '' )
	).toUpperCase();
}

/**
 * "Nice" axis ticks from 0 to just above max.
 *
 * @param {number} max   Largest value.
 * @param {number} count Target tick count.
 * @return {number[]} Ticks.
 */
export function niceTicks( max, count = 4 ) {
	if ( ! max || max <= 0 ) {
		return [ 0, 1 ];
	}
	const raw = max / count;
	const pow = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
	const step = [ 1, 2, 2.5, 5, 10 ]
		.map( ( m ) => m * pow )
		.find( ( s ) => s >= raw );
	const ticks = [];
	for ( let v = 0; v <= max + step * 0.999; v += step ) {
		ticks.push( Number( v.toFixed( 10 ) ) );
	}
	return ticks;
}

/**
 * Full date with year: "October 1, 2026".
 *
 * @param {string} ymd `YYYY-MM-DD`.
 * @return {string} Label.
 */
export function dateLong( ymd ) {
	return new Intl.DateTimeFormat( locale, { dateStyle: 'long' } ).format(
		parseSiteDate( ymd )
	);
}

/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
/**
 * Dates from the REST API are ISO 8601 (UTC). These format them for the viewer's browser locale.
 */
const locale =
	( typeof document !== 'undefined' && document.documentElement.lang ) ||
	undefined;

/**
 * "Oct 2, 14:05" (adds the year when it isn't this year).
 *
 * @param {string} iso ISO date.
 * @return {string} Label.
 */
export function when( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	return new Intl.DateTimeFormat( locale, {
		month: 'short',
		day: 'numeric',
		year:
			d.getFullYear() === new Date().getFullYear()
				? undefined
				: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} ).format( d );
}

/**
 * "3 hours ago", "in 2 days".
 *
 * @param {string} iso ISO date.
 * @return {string} Label.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const diff = ( new Date( iso ) - Date.now() ) / 1000;
	const rtf = new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } );
	const abs = Math.abs( diff );
	if ( abs < 60 ) {
		return rtf.format( Math.round( diff ), 'second' );
	}
	if ( abs < 3600 ) {
		return rtf.format( Math.round( diff / 60 ), 'minute' );
	}
	if ( abs < 86400 ) {
		return rtf.format( Math.round( diff / 3600 ), 'hour' );
	}
	if ( abs < 86400 * 30 ) {
		return rtf.format( Math.round( diff / 86400 ), 'day' );
	}
	return when( iso );
}

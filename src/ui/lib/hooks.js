/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const THEME_KEY = 'hdh-theme';

/**
 * GET an endpoint and keep the last good response while refetching.
 *
 * @param {string} path API path relative to `gatehouse/v1`.
 * @return {{data: any, error: Error|null, loading: boolean, reload: Function}} State.
 */
export function useApi( path ) {
	const [ state, setState ] = useState( {
		data: null,
		error: null,
		loading: true,
	} );
	const latest = useRef( path );

	const load = useCallback( () => {
		latest.current = path;
		setState( ( s ) => ( { ...s, loading: true } ) );
		apiFetch( { path: `/helpdesk-hero/v1/${ path }` } )
			.then( ( data ) => {
				if ( latest.current === path ) {
					setState( { data, error: null, loading: false } );
				}
			} )
			.catch( ( error ) => {
				if ( latest.current === path ) {
					setState( ( s ) => ( { ...s, error, loading: false } ) );
				}
			} );
	}, [ path ] );

	useEffect( load, [ load ] );

	return { ...state, reload: load };
}

/**
 * Send a write request.
 *
 * @param {string} path   API path relative to `gatehouse/v1`.
 * @param {string} method HTTP method.
 * @param {Object} data   Body.
 * @return {Promise<any>} Response.
 */
export function send( path, method, data ) {
	return apiFetch( { path: `/helpdesk-hero/v1/${ path }`, method, data } );
}

/**
 * Current route from the URL hash.
 *
 * @param {string} fallback Default route.
 * @return {string} Route.
 */
function readRoute( fallback ) {
	return (
		window.location.hash.replace( /^#\/?/, '' ).split( '?' )[ 0 ] ||
		fallback
	);
}

/**
 * Hash router: `#/sources` → `sources`.
 *
 * @param {string} fallback Default route.
 * @return {[string, Function]} Route and navigate function.
 */
export function useRoute( fallback ) {
	const [ route, setRoute ] = useState( () => readRoute( fallback ) );

	useEffect( () => {
		const onHash = () => {
			setRoute( readRoute( fallback ) );
			window.scrollTo( { top: 0 } );
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [ fallback ] );

	const go = useCallback( ( next ) => {
		window.location.hash = `#/${ next }`;
	}, [] );

	return [ route, go ];
}

/**
 * Light/dark/system theme, remembered per browser.
 *
 * @return {[string, Function]} Theme and setter.
 */
export function useTheme() {
	const [ theme, setTheme ] = useState( () => {
		try {
			return window.localStorage.getItem( THEME_KEY ) || 'system';
		} catch ( e ) {
			return 'system';
		}
	} );

	const update = useCallback( ( next ) => {
		setTheme( next );
		try {
			window.localStorage.setItem( THEME_KEY, next );
		} catch ( e ) {}
	}, [] );

	return [ theme, update ];
}

/**
 * Remembered value per browser (date range etc.).
 *
 * @param {string} key      Storage key.
 * @param {any}    fallback Default.
 * @return {[any, Function]} Value and setter.
 */
export function useStored( key, fallback ) {
	const [ value, setValue ] = useState( () => {
		try {
			const raw = window.localStorage.getItem( `hdh-${ key }` );
			return raw === null ? fallback : JSON.parse( raw );
		} catch ( e ) {
			return fallback;
		}
	} );
	const update = useCallback(
		( next ) => {
			setValue( next );
			try {
				window.localStorage.setItem(
					`hdh-${ key }`,
					JSON.stringify( next )
				);
			} catch ( e ) {}
		},
		[ key ]
	);
	return [ value, update ];
}

/**
 * Width of an element, kept up to date.
 *
 * @return {[Object, number]} Ref and width.
 */
export function useWidth() {
	const ref = useRef( null );
	const [ width, setWidth ] = useState( 0 );
	useEffect( () => {
		if ( ! ref.current ) {
			return undefined;
		}
		const observer = new window.ResizeObserver( ( entries ) => {
			setWidth( Math.floor( entries[ 0 ].contentRect.width ) );
		} );
		observer.observe( ref.current );
		return () => observer.disconnect();
	}, [] );
	return [ ref, width ];
}

/**
 * Site "now" for relative times (the server stores site-local time).
 *
 * @return {Date} Now in site time.
 */
export function siteNow() {
	const offset = window.hdhBoot?.gmtOffset;
	if ( typeof offset !== 'number' ) {
		return new Date();
	}
	const utc = Date.now() + new Date().getTimezoneOffset() * 60000;
	return new Date( utc + offset * 3600000 );
}

/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
/**
 * Light text formatting for messages: **bold**, *italic*, `code`, ```code blocks```,
 * [links](https://…), bare links, and "- " or "1. " lists. Messages are stored as plain text, so
 * emails and help desks get readable text; here they're drawn as formatted text. Only React
 * elements are created (never raw HTML), and links must be http(s) or mailto.
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import Icon from './Icon';

const SAFE_URL = /^(https?:\/\/|mailto:)/i;

/**
 * Inline formatting in one line of text.
 *
 * @param {string} text Text.
 * @param {string} key  Key prefix.
 * @return {Array} Nodes.
 */
function inline( text, key ) {
	const out = [];
	// Order matters: code first (nothing inside is formatted), then links, bold, italic, bare URLs.
	const re = /(`[^`\n]+`)|(\[[^\]\n]+\]\((?:https?:\/\/|mailto:)[^\s)]+\))|(\*\*[^*\n]+\*\*)|((?<![\w*])\*(?![\s*])[^*\n]+?(?<!\s)\*(?![\w*])|(?<!\w)_(?!\s)[^_\n]+?(?<!\s)_(?!\w))|(https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"])/g;
	let last = 0;
	let m;
	let n = 0;
	while ( ( m = re.exec( text ) ) !== null ) {
		if ( m.index > last ) {
			out.push( text.slice( last, m.index ) );
		}
		const k = `${ key }-${ n++ }`;
		if ( m[ 1 ] ) {
			out.push( <code key={ k }>{ m[ 1 ].slice( 1, -1 ) }</code> );
		} else if ( m[ 2 ] ) {
			const label = m[ 2 ].slice( 1, m[ 2 ].indexOf( '](' ) );
			const url = m[ 2 ].slice( m[ 2 ].indexOf( '](' ) + 2, -1 );
			out.push(
				SAFE_URL.test( url ) ? (
					<a key={ k } href={ url } target="_blank" rel="noopener noreferrer nofollow">
						{ label }
					</a>
				) : (
					m[ 2 ]
				)
			);
		} else if ( m[ 3 ] ) {
			out.push( <strong key={ k }>{ inline( m[ 3 ].slice( 2, -2 ), k ) }</strong> );
		} else if ( m[ 4 ] ) {
			out.push( <em key={ k }>{ m[ 4 ].slice( 1, -1 ) }</em> );
		} else if ( m[ 5 ] ) {
			out.push(
				<a key={ k } href={ m[ 5 ] } target="_blank" rel="noopener noreferrer nofollow">
					{ m[ 5 ] }
				</a>
			);
		}
		last = re.lastIndex;
	}
	if ( last < text.length ) {
		out.push( text.slice( last ) );
	}
	return out;
}

/**
 * A message with light formatting.
 *
 * @param {Object} props      Props.
 * @param {string} props.text Plain text with formatting marks.
 * @return {JSX.Element} Formatted text.
 */
export function RichText( { text } ) {
	const lines = String( text || '' ).split( '\n' );
	const blocks = [];
	let i = 0;
	while ( i < lines.length ) {
		const line = lines[ i ];
		if ( /^```/.test( line.trim() ) ) {
			const code = [];
			i++;
			while ( i < lines.length && ! /^```/.test( lines[ i ].trim() ) ) {
				code.push( lines[ i ] );
				i++;
			}
			i++;
			blocks.push( { type: 'code', text: code.join( '\n' ) } );
			continue;
		}
		const bullet = /^\s*[-*•]\s+(.*)$/.exec( line );
		const number = /^\s*\d+[.)]\s+(.*)$/.exec( line );
		if ( bullet || number ) {
			const type = bullet ? 'ul' : 'ol';
			const items = [];
			while ( i < lines.length ) {
				const b = /^\s*[-*•]\s+(.*)$/.exec( lines[ i ] );
				const o = /^\s*\d+[.)]\s+(.*)$/.exec( lines[ i ] );
				if ( ( type === 'ul' && b ) || ( type === 'ol' && o ) ) {
					items.push( ( b || o )[ 1 ] );
					i++;
				} else {
					break;
				}
			}
			blocks.push( { type, items } );
			continue;
		}
		const text = [];
		while (
			i < lines.length &&
			! /^```/.test( lines[ i ].trim() ) &&
			! /^\s*([-*•]|\d+[.)])\s+/.test( lines[ i ] )
		) {
			text.push( lines[ i ] );
			i++;
		}
		blocks.push( { type: 'text', text: text.join( '\n' ) } );
	}
	return (
		<>
			{ blocks.map( ( b, k ) => {
				if ( b.type === 'code' ) {
					return (
						<pre key={ k } className="hdh-rich__pre">
							<code>{ b.text }</code>
						</pre>
					);
				}
				if ( b.type === 'ul' || b.type === 'ol' ) {
					const List = b.type;
					return (
						<List key={ k } className="hdh-rich__list">
							{ b.items.map( ( item, j ) => (
								<li key={ j }>{ inline( item, `${ k }-${ j }` ) }</li>
							) ) }
						</List>
					);
				}
				return <Fragment key={ k }>{ inline( b.text, String( k ) ) }</Fragment>;
			} ) }
		</>
	);
}

/**
 * Toolbar that adds formatting marks to a textarea.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.target   Ref to the textarea.
 * @param {string}   props.value    Text.
 * @param {Function} props.onChange ( text ) => void.
 * @return {JSX.Element} Toolbar.
 */
export function FormatBar( { target, value, onChange } ) {
	const apply = ( kind ) => {
		const el = target.current;
		const text = value || '';
		const start = el ? el.selectionStart : text.length;
		const end = el ? el.selectionEnd : text.length;
		const picked = text.slice( start, end );
		let insert;
		let selFrom;
		let selTo;
		if ( kind === 'list' || kind === 'numbers' ) {
			// Each selected line (or the current one) becomes a list item.
			const lineStart = text.lastIndexOf( '\n', start - 1 ) + 1;
			const block = text.slice( lineStart, end ) || '';
			const mark = ( n ) => ( kind === 'list' ? '- ' : `${ n + 1 }. ` );
			const lines = block.split( '\n' ).map( ( l, n ) => mark( n ) + l.replace( /^\s*([-*•]|\d+[.)])\s+/, '' ) );
			insert = lines.join( '\n' );
			const next = text.slice( 0, lineStart ) + insert + text.slice( end );
			onChange( next );
			selFrom = selTo = lineStart + insert.length;
		} else {
			const wrap = {
				bold: [ '**', '**', __( 'bold text', 'helpdesk-hero' ) ],
				italic: [ '*', '*', __( 'italic text', 'helpdesk-hero' ) ],
				code: picked.includes( '\n' ) ? [ '```\n', '\n```', 'code' ] : [ '`', '`', 'code' ],
				link: [ '[', '](https://)', __( 'link text', 'helpdesk-hero' ) ],
			}[ kind ];
			const inner = picked || wrap[ 2 ];
			insert = wrap[ 0 ] + inner + wrap[ 1 ];
			onChange( text.slice( 0, start ) + insert + text.slice( end ) );
			if ( kind === 'link' ) {
				// Select the address so it can be pasted over.
				selFrom = start + wrap[ 0 ].length + inner.length + 2;
				selTo = selFrom + 'https://'.length;
			} else {
				selFrom = start + wrap[ 0 ].length;
				selTo = selFrom + inner.length;
			}
		}
		window.requestAnimationFrame( () => {
			if ( el ) {
				el.focus();
				el.setSelectionRange( selFrom, selTo );
			}
		} );
	};
	const buttons = [
		[ 'bold', 'bold', __( 'Bold', 'helpdesk-hero' ) ],
		[ 'italic', 'italic', __( 'Italic', 'helpdesk-hero' ) ],
		[ 'link', 'link', __( 'Link', 'helpdesk-hero' ) ],
		[ 'list', 'list', __( 'Bulleted list', 'helpdesk-hero' ) ],
		[ 'numbers', 'numbers', __( 'Numbered list', 'helpdesk-hero' ) ],
		[ 'code', 'code', __( 'Code', 'helpdesk-hero' ) ],
	];
	return (
		<div className="hdh-formatbar" role="toolbar" aria-label={ __( 'Formatting', 'helpdesk-hero' ) }>
			{ buttons.map( ( [ kind, icon, label ] ) => (
				<button
					key={ kind }
					type="button"
					className="hdh-formatbar__btn"
					title={ label }
					aria-label={ label }
					onMouseDown={ ( e ) => e.preventDefault() }
					onClick={ () => apply( kind ) }
				>
					<Icon name={ icon } size={ 15 } />
				</button>
			) ) }
		</div>
	);
}

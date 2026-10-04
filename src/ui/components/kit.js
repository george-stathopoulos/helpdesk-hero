/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
/**
 * Helpdesk Hero building blocks on top of the Gatehouse kit (ui.js): form fields, the
 * conversation thread, health flags, copy boxes and status pills.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef, createRoot } from '@wordpress/element';
import Icon from './Icon';
import { RichText, FormatBar } from './rich';
import { Button, Pill } from './ui';
import { when, ago } from '../lib/time';

/* ------------------------------------------------------------------ Fields */

let fieldId = 0;

export function useId( prefix = 'hdh-f' ) {
	const [ id ] = useState( () => `${ prefix }-${ ++fieldId }` );
	return id;
}

export function Field( {
	label,
	help,
	children,
	id,
	className = '',
	hideLabel = false,
} ) {
	return (
		<div className={ `hdh-field ${ className }` }>
			{ label && (
				<label
					htmlFor={ id }
					className={ hideLabel ? 'hdh-sr' : undefined }
				>
					{ label }
				</label>
			) }
			{ children }
			{ help && <div className="hdh-field__help">{ help }</div> }
		</div>
	);
}

export function TextField( {
	label,
	help,
	value,
	onChange,
	type = 'text',
	placeholder,
	className,
	hideLabel,
	...rest
} ) {
	const id = useId();
	return (
		<Field
			label={ label }
			help={ help }
			id={ id }
			className={ className }
			hideLabel={ hideLabel }
		>
			<input
				id={ id }
				className="hdh-input"
				type={ type }
				value={ value ?? '' }
				placeholder={ placeholder }
				onChange={ ( e ) => onChange( e.target.value ) }
				{ ...rest }
			/>
		</Field>
	);
}

export function TextArea( {
	label,
	help,
	value,
	onChange,
	rows = 6,
	placeholder,
	className,
	formatting = false,
	...rest
} ) {
	const id = useId();
	const ref = useRef( null );
	return (
		<Field label={ label } help={ help } id={ id } className={ className }>
			{ formatting && <FormatBar target={ ref } value={ value ?? '' } onChange={ onChange } /> }
			<textarea
				ref={ ref }
				id={ id }
				className={ `hdh-input hdh-textarea ${ formatting ? 'has-formatbar' : '' }` }
				rows={ rows }
				value={ value ?? '' }
				placeholder={ placeholder }
				onChange={ ( e ) => onChange( e.target.value ) }
				{ ...rest }
			/>
		</Field>
	);
}

export function SelectField( {
	label,
	help,
	value,
	onChange,
	options,
	className = '',
	hideLabel = false,
	...rest
} ) {
	const id = useId();
	return (
		<Field
			label={ label }
			help={ help }
			id={ id }
			hideLabel={ hideLabel }
			className={ `${ className } ${ hideLabel ? 'is-compact' : '' }` }
		>
			<select
				id={ id }
				className="hdh-input hdh-select"
				value={ value ?? '' }
				onChange={ ( e ) => onChange( e.target.value ) }
				{ ...rest }
			>
				{ options.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ o.label }
					</option>
				) ) }
			</select>
		</Field>
	);
}

/**
 * Checkbox row with a title, description and an optional lock.
 *
 * @param {Object}   props           Props.
 * @param {boolean}  props.checked   Checked.
 * @param {Function} props.onChange  Change handler.
 * @param {string}   props.label     Label.
 * @param {string}   props.desc      Description.
 * @param {boolean}  props.disabled  Disabled.
 * @param {string}   props.locked    Why it can't be changed (shows a lock).
 * @param {boolean}  props.hideLabel Keep the label for screen readers only.
 * @return {JSX.Element} Row.
 */
export function Check( {
	checked,
	onChange,
	label,
	desc,
	disabled,
	locked,
	hideLabel,
} ) {
	const id = useId( 'hdh-c' );
	return (
		<label
			htmlFor={ id }
			className={ `hdh-check ${ disabled ? 'is-disabled' : '' }` }
		>
			<input
				id={ id }
				type="checkbox"
				checked={ !! checked }
				disabled={ disabled }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
			<span className="hdh-check__text">
				<span
					className={ `hdh-check__label${
						hideLabel ? ' hdh-sr' : ''
					}` }
				>
					{ label }
					{ locked && (
						<span
							className="hdh-check__lock"
							title={ locked }
							aria-label={ locked }
						>
							<Icon name="lock" size={ 12 } />
						</span>
					) }
				</span>
				{ desc && <span className="hdh-check__desc">{ desc }</span> }
			</span>
		</label>
	);
}

/* ------------------------------------------------------------------ Thread */

/**
 * Conversation.
 *
 * @param {Object} props          Props.
 * @param {Array}  props.items    Items: { id, from: 'support'|'you'|'system'|'event', author, body, time }.
 * @param {string} props.youLabel Name for the site owner's messages without an author.
 * @return {JSX.Element} Thread.
 */
export function Thread( { items, youLabel } ) {
	return (
		<ol className="hdh-thread">
			{ items.map( ( m ) =>
				m.from === 'event' || m.from === 'system' ? (
					<li key={ m.id } className="hdh-thread__event">
						<span>{ m.body }</span>
						<time dateTime={ m.time } title={ when( m.time ) }>
							{ ago( m.time ) }
						</time>
					</li>
				) : (
					<li
						key={ m.id }
						className={ `hdh-msg ${
							{ support: 'is-support', note: 'is-note' }[
								m.from
							] || 'is-you'
						}` }
					>
						<div className="hdh-msg__head">
							<span
								className="hdh-msg__avatar"
								aria-hidden="true"
							>
								{ ( m.author || '?' )
									.trim()
									.charAt( 0 )
									.toUpperCase() }
							</span>
							<strong>
								{ m.author ||
									( m.from === 'you' ? youLabel : '' ) }
							</strong>
							{ m.from === 'note' && (
								<span className="hdh-msg__note">
									<Icon name="lock" size={ 11 } />
									{ __( 'Internal note · the customer doesn’t see this', 'helpdesk-hero' ) }
								</span>
							) }
							<time
								dateTime={ m.time }
								title={ when( m.time ) }
								className="hdh-muted"
							>
								{ ago( m.time ) }
							</time>
						</div>
						{ m.body && (
							<div className="hdh-msg__body">
								<RichText text={ m.body } />
							</div>
						) }
						<Attachments list={ m.attachments } />
						{ m.spots > 0 && (
							<button
								type="button"
								className="hdh-spot-chip"
								onClick={ () => {
									const panel = document.getElementById( 'hdh-spots' );
									if ( panel ) {
										panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
									}
								} }
							>
								<Icon name="pin" size={ 13 } />
								{ sprintf(
									/* translators: %d: number of problem spots */
									_n( '%d problem spot', '%d problem spots', m.spots, 'helpdesk-hero' ),
									m.spots
								) }
							</button>
						) }
					</li>
				)
			) }
		</ol>
	);
}

/**
 * Files attached to a message: image previews and download links.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.list [ { id, name, size, type, url, image } ].
 * @return {JSX.Element|null} List.
 */
export function Attachments( { list } ) {
	if ( ! list || ! list.length ) {
		return null;
	}
	return (
		<ul className="hdh-attachments">
			{ list.map( ( a ) => (
				<li key={ a.id }>
					<a href={ a.url } target="_blank" rel="noopener noreferrer">
						{ a.image ? (
							<img src={ a.url } alt={ a.name } loading="lazy" />
						) : (
							<Icon name="file" size={ 16 } />
						) }
						<span className="hdh-attachments__name">{ a.name }</span>
						<span className="hdh-muted">{ fileSize( a.size ) }</span>
					</a>
				</li>
			) ) }
		</ul>
	);
}

/**
 * "1.2 MB".
 *
 * @param {number} bytes Bytes.
 * @return {string} Size.
 */
export function fileSize( bytes ) {
	if ( ! bytes ) {
		return '';
	}
	if ( bytes < 1024 * 1024 ) {
		return `${ Math.max( 1, Math.round( bytes / 1024 ) ) } KB`;
	}
	return `${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB`;
}

/* ------------------------------------------------------------------ Flags */

const LEVEL_TONE = { critical: 'critical', warning: 'warning', info: '' };

export function FlagList( { flags, empty } ) {
	if ( ! flags || ! flags.length ) {
		return (
			<p className="hdh-flaglist__ok">
				<Icon name="check" size={ 15 } />
				{ empty ||
					__(
						'No problems found in configuration, plugins or error logs.',
						'helpdesk-hero'
					) }
			</p>
		);
	}
	const levelLabels = {
		critical: __( 'Critical', 'helpdesk-hero' ),
		warning: __( 'Warning', 'helpdesk-hero' ),
		info: __( 'Info', 'helpdesk-hero' ),
	};
	return (
		<ul className="hdh-flaglist">
			{ flags.map( ( f, i ) => (
				<li key={ i } className={ `is-${ f.level }` }>
					<Pill tone={ LEVEL_TONE[ f.level ] } dot>
						{ levelLabels[ f.level ] || levelLabels.info }
					</Pill>
					<div>
						<strong>{ f.title }</strong>
						{ f.detail && <p>{ f.detail }</p> }
					</div>
				</li>
			) ) }
		</ul>
	);
}

/* ------------------------------------------------------------------ Copy */

function copyText( text ) {
	if ( window.navigator.clipboard ) {
		return window.navigator.clipboard.writeText( text );
	}
	const area = document.createElement( 'textarea' );
	area.value = text;
	document.body.appendChild( area );
	area.select();
	document.execCommand( 'copy' );
	area.remove();
	return Promise.resolve();
}

export function CopyBox( { value, label, note, rows } ) {
	const [ copied, setCopied ] = useState( false );
	const multiline = rows || String( value ).includes( '\n' );
	return (
		<div className="hdh-copybox">
			{ multiline ? (
				<textarea
					className="hdh-input hdh-copybox__value is-multi"
					readOnly
					rows={ rows || 10 }
					value={ value }
					aria-label={ label }
					onFocus={ ( e ) => e.target.select() }
				/>
			) : (
				<input
					className="hdh-input hdh-copybox__value"
					readOnly
					value={ value }
					aria-label={ label }
					onFocus={ ( e ) => e.target.select() }
				/>
			) }
			<div className="hdh-row">
				<Button
					variant="primary"
					size="sm"
					icon={ copied ? 'check' : 'copy' }
					onClick={ () =>
						copyText( value ).then( () => {
							setCopied( true );
							window.setTimeout( () => setCopied( false ), 1800 );
						} )
					}
				>
					{ copied
						? __( 'Copied', 'helpdesk-hero' )
						: label || __( 'Copy', 'helpdesk-hero' ) }
				</Button>
				{ note && <span className="hdh-muted">{ note }</span> }
			</div>
		</div>
	);
}

export function CodeBlock( { text, maxHeight = 360 } ) {
	return (
		<pre className="hdh-code" style={ { maxHeight } }>
			{ text }
		</pre>
	);
}

/* ------------------------------------------------------------------ Status */

export function TicketStatus( { status, labels } ) {
	const tones = {
		open: 'accent',
		pending: 'warning',
		sent: 'good',
		closed: '',
	};
	return (
		<Pill tone={ tones[ status ] || '' } dot>
			{ labels[ status ] || status }
		</Pill>
	);
}

/**
 * Billing state of a ticket or time entry, colour-coded: unbilled (amber), invoiced (blue),
 * paid (green).
 *
 * @param {Object}  props         Props.
 * @param {string}  props.state   unbilled | invoiced | paid.
 * @param {boolean} props.compact Icon only (with the state as its tooltip).
 * @return {JSX.Element|null} Badge.
 */
export function BillingBadge( { state, compact } ) {
	const labels = {
		unbilled: __( 'Unbilled', 'helpdesk-hero' ),
		invoiced: __( 'Invoiced', 'helpdesk-hero' ),
		paid: __( 'Paid', 'helpdesk-hero' ),
	};
	if ( ! labels[ state ] ) {
		return null;
	}
	return (
		<span
			className={ `hdh-bill is-${ state } ${ compact ? 'is-compact' : '' }` }
			title={ labels[ state ] }
		>
			<Icon name="receipt" size={ 13 } />
			{ compact ? (
				<span className="screen-reader-text">{ labels[ state ] }</span>
			) : (
				labels[ state ]
			) }
		</span>
	);
}

/**
 * Whether an access end time means "no end date" (stored as the year 9999).
 *
 * @param {string} expires ISO date.
 * @return {boolean} Permanent.
 */
export function isPermanentAccess( expires ) {
	return !! expires && parseInt( String( expires ).slice( 0, 4 ), 10 ) >= 9000;
}

export function AccessState( { active, expires, emptyLabel } ) {
	if ( active && isPermanentAccess( expires ) ) {
		return (
			<span className="hdh-access">
				<span className="hdh-access__dot" />
				{ __( 'Access with no end date', 'helpdesk-hero' ) }
			</span>
		);
	}
	if ( ! active ) {
		return (
			<span className="hdh-access is-off">
				<span className="hdh-access__dot" />
				{ emptyLabel || __( 'No access', 'helpdesk-hero' ) }
			</span>
		);
	}
	return (
		<span className="hdh-access" title={ when( expires ) }>
			<span className="hdh-access__dot" />
			{
				/* translators: %s: relative time, e.g. "in 3 hours". */
				__( 'Access ends', 'helpdesk-hero' )
			}{ ' ' }
			{ ago( expires ) }
		</span>
	);
}

/**
 * Key/value list.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.rows Rows: [ term, value ] (null rows are skipped).
 * @return {JSX.Element} List.
 */
export function KeyValues( { rows } ) {
	return (
		<dl className="hdh-dl">
			{ rows
				.filter( ( r ) => r && r[ 1 ] !== null && r[ 1 ] !== undefined )
				.map( ( [ k, v ] ) => (
					<div key={ k } style={ { display: 'contents' } }>
						<dt>{ k }</dt>
						<dd>{ v }</dd>
					</div>
				) ) }
		</dl>
	);
}

/**
 * Ask the user to confirm an action.
 *
 * @param {string} message Question.
 * @return {boolean} Confirmed.
 */
/**
 * Ask before doing something that can't be undone, in an in-app dialog. Unlike window.confirm(),
 * this works where browsers block dialogs (sandboxed frames such as WordPress Playground).
 *
 * @param {string} message             Question.
 * @param {Object} options             Options.
 * @param {string} options.confirmText Label of the confirm button.
 * @return {Promise<boolean>} Whether the person confirmed.
 */
export function confirmAction( message, { confirmText } = {} ) {
	return new Promise( ( resolve ) => {
		const doc = window.document;
		const host = doc.createElement( 'div' );
		( doc.querySelector( '.hdh-root' ) || doc.body ).appendChild( host );
		const root = createRoot( host );
		const done = ( answer ) => {
			root.unmount();
			host.remove();
			resolve( answer );
		};
		root.render(
			<ConfirmDialog
				message={ message }
				confirmText={ confirmText }
				onDone={ done }
			/>
		);
	} );
}

function ConfirmDialog( { message, confirmText, onDone } ) {
	const ok = useRef( null );
	useEffect( () => {
		const previous = window.document.activeElement;
		ok.current?.focus();
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				onDone( false );
			}
		};
		window.document.addEventListener( 'keydown', onKey );
		return () => {
			window.document.removeEventListener( 'keydown', onKey );
			previous?.focus?.();
		};
	}, [ onDone ] );
	return (
		<>
			<div
				className="hdh-scrim"
				aria-hidden="true"
				onClick={ () => onDone( false ) }
			/>
			<div
				className="hdh-dialog"
				role="alertdialog"
				aria-modal="true"
				aria-describedby="hdh-dialog-text"
			>
				<p id="hdh-dialog-text" className="hdh-dialog__text">
					{ message }
				</p>
				<div className="hdh-dialog__actions">
					<Button variant="ghost" onClick={ () => onDone( false ) }>
						{ __( 'Cancel', 'helpdesk-hero' ) }
					</Button>
					<button
						ref={ ok }
						type="button"
						className="hdh-btn is-primary"
						onClick={ () => onDone( true ) }
					>
						{ confirmText || __( 'Confirm', 'helpdesk-hero' ) }
					</button>
				</div>
			</div>
		</>
	);
}

/* ------------------------------------------------------------------ Donut */

const SLOT_COLORS = [ 1, 2, 3, 4, 5, 6, 7, 8 ].map(
	( n ) => `var(--hdh-s${ n })`
);

/**
 * Donut chart with a legend that doubles as the data table.
 *
 * @param {Object} props        Props.
 * @param {Array}  props.items  [{ label, value, color }].
 * @param {string} props.title  Accessible name.
 * @param {string} props.center Text in the middle (defaults to the total).
 * @return {JSX.Element} Chart.
 */
export function Donut( { items, title, center } ) {
	const total = items.reduce( ( s, i ) => s + i.value, 0 );
	const r = 52;
	const c = 2 * Math.PI * r;
	let offset = 0;
	return (
		<div className="hdh-donut">
			<svg
				viewBox="0 0 140 140"
				role="img"
				aria-label={ title }
				className="hdh-donut__svg"
			>
				<circle
					cx="70"
					cy="70"
					r={ r }
					fill="none"
					stroke="var(--hdh-surface-3)"
					strokeWidth="18"
				/>
				{ total > 0 &&
					items.map( ( item, i ) => {
						const len = ( item.value / total ) * c;
						const seg = (
							<circle
								key={ item.label }
								cx="70"
								cy="70"
								r={ r }
								fill="none"
								stroke={
									item.color ||
									SLOT_COLORS[ i % SLOT_COLORS.length ]
								}
								strokeWidth="18"
								strokeDasharray={ `${ Math.max(
									0,
									len - 1.5
								) } ${ c }` }
								strokeDashoffset={ -offset }
								transform="rotate(-90 70 70)"
							>
								<title>{ `${ item.label }: ${ item.value }` }</title>
							</circle>
						);
						offset += len;
						return seg;
					} ) }
				<text
					x="70"
					y="66"
					textAnchor="middle"
					className="hdh-donut__total"
				>
					{ center !== undefined ? center : total }
				</text>
				<text
					x="70"
					y="84"
					textAnchor="middle"
					className="hdh-donut__caption"
				>
					{ __( 'total', 'helpdesk-hero' ) }
				</text>
			</svg>
			<ul className="hdh-donut__legend">
				{ items.map( ( item, i ) => (
					<li key={ item.label }>
						<span
							className="hdh-donut__swatch"
							style={ {
								background:
									item.color ||
									SLOT_COLORS[ i % SLOT_COLORS.length ],
							} }
						/>
						<span className="hdh-donut__label">{ item.label }</span>
						<span className="hdh-donut__value">{ item.value }</span>
						<span className="hdh-donut__pct">
							{ total
								? Math.round( ( item.value / total ) * 100 )
								: 0 }
							%
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/* ------------------------------------------------------------------ Bits */

export function Stars( { value, size = 14 } ) {
	return (
		<span className="hdh-stars" role="img" aria-label={ `${ value } / 5` }>
			{ [ 1, 2, 3, 4, 5 ].map( ( n ) => (
				<svg
					key={ n }
					width={ size }
					height={ size }
					viewBox="0 0 24 24"
					aria-hidden="true"
					className={ n <= value ? 'is-on' : '' }
				>
					<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z" />
				</svg>
			) ) }
		</span>
	);
}

export function TagPills( { tags } ) {
	if ( ! tags || ! tags.length ) {
		return null;
	}
	return (
		<span className="hdh-tagpills">
			{ tags.map( ( t ) => (
				<span
					key={ t.id || t.name }
					className="hdh-tagpill"
					style={ { '--tag': t.color } }
				>
					{ t.name }
				</span>
			) ) }
		</span>
	);
}

/* ------------------------------------------------------------------ Lists */

export const PAGE_SIZES = [ 5, 10, 20, 30, 50, 100 ];

/**
 * "Sort by" select with a direction toggle.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.options  [ { value, label } ].
 * @param {string}   props.value    Sort key.
 * @param {string}   props.order    asc | desc.
 * @param {Function} props.onChange ( value, order ) => void.
 * @return {JSX.Element} Control.
 */
export function SortControl( { options, value, order, onChange } ) {
	return (
		<span className="hdh-sort">
			<select
				className="hdh-input hdh-select"
				aria-label={ __( 'Sort by', 'helpdesk-hero' ) }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value, order ) }
			>
				{ options.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ sprintf(
							/* translators: %s: what the list is sorted by */
							__( 'Sort: %s', 'helpdesk-hero' ),
							o.label
						) }
					</option>
				) ) }
			</select>
			<button
				type="button"
				className="hdh-btn is-sm hdh-sort__dir"
				onClick={ () =>
					onChange( value, order === 'asc' ? 'desc' : 'asc' )
				}
				aria-label={
					order === 'asc'
						? __( 'Ascending. Switch to descending', 'helpdesk-hero' )
						: __( 'Descending. Switch to ascending', 'helpdesk-hero' )
				}
				title={
					order === 'asc'
						? __( 'Ascending', 'helpdesk-hero' )
						: __( 'Descending', 'helpdesk-hero' )
				}
			>
				{ order === 'asc' ? '↑' : '↓' }
			</button>
		</span>
	);
}

/**
 * Page size and page navigation under a list.
 *
 * @param {Object}   props         Props.
 * @param {number}   props.page    Current page (1-based).
 * @param {number}   props.perPage Rows per page.
 * @param {number}   props.total   Total rows.
 * @param {Function} props.onPage  ( page ) => void.
 * @param {Function} props.onPer   ( perPage ) => void.
 * @return {JSX.Element|null} Pager.
 */
export function Pager( { page, perPage, total, onPage, onPer } ) {
	if ( ! total ) {
		return null;
	}
	const pages = Math.max( 1, Math.ceil( total / perPage ) );
	const from = ( page - 1 ) * perPage + 1;
	const to = Math.min( total, page * perPage );
	return (
		<div className="hdh-pager">
			<label className="hdh-pager__size">
				<span>{ __( 'Rows per page', 'helpdesk-hero' ) }</span>
				<select
					className="hdh-input hdh-select"
					value={ perPage }
					onChange={ ( e ) => onPer( parseInt( e.target.value, 10 ) ) }
				>
					{ PAGE_SIZES.map( ( n ) => (
						<option key={ n } value={ n }>
							{ n }
						</option>
					) ) }
				</select>
			</label>
			<span className="hdh-muted">
				{ sprintf(
					/* translators: 1: first row, 2: last row, 3: total rows */
					__( '%1$d–%2$d of %3$d', 'helpdesk-hero' ),
					from,
					to,
					total
				) }
			</span>
			<span className="hdh-pager__nav">
				<Button
					size="sm"
					variant="ghost"
					disabled={ page <= 1 }
					onClick={ () => onPage( page - 1 ) }
				>
					{ __( 'Previous', 'helpdesk-hero' ) }
				</Button>
				<span className="hdh-muted">
					{ sprintf(
						/* translators: 1: page, 2: number of pages */
						__( 'Page %1$d of %2$d', 'helpdesk-hero' ),
						page,
						pages
					) }
				</span>
				<Button
					size="sm"
					variant="ghost"
					disabled={ page >= pages }
					onClick={ () => onPage( page + 1 ) }
				>
					{ __( 'Next', 'helpdesk-hero' ) }
				</Button>
			</span>
		</div>
	);
}

export function ProBadge() {
	return <span className="hdh-probadge">Pro</span>;
}

/* ------------------------------------------------------------------ Local AI (WebLLM) */

/**
 * Suggests the local, in-browser "AI Provider for WebLLM" (no API key, no cost, nothing leaves
 * the site), or says what's missing to use it.
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.local   Local AI state from the server (installed, active, worker, links).
 * @param {boolean} props.enabled Whether AI is already available on this site.
 * @return {JSX.Element|null} Hint.
 */
export function LocalAiHint( { local, enabled } ) {
	if ( ! local ) {
		return null;
	}
	let text;
	let link;
	let label;
	if ( local.active && ! local.worker ) {
		text = __(
			'AI Provider for WebLLM is active. Turn on its “In-browser worker” so AI features can use the local model while a dashboard tab is open.',
			'helpdesk-hero'
		);
		link = local.settings;
		label = __( 'Open WebLLM settings', 'helpdesk-hero' );
	} else if ( enabled || local.active ) {
		return null;
	} else if ( local.installed ) {
		text = __(
			'AI Provider for WebLLM is installed but not active. Activate it to run AI privately in your browser.',
			'helpdesk-hero'
		);
		link = local.plugins;
		label = __( 'Go to Plugins', 'helpdesk-hero' );
	} else {
		text = __(
			'Run AI privately, at no cost: the free AI Provider for WebLLM runs a model in your browser. No API key, and nothing leaves your site. Any provider under Settings › Connectors works too.',
			'helpdesk-hero'
		);
		link = local.url;
		label = __( 'Get AI Provider for WebLLM', 'helpdesk-hero' );
	}
	return (
		<div className="hdh-local-ai">
			<Icon name="spark" size={ 16 } />
			<p>
				{ text }{ ' ' }
				<a
					href={ link }
					target={ link === local.url ? '_blank' : undefined }
					rel="noopener noreferrer"
				>
					{ label }
				</a>
			</p>
		</div>
	);
}

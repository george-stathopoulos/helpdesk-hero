/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { useWidth } from '../lib/hooks';
import { dayLabel, dayLabelLong, niceTicks, integer } from '../lib/format';
import { sequential } from '../lib/colors';
import Icon from './Icon';

/* ==========================================================================
   Stacked column chart (daily spend by source)
   ========================================================================== */

const M = { top: 10, right: 6, bottom: 28, left: 52 };
const GAP = 2;

/**
 * Path for a rectangle with rounded top corners (data end) and a square base.
 *
 * @param {number} x Left.
 * @param {number} y Top.
 * @param {number} w Width.
 * @param {number} h Height.
 * @param {number} r Radius.
 * @return {string} SVG path.
 */
function topRounded( x, y, w, h, r ) {
	const rr = Math.min( r, w / 2, h );
	return `M${ x },${ y + h }V${ y + rr }Q${ x },${ y } ${ x + rr },${ y }H${
		x + w - rr
	}Q${ x + w },${ y } ${ x + w },${ y + rr }V${ y + h }Z`;
}

/**
 * Stacked columns with legend (toggle to isolate), hover tooltip, keyboard focus and a table view.
 *
 * @param {Object}   props        Props.
 * @param {string[]} props.dates  Days (YYYY-MM-DD).
 * @param {Array}    props.series [{ id, label, values, color }], bottom first.
 * @param {Function} props.format Value formatter.
 * @param {number}   props.height Plot height in px (axis band added).
 * @param {string}   props.title    Accessible name.
 * @param {Function} props.onSelect Optional: called with a day when its column is clicked (or Enter).
 * @return {JSX.Element} Chart.
 */
export function StackedColumns( {
	dates,
	series,
	format,
	height = 240,
	title,
	onSelect,
} ) {
	const [ ref, width ] = useWidth();
	const [ hidden, setHidden ] = useState( () => new Set() );
	const [ active, setActive ] = useState( null );
	const [ asTable, setAsTable ] = useState( false );

	const visible = series.filter( ( s ) => ! hidden.has( s.id ) );
	const totals = dates.map( ( _, i ) =>
		visible.reduce( ( sum, s ) => sum + ( s.values[ i ] || 0 ), 0 )
	);
	const ticks = niceTicks( Math.max( ...totals, 0 ) );
	const yMax = ticks[ ticks.length - 1 ] || 1;

	const plotW = Math.max( 0, width - M.left - M.right );
	const plotH = height;
	const band = dates.length ? plotW / dates.length : 0;
	const colW = Math.max(
		2,
		Math.min( 24, band - Math.max( 2, band * 0.28 ) )
	);
	const y = ( v ) => M.top + plotH - ( v / yMax ) * plotH;
	const x = ( i ) => M.left + i * band + ( band - colW ) / 2;

	const labelEvery = Math.max(
		1,
		Math.ceil( dates.length / Math.max( 2, Math.floor( plotW / 72 ) ) )
	);

	const toggle = ( id ) => {
		setHidden( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else if ( next.size < series.length - 1 ) {
				next.add( id );
			}
			return next;
		} );
	};

	const isolate = ( id ) => {
		const others = series
			.filter( ( s ) => s.id !== id )
			.map( ( s ) => s.id );
		const already =
			others.every( ( o ) => hidden.has( o ) ) && ! hidden.has( id );
		setHidden( already ? new Set() : new Set( others ) );
	};

	const onKey = ( e ) => {
		if ( ! dates.length ) {
			return;
		}
		if ( onSelect && e.key === 'Enter' && active !== null ) {
			onSelect( dates[ active ] );
			return;
		}
		if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
			e.preventDefault();
			const step = e.key === 'ArrowRight' ? 1 : -1;
			setActive( ( a ) =>
				Math.max(
					0,
					Math.min(
						dates.length - 1,
						a === null ? dates.length - 1 : a + step
					)
				)
			);
		}
	};

	const onMove = ( e ) => {
		const rect = e.currentTarget.getBoundingClientRect();
		const i = Math.floor( ( e.clientX - rect.left - M.left ) / band );
		setActive( i >= 0 && i < dates.length ? i : null );
	};

	const tipLeft =
		active === null
			? 0
			: Math.max( 110, Math.min( width - 110, x( active ) + colW / 2 ) );
	const tipTop = active === null ? 0 : Math.max( 64, y( totals[ active ] ) );

	return (
		<div>
			<div
				className="hdh-row"
				style={ { marginBottom: 12, alignItems: 'flex-start' } }
			>
				<ul
					className="hdh-legend"
					style={ { margin: 0, flex: 1 } }
					aria-label={ __( 'Series', 'helpdesk-hero' ) }
				>
					{ series.map( ( s ) => (
						<li key={ s.id }>
							<button
								type="button"
								className={ `hdh-legend__item ${
									hidden.has( s.id ) ? 'is-dim' : ''
								}` }
								onClick={ () => toggle( s.id ) }
								onDoubleClick={ () => isolate( s.id ) }
								aria-pressed={ ! hidden.has( s.id ) }
								title={ __(
									'Click to show or hide. Double-click to isolate.',
									'helpdesk-hero'
								) }
							>
								<i style={ { background: s.color } } />
								{ s.label }
								<b>
									{ format(
										s.values.reduce( ( a, b ) => a + b, 0 )
									) }
								</b>
							</button>
						</li>
					) ) }
				</ul>
				<button
					type="button"
					className="hdh-btn is-ghost is-sm"
					onClick={ () => setAsTable( ! asTable ) }
					aria-pressed={ asTable }
				>
					<Icon name={ asTable ? 'chart' : 'table' } size={ 14 } />
					{ asTable
						? __( 'Chart', 'helpdesk-hero' )
						: __( 'Table', 'helpdesk-hero' ) }
				</button>
			</div>

			{ asTable ? (
				<div
					className="hdh-table-wrap"
					style={ {
						maxHeight: height + M.bottom + 20,
						overflowY: 'auto',
					} }
				>
					<table className="hdh-table">
						<caption className="hdh-sr">{ title }</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Day', 'helpdesk-hero' ) }
								</th>
								{ visible.map( ( s ) => (
									<th
										key={ s.id }
										scope="col"
										className="is-num"
									>
										{ s.label }
									</th>
								) ) }
								<th scope="col" className="is-num">
									{ __( 'Total', 'helpdesk-hero' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ dates.map( ( d, i ) => (
								<tr key={ d }>
									<th
										scope="row"
										style={ {
											fontWeight: 500,
											color: 'var(--hdh-ink)',
										} }
									>
										{ dayLabelLong( d ) }
									</th>
									{ visible.map( ( s ) => (
										<td key={ s.id } className="is-num">
											{ format( s.values[ i ] || 0 ) }
										</td>
									) ) }
									<td className="is-num">
										<strong>
											{ format( totals[ i ] ) }
										</strong>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) : (
				<div
					className="hdh-chart"
					ref={ ref }
					style={ { height: plotH + M.top + M.bottom } }
				>
					{ width > 0 && (
						<svg
							width={ width }
							height={ plotH + M.top + M.bottom }
							role="img"
							aria-label={ title }
							tabIndex={ 0 }
							onKeyDown={ onKey }
							onMouseMove={ onMove }
							onClick={
								onSelect
									? () => active !== null && onSelect( dates[ active ] )
									: undefined
							}
							style={ onSelect ? { cursor: 'pointer' } : undefined }
							onMouseLeave={ () => setActive( null ) }
							onBlur={ () => setActive( null ) }
						>
							{ ticks.map( ( t ) => (
								<g key={ t }>
									<line
										className={
											t === 0
												? 'hdh-baseline'
												: 'hdh-gridline'
										}
										x1={ M.left }
										x2={ width - M.right }
										y1={ Math.round( y( t ) ) + 0.5 }
										y2={ Math.round( y( t ) ) + 0.5 }
									/>
									<text
										className="hdh-axis-text"
										x={ M.left - 10 }
										y={ y( t ) }
										dy="0.32em"
										textAnchor="end"
									>
										{ format( t ) }
									</text>
								</g>
							) ) }

							{ dates.map( ( d, i ) => {
								let base = 0;
								const top =
									visible.length -
									1 -
									[ ...visible ]
										.reverse()
										.findIndex(
											( s ) => ( s.values[ i ] || 0 ) > 0
										);
								return (
									<g
										key={ d }
										opacity={
											active === null || active === i
												? 1
												: 0.38
										}
										style={ { transition: 'opacity .15s' } }
									>
										{ visible.map( ( s, si ) => {
											const v = s.values[ i ] || 0;
											if ( v <= 0 ) {
												return null;
											}
											const below = base;
											const y0 = y( base );
											const y1 = y( base + v );
											base += v;
											const h = Math.max(
												0,
												y0 -
													y1 -
													( below > 0 ? GAP : 0 )
											);
											if ( h < 0.5 ) {
												return null;
											}
											return si === top ? (
												<path
													key={ s.id }
													d={ topRounded(
														x( i ),
														y1,
														colW,
														h,
														4
													) }
													fill={ s.color }
												/>
											) : (
												<rect
													key={ s.id }
													x={ x( i ) }
													y={ y1 }
													width={ colW }
													height={ h }
													fill={ s.color }
												/>
											);
										} ) }
									</g>
								);
							} ) }

							{ dates.map( ( d, i ) =>
								( i % labelEvery === 0 ||
									i === dates.length - 1 ) &&
								( i === dates.length - 1 ||
									dates.length - 1 - i >= labelEvery / 2 ) ? (
									<text
										key={ d }
										className="hdh-axis-text"
										x={ x( i ) + colW / 2 }
										y={ M.top + plotH + 18 }
										textAnchor="middle"
									>
										{ dayLabel( d ) }
									</text>
								) : null
							) }
						</svg>
					) }

					{ active !== null && (
						<div
							className="hdh-tooltip"
							style={ { left: tipLeft, top: tipTop } }
						>
							<div className="hdh-tooltip__title">
								{ dayLabelLong( dates[ active ] ) }
							</div>
							{ [ ...visible ].reverse().map( ( s ) => (
								<div key={ s.id } className="hdh-tooltip__row">
									<i style={ { background: s.color } } />
									<span>{ s.label }</span>
									<strong>
										{ format( s.values[ active ] || 0 ) }
									</strong>
								</div>
							) ) }
							<div className="hdh-tooltip__row hdh-tooltip__total">
								<span />
								<span>{ __( 'Total', 'helpdesk-hero' ) }</span>
								<strong>{ format( totals[ active ] ) }</strong>
							</div>
						</div>
					) }
				</div>
			) }
		</div>
	);
}

/* ==========================================================================
   Sparkline
   ========================================================================== */

/**
 * Trend sparkline: de-emphasis line with a soft wash and the current point in the accent.
 *
 * @param {Object}   props        Props.
 * @param {number[]} props.values Values.
 * @param {number}   props.width  Width.
 * @param {number}   props.height Height.
 * @return {JSX.Element} SVG.
 */
export function Sparkline( { values = [], width = 96, height = 30 } ) {
	const id = useMemo(
		() => `hdh-sp-${ Math.random().toString( 36 ).slice( 2 ) }`,
		[]
	);
	if ( values.length < 2 ) {
		return <svg width={ width } height={ height } aria-hidden="true" />;
	}
	const max = Math.max( ...values, 0 );
	const min = Math.min( ...values, 0 );
	const span = max - min || 1;
	const pts = values.map( ( v, i ) => [
		1 + ( i / ( values.length - 1 ) ) * ( width - 6 ),
		3 + ( height - 6 ) - ( ( v - min ) / span ) * ( height - 6 ),
	] );
	const line = pts
		.map(
			( p, i ) =>
				`${ i ? 'L' : 'M' }${ p[ 0 ].toFixed( 1 ) },${ p[ 1 ].toFixed(
					1
				) }`
		)
		.join( '' );
	const last = pts[ pts.length - 1 ];
	return (
		<svg
			width={ width }
			height={ height }
			aria-hidden="true"
			style={ { overflow: 'visible', flexShrink: 0 } }
		>
			<defs>
				<linearGradient id={ id } x1="0" x2="0" y1="0" y2="1">
					<stop
						offset="0"
						stopColor="var(--hdh-accent)"
						stopOpacity="0.16"
					/>
					<stop
						offset="1"
						stopColor="var(--hdh-accent)"
						stopOpacity="0"
					/>
				</linearGradient>
			</defs>
			<path
				d={ `${ line }L${ last[ 0 ] },${ height }L${ pts[ 0 ][ 0 ] },${ height }Z` }
				fill={ `url(#${ id })` }
			/>
			<path
				d={ line }
				fill="none"
				stroke="var(--hdh-muted)"
				strokeWidth="1.5"
				strokeLinejoin="round"
				strokeLinecap="round"
			/>
			<circle
				cx={ last[ 0 ] }
				cy={ last[ 1 ] }
				r="3.5"
				fill="var(--hdh-accent)"
				stroke="var(--hdh-surface)"
				strokeWidth="2"
			/>
		</svg>
	);
}

/* ==========================================================================
   Bar list
   ========================================================================== */

/**
 * Ranked horizontal bars: label + value on one line, bar below, optional meta line.
 *
 * @param {Object}   props        Props.
 * @param {Array}    props.items  [{ id, label, value, meta, color, prefix }].
 * @param {Function} props.format   Value formatter.
 * @param {Function} props.onSelect Optional: called with an item when its row is clicked.
 * @return {JSX.Element} List.
 */
export function BarList( { items, format, onSelect } ) {
	const max = Math.max( ...items.map( ( i ) => i.value ), 0 ) || 1;
	return (
		<ul className="hdh-bars">
			{ items.map( ( item ) => (
				<li
					key={ item.id }
					className={ `hdh-bars__row ${ onSelect ? 'is-clickable' : '' }` }
					role={ onSelect ? 'button' : undefined }
					tabIndex={ onSelect ? 0 : undefined }
					onClick={ onSelect ? () => onSelect( item ) : undefined }
					onKeyDown={
						onSelect
							? ( e ) =>
									( e.key === 'Enter' || e.key === ' ' ) &&
									( e.preventDefault(), onSelect( item ) )
							: undefined
					}
				>
					<div className="hdh-bars__label">
						{ item.prefix }
						<span title={ item.label }>{ item.label }</span>
					</div>
					<div className="hdh-bars__value">
						{ format( item.value ) }
					</div>
					<div className="hdh-bars__track">
						<div
							className="hdh-bars__fill"
							style={ {
								width: `${ ( item.value / max ) * 100 }%`,
								background: item.color || 'var(--hdh-s1)',
							} }
						/>
					</div>
					{ item.meta && (
						<div className="hdh-bars__meta">{ item.meta }</div>
					) }
				</li>
			) ) }
		</ul>
	);
}

/* ==========================================================================
   Heatmap (weekday × hour)
   ========================================================================== */

const DAYS = [
	__( 'Mon', 'helpdesk-hero' ),
	__( 'Tue', 'helpdesk-hero' ),
	__( 'Wed', 'helpdesk-hero' ),
	__( 'Thu', 'helpdesk-hero' ),
	__( 'Fri', 'helpdesk-hero' ),
	__( 'Sat', 'helpdesk-hero' ),
	__( 'Sun', 'helpdesk-hero' ),
];

/**
 * Requests by weekday and hour, one-hue sequential scale.
 *
 * @param {Object}     props      Props.
 * @param {number[][]} props.grid 7 × 24 counts (Monday first).
 * @return {JSX.Element} Heatmap.
 */
export function Heatmap( { grid } ) {
	const [ tip, setTip ] = useState( null );
	const max = Math.max( ...grid.flat(), 0 );
	const hourLabel = ( h ) => `${ String( h ).padStart( 2, '0' ) }:00`;

	return (
		<div style={ { position: 'relative' } }>
			<div
				className="hdh-heat"
				role="grid"
				aria-label={ __(
					'Requests by weekday and hour',
					'helpdesk-hero'
				) }
			>
				{ grid.map( ( row, d ) => (
					<div key={ d } role="row" style={ { display: 'contents' } }>
						<div role="rowheader">{ DAYS[ d ] }</div>
						{ row.map( ( v, h ) => (
							<button
								key={ h }
								type="button"
								role="gridcell"
								className="hdh-heat__cell"
								style={ { background: sequential( v, max ) } }
								aria-label={ sprintf(
									/* translators: 1: weekday, 2: hour, 3: number of requests. */
									__(
										'%1$s %2$s: %3$s requests',
										'helpdesk-hero'
									),
									DAYS[ d ],
									hourLabel( h ),
									integer( v )
								) }
								onMouseEnter={ ( e ) =>
									setTip( {
										d,
										h,
										v,
										x:
											e.currentTarget.offsetLeft +
											e.currentTarget.offsetWidth / 2,
										y: e.currentTarget.offsetTop,
									} )
								}
								onFocus={ ( e ) =>
									setTip( {
										d,
										h,
										v,
										x:
											e.currentTarget.offsetLeft +
											e.currentTarget.offsetWidth / 2,
										y: e.currentTarget.offsetTop,
									} )
								}
								onMouseLeave={ () => setTip( null ) }
								onBlur={ () => setTip( null ) }
							/>
						) ) }
					</div>
				) ) }
				<div />
				{ Array.from( { length: 24 }, ( _, h ) => (
					<div key={ h } className="hdh-heat__hour">
						{ h % 6 === 0 ? String( h ).padStart( 2, '0' ) : '' }
					</div>
				) ) }
			</div>
			{ tip && (
				<div
					className="hdh-tooltip"
					style={ { left: tip.x, top: tip.y, minWidth: 150 } }
				>
					<div className="hdh-tooltip__title">{ `${
						DAYS[ tip.d ]
					} · ${ hourLabel( tip.h ) }–${ hourLabel(
						( tip.h + 1 ) % 24
					) }` }</div>
					<div
						className="hdh-tooltip__row"
						style={ { gridTemplateColumns: '1fr auto' } }
					>
						<span>{ __( 'Requests', 'helpdesk-hero' ) }</span>
						<strong>{ integer( tip.v ) }</strong>
					</div>
				</div>
			) }
			<div
				className="hdh-row"
				style={ { marginTop: 14, justifyContent: 'space-between' } }
			>
				<span className="hdh-scale">
					{ __( 'Fewer', 'helpdesk-hero' ) }
					<span>
						{ [ 0, 1, 2, 3, 4, 5, 6, 7 ].map( ( q ) => (
							<i
								key={ q }
								style={ {
									background: `var(--hdh-q${ q })`,
								} }
							/>
						) ) }
					</span>
					{ __( 'More', 'helpdesk-hero' ) }
				</span>
				<span className="hdh-scale">
					{ __( 'Site time', 'helpdesk-hero' ) }
				</span>
			</div>
		</div>
	);
}

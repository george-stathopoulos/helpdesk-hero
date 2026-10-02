/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
import { __, sprintf } from '@wordpress/i18n';
import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import Icon from './Icon';
import { initials } from '../lib/format';

/* ------------------------------------------------------------------ Card */

export function Card( {
	title,
	sub,
	action,
	children,
	className = '',
	bodyClass = 'hdh-card__body',
	foot,
	...rest
} ) {
	return (
		<section className={ `hdh-card ${ className }` } { ...rest }>
			{ ( title || action ) && (
				<header className="hdh-card__head">
					<div>
						{ title && (
							<h2 className="hdh-card__title">{ title }</h2>
						) }
						{ sub && <p className="hdh-card__sub">{ sub }</p> }
					</div>
					{ action }
				</header>
			) }
			{ bodyClass === null ? (
				children
			) : (
				<div className={ bodyClass }>{ children }</div>
			) }
			{ foot && <footer className="hdh-card__foot">{ foot }</footer> }
		</section>
	);
}

/* ------------------------------------------------------------------ Delta */

/**
 * Signed change vs the previous period. `goodWhen` says which direction is good.
 *
 * @param {Object}      props          Props.
 * @param {number|null} props.value    Percent change.
 * @param {string}      props.goodWhen 'up', 'down' or 'neutral'.
 * @param {string}      props.label    Accessible comparison label.
 * @return {JSX.Element|null} Delta badge.
 */
export function Delta( { value, goodWhen = 'up', label } ) {
	if ( value === null || value === undefined || ! isFinite( value ) ) {
		return <span className="hdh-delta">{ __( 'New', 'helpdesk-hero' ) }</span>;
	}
	const rounded =
		Math.abs( value ) < 10 ? value.toFixed( 1 ) : Math.round( value );
	const up = value > 0.05;
	const down = value < -0.05;
	let tone = '';
	if ( goodWhen !== 'neutral' && ( up || down ) ) {
		tone =
			( up && goodWhen === 'up' ) || ( down && goodWhen === 'down' )
				? 'is-good'
				: 'is-bad';
	}
	return (
		<span className={ `hdh-delta ${ tone }` } title={ label }>
			{ up && <Icon name="up" size={ 12 } /> }
			{ down && <Icon name="down" size={ 12 } /> }
			{ `${ Math.abs( rounded ) }%` }
			<span className="hdh-sr">{ label }</span>
		</span>
	);
}

/* ------------------------------------------------------------------ Pill */

export function Pill( { tone = '', dot = false, icon, children } ) {
	return (
		<span className={ `hdh-pill ${ tone ? `is-${ tone }` : '' }` }>
			{ dot && <span className="hdh-pill__dot" /> }
			{ icon && <Icon name={ icon } size={ 12 } /> }
			{ children }
		</span>
	);
}

const SOURCE_STATUS = {
	active: {
		tone: 'good',
		icon: 'check',
		label: __( 'Active', 'helpdesk-hero' ),
	},
	forecast: {
		tone: 'serious',
		icon: 'gauge',
		label: __( 'On pace to exceed', 'helpdesk-hero' ),
	},
	near: {
		tone: 'warning',
		icon: 'alert',
		label: __( 'Near budget', 'helpdesk-hero' ),
	},
	capped: {
		tone: 'critical',
		icon: 'ban',
		label: __( 'Budget reached', 'helpdesk-hero' ),
	},
	paused: {
		tone: 'critical',
		icon: 'pause',
		label: __( 'Paused', 'helpdesk-hero' ),
	},
};

export function SourceStatus( { status } ) {
	const s = SOURCE_STATUS[ status ] || SOURCE_STATUS.active;
	return (
		<Pill tone={ s.tone } icon={ s.icon }>
			{ s.label }
		</Pill>
	);
}

const REQUEST_STATUS = {
	ok: { tone: 'good', icon: 'check', label: __( 'Completed', 'helpdesk-hero' ) },
	blocked: {
		tone: 'critical',
		icon: 'ban',
		label: __( 'Blocked', 'helpdesk-hero' ),
	},
	error: {
		tone: 'warning',
		icon: 'alert',
		label: __( 'Failed', 'helpdesk-hero' ),
	},
};

export function RequestStatus( { status, cached = false } ) {
	if ( cached && status === 'ok' ) {
		return (
			<Pill tone="accent" icon="bolt">
				{ __( 'From cache', 'helpdesk-hero' ) }
			</Pill>
		);
	}
	const s = REQUEST_STATUS[ status ] || REQUEST_STATUS.ok;
	return (
		<Pill tone={ s.tone } icon={ s.icon }>
			{ s.label }
		</Pill>
	);
}

/* ------------------------------------------------------------------ Avatar */

const TYPE_LABEL = {
	plugin: __( 'Plugin', 'helpdesk-hero' ),
	theme: __( 'Theme', 'helpdesk-hero' ),
	'mu-plugin': __( 'Must-use plugin', 'helpdesk-hero' ),
	core: __( 'WordPress', 'helpdesk-hero' ),
};

export function typeLabel( type ) {
	return TYPE_LABEL[ type ] || type;
}

export function SourceAvatar( { label, color, large = false } ) {
	return (
		<span
			className={ `hdh-avatar ${ large ? 'is-lg' : '' }` }
			aria-hidden="true"
		>
			{ initials( label ) }
			{ color && (
				<span
					className="hdh-avatar__dot"
					style={ { background: color } }
				/>
			) }
		</span>
	);
}

/* ------------------------------------------------------------------ Controls */

export function Button( { variant = '', size = '', icon, children, ...rest } ) {
	const cls = [
		'hdh-btn',
		variant && `is-${ variant }`,
		size && `is-${ size }`,
		! children && 'is-icon',
	]
		.filter( Boolean )
		.join( ' ' );
	return (
		<button type="button" className={ cls } { ...rest }>
			{ icon && <Icon name={ icon } size={ 15 } /> }
			{ children }
		</button>
	);
}

export function Switch( { checked, onChange, label, id, disabled } ) {
	return (
		<span className="hdh-switch">
			<input
				type="checkbox"
				role="switch"
				id={ id }
				checked={ !! checked }
				disabled={ disabled }
				aria-label={ label }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
			<span className="hdh-switch__track" aria-hidden="true" />
		</span>
	);
}

export function Segmented( { options, value, onChange, label } ) {
	return (
		<div className="hdh-seg" role="group" aria-label={ label }>
			{ options.map( ( o ) => (
				<button
					key={ o.value }
					type="button"
					className={ o.value === value ? 'is-active' : '' }
					aria-pressed={ o.value === value }
					onClick={ () => onChange( o.value ) }
				>
					{ o.label }
				</button>
			) ) }
		</div>
	);
}

export function Setting( { title, desc, children, badge } ) {
	return (
		<div className="hdh-setting">
			<div className="hdh-setting__text">
				<div className="hdh-setting__title">
					{ title }
					{ badge }
				</div>
				{ desc && <div className="hdh-setting__desc">{ desc }</div> }
			</div>
			<div className="hdh-setting__control">{ children }</div>
		</div>
	);
}

export function MoneyInput( {
	value,
	onChange,
	id,
	placeholder = '0.00',
	label,
} ) {
	return (
		<span className="hdh-money">
			<input
				id={ id }
				className="hdh-input hdh-num"
				type="number"
				min="0"
				step="0.01"
				inputMode="decimal"
				aria-label={ label }
				placeholder={ placeholder }
				value={ value === 0 || value === '0' ? '' : value }
				onChange={ ( e ) =>
					onChange( e.target.value === '' ? 0 : e.target.value )
				}
			/>
		</span>
	);
}

/* ------------------------------------------------------------------ Meter */

/**
 * Budget meter with an optional forecast extension and a marker at 100%.
 *
 * @param {Object}  props          Props.
 * @param {number}  props.value    Spent.
 * @param {number}  props.max      Budget.
 * @param {number}  props.forecast Forecast (optional).
 * @param {boolean} props.small    Smaller variant.
 * @param {string}  props.label    Accessible name.
 * @return {JSX.Element} Meter.
 */
export function Meter( { value, max, forecast, small = false, label } ) {
	// Scale so the budget sits at 100% unless the forecast overshoots it.
	const scaleMax = Math.max( max, forecast || 0, value ) || 1;
	const pct = ( v ) => `${ Math.min( 100, ( v / scaleMax ) * 100 ) }%`;
	const ratio = max ? value / max : 0;
	let color = 'var(--hdh-s1)';
	if ( ratio >= 1 ) {
		color = 'var(--hdh-critical)';
	} else if ( ratio >= 0.8 ) {
		color = 'var(--hdh-warning)';
	} else if ( forecast && max && forecast > max ) {
		color = 'var(--hdh-serious)';
	}
	return (
		<div
			className={ `hdh-meter ${ small ? 'is-sm' : '' }` }
			style={ { '--hdh-meter-color': color } }
			role="meter"
			aria-valuemin={ 0 }
			aria-valuemax={ max }
			aria-valuenow={ value }
			aria-label={ label }
		>
			{ forecast > value && (
				<span
					className="hdh-meter__forecast"
					style={ { width: pct( forecast ) } }
				/>
			) }
			<span
				className="hdh-meter__fill"
				style={ { width: pct( value ) } }
			/>
			{ max > 0 && scaleMax > max && (
				<span
					className="hdh-meter__mark"
					style={ { left: pct( max ) } }
				/>
			) }
		</div>
	);
}

/* ------------------------------------------------------------------ Drawer */

export function Drawer( {
	title,
	sub,
	onClose,
	children,
	footer,
	wide = false,
} ) {
	const panel = useRef( null );
	useEffect( () => {
		const doc = panel.current.ownerDocument;
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
				return;
			}
			if ( e.key !== 'Tab' || ! panel.current ) {
				return;
			}
			// Keep keyboard focus inside the open drawer.
			const items = panel.current.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);
			if ( ! items.length ) {
				e.preventDefault();
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			const active = doc.activeElement;
			if (
				e.shiftKey &&
				( active === first || active === panel.current )
			) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && active === last ) {
				e.preventDefault();
				first.focus();
			} else if ( ! panel.current.contains( active ) ) {
				e.preventDefault();
				first.focus();
			}
		};
		doc.addEventListener( 'keydown', onKey );
		const previous = doc.activeElement;
		panel.current.focus();
		return () => {
			doc.removeEventListener( 'keydown', onKey );
			previous?.focus?.();
		};
	}, [ onClose ] );

	return (
		<>
			<div className="hdh-scrim" onClick={ onClose } aria-hidden="true" />
			<aside
				className={ `hdh-drawer${ wide ? ' is-wide' : '' }` }
				role="dialog"
				aria-modal="true"
				aria-label={ typeof title === 'string' ? title : undefined }
				tabIndex={ -1 }
				ref={ panel }
			>
				<header className="hdh-drawer__head">
					<div>
						{ title }
						{ sub }
					</div>
					<Button
						variant="ghost"
						icon="x"
						onClick={ onClose }
						aria-label={ __( 'Close', 'helpdesk-hero' ) }
					/>
				</header>
				<div className="hdh-drawer__body">{ children }</div>
				{ footer && (
					<footer className="hdh-drawer__foot">{ footer }</footer>
				) }
			</aside>
		</>
	);
}

/* ------------------------------------------------------------------ Toast */

const ToastContext = createContext( () => {} );

export function ToastProvider( { children } ) {
	const [ toast, setToast ] = useState( null );
	const timer = useRef();
	const show = useCallback( ( message, icon = 'check' ) => {
		window.clearTimeout( timer.current );
		setToast( { message, icon } );
		timer.current = window.setTimeout( () => setToast( null ), 2600 );
	}, [] );
	return (
		<ToastContext.Provider value={ show }>
			{ children }
			<div aria-live="polite" className="hdh-sr">
				{ toast?.message }
			</div>
			{ toast && (
				<div className="hdh-toast" role="status">
					<Icon name={ toast.icon } size={ 15 } />
					{ toast.message }
				</div>
			) }
		</ToastContext.Provider>
	);
}

export function useToast() {
	return useContext( ToastContext );
}

/* ------------------------------------------------------------------ Empty */

export function Empty( { title, text, children, compact = false, art } ) {
	return (
		<div className={ `hdh-empty ${ compact ? 'is-compact' : '' }` }>
			{ art && <div className="hdh-empty__art">{ art }</div> }
			<h3 className="hdh-empty__title">{ title }</h3>
			{ text && <p className="hdh-empty__text">{ text }</p> }
			{ children }
		</div>
	);
}

export function ErrorNotice( { error, onRetry } ) {
	return (
		<div className="hdh-banner" role="alert">
			<span className="hdh-banner__icon">
				<Icon name="alert" />
			</span>
			<div className="hdh-banner__text">
				<strong>{ __( 'Could not load data.', 'helpdesk-hero' ) }</strong>{ ' ' }
				{ error?.message || '' }
			</div>
			{ onRetry && (
				<Button size="sm" icon="refresh" onClick={ onRetry }>
					{ __( 'Retry', 'helpdesk-hero' ) }
				</Button>
			) }
		</div>
	);
}

/* ------------------------------------------------------------------ Page head */

export function PageHead( { title, lede, children, help } ) {
	return (
		<div className="hdh-pagehead">
			<div>
				<h1 className="hdh-pagehead__title">{ title }</h1>
				{ lede && <p className="hdh-pagehead__lede">{ lede }</p> }
			</div>
			{ ( children || help ) && (
				<div className="hdh-toolbar">
					{ children }
					{ help && (
						<a
							className="hdh-btn is-ghost is-icon"
							href={ `#/help?topic=${ help }` }
							title={ __( 'Help for this page', 'helpdesk-hero' ) }
							aria-label={ __(
								'Help for this page',
								'helpdesk-hero'
							) }
						>
							<span className="hdh-qmark" aria-hidden="true">
								?
							</span>
						</a>
					) }
				</div>
			) }
		</div>
	);
}

export const RANGE_OPTIONS = [
	{ value: 7, label: __( '7 days', 'helpdesk-hero' ) },
	{ value: 30, label: __( '30 days', 'helpdesk-hero' ) },
	{ value: 90, label: __( '90 days', 'helpdesk-hero' ) },
];

export function rangeLabel( days ) {
	/* translators: %d: number of days. */
	return sprintf( __( 'last %d days', 'helpdesk-hero' ), days );
}

/* ------------------------------------------------------------------ Loading */

/**
 * Initial-load placeholder shaped like a page: a heading, a wide card and a row of cards.
 *
 * @return {JSX.Element} Skeleton.
 */
export function Loading() {
	const blocks = [
		[ 12, 56 ],
		[ 8, 320 ],
		[ 4, 320 ],
		[ 4, 120 ],
		[ 4, 120 ],
		[ 4, 120 ],
	];
	return (
		<div
			className="hdh-skeleton"
			role="status"
			aria-label={ __( 'Loading', 'helpdesk-hero' ) }
		>
			{ blocks.map( ( [ span, height ], i ) => (
				<div
					key={ i }
					style={ {
						gridColumn: `span ${ span }`,
						height,
						opacity: i === 0 ? 0.5 : 1,
					} }
				/>
			) ) }
		</div>
	);
}

/* ------------------------------------------------------------------ InfoTip */

let tipId = 0;

/**
 * Small "i" button that explains a term. Opens on hover, focus or click; Escape closes it.
 *
 * @param {Object} props          Props.
 * @param {*}      props.children Explanation.
 * @param {string} props.label    Accessible name of the button.
 * @param {string} props.align    'center', 'left' or 'right' edge alignment of the popover.
 * @return {JSX.Element} Tooltip.
 */
export function InfoTip( { children, label, align = 'center' } ) {
	const [ open, setOpen ] = useState( false );
	const [ pinned, setPinned ] = useState( false );
	const id = useRef( `hdh-tip-${ ++tipId }` ).current;
	const show = open || pinned;
	return (
		<span
			className={ `hdh-tip is-${ align }` }
			onMouseEnter={ () => setOpen( true ) }
			onMouseLeave={ () => setOpen( false ) }
		>
			<button
				type="button"
				className="hdh-tip__btn"
				aria-label={ label || __( 'More information', 'helpdesk-hero' ) }
				aria-expanded={ show }
				aria-describedby={ show ? id : undefined }
				onClick={ ( e ) => {
					e.stopPropagation();
					setPinned( ! pinned );
				} }
				onFocus={ () => setOpen( true ) }
				onBlur={ () => {
					setOpen( false );
					setPinned( false );
				} }
				onKeyDown={ ( e ) => {
					if ( e.key === 'Escape' ) {
						setOpen( false );
						setPinned( false );
					}
				} }
			>
				<Icon name="info" size={ 13 } />
			</button>
			{ show && (
				<span role="tooltip" id={ id } className="hdh-tip__pop">
					{ children }
				</span>
			) }
		</span>
	);
}

/**
 * A label followed by an InfoTip.
 *
 * @param {Object} props       Props.
 * @param {*}      props.label Label.
 * @param {*}      props.tip   Explanation.
 * @param {string} props.align Popover alignment.
 * @return {JSX.Element} Label with tooltip.
 */
export function Term( { label, tip, align } ) {
	return (
		<span className="hdh-term">
			{ label }
			<InfoTip align={ align }>{ tip }</InfoTip>
		</span>
	);
}

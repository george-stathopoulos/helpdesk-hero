/**
 * The support console: what support sees after logging in to a customer's site. Its own frame
 * (not the customer's help center), with the site's tickets, their problem spots, site details,
 * debugging tools and troubleshooting mode. Only support accounts get it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, useRoute, useTheme, send } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	Pill,
	Button,
	Loading,
	ErrorNotice,
	Empty,
	ToastProvider,
	ErrorBoundary,
	useToast,
} from '../ui/components/ui';
import { Thread, TagPills, confirmAction } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { when, ago } from '../ui/lib/time';
import { TroubleshootPanel } from './Troubleshoot';
import SupportHealth from './SupportHealth';
import SupportDebug from './SupportDebug';

const boot = window.hdhBoot || {};

const TABS = [
	{ id: 'tickets', label: __( 'Tickets', 'helpdesk-hero' ), icon: 'inbox' },
	{ id: 'details', label: __( 'Site details', 'helpdesk-hero' ), icon: 'gauge' },
	{ id: 'debug', label: __( 'Debugging', 'helpdesk-hero' ), icon: 'code' },
	{ id: 'troubleshoot', label: __( 'Troubleshoot', 'helpdesk-hero' ), icon: 'wrench' },
];

const PRIORITY = { urgent: 'bad', high: 'warning', normal: '', low: '' };

function message( e ) {
	return ( e && e.message ) || __( 'Something went wrong.', 'helpdesk-hero' );
}

/**
 * The header's session controls: how long access lasts, leave, or end it.
 *
 * @return {JSX.Element|null} Controls.
 */
function SessionBar() {
	const { data } = useApi( 'admin/session' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();
	if ( ! data ) {
		return null;
	}
	const end = async () => {
		const ok = await confirmAction(
			__(
				'End access now? Your support account is deleted and you are logged out. To come back, the customer has to give access again.',
				'helpdesk-hero'
			),
			{ confirmText: __( 'End access', 'helpdesk-hero' ) }
		);
		if ( ! ok ) {
			return;
		}
		setBusy( true );
		send( 'admin/session/end', 'POST', {} )
			.then( ( r ) => window.location.assign( r.redirect ) )
			.catch( ( e ) => {
				toast( message( e ), 'alert' );
				setBusy( false );
			} );
	};
	return (
		<div className="hdh-console__session">
			<Pill tone="good" dot>
				{ data.permanent
					? __( 'Access with no end date', 'helpdesk-hero' )
					: sprintf(
							/* translators: %s: date and time */
							__( 'Access until %s', 'helpdesk-hero' ),
							when( data.expires )
					  ) }
			</Pill>
			<a className="hdh-btn is-ghost is-sm" href={ data.logout }>
				<Icon name="login" size={ 15 } />
				{ __( 'Leave session', 'helpdesk-hero' ) }
			</a>
			<Button variant="danger" size="sm" icon="x" disabled={ busy } onClick={ end }>
				{ __( 'End access', 'helpdesk-hero' ) }
			</Button>
		</div>
	);
}

/**
 * One ticket in full: details, problem spots and the conversation (read-only).
 *
 * @param {Object} props    Props.
 * @param {number} props.id Ticket.
 * @return {JSX.Element} Ticket.
 */
function TicketDetail( { id } ) {
	const { data, error, reload } = useApi( `admin/support/tickets/${ id }` );
	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || data.id !== id ) {
		return <Loading />;
	}
	return (
		<div className="hdh-stack" style={ { gap: 18 } }>
			<Card>
				<div className="hdh-console__ticket-head">
					<div>
						<h2 className="hdh-console__subject">{ data.subject }</h2>
						<p className="hdh-muted" style={ { margin: '4px 0 0' } }>
							{ sprintf(
								/* translators: 1: ticket number, 2: date, 3: customer */
								__( '#%1$d · opened %2$s · by %3$s', 'helpdesk-hero' ),
								data.id,
								when( data.created ),
								data.customer || __( 'the site owner', 'helpdesk-hero' )
							) }
						</p>
						<div className="hdh-row" style={ { marginTop: 10, gap: 6 } }>
							<Pill dot>{ data.status }</Pill>
							{ data.priority !== 'normal' && (
								<Pill tone={ PRIORITY[ data.priority ] }>{ data.priority_label }</Pill>
							) }
							{ data.tags.length > 0 && <TagPills tags={ data.tags } /> }
						</div>
					</div>
					{ data.hub_link && (
						<a className="hdh-btn is-primary" href={ data.hub_link } target="_blank" rel="noopener noreferrer">
							<Icon name="send" size={ 15 } />
							{ __( 'Reply in the hub', 'helpdesk-hero' ) }
						</a>
					) }
				</div>
			</Card>

			<Card
				title={ __( 'Problem spots', 'helpdesk-hero' ) }
				sub={
					data.spots.length
						? __( 'Where the customer pointed. Open one to see the page with the spot highlighted.', 'helpdesk-hero' )
						: undefined
				}
			>
				{ data.spots.length ? (
					<ul className="hdh-plain-list hdh-session-spots">
						{ data.spots.map( ( s, i ) => (
							<li key={ s.id }>
								<div className="hdh-row" style={ { gap: 8 } }>
									<span className="hdh-picked-spot__num">{ i + 1 }</span>
									<strong>{ s.label || s.title || s.url }</strong>
									<a className="hdh-btn is-sm" href={ s.highlight }>
										<Icon name="eye" size={ 14 } />
										{ __( 'Open and highlight', 'helpdesk-hero' ) }
									</a>
								</div>
								<div className="hdh-muted" style={ { fontSize: 13, marginTop: 4 } }>
									{ s.title || s.url }
									{ s.viewport ? ` · ${ s.viewport.w }×${ s.viewport.h }` : '' }
									{ s.browser ? ` · ${ s.browser }` : '' }
									{ s.created_at ? ` · ${ ago( s.created_at ) }` : '' }
								</div>
								{ ( s.errors || [] ).map( ( e, n ) => (
									<code key={ `e${ n }` } className="hdh-error-detail" style={ { display: 'block', marginTop: 4 } }>
										{ e.message }
										{ e.source ? ` (${ e.source }:${ e.line })` : '' }
									</code>
								) ) }
								{ ( s.requests || [] ).map( ( r, n ) => (
									<code key={ `r${ n }` } className="hdh-error-detail" style={ { display: 'block', marginTop: 4 } }>
										{ r.method } { r.url } → { r.status || __( 'no response', 'helpdesk-hero' ) }
									</code>
								) ) }
							</li>
						) ) }
					</ul>
				) : (
					<p className="hdh-muted" style={ { margin: 0 } }>
						{ __( 'The customer didn’t mark any spots on this ticket.', 'helpdesk-hero' ) }
					</p>
				) }
			</Card>

			<Card
				title={ __( 'Conversation', 'helpdesk-hero' ) }
				sub={ __( 'Read-only here: reply from your support hub, so everything stays in one place.', 'helpdesk-hero' ) }
			>
				<Thread items={ data.messages } youLabel={ __( 'Support', 'helpdesk-hero' ) } />
			</Card>
		</div>
	);
}

/**
 * The site's tickets on the left, the selected one on the right.
 *
 * @return {JSX.Element} Tickets.
 */
function ConsoleTickets() {
	const { data, error, reload } = useApi( 'admin/support/tickets' );
	const [ selected, setSelected ] = useState( 0 );
	useEffect( () => {
		if ( data && ! selected ) {
			setSelected( data.focus || ( data.tickets[ 0 ] && data.tickets[ 0 ].id ) || 0 );
		}
	}, [ data, selected ] );
	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	if ( ! data.tickets.length ) {
		return (
			<Empty
				title={ __( 'No open tickets on this site', 'helpdesk-hero' ) }
				text={ __( 'Use Site details, Debugging and Troubleshoot to look around.', 'helpdesk-hero' ) }
			/>
		);
	}
	return (
		<div className="hdh-console__tickets">
			<nav className="hdh-console__list" aria-label={ __( 'Tickets on this site', 'helpdesk-hero' ) }>
				{ data.tickets.map( ( t ) => (
					<button
						key={ t.id }
						type="button"
						className={ `hdh-console__item ${ t.id === selected ? 'is-active' : '' }` }
						aria-current={ t.id === selected ? 'true' : undefined }
						onClick={ () => setSelected( t.id ) }
					>
						<span className="hdh-console__item-top">
							<strong>{ t.subject }</strong>
							{ t.id === data.focus && (
								<Pill tone="accent">{ __( 'Access given for this', 'helpdesk-hero' ) }</Pill>
							) }
						</span>
						<span className="hdh-muted">
							#{ t.id } · { t.status } · { ago( t.updated ) }
							{ t.spots
								? ` · ${ sprintf(
										/* translators: %d: number of problem spots */
										__( '%d spots', 'helpdesk-hero' ),
										t.spots
								  ) }`
								: '' }
						</span>
					</button>
				) ) }
			</nav>
			<div>{ selected ? <TicketDetail id={ selected } /> : null }</div>
		</div>
	);
}

export default function SupportConsole() {
	const [ route ] = useRoute( 'tickets' );
	const [ theme ] = useTheme();
	const section = route.split( /[/?]/ )[ 0 ];
	const current = TABS.find( ( t ) => t.id === section ) || TABS[ 0 ];
	let page;
	if ( current.id === 'details' ) {
		page = <SupportHealth />;
	} else if ( current.id === 'debug' ) {
		page = <SupportDebug />;
	} else if ( current.id === 'troubleshoot' ) {
		page = <TroubleshootPanel />;
	} else {
		page = (
			<>
				<PageHead
					title={ __( 'Tickets', 'helpdesk-hero' ) }
					lede={ sprintf(
						/* translators: %s: site name */
						__( 'The open tickets on %s, with the customer’s problem spots. Reply from your support hub.', 'helpdesk-hero' ),
						boot.siteName
					) }
				/>
				<ConsoleTickets />
			</>
		);
	}
	return (
		<div className="hdh-root hdh-console" data-theme={ theme === 'system' ? undefined : theme }>
			<ToastProvider>
				<header className="hdh-header">
					<div className="hdh-header__inner">
						<div className="hdh-brand">
							<span className="hdh-brand__mark">
								<Icon name="wrench" size={ 20 } />
							</span>
							<span>
								<div className="hdh-brand__name">{ __( 'Support console', 'helpdesk-hero' ) }</div>
								<div className="hdh-brand__sub">{ boot.siteName }</div>
							</span>
						</div>
						<nav className="hdh-nav" aria-label={ __( 'Support console', 'helpdesk-hero' ) }>
							{ TABS.map( ( t ) => (
								<a
									key={ t.id }
									href={ `#/${ t.id }` }
									className={ `hdh-nav__item ${ t.id === current.id ? 'is-active' : '' }` }
									aria-current={ t.id === current.id ? 'page' : undefined }
								>
									<Icon name={ t.icon } size={ 15 } />
									{ t.label }
								</a>
							) ) }
						</nav>
						<div className="hdh-header__end">
							<SessionBar />
						</div>
					</div>
				</header>
				<main className="hdh-shell" key={ current.id }>
					<ErrorBoundary resetKey={ current.id }>{ page }</ErrorBoundary>
				</main>
			</ToastProvider>
		</div>
	);
}

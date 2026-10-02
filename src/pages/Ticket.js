import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	Button,
	ErrorNotice,
	Loading,
	useToast,
} from '../ui/components/ui';
import {
	Thread,
	TicketStatus,
	TextArea,
	TagPills,
	Stars,
	CopyBox,
	confirmAction,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import GrantPanel from '../components/GrantPanel';
import AccessFields from '../components/AccessFields';
import useStateApi from '../components/useStateApi';
import {
	STATUS_LABELS,
	PRIORITY_LABELS,
	message,
	boot,
} from '../components/common';

export default function Ticket( { param } ) {
	const id = parseInt( param, 10 );
	const { data, error, reload } = useApi( `admin/tickets/${ id }` );
	const state = useStateApi();
	const access = useApi( 'admin/access' );
	const compose = useApi( 'admin/compose' );
	const [ give, setGive ] = useState( null );
	const [ override, setOverride ] = useState( null );
	const [ body, setBody ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const toast = useToast();
	const ticket = override && override.id === id ? override : data;

	if ( error && ! ticket ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! ticket || ! state.data ) {
		return <Loading />;
	}
	const policy = state.data.policy;
	const canReply =
		policy.tickets.customer_replies && ticket.channel === 'hub';
	const canClose = policy.tickets.customer_close || ticket.channel !== 'hub';

	const act = ( key, path, payload, done ) => {
		setBusy( key );
		send( `admin/tickets/${ id }/${ path }`, 'POST', payload )
			.then( ( r ) => {
				setOverride( r );
				if ( done ) {
					done();
				}
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<>
			<a className="hdh-back" href="#/tickets">
				<Icon name="arrowLeft" size={ 14 } />
				{ __( 'Tickets', 'helpdesk-hero' ) }
			</a>
			<div className="hdh-pagehead">
				<div>
					<h1 className="hdh-pagehead__title">{ ticket.subject }</h1>
					<p className="hdh-pagehead__lede hdh-row">
						<TicketStatus
							status={ ticket.status }
							labels={ STATUS_LABELS }
						/>
						<span>#{ ticket.id }</span>
						<span>·</span>
						<span>
							{ PRIORITY_LABELS[ ticket.priority ] ||
								ticket.priority }
						</span>
						{ ticket.reference && (
							<>
								<span>·</span>
								<span>{ ticket.reference }</span>
							</>
						) }
						<span>·</span>
						<span title={ when( ticket.created ) }>
							{ ago( ticket.created ) }
						</span>
						<TagPills tags={ ticket.tags } />
					</p>
				</div>
				{ canClose && (
					<div className="hdh-toolbar">
						<Button
							icon={
								ticket.status === 'closed' ? 'refresh' : 'check'
							}
							disabled={ busy === 'status' }
							onClick={ () =>
								act( 'status', 'status', {
									status:
										ticket.status === 'closed'
											? 'open'
											: 'closed',
								} )
							}
						>
							{ ticket.status === 'closed'
								? __( 'Reopen', 'helpdesk-hero' )
								: __( 'Mark as solved', 'helpdesk-hero' ) }
						</Button>
					</div>
				) }
			</div>

			<div className="hdh-split">
				<div className="hdh-split__main">
					{ ticket.manual && (
						<ManualEmail
							ticket={ ticket }
							onChange={ setOverride }
						/>
					) }
					<Card>
						<Thread
							items={ ticket.messages }
							youLabel={ boot.userName }
						/>
					</Card>
					{ canReply ? (
						<Card title={ __( 'Reply', 'helpdesk-hero' ) }>
							<div className="hdh-stack">
								<TextArea
									label={ __( 'Message', 'helpdesk-hero' ) }
									value={ body }
									onChange={ setBody }
									rows={ 5 }
								/>
								<div className="hdh-row">
									<Button
										variant="primary"
										icon="send"
										disabled={
											busy === 'reply' || ! body.trim()
										}
										onClick={ () =>
											act(
												'reply',
												'reply',
												{ body },
												() => {
													setBody( '' );
													toast(
														__(
															'Reply sent',
															'helpdesk-hero'
														)
													);
												}
											)
										}
									>
										{ busy === 'reply'
											? __( 'Sending…', 'helpdesk-hero' )
											: __(
													'Send reply',
													'helpdesk-hero'
											  ) }
									</Button>
								</div>
							</div>
						</Card>
					) : (
						<div className="hdh-policy-note">
							<Icon name="info" size={ 15 } />
							<span>
								{ ticket.channel !== 'hub'
									? __(
											'You sent this ticket by email yourself; replies arrive in your inbox.',
											'helpdesk-hero'
									  )
									: sprintf(
											/* translators: %s: support team name */
											__(
												'%s replies here. To add something, contact them directly.',
												'helpdesk-hero'
											),
											boot.supportName
									  ) }
							</span>
						</div>
					) }
				</div>
				<aside className="hdh-split__side">
					{ ( ticket.can_rate || ticket.rating ) && (
						<RateCard ticket={ ticket } onChange={ setOverride } />
					) }
					<Card title={ __( 'Support access', 'helpdesk-hero' ) }>
						{ ticket.grant ? (
							<GrantPanel
								grant={ ticket.grant }
								policy={ policy.access }
								durations={
									access.data ? access.data.durations : []
								}
								activity={ ticket.activity }
								onChange={ ( grant ) =>
									setOverride( { ...ticket, grant } )
								}
							/>
						) : (
							<GiveAccess
								off={ policy.access.mode === 'off' }
								compose={ compose.data }
								value={ give }
								setValue={ setGive }
								busy={ busy === 'grant' }
								onGive={ () => {
									setBusy( 'grant' );
									send( 'admin/access', 'POST', {
										ticket: id,
										hours: give.hours,
										role: give.role,
									} )
										.then( ( r ) => {
											setOverride( {
												...ticket,
												grant: r.grant,
											} );
											toast(
												__(
													'Access given. Your support team can log in now.',
													'helpdesk-hero'
												)
											);
										} )
										.catch( ( e ) =>
											toast( message( e ), 'alert' )
										)
										.finally( () => setBusy( '' ) );
								} }
							/>
						) }
					</Card>
				</aside>
			</div>
		</>
	);
}

function GiveAccess( { off, compose, value, setValue, onGive, busy } ) {
	if ( off ) {
		return (
			<p className="hdh-muted">
				{ __(
					'Your support team doesn’t use site access.',
					'helpdesk-hero'
				) }
			</p>
		);
	}
	if ( ! value ) {
		return (
			<div className="hdh-stack">
				<p className="hdh-muted">
					{ __(
						'Support can’t log in to your site for this ticket.',
						'helpdesk-hero'
					) }
				</p>
				<div>
					<Button
						icon="key"
						disabled={ ! compose }
						onClick={ () =>
							setValue( {
								hours: compose.access.default_hours,
								role: compose.access.default_role,
							} )
						}
					>
						{ __( 'Give access', 'helpdesk-hero' ) }
					</Button>
				</div>
			</div>
		);
	}
	return (
		<div className="hdh-stack">
			<AccessFields
				access={ compose.access }
				value={ value }
				onChange={ setValue }
			/>
			<div className="hdh-row">
				<Button
					variant="primary"
					icon="key"
					disabled={ busy }
					onClick={ onGive }
				>
					{ __( 'Give access', 'helpdesk-hero' ) }
				</Button>
				<Button variant="ghost" onClick={ () => setValue( null ) }>
					{ __( 'Cancel', 'helpdesk-hero' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * "Email it myself": the steps until the customer confirms they sent it, then the text stays
 * available for reference.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.ticket   Ticket.
 * @param {Function} props.onChange Called with the updated ticket.
 * @return {JSX.Element} Card.
 */
function ManualEmail( { ticket, onChange } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ open, setOpen ] = useState( ticket.status === 'unsent' );
	const toast = useToast();
	const m = ticket.manual;
	const unsent = ticket.status === 'unsent';

	const confirm = () => {
		setBusy( 'confirm' );
		send( `admin/tickets/${ ticket.id }/emailed`, 'POST' )
			.then( ( r ) => {
				onChange( r );
				setOpen( false );
				toast(
					r.registered
						? sprintf(
								/* translators: %s: support team name */
								__(
									'Thanks! %s can now see this ticket in their hub too.',
									'helpdesk-hero'
								),
								boot.supportName
						  )
						: __(
								'Thanks! The ticket will also be added to your support team’s hub as soon as it can be reached.',
								'helpdesk-hero'
						  )
				);
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};
	const discard = async () => {
		if (
			! ( await confirmAction(
				__(
					'Discard this ticket? Any support access created for it ends too.',
					'helpdesk-hero'
				)
			) )
		) {
			return;
		}
		setBusy( 'discard' );
		send( `admin/tickets/${ ticket.id }/discard`, 'POST' )
			.then( () => {
				window.location.hash = '#/tickets';
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! unsent && ! open ) {
		return (
			<div className="hdh-policy-note">
				<Icon name="mail" size={ 15 } />
				<span>
					{ m.registered
						? __(
								'You emailed this ticket, and it’s registered with your support team.',
								'helpdesk-hero'
						  )
						: __(
								'You emailed this ticket. It will also be added to your support team’s hub as soon as it can be reached.',
								'helpdesk-hero'
						  ) }{ ' ' }
					<button
						type="button"
						className="hdh-btn is-ghost is-sm"
						onClick={ () => setOpen( true ) }
					>
						{ __( 'Show the email', 'helpdesk-hero' ) }
					</button>
				</span>
			</div>
		);
	}

	return (
		<Card
			title={
				unsent
					? __( 'Finish sending this ticket', 'helpdesk-hero' )
					: __( 'The email you sent', 'helpdesk-hero' )
			}
			sub={
				unsent
					? sprintf(
							/* translators: %s: email address */
							__(
								'Your support team hasn’t received it yet. Email the text below to %s, then confirm.',
								'helpdesk-hero'
							),
							m.to
					  )
					: null
			}
		>
			<div className="hdh-stack">
				{ unsent && (
					<ol className="hdh-steps-list">
						<li>{ __( 'Copy the text.', 'helpdesk-hero' ) }</li>
						<li>
							{ sprintf(
								/* translators: %s: email address */
								__(
									'Send it to %s from your email.',
									'helpdesk-hero'
								),
								m.to
							) }
						</li>
						<li>
							{ __(
								'Come back and press “I’ve sent it”.',
								'helpdesk-hero'
							) }
						</li>
					</ol>
				) }
				<CopyBox
					value={ m.text }
					label={ __( 'Copy email text', 'helpdesk-hero' ) }
					rows={ 12 }
				/>
				<div className="hdh-row">
					<a
						className="hdh-btn"
						href={ `mailto:${ m.to }?subject=${ encodeURIComponent(
							ticket.subject
						) }` }
					>
						<Icon name="mail" size={ 15 } />
						{ __( 'Open my email app', 'helpdesk-hero' ) }
					</a>
					{ unsent && (
						<>
							<Button
								variant="primary"
								icon="check"
								disabled={ !! busy }
								onClick={ confirm }
							>
								{ __( 'I’ve sent it', 'helpdesk-hero' ) }
							</Button>
							<span className="hdh-spacer" />
							<Button
								variant="ghost"
								icon="trash"
								disabled={ !! busy }
								onClick={ discard }
							>
								{ __( 'Discard', 'helpdesk-hero' ) }
							</Button>
						</>
					) }
					{ ! unsent && (
						<Button
							variant="ghost"
							onClick={ () => setOpen( false ) }
						>
							{ __( 'Hide', 'helpdesk-hero' ) }
						</Button>
					) }
				</div>
				{ unsent && (
					<p className="hdh-muted" style={ { fontSize: 12.5 } }>
						{ __(
							'If the email includes a support login link, it works once: keep the email private.',
							'helpdesk-hero'
						) }
					</p>
				) }
			</div>
		</Card>
	);
}

/**
 * Star rating once a ticket is solved or support access has ended.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.ticket   Ticket.
 * @param {Function} props.onChange Called with the updated ticket.
 * @return {JSX.Element} Card.
 */
function RateCard( { ticket, onChange } ) {
	const [ stars, setStars ] = useState( 0 );
	const [ hover, setHover ] = useState( 0 );
	const [ comment, setComment ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	if ( ticket.rating ) {
		return (
			<Card title={ __( 'Your rating', 'helpdesk-hero' ) }>
				<div className="hdh-stack" style={ { gap: 8 } }>
					<Stars value={ ticket.rating.stars } size={ 18 } />
					{ ticket.rating.comment && (
						<p>“{ ticket.rating.comment }”</p>
					) }
					<span className="hdh-muted">
						{ __( 'Thank you!', 'helpdesk-hero' ) }
					</span>
				</div>
			</Card>
		);
	}

	const submit = () => {
		setBusy( true );
		send( `admin/tickets/${ ticket.id }/rate`, 'POST', {
			rating: stars,
			comment,
		} )
			.then( ( r ) => {
				onChange( r );
				toast( __( 'Thanks for your feedback', 'helpdesk-hero' ) );
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};
	const labels = [
		__( 'Poor', 'helpdesk-hero' ),
		__( 'Fair', 'helpdesk-hero' ),
		__( 'Good', 'helpdesk-hero' ),
		__( 'Very good', 'helpdesk-hero' ),
		__( 'Excellent', 'helpdesk-hero' ),
	];
	const shown = hover || stars;
	return (
		<Card
			title={ sprintf(
				/* translators: %s: support team name */
				__( 'How did %s do?', 'helpdesk-hero' ),
				boot.supportName
			) }
		>
			<div className="hdh-stack">
				<div className="hdh-row">
					<span
						className="hdh-star-input"
						role="radiogroup"
						tabIndex={ -1 }
						aria-label={ __( 'Rating', 'helpdesk-hero' ) }
						onMouseLeave={ () => setHover( 0 ) }
					>
						{ [ 1, 2, 3, 4, 5 ].map( ( n ) => (
							<button
								key={ n }
								type="button"
								role="radio"
								aria-checked={ stars === n }
								aria-label={ `${ n } – ${ labels[ n - 1 ] }` }
								className={ n <= shown ? 'is-on' : '' }
								onMouseEnter={ () => setHover( n ) }
								onClick={ () => setStars( n ) }
							>
								<svg
									width="26"
									height="26"
									viewBox="0 0 24 24"
									aria-hidden="true"
								>
									<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z" />
								</svg>
							</button>
						) ) }
					</span>
					<span className="hdh-muted">
						{ shown ? labels[ shown - 1 ] : '' }
					</span>
				</div>
				<TextArea
					label={ __(
						'Anything to add? (optional)',
						'helpdesk-hero'
					) }
					value={ comment }
					onChange={ setComment }
					rows={ 3 }
				/>
				<div>
					<Button
						variant="primary"
						disabled={ ! stars || busy }
						onClick={ submit }
					>
						{ __( 'Send rating', 'helpdesk-hero' ) }
					</Button>
				</div>
			</div>
		</Card>
	);
}

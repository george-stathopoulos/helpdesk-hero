import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Setting,
	Button,
	ErrorNotice,
	Loading,
	Pill,
	useToast,
} from '../ui/components/ui';
import { KeyValues, LocalAiHint, confirmAction } from '../ui/components/kit';
import { SaveBar } from '../ui/lib/settings';
import Icon from '../ui/components/Icon';
import useStateApi from '../components/useStateApi';
import { message } from '../components/common';

function hours( h ) {
	if ( h < 24 ) {
		/* translators: %d: number of hours */
		return sprintf( _n( '%d hour', '%d hours', h, 'helpdesk-hero' ), h );
	}
	const days = Math.round( h / 24 );
	/* translators: %d: number of days */
	return sprintf( _n( '%d day', '%d days', days, 'helpdesk-hero' ), days );
}

function YesNo( { on } ) {
	return on ? (
		<Pill tone="good" icon="check">
			{ __( 'Yes', 'helpdesk-hero' ) }
		</Pill>
	) : (
		<Pill>{ __( 'No', 'helpdesk-hero' ) }</Pill>
	);
}

export default function Settings() {
	const { data, error, reload } = useStateApi();
	const [ email, setEmail ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ test, setTest ] = useState( null );
	const [ testing, setTesting ] = useState( false );
	const toast = useToast();

	useEffect( () => {
		if ( data ) {
			setEmail( data.notify_email );
		}
	}, [ data ] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || email === null ) {
		return <Loading />;
	}
	const p = data.policy;
	const modes = {
		ask: __( 'You choose with each ticket', 'helpdesk-hero' ),
		always: __( 'Included with every ticket', 'helpdesk-hero' ),
		off: __( 'Never', 'helpdesk-hero' ),
	};
	const sectionRule = {
		required: __( 'always sent', 'helpdesk-hero' ),
		on: __( 'sent unless you untick it', 'helpdesk-hero' ),
		off: __( 'only if you tick it', 'helpdesk-hero' ),
		never: __( 'never sent', 'helpdesk-hero' ),
	};
	const sectionNames = {
		environment: __( 'WordPress, PHP and server', 'helpdesk-hero' ),
		extensions: __( 'Plugins and themes', 'helpdesk-hero' ),
		errors: __( 'Recent errors', 'helpdesk-hero' ),
		changes: __( 'Recent changes', 'helpdesk-hero' ),
		debug_log: __( 'debug.log', 'helpdesk-hero' ),
	};

	const save = () => {
		setSaving( true );
		send( 'admin/settings', 'POST', { notify_email: email } )
			.then( () => {
				toast( __( 'Settings saved', 'helpdesk-hero' ) );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setSaving( false ) );
	};
	const testConnection = () => {
		setTesting( true );
		setTest( null );
		send( 'admin/test-connection', 'POST' )
			.then( ( r ) => {
				let text = sprintf(
					/* translators: %s: support team name */
					__(
						'Connected. %s’s hub received a signed request from this site and answered.',
						'helpdesk-hero'
					),
					r.hub_name || data.hub.name
				);
				if ( Math.abs( r.clock_skew ) > 120 ) {
					text +=
						' ' +
						__(
							'The two servers’ clocks differ by more than two minutes; ask your host to sync this server’s time, or requests may be refused.',
							'helpdesk-hero'
						);
				}
				setTest( { ok: true, text } );
			} )
			.catch( ( e ) => setTest( { ok: false, text: message( e ) } ) )
			.finally( () => setTesting( false ) );
	};
	const disconnect = async () => {
		if (
			await confirmAction(
				__(
					'Disconnect from your support team? Any active support access ends now. Your tickets stay here.',
					'helpdesk-hero'
				)
			)
		) {
			send( 'admin/disconnect', 'POST' ).then( () =>
				window.location.reload()
			);
		}
	};

	return (
		<>
			<PageHead title={ __( 'Settings', 'helpdesk-hero' ) } />
			<div className="hdh-split">
				<div className="hdh-split__main">
					<Card
						title={ __(
							'Your support team’s policy',
							'helpdesk-hero'
						) }
						sub={ __(
							'Set by your support team. Contact them if something here should change.',
							'helpdesk-hero'
						) }
					>
						<div className="hdh-stack" style={ { gap: 18 } }>
							<div>
								<div className="hdh-section-title">
									{ __( 'Site access', 'helpdesk-hero' ) }
								</div>
								<KeyValues
									rows={ [
										[
											__( 'When', 'helpdesk-hero' ),
											modes[ p.access.mode ],
										],
										p.access.mode !== 'off'
											? [
													__(
														'Access level',
														'helpdesk-hero'
													),
													p.access.roles
														.map(
															( r ) =>
																data
																	.role_labels[
																	r
																] || r
														)
														.join( ', ' ) +
														( p.access.customer_role
															? ` (${ __(
																	'you choose',
																	'helpdesk-hero'
															  ) })`
															: '' ),
											  ]
											: null,
										p.access.mode !== 'off'
											? [
													__(
														'How long',
														'helpdesk-hero'
													),
													sprintf(
														/* translators: 1: default length, 2: maximum length */ __(
															'%1$s, at most %2$s',
															'helpdesk-hero'
														),
														hours(
															p.access
																.default_hours
														),
														hours(
															p.access.max_hours
														)
													),
											  ]
											: null,
										p.access.mode !== 'off'
											? [
													__(
														'More time',
														'helpdesk-hero'
													),
													p.access.extension ===
													'auto'
														? __(
																'Support can extend it within the maximum',
																'helpdesk-hero'
														  )
														: __(
																'You approve each request',
																'helpdesk-hero'
														  ),
											  ]
											: null,
										p.access.mode !== 'off'
											? [
													__(
														'Plugin installs',
														'helpdesk-hero'
													),
													<YesNo
														key="p"
														on={
															p.access
																.plugin_installs
														}
													/>,
											  ]
											: null,
										p.access.mode !== 'off'
											? [
													__(
														'Pages visited are logged',
														'helpdesk-hero'
													),
													<YesNo
														key="l"
														on={
															p.access
																.log_page_views
														}
													/>,
											  ]
											: null,
										p.access.mode !== 'off'
											? [
													__(
														'Ends when the ticket closes',
														'helpdesk-hero'
													),
													<YesNo
														key="e"
														on={
															p.access
																.end_on_close
														}
													/>,
											  ]
											: null,
									] }
								/>
							</div>
							<div>
								<div className="hdh-section-title">
									{ __(
										'Site details sent with tickets',
										'helpdesk-hero'
									) }
								</div>
								<KeyValues
									rows={ Object.keys( p.diagnostics ).map(
										( k ) => [
											sectionNames[ k ] || k,
											sectionRule[ p.diagnostics[ k ] ],
										]
									) }
								/>
							</div>
						</div>
					</Card>
				</div>
				<aside className="hdh-split__side">
					{ data.policy?.tickets?.ai_assistant &&
						( ! ( window.hdhBoot || {} ).aiEnabled ||
							( ( window.hdhBoot || {} ).localAi || {} )
								.active ) && (
							<Card
								title={ __(
									'AI writing assistant',
									'helpdesk-hero'
								) }
							>
								<LocalAiHint
									local={ ( window.hdhBoot || {} ).localAi }
									enabled={
										( window.hdhBoot || {} ).aiEnabled
									}
								/>
							</Card>
						) }
					<Card title={ __( 'Connection', 'helpdesk-hero' ) }>
						<div className="hdh-stack">
							<span className="hdh-access">
								<span className="hdh-access__dot" />
								{ sprintf(
									/* translators: %s: support team */
									__( 'Connected to %s', 'helpdesk-hero' ),
									data.hub.name
								) }
							</span>
							<KeyValues
								rows={ [
									[
										__( 'Since', 'helpdesk-hero' ),
										when( data.hub.connected_at ),
									],
									[
										__( 'Hub', 'helpdesk-hero' ),
										data.hub.url,
									],
								] }
							/>
							<div className="hdh-row">
								<Button
									icon="refresh"
									disabled={ testing }
									onClick={ testConnection }
								>
									{ testing
										? __( 'Testing…', 'helpdesk-hero' )
										: __(
												'Test connection',
												'helpdesk-hero'
										  ) }
								</Button>
								<Button
									variant="ghost"
									icon="ban"
									onClick={ disconnect }
								>
									{ __( 'Disconnect', 'helpdesk-hero' ) }
								</Button>
							</div>
							{ test && (
								<div
									className="hdh-policy-note"
									role="status"
									style={
										test.ok
											? undefined
											: {
													color: 'var(--hdh-critical-ink)',
											  }
									}
								>
									<Icon
										name={ test.ok ? 'check' : 'alert' }
										size={ 15 }
									/>
									<span>{ test.text }</span>
								</div>
							) }
						</div>
					</Card>
					<Card
						title={ __( 'Notifications', 'helpdesk-hero' ) }
						bodyClass={ null }
					>
						<Setting
							title={ __( 'Email', 'helpdesk-hero' ) }
							desc={ __(
								'Replies, support logins and requests for more time.',
								'helpdesk-hero'
							) }
						>
							<input
								type="email"
								className="hdh-input"
								aria-label={ __(
									'Notification email',
									'helpdesk-hero'
								) }
								placeholder={ data.admin_email }
								value={ email }
								onChange={ ( e ) => setEmail( e.target.value ) }
							/>
						</Setting>
					</Card>
					<div className="hdh-policy-note">
						<Icon name="shield" size={ 15 } />
						<span>
							{ __(
								'Nothing is sent to your support team until you open a ticket, and only the details listed here.',
								'helpdesk-hero'
							) }
						</span>
					</div>
				</aside>
			</div>
			<SaveBar
				dirty={ email !== data.notify_email }
				saving={ saving }
				save={ save }
				discard={ () => setEmail( data.notify_email ) }
			/>
		</>
	);
}

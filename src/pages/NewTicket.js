import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	Button,
	ErrorNotice,
	Loading,
	useToast,
} from '../ui/components/ui';
import {
	TextField,
	TextArea,
	SelectField,
	Check,
	FlagList,
	CodeBlock,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import AccessFields from '../components/AccessFields';
import { message, boot } from '../components/common';

function pageUrlFromHash() {
	const query = window.location.hash.split( '?' )[ 1 ] || '';
	return new URLSearchParams( query ).get( 'page_url' ) || '';
}

export default function NewTicket( { go } ) {
	const { data, error, reload } = useApi( 'admin/compose' );
	const toast = useToast();
	const [ form, setForm ] = useState( null );
	const [ sending, setSending ] = useState( '' );
	const [ ai, setAi ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );

	useEffect( () => {
		if ( data && ! form ) {
			setForm( {
				subject: '',
				description: '',
				priority: 'normal',
				category: '',
				page_url: pageUrlFromHash(),
				contact_name: data.contact.name,
				contact_email: data.contact.email,
				sections: data.sections
					.filter( ( s ) => s.checked )
					.map( ( s ) => s.key ),
				grant: data.access.mode !== 'off',
				hours: data.access.default_hours,
				role: data.access.default_role,
			} );
		}
	}, [ data, form ] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || ! form ) {
		return <Loading />;
	}

	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const t = data.tickets;
	const a = data.access;
	const intro = ( boot.branding && boot.branding.intro ) || t.intro;
	const ready = form.subject.trim() && form.description.trim();

	const submit = ( isManual ) => {
		setSending( isManual ? 'manual' : 'send' );
		send( 'admin/tickets', 'POST', { ...form, manual: isManual } )
			.then( ( r ) => {
				if ( isManual ) {
					go( `ticket/${ r.ticket_id }` );
				} else {
					toast(
						sprintf(
							/* translators: %s: support team name */
							__( 'Ticket sent to %s', 'helpdesk-hero' ),
							boot.supportName
						)
					);
					go( `ticket/${ r.ticket_id }` );
				}
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setSending( '' ) );
	};

	const improve = () => {
		setAiBusy( true );
		const before = { subject: form.subject, description: form.description };
		send( 'admin/ai/improve', 'POST', before )
			.then( ( r ) => {
				setForm( ( f ) => ( {
					...f,
					subject: r.subject || f.subject,
					description: r.description || f.description,
				} ) );
				setAi( { ...r, before } );
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setAiBusy( false ) );
	};

	return (
		<>
			<PageHead
				title={ __( 'New ticket', 'helpdesk-hero' ) }
				lede={ sprintf(
					/* translators: %s: support team name */
					__(
						'Tell %s what’s wrong. Your site’s details are attached for you, so you don’t have to look anything up.',
						'helpdesk-hero'
					),
					boot.supportName
				) }
			/>
			{ intro && (
				<div className="hdh-banner">
					<span className="hdh-banner__icon">
						<Icon name="info" />
					</span>
					<div
						className="hdh-banner__text"
						style={ { whiteSpace: 'pre-wrap' } }
					>
						{ intro }
					</div>
				</div>
			) }

			<div className="hdh-split">
				<div className="hdh-split__main">
					<Card title={ __( 'What’s happening?', 'helpdesk-hero' ) }>
						<div className="hdh-stack">
							<TextField
								label={ __( 'Subject', 'helpdesk-hero' ) }
								value={ form.subject }
								onChange={ set( 'subject' ) }
								placeholder={ __(
									'For example: Checkout page shows a blank screen',
									'helpdesk-hero'
								) }
								maxLength={ 200 }
							/>
							<TextArea
								label={ __( 'Description', 'helpdesk-hero' ) }
								value={ form.description }
								onChange={ set( 'description' ) }
								rows={ 9 }
								placeholder={ __(
									'What did you do, what did you expect, and what happened instead? When did it start?',
									'helpdesk-hero'
								) }
							/>
							{ data.ai && (
								<div
									className="hdh-stack"
									style={ { gap: 10 } }
								>
									<div className="hdh-row">
										<Button
											icon="spark"
											onClick={ improve }
											disabled={
												aiBusy ||
												! form.description.trim()
											}
										>
											{ aiBusy
												? __(
														'Thinking…',
														'helpdesk-hero'
												  )
												: __(
														'Help me describe this',
														'helpdesk-hero'
												  ) }
										</Button>
										<span
											className="hdh-muted"
											style={ { fontSize: 12.5 } }
										>
											{ __(
												'Turns your notes into a clear report using this site’s AI provider. Only your text and the health check titles are sent.',
												'helpdesk-hero'
											) }
										</span>
									</div>
									{ ai && (
										<div className="hdh-policy-note">
											<Icon name="spark" size={ 15 } />
											<div
												className="hdh-stack"
												style={ { gap: 6 } }
											>
												<span>
													{ __(
														'Your description was rewritten. Check it’s still accurate.',
														'helpdesk-hero'
													) }
												</span>
												{ ai.questions &&
													ai.questions.length > 0 && (
														<span>
															<strong>
																{ __(
																	'Support will probably ask:',
																	'helpdesk-hero'
																) }
															</strong>{ ' ' }
															{ ai.questions.join(
																' · '
															) }
														</span>
													) }
												{ ai.tips &&
													ai.tips.length > 0 && (
														<span>
															<strong>
																{ __(
																	'Worth checking first:',
																	'helpdesk-hero'
																) }
															</strong>{ ' ' }
															{ ai.tips.join(
																' · '
															) }
														</span>
													) }
												<span>
													<button
														type="button"
														className="hdh-btn is-ghost is-sm"
														onClick={ () => {
															setForm(
																( f ) => ( {
																	...f,
																	...ai.before,
																} )
															);
															setAi( null );
														} }
													>
														{ __(
															'Undo',
															'helpdesk-hero'
														) }
													</button>
												</span>
											</div>
										</div>
									) }
								</div>
							) }
							<div className="hdh-form-grid">
								{ t.priorities && (
									<SelectField
										label={ __(
											'How urgent is it?',
											'helpdesk-hero'
										) }
										value={ form.priority }
										onChange={ set( 'priority' ) }
										options={ Object.keys(
											data.priorities
										).map( ( k ) => ( {
											value: k,
											label: data.priorities[ k ],
										} ) ) }
									/>
								) }
								{ t.categories.length > 0 && (
									<SelectField
										label={ __( 'Topic', 'helpdesk-hero' ) }
										value={ form.category }
										onChange={ set( 'category' ) }
										options={ [
											{
												value: '',
												label: __(
													'Choose…',
													'helpdesk-hero'
												),
											},
											...t.categories.map( ( c ) => ( {
												value: c,
												label: c,
											} ) ),
										] }
									/>
								) }
								<TextField
									className="is-wide"
									label={ __(
										'Page with the problem (optional)',
										'helpdesk-hero'
									) }
									type="url"
									value={ form.page_url }
									onChange={ set( 'page_url' ) }
									placeholder={ boot.homeUrl }
								/>
								<TextField
									label={ __( 'Your name', 'helpdesk-hero' ) }
									value={ form.contact_name }
									onChange={ set( 'contact_name' ) }
								/>
								<TextField
									label={ __( 'Reply to', 'helpdesk-hero' ) }
									type="email"
									value={ form.contact_email }
									onChange={ set( 'contact_email' ) }
								/>
							</div>
						</div>
					</Card>

					{ a.mode !== 'off' && (
						<Card
							title={
								a.mode === 'always'
									? sprintf(
											/* translators: %s: support team name */
											__(
												'%s will be able to log in',
												'helpdesk-hero'
											),
											boot.supportName
									  )
									: __( 'Support access', 'helpdesk-hero' )
							}
							sub={
								a.mode === 'always'
									? __(
											'Your support team needs access to investigate, so it comes with every ticket.',
											'helpdesk-hero'
									  )
									: null
							}
						>
							<div className="hdh-stack">
								{ a.mode === 'ask' && (
									<Check
										checked={ form.grant }
										onChange={ set( 'grant' ) }
										label={ sprintf(
											/* translators: %s: support team name */
											__(
												'Let %s log in to investigate',
												'helpdesk-hero'
											),
											boot.supportName
										) }
										desc={ __(
											'Usually the fastest way to a fix.',
											'helpdesk-hero'
										) }
									/>
								) }
								{ ( a.mode === 'always' || form.grant ) && (
									<AccessFields
										access={ a }
										value={ {
											hours: form.hours,
											role: form.role,
										} }
										onChange={ ( v ) =>
											setForm( ( f ) => ( {
												...f,
												...v,
											} ) )
										}
									/>
								) }
							</div>
						</Card>
					) }
				</div>

				<aside className="hdh-split__side">
					{ boot.branding && boot.branding.contact && (
						<Card title={ __( 'Contact', 'helpdesk-hero' ) }>
							<p style={ { whiteSpace: 'pre-wrap' } }>
								{ boot.branding.contact }
							</p>
						</Card>
					) }
					<Card
						title={ __( 'Health check', 'helpdesk-hero' ) }
						sub={ __(
							'What support will look at first.',
							'helpdesk-hero'
						) }
					>
						<FlagList flags={ data.flags } />
					</Card>
					<Card
						title={ __(
							'Site details sent with the ticket',
							'helpdesk-hero'
						) }
						sub={ __(
							'Emails, passwords and keys are removed automatically.',
							'helpdesk-hero'
						) }
					>
						{ data.sections.map( ( s ) => (
							<Check
								key={ s.key }
								label={ s.label }
								checked={ form.sections.includes( s.key ) }
								disabled={ s.required }
								locked={
									s.required
										? __(
												'Required by your support team',
												'helpdesk-hero'
										  )
										: ''
								}
								onChange={ ( on ) =>
									set( 'sections' )(
										on
											? [ ...form.sections, s.key ]
											: form.sections.filter(
													( k ) => k !== s.key
											  )
									)
								}
							/>
						) ) }
						<details style={ { marginTop: 10 } }>
							<summary
								style={ {
									cursor: 'pointer',
									color: 'var(--hdh-accent)',
								} }
							>
								{ __(
									'Preview everything that can be sent',
									'helpdesk-hero'
								) }
							</summary>
							<div style={ { marginTop: 10 } }>
								<CodeBlock
									text={ data.preview }
									maxHeight={ 300 }
								/>
							</div>
						</details>
					</Card>
					<Card>
						<div className="hdh-stack">
							<Button
								variant="primary"
								icon="send"
								disabled={ ! ready || !! sending }
								onClick={ () => submit( false ) }
							>
								{ sending === 'send'
									? __( 'Sending…', 'helpdesk-hero' )
									: sprintf(
											/* translators: %s: support team name */
											__( 'Send to %s', 'helpdesk-hero' ),
											boot.supportName
									  ) }
							</Button>
							{ t.manual_email && (
								<Button
									variant="ghost"
									icon="mail"
									disabled={ ! ready || !! sending }
									onClick={ () => submit( true ) }
								>
									{ __(
										'Email it myself instead',
										'helpdesk-hero'
									) }
								</Button>
							) }
							<p
								className="hdh-muted"
								style={ { fontSize: 12.5 } }
							>
								{ __(
									'Replies appear in Tickets, and you get an email when support answers.',
									'helpdesk-hero'
								) }
							</p>
						</div>
					</Card>
				</aside>
			</div>
		</>
	);
}

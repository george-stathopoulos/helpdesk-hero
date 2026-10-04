import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { when } from '../ui/lib/time';
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
import { PickedSpots, useSpotsFromHash, hashParam } from '../components/Spots';
import {
	FileAttach,
	CustomFields,
	fieldsComplete,
} from '../components/Extras';
import { message, boot } from '../components/common';

/**
 * What to do about support access for a category and priority (same rules as the server):
 * off, required, ticked or unticked.
 *
 * @param {Object} a        Access policy.
 * @param {string} category Category.
 * @param {string} priority Priority.
 * @return {string} Choice.
 */
function accessChoice( a, category, priority ) {
	if ( a.mode === 'off' ) {
		return 'off';
	}
	const rules = a.rules || {};
	const rule =
		( rules.categories || {} )[ category ] ||
		( rules.priorities || {} )[ priority ] ||
		'';
	if ( a.mode === 'always' ) {
		return rule === 'off' ? 'off' : 'required';
	}
	return rule || 'ticked';
}


function pageUrlFromHash() {
	return hashParam( 'page_url' );
}


export default function NewTicket( { go } ) {
	const { data, error, reload } = useApi( 'admin/compose' );
	const toast = useToast();
	const [ form, setForm ] = useState( null );
	const [ sending, setSending ] = useState( '' );
	const [ ai, setAi ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );
	const [ pins, setPins ] = useSpotsFromHash( 'pin' );

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
				grant: accessChoice( data.access, '', 'normal' ) === 'ticked',
				hours: data.access.default_hours,
				role: data.access.default_role,
				fields: {},
				files: [],
			} );
		}
	}, [ data, form ] );

	// Pinpoint: spots picked on a page come with the address; one of them from wp-admin
	// usually needs support to log in and look.
	const fromAdmin = pins.some( ( p ) => p.area === 'admin' );
	useEffect( () => {
		if ( fromAdmin && form && data ) {
			setForm( ( f ) => ( {
				...f,
				grant: [ 'ticked', 'unticked' ].includes(
					accessChoice( data.access, f.category, f.priority )
				)
					? true
					: f.grant,
			} ) );
		}
	}, [ fromAdmin, !! form ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		if ( pins.length && form && ! form.page_url ) {
			setForm( ( f ) => ( { ...f, page_url: pins[ 0 ].url } ) );
		}
	}, [ pins.length, !! form ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// A category or priority with its own access rule sets the box the way the team chose.
	const choice =
		data && form ? accessChoice( data.access, form.category, form.priority ) : 'off';
	useEffect( () => {
		if ( form && ( choice === 'ticked' || choice === 'unticked' ) ) {
			setForm( ( f ) => ( { ...f, grant: choice === 'ticked' } ) );
		}
	}, [ choice ] ); // eslint-disable-line react-hooks/exhaustive-deps

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
	const ready =
		form.subject.trim() &&
		form.description.trim() &&
		fieldsComplete( data.fields, form.fields );

	const submit = ( isManual ) => {
		setSending( isManual ? 'manual' : 'send' );
		const { files, ...rest } = form;
		send( 'admin/tickets', 'POST', {
			...rest,
			attachments: isManual ? [] : files.map( ( f ) => f.id ),
			manual: isManual,
			pinpoint: pins.map( ( p ) => p.id ).join( ',' ),
		} )
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
					{ ! pins.length && boot.pinpoint && ! boot.pinpoint.enabled && boot.pinpoint.mode !== 'off' && (
						<div className="hdh-policy-note">
							<Icon name="pin" size={ 15 } />
							<span>
								{ __(
									'Easier: with Pinpoint you click the problem on the page itself, and your support team sees exactly where it is.',
									'helpdesk-hero'
								) }{ ' ' }
								<a href="#/pinpoint">{ __( 'Turn on Pinpoint', 'helpdesk-hero' ) }</a>
							</span>
						</div>
					) }
					<PickedSpots
						pins={ pins }
						onRemove={ ( id ) => setPins( pins.filter( ( p ) => p.id !== id ) ) }
						sub={ __(
							'Sent with the ticket, so your support team sees exactly where the problem is.',
							'helpdesk-hero'
						) }
					/>
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
								formatting
								label={ __( 'Description', 'helpdesk-hero' ) }
								value={ form.description }
								onChange={ set( 'description' ) }
								rows={ 9 }
								placeholder={ __(
									'What did you do, what did you expect, and what happened instead? When did it start?',
									'helpdesk-hero'
								) }
							/>
							<FileAttach
								config={ data.files }
								files={ form.files }
								onChange={ set( 'files' ) }
							/>
							<CustomFields
								fields={ data.fields }
								answers={ form.fields }
								onChange={ set( 'fields' ) }
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

					{ choice !== 'off' && a.current && (
						<Card title={ __( 'Support access', 'helpdesk-hero' ) }>
							<p className="hdh-muted" style={ { margin: 0 } }>
								{ sprintf(
									/* translators: 1: support team name, 2: date */
									__(
										'%1$s already has access to your site until %2$s. This ticket uses it too; change or end it under Support access.',
										'helpdesk-hero'
									),
									boot.supportName,
									when( a.current.expires_at )
								) }
							</p>
						</Card>
					) }
					{ choice !== 'off' && ! a.current && (
						<Card
							title={
								choice === 'required'
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
								choice === 'required'
									? __(
											'Your support team needs access to investigate this kind of problem, so it comes with the ticket.',
											'helpdesk-hero'
									  )
									: null
							}
						>
							<div className="hdh-stack">
								{ choice !== 'required' && (
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
								{ ( choice === 'required' || form.grant ) && (
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

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Button,
	Empty,
	ErrorNotice,
	Loading,
	useToast,
} from '../ui/components/ui';
import { CopyBox, TextField } from '../ui/components/kit';
import GrantPanel from '../components/GrantPanel';
import AccessFields from '../components/AccessFields';
import { message } from '../components/common';

export default function Access() {
	const { data, error, reload } = useApi( 'admin/access' );
	const compose = useApi( 'admin/compose' );
	const [ giving, setGiving ] = useState( null );
	const [ created, setCreated ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const policy = data.policy;

	const give = () => {
		setBusy( true );
		send( 'admin/access', 'POST', giving )
			.then( ( r ) => {
				setCreated( r.url );
				setGiving( null );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<PageHead
				title={ __( 'Support access', 'helpdesk-hero' ) }
				lede={ __(
					'Temporary accounts for your support team. They only log in with a one-time link, are deleted when access ends, and everything they do is logged.',
					'helpdesk-hero'
				) }
			>
				{ policy.mode !== 'off' && ! giving && compose.data && (
					<Button
						icon="key"
						onClick={ () => {
							setCreated( '' );
							setGiving( {
								hours: compose.data.access.default_hours,
								role: compose.data.access.default_role,
								note: '',
							} );
						} }
					>
						{ __(
							'Give access without a ticket',
							'helpdesk-hero'
						) }
					</Button>
				) }
			</PageHead>

			{ giving && (
				<Card
					title={ __(
						'Give access without a ticket',
						'helpdesk-hero'
					) }
					sub={ __(
						'For example when support asks by phone. You get a one-time login link to send them.',
						'helpdesk-hero'
					) }
					style={ { marginBottom: 18 } }
				>
					<div className="hdh-stack">
						<AccessFields
							access={ compose.data.access }
							value={ giving }
							onChange={ ( v ) =>
								setGiving( { ...giving, ...v } )
							}
						/>
						<TextField
							label={ __(
								'Note for your records (optional)',
								'helpdesk-hero'
							) }
							value={ giving.note }
							onChange={ ( note ) =>
								setGiving( { ...giving, note } )
							}
						/>
						<div className="hdh-row">
							<Button
								variant="primary"
								icon="key"
								disabled={ busy }
								onClick={ give }
							>
								{ __( 'Create login link', 'helpdesk-hero' ) }
							</Button>
							<Button
								variant="ghost"
								onClick={ () => setGiving( null ) }
							>
								{ __( 'Cancel', 'helpdesk-hero' ) }
							</Button>
						</div>
					</div>
				</Card>
			) }
			{ created && (
				<Card
					title={ __(
						'Send this link to your support team',
						'helpdesk-hero'
					) }
					style={ { marginBottom: 18 } }
				>
					<CopyBox
						value={ created }
						label={ __( 'Copy login link', 'helpdesk-hero' ) }
						note={ __(
							'It works once and opens a confirmation page first, so email scanners can’t use it up.',
							'helpdesk-hero'
						) }
					/>
				</Card>
			) }

			<div className="hdh-section-title">
				{ __( 'Active now', 'helpdesk-hero' ) }
			</div>
			{ ! data.active.length ? (
				<Card>
					<Empty
						compact
						title={ __(
							'Nobody from support can log in right now.',
							'helpdesk-hero'
						) }
					/>
				</Card>
			) : (
				<div className="hdh-grid" style={ { marginBottom: 18 } }>
					{ data.active.map( ( g ) => (
						<Card
							key={ g.id }
							className="hdh-span-6"
							title={
								g.ticket ? (
									<a href={ `#/ticket/${ g.ticket.id }` }>
										{ g.ticket.subject }
									</a>
								) : (
									g.note ||
									__(
										'Access without a ticket',
										'helpdesk-hero'
									)
								)
							}
						>
							<GrantPanel
								grant={ g }
								policy={ policy }
								durations={ data.durations }
								activity={ [] }
								onChange={ reload }
							/>
						</Card>
					) ) }
				</div>
			) }

			{ data.past.length > 0 && (
				<>
					<div
						className="hdh-section-title"
						style={ { marginTop: 18 } }
					>
						{ __( 'Past access', 'helpdesk-hero' ) }
					</div>
					<Card bodyClass={ null }>
						<div className="hdh-table-wrap">
							<table className="hdh-table">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'For', 'helpdesk-hero' ) }
										</th>
										<th scope="col">
											{ __(
												'Access level',
												'helpdesk-hero'
											) }
										</th>
										<th scope="col">
											{ __( 'Granted', 'helpdesk-hero' ) }
										</th>
										<th scope="col">
											{ __( 'Ended', 'helpdesk-hero' ) }
										</th>
										<th scope="col">
											{ __( 'Logged', 'helpdesk-hero' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ data.past.map( ( g ) => (
										<tr key={ g.id }>
											<td>
												{ g.ticket ? (
													<a
														href={ `#/ticket/${ g.ticket.id }` }
													>
														{ g.ticket.subject }
													</a>
												) : (
													g.note || '—'
												) }
											</td>
											<td>{ g.role_label }</td>
											<td title={ when( g.created_at ) }>
												{ ago( g.created_at ) }
											</td>
											<td
												title={ when(
													g.ended_at || g.expires_at
												) }
											>
												{ ago(
													g.ended_at || g.expires_at
												) }
											</td>
											<td>
												<a
													href={ `#/activity?grant=${ g.id }` }
												>
													{ g.events }
												</a>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</Card>
				</>
			) }
		</>
	);
}

import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { useApi } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Segmented,
	Empty,
	ErrorNotice,
	Loading,
	Button,
} from '../ui/components/ui';
import { TicketStatus, AccessState, TagPills } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { STATUS_LABELS, PRIORITY_LABELS, boot } from '../components/common';

export default function Tickets( { go } ) {
	const [ status, setStatus ] = useState( 'active' );
	const { data, error, loading, reload } = useApi(
		addQueryArgs( 'admin/tickets', { status } )
	);

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	return (
		<>
			<PageHead
				title={ __( 'Tickets', 'helpdesk-hero' ) }
				lede={ __(
					'Everything you sent to your support team, with their replies.',
					'helpdesk-hero'
				) }
			>
				<Button
					variant="primary"
					icon="plus"
					onClick={ () => go( 'new' ) }
				>
					{ __( 'New ticket', 'helpdesk-hero' ) }
				</Button>
			</PageHead>
			{ data.tickets.some( ( t ) => t.needs_rating ) && (
				<div className="hdh-banner">
					<span className="hdh-banner__icon">
						<Icon name="spark" />
					</span>
					<div className="hdh-banner__text">
						<strong>
							{ sprintf(
								/* translators: %s: support team name */
								__( 'How did %s do?', 'helpdesk-hero' ),
								boot.supportName
							) }
						</strong>{ ' ' }
						{ __(
							'Rate a solved ticket in a few seconds. It helps them improve.',
							'helpdesk-hero'
						) }
					</div>
					<a
						className="hdh-btn is-primary is-sm"
						href={ `#/ticket/${
							data.tickets.find( ( t ) => t.needs_rating ).id
						}` }
					>
						{ __( 'Rate now', 'helpdesk-hero' ) }
					</a>
				</div>
			) }
			{ data.tickets.some( ( t ) => t.status === 'unsent' ) && (
				<div className="hdh-banner">
					<span className="hdh-banner__icon">
						<Icon name="mail" />
					</span>
					<div className="hdh-banner__text">
						<strong>
							{ __(
								'A ticket is waiting to be emailed.',
								'helpdesk-hero'
							) }
						</strong>{ ' ' }
						{ __(
							'Open it to copy the email, send it, and confirm.',
							'helpdesk-hero'
						) }
					</div>
					<a
						className="hdh-btn is-sm"
						href={ `#/ticket/${
							data.tickets.find( ( t ) => t.status === 'unsent' )
								.id
						}` }
					>
						{ __( 'Open', 'helpdesk-hero' ) }
					</a>
				</div>
			) }
			<Card
				bodyClass={ null }
				title={ boot.supportName }
				action={
					<Segmented
						label={ __( 'Show', 'helpdesk-hero' ) }
						value={ status }
						onChange={ setStatus }
						options={ [
							{
								value: 'active',
								label: __( 'Open', 'helpdesk-hero' ),
							},
							{
								value: 'closed',
								label: __( 'Closed', 'helpdesk-hero' ),
							},
							{
								value: 'all',
								label: __( 'All', 'helpdesk-hero' ),
							},
						] }
					/>
				}
			>
				{ ! data.tickets.length ? (
					<Empty
						title={
							status === 'active'
								? __( 'No open tickets', 'helpdesk-hero' )
								: __( 'Nothing here', 'helpdesk-hero' )
						}
						text={ __(
							'Something not working? Open a ticket and your site’s details are attached for you.',
							'helpdesk-hero'
						) }
					>
						<Button
							variant="primary"
							icon="plus"
							onClick={ () => go( 'new' ) }
						>
							{ __( 'New ticket', 'helpdesk-hero' ) }
						</Button>
					</Empty>
				) : (
					<div
						className={ `hdh-table-wrap ${
							loading ? 'hdh-is-loading' : ''
						}` }
					>
						<table className="hdh-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Subject', 'helpdesk-hero' ) }
									</th>
									<th scope="col">
										{ __( 'Status', 'helpdesk-hero' ) }
									</th>
									<th scope="col">
										{ __(
											'Support access',
											'helpdesk-hero'
										) }
									</th>
									<th scope="col">
										{ __( 'Updated', 'helpdesk-hero' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ data.tickets.map( ( t ) => (
									<tr
										key={ t.id }
										className="hdh-row-link"
										onClick={ () =>
											go( `ticket/${ t.id }` )
										}
									>
										<td>
											{ t.unread && (
												<span
													className="hdh-unread-dot"
													title={ __(
														'New reply',
														'helpdesk-hero'
													) }
												/>
											) }
											<a
												className="hdh-strong-link"
												href={ `#/ticket/${ t.id }` }
												onClick={ ( e ) =>
													e.stopPropagation()
												}
											>
												{ t.subject }
											</a>
											<div className="hdh-muted">
												#{ t.id } ·{ ' ' }
												{ PRIORITY_LABELS[
													t.priority
												] || t.priority }
												{ t.reference
													? ` · ${ t.reference }`
													: '' }
											</div>
											{ t.tags.length > 0 && (
												<div style={ { marginTop: 4 } }>
													<TagPills tags={ t.tags } />
												</div>
											) }
										</td>
										<td>
											<TicketStatus
												status={ t.status }
												labels={ STATUS_LABELS }
											/>
										</td>
										<td>
											<AccessState
												active={ t.access_active }
												expires={ t.access_expires }
												emptyLabel={ __(
													'None',
													'helpdesk-hero'
												) }
											/>
										</td>
										<td title={ when( t.updated_at ) }>
											{ ago( t.updated_at ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>
		</>
	);
}

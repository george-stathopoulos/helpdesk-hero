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
	Pill,
} from '../ui/components/ui';

export default function Activity() {
	const grant = new URLSearchParams(
		window.location.hash.split( '?' )[ 1 ] || ''
	).get( 'grant' );
	const [ type, setType ] = useState( 'support' );
	const { data, error, loading, reload } = useApi(
		addQueryArgs( 'admin/activity', grant ? { grant } : { type } )
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
				title={ __( 'Activity', 'helpdesk-hero' ) }
				lede={ __(
					'What support did on your site, what changed recently, and the errors your site reported.',
					'helpdesk-hero'
				) }
			/>
			<Card
				bodyClass={ null }
				title={
					grant
						? sprintf(
								/* translators: %s: access number */
								__( 'Support access #%s', 'helpdesk-hero' ),
								grant
						  )
						: null
				}
				action={
					grant ? (
						<a className="hdh-btn is-ghost is-sm" href="#/activity">
							{ __( 'Show everything', 'helpdesk-hero' ) }
						</a>
					) : (
						<Segmented
							label={ __( 'Show', 'helpdesk-hero' ) }
							value={ type }
							onChange={ setType }
							options={ [
								{
									value: 'support',
									label: __(
										'Support activity',
										'helpdesk-hero'
									),
								},
								{
									value: 'change',
									label: __(
										'Site changes',
										'helpdesk-hero'
									),
								},
								{
									value: 'error',
									label: __( 'Errors', 'helpdesk-hero' ),
								},
							] }
						/>
					)
				}
			>
				{ ! data.entries.length ? (
					<Empty
						compact
						title={ __( 'Nothing recorded yet.', 'helpdesk-hero' ) }
					/>
				) : (
					<div
						className={ `hdh-table-wrap ${
							loading ? 'hdh-is-loading' : ''
						}` }
					>
						<table className="hdh-table">
							<thead>
								<tr>
									<th scope="col" style={ { width: 150 } }>
										{ __( 'When', 'helpdesk-hero' ) }
									</th>
									<th scope="col" style={ { width: 200 } }>
										{ __( 'Who', 'helpdesk-hero' ) }
									</th>
									<th scope="col">
										{ __( 'What', 'helpdesk-hero' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ data.entries.map( ( e ) => (
									<tr key={ e.id }>
										<td title={ when( e.time ) }>
											{ ago( e.time ) }
										</td>
										<td>
											{ e.support ? (
												<Pill tone="accent">
													{ e.who }
												</Pill>
											) : (
												e.who
											) }
											{ e.ip && (
												<div className="hdh-muted">
													{ e.ip }
												</div>
											) }
										</td>
										<td>
											{ e.text }
											{ e.changes && (
												<details>
													<summary
														style={ {
															cursor: 'pointer',
															color: 'var(--hdh-accent)',
														} }
													>
														{ __(
															'Details',
															'helpdesk-hero'
														) }
													</summary>
													<ul
														style={ {
															margin: '6px 0 0 16px',
															listStyle: 'disc',
														} }
													>
														{ Object.keys(
															e.changes
														).map( ( k ) => (
															<li key={ k }>
																<code>
																	{ k }
																</code>
																:{ ' ' }
																<del>
																	{
																		e
																			.changes[
																			k
																		].from
																	}
																</del>{ ' ' }
																→{ ' ' }
																<ins>
																	{
																		e
																			.changes[
																			k
																		].to
																	}
																</ins>
															</li>
														) ) }
													</ul>
												</details>
											) }
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

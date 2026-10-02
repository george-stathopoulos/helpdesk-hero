import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import { Button, useToast } from '../ui/components/ui';
import {
	AccessState,
	KeyValues,
	CopyBox,
	confirmAction,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { message, boot } from './common';

/**
 * Status and controls for one support access grant. What is offered follows the support
 * team's policy: extending only if they allow it, and never beyond their maximum length.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.grant     Grant.
 * @param {Object}   props.policy    Access policy.
 * @param {Array}    props.durations Duration options.
 * @param {Function} props.onChange  Called with the updated grant.
 * @param {Array}    props.activity  Recent activity (optional).
 * @return {JSX.Element} Panel.
 */
export default function GrantPanel( {
	grant,
	policy,
	durations,
	onChange,
	activity,
} ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );
	const [ hours, setHours ] = useState(
		durations && durations.length
			? String( durations[ Math.min( 2, durations.length - 1 ) ].value )
			: '24'
	);
	const [ link, setLink ] = useState( '' );
	const [ summary, setSummary ] = useState( '' );

	const act = ( op, extra = {} ) => {
		setBusy( op );
		return send( `admin/access/${ grant.id }`, 'POST', { op, ...extra } )
			.then( ( r ) => {
				if ( r.url ) {
					setLink( r.url );
				}
				onChange( r.grant );
				const done = {
					extend: __( 'Access extended', 'helpdesk-hero' ),
					approve: __( 'Extension approved', 'helpdesk-hero' ),
					decline: __( 'Extension declined', 'helpdesk-hero' ),
					revoke: __(
						'Access ended. The support account was deleted.',
						'helpdesk-hero'
					),
				};
				if ( done[ op ] ) {
					toast( done[ op ] );
				}
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	let linkLabel = '—';
	if ( grant.link_used_at ) {
		linkLabel = sprintf(
			/* translators: %s: relative time */
			__( 'Used %s', 'helpdesk-hero' ),
			ago( grant.link_used_at )
		);
	} else if ( grant.link_pending ) {
		linkLabel = __( 'Not used yet', 'helpdesk-hero' );
	}

	const explain = () => {
		setBusy( 'summary' );
		send( 'admin/ai/summary', 'POST', { grant: grant.id } )
			.then( ( r ) => setSummary( r.summary ) )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<div className="hdh-stack">
			<AccessState
				active={ grant.active }
				expires={ grant.expires_at }
				emptyLabel={
					grant.ended_at
						? sprintf(
								/* translators: %s: relative time */ __(
									'Ended %s',
									'helpdesk-hero'
								),
								ago( grant.ended_at )
						  )
						: __( 'Ended', 'helpdesk-hero' )
				}
			/>

			{ grant.extension && grant.active && (
				<div className="hdh-banner" style={ { margin: 0 } }>
					<span className="hdh-banner__icon">
						<Icon name="clock" />
					</span>
					<div className="hdh-banner__text">
						<strong>
							{ sprintf(
								/* translators: 1: support name, 2: hours */
								__(
									'%1$s asks for %2$d more hours.',
									'helpdesk-hero'
								),
								grant.extension.by || boot.supportName,
								grant.extension.hours
							) }
						</strong>{ ' ' }
						{ grant.extension.reason }
					</div>
					<Button
						size="sm"
						variant="primary"
						disabled={ !! busy }
						onClick={ () => act( 'approve' ) }
					>
						{ __( 'Approve', 'helpdesk-hero' ) }
					</Button>
					<Button
						size="sm"
						variant="ghost"
						disabled={ !! busy }
						onClick={ () => act( 'decline' ) }
					>
						{ __( 'Decline', 'helpdesk-hero' ) }
					</Button>
				</div>
			) }

			<KeyValues
				rows={ [
					[ __( 'Access level', 'helpdesk-hero' ), grant.role_label ],
					[ __( 'Account', 'helpdesk-hero' ), grant.account || '—' ],
					[ __( 'Login link', 'helpdesk-hero' ), linkLabel ],
					[
						__( 'Granted', 'helpdesk-hero' ),
						when( grant.created_at ),
					],
					grant.plugins
						? [
								__( 'Plugins', 'helpdesk-hero' ),
								__(
									'May install and delete plugins',
									'helpdesk-hero'
								),
						  ]
						: null,
				] }
			/>

			{ grant.active && (
				<div className="hdh-row">
					{ policy.customer_extend &&
						durations &&
						durations.length > 0 && (
							<>
								<select
									className="hdh-input hdh-select"
									aria-label={ __(
										'Extend by',
										'helpdesk-hero'
									) }
									value={ hours }
									onChange={ ( e ) =>
										setHours( e.target.value )
									}
								>
									{ durations.map( ( d ) => (
										<option
											key={ d.value }
											value={ d.value }
										>
											+{ d.label }
										</option>
									) ) }
								</select>
								<Button
									size="sm"
									icon="clock"
									disabled={ !! busy }
									onClick={ () =>
										act( 'extend', {
											hours: parseInt( hours, 10 ),
										} )
									}
								>
									{ __( 'Extend', 'helpdesk-hero' ) }
								</Button>
							</>
						) }
					{ ! grant.ticket && (
						<Button
							size="sm"
							icon="link"
							disabled={ !! busy }
							onClick={ () => act( 'link' ) }
						>
							{ __( 'New login link', 'helpdesk-hero' ) }
						</Button>
					) }
					<Button
						size="sm"
						variant="ghost"
						icon="ban"
						disabled={ !! busy }
						onClick={ () =>
							confirmAction(
								__(
									'End support access now? The support account is deleted and logged out.',
									'helpdesk-hero'
								),
								{
									confirmText: __(
										'End access',
										'helpdesk-hero'
									),
								}
							).then( ( yes ) => yes && act( 'revoke' ) )
						}
					>
						{ __( 'End access now', 'helpdesk-hero' ) }
					</Button>
				</div>
			) }

			{ link && (
				<CopyBox
					value={ link }
					label={ __( 'Copy login link', 'helpdesk-hero' ) }
					note={ __(
						'Works once. Any older link stops working.',
						'helpdesk-hero'
					) }
				/>
			) }

			{ activity && (
				<div>
					<div
						className="hdh-row"
						style={ {
							justifyContent: 'space-between',
							marginBottom: 6,
						} }
					>
						<span
							className="hdh-section-title"
							style={ { margin: 0 } }
						>
							{ __( 'What support did', 'helpdesk-hero' ) }
						</span>
						<span className="hdh-row" style={ { gap: 6 } }>
							{ boot.aiEnabled && activity.length > 0 && (
								<Button
									size="sm"
									variant="ghost"
									icon="spark"
									disabled={ busy === 'summary' }
									onClick={ explain }
								>
									{ busy === 'summary'
										? __( 'Explaining…', 'helpdesk-hero' )
										: __(
												'Explain in plain English',
												'helpdesk-hero'
										  ) }
								</Button>
							) }
							<a
								className="hdh-btn is-ghost is-sm"
								href={ `#/activity?grant=${ grant.id }` }
							>
								{ __( 'Full log', 'helpdesk-hero' ) }
							</a>
						</span>
					</div>
					{ summary && (
						<div
							className="hdh-policy-note"
							style={ {
								whiteSpace: 'pre-wrap',
								marginBottom: 10,
							} }
						>
							<Icon name="spark" size={ 15 } />
							<span>{ summary }</span>
						</div>
					) }
					{ ! activity.length ? (
						<p className="hdh-muted">
							{ __( 'Nothing yet.', 'helpdesk-hero' ) }
						</p>
					) : (
						<ul
							className="hdh-feed"
							style={ { maxHeight: 300, overflow: 'auto' } }
						>
							{ activity.slice( 0, 20 ).map( ( e ) => (
								<li
									key={ e.id }
									className="hdh-feed__item"
									style={ {
										gridTemplateColumns:
											'minmax(0,1fr) auto',
										padding: '8px 0',
									} }
								>
									<span>{ e.text }</span>
									<span
										className="hdh-muted"
										title={ when( e.time ) }
									>
										{ ago( e.time ) }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</div>
	);
}

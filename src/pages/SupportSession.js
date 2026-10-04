import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	ErrorNotice,
	Loading,
	Pill,
	Button,
	useToast,
} from '../ui/components/ui';
import { KeyValues, confirmAction } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { RichText } from '../ui/components/rich';
import { when, ago } from '../ui/lib/time';
import { TroubleshootPanel } from './Troubleshoot';

/**
 * What support sees on a customer's site: the session, read-only. Support works on the site and
 * talks to the customer from the support hub, never through the customer's own help center.
 *
 * @return {JSX.Element} Page.
 */
export default function SupportSession() {
	const { data, error, reload } = useApi( 'admin/session' );
	const [ ending, setEnding ] = useState( false );
	const toast = useToast();
	const endAccess = async () => {
		const ok = await confirmAction(
			__(
				'End access now? Your support account is deleted and you are logged out. To come back, the site owner has to give access again.',
				'helpdesk-hero'
			),
			{ confirmText: __( 'End access', 'helpdesk-hero' ) }
		);
		if ( ! ok ) {
			return;
		}
		setEnding( true );
		send( 'admin/session/end', 'POST', {} )
			.then( ( r ) => window.location.assign( r.redirect ) )
			.catch( ( e ) => {
				toast( e.message || __( 'Could not end access.', 'helpdesk-hero' ), 'alert' );
				setEnding( false );
			} );
	};
	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const logged = [
		__( 'Logging in and out', 'helpdesk-hero' ),
		__(
			'Settings you change, with the old and new value',
			'helpdesk-hero'
		),
		__(
			'Plugins and themes you install, update or switch',
			'helpdesk-hero'
		),
		__( 'Posts and pages you create, edit or delete', 'helpdesk-hero' ),
		__( 'Troubleshooting mode on and off', 'helpdesk-hero' ),
	];
	if ( data.page_views ) {
		logged.splice( 1, 0, __( 'Every page you visit', 'helpdesk-hero' ) );
	}
	return (
		<>
			<PageHead
				title={ __( 'Support session', 'helpdesk-hero' ) }
				lede={ sprintf(
					/* translators: 1: site name, 2: support team */
					__(
						'You are remotely accessing %1$s as %2$s support. Reply to the customer from your support hub: this site’s help center belongs to the site owner.',
						'helpdesk-hero'
					),
					data.site,
					data.team
				) }
			/>
				{ ( data.tickets || [] ).length > 0 && (
					<div className="hdh-section-title">
						{ __( 'Open tickets', 'helpdesk-hero' ) }
					</div>
				) }
			<div className="hdh-split">
				<div className="hdh-split__main">
					{ ( data.tickets || [] ).map( ( t ) => (
						<Card
							key={ t.id }
							title={ t.subject }
							sub={ sprintf(
								/* translators: 1: ticket number, 2: date */
								__( '#%1$d · opened %2$s', 'helpdesk-hero' ),
								t.id,
								when( t.created )
							) }
						>
							<div className="hdh-stack">
								<div className="hdh-rich">
									<RichText text={ t.description } />
								</div>
								{ t.spots.length > 0 && (
									<ul className="hdh-plain-list hdh-session-spots">
										{ t.spots.map( ( s, i ) => (
											<li key={ s.id }>
												<span className="hdh-picked-spot__num">{ i + 1 }</span>
												<strong>{ s.label || s.title || s.url }</strong>{ ' ' }
												<span className="hdh-muted">
													{ s.kind === 'area'
														? __( 'area', 'helpdesk-hero' )
														: __( 'element', 'helpdesk-hero' ) }
													{ ' · ' }
													{ ago( s.created_at ) }
												</span>{ ' ' }
												<a className="hdh-btn is-sm" href={ s.highlight }>
													<Icon name="eye" size={ 14 } />
													{ __( 'Open and highlight', 'helpdesk-hero' ) }
												</a>
											</li>
										) ) }
									</ul>
								) }
							</div>
						</Card>
					) ) }
					{ data.troubleshooting && (
						<Card>
							<TroubleshootPanel embedded />
						</Card>
					) }
					<Card
						title={ __(
							'What the site owner sees',
							'helpdesk-hero'
						) }
						sub={ __(
							'Everything below is recorded and listed in the site owner’s Activity log, as your team’s policy sets.',
							'helpdesk-hero'
						) }
					>
						<ul className="hdh-steps-list">
							{ logged.map( ( l ) => (
								<li key={ l }>{ l }</li>
							) ) }
						</ul>
					</Card>
				</div>
				<aside className="hdh-split__side">
					<Card title={ __( 'This session', 'helpdesk-hero' ) }>
						<div className="hdh-stack">
							<Pill tone="good" dot>
								{ data.permanent
									? __( 'No end date', 'helpdesk-hero' )
									: sprintf(
											/* translators: %s: date and time */
											__( 'Until %s', 'helpdesk-hero' ),
											when( data.expires )
									  ) }
							</Pill>
							<KeyValues
								rows={ [
									[
										__( 'Signed in as', 'helpdesk-hero' ),
										data.user,
									],
									[
										__( 'Access level', 'helpdesk-hero' ),
										data.role,
									],
									[
										__(
											'Plugin installs',
											'helpdesk-hero'
										),
										data.plugin_installs
											? __( 'Allowed', 'helpdesk-hero' )
											: __(
													'Not allowed',
													'helpdesk-hero'
											  ),
									],
								] }
							/>
							<div className="hdh-session-exit">
								<a className="hdh-btn" href={ data.logout }>
									<Icon name="login" size={ 15 } />
									{ __( 'Leave session', 'helpdesk-hero' ) }
								</a>
								<p className="hdh-muted">
									{ __(
										'Log out. Access stays on: your team can log in again from the support hub until it ends.',
										'helpdesk-hero'
									) }
								</p>
								<Button
									variant="danger"
									icon="x"
									disabled={ ending }
									onClick={ endAccess }
								>
									{ __( 'End access', 'helpdesk-hero' ) }
								</Button>
								<p className="hdh-muted">
									{ __(
										'For when you’re done: deletes your support account now. The site owner has to give access again if you need it.',
										'helpdesk-hero'
									) }
								</p>
							</div>
						</div>
					</Card>
				</aside>
			</div>
		</>
	);
}

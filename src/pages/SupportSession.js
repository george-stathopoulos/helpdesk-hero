import { __, sprintf } from '@wordpress/i18n';
import { useApi } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	ErrorNotice,
	Loading,
	Pill,
} from '../ui/components/ui';
import { KeyValues } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { when } from '../ui/lib/time';

/**
 * What support sees on a customer's site: the session, read-only. Support works on the site and
 * talks to the customer from the support hub, never through the customer's own help center.
 *
 * @return {JSX.Element} Page.
 */
export default function SupportSession() {
	const { data, error, reload } = useApi( 'admin/session' );
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
			<div className="hdh-split">
				<div className="hdh-split__main">
					{ data.ticket && (
						<Card
							title={ data.ticket.subject }
							sub={ sprintf(
								/* translators: %s: date */
								__( 'Opened %s', 'helpdesk-hero' ),
								when( data.ticket.created )
							) }
						>
							<p style={ { whiteSpace: 'pre-wrap' } }>
								{ data.ticket.description }
							</p>
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
								{ sprintf(
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
							{ data.troubleshooting && (
								<a
									className="hdh-btn"
									href={ data.troubleshooting }
								>
									<Icon name="wrench" size={ 15 } />
									{ __(
										'Troubleshooting mode',
										'helpdesk-hero'
									) }
								</a>
							) }
							<a
								className="hdh-btn is-ghost"
								href={ data.logout }
							>
								<Icon name="login" size={ 15 } />
								{ __( 'Log out', 'helpdesk-hero' ) }
							</a>
						</div>
					</Card>
				</aside>
			</div>
		</>
	);
}

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { Card, Button } from '../ui/components/ui';
import Icon from '../ui/components/Icon';
import { message, boot } from '../components/common';

export default function Connect() {
	// A connection link from the support team fills in the code.
	const fromLink = boot.connectCode || '';
	const [ code, setCode ] = useState( fromLink );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const connect = () => {
		setBusy( true );
		setError( '' );
		send( 'admin/connect', 'POST', { code } )
			.then( () => {
				// Reload without the code from the link, which is used up now.
				const url = new URL( window.location.href );
				url.searchParams.delete( 'hdh_code' );
				window.location.replace( url.toString() );
			} )
			.catch( ( e ) => {
				setError( message( e ) );
				setBusy( false );
			} );
	};

	return (
		<div className="hdh-connect">
			<div className="hdh-connect__mark">
				<Icon name="ring" size={ 28 } />
			</div>
			<h1>{ __( 'Connect to your support team', 'helpdesk-hero' ) }</h1>
			<p>
				{ fromLink
					? __(
							'Your support team’s link filled in the connection code. Click Connect to finish. Once connected you can open tickets from here, with your site’s details attached, and give support safe, temporary access when they need it.',
							'helpdesk-hero'
					  )
					: __(
							'Paste the connection code your support team gave you. Once connected you can open tickets from here, with your site’s details attached, and give support safe, temporary access when they need it.',
							'helpdesk-hero'
					  ) }
			</p>
			<Card>
				<div className="hdh-stack">
					<div className="hdh-field">
						<label htmlFor="hdh-code">
							{ __( 'Connection code', 'helpdesk-hero' ) }
						</label>
						<textarea
							id="hdh-code"
							className="hdh-input"
							rows={ 3 }
							style={ {
								fontFamily: 'var(--hdh-mono)',
								fontSize: 12.5,
							} }
							placeholder="hdh1.…"
							value={ code }
							onChange={ ( e ) => setCode( e.target.value ) }
						/>
						{ error && (
							<div
								className="hdh-field__help"
								role="alert"
								style={ { color: 'var(--hdh-critical-ink)' } }
							>
								{ error }
							</div>
						) }
					</div>
					<div className="hdh-row">
						<Button
							variant="primary"
							icon="link"
							onClick={ connect }
							disabled={ busy || ! code.trim() }
						>
							{ busy
								? __( 'Connecting…', 'helpdesk-hero' )
								: __( 'Connect', 'helpdesk-hero' ) }
						</Button>
					</div>
					<div className="hdh-policy-note">
						<Icon name="shield" size={ 15 } />
						<span>
							{ __(
								'Nothing is sent until you open a ticket, and you see everything before it goes. Your support team never gets a password: access is a temporary account you can end at any time.',
								'helpdesk-hero'
							) }
						</span>
					</div>
				</div>
			</Card>
			<p className="hdh-muted" style={ { marginTop: 18 } }>
				{ __(
					'No code? Ask the company that supports your site (your developer, agency or plugin vendor) whether they use Helpdesk Hero.',
					'helpdesk-hero'
				) }
			</p>
		</div>
	);
}

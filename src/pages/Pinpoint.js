/**
 * Pinpoint: what it is, how to use it, and the switch to turn it on.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { Card, PageHead, Button, Setting, useToast } from '../ui/components/ui';
import Icon from '../ui/components/Icon';
import PinpointDemo from '../ui/components/PinpointDemo';
import { message, boot } from '../components/common';

const STEPS = [
	{
		icon: 'pin',
		title: __( 'Click “Report a problem”', 'helpdesk-hero' ),
		text: __(
			'On the page with the problem, click Report a problem in the black toolbar at the top. It’s there on your dashboard and on your site itself, whenever you’re logged in.',
			'helpdesk-hero'
		),
	},
	{
		icon: 'eye',
		title: __( 'Point at the problem', 'helpdesk-hero' ),
		text: __(
			'Click the part of the page that’s wrong: a button, a block, a message. Or drag a box around an area. Press Esc to cancel.',
			'helpdesk-hero'
		),
	},
	{
		icon: 'message',
		title: __( 'Say what’s wrong and send', 'helpdesk-hero' ),
		text: __(
			'A new ticket opens with the spot and the page already attached. Describe the problem in a sentence or two and send it. Your support team opens the page with that spot highlighted.',
			'helpdesk-hero'
		),
	},
];

export default function Pinpoint() {
	const toast = useToast();
	const [ state, setState ] = useState( boot.pinpoint || { enabled: false, mode: 'customer' } );
	const [ busy, setBusy ] = useState( false );
	const team = boot.supportName;

	const save = ( patch, after ) => {
		setBusy( true );
		send( 'admin/pinpoint-settings', 'POST', patch )
			.then( ( s ) => {
				setState( s );
				boot.pinpoint = s;
				if ( after ) {
					after( s );
				}
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	// The picker loads with the page, so turning Pinpoint on reloads to add it to the toolbar.
	const activate = () =>
		save( { enabled: true }, () => {
			toast( __( 'Pinpoint is on. Look for Report a problem in the toolbar.', 'helpdesk-hero' ) );
			window.setTimeout( () => window.location.reload(), 900 );
		} );

	const tryIt = () => {
		const pin = window.hdhPinpoint;
		if ( pin && pin.start ) {
			pin.start();
		} else {
			window.location.reload();
		}
	};

	return (
		<>
			<PageHead
				title={ __( 'Pinpoint', 'helpdesk-hero' ) }
				lede={ sprintf(
					/* translators: %s: support team name */
					__(
						'Show %s exactly where the problem is, instead of describing it. Point at it on the page; the rest is attached for you.',
						'helpdesk-hero'
					),
					team
				) }
			/>

			<Card className="hdh-pinpoint-hero" style={ { marginBottom: 18 } }>
				<div className="hdh-pinpoint-hero__inner">
					<span className="hdh-pinpoint-hero__icon">
						<Icon name="pin" size={ 26 } />
					</span>
					<div className="hdh-pinpoint-hero__text">
						<strong>
							{ state.enabled
								? __( 'Pinpoint is on', 'helpdesk-hero' )
								: __( 'Pinpoint is off', 'helpdesk-hero' ) }
						</strong>
						<span>
							{ state.mode === 'always' && (
								<>
									{ sprintf(
										/* translators: %s: support team name */
										__( '%s keeps Pinpoint on for this site.', 'helpdesk-hero' ),
										team
									) }{ ' ' }
								</>
							) }
							{ state.enabled
								? __(
										'Report a problem is in the toolbar at the top of every page, for administrators of this site.',
										'helpdesk-hero'
								  )
								: __(
										'Turn it on to add Report a problem to the toolbar at the top of every page. Only administrators of this site see it.',
										'helpdesk-hero'
								  ) }
						</span>
					</div>
					{ state.enabled ? (
						<Button variant="primary" icon="pin" onClick={ tryIt }>
							{ __( 'Try it on this page', 'helpdesk-hero' ) }
						</Button>
					) : (
						<Button variant="primary" icon="pin" disabled={ busy || ! state.connected } onClick={ activate }>
							{ __( 'Turn on Pinpoint', 'helpdesk-hero' ) }
						</Button>
					) }
				</div>
			</Card>

			<div className="hdh-section-title">{ __( 'How it works', 'helpdesk-hero' ) }</div>
			<Card style={ { marginBottom: 18 } }>
				<PinpointDemo />
			</Card>
			<div className="hdh-grid" style={ { marginBottom: 18 } }>
				{ STEPS.map( ( s, i ) => (
					<Card key={ s.title } className="hdh-span-4">
						<div className="hdh-pinpoint-step">
							<span className="hdh-pinpoint-step__num">{ i + 1 }</span>
							<Icon name={ s.icon } size={ 20 } />
						</div>
						<strong className="hdh-pinpoint-step__title">{ s.title }</strong>
						<p className="hdh-muted" style={ { margin: '6px 0 0' } }>
							{ s.text }
						</p>
					</Card>
				) ) }
			</div>

			<div className="hdh-grid" style={ { marginBottom: 18 } }>
				<Card
					className="hdh-span-6"
					title={ __( 'What’s sent with the ticket', 'helpdesk-hero' ) }
				>
					<ul className="hdh-checklist">
						<li>{ __( 'The page’s address and title', 'helpdesk-hero' ) }</li>
						<li>{ __( 'The spot you picked, so support can open the page with it highlighted', 'helpdesk-hero' ) }</li>
						<li>{ __( 'Your screen size and browser', 'helpdesk-hero' ) }</li>
						<li>{ __( 'Errors on that page and requests that failed, which often explain the problem', 'helpdesk-hero' ) }</li>
					</ul>
				</Card>
				<Card
					className="hdh-span-6"
					title={ __( 'You stay in control', 'helpdesk-hero' ) }
				>
					<ul className="hdh-checklist">
						<li>{ __( 'Nothing is sent when you point. You see the ticket and the details before you send it, and can leave the spot out.', 'helpdesk-hero' ) }</li>
						<li>{ __( 'No screenshots: support sees the page itself, so nothing on your screen is copied.', 'helpdesk-hero' ) }</li>
						<li>{ __( 'Passwords and keys found in error messages are removed automatically.', 'helpdesk-hero' ) }</li>
						<li>{ __( 'Problem in your dashboard? Pinpoint suggests giving support access, following your support team’s rules, so they can look.', 'helpdesk-hero' ) }</li>
					</ul>
				</Card>
			</div>

			{ state.enabled && (
				<Card title={ __( 'Settings', 'helpdesk-hero' ) }>
					{ state.mode !== 'always' && (
					<Setting
						title={ __( 'Pinpoint', 'helpdesk-hero' ) }
						desc={ __( 'Turn it off to remove Report a problem from the toolbar.', 'helpdesk-hero' ) }
					>
						<Button
							variant="ghost"
							disabled={ busy }
							onClick={ () => save( { enabled: false }, () => window.location.reload() ) }
						>
							{ __( 'Turn off', 'helpdesk-hero' ) }
						</Button>
					</Setting>
					) }
				</Card>
			) }
		</>
	);
}

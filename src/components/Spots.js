/**
 * Problem spots (Pinpoint) on the customer's screens: the spots about to be sent with a ticket
 * or reply, and the ones already sent.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import { Card, Button, useToast } from '../ui/components/ui';
import { KeyValues } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { message } from './common';

/**
 * A query parameter from the #… part of the address.
 *
 * @param {string} name Name.
 * @return {string} Value.
 */
export function hashParam( name ) {
	const query = window.location.hash.split( '?' )[ 1 ] || '';
	return new URLSearchParams( query ).get( name ) || '';
}

/**
 * Spots named in the address (#…?pin=a,b or ?pins=a,b), loaded once.
 *
 * @param {string} name Parameter.
 * @return {Array} [ pins, setPins ].
 */
export function useSpotsFromHash( name ) {
	const [ pins, setPins ] = useState( [] );
	const toast = useToast();
	useEffect( () => {
		const ids = hashParam( name ).split( ',' ).filter( Boolean ).slice( 0, 5 );
		if ( ! ids.length ) {
			return;
		}
		Promise.all( ids.map( ( id ) => send( `admin/pinpoint/${ id }` ).catch( () => null ) ) ).then(
			( list ) => {
				const found = list.filter( Boolean );
				if ( found.length < ids.length ) {
					toast( __( 'Some spots couldn’t be found. Try picking them again.', 'helpdesk-hero' ), 'alert' );
				}
				setPins( found );
			}
		);
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps
	return [ pins, setPins ];
}

function spotText( pin ) {
	if ( pin.kind === 'area' ) {
		return __( 'The area you drew a box around', 'helpdesk-hero' );
	}
	return pin.text ? `“${ pin.text }”` : __( 'The element you clicked', 'helpdesk-hero' );
}

/**
 * Spots about to be sent, each removable.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.pins     Spots.
 * @param {Function} props.onRemove ( id ) => void.
 * @param {string}   props.sub      Explanation.
 * @return {JSX.Element|null} Card.
 */
export function PickedSpots( { pins, onRemove, sub } ) {
	if ( ! pins.length ) {
		return null;
	}
	return (
		<Card
			title={
				pins.length === 1
					? __( 'Problem spot', 'helpdesk-hero' )
					: sprintf(
							/* translators: %d: number of spots */
							__( '%d problem spots', 'helpdesk-hero' ),
							pins.length
					  )
			}
			sub={ sub }
		>
			<div className="hdh-stack">
				{ pins.map( ( pin, i ) => (
					<div key={ pin.id } className="hdh-picked-spot">
						<div className="hdh-row" style={ { justifyContent: 'space-between' } }>
							<strong>
								<span className="hdh-picked-spot__num">{ i + 1 }</span>
								{ pin.label || pin.title || pin.url }
							</strong>
							<Button
								size="sm"
								variant="ghost"
								icon="trash"
								onClick={ () => onRemove( pin.id ) }
							>
								{ __( 'Don’t send', 'helpdesk-hero' ) }
							</Button>
						</div>
						<KeyValues
							rows={ [
								[ __( 'Page', 'helpdesk-hero' ), pin.title || pin.url ],
								[ __( 'Spot', 'helpdesk-hero' ), spotText( pin ) ],
								[
									__( 'Errors on the page', 'helpdesk-hero' ),
									pin.errors.length
										? pin.errors.map( ( e ) => e.message ).join( ' · ' )
										: __( 'None', 'helpdesk-hero' ),
								],
								[
									__( 'Failed requests', 'helpdesk-hero' ),
									pin.requests.length
										? pin.requests
												.map( ( r ) => `${ r.status || '—' } ${ r.url }` )
												.join( ' · ' )
										: __( 'None', 'helpdesk-hero' ),
								],
							] }
						/>
					</div>
				) ) }
			</div>
		</Card>
	);
}

/**
 * Spots already sent with a ticket.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.spots Spots { id, title, url, label, kind, created_at }.
 * @return {JSX.Element|null} Card.
 */
export function SentSpots( { spots } ) {
	if ( ! spots || ! spots.length ) {
		return null;
	}
	return (
		<Card title={ __( 'Problem spots', 'helpdesk-hero' ) }>
			<ul className="hdh-plain-list">
				{ spots.map( ( s ) => (
					<li key={ s.id }>
						<Icon name="pin" size={ 13 } />{ ' ' }
						<a href={ s.url } target="_blank" rel="noopener noreferrer">
							{ s.label || s.title || s.url }
						</a>{ ' ' }
						<span className="hdh-muted" title={ when( s.created_at ) }>
							{ ago( s.created_at ) }
						</span>
					</li>
				) ) }
			</ul>
		</Card>
	);
}

/**
 * "Add a problem spot" for a reply: explains how, and makes the toolbar's Report a problem
 * offer this ticket first.
 *
 * @param {Object} props          Props.
 * @param {number} props.ticketId Ticket.
 * @param {Object} props.state    Pinpoint state from boot.
 * @return {JSX.Element|null} Button and help.
 */
export function AddSpot( { ticketId, state } ) {
	const [ open, setOpen ] = useState( false );
	if ( ! state || state.mode === 'off' ) {
		return null;
	}
	const start = () => {
		try {
			window.sessionStorage.setItem( 'hdh-pin-for', String( ticketId ) );
		} catch ( e ) {}
		setOpen( true );
	};
	return (
		<div>
			<Button size="sm" variant="ghost" icon="pin" onClick={ start }>
				{ __( 'Add a problem spot', 'helpdesk-hero' ) }
			</Button>
			{ open && (
				<div className="hdh-policy-note" style={ { marginTop: 8 } }>
					<Icon name="pin" size={ 15 } />
					<span>
						{ state.enabled
							? __(
									'Go to the page with the problem and click Report a problem in the toolbar at the top. Point at the problem, then choose this ticket under “Send with”. You’ll come back here with the spot added to your reply.',
									'helpdesk-hero'
							  )
							: __(
									'Turn on Pinpoint first (Get Help › Pinpoint). Then go to the page with the problem, click Report a problem in the toolbar and choose this ticket.',
									'helpdesk-hero'
							  ) }{ ' ' }
						{ ! state.enabled && (
							<a href="#/pinpoint">{ __( 'Turn on Pinpoint', 'helpdesk-hero' ) }</a>
						) }
					</span>
				</div>
			) }
		</div>
	);
}

export { message };

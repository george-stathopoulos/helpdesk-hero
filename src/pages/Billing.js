import { __, sprintf } from '@wordpress/i18n';
import { useApi } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	ErrorNotice,
	Loading,
	Empty,
	Pill,
} from '../ui/components/ui';
import { boot } from '../components/common';

/**
 * Money in the support team's currency.
 *
 * @param {number} amount   Amount.
 * @param {string} currency ISO code.
 * @return {string} Formatted.
 */
export function money( amount, currency ) {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currency || 'EUR',
		} ).format( amount || 0 );
	} catch ( e ) {
		return `${ ( amount || 0 ).toFixed( 2 ) } ${ currency || '' }`;
	}
}

/**
 * "1 h 25 min".
 *
 * @param {number} minutes Minutes.
 * @return {string} Duration.
 */
export function duration( minutes ) {
	const m = Math.round( minutes || 0 );
	const h = Math.floor( m / 60 );
	if ( ! h ) {
		/* translators: %d: minutes */
		return sprintf( __( '%d min', 'helpdesk-hero' ), m );
	}
	return m % 60
		? /* translators: 1: hours, 2: minutes */
		  sprintf( __( '%1$d h %2$d min', 'helpdesk-hero' ), h, m % 60 )
		: /* translators: %d: hours */
		  sprintf( __( '%d h', 'helpdesk-hero' ), h );
}

function monthName( ym ) {
	return new Date( `${ ym }-15T12:00:00` ).toLocaleDateString( undefined, {
		month: 'long',
		year: 'numeric',
	} );
}

export const STATE = {
	unbilled: {
		label: __( 'To be invoiced', 'helpdesk-hero' ),
		tone: 'warning',
	},
	invoiced: { label: __( 'Invoiced', 'helpdesk-hero' ), tone: 'accent' },
	paid: { label: __( 'Paid', 'helpdesk-hero' ), tone: 'good' },
	free: { label: __( 'No charge', 'helpdesk-hero' ), tone: '' },
};

export default function Billing( { go } ) {
	const { data, error, reload } = useApi( 'admin/billing' );
	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const c = data.currency;
	const usedHours = data.used_minutes / 60;
	const share = data.retainer_hours
		? Math.min( 100, ( usedHours / data.retainer_hours ) * 100 )
		: 0;
	return (
		<>
			<PageHead
				title={ __( 'Billing', 'helpdesk-hero' ) }
				lede={ sprintf(
					/* translators: %s: support team name */
					__(
						'What %s did for you, how long it took and what it costs. Invoices come from your support team; this page shows where each charge stands.',
						'helpdesk-hero'
					),
					boot.supportName
				) }
			/>
			<div className="hdh-kpis is-3">
				<Card bodyClass={ null }>
					<div className="hdh-stat">
						<div className="hdh-stat__label">
							{ __( 'Time this month', 'helpdesk-hero' ) }
						</div>
						<div className="hdh-stat__value">
							{ duration( data.month_minutes ) }
						</div>
					</div>
				</Card>
				<Card bodyClass={ null }>
					<div className="hdh-stat">
						<div className="hdh-stat__label">
							{ __( 'To be invoiced', 'helpdesk-hero' ) }
						</div>
						<div className="hdh-stat__value">
							{ money( data.due, c ) }
						</div>
					</div>
				</Card>
				<Card bodyClass={ null }>
					<div className="hdh-stat">
						<div className="hdh-stat__label">
							{ __( 'Invoiced, not yet paid', 'helpdesk-hero' ) }
						</div>
						<div className="hdh-stat__value">
							{ money( data.invoiced, c ) }
						</div>
					</div>
				</Card>
			</div>
			{ data.retainer_hours > 0 && (
				<Card
					title={ __( 'Your plan this month', 'helpdesk-hero' ) }
					style={ { marginBottom: 18 } }
				>
					<p style={ { marginTop: 0 } }>
						{ sprintf(
							/* translators: 1: hours used, 2: hours included */
							__(
								'%1$s of %2$s included hours used. Time beyond them is charged at the usual rate.',
								'helpdesk-hero'
							),
							usedHours.toFixed( 1 ),
							data.retainer_hours
						) }
					</p>
					<div
						className="hdh-meter"
						role="progressbar"
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
						aria-valuenow={ Math.round( share ) }
						aria-label={ __( 'Included hours used', 'helpdesk-hero' ) }
					>
						<span className="hdh-meter__fill" style={ { width: `${ share }%` } } />
					</div>
				</Card>
			) }
			<Card
				title={ __( 'Month by month', 'helpdesk-hero' ) }
				bodyClass={ null }
				style={ { marginBottom: 18 } }
			>
				{ ! data.months.length ? (
					<Empty
						compact
						title={ __( 'Nothing recorded yet.', 'helpdesk-hero' ) }
					/>
				) : (
					<div className="hdh-table-wrap">
						<table className="hdh-table">
							<thead>
								<tr>
									<th scope="col">{ __( 'Month', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Time', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Cost', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'To be invoiced', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Invoiced', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Paid', 'helpdesk-hero' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.months.map( ( m ) => (
									<tr key={ m.month }>
										<td>
											{ monthName( m.month ) }
											{ m.retainer_hours > 0 && (
												<div className="hdh-muted">
													{ sprintf(
														/* translators: 1: hours used, 2: hours included */
														__( '%1$s of %2$s included hours', 'helpdesk-hero' ),
														( m.retainer_used / 60 ).toFixed( 1 ),
														m.retainer_hours
													) }
												</div>
											) }
										</td>
										<td>{ duration( m.minutes ) }</td>
										<td>{ money( m.charged, c ) }</td>
										<td>{ money( m.due, c ) }</td>
										<td>{ money( m.invoiced, c ) }</td>
										<td>{ money( m.paid, c ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>
			<Card
				title={ __( 'Work and charges', 'helpdesk-hero' ) }
				sub={ __(
					'The last six months, per ticket and service.',
					'helpdesk-hero'
				) }
				bodyClass={ null }
			>
				{ ! data.items.length ? (
					<Empty compact title={ __( 'Nothing recorded yet.', 'helpdesk-hero' ) } />
				) : (
					<div className="hdh-table-wrap">
						<table className="hdh-table">
							<thead>
								<tr>
									<th scope="col">{ __( 'Ticket', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Service', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Time', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Cost', 'helpdesk-hero' ) }</th>
									<th scope="col">{ __( 'Status', 'helpdesk-hero' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( i, n ) => (
									<tr key={ n }>
										<td>
											{ i.ticket ? (
												<a
													href={ `#/ticket/${ i.ticket }` }
													onClick={ ( e ) => {
														e.preventDefault();
														go( `ticket/${ i.ticket }` );
													} }
												>
													{ i.subject }
												</a>
											) : (
												i.subject
											) }
											<div className="hdh-muted">{ monthName( i.month ) }</div>
										</td>
										<td>{ i.service || '—' }</td>
										<td>{ i.minutes ? duration( i.minutes ) : '—' }</td>
										<td>{ money( i.amount, c ) }</td>
										<td>
											<Pill tone={ STATE[ i.state ].tone }>
												{ STATE[ i.state ].label }
											</Pill>
											{ i.invoice_ref && (
												<div className="hdh-muted">{ i.invoice_ref }</div>
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

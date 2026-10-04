import { __ } from '@wordpress/i18n';
import { SelectField } from '../ui/components/kit';
import Icon from '../ui/components/Icon';

/**
 * Duration and access level, as far as the support team's policy lets the customer choose.
 * Fixed values are shown as plain text instead of a control.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.access   Access policy (with role_options, duration_options, default_label).
 * @param {Object}   props.value    { hours, role }.
 * @param {Function} props.onChange Change handler.
 * @return {JSX.Element} Fields.
 */
// Same value as Helpdesk_Hero_Policy::PERMANENT: access with no end date.
export const PERMANENT = 999999;

export default function AccessFields( { access, value, onChange } ) {
	const durations = access.duration_options || [];
	const roles = access.role_options || [];
	const fixedDuration = durations.find(
		( d ) => d.value === access.default_hours
	);
	return (
		<div className="hdh-stack">
			<div className="hdh-form-grid">
				{ access.customer_duration ? (
					<SelectField
						label={ __( 'For how long', 'helpdesk-hero' ) }
						value={ String( value.hours || access.default_hours ) }
						onChange={ ( v ) =>
							onChange( { ...value, hours: parseInt( v, 10 ) } )
						}
						options={ durations.map( ( d ) => ( {
							value: String( d.value ),
							label: d.label,
						} ) ) }
					/>
				) : (
					<div className="hdh-field">
						<span className="hdh-field__label">
							{ __( 'For how long', 'helpdesk-hero' ) }
						</span>
						<span>
							{ fixedDuration
								? fixedDuration.label
								: `${ access.default_hours }h` }
						</span>
					</div>
				) }
				{ access.customer_role ? (
					<SelectField
						label={ __( 'Access level', 'helpdesk-hero' ) }
						value={ value.role || access.default_role }
						onChange={ ( v ) => onChange( { ...value, role: v } ) }
						options={ roles }
					/>
				) : (
					<div className="hdh-field">
						<span className="hdh-field__label">
							{ __( 'Access level', 'helpdesk-hero' ) }
						</span>
						<span>{ access.default_label }</span>
					</div>
				) }
			</div>
			{ ( value.hours || access.default_hours ) === PERMANENT && (
				<div className="hdh-policy-note">
					<Icon name="clock" size={ 15 } />
					<span>
						{ __(
							'No end date: access lasts until you or your support team end it, even after tickets close. Handy while your site is being built or for ongoing care. Everything is still logged, and you can end it any time under Support access.',
							'helpdesk-hero'
						) }
					</span>
				</div>
			) }
			<div className="hdh-policy-note">
				<Icon name="shield" size={ 15 } />
				<span>
					{ __(
						'A temporary account with a login link that works once. It is deleted when access ends, everything it does is logged for you, and you can end it at any time.',
						'helpdesk-hero'
					) }
					{ access.plugin_installs
						? ' ' +
						  __(
								'Support may install and delete plugins.',
								'helpdesk-hero'
						  )
						: '' }
				</span>
			</div>
		</div>
	);
}

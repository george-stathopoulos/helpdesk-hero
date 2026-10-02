import { __ } from '@wordpress/i18n';

export const STATUS_LABELS = {
	open: __( 'Open', 'helpdesk-hero' ),
	pending: __( 'Waiting for you', 'helpdesk-hero' ),
	sent: __( 'Sent by email', 'helpdesk-hero' ),
	closed: __( 'Closed', 'helpdesk-hero' ),
};

export const PRIORITY_LABELS = {
	low: __( 'Low', 'helpdesk-hero' ),
	normal: __( 'Normal', 'helpdesk-hero' ),
	high: __( 'High', 'helpdesk-hero' ),
	urgent: __( 'Urgent', 'helpdesk-hero' ),
};

/**
 * Error message from an apiFetch rejection.
 *
 * @param {Object} e Error.
 * @return {string} Message.
 */
export function message( e ) {
	return ( e && e.message ) || __( 'Something went wrong.', 'helpdesk-hero' );
}

export const boot = window.hdhBoot || {};

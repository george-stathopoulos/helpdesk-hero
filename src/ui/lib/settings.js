/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useApi, send } from './hooks';
import { useToast, Button } from '../components/ui';
import Icon from '../components/Icon';

/**
 * Load settings and keep an editable draft.
 *
 * @return {Object} { payload, draft, setDraft, patch, dirty, save, discard, saving, error, reload }.
 */
export function useSettingsDraft() {
	const { data, error, reload } = useApi( 'settings' );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const toast = useToast();

	useEffect( () => {
		if ( data ) {
			setDraft( data.settings );
		}
	}, [ data ] );

	const dirty =
		!! data &&
		!! draft &&
		JSON.stringify( draft ) !== JSON.stringify( data.settings );

	/**
	 * Shallow-merge a value into a top-level section (or set a top-level scalar).
	 */
	const patch = useCallback( ( section, value ) => {
		setDraft( ( d ) => {
			if (
				value !== null &&
				typeof value === 'object' &&
				! Array.isArray( value ) &&
				d[ section ] &&
				typeof d[ section ] === 'object' &&
				! Array.isArray( d[ section ] )
			) {
				return { ...d, [ section ]: { ...d[ section ], ...value } };
			}
			return { ...d, [ section ]: value };
		} );
	}, [] );

	const save = useCallback( () => {
		setSaving( true );
		return send( 'settings', 'POST', draft )
			.then( () => {
				toast( __( 'Settings saved', 'helpdesk-hero' ) );
				reload();
			} )
			.catch( ( e ) =>
				toast(
					e.message || __( 'Could not save settings', 'helpdesk-hero' ),
					'alert'
				)
			)
			.finally( () => setSaving( false ) );
	}, [ draft, reload, toast ] );

	const discard = useCallback( () => setDraft( data?.settings ), [ data ] );

	return {
		payload: data,
		draft,
		setDraft,
		patch,
		dirty,
		save,
		discard,
		saving,
		error,
		reload,
	};
}

/**
 * Floating bar shown while there are unsaved changes.
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.dirty   Unsaved changes.
 * @param {boolean}  props.saving  Saving.
 * @param {Function} props.save    Save.
 * @param {Function} props.discard Discard.
 * @return {JSX.Element|null} Bar.
 */
export function SaveBar( { dirty, saving, save, discard } ) {
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const warn = ( e ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	if ( ! dirty ) {
		return null;
	}
	return (
		<div
			className="hdh-savebar"
			role="region"
			aria-label={ __( 'Unsaved changes', 'helpdesk-hero' ) }
		>
			<Icon name="info" size={ 16 } />
			<span>{ __( 'You have unsaved changes', 'helpdesk-hero' ) }</span>
			<Button
				variant="ghost"
				size="sm"
				onClick={ discard }
				disabled={ saving }
			>
				{ __( 'Discard', 'helpdesk-hero' ) }
			</Button>
			<Button
				variant="primary"
				size="sm"
				onClick={ save }
				disabled={ saving }
			>
				{ saving
					? __( 'Saving…', 'helpdesk-hero' )
					: __( 'Save changes', 'helpdesk-hero' ) }
			</Button>
		</div>
	);
}

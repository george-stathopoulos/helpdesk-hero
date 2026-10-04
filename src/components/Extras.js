/**
 * Extras the support team turns on from their hub: file attachments and custom ticket fields.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, useToast } from '../ui/components/ui';
import {
	TextField,
	TextArea,
	SelectField,
	Check,
	fileSize,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { message } from './common';

/**
 * Attach files: each is uploaded to this site straight away and sent with the ticket or reply.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.config   { enabled, max_mb, max_files, types }.
 * @param {Array}    props.files    Uploaded files.
 * @param {Function} props.onChange New list.
 * @return {JSX.Element|null} Control.
 */
export function FileAttach( { config, files, onChange } ) {
	const input = useRef( null );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();
	if ( ! config || ! config.enabled ) {
		return null;
	}
	const pick = async ( e ) => {
		const chosen = Array.from( e.target.files || [] );
		e.target.value = '';
		if ( files.length + chosen.length > config.max_files ) {
			toast(
				sprintf(
					/* translators: %d: number of files */
					__( 'You can attach up to %d files.', 'helpdesk-hero' ),
					config.max_files
				),
				'alert'
			);
			return;
		}
		setBusy( true );
		const added = [];
		for ( const file of chosen ) {
			if ( file.size > config.max_mb * 1024 * 1024 ) {
				toast(
					sprintf(
						/* translators: 1: file name, 2: megabytes */
						__( '%1$s is larger than %2$d MB.', 'helpdesk-hero' ),
						file.name,
						config.max_mb
					),
					'alert'
				);
				continue;
			}
			const body = new window.FormData();
			body.append( 'file', file );
			try {
				added.push(
					await apiFetch( {
						path: '/helpdesk-hero/v1/admin/attachments',
						method: 'POST',
						body,
					} )
				);
			} catch ( err ) {
				toast( `${ file.name }: ${ message( err ) }`, 'alert' );
			}
		}
		onChange( [ ...files, ...added ] );
		setBusy( false );
	};
	return (
		<div className="hdh-stack" style={ { gap: 6 } }>
			<div className="hdh-row">
				<Button
					size="sm"
					icon="paperclip"
					disabled={ busy }
					onClick={ () => input.current && input.current.click() }
				>
					{ busy
						? __( 'Uploading…', 'helpdesk-hero' )
						: __( 'Attach screenshots or files', 'helpdesk-hero' ) }
				</Button>
				<span className="hdh-muted" style={ { fontSize: 12.5 } }>
					{ sprintf(
						/* translators: 1: number of files, 2: megabytes, 3: file types */
						__(
							'Up to %1$d files, %2$d MB each (%3$s).',
							'helpdesk-hero'
						),
						config.max_files,
						config.max_mb,
						config.types.join( ', ' )
					) }
				</span>
				<input
					ref={ input }
					type="file"
					multiple
					className="hdh-sr"
					tabIndex={ -1 }
					aria-hidden="true"
					accept={ config.types.map( ( t ) => `.${ t }` ).join( ',' ) }
					onChange={ pick }
				/>
			</div>
			{ files.length > 0 && (
				<ul className="hdh-attachments">
					{ files.map( ( f ) => (
						<li key={ f.id }>
							<span className="hdh-attachments__chip">
								<Icon name="file" size={ 14 } />
								<span className="hdh-attachments__name">
									{ f.name }
								</span>
								<span className="hdh-muted">
									{ fileSize( f.size ) }
								</span>
								<button
									type="button"
									className="hdh-link-button"
									aria-label={ sprintf(
										/* translators: %s: file name */
										__( 'Remove %s', 'helpdesk-hero' ),
										f.name
									) }
									onClick={ () =>
										onChange(
											files.filter( ( x ) => x.id !== f.id )
										)
									}
								>
									×
								</button>
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Whether every required field has an answer.
 *
 * @param {Array}  fields  Definitions.
 * @param {Object} answers Answers.
 * @return {boolean} Complete.
 */
export function fieldsComplete( fields, answers ) {
	return ( fields || [] ).every(
		( f ) =>
			! f.required ||
			( answers[ f.id ] !== undefined &&
				String( answers[ f.id ] ).trim() !== '' )
	);
}

/**
 * The support team's extra questions.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.fields   Definitions.
 * @param {Object}   props.answers  Answers.
 * @param {Function} props.onChange New answers.
 * @return {JSX.Element|null} Fields.
 */
export function CustomFields( { fields, answers, onChange } ) {
	if ( ! fields || ! fields.length ) {
		return null;
	}
	const set = ( id ) => ( v ) => onChange( { ...answers, [ id ]: v } );
	return (
		<div className="hdh-stack">
			{ fields.map( ( f ) => {
				const label = f.required
					? sprintf(
							/* translators: %s: field label */
							__( '%s (required)', 'helpdesk-hero' ),
							f.label
					  )
					: f.label;
				switch ( f.type ) {
					case 'textarea':
						return (
							<TextArea
								key={ f.id }
								label={ label }
								help={ f.help }
								rows={ 3 }
								value={ answers[ f.id ] || '' }
								onChange={ set( f.id ) }
							/>
						);
					case 'select':
						return (
							<SelectField
								key={ f.id }
								label={ label }
								help={ f.help }
								value={ answers[ f.id ] || '' }
								onChange={ set( f.id ) }
								options={ [
									{
										value: '',
										label: __( 'Choose…', 'helpdesk-hero' ),
									},
									...f.options.map( ( o ) => ( {
										value: o,
										label: o,
									} ) ),
								] }
							/>
						);
					case 'checkbox':
						return (
							<Check
								key={ f.id }
								label={ f.label }
								desc={ f.help }
								checked={ !! answers[ f.id ] }
								onChange={ set( f.id ) }
							/>
						);
					default:
						return (
							<TextField
								key={ f.id }
								label={ label }
								help={ f.help }
								type={
									{ number: 'number', url: 'url' }[ f.type ] ||
									'text'
								}
								value={ answers[ f.id ] || '' }
								onChange={ set( f.id ) }
							/>
						);
				}
			} ) }
		</div>
	);
}

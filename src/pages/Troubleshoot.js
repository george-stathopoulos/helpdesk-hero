import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Button,
	ErrorNotice,
	Loading,
	Pill,
	useToast,
} from '../ui/components/ui';
import { Check } from '../ui/components/kit';
import { Shell } from '../App';
import { message } from '../components/common';

/**
 * Troubleshooting mode: on its own page (site owners, Tools › Troubleshoot) or inside the
 * Support session page (support accounts).
 *
 * @param {Object}  props          Props.
 * @param {boolean} props.embedded Inside another page: a section heading instead of a page title.
 * @return {JSX.Element} Panel.
 */
export function TroubleshootPanel( { embedded } ) {
	const { data, error, reload } = useApi( 'admin/troubleshoot' );
	const [ keep, setKeep ] = useState( null );
	const [ theme, setTheme ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const selected =
		keep === null
			? data.plugins.filter( ( p ) => p.keep ).map( ( p ) => p.file )
			: keep;
	const useTheme = keep === null ? data.use_theme : theme;

	const apply = ( op ) => {
		setBusy( true );
		send( 'admin/troubleshoot', 'POST', {
			op,
			keep: selected,
			theme: useTheme,
		} )
			.then( () => window.location.reload() )
			.catch( ( e ) => {
				toast( message( e ), 'alert' );
				setBusy( false );
			} );
	};

	return (
		<>
			{ embedded ? (
				<div className="hdh-row" style={ { justifyContent: 'space-between', marginTop: 8 } }>
					<div>
						<div className="hdh-section-title" style={ { margin: 0 } }>
							{ __( 'Troubleshooting mode', 'helpdesk-hero' ) }
						</div>
						<p className="hdh-muted" style={ { margin: '4px 0 10px' } }>
							{ __(
								'Turn plugins off (or use a default theme) for your browser session only. Visitors and other users keep seeing the site exactly as it is. Switch off half, test, then narrow it down.',
								'helpdesk-hero'
							) }
						</p>
					</div>
					{ data.active && (
						<Pill tone="warning" dot>
							{ __( 'On for your session', 'helpdesk-hero' ) }
						</Pill>
					) }
				</div>
			) : (
				<PageHead
					title={ __( 'Troubleshooting mode', 'helpdesk-hero' ) }
					lede={ __(
						'Turn plugins off for your browser session only. Visitors and other users keep seeing the site exactly as it is. Switch off half, test, then narrow it down.',
						'helpdesk-hero'
					) }
				>
					{ data.active && (
						<Pill tone="warning" dot>
							{ __( 'On for your session', 'helpdesk-hero' ) }
						</Pill>
					) }
				</PageHead>
			) }
			<Card
				title={ __( 'Plugins to keep on', 'helpdesk-hero' ) }
				sub={
					data.active
						? `${ __(
								'Switches itself off',
								'helpdesk-hero'
						  ) } ${ ago( data.expires_at ) } (${ when(
								data.expires_at
						  ) })`
						: null
				}
				action={
					<div className="hdh-row">
						<Button
							size="sm"
							variant="ghost"
							onClick={ () =>
								setKeep( data.plugins.map( ( p ) => p.file ) )
							}
						>
							{ __( 'Keep all', 'helpdesk-hero' ) }
						</Button>
						<Button
							size="sm"
							variant="ghost"
							onClick={ () => setKeep( [] ) }
						>
							{ __( 'Switch all off', 'helpdesk-hero' ) }
						</Button>
					</div>
				}
			>
				<div className="hdh-stack" style={ { gap: 0 } }>
					{ data.plugins.map( ( p ) => (
						<Check
							key={ p.file }
							label={ p.name }
							desc={ p.version }
							checked={ selected.includes( p.file ) }
							onChange={ ( on ) =>
								setKeep(
									on
										? [ ...selected, p.file ]
										: selected.filter(
												( f ) => f !== p.file
										  )
								)
							}
						/>
					) ) }
					{ data.default_theme && (
						<Check
							label={ __(
								'Also switch to a default theme',
								'helpdesk-hero'
							) }
							desc={ data.default_theme }
							checked={ useTheme }
							onChange={ ( v ) => {
								if ( keep === null ) {
									setKeep( selected );
								}
								setTheme( v );
							} }
						/>
					) }
				</div>
				<div className="hdh-row" style={ { marginTop: 16 } }>
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => apply( 'start' ) }
					>
						{ data.active
							? __( 'Apply', 'helpdesk-hero' )
							: __( 'Start troubleshooting', 'helpdesk-hero' ) }
					</Button>
					{ data.active && (
						<Button
							variant="ghost"
							disabled={ busy }
							onClick={ () => apply( 'stop' ) }
						>
							{ __( 'Stop troubleshooting', 'helpdesk-hero' ) }
						</Button>
					) }
				</div>
			</Card>
		</>
	);
}

export default function Troubleshoot() {
	return (
		<Shell>
			<main className="hdh-shell">
				<TroubleshootPanel />
			</main>
		</Shell>
	);
}

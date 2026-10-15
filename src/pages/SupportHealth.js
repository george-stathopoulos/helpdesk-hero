/**
 * Site health: what looks wrong, and the full site details (copyable).
 */
import { __ } from '@wordpress/i18n';
import { useApi } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	Button,
	Loading,
	ErrorNotice,
	useToast,
} from '../ui/components/ui';
import { FlagList, CodeBlock } from '../ui/components/kit';

export default function Health() {
	const { data, error, reload } = useApi( 'admin/support/health' );
	const toast = useToast();
	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const copy = () =>
		navigator.clipboard
			.writeText( data.text )
			.then( () =>
				toast( __( 'Site details copied', 'helpdesk-hero' ) )
			)
			.catch( () =>
				toast( __( 'Couldn’t copy.', 'helpdesk-hero' ), 'alert' )
			);
	return (
		<>
			<PageHead
				title={ __( 'Site details', 'helpdesk-hero' ) }
				lede={ __(
					'What might be causing problems on this site, and everything about its setup. Passwords, keys and email addresses are removed.',
					'helpdesk-hero'
				) }
			>
				<Button icon="refresh" variant="ghost" onClick={ reload }>
					{ __( 'Check again', 'helpdesk-hero' ) }
				</Button>
			</PageHead>
			<Card
				title={ __( 'Things to look at', 'helpdesk-hero' ) }
				style={ { marginBottom: 18 } }
			>
				<FlagList flags={ data.flags } />
			</Card>
			<Card
				title={ __( 'Site details', 'helpdesk-hero' ) }
				action={
					<Button size="sm" icon="copy" onClick={ copy }>
						{ __( 'Copy', 'helpdesk-hero' ) }
					</Button>
				}
			>
				<CodeBlock text={ data.text } maxHeight={ 520 } />
			</Card>
		</>
	);
}

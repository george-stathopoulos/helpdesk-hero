import { __, sprintf } from '@wordpress/i18n';
import { useRoute, useTheme } from './ui/lib/hooks';
import { ToastProvider, ErrorBoundary } from './ui/components/ui';
import Icon from './ui/components/Icon';
import Connect from './pages/Connect';
import Tickets from './pages/Tickets';
import Ticket from './pages/Ticket';
import NewTicket from './pages/NewTicket';
import Access from './pages/Access';
import Activity from './pages/Activity';
import Settings from './pages/Settings';
import SupportSession from './pages/SupportSession';
import Billing from './pages/Billing';
import Pinpoint from './pages/Pinpoint';

const boot = window.hdhBoot || {};

const ROUTES = [
	{
		id: 'tickets',
		label: __( 'Tickets', 'helpdesk-hero' ),
		icon: 'inbox',
		Page: Tickets,
	},
	{
		id: 'new',
		label: __( 'New ticket', 'helpdesk-hero' ),
		icon: 'plus',
		Page: NewTicket,
	},
	// Hidden when the support team's policy turns Pinpoint off.
	...( boot.pinpoint && boot.pinpoint.mode === 'off'
		? []
		: [
				{
					id: 'pinpoint',
					label: __( 'Pinpoint', 'helpdesk-hero' ),
					icon: 'pin',
					Page: Pinpoint,
					// Until it's turned on, a badge points people at it.
					badge:
						boot.pinpoint && ! boot.pinpoint.enabled
							? __( 'New', 'helpdesk-hero' )
							: '',
				},
		  ] ),
	{
		id: 'access',
		label: __( 'Support access', 'helpdesk-hero' ),
		icon: 'key',
		Page: Access,
	},
	{
		id: 'activity',
		label: __( 'Activity', 'helpdesk-hero' ),
		icon: 'activity',
		Page: Activity,
	},
	...( boot.billing
		? [
				{
					id: 'billing',
					label: __( 'Billing', 'helpdesk-hero' ),
					icon: 'coins',
					Page: Billing,
				},
		  ]
		: [] ),
	{
		id: 'settings',
		label: __( 'Settings', 'helpdesk-hero' ),
		icon: 'settings',
		Page: Settings,
	},
];

const THEMES = [
	{
		id: 'system',
		icon: 'monitor',
		label: __( 'Theme: match system', 'helpdesk-hero' ),
	},
	{ id: 'light', icon: 'sun', label: __( 'Theme: light', 'helpdesk-hero' ) },
	{ id: 'dark', icon: 'moon', label: __( 'Theme: dark', 'helpdesk-hero' ) },
];

export function Shell( { nav, current, children } ) {
	const [ theme, setTheme ] = useTheme();
	const themeIndex = Math.max(
		0,
		THEMES.findIndex( ( t ) => t.id === theme )
	);
	const nextTheme = THEMES[ ( themeIndex + 1 ) % THEMES.length ];
	const branding = boot.branding || {};
	// White label: the support team's colour drives every accent, including the logo badge.
	const c = branding.color;
	const style = c
		? {
				'--hdh-accent': c,
				'--hdh-accent-hover': `color-mix(in srgb, ${ c } 85%, black)`,
				'--hdh-accent-soft': `color-mix(in srgb, ${ c } 9%, transparent)`,
				'--hdh-accent-line': `color-mix(in srgb, ${ c } 30%, transparent)`,
				'--hdh-brand-mark': c,
		  }
		: undefined;
	return (
		<div
			className="hdh-root"
			data-theme={ theme === 'system' ? undefined : theme }
			style={ style }
		>
			<ToastProvider>
				<header className="hdh-header">
					<div className="hdh-header__inner">
						<div className="hdh-brand">
							{ branding.logo ? (
								<img
									className="hdh-brand__logo"
									src={ branding.logo }
									alt={ boot.supportName }
								/>
							) : (
								<span className="hdh-brand__mark">
									<Icon name="ring" size={ 20 } />
								</span>
							) }
							<span>
								<div className="hdh-brand__name">
									{ boot.centerName ||
										__( 'Get Help', 'helpdesk-hero' ) }
								</div>
								<div className="hdh-brand__sub">
									{ boot.connected
										? sprintf(
												/* translators: %s: support team name */
												__(
													'from %s',
													'helpdesk-hero'
												),
												boot.supportName
										  )
										: __(
												'Not connected yet',
												'helpdesk-hero'
										  ) }
								</div>
							</span>
						</div>
						{ nav && (
							<nav
								className="hdh-nav"
								aria-label={ __(
									'Get Help sections',
									'helpdesk-hero'
								) }
							>
								{ nav.map( ( r ) => (
									<a
										key={ r.id }
										href={ `#/${ r.id }` }
										className={ `hdh-nav__item ${
											r.id === current ? 'is-active' : ''
										}` }
										aria-current={
											r.id === current
												? 'page'
												: undefined
										}
									>
										<Icon name={ r.icon } size={ 15 } />
										{ r.label }
										{ r.badge && (
											<span className="hdh-nav__badge">
												{ r.badge }
											</span>
										) }
									</a>
								) ) }
							</nav>
						) }
						<div className="hdh-header__end">
							{ branding.url && (
								<a
									className="hdh-btn is-ghost is-sm"
									href={ branding.url }
									target="_blank"
									rel="noopener noreferrer"
								>
									<Icon name="info" size={ 15 } />
									{ __( 'Help center', 'helpdesk-hero' ) }
								</a>
							) }
							<button
								type="button"
								className="hdh-btn is-ghost is-icon"
								onClick={ () => setTheme( nextTheme.id ) }
								title={ THEMES[ themeIndex ].label }
								aria-label={ `${
									THEMES[ themeIndex ].label
								}. ${ __(
									'Click to change.',
									'helpdesk-hero'
								) }` }
							>
								<Icon
									name={ THEMES[ themeIndex ].icon }
									size={ 17 }
								/>
							</button>
						</div>
					</div>
				</header>
				{ children }
				<footer className="hdh-footer">
					<span>
						{ branding.center
							? sprintf(
									/* translators: 1: help center name, 2: support team name */
									__( '%1$s by %2$s', 'helpdesk-hero' ),
									branding.center,
									boot.supportName
							  )
							: __( 'Helpdesk Hero', 'helpdesk-hero' ) }
					</span>
					<span className="hdh-footer__links">
						<span>
							{ sprintf(
								/* translators: %s: plugin version number. */
								__( 'Version %s', 'helpdesk-hero' ),
								boot.version
							) }
						</span>
					</span>
				</footer>
			</ToastProvider>
		</div>
	);
}

export default function App() {
	const [ route, go ] = useRoute( 'tickets' );
	if ( boot.isSupport ) {
		// Support accounts never use the customer's help center.
		return (
			<Shell>
				<main className="hdh-shell">
					<SupportSession />
				</main>
			</Shell>
		);
	}
	if ( ! boot.connected ) {
		return (
			<Shell>
				<main className="hdh-shell">
					<Connect />
				</main>
			</Shell>
		);
	}
	const [ section, param ] = route.split( '/' );
	const current =
		section === 'ticket'
			? { id: 'tickets', Page: Ticket }
			: ROUTES.find( ( r ) => r.id === section ) || ROUTES[ 0 ];
	const { Page } = current;
	return (
		<Shell nav={ ROUTES } current={ current.id }>
			<main className="hdh-shell" key={ route }>
				<ErrorBoundary resetKey={ route }>
					<Page go={ go } param={ param } />
				</ErrorBoundary>
			</main>
		</Shell>
	);
}

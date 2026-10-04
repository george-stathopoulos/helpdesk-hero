/* Generated from packages/ui by bin/sync-ui.mjs. Edit the package, not this copy. */
/**
 * A short looping animation of Pinpoint: click "Report a problem", drag a box around the
 * problem, continue, and support sees the same spot highlighted. Drawn (SVG and CSS), so it is
 * sharp, light, themed and translatable; with "reduce motion" it shows the key frame, still.
 */
import { __ } from '@wordpress/i18n';

export default function PinpointDemo() {
	const captions = [
		__( '1. Click “Report a problem” in the toolbar', 'helpdesk-hero' ),
		__( '2. Click the problem, or drag a box around it', 'helpdesk-hero' ),
		__( '3. Add a note and continue to the ticket', 'helpdesk-hero' ),
		__( '4. Support opens the page with that spot highlighted', 'helpdesk-hero' ),
	];
	return (
		<figure className="hdh-ppdemo" aria-label={ __( 'How Pinpoint works', 'helpdesk-hero' ) }>
			<svg viewBox="0 0 480 280" role="img" aria-hidden="true" focusable="false">
				{ /* Browser window */ }
				<rect className="d-window" x="8" y="8" width="464" height="264" rx="12" />
				<rect className="d-toolbar" x="8" y="8" width="464" height="26" rx="12" />
				<rect className="d-toolbar" x="8" y="22" width="464" height="12" />
				<circle className="d-dot" cx="24" cy="21" r="4" />
				<circle className="d-dot" cx="38" cy="21" r="4" />
				<circle className="d-dot" cx="52" cy="21" r="4" />
				{ /* "Report a problem" button in the toolbar */ }
				<g className="d-report">
					<rect x="352" y="13" width="108" height="16" rx="8" />
					<text x="406" y="25" textAnchor="middle">
						{ __( 'Report a problem', 'helpdesk-hero' ) }
					</text>
				</g>
				{ /* The page */ }
				<rect className="d-block" x="32" y="52" width="200" height="14" rx="4" />
				<rect className="d-line" x="32" y="76" width="300" height="8" rx="4" />
				<rect className="d-line" x="32" y="90" width="260" height="8" rx="4" />
				<rect className="d-card" x="32" y="114" width="196" height="120" rx="10" />
				<rect className="d-card" x="244" y="114" width="196" height="120" rx="10" />
				<rect className="d-line" x="48" y="132" width="120" height="8" rx="4" />
				<rect className="d-line" x="260" y="132" width="120" height="8" rx="4" />
				<rect className="d-price" x="260" y="160" width="70" height="18" rx="4" />
				<rect className="d-line" x="48" y="160" width="150" height="8" rx="4" />
				<rect className="d-line" x="48" y="174" width="130" height="8" rx="4" />
				<rect className="d-btn" x="260" y="198" width="90" height="22" rx="6" />
				<rect className="d-btn" x="48" y="198" width="90" height="22" rx="6" />
				{ /* Picking: page dims, a box is dragged around the price */ }
				<rect className="d-dim" x="8" y="34" width="464" height="238" />
				<rect className="d-drag" x="252" y="152" width="0" height="0" rx="4" />
				<g className="d-num">
					<circle cx="252" cy="152" r="9" />
					<text x="252" y="156" textAnchor="middle">
						1
					</text>
				</g>
				{ /* The panel */ }
				<g className="d-panel">
					<rect x="300" y="196" width="160" height="66" rx="10" />
					<rect className="d-line" x="312" y="208" width="90" height="7" rx="3" />
					<rect className="d-input" x="312" y="222" width="136" height="14" rx="4" />
					<rect className="d-go" x="312" y="242" width="60" height="13" rx="4" />
				</g>
				{ /* Support's side: the same spot, highlighted */ }
				<g className="d-support">
					<rect className="d-shade" x="8" y="34" width="464" height="238" />
					<rect className="d-hl" x="252" y="152" width="90" height="34" rx="4" />
					<g className="d-label">
						<rect x="252" y="128" width="110" height="18" rx="6" />
						<text x="307" y="141" textAnchor="middle">
							{ __( 'The reported spot', 'helpdesk-hero' ) }
						</text>
					</g>
				</g>
				{ /* Pointer */ }
				<path className="d-cursor" d="M0 0 L0 16 L4.5 12 L8 19 L10.5 18 L7 11 L13 11 Z" />
			</svg>
			<figcaption>
				{ captions.map( ( c, i ) => (
					<span key={ i } className={ `d-cap d-cap-${ i + 1 }` }>
						{ c }
					</span>
				) ) }
			</figcaption>
		</figure>
	);
}

import { createRoot } from '@wordpress/element';
import App from './App';
import Troubleshoot from './pages/Troubleshoot';
import './ui/style.scss';

function mount() {
	const root = document.getElementById( 'hdh-root' );
	if ( ! root ) {
		return;
	}
	root.classList.remove( 'hdh-root' );
	const boot = window.hdhBoot || {};
	createRoot( root ).render(
		boot.view === 'troubleshoot' ? <Troubleshoot standalone /> : <App />
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}

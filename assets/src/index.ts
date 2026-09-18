import type { EditTraceConfig } from './types';
import { Inspector } from './inspector/inspector';
import styles from './styles/inspector.css';

declare global {
	interface Window {
		EditTraceConfig?: EditTraceConfig;
		EditTrace?: Inspector;
	}
}

function boot(): void {
	const config = window.EditTraceConfig;
	if ( ! config || ! config.restUrl || ! config.traceId ) {
		return;
	}
	const inspector = new Inspector( config, styles );
	window.EditTrace = inspector;

	const adminBarLink = document.querySelector< HTMLAnchorElement >( '#wp-admin-bar-edittrace > a' );
	if ( adminBarLink ) {
		adminBarLink.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			inspector.toggle();
		} );
		document.addEventListener( 'edittrace:activate', () => adminBarLink.parentElement?.classList.add( 'edittrace-admin-bar--active' ) );
		document.addEventListener( 'edittrace:deactivate', () => adminBarLink.parentElement?.classList.remove( 'edittrace-admin-bar--active' ) );
	}

	if ( window.location.hash === '#edittrace' ) {
		inspector.activate();
	}

	// The admin bar collapses on narrow screens and may push our node out of
	// view; offer a floating launcher when the toolbar button is not usable.
	const ensureLauncher = (): void => {
		if ( inspector.isActive() ) {
			return;
		}
		const rect = adminBarLink?.getBoundingClientRect();
		const usable = !! rect && rect.width > 0 && rect.height > 0 && rect.right <= window.innerWidth && rect.left >= 0;
		if ( ! usable ) {
			inspector.showLauncher();
		}
	};
	ensureLauncher();
	window.addEventListener( 'resize', ensureLauncher );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}

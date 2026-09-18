import type { EditTraceConfig, SourceResult } from '../types';
import { ApiClient } from '../api/client';
import { Highlighter } from './highlighter';
import { Panel } from '../panel/panel';
import { extractElementContext } from './context';
import { describe, meaningfulChild, meaningfulParent, resolveTarget } from './selection';

/**
 * Inspector Mode: hover highlighting, click-to-select, keyboard navigation,
 * and orchestration of REST requests + panel state. Contains no knowledge
 * of Gutenberg/Elementor/ACF; providers describe themselves via markers.
 */
export class Inspector {
	private active = false;
	private readonly host: HTMLElement;
	private readonly shadow: ShadowRoot;
	private readonly highlighter: Highlighter;
	private readonly panel: Panel;
	private readonly toolbar: HTMLElement;
	private readonly api: ApiClient;
	private readonly globalStyle: HTMLStyleElement;
	private lastPoint: { x: number; y: number } | null = null;
	private raf = 0;
	private pendingHover: Element | null = null;
	private controller: AbortController | null = null;
	private requestId = 0;

	constructor( private readonly config: EditTraceConfig, styles: string ) {
		this.api = new ApiClient( config );
		this.host = document.createElement( 'div' );
		this.host.id = 'edittrace-root';
		this.host.setAttribute( 'data-edittrace-ui', '' );
		this.shadow = this.host.attachShadow( { mode: 'open' } );
		const style = document.createElement( 'style' );
		style.textContent = styles;
		this.shadow.appendChild( style );

		this.highlighter = new Highlighter( this.shadow );
		this.toolbar = this.buildToolbar();
		this.shadow.appendChild( this.toolbar );
		this.panel = new Panel( this.shadow, config, {
			onClose: () => this.closePanel(),
			onInspectParent: () => this.selectParent(),
			onSearch: () => this.runSearch(),
		} );

		this.globalStyle = document.createElement( 'style' );
		this.globalStyle.id = 'edittrace-global-style';
		this.globalStyle.textContent = 'html.edittrace-inspecting, html.edittrace-inspecting body, html.edittrace-inspecting body *:not([data-edittrace-ui]) { cursor: crosshair !important; }';

		this.onMouseMove = this.onMouseMove.bind( this );
		this.onClick = this.onClick.bind( this );
		this.onKeyDown = this.onKeyDown.bind( this );
		this.onScroll = this.onScroll.bind( this );
	}

	isActive(): boolean {
		return this.active;
	}

	toggle(): void {
		if ( this.active ) {
			this.deactivate();
		} else {
			this.activate();
		}
	}

	activate(): void {
		if ( this.active ) {
			return;
		}
		this.active = true;
		if ( ! this.host.isConnected ) {
			document.body.appendChild( this.host );
		}
		document.head.appendChild( this.globalStyle );
		document.documentElement.classList.add( 'edittrace-inspecting' );
		this.host.classList.add( 'edittrace-root--active' );
		this.toolbar.hidden = false;
		document.addEventListener( 'mousemove', this.onMouseMove, true );
		document.addEventListener( 'click', this.onClick, true );
		document.addEventListener( 'keydown', this.onKeyDown, true );
		window.addEventListener( 'scroll', this.onScroll, true );
		window.addEventListener( 'resize', this.onScroll );
		document.dispatchEvent( new CustomEvent( 'edittrace:activate' ) );
	}

	deactivate(): void {
		if ( ! this.active ) {
			return;
		}
		this.active = false;
		this.cancelRequest();
		document.documentElement.classList.remove( 'edittrace-inspecting' );
		this.globalStyle.remove();
		this.host.classList.remove( 'edittrace-root--active' );
		this.toolbar.hidden = true;
		this.highlighter.clear();
		this.panel.close();
		document.removeEventListener( 'mousemove', this.onMouseMove, true );
		document.removeEventListener( 'click', this.onClick, true );
		document.removeEventListener( 'keydown', this.onKeyDown, true );
		window.removeEventListener( 'scroll', this.onScroll, true );
		window.removeEventListener( 'resize', this.onScroll );
		document.dispatchEvent( new CustomEvent( 'edittrace:deactivate' ) );
	}

	getSelected(): Element | null {
		return this.highlighter.getSelected();
	}

	/**
	 * Selects and traces an element programmatically.
	 */
	async select( el: Element ): Promise< SourceResult | null > {
		this.highlighter.setSelected( el, describe( el, this.config.markers ) );
		this.highlighter.setHover( null );
		return this.inspect( el );
	}

	private isOwnNode( target: EventTarget | null ): boolean {
		if ( ! ( target instanceof Node ) ) {
			return false;
		}
		const path = ( target as Node ).getRootNode();
		return path === this.shadow || this.host.contains( target as Node ) || !! ( target as Element ).closest?.( '#wpadminbar' );
	}

	private onMouseMove( e: MouseEvent ): void {
		if ( this.isOwnNode( e.target ) ) {
			this.pendingHover = null;
			this.scheduleHover();
			return;
		}
		this.lastPoint = { x: e.clientX, y: e.clientY };
		const target = e.target instanceof Element ? resolveTarget( e.target, this.config.markers ) : null;
		this.pendingHover = target;
		this.scheduleHover();
	}

	private scheduleHover(): void {
		if ( this.raf ) {
			return;
		}
		this.raf = window.requestAnimationFrame( () => {
			this.raf = 0;
			const el = this.pendingHover;
			if ( el && el !== this.highlighter.getSelected() ) {
				this.highlighter.setHover( el, describe( el, this.config.markers ) );
			} else {
				this.highlighter.setHover( null );
			}
		} );
	}

	private onClick( e: MouseEvent ): void {
		if ( this.isOwnNode( e.target ) ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();
		if ( ! ( e.target instanceof Element ) ) {
			return;
		}
		this.lastPoint = { x: e.clientX, y: e.clientY };
		const target = resolveTarget( e.target, this.config.markers );
		void this.select( target );
	}

	private onKeyDown( e: KeyboardEvent ): void {
		if ( ! this.active ) {
			return;
		}
		const targetEl = e.target as Element | null;
		const typing = targetEl && ! this.isOwnNode( targetEl ) && /^(input|textarea|select)$/i.test( targetEl.tagName );
		if ( typing && e.key !== 'Escape' ) {
			return;
		}
		switch ( e.key ) {
			case 'Escape':
				e.preventDefault();
				e.stopPropagation();
				if ( this.panel.isOpen() ) {
					this.closePanel();
				} else {
					this.deactivate();
				}
				break;
			case 'ArrowUp':
				e.preventDefault();
				e.stopPropagation();
				this.selectParent();
				break;
			case 'ArrowDown':
				e.preventDefault();
				e.stopPropagation();
				this.selectChild();
				break;
			case 'Enter': {
				const current = this.highlighter.getSelected() || this.highlighter.getHover();
				if ( current ) {
					e.preventDefault();
					e.stopPropagation();
					void this.select( current );
				}
				break;
			}
			default:
				break;
		}
	}

	private onScroll(): void {
		this.highlighter.refresh();
	}

	private current(): Element | null {
		return this.highlighter.getSelected() || this.highlighter.getHover();
	}

	selectParent(): void {
		const current = this.current();
		if ( ! current ) {
			return;
		}
		const parent = meaningfulParent( current, this.config.markers );
		if ( parent ) {
			void this.select( parent );
		}
	}

	selectChild(): void {
		const current = this.current();
		if ( ! current ) {
			return;
		}
		const child = meaningfulChild( current, this.config.markers, this.lastPoint || undefined );
		if ( child ) {
			void this.select( child );
		}
	}

	private closePanel(): void {
		this.cancelRequest();
		this.panel.close();
		this.highlighter.setSelected( null );
	}

	private cancelRequest(): void {
		if ( this.controller ) {
			this.controller.abort();
			this.controller = null;
		}
	}

	private async inspect( el: Element ): Promise< SourceResult | null > {
		this.cancelRequest();
		const id = ++this.requestId;
		const context = extractElementContext( el, this.config.markers, this.config.traceId );
		const summary = context.text || context.alt || context.href || context.src || `<${ context.tag }>`;
		this.panel.setState( { status: 'loading', summary } );
		this.controller = new AbortController();
		try {
			const result = await this.api.inspect( context, this.controller.signal );
			if ( id !== this.requestId ) {
				return null;
			}
			this.panel.setState( { status: 'result', result, searching: false } );
			document.dispatchEvent( new CustomEvent( 'edittrace:result', { detail: result } ) );
			if ( result.status === 'unknown' && this.config.fallbackSearch ) {
				void this.runSearch();
			}
			return result;
		} catch ( err ) {
			if ( ( err as Error ).name === 'AbortError' || id !== this.requestId ) {
				return null;
			}
			this.panel.setState( { status: 'error', message: ( err as Error ).message || this.config.i18n.error || 'Request failed', summary } );
			return null;
		}
	}

	async runSearch(): Promise< void > {
		const state = this.panel.getState();
		const selected = this.highlighter.getSelected();
		if ( state.status !== 'result' || ! selected ) {
			return;
		}
		const id = this.requestId;
		this.panel.setState( { ...state, searching: true, searchError: undefined } );
		const context = extractElementContext( selected, this.config.markers, this.config.traceId );
		this.controller = new AbortController();
		try {
			const found = await this.api.search( context, this.controller.signal );
			if ( id !== this.requestId ) {
				return;
			}
			const base = state.result;
			const merged: SourceResult = {
				...base,
				status: found.candidates.length ? 'candidates' : 'unknown',
				primary: found.primary,
				candidates: found.candidates,
				weak: [ ...( base.weak || [] ), ...( found.weak || [] ) ],
				searched: true,
				notes: [ ...( base.notes || [] ), ...( found.notes || [] ) ],
			};
			this.panel.setState( { status: 'result', result: merged, searching: false } );
			document.dispatchEvent( new CustomEvent( 'edittrace:result', { detail: merged } ) );
		} catch ( err ) {
			if ( ( err as Error ).name === 'AbortError' || id !== this.requestId ) {
				return;
			}
			this.panel.setState( { ...state, searching: false, searchError: ( err as Error ).message } );
		}
	}

	private buildToolbar(): HTMLElement {
		const bar = document.createElement( 'div' );
		bar.className = 'edittrace-toolbar';
		bar.setAttribute( 'role', 'toolbar' );
		bar.setAttribute( 'aria-label', 'EditTrace inspector' );
		bar.hidden = true;
		const t = this.config.i18n;
		bar.innerHTML = `
			<span class="edittrace-toolbar__status"><span class="edittrace-toolbar__dot"></span>${ t.active || 'EditTrace Active' }</span>
			<button type="button" class="edittrace-toolbar__btn" data-edittrace-tool="parent" title="Select parent (↑)"><span aria-hidden="true">↑</span> ${ t.parent || 'Parent' }</button>
			<button type="button" class="edittrace-toolbar__btn" data-edittrace-tool="child" title="Select child (↓)"><span aria-hidden="true">↓</span> ${ t.child || 'Child' }</button>
			<button type="button" class="edittrace-toolbar__btn edittrace-toolbar__btn--exit" data-edittrace-tool="exit" title="Exit (Esc)">${ t.exit || 'Exit' }</button>`;
		bar.addEventListener( 'click', ( e ) => {
			const btn = ( e.target as Element ).closest( '[data-edittrace-tool]' );
			if ( ! btn ) {
				return;
			}
			const tool = btn.getAttribute( 'data-edittrace-tool' );
			if ( tool === 'parent' ) {
				this.selectParent();
			} else if ( tool === 'child' ) {
				this.selectChild();
			} else if ( tool === 'exit' ) {
				this.deactivate();
			}
		} );
		return bar;
	}
}

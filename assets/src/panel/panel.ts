import type { EditTraceConfig, SourceCandidate, SourceResult } from '../types';
import { attr, esc, isSafeUrl, truncate } from './render';

export type PanelState =
	| { status: 'idle' }
	| { status: 'loading'; summary: string }
	| { status: 'result'; result: SourceResult; searching: boolean; searchError?: string }
	| { status: 'error'; message: string; summary: string };

export interface PanelCallbacks {
	onClose(): void;
	onInspectParent(): void;
	onSearch(): void;
}

/**
 * Right-side result panel. Pure state → HTML rendering; all interaction is
 * delegated through callbacks so the panel stays independent of the DOM
 * inspector.
 */
export class Panel {
	readonly element: HTMLElement;
	private state: PanelState = { status: 'idle' };

	constructor( root: ParentNode, private readonly config: EditTraceConfig, private readonly callbacks: PanelCallbacks ) {
		this.element = document.createElement( 'aside' );
		this.element.className = 'edittrace-panel';
		this.element.setAttribute( 'role', 'complementary' );
		this.element.setAttribute( 'aria-label', 'EditTrace' );
		this.element.hidden = true;
		root.appendChild( this.element );
		this.element.addEventListener( 'click', ( e ) => this.handleClick( e ) );
	}

	getState(): PanelState {
		return this.state;
	}

	setState( state: PanelState ): void {
		this.state = state;
		this.element.hidden = state.status === 'idle';
		this.element.innerHTML = this.render( state );
		if ( state.status !== 'idle' ) {
			this.element.scrollTop = 0;
		}
	}

	isOpen(): boolean {
		return this.state.status !== 'idle';
	}

	close(): void {
		this.setState( { status: 'idle' } );
	}

	private handleClick( e: Event ): void {
		const target = ( e.target as Element ).closest( '[data-edittrace-action]' );
		if ( ! target ) {
			return;
		}
		const action = target.getAttribute( 'data-edittrace-action' );
		if ( action === 'close' ) {
			e.preventDefault();
			this.callbacks.onClose();
		} else if ( action === 'parent' ) {
			e.preventDefault();
			this.callbacks.onInspectParent();
		} else if ( action === 'search' ) {
			e.preventDefault();
			this.callbacks.onSearch();
		}
	}

	render( state: PanelState ): string {
		const header = `
			<header class="edittrace-panel__header">
				<span class="edittrace-panel__brand"><span class="edittrace-panel__dot"></span>EditTrace</span>
				<button type="button" class="edittrace-iconbtn" data-edittrace-action="close" aria-label="Close panel" title="Close (Esc)">×</button>
			</header>`;

		switch ( state.status ) {
			case 'idle':
				return '';
			case 'loading':
				return `${ header }
					<div class="edittrace-panel__body">
						${ this.selectedBlock( state.summary ) }
						<div class="edittrace-loading"><span class="edittrace-spinner"></span>${ esc( this.config.i18n.loading || 'Tracing source…' ) }</div>
					</div>`;
			case 'error':
				return `${ header }
					<div class="edittrace-panel__body">
						${ this.selectedBlock( state.summary ) }
						<div class="edittrace-alert edittrace-alert--error">${ esc( state.message ) }</div>
					</div>`;
			case 'result':
				return `${ header }<div class="edittrace-panel__body">${ this.renderResult( state.result, state.searching, state.searchError ) }</div>`;
		}
	}

	private selectedBlock( summary: string, tag = '' ): string {
		return `
			<section class="edittrace-section edittrace-section--selected">
				<div class="edittrace-label">Selected element</div>
				<div class="edittrace-selected">
					${ tag ? `<span class="edittrace-tag">${ esc( tag ) }</span>` : '' }
					<span class="edittrace-selected__text">${ esc( truncate( summary, 140 ) ) }</span>
				</div>
			</section>`;
	}

	private renderResult( result: SourceResult, searching: boolean, searchError?: string ): string {
		const parts: string[] = [];
		parts.push( this.selectedBlock( result.selected.summary, result.selected.tag ) );

		if ( result.status === 'exact' && result.primary ) {
			parts.push( this.renderPrimary( result.primary ) );
			const others = result.candidates.slice( 1 );
			if ( others.length ) {
				parts.push( this.renderOthers( others, 'Also involved' ) );
			}
		} else if ( result.status === 'candidates' ) {
			parts.push( `
				<section class="edittrace-section">
					<div class="edittrace-headline edittrace-headline--warn">Exact source not found</div>
					<div class="edittrace-muted">${ result.candidates.length } possible source${ result.candidates.length === 1 ? '' : 's' }${ result.searched ? ' from WordPress search' : '' }</div>
				</section>` );
			parts.push( this.renderCandidateList( result.candidates ) );
		} else {
			parts.push( this.renderUnknown( result, searching, searchError ) );
		}

		if ( searching && result.status !== 'unknown' ) {
			parts.push( `<div class="edittrace-loading"><span class="edittrace-spinner"></span>Searching WordPress…</div>` );
		}

		parts.push( this.renderTechnical( result ) );
		return parts.join( '' );
	}

	private renderPrimary( c: SourceCandidate ): string {
		const rows: string[] = [];
		rows.push( `<div class="edittrace-system">${ esc( c.system || c.provider ) }</div>` );
		if ( c.sourceName ) {
			rows.push( this.kv( c.sourceLabel || 'Source', c.sourceName ) );
		}
		if ( c.itemName ) {
			rows.push( this.kv( c.itemLabel || 'Item', c.itemName, c.itemKey && c.itemKey !== c.itemName ? c.itemKey : '' ) );
		}
		for ( const [ label, value ] of Object.entries( c.details || {} ) ) {
			rows.push( this.kv( label, value ) );
		}

		let html = `
			<section class="edittrace-section">
				<div class="edittrace-label">Source</div>
				${ rows.join( '' ) }
			</section>`;

		if ( c.hierarchy && c.hierarchy.length > 1 ) {
			html += `
				<section class="edittrace-section">
					<div class="edittrace-label">Location</div>
					<ol class="edittrace-crumbs">${ c.hierarchy.map( ( h, i ) => `<li class="edittrace-crumb${ i === c.hierarchy.length - 1 ? ' edittrace-crumb--current' : '' }">${ esc( h ) }</li>` ).join( '' ) }</ol>
				</section>`;
		}

		if ( c.global ) {
			html += `
				<section class="edittrace-section">
					<div class="edittrace-global"><div class="edittrace-global__title">Global content</div><div>${ esc( c.globalNote || 'Changes here may affect multiple pages.' ) }</div></div>
				</section>`;
		}

		if ( c.usage && Object.keys( c.usage ).length ) {
			html += `
				<section class="edittrace-section">
					<div class="edittrace-label">Usage</div>
					${ Object.entries( c.usage ).map( ( [ k, v ] ) => this.kv( k, String( v ) ) ).join( '' ) }
				</section>`;
		}

		html += this.renderActions( c );
		html += `
			<section class="edittrace-section">
				<div class="edittrace-label">Confidence</div>
				${ this.confidenceBadge( c ) }
				${ c.reason ? `<div class="edittrace-muted edittrace-reason">${ esc( c.reason ) }</div>` : '' }
			</section>`;
		return html;
	}

	private renderActions( c: SourceCandidate ): string {
		const buttons: string[] = [];
		if ( isSafeUrl( c.editUrl ) ) {
			buttons.push( `<a class="edittrace-btn edittrace-btn--primary" href="${ attr( c.editUrl ) }" target="_blank" rel="noopener">${ esc( c.editLabel || 'Edit' ) }</a>` );
		}
		for ( const action of c.actions || [] ) {
			if ( isSafeUrl( action.url ) ) {
				buttons.push( `<a class="edittrace-btn" href="${ attr( action.url ) }" target="_blank" rel="noopener">${ esc( action.label ) }</a>` );
			}
		}
		if ( ! buttons.length ) {
			buttons.push( `<div class="edittrace-muted">No direct edit link is available for this source.</div>` );
		}
		return `
			<section class="edittrace-section">
				<div class="edittrace-label">Actions</div>
				<div class="edittrace-actions">${ buttons.join( '' ) }</div>
			</section>`;
	}

	private renderOthers( list: SourceCandidate[], title: string ): string {
		return `
			<section class="edittrace-section">
				<div class="edittrace-label">${ esc( title ) }</div>
				${ this.renderCandidateList( list ) }
			</section>`;
	}

	private renderCandidateList( list: SourceCandidate[] ): string {
		const shown = list.slice( 0, 3 );
		const rest = list.slice( 3 );
		const item = ( c: SourceCandidate ): string => `
			<li class="edittrace-candidate">
				<div class="edittrace-candidate__head">
					<span class="edittrace-candidate__name">${ esc( c.sourceName || c.itemName || c.system ) }</span>
					${ this.confidenceBadge( c ) }
				</div>
				<div class="edittrace-candidate__meta">${ esc( [ c.system, c.sourceLabel, c.itemLabel && c.itemName ? `${ c.itemLabel }: ${ c.itemName }` : c.itemName ].filter( Boolean ).join( ' · ' ) ) }</div>
				${ c.global ? `<div class="edittrace-candidate__global">Global content</div>` : '' }
				${ c.reason ? `<div class="edittrace-candidate__reason">${ esc( c.reason ) }</div>` : '' }
				<div class="edittrace-candidate__actions">
					${ isSafeUrl( c.editUrl ) ? `<a class="edittrace-btn edittrace-btn--small" href="${ attr( c.editUrl ) }" target="_blank" rel="noopener">${ esc( c.editLabel || 'Edit' ) }</a>` : '' }
					${ ( c.actions || [] ).filter( ( a ) => isSafeUrl( a.url ) ).map( ( a ) => `<a class="edittrace-btn edittrace-btn--small edittrace-btn--ghost" href="${ attr( a.url ) }" target="_blank" rel="noopener">${ esc( a.label ) }</a>` ).join( '' ) }
				</div>
			</li>`;
		let html = `<ol class="edittrace-candidates">${ shown.map( item ).join( '' ) }</ol>`;
		if ( rest.length ) {
			html += `<details class="edittrace-details"><summary>${ rest.length } more</summary><ol class="edittrace-candidates" start="4">${ rest.map( item ).join( '' ) }</ol></details>`;
		}
		return html;
	}

	private renderUnknown( result: SourceResult, searching: boolean, searchError?: string ): string {
		const canSearch = this.config.fallbackSearch && ! result.searched;
		return `
			<section class="edittrace-section">
				<div class="edittrace-headline edittrace-headline--warn">Source not identified</div>
				<p class="edittrace-muted">EditTrace could not determine the exact source of this element${ result.searched ? ', and a WordPress search found nothing usable' : '' }.</p>
				${ searching ? `<div class="edittrace-loading"><span class="edittrace-spinner"></span>Searching WordPress…</div>` : '' }
				${ searchError ? `<div class="edittrace-alert edittrace-alert--error">${ esc( searchError ) }</div>` : '' }
				<div class="edittrace-label">Try</div>
				<div class="edittrace-actions">
					<button type="button" class="edittrace-btn" data-edittrace-action="parent">Inspect parent</button>
					${ canSearch && ! searching ? `<button type="button" class="edittrace-btn" data-edittrace-action="search">${ esc( this.config.i18n.search || 'Search WordPress' ) }</button>` : '' }
					${ isSafeUrl( result.page.editUrl ) ? `<a class="edittrace-btn edittrace-btn--ghost" href="${ attr( result.page.editUrl ) }" target="_blank" rel="noopener">Open current page in editor</a>` : '' }
				</div>
			</section>`;
	}

	private renderTechnical( result: SourceResult ): string {
		const rows: string[] = [];
		rows.push( this.kv( 'Current page', result.page.title || '—' ) );
		if ( result.page.template ) {
			rows.push( this.kv( 'Template', result.page.template ) );
		}
		if ( result.page.postType ) {
			rows.push( this.kv( 'Queried object', `${ result.page.postType } #${ result.page.objectId }` ) );
		}
		rows.push( this.kv( 'Page trace', result.trace.available ? `available (${ result.trace.entries } entries)` : 'not available' ) );
		if ( result.debug && result.trace.id ) {
			rows.push( this.kv( 'Trace id', result.trace.id ) );
		}
		const all = [ ...result.candidates, ...( result.weak || [] ) ];
		for ( const c of all ) {
			const tech = Object.entries( c.technical || {} )
				.filter( ( [ k ] ) => result.debug || k !== 'providerPriority' )
				.map( ( [ k, v ] ) => `${ k }: ${ typeof v === 'object' ? JSON.stringify( v ) : String( v ) }` )
				.join( ', ' );
			rows.push( this.kv( `${ c.provider } (${ c.confidence.toFixed( 2 ) })`, `${ c.sourceType }${ c.sourceId ? ' ' + c.sourceId : '' }${ tech ? ' — ' + tech : '' }` ) );
		}
		if ( ! all.length ) {
			rows.push( this.kv( 'Providers', 'no candidates' ) );
		}
		for ( const note of result.notes || [] ) {
			rows.push( `<div class="edittrace-note">${ esc( note ) }</div>` );
		}
		if ( result.selected.href ) {
			rows.push( this.kv( 'href', result.selected.href ) );
		}
		if ( result.selected.src ) {
			rows.push( this.kv( 'src', result.selected.src ) );
		}
		return `
			<details class="edittrace-details edittrace-technical">
				<summary>Technical details</summary>
				<div class="edittrace-technical__body">${ rows.join( '' ) }</div>
			</details>`;
	}

	private confidenceBadge( c: SourceCandidate ): string {
		return `<span class="edittrace-confidence edittrace-confidence--${ attr( c.status ) }" title="Score ${ c.confidence.toFixed( 2 ) }">${ esc( c.statusLabel ) }</span>`;
	}

	private kv( label: string, value: string, sub = '' ): string {
		return `<div class="edittrace-kv"><span class="edittrace-kv__k">${ esc( label ) }</span><span class="edittrace-kv__v">${ esc( value ) }${ sub ? `<span class="edittrace-kv__sub">${ esc( sub ) }</span>` : '' }</span></div>`;
	}
}

/**
 * Overlay boxes drawn over the hovered/selected element. Uses position:fixed
 * elements inside the shadow root so the host page never reflows.
 */
export class Highlighter {
	private readonly hover: HTMLElement;
	private readonly selected: HTMLElement;
	private readonly hoverBadge: HTMLElement;
	private readonly selectedBadge: HTMLElement;
	private hoverTarget: Element | null = null;
	private selectedTarget: Element | null = null;

	constructor( root: ParentNode ) {
		this.hover = document.createElement( 'div' );
		this.hover.className = 'edittrace-highlight edittrace-highlight--hover';
		this.hover.hidden = true;
		this.hoverBadge = document.createElement( 'span' );
		this.hoverBadge.className = 'edittrace-badge';
		this.hover.appendChild( this.hoverBadge );

		this.selected = document.createElement( 'div' );
		this.selected.className = 'edittrace-highlight edittrace-highlight--selected';
		this.selected.hidden = true;
		this.selectedBadge = document.createElement( 'span' );
		this.selectedBadge.className = 'edittrace-badge';
		this.selected.appendChild( this.selectedBadge );

		root.appendChild( this.hover );
		root.appendChild( this.selected );
	}

	setHover( el: Element | null, label = '' ): void {
		this.hoverTarget = el;
		this.hoverBadge.textContent = label;
		this.draw( this.hover, el );
	}

	setSelected( el: Element | null, label = '' ): void {
		this.selectedTarget = el;
		this.selectedBadge.textContent = label;
		this.draw( this.selected, el );
	}

	getSelected(): Element | null {
		return this.selectedTarget;
	}

	getHover(): Element | null {
		return this.hoverTarget;
	}

	refresh(): void {
		this.draw( this.hover, this.hoverTarget );
		this.draw( this.selected, this.selectedTarget );
	}

	clear(): void {
		this.setHover( null );
		this.setSelected( null );
	}

	private draw( box: HTMLElement, el: Element | null ): void {
		if ( ! el || ! el.isConnected ) {
			box.hidden = true;
			return;
		}
		const r = el.getBoundingClientRect();
		box.hidden = false;
		box.style.left = `${ r.left }px`;
		box.style.top = `${ r.top }px`;
		box.style.width = `${ Math.max( r.width, 0 ) }px`;
		box.style.height = `${ Math.max( r.height, 0 ) }px`;
		box.classList.toggle( 'edittrace-highlight--badge-below', r.top < 28 );
	}
}

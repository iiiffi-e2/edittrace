import type { DomMarker } from '../types';

/**
 * Source-agnostic DOM selection helpers: which element is worth selecting,
 * how to describe it, and how to move to a meaningful parent/child.
 * Markers (provided by server-side providers) are the only knowledge this
 * module has about content systems.
 */

const INLINE_TAGS = new Set( [
	'span', 'strong', 'em', 'b', 'i', 'u', 'small', 'mark', 'sub', 'sup', 'code', 'abbr', 'time', 's', 'del', 'ins', 'br', 'wbr', 'font', 'bdi', 'bdo', 'kbd', 'samp', 'var', 'cite', 'q', 'dfn',
] );

const TEXT_CONTAINERS = new Set( [
	'a', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'label', 'td', 'th', 'blockquote', 'figcaption', 'dt', 'dd', 'summary', 'legend', 'pre',
] );

const SEMANTIC_TAGS = new Set( [
	'a', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'ul', 'ol', 'img', 'picture', 'video', 'audio', 'figure', 'nav', 'header', 'footer', 'main', 'section', 'article', 'aside', 'form', 'table', 'blockquote', 'iframe', 'svg', 'input', 'select', 'textarea', 'label', 'details', 'summary',
] );

const SKIP_TAGS = new Set( [ 'script', 'style', 'template', 'noscript', 'link', 'meta' ] );

const TAG_LABELS: Record< string, string > = {
	h1: 'Heading', h2: 'Heading', h3: 'Heading', h4: 'Heading', h5: 'Heading', h6: 'Heading',
	p: 'Paragraph', a: 'Link', button: 'Button', img: 'Image', picture: 'Image', svg: 'Icon', video: 'Video', audio: 'Audio',
	ul: 'List', ol: 'List', li: 'List item', nav: 'Navigation', header: 'Header', footer: 'Footer', main: 'Main', section: 'Section',
	article: 'Article', aside: 'Sidebar', form: 'Form', table: 'Table', tr: 'Table row', td: 'Table cell', th: 'Table cell',
	blockquote: 'Quote', figure: 'Figure', figcaption: 'Caption', iframe: 'Embed', input: 'Field', select: 'Field', textarea: 'Field',
	label: 'Label', span: 'Text', strong: 'Text', em: 'Text', div: 'Container', details: 'Details', summary: 'Summary', time: 'Text',
};

export function isInlineTag( el: Element ): boolean {
	return INLINE_TAGS.has( el.tagName.toLowerCase() );
}

export function isSemantic( el: Element ): boolean {
	return SEMANTIC_TAGS.has( el.tagName.toLowerCase() );
}

export function isSkippable( el: Element ): boolean {
	return SKIP_TAGS.has( el.tagName.toLowerCase() );
}

export function looksLikeButton( el: Element ): boolean {
	const tag = el.tagName.toLowerCase();
	if ( tag === 'button' ) {
		return true;
	}
	if ( tag === 'input' ) {
		const type = ( el.getAttribute( 'type' ) || 'text' ).toLowerCase();
		return type === 'submit' || type === 'button' || type === 'reset';
	}
	if ( el.getAttribute( 'role' ) === 'button' ) {
		return true;
	}
	const cls = el.className && typeof el.className === 'string' ? el.className.toLowerCase() : '';
	return /(^|[\s_-])(button|btn)([\s_-]|$)/.test( cls );
}

/**
 * Human label for a bare element ("Heading", "Button"...).
 */
export function tagLabel( el: Element ): string {
	const tag = el.tagName.toLowerCase();
	if ( tag === 'a' && looksLikeButton( el ) ) {
		return 'Button';
	}
	if ( tag === 'input' && looksLikeButton( el ) ) {
		return 'Button';
	}
	return TAG_LABELS[ tag ] || 'Element';
}

/**
 * Finds the nearest marker (self first) that matches the element.
 */
export function findMarker( el: Element, markers: DomMarker[] ): { element: Element; marker: DomMarker } | null {
	let current: Element | null = el;
	while ( current && current !== document.documentElement ) {
		for ( const marker of markers ) {
			try {
				if ( current.matches( marker.selector ) ) {
					return { element: current, marker };
				}
			} catch {
				// Invalid selector from a third-party provider; ignore it.
			}
		}
		current = current.parentElement;
	}
	return null;
}

export function isSourceBearing( el: Element, markers: DomMarker[] ): boolean {
	return markers.some( ( m ) => {
		try {
			return el.matches( m.selector );
		} catch {
			return false;
		}
	} );
}

function humanize( value: string ): string {
	return value
		.replace( /\.default$/, '' )
		.replace( /^core\//, '' )
		.replace( /^[a-z0-9-]+\//, '' )
		.split( /[-_./]/ )
		.filter( Boolean )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

/**
 * Type name from a marker element, e.g. "Heading" from data-widget_type="heading.default".
 */
export function markerType( el: Element, marker: DomMarker ): string {
	if ( marker.typeFromDataset ) {
		const value = ( el as HTMLElement ).dataset?.[ marker.typeFromDataset ];
		if ( value ) {
			return humanize( value );
		}
	}
	if ( marker.typeFromClassPrefix ) {
		for ( const cls of Array.from( el.classList ) ) {
			if ( cls.startsWith( marker.typeFromClassPrefix ) && cls.length > marker.typeFromClassPrefix.length ) {
				const rest = cls.slice( marker.typeFromClassPrefix.length );
				if ( ! /__|--/.test( rest ) ) {
					return humanize( rest );
				}
			}
		}
	}
	return '';
}

/**
 * Hover badge text: "Elementor · Heading", "Gutenberg · Button", "Heading".
 */
export function describe( el: Element, markers: DomMarker[] ): string {
	const found = findMarker( el, markers );
	const base = tagLabel( el );
	if ( ! found ) {
		return base;
	}
	const type = found.element === el ? markerType( el, found.marker ) || base : base;
	return `${ found.marker.label } · ${ type }`;
}

function rect( el: Element ): DOMRect {
	return el.getBoundingClientRect();
}

function sameRect( a: Element, b: Element, tolerance = 3 ): boolean {
	const ra = rect( a );
	const rb = rect( b );
	return (
		Math.abs( ra.left - rb.left ) <= tolerance &&
		Math.abs( ra.top - rb.top ) <= tolerance &&
		Math.abs( ra.width - rb.width ) <= tolerance &&
		Math.abs( ra.height - rb.height ) <= tolerance
	);
}

/**
 * Turns a raw event target into the element worth inspecting. Nested inline
 * text (span inside a button) resolves to its text container; SVG internals
 * resolve to the <svg>; <picture> children resolve to the <img>.
 */
export function resolveTarget( raw: Element, markers: DomMarker[] ): Element {
	let el: Element = raw;
	const svg = el.closest( 'svg' );
	if ( svg && svg !== el ) {
		el = svg;
	}
	if ( isSourceBearing( el, markers ) ) {
		return el;
	}
	if ( isInlineTag( el ) ) {
		let parent = el.parentElement;
		while ( parent && parent !== document.body ) {
			const tag = parent.tagName.toLowerCase();
			if ( TEXT_CONTAINERS.has( tag ) || isSourceBearing( parent, markers ) || looksLikeButton( parent ) ) {
				return parent;
			}
			if ( ! isInlineTag( parent ) ) {
				break;
			}
			parent = parent.parentElement;
		}
	}
	if ( el.tagName.toLowerCase() === 'source' && el.parentElement?.tagName.toLowerCase() === 'picture' ) {
		const img = el.parentElement.querySelector( 'img' );
		if ( img ) {
			return img;
		}
	}
	return el;
}

/**
 * The next ancestor worth selecting.
 */
export function meaningfulParent( el: Element, markers: DomMarker[] ): Element | null {
	let current = el.parentElement;
	while ( current && current !== document.documentElement ) {
		if ( current === document.body ) {
			return null;
		}
		if ( isSourceBearing( current, markers ) || isSemantic( current ) || ! sameRect( current, el ) ) {
			return current;
		}
		current = current.parentElement;
	}
	return null;
}

/**
 * Nearest source-bearing ancestor (or self).
 */
export function sourceBearingAncestor( el: Element, markers: DomMarker[] ): Element | null {
	const found = findMarker( el, markers );
	return found ? found.element : null;
}

function isVisible( el: Element ): boolean {
	if ( isSkippable( el ) ) {
		return false;
	}
	const r = rect( el );
	return r.width > 0 && r.height > 0;
}

/**
 * The child worth selecting, preferring the one under a point.
 */
export function meaningfulChild( el: Element, markers: DomMarker[], point?: { x: number; y: number } ): Element | null {
	const children = Array.from( el.children ).filter( isVisible );
	if ( children.length === 0 ) {
		return null;
	}
	let pick: Element | undefined;
	if ( point ) {
		pick = children.find( ( child ) => {
			const r = rect( child );
			return point.x >= r.left && point.x <= r.right && point.y >= r.top && point.y <= r.bottom;
		} );
	}
	let child: Element = pick || children[ 0 ];
	// Collapse transparent single-child wrappers.
	for ( let i = 0; i < 6; i++ ) {
		if ( isSourceBearing( child, markers ) || isSemantic( child ) ) {
			break;
		}
		const inner = Array.from( child.children ).filter( isVisible );
		if ( inner.length !== 1 || ! sameRect( inner[ 0 ], child ) ) {
			break;
		}
		child = inner[ 0 ];
	}
	return child;
}

import type { AncestorContext, DomMarker, ElementContext } from '../types';

/**
 * Builds the limited, normalized representation of an element that is sent
 * to the server. Never includes outerHTML or user-entered form values.
 */

const MAX_TEXT = 1000;
const MAX_ANCESTORS = 10;
const MAX_CLASSES = 40;
const FORM_TAGS = new Set( [ 'input', 'textarea', 'select', 'option', 'script', 'style', 'template', 'noscript' ] );

function isFormField( el: Element ): boolean {
	return FORM_TAGS.has( el.tagName.toLowerCase() );
}

/**
 * Visible text without values of form fields or scripts.
 */
export function visibleText( el: Element ): string {
	if ( isFormField( el ) ) {
		const tag = el.tagName.toLowerCase();
		if ( tag === 'input' ) {
			const type = ( el.getAttribute( 'type' ) || 'text' ).toLowerCase();
			if ( type === 'submit' || type === 'button' || type === 'reset' ) {
				return ( el.getAttribute( 'value' ) || '' ).trim();
			}
		}
		return '';
	}
	const parts: string[] = [];
	const walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
		acceptNode( node: Node ): number {
			if ( node.nodeType === Node.ELEMENT_NODE ) {
				return isFormField( node as Element ) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_SKIP;
			}
			return NodeFilter.FILTER_ACCEPT;
		},
	} );
	let node = walker.nextNode();
	let total = 0;
	while ( node && total < MAX_TEXT * 2 ) {
		const value = node.nodeValue || '';
		parts.push( value );
		total += value.length;
		node = walker.nextNode();
	}
	return parts.join( ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, MAX_TEXT );
}

function datasetKeys( markers: DomMarker[] ): Set< string > {
	const keys = new Set< string >();
	for ( const marker of markers ) {
		( marker.datasetKeys || [] ).forEach( ( k ) => keys.add( k ) );
		if ( marker.typeFromDataset ) {
			keys.add( marker.typeFromDataset );
		}
		if ( marker.typeFallbackDataset ) {
			keys.add( marker.typeFallbackDataset );
		}
	}
	return keys;
}

function pickDataset( el: Element, allowed: Set< string > ): Record< string, string > {
	const out: Record< string, string > = {};
	const dataset = ( el as HTMLElement ).dataset;
	if ( ! dataset ) {
		return out;
	}
	for ( const key of Object.keys( dataset ) ) {
		if ( key.startsWith( 'edittrace' ) || allowed.has( key ) ) {
			const value = dataset[ key ];
			if ( typeof value === 'string' && value.length <= 200 ) {
				out[ key ] = value;
			}
		}
	}
	return out;
}

function classes( el: Element ): string[] {
	return Array.from( el.classList ).slice( 0, MAX_CLASSES );
}

function ancestorContext( el: Element, allowed: Set< string > ): AncestorContext {
	return {
		tag: el.tagName.toLowerCase(),
		id: el.id || '',
		classes: classes( el ),
		dataset: pickDataset( el, allowed ),
	};
}

function imageSource( el: Element ): { src: string; alt: string; width: number; height: number } {
	let img: HTMLImageElement | null = null;
	const tag = el.tagName.toLowerCase();
	if ( tag === 'img' ) {
		img = el as HTMLImageElement;
	} else if ( tag === 'picture' || tag === 'figure' ) {
		img = el.querySelector( 'img' );
	}
	if ( ! img ) {
		return { src: '', alt: '', width: 0, height: 0 };
	}
	const src = img.currentSrc || img.getAttribute( 'src' ) || img.getAttribute( 'data-src' ) || '';
	return {
		src: src.startsWith( 'data:' ) ? '' : src,
		alt: ( img.getAttribute( 'alt' ) || '' ).trim(),
		width: img.naturalWidth || 0,
		height: img.naturalHeight || 0,
	};
}

/**
 * Extracts the element context for `el`.
 */
export function extractElementContext( el: Element, markers: DomMarker[], traceId: string ): ElementContext {
	const allowed = datasetKeys( markers );
	const tag = el.tagName.toLowerCase();
	const image = imageSource( el );

	let href = '';
	if ( tag === 'a' ) {
		href = ( el as HTMLAnchorElement ).href || '';
	} else if ( tag === 'area' ) {
		href = ( el as HTMLAreaElement ).href || '';
	}

	const ancestors: AncestorContext[] = [];
	let parent = el.parentElement;
	while ( parent && ancestors.length < MAX_ANCESTORS && parent !== document.documentElement ) {
		ancestors.push( ancestorContext( parent, allowed ) );
		parent = parent.parentElement;
	}

	return {
		tag,
		text: visibleText( el ),
		href,
		src: image.src,
		alt: image.alt,
		width: image.width,
		height: image.height,
		id: el.id || '',
		classes: classes( el ),
		dataset: pickDataset( el, allowed ),
		ancestors,
		pageUrl: window.location.href.split( '#' )[ 0 ],
		traceId,
	};
}

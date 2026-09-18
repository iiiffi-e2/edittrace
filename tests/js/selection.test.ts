import { describe, it, expect, beforeEach } from 'vitest';
import { describe as describeElement, findMarker, meaningfulChild, meaningfulParent, resolveTarget, tagLabel } from '../../assets/src/inspector/selection';
import type { DomMarker } from '../../assets/src/types';

const markers: DomMarker[] = [
	{ selector: '[data-edittrace-id]:not([data-edittrace-kind])', label: 'Gutenberg', typeFromClassPrefix: 'wp-block-' },
	{ selector: '.elementor-element[data-id]', label: 'Elementor', typeFromDataset: 'widget_type', typeFallbackDataset: 'element_type' },
];

/** jsdom has no layout; give elements fake boxes. */
function box( el: Element, rect: Partial< DOMRect > ): void {
	( el as HTMLElement ).getBoundingClientRect = () =>
		( { left: 0, top: 0, width: 100, height: 20, right: 100, bottom: 20, x: 0, y: 0, toJSON: () => ( {} ), ...rect } ) as DOMRect;
}

describe( 'selection', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'resolves nested inline text to the button/link', () => {
		document.body.innerHTML = `<div class="wp-block-button" data-edittrace-id="t1"><a class="wp-block-button__link" href="#"><span><strong>Request</strong> a Demo</span></a></div>`;
		const strong = document.querySelector( 'strong' )!;
		expect( resolveTarget( strong, markers ).tagName ).toBe( 'A' );
		expect( tagLabel( resolveTarget( strong, markers ) ) ).toBe( 'Button' );
	} );

	it( 'keeps source-bearing elements and resolves svg internals', () => {
		document.body.innerHTML = `<div class="elementor-element" data-id="a1" data-element_type="widget" data-widget_type="icon.default"><svg><path id="p"></path></svg></div>`;
		expect( resolveTarget( document.getElementById( 'p' )!, markers ).tagName.toLowerCase() ).toBe( 'svg' );
		const el = document.querySelector( '.elementor-element' )!;
		expect( resolveTarget( el, markers ) ).toBe( el );
	} );

	it( 'describes elements with marker labels and types', () => {
		document.body.innerHTML = `
			<div class="wp-block-heading" data-edittrace-id="t1"><h2 id="h">Title</h2></div>
			<div class="elementor-element" data-id="a1" data-element_type="widget" data-widget_type="heading.default" id="w"></div>
			<div class="elementor-element" data-id="a2" data-element_type="container" id="c"></div>
			<p id="plain">x</p>`;
		expect( describeElement( document.getElementById( 'h' )!, markers ) ).toBe( 'Gutenberg · Heading' );
		expect( describeElement( document.getElementById( 'w' )!, markers ) ).toBe( 'Elementor · Heading' );
		expect( describeElement( document.getElementById( 'c' )!, markers ) ).toBe( 'Elementor · Container' );
		expect( describeElement( document.getElementById( 'plain' )!, markers ) ).toBe( 'Paragraph' );
		expect( findMarker( document.getElementById( 'h' )!, markers )?.marker.label ).toBe( 'Gutenberg' );
	} );

	it( 'meaningful parent skips same-size wrappers but stops at source-bearing or semantic ancestors', () => {
		document.body.innerHTML = `<section id="s"><div id="wrap"><div id="inner"><a id="a">x</a></div></div></section>`;
		const a = document.getElementById( 'a' )!;
		const inner = document.getElementById( 'inner' )!;
		const wrap = document.getElementById( 'wrap' )!;
		const s = document.getElementById( 's' )!;
		box( a, {} );
		box( inner, {} );
		box( wrap, {} );
		box( s, { width: 500 } );
		expect( meaningfulParent( a, markers ) ).toBe( s );
		wrap.setAttribute( 'data-edittrace-id', 't5' );
		expect( meaningfulParent( a, markers ) ).toBe( wrap );
		expect( meaningfulParent( s, markers ) ).toBeNull();
	} );

	it( 'meaningful child prefers the child under the pointer and collapses wrappers', () => {
		document.body.innerHTML = `<div id="root"><div id="left"><div id="leftwrap"><p id="p">a</p></div></div><div id="right">b</div></div>`;
		const root = document.getElementById( 'root' )!;
		const left = document.getElementById( 'left' )!;
		const leftwrap = document.getElementById( 'leftwrap' )!;
		const p = document.getElementById( 'p' )!;
		const right = document.getElementById( 'right' )!;
		box( root, { width: 200 } );
		box( left, { width: 100 } );
		box( leftwrap, { width: 100 } );
		box( p, { width: 100 } );
		box( right, { left: 100, right: 200, width: 100 } );
		expect( meaningfulChild( root, markers, { x: 150, y: 10 } ) ).toBe( right );
		expect( meaningfulChild( root, markers, { x: 10, y: 10 } ) ).toBe( p );
		expect( meaningfulChild( p, markers ) ).toBeNull();
	} );
} );

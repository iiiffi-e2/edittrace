import { describe, it, expect, beforeEach } from 'vitest';
import { extractElementContext, visibleText } from '../../assets/src/inspector/context';
import type { DomMarker } from '../../assets/src/types';

const markers: DomMarker[] = [
	{ selector: '[data-edittrace-id]', label: 'Gutenberg', datasetKeys: [ 'edittraceId' ] },
	{ selector: '.elementor-element[data-id]', label: 'Elementor', typeFromDataset: 'widget_type', datasetKeys: [ 'id', 'element_type', 'widget_type' ] },
];

describe( 'extractElementContext', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'collects tag, text, href, classes, allow-listed dataset and bounded ancestors', () => {
		document.body.innerHTML = `
			<div data-edittrace-id="t9" data-secret="nope" class="wp-block-group">
				<div class="elementor-element" data-id="abc1234" data-element_type="widget" data-widget_type="button.default" data-settings='{"x":1}'>
					<a class="btn primary" href="/demo/" id="cta" data-track="xyz"><span>Request</span> a <strong>Demo</strong></a>
				</div>
			</div>`;
		const a = document.querySelector( 'a' )!;
		const ctx = extractElementContext( a, markers, 'deadbeefdeadbeefdeadbeefdeadbeef' );
		expect( ctx.tag ).toBe( 'a' );
		expect( ctx.text ).toBe( 'Request a Demo' );
		expect( ctx.href ).toMatch( /\/demo\/$/ );
		expect( ctx.classes ).toEqual( [ 'btn', 'primary' ] );
		expect( ctx.id ).toBe( 'cta' );
		expect( ctx.dataset ).toEqual( {} );
		expect( ctx.traceId ).toBe( 'deadbeefdeadbeefdeadbeefdeadbeef' );
		expect( ctx.ancestors[ 0 ].dataset ).toEqual( { id: 'abc1234', element_type: 'widget', widget_type: 'button.default' } );
		expect( ctx.ancestors[ 1 ].dataset ).toEqual( { edittraceId: 't9' } );
		expect( JSON.stringify( ctx ) ).not.toContain( 'nope' );
		expect( JSON.stringify( ctx ) ).not.toContain( 'settings' );
	} );

	it( 'never transmits user-entered form values', () => {
		document.body.innerHTML = `
			<form>
				<label>Email <input type="email" value="user@example.com"></label>
				<input type="password" value="hunter2">
				<textarea>private note</textarea>
				<button type="submit"><span>Send</span></button>
				<input type="submit" value="Subscribe">
			</form>`;
		const form = document.querySelector( 'form' )!;
		const ctx = extractElementContext( form, markers, '' );
		expect( ctx.text ).toBe( 'Email Send' );
		expect( JSON.stringify( ctx ) ).not.toContain( 'hunter2' );
		expect( JSON.stringify( ctx ) ).not.toContain( 'user@example.com' );
		expect( JSON.stringify( ctx ) ).not.toContain( 'private note' );
		expect( visibleText( document.querySelector( 'input[type=submit]' )! ) ).toBe( 'Subscribe' );
		expect( visibleText( document.querySelector( 'input[type=password]' )! ) ).toBe( '' );
		expect( visibleText( document.querySelector( 'textarea' )! ) ).toBe( '' );
	} );

	it( 'captures image src and alt but ignores data URIs', () => {
		document.body.innerHTML = `<figure><img src="data:image/png;base64,AAA" alt="Inline"></figure><img src="/wp-content/uploads/hero.jpg" alt="Hero">`;
		const inline = extractElementContext( document.querySelector( 'figure' )!, markers, '' );
		expect( inline.src ).toBe( '' );
		expect( inline.alt ).toBe( 'Inline' );
		const hero = extractElementContext( document.querySelectorAll( 'img' )[ 1 ], markers, '' );
		expect( hero.src ).toContain( '/wp-content/uploads/hero.jpg' );
		expect( hero.alt ).toBe( 'Hero' );
	} );

	it( 'limits ancestors to ten', () => {
		let html = '<span id="deep">x</span>';
		for ( let i = 0; i < 20; i++ ) {
			html = `<div class="l${ i }">${ html }</div>`;
		}
		document.body.innerHTML = html;
		const ctx = extractElementContext( document.getElementById( 'deep' )!, markers, '' );
		expect( ctx.ancestors ).toHaveLength( 10 );
	} );
} );

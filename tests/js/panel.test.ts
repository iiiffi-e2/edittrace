import { describe, it, expect, vi } from 'vitest';
import { Panel } from '../../assets/src/panel/panel';
import type { EditTraceConfig, SourceCandidate, SourceResult } from '../../assets/src/types';

const config: EditTraceConfig = {
	version: '1.0.0',
	restUrl: 'http://x.test/wp-json/edittrace/v1',
	nonce: 'n',
	traceId: 't',
	page: { title: 'Homepage', editUrl: 'http://x.test/wp-admin/post.php?post=1&action=edit' },
	markers: [],
	fallbackSearch: true,
	debug: false,
	adminUrl: 'http://x.test/wp-admin/',
	i18n: {},
};

function candidate( over: Partial< SourceCandidate > = {} ): SourceCandidate {
	return {
		provider: 'elementor', system: 'Elementor', sourceType: 'elementor_widget', sourceId: '10', sourceName: 'Homepage', sourceLabel: 'Page',
		itemLabel: 'Widget', itemName: 'Button', itemKey: 'button', hierarchy: [ 'Homepage', 'Hero', 'Button' ], confidence: 1, status: 'exact', statusLabel: 'Exact',
		editUrl: 'http://x.test/wp-admin/post.php?post=10&action=elementor', editLabel: 'Edit in Elementor', actions: [], global: false, globalNote: '', role: 'structure',
		reason: 'matched', details: {}, usage: {}, technical: { documentId: 10 }, ...over,
	};
}

function result( over: Partial< SourceResult > = {} ): SourceResult {
	return {
		status: 'exact', selected: { summary: 'Request a Demo', tag: 'a', text: 'Request a Demo', href: '', src: '', alt: '' }, primary: candidate(), candidates: [ candidate() ], weak: [],
		searched: false, trace: { available: true, id: null, entries: 12 }, page: { title: 'Homepage', url: '', objectType: 'post', objectId: 1, postType: 'page', editUrl: null, template: null },
		notes: [], debug: false, ...over,
	};
}

function make() {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const callbacks = { onClose: vi.fn(), onInspectParent: vi.fn(), onSearch: vi.fn() };
	return { panel: new Panel( host, config, callbacks ), host, callbacks };
}

describe( 'Panel', () => {
	it( 'starts idle and renders loading state', () => {
		const { panel } = make();
		expect( panel.isOpen() ).toBe( false );
		panel.setState( { status: 'loading', summary: 'Hello' } );
		expect( panel.isOpen() ).toBe( true );
		expect( panel.element.textContent ).toContain( 'Hello' );
		expect( panel.element.querySelector( '.edittrace-spinner' ) ).not.toBeNull();
	} );

	it( 'renders an exact result with source, location, actions and confidence', () => {
		const { panel } = make();
		panel.setState( { status: 'result', result: result(), searching: false } );
		const text = panel.element.textContent || '';
		expect( text ).toContain( 'Elementor' );
		expect( text ).toContain( 'Homepage' );
		expect( panel.element.querySelectorAll( '.edittrace-crumb' ) ).toHaveLength( 3 );
		const link = panel.element.querySelector< HTMLAnchorElement >( '.edittrace-btn--primary' )!;
		expect( link.getAttribute( 'href' ) ).toContain( 'action=elementor' );
		expect( link.textContent ).toBe( 'Edit in Elementor' );
		expect( panel.element.querySelector( '.edittrace-confidence--exact' )?.textContent ).toBe( 'Exact' );
		expect( panel.element.querySelector( '.edittrace-global' ) ).toBeNull();
		expect( panel.element.querySelector( '.edittrace-technical' ) ).not.toBeNull();
		expect( ( panel.element.querySelector( '.edittrace-technical' ) as HTMLDetailsElement ).open ).toBe( false );
	} );

	it( 'shows the global warning and escapes HTML', () => {
		const { panel } = make();
		const c = candidate( { global: true, globalNote: 'Affects <b>many</b> pages', sourceName: '<img src=x onerror=alert(1)>' } );
		panel.setState( { status: 'result', result: result( { primary: c, candidates: [ c ] } ), searching: false } );
		expect( panel.element.querySelector( '.edittrace-global' )?.textContent ).toContain( 'Affects <b>many</b> pages' );
		expect( panel.element.querySelector( 'img' ) ).toBeNull();
	} );

	it( 'renders candidate lists with at most three visible', () => {
		const { panel } = make();
		const list = [ 1, 2, 3, 4, 5 ].map( ( i ) => candidate( { confidence: 0.6, status: 'possible', statusLabel: 'Possible', sourceId: String( i ), sourceName: `Source ${ i }` } ) );
		panel.setState( { status: 'result', result: result( { status: 'candidates', primary: list[ 0 ], candidates: list } ), searching: false } );
		expect( panel.element.textContent ).toContain( 'Exact source not found' );
		expect( panel.element.querySelectorAll( '.edittrace-candidates' )[ 0 ].children ).toHaveLength( 3 );
		expect( panel.element.querySelector( 'details summary' )?.textContent ).toContain( '2 more' );
	} );

	it( 'renders the unknown state with search and parent actions and wires callbacks', () => {
		const { panel, callbacks } = make();
		panel.setState( { status: 'result', result: result( { status: 'unknown', primary: null, candidates: [] } ), searching: false } );
		expect( panel.element.textContent ).toContain( 'Source not identified' );
		( panel.element.querySelector( '[data-edittrace-action="search"]' ) as HTMLElement ).click();
		expect( callbacks.onSearch ).toHaveBeenCalled();
		( panel.element.querySelector( '[data-edittrace-action="parent"]' ) as HTMLElement ).click();
		expect( callbacks.onInspectParent ).toHaveBeenCalled();
		( panel.element.querySelector( '[data-edittrace-action="close"]' ) as HTMLElement ).click();
		expect( callbacks.onClose ).toHaveBeenCalled();
	} );

	it( 'rejects unsafe edit urls', () => {
		const { panel } = make();
		const c = candidate( { editUrl: 'javascript:alert(1)' } );
		panel.setState( { status: 'result', result: result( { primary: c, candidates: [ c ] } ), searching: false } );
		expect( panel.element.querySelector( '.edittrace-btn--primary' ) ).toBeNull();
		expect( panel.element.textContent ).toContain( 'No direct edit link' );
	} );
} );

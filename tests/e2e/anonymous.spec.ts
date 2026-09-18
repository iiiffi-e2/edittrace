import { test, expect } from '@playwright/test';

/**
 * Mandatory: logged-out visitors get nothing from EditTrace.
 */
test.describe( 'Anonymous visitor', () => {
	test( 'receives no EditTrace UI, scripts, metadata or REST access', async ( { page, request } ) => {
		const response = await page.goto( '/' );
		expect( response?.ok() ).toBeTruthy();
		const html = await response!.text();

		expect( html ).not.toContain( 'EditTraceConfig' );
		expect( html ).not.toContain( 'edittrace.js' );
		expect( html ).not.toContain( 'data-edittrace-id' );
		expect( html ).not.toContain( 'wp-admin-bar-edittrace' );
		expect( html ).not.toContain( 'edittrace-adminbar.css' );
		await expect( page.locator( '#edittrace-root' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => 'EditTrace' in window || 'EditTraceConfig' in window ) ).toBe( false );

		for ( const path of [ '/acf-demo/', '/elementor-landing/' ] ) {
			const res = await request.get( path );
			const body = await res.text();
			expect( body ).not.toContain( 'data-edittrace-id' );
			expect( body ).not.toContain( 'EditTraceConfig' );
		}

		const inspectRes = await request.post( '/wp-json/edittrace/v1/inspect', { data: { element: { tag: 'a', text: 'x' } } } );
		expect( inspectRes.status() ).toBe( 401 );
		const searchRes = await request.post( '/wp-json/edittrace/v1/search', { data: { element: { tag: 'a', text: 'x' } } } );
		expect( searchRes.status() ).toBe( 401 );
	} );
} );

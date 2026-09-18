import { test, expect } from '@playwright/test';

/**
 * Logged-in administrators get nothing until they switch EditTrace on, and
 * can switch it off again from the toolbar.
 */
test.describe( 'Per-user switch', () => {
	test( 'turning EditTrace off removes tracing and scripts; turning it on restores them and opens the inspector', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#wp-admin-bar-edittrace' ) ).toHaveClass( /edittrace-admin-bar--on/ );

		await page.hover( '#wp-admin-bar-edittrace' );
		await page.locator( '#wp-admin-bar-edittrace-off a' ).click();
		await page.waitForURL( ( url ) => ! url.searchParams.has( 'action' ) );

		let html = await page.content();
		expect( html ).not.toContain( 'EditTraceConfig' );
		expect( html ).not.toContain( 'edittrace.js' );
		expect( html ).not.toContain( 'data-edittrace-id' );
		await expect( page.locator( '#edittrace-root' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wp-admin-bar-edittrace' ) ).toHaveClass( /edittrace-admin-bar--off/ );

		await page.locator( '#wp-admin-bar-edittrace > a' ).click();
		await page.waitForURL( /#edittrace$/ );
		await expect( page.locator( '#wp-admin-bar-edittrace' ) ).toHaveClass( /edittrace-admin-bar--on/ );
		html = await page.content();
		expect( html ).toContain( 'EditTraceConfig' );
		expect( html ).toContain( 'data-edittrace-id' );
		await expect( page.locator( '#edittrace-root' ) ).toHaveClass( /edittrace-root--active/ );
	} );

	test( 'toggle endpoint rejects requests without a valid nonce', async ( { request } ) => {
		const res = await request.get( '/wp-admin/admin-post.php?action=edittrace_toggle&state=on&redirect=%2F', { maxRedirects: 0 } );
		expect( [ 403, 401, 302 ] ).toContain( res.status() );
		expect( res.status() ).not.toBe( 200 );
	} );
} );

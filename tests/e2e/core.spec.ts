import { test, expect } from '@playwright/test';
import { activate, inspect, panel } from './helpers';

test.describe( 'Core sources', () => {
	test( 'image resolves to the attachment and its block', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.home-image img' );
		expect( result.status ).toBe( 'exact' );
		const media = result.candidates.find( ( c ) => c.provider === 'media' );
		expect( media?.sourceName ).toBe( 'hero.jpg' );
		expect( media?.editUrl ).toContain( 'post.php?post=' );
		expect( result.primary?.itemKey ).toBe( 'core/image' );
		await expect( panel( page ) ).toContainText( 'Media Library' );
	} );

	test( 'classic menu item resolves to Appearance → Menus', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.classic-menu a' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.system ).toBe( 'Navigation' );
		expect( result.primary?.sourceName ).toBe( 'Primary Navigation' );
		expect( result.primary?.editUrl ).toContain( 'nav-menus.php' );
	} );

	test( 'site title resolves to General Settings', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.wp-block-site-title a' );
		expect( result.primary?.itemName ).toBe( 'Site Title' );
		expect( result.primary?.editUrl ).toContain( 'options-general.php' );
	} );

	test( 'unknown content produces a useful unknown state without fabricating a source', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.untraceable-text' );
		expect( result.status ).toBe( 'unknown' );
		expect( result.primary ).toBeNull();
		await expect( panel( page ) ).toContainText( 'Source not identified' );
		await expect( panel( page ).locator( '[data-edittrace-action="parent"]' ) ).toBeVisible();
		// The automatic fallback search finishes and still finds nothing.
		await expect( panel( page ) ).toContainText( 'search found nothing', { timeout: 15_000 } );
		await expect( panel( page ).locator( '.edittrace-technical' ) ).toBeVisible();
	} );

	test( 'fallback search finds option-backed content as a possible source', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const first = await inspect( page, '.option-text' );
		expect( first.status ).toBe( 'unknown' );
		await expect( panel( page ) ).toContainText( 'Exact source not found', { timeout: 15_000 } );
		await expect( panel( page ) ).toContainText( 'edittrace_test_footer_text' );
		await expect( panel( page ).locator( '.edittrace-confidence--exact' ) ).toHaveCount( 0 );
	} );
} );

import { test, expect } from '@playwright/test';
import { activate, inspect, panel } from './helpers';

test.describe( 'Gutenberg', () => {
	test( 'heading resolves to the exact page and block', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.home-heading' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.system ).toBe( 'Gutenberg' );
		expect( result.primary?.sourceName ).toBe( 'Homepage' );
		expect( result.primary?.itemKey ).toBe( 'core/heading' );
		expect( result.primary?.editUrl ).toContain( 'post.php?post=' );
		await expect( panel( page ).locator( '.edittrace-btn--primary' ) ).toHaveText( 'Edit Page' );
	} );

	test( 'button inside nested group/columns reports the full hierarchy', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.home-cta a span' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.hierarchy ).toEqual( [ 'Homepage', 'Hero', 'Columns', 'Column 2', 'Buttons', 'Button' ] );
		expect( result.primary?.itemKey ).toBe( 'core/button' );
		expect( result.primary?.global ).toBe( false );
		await expect( panel( page ).locator( '.edittrace-crumb--current' ) ).toHaveText( 'Button' );
	} );

	test( 'template part and synced pattern are reported as global content, not the page', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const part = await inspect( page, '.header-tagline' );
		expect( part.status ).toBe( 'exact' );
		expect( part.primary?.sourceLabel ).toBe( 'Template Part' );
		expect( part.primary?.sourceName ).toBe( 'Header' );
		expect( part.primary?.global ).toBe( true );
		expect( part.primary?.editUrl ).toContain( 'site-editor.php' );
		await expect( panel( page ).locator( '.edittrace-global' ) ).toBeVisible();

		const pattern = await inspect( page, '.shared-promo-text' );
		expect( pattern.primary?.sourceLabel ).toBe( 'Synced Pattern' );
		expect( pattern.primary?.global ).toBe( true );
		expect( pattern.primary?.editLabel ).toBe( 'Edit Pattern' );
	} );

	test( 'navigation block link resolves to the navigation menu', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.nav-about a' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.system ).toBe( 'Navigation' );
		expect( result.primary?.sourceName ).toBe( 'Main Navigation' );
		expect( result.primary?.itemName ).toBe( 'About Us' );
		expect( result.primary?.editUrl ).toContain( 'wp_navigation' );
	} );
} );

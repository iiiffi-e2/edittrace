import { test, expect } from '@playwright/test';
import { activate, inspect, panel } from './helpers';

test.describe( 'Elementor', () => {
	test( 'heading resolves to the correct document and widget', async ( { page } ) => {
		await page.goto( '/elementor-landing/' );
		await activate( page );
		const result = await inspect( page, '[data-elementor-type="wp-page"] .elementor-widget-heading .elementor-heading-title' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.system ).toBe( 'Elementor' );
		expect( result.primary?.sourceName ).toBe( 'Elementor Landing' );
		expect( result.primary?.itemName ).toBe( 'Heading' );
		expect( result.primary?.hierarchy ).toEqual( [ 'Elementor Landing', 'Hero Container', 'Heading' ] );
		expect( result.primary?.editUrl ).toContain( 'action=elementor' );
		await expect( panel( page ).locator( '.edittrace-btn--primary' ) ).toHaveText( 'Edit in Elementor' );
	} );

	test( 'nested button, text and image widgets resolve with hierarchy', async ( { page } ) => {
		await page.goto( '/elementor-landing/' );
		await activate( page );
		const button = await inspect( page, '[data-elementor-type="wp-page"] .elementor-widget-button a span' );
		expect( button.primary?.hierarchy ).toEqual( [ 'Elementor Landing', 'Hero Container', 'CTA Container', 'Button' ] );
		expect( button.primary?.technical.elementId ).toBe( 'e5f6a7b' );

		const text = await inspect( page, '[data-elementor-type="wp-page"] .elementor-widget-text-editor p' );
		expect( text.primary?.itemKey ).toBe( 'text-editor' );

		const image = await inspect( page, '[data-elementor-type="wp-page"] .elementor-widget-image img' );
		expect( image.primary?.itemKey ).toBe( 'image' );
		expect( image.candidates.some( ( c ) => c.provider === 'media' && c.sourceName === 'hero.jpg' ) ).toBe( true );
	} );

	test( 'global header element resolves to the Theme Builder template, not the current page', async ( { page } ) => {
		await page.goto( '/elementor-landing/' );
		await activate( page );
		const result = await inspect( page, '[data-elementor-type="header"] .elementor-widget-button a' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.system ).toBe( 'Elementor Theme Builder' );
		expect( result.primary?.sourceName ).toBe( 'Main Site Header' );
		expect( result.primary?.sourceName ).not.toBe( 'Elementor Landing' );
		expect( result.primary?.global ).toBe( true );
		expect( result.primary?.hierarchy ).toEqual( [ 'Main Site Header', 'Header Container', 'Button' ] );
		await expect( panel( page ).locator( '.edittrace-global' ) ).toContainText( 'Theme Builder' );
	} );
} );

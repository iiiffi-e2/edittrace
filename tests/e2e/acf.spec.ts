import { test, expect } from '@playwright/test';
import { activate, inspect, panel } from './helpers';

test.describe( 'ACF', () => {
	test( 'page field text resolves to the field and the page', async ( { page } ) => {
		await page.goto( '/acf-demo/' );
		await activate( page );
		const result = await inspect( page, '.acf-hero-heading' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.system ).toBe( 'Advanced Custom Fields' );
		expect( result.primary?.itemName ).toBe( 'Hero Heading' );
		expect( result.primary?.itemKey ).toBe( 'hero_heading' );
		expect( result.primary?.sourceName ).toBe( 'ACF Demo' );
		expect( result.primary?.global ).toBe( false );
		expect( result.primary?.editUrl ).toContain( 'post.php?post=' );
		await expect( panel( page ) ).toContainText( 'Homepage Hero' );
	} );

	test( 'link, image and WYSIWYG fields resolve', async ( { page } ) => {
		await page.goto( '/acf-demo/' );
		await activate( page );
		const link = await inspect( page, '.acf-hero-cta span' );
		expect( link.primary?.itemKey ).toBe( 'hero_cta' );
		expect( link.status ).toBe( 'exact' );

		const image = await inspect( page, '.acf-hero-image' );
		expect( image.primary?.itemKey ).toBe( 'hero_image' );
		expect( image.candidates.some( ( c ) => c.provider === 'media' ) ).toBe( true );

		const intro = await inspect( page, '.acf-hero-intro p' );
		expect( intro.primary?.itemKey ).toBe( 'hero_intro' );
		expect( intro.primary?.status ).toBe( 'high' );
	} );

	test( 'options field resolves to Site Options with a global warning', async ( { page } ) => {
		await page.goto( '/acf-demo/' );
		await activate( page );
		const result = await inspect( page, '.company-phone' );
		expect( result.status ).toBe( 'exact' );
		expect( result.primary?.sourceType ).toBe( 'acf_option' );
		expect( result.primary?.itemName ).toBe( 'Main Phone Number' );
		expect( result.primary?.sourceName ).toBe( 'Site Options' );
		expect( result.primary?.global ).toBe( true );
		await expect( panel( page ).locator( '.edittrace-global' ) ).toBeVisible();
		await expect( panel( page ) ).toContainText( 'Company Information' );
	} );
} );

import { test, expect } from '@playwright/test';
import { activate, inspect, panel } from './helpers';

test.describe( 'Core inspector', () => {
	test( 'admin bar button toggles Inspector Mode with toolbar and hover highlight', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#wp-admin-bar-edittrace' ) ).toBeVisible();
		await activate( page );
		const root = page.locator( '#edittrace-root' );
		await expect( root.locator( '.edittrace-toolbar' ) ).toBeVisible();
		await expect( root.locator( '.edittrace-toolbar' ) ).toContainText( 'EditTrace Active' );

		await page.hover( '.home-heading' );
		const hover = root.locator( '.edittrace-highlight--hover' );
		await expect( hover ).toBeVisible();
		await expect( hover.locator( '.edittrace-badge' ) ).toContainText( 'Heading' );

		await page.keyboard.press( 'Escape' );
		await expect( root ).not.toHaveClass( /edittrace-root--active/ );
	} );

	test( 'clicking an element opens the panel with a server result', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		const result = await inspect( page, '.home-heading' );
		expect( [ 'exact', 'candidates', 'unknown' ] ).toContain( result.status );
		await expect( panel( page ) ).toBeVisible();
		await expect( panel( page ).locator( '.edittrace-selected__text' ) ).toContainText( 'Welcome to EditTrace' );
		await expect( panel( page ).locator( '.edittrace-technical' ) ).toBeVisible();
	} );

	test( 'nested span resolves to its button and parent/child navigation works', async ( { page } ) => {
		await page.goto( '/' );
		await activate( page );
		await inspect( page, '.home-cta span' );
		const selectedTag = await page.evaluate( () => ( window as unknown as { EditTrace: { getSelected(): Element | null } } ).EditTrace.getSelected()?.tagName );
		expect( selectedTag ).toBe( 'A' );

		await page.keyboard.press( 'ArrowUp' );
		await page.waitForTimeout( 300 );
		const parentTag = await page.evaluate( () => ( window as unknown as { EditTrace: { getSelected(): Element | null } } ).EditTrace.getSelected()?.className );
		expect( parentTag ).toContain( 'wp-block-button' );
	} );
} );

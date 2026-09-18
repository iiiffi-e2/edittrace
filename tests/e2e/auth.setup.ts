import { test as setup, expect } from '@playwright/test';

const user = process.env.EDITTRACE_ADMIN_USER || 'admin';
const pass = process.env.EDITTRACE_ADMIN_PASS || 'password';

setup( 'authenticate as administrator', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	// EditTrace is off per user until switched on from the toolbar.
	await page.goto( '/' );
	const node = page.locator( '#wp-admin-bar-edittrace > a' );
	if ( await node.evaluate( ( el ) => el.parentElement?.classList.contains( 'edittrace-admin-bar--off' ) ) ) {
		await node.click();
		await page.waitForURL( /#edittrace$/ );
	}
	await expect( page.locator( '#wp-admin-bar-edittrace' ) ).toHaveClass( /edittrace-admin-bar--on/ );
	await page.context().storageState( { path: 'tests/e2e/.auth/admin.json' } );
} );

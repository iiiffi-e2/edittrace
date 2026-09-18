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
	await page.context().storageState( { path: 'tests/e2e/.auth/admin.json' } );
} );

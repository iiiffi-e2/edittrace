import { defineConfig, devices } from '@playwright/test';
import { existsSync } from 'node:fs';

const baseURL = process.env.EDITTRACE_SITE_URL || 'http://127.0.0.1:8787';
const chrome = process.env.EDITTRACE_CHROME || ( existsSync( '/opt/pw-browsers/chromium' ) ? '/opt/pw-browsers/chromium' : undefined );

export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 60_000,
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		...devices[ 'Desktop Chrome' ],
		launchOptions: chrome ? { executablePath: chrome } : {},
	},
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.ts/ },
		{
			name: 'admin',
			testMatch: /.*\.spec\.ts/,
			testIgnore: /anonymous/,
			dependencies: [ 'setup' ],
			use: { storageState: 'tests/e2e/.auth/admin.json' },
		},
		{
			name: 'anonymous',
			testMatch: /anonymous\.spec\.ts/,
		},
	],
} );

/**
 * Playwright end-to-end tests against wp-env (tests instance, port 8889).
 */
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8889';

export default defineConfig( {
	testDir: './specs',
	outputDir: '../../test-results',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI
		? [
				[ 'github' ],
				[
					'html',
					{ open: 'never', outputFolder: '../../playwright-report' },
				],
			]
		: 'list',
	globalSetup: require.resolve( './global-setup.ts' ),
	use: {
		baseURL,
		storageState: path.join(
			__dirname,
			'../../test-results/storage-state.json'
		),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );

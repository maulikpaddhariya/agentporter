import { defineConfig, devices } from '@playwright/test';

// PW_CHANNEL=chrome uses an installed Google Chrome instead of Playwright's download (offline runs).
const channel = process.env.PW_CHANNEL;

export default defineConfig( {
	testDir: '.',
	outputDir: '../../../test-results/seeded',
	reporter: 'list',
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ], ...( channel ? { channel } : {} ) },
		},
	],
} );

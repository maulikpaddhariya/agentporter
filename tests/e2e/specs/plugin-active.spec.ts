/**
 * Smoke test: AgentPorter is active and its plugin row is accessible.
 */
import { test, expect } from '@playwright/test';
import { expectNoSeriousA11yViolations } from '../axe';

test( 'AgentPorter is active on the Plugins screen', async ( { page } ) => {
	await page.goto( '/wp-admin/plugins.php' );
	const row = page.locator( 'tr[data-slug="agentporter"]' );
	await expect( row ).toHaveClass( /active/ );
	await expectNoSeriousA11yViolations( page, 'tr[data-slug="agentporter"]' );
} );

test( 'no PHP notices or fatal errors on the dashboard', async ( { page } ) => {
	const response = await page.goto( '/wp-admin/' );
	expect( response?.status() ).toBe( 200 );
	await expect( page.locator( 'body' ) ).not.toContainText(
		/(Fatal error|Warning:|Notice:|Deprecated:)/
	);
} );

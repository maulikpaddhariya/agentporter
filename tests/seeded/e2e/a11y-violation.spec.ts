/**
 * Seeded violation: proves the axe gate fails on inaccessible markup. Runs without WordPress.
 */
import { test } from '@playwright/test';
import { expectNoSeriousA11yViolations } from '../../e2e/axe';

test( 'seeded inaccessible markup fails the axe gate', async ( { page } ) => {
	await page.setContent(
		'<!doctype html><html lang="en"><head><title>Seeded</title></head><body><main>' +
			'<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">' +
			'<button></button><input type="text"></main></body></html>'
	);
	await expectNoSeriousA11yViolations( page );
} );

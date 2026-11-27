/**
 * Accessibility assertion: zero serious or critical axe violations (STANDARDS §4.3).
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

export async function expectNoSeriousA11yViolations(
	page: Page,
	include?: string
): Promise< void > {
	let builder = new AxeBuilder( { page } ).withTags( [
		'wcag2a',
		'wcag2aa',
		'wcag21a',
		'wcag21aa',
	] );
	if ( include ) {
		builder = builder.include( include );
	}
	const results = await builder.analyze();
	const serious = results.violations.filter(
		( violation ) =>
			violation.impact === 'serious' || violation.impact === 'critical'
	);
	expect(
		serious.map(
			( violation ) =>
				`${ violation.id }: ${ violation.help } (${ violation.nodes.length })`
		)
	).toEqual( [] );
}

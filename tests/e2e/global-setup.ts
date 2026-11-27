/**
 * Logs in once as the wp-env administrator and saves the session for every spec.
 */
import { request, type FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

export default async function globalSetup(
	config: FullConfig
): Promise< void > {
	const project = config.projects[ 0 ];
	const baseURL = String( project?.use.baseURL ?? 'http://localhost:8889' );
	const storageStatePath = String(
		project?.use.storageState ?? 'test-results/storage-state.json'
	);
	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );
	await requestUtils.setupRest();
	await requestContext.dispose();
}

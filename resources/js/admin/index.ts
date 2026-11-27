/**
 * AgentPorter admin entry point.
 *
 * P0-5 placeholder: proves the build, lint, type-check, test and size tooling end to end.
 * P0-6 replaces it with the React admin app.
 */
import './style.scss';

export const ROOT_ID = 'agentporter-admin';

/**
 * Finds the admin mount point rendered by the PHP admin page.
 *
 * @param doc Document to search (injectable for tests).
 * @return The mount element, or null when the page has none.
 */
export function findRoot( doc: Document = document ): HTMLElement | null {
	return doc.getElementById( ROOT_ID );
}

/**
 * Marks the mount point as ready. Uses textContent, never HTML injection.
 *
 * @param root  Mount element.
 * @param label Status text.
 */
export function markReady( root: HTMLElement, label: string ): void {
	root.dataset.ready = 'true';
	root.textContent = label;
}

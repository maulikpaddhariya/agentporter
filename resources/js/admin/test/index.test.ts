/**
 * Internal dependencies
 */
import { findRoot, markReady, ROOT_ID } from '../index';

describe( 'admin entry', () => {
	it( 'returns null when the mount point is missing', () => {
		document.body.innerHTML = '';
		expect( findRoot() ).toBeNull();
	} );

	it( 'marks the root ready using text, not HTML', () => {
		const root = document.createElement( 'div' );
		root.id = ROOT_ID;
		document.body.appendChild( root );
		markReady( root, '<img src=x onerror=alert(1)>' );
		expect( findRoot() ).toBe( root );
		expect( root.dataset.ready ).toBe( 'true' );
		expect( root.querySelector( 'img' ) ).toBeNull();
		expect( root.textContent ).toBe( '<img src=x onerror=alert(1)>' );
	} );
} );

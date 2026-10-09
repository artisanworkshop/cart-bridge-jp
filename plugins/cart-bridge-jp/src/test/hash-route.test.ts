import { describe, expect, it } from '@jest/globals';
import { parseHash, tabHref } from '../hash-route';

describe( 'parseHash', () => {
	it( 'reads the tab without a platform', () => {
		expect( parseHash( '#/mappings' ) ).toEqual( {
			tab: 'mappings',
			platform: null,
		} );
	} );

	it( 'reads the tab and the platform', () => {
		expect( parseHash( '#/mappings?platform=colorme' ) ).toEqual( {
			tab: 'mappings',
			platform: 'colorme',
		} );
	} );

	it( 'decodes the platform and ignores other parameters', () => {
		expect( parseHash( '#/import?x=1&platform=a%2Fb' ) ).toEqual( {
			tab: 'import',
			platform: 'a/b',
		} );
	} );

	it( 'treats an empty platform as none', () => {
		expect( parseHash( '#/mappings?platform=' ).platform ).toBeNull();
		expect( parseHash( '#/mappings?' ).platform ).toBeNull();
	} );

	it( 'handles an empty or bare hash', () => {
		expect( parseHash( '' ) ).toEqual( { tab: '', platform: null } );
		expect( parseHash( '#' ) ).toEqual( { tab: '', platform: null } );
		expect( parseHash( '#/' ) ).toEqual( { tab: '', platform: null } );
	} );
} );

describe( 'tabHref', () => {
	it( 'omits the platform when there is none', () => {
		expect( tabHref( 'mappings' ) ).toBe( '#/mappings' );
		expect( tabHref( 'mappings', null ) ).toBe( '#/mappings' );
		expect( tabHref( 'mappings', '' ) ).toBe( '#/mappings' );
	} );

	it( 'encodes the platform so that it round-trips', () => {
		const href = tabHref( 'mappings', 'a&b=c' );

		expect( href ).toBe( '#/mappings?platform=a%26b%3Dc' );
		expect( parseHash( href ) ).toEqual( {
			tab: 'mappings',
			platform: 'a&b=c',
		} );
	} );
} );

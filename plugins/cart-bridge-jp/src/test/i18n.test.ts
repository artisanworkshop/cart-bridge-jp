import { afterEach, describe, expect, it } from '@jest/globals';
import { displayLocale, joinList, joinSentences } from '../i18n';

describe( 'joinList', () => {
	it( 'returns an empty string for no items', () => {
		expect( joinList( [] ) ).toBe( '' );
	} );

	it( 'returns a single item as is', () => {
		expect( joinList( [ 'Products' ] ) ).toBe( 'Products' );
	} );

	it( 'joins the items in order with the translatable separator', () => {
		expect( joinList( [ 'Products', 'Customers', 'Orders' ] ) ).toBe(
			'Products, Customers, Orders'
		);
	} );

	it( 'does not treat a percent sign in an item as a placeholder', () => {
		expect( joinList( [ '10%', '%s' ] ) ).toBe( '10%, %s' );
	} );
} );

describe( 'joinSentences', () => {
	it( 'joins two sentences with the translatable separator', () => {
		expect( joinSentences( 'First.', 'Second.' ) ).toBe( 'First. Second.' );
	} );
} );

describe( 'displayLocale', () => {
	afterEach( () => {
		delete window.cbjpAdmin;
	} );

	const withLocale = ( locale?: string ) => {
		window.cbjpAdmin = { restUrl: '', restNonce: '', locale };
	};

	it( 'falls back to the browser default without the bootstrap data', () => {
		expect( displayLocale() ).toBeUndefined();
	} );

	it( 'falls back to the browser default when no locale is passed', () => {
		withLocale( undefined );

		expect( displayLocale() ).toBeUndefined();
	} );

	it( 'uses the WordPress user locale', () => {
		withLocale( 'ja' );

		expect( displayLocale() ).toBe( 'ja' );
	} );

	it( 'accepts a region converted from the WordPress locale', () => {
		withLocale( 'en-US' );

		expect( displayLocale() ).toBe( 'en-US' );
	} );

	it( 'falls back to the browser default for a tag that is not valid BCP 47', () => {
		// WordPress の `pt_PT_ao90` は `-` にしても BCP 47 として不正（4 文字の variant は数字で始まる）。
		withLocale( 'pt-PT-ao90' );

		expect( displayLocale() ).toBeUndefined();
	} );
} );

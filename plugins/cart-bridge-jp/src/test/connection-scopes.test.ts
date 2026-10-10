import { describe, expect, it } from '@jest/globals';
import { missingScopes } from '../connection-scopes';
import type { Connection } from '../types';

function connection( fields: Record< string, unknown > ): Connection {
	return fields as unknown as Connection;
}

describe( 'missingScopes', () => {
	it( 'lists the scopes a connected token is missing', () => {
		expect(
			missingScopes(
				connection( {
					connected: true,
					missing_scopes: [ 'read_sales', 'read_shop_coupons' ],
				} )
			)
		).toEqual( [ 'read_sales', 'read_shop_coupons' ] );
	} );

	it( 'reads nothing for a connection that is not connected', () => {
		for ( const connected of [ false, 'true', 1, undefined ] ) {
			expect(
				missingScopes(
					connection( {
						connected,
						missing_scopes: [ 'read_sales' ],
					} )
				)
			).toEqual( [] );
		}
	} );

	it( 'reads only non-empty strings, once each', () => {
		expect(
			missingScopes(
				connection( {
					connected: true,
					missing_scopes: [
						'read_sales',
						'',
						7,
						null,
						[ 'write_sales' ],
						'read_sales',
					],
				} )
			)
		).toEqual( [ 'read_sales' ] );
	} );

	it( 'reads nothing when the value is missing or not a list', () => {
		expect( missingScopes( null ) ).toEqual( [] );
		expect( missingScopes( connection( { connected: true } ) ) ).toEqual(
			[]
		);

		for ( const value of [ 'read_sales', { 0: 'read_sales' }, null ] ) {
			expect(
				missingScopes(
					connection( { connected: true, missing_scopes: value } )
				)
			).toEqual( [] );
		}
	} );

	it( 'does not read an inherited property', () => {
		const inherited = Object.create( {
			missing_scopes: [ 'read_sales' ],
		} ) as Record< string, unknown >;
		inherited.connected = true;

		expect( missingScopes( connection( inherited ) ) ).toEqual( [] );
	} );
} );

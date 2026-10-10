import { describe, expect, it } from '@jest/globals';
import type { VerificationEntity } from '../../types';
import { rowStatus } from '../verification-status';

function row(
	overrides: Partial< VerificationEntity > = {}
): VerificationEntity {
	return {
		entity: 'product',
		status: 'completed',
		processed: 2,
		written: 2,
		skipped: 0,
		warned: 0,
		linked: 2,
		existing: 2,
		missing: 0,
		remote_amount: null,
		local_amount: null,
		...overrides,
	};
}

describe( 'rowStatus', () => {
	it( 'reconciles matching counts', () => {
		expect( rowStatus( row(), true ) ).toBe( 'reconciled' );
	} );

	// R3-6c1: 登録の無い種類（Pro を止めた後の過去の受注など）は実在を確かめられない。null を 0 として「Woo 側が少ない」と読まない。
	it( 'reports rows whose records could not be checked as unknown', () => {
		expect(
			rowStatus( row( { existing: null, missing: null } ), true )
		).toBe( 'unknown' );
		expect( rowStatus( row( { existing: null } ), true ) ).toBe(
			'unknown'
		);
		expect( rowStatus( row( { missing: null } ), true ) ).toBe( 'unknown' );
	} );

	it( 'still tells missing, fewer, more, and amount apart', () => {
		expect( rowStatus( row( { existing: 1, missing: 1 } ), true ) ).toBe(
			'missing'
		);
		expect( rowStatus( row( { existing: 1 } ), true ) ).toBe( 'fewer' );
		expect( rowStatus( row( { existing: 3 } ), true ) ).toBe( 'more' );
		expect(
			rowStatus(
				row( { remote_amount: '10.00', local_amount: '9.00' } ),
				true
			)
		).toBe( 'amount' );
		expect(
			rowStatus(
				row( { remote_amount: '10.00', local_amount: '9.00' } ),
				false
			)
		).toBe( 'reconciled' );
	} );
} );

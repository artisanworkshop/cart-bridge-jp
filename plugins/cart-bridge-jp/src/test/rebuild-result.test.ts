import { describe, expect, it } from '@jest/globals';
import { mergeCounts, mergeSkipped } from '../rebuild-result';

describe( 'mergeCounts', () => {
	it( 'adds batches and keeps the order the server sent the keys in', () => {
		// サーバーの走査順（coupon は customer・order より前。LinkSource の position）を並べ替えない。
		const first = mergeCounts( {}, { product: 2, variant: 0, coupon: 1 } );
		const second = mergeCounts( first, {
			coupon: 1,
			customer: 3,
			order: 0,
		} );

		expect( Object.keys( second ) ).toEqual( [
			'product',
			'variant',
			'coupon',
			'customer',
			'order',
		] );
		expect( second ).toEqual( {
			product: 2,
			variant: 0,
			coupon: 2,
			customer: 3,
			order: 0,
		} );
	} );

	it( 'does not read inherited properties as earlier counts', () => {
		// `constructor` は登録できるキーの形（PR #116 G1-2）。
		const counts = mergeCounts(
			mergeCounts( {}, { constructor: 2, tostring: 1 } ),
			{ constructor: 1 }
		);

		expect( counts.constructor ).toBe( 3 );
		expect( counts ).toEqual( { constructor: 3, tostring: 1 } );
	} );

	it( 'ignores malformed counts', () => {
		expect( mergeCounts( { product: 1 }, null ) ).toEqual( { product: 1 } );
		expect( mergeCounts( { product: 1 }, [ 3 ] ) ).toEqual( {
			product: 1,
		} );
		expect(
			mergeCounts( {}, { product: '2', tag: Number.NaN, order: 1 } )
		).toEqual( { order: 1 } );
	} );
} );

describe( 'mergeSkipped', () => {
	it( 'collects the skipped keys across batches without duplicates', () => {
		expect(
			mergeSkipped( mergeSkipped( [], [ 'gizmo' ] ), [
				'gizmo',
				'order',
			] )
		).toEqual( [ 'gizmo', 'order' ] );
	} );

	it( 'ignores a missing or malformed value', () => {
		expect( mergeSkipped( [ 'gizmo' ], undefined ) ).toEqual( [ 'gizmo' ] );
		expect( mergeSkipped( [], [ 3, '', null, 'tag' ] ) ).toEqual( [
			'tag',
		] );
	} );
} );

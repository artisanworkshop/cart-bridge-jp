import { describe, expect, it } from '@jest/globals';
import type { EntityType, JobTotals, LimitEntity, Limits } from '../../types';
import {
	buildUpsellLineData,
	dryRunEntityTotals,
	sanitizeProUrl,
	type DryRunTotals,
} from '../upsell-breakdown';

const ENTITIES: EntityType[] = [
	'category',
	'tag',
	'product',
	'customer',
	'order',
	'stock',
	'coupon',
	'review',
];

/**
 * 無料版（`LimitPolicy::DEFAULT_LIMITS`）の `/limits` 応答。`overrides` で個別に上書きする。
 * @param overrides
 * @param unlocked  Pro 解除後（全エンティティの上限なし）の応答にする
 */
function makeLimits(
	overrides: Partial< Record< EntityType, Partial< LimitEntity > > > = {},
	unlocked = false
): Limits {
	const defaults: Record< EntityType, number | null > = {
		category: null,
		tag: null,
		product: 50,
		customer: 10,
		order: 10,
		stock: null,
		coupon: 10,
		review: null,
	};

	const entities = {} as Record< EntityType, LimitEntity >;

	for ( const entity of ENTITIES ) {
		const limit = unlocked ? null : defaults[ entity ];

		entities[ entity ] = {
			limit,
			unlocked: null === limit,
			used: 0,
			remaining: limit,
			...overrides[ entity ],
		};
	}

	return { unlocked, entities, pro_url: '' };
}

function makeTotals( overrides: Partial< JobTotals > ): JobTotals {
	return {
		total: 0,
		processed: 0,
		created: 0,
		updated: 0,
		skipped: 0,
		unchanged: 0,
		warned: 0,
		failed: 0,
		...overrides,
	};
}

describe( 'dryRunEntityTotals', () => {
	it( 'counts created, updated and unchanged items as migratable', () => {
		expect(
			dryRunEntityTotals(
				makeTotals( {
					processed: 10,
					created: 3,
					updated: 2,
					skipped: 5,
					unchanged: 1,
				} )
			)
		).toEqual( { processed: 10, migratable: 6 } );
	} );

	it( 'reports the breakdown as unknown for a job recorded before `unchanged` existed', () => {
		const totals = makeTotals( { processed: 7, created: 3 } );
		delete totals.unchanged;

		expect( dryRunEntityTotals( totals ) ).toEqual( {
			processed: 7,
			migratable: null,
		} );
	} );
} );

describe( 'buildUpsellLineData', () => {
	// issue #55 の実機 E2E: 7 件中 dry-run で移行できるのは 3 件（4 件は価格未設定等）。
	// サンプル移行で 2 件が作られた。上限（50）には届いていない。
	it( 'separates items not migrated yet from items that cannot be migrated', () => {
		const dryRunTotals: DryRunTotals = {
			product: { processed: 7, migratable: 3 },
		};

		expect(
			buildUpsellLineData(
				'product',
				makeLimits( { product: { used: 2 } } ),
				dryRunTotals
			)
		).toEqual( {
			entity: 'product',
			kind: 'breakdown',
			found: 7,
			migrated: 2,
			notMigrated: 1,
			blocked: 4,
		} );
	} );

	it( 'shows nothing when everything that can be migrated has been migrated', () => {
		expect(
			buildUpsellLineData(
				'product',
				makeLimits( { product: { used: 3 } } ),
				{ product: { processed: 7, migratable: 3 } }
			)
		).toBeNull();
	} );

	it( 'never reports a negative count when more items are linked than the preview can migrate', () => {
		expect(
			buildUpsellLineData(
				'customer',
				makeLimits( { customer: { used: 10 } } ),
				{ customer: { processed: 12, migratable: 8 } }
			)
		).toBeNull();
	} );

	it( 'falls back to the limit message when the breakdown is unknown and the limit is reached', () => {
		expect(
			buildUpsellLineData(
				'order',
				makeLimits( { order: { used: 10 } } ),
				{ order: { processed: 25, migratable: null } }
			)
		).toEqual( { entity: 'order', kind: 'limit_reached', limit: 10 } );
	} );

	it( 'falls back to the limit message when there is no preview', () => {
		expect(
			buildUpsellLineData(
				'coupon',
				makeLimits( { coupon: { used: 10 } } ),
				null
			)
		).toEqual( { entity: 'coupon', kind: 'limit_reached', limit: 10 } );
	} );

	it( 'shows nothing without a breakdown while the limit is not reached', () => {
		expect(
			buildUpsellLineData(
				'order',
				makeLimits( { order: { used: 4 } } ),
				{ order: { processed: 25, migratable: null } }
			)
		).toBeNull();
	} );

	it( 'does not break down stock, whose preview skips items until their products are migrated', () => {
		expect(
			buildUpsellLineData(
				'stock',
				makeLimits( { stock: { used: 5 } } ),
				{ stock: { processed: 30, migratable: 0 } }
			)
		).toEqual( {
			entity: 'stock',
			kind: 'dependent',
			found: 30,
			migrated: 5,
		} );
	} );

	it( 'shows nothing for stock once every previewed item is migrated', () => {
		expect(
			buildUpsellLineData(
				'stock',
				makeLimits( { stock: { used: 30 } } ),
				{ stock: { processed: 30, migratable: 30 } }
			)
		).toBeNull();
	} );

	it( 'shows nothing after the Pro version unlocks the limits', () => {
		const limits = makeLimits( { product: { used: 2 } }, true );

		expect(
			buildUpsellLineData( 'product', limits, {
				product: { processed: 7, migratable: 3 },
			} )
		).toBeNull();
		expect(
			buildUpsellLineData( 'stock', limits, {
				stock: { processed: 30, migratable: 0 },
			} )
		).toBeNull();
	} );

	it( 'shows nothing for entities without a limit', () => {
		expect(
			buildUpsellLineData( 'category', makeLimits(), {
				category: { processed: 40, migratable: 10 },
			} )
		).toBeNull();
	} );
} );

describe( 'sanitizeProUrl', () => {
	it.each( [
		[
			'https://example.com/pro?a=1&b=2',
			'https://example.com/pro?a=1&b=2',
		],
		[ 'http://example.com/pro', 'http://example.com/pro' ],
	] )( 'keeps %s', ( value, expected ) => {
		expect( sanitizeProUrl( value ) ).toBe( expected );
	} );

	it.each( [
		[ '' ],
		[ '   ' ],
		[ 'javascript:alert(1)' ],
		[ 'data:text/html,<b>x</b>' ],
		[ 'ftp://example.com/pro' ],
		[ '//example.com/pro' ],
		[ 'example.com/pro' ],
		[ null ],
		[ undefined ],
		[ 1 ],
		[ [ 'https://example.com/pro' ] ],
	] )( 'drops %p', ( value ) => {
		expect( sanitizeProUrl( value ) ).toBe( '' );
	} );
} );

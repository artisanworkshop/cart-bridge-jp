import { describe, expect, it } from '@jest/globals';
import type { MappingCandidate, SettingsMappings } from '../../types';
import {
	hasOrderMappingGaps,
	mappingCoverage,
	orderMappingStatus,
} from '../mapping-status';

function candidates( ...ids: string[] ): MappingCandidate[] {
	return ids.map( ( id ) => ( { id, name: `Name ${ id }` } ) );
}

/**
 * `GET /settings/mappings/{platform}` の応答。`overrides` で個別に上書きする。
 * @param overrides
 */
function makeMappings(
	overrides: Partial< SettingsMappings > = {}
): SettingsMappings {
	return {
		category_map: {},
		payment_map: {},
		shipping_map: {},
		status_map: {},
		asp_candidates: {
			category: [],
			payment: candidates( '101', '102', '103' ),
			shipping: candidates( '201', '202' ),
			status: candidates( 'pending', 'processing' ),
		},
		woo_candidates: {
			category: [],
			payment: candidates( 'bacs', 'cod' ),
			shipping: candidates( 'flat_rate:1', 'free_shipping:2' ),
			status: candidates( 'wc-pending', 'wc-processing' ),
		},
		...overrides,
	};
}

describe( 'mappingCoverage', () => {
	it( 'counts every source as unmapped when nothing is saved', () => {
		expect(
			mappingCoverage( candidates( 'a', 'b' ), candidates( 'x' ), {} )
		).toEqual( { total: 2, unmapped: 2 } );
	} );

	it( 'counts a source as mapped when its target exists', () => {
		expect(
			mappingCoverage( candidates( 'a', 'b' ), candidates( 'x', 'y' ), {
				a: 'x',
				b: 'y',
			} )
		).toEqual( { total: 2, unmapped: 0 } );
	} );

	it( 'treats a saved target missing from the WooCommerce options as unmapped', () => {
		// 無効化・削除されたゲートウェイや配送方法インスタンス。インポート時は未マッピング扱いになる。
		expect(
			mappingCoverage( candidates( 'a', 'b' ), candidates( 'x' ), {
				a: 'x',
				b: 'gone',
			} )
		).toEqual( { total: 2, unmapped: 1 } );
	} );

	it( 'treats an empty or non-string saved value as unmapped', () => {
		expect(
			mappingCoverage(
				candidates( 'a', 'b', 'c' ),
				candidates( 'x', '1' ),
				{
					a: '',
					b: 1,
					c: null,
				}
			)
		).toEqual( { total: 3, unmapped: 3 } );
	} );

	it( 'ignores saved entries whose source is not a current option', () => {
		// 削除済みの ASP 側の方法（候補に無い）は数えない（R3-0m の要検証。v1.0 では扱わない）。
		expect(
			mappingCoverage( candidates( 'a' ), candidates( 'x' ), {
				a: 'x',
				removed: 'x',
			} )
		).toEqual( { total: 1, unmapped: 0 } );
	} );

	it( 'counts duplicate source ids once', () => {
		expect(
			mappingCoverage( candidates( 'a', 'a', 'b' ), candidates( 'x' ), {
				a: 'x',
			} )
		).toEqual( { total: 2, unmapped: 1 } );
	} );

	it( 'does not read inherited properties as saved values', () => {
		// `map[ 'constructor' ]` は Object.prototype の関数を返すため、自前のキーだけを読む。
		expect(
			mappingCoverage(
				candidates( 'constructor', 'toString' ),
				candidates( 'x' ),
				{}
			)
		).toEqual( { total: 2, unmapped: 2 } );
		expect(
			mappingCoverage( candidates( 'constructor' ), candidates( 'x' ), {
				constructor: 'x',
			} )
		).toEqual( { total: 1, unmapped: 0 } );
	} );

	it( 'ignores string values inherited through the prototype chain', () => {
		// 型チェックだけでは、プロトタイプから継承した文字列（汚染された Object.prototype など）を設定済みと読んでしまう。
		const inherited = Object.create( { a: 'x' } ) as Record<
			string,
			string
		>;

		expect(
			mappingCoverage( candidates( 'a' ), candidates( 'x' ), inherited )
		).toEqual( { total: 1, unmapped: 1 } );
	} );

	it( 'does not treat an inherited target id as an existing option', () => {
		expect(
			mappingCoverage( candidates( 'a' ), candidates( 'x' ), {
				a: 'constructor',
			} )
		).toEqual( { total: 1, unmapped: 1 } );
	} );

	it( 'treats malformed inputs as empty', () => {
		expect( mappingCoverage( null, candidates( 'x' ), {} ) ).toEqual( {
			total: 0,
			unmapped: 0,
		} );
		expect(
			mappingCoverage(
				[
					null,
					5,
					{ id: '' },
					{ id: 7 },
					{ name: 'no id' },
					{ id: 'a' },
				],
				candidates( 'x' ),
				{ a: 'x' }
			)
		).toEqual( { total: 1, unmapped: 0 } );
		// マップが配列（PHP の空配列が `[]` で届いた等）・null でも落ちず、未設定として数える。
		expect(
			mappingCoverage( candidates( 'a' ), candidates( 'x' ), [ 'x' ] )
		).toEqual( { total: 1, unmapped: 1 } );
		expect(
			mappingCoverage( candidates( 'a' ), candidates( 'x' ), null )
		).toEqual( { total: 1, unmapped: 1 } );
		// 対応先の候補が壊れていれば、どの設定も実在を確かめられないので未マッピング。
		expect(
			mappingCoverage( candidates( 'a' ), 'broken', { a: 'x' } )
		).toEqual( { total: 1, unmapped: 1 } );
	} );
} );

describe( 'orderMappingStatus', () => {
	it( 'reports payment and shipping coverage separately', () => {
		const status = orderMappingStatus(
			makeMappings( {
				payment_map: { '101': 'bacs', '102': 'removed-gateway' },
				shipping_map: {
					'201': 'flat_rate:1',
					'202': 'free_shipping:2',
				},
			} )
		);

		expect( status ).toEqual( {
			payment: { total: 3, unmapped: 2 },
			shipping: { total: 2, unmapped: 0 },
		} );
		expect( hasOrderMappingGaps( status ) ).toBe( true );
	} );

	it( 'ignores the status and category maps', () => {
		const status = orderMappingStatus(
			makeMappings( {
				payment_map: {
					'101': 'bacs',
					'102': 'cod',
					'103': 'cod',
				},
				shipping_map: { '201': 'flat_rate:1', '202': 'flat_rate:1' },
				status_map: {},
			} )
		);

		expect( status ).toEqual( {
			payment: { total: 3, unmapped: 0 },
			shipping: { total: 2, unmapped: 0 },
		} );
		expect( hasOrderMappingGaps( status ) ).toBe( false );
	} );

	it( 'has no gaps when the platform options could not be loaded', () => {
		// 候補の取得に失敗すると REST は空の候補を返す。誤った警告を出さない。
		const status = orderMappingStatus(
			makeMappings( {
				asp_candidates: {
					category: [],
					payment: [],
					shipping: [],
					status: [],
				},
			} )
		);

		expect( status ).toEqual( {
			payment: { total: 0, unmapped: 0 },
			shipping: { total: 0, unmapped: 0 },
		} );
		expect( hasOrderMappingGaps( status ) ).toBe( false );
	} );

	it( 'reports a gap for shipping alone', () => {
		const status = orderMappingStatus(
			makeMappings( {
				payment_map: { '101': 'bacs', '102': 'bacs', '103': 'cod' },
			} )
		);

		expect( status.payment.unmapped ).toBe( 0 );
		expect( status.shipping.unmapped ).toBe( 2 );
		expect( hasOrderMappingGaps( status ) ).toBe( true );
	} );

	it( 'treats a missing or malformed response as having no gaps', () => {
		expect( hasOrderMappingGaps( orderMappingStatus( null ) ) ).toBe(
			false
		);
		expect( hasOrderMappingGaps( orderMappingStatus( {} ) ) ).toBe( false );
	} );
} );

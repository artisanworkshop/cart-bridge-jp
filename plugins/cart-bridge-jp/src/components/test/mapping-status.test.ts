import { describe, expect, it } from '@jest/globals';
import type {
	MappingCandidate,
	MappingKindInfo,
	SettingsMappings,
} from '../../types';
import {
	editableMaps,
	importMappingGaps,
	mappingCoverage,
	parseMappingKinds,
	savedMap,
} from '../mapping-status';

function candidates( ...ids: string[] ): MappingCandidate[] {
	return ids.map( ( id ) => ( { id, name: `Name ${ id }` } ) );
}

function kind(
	key: string,
	overrides: Partial< MappingKindInfo > = {}
): MappingKindInfo {
	return {
		key,
		map_key: `${ key }_map`,
		entity: 'order',
		source_side: 'asp',
		applies: true,
		import_notice: false,
		label: `${ key } mapping`,
		description: '',
		source_heading: `Platform ${ key }`,
		target_heading: `WooCommerce ${ key }`,
		unmapped_label: '— Unmapped —',
		no_targets_help: '',
		...overrides,
	};
}

/**
 * `GET /settings/mappings/{platform}` の応答（無料版の 4 種類。決済・配送が取込みの前の案内の対象）。`overrides` で個別に上書きする。
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
		kinds: [
			kind( 'category', { entity: 'product', source_side: 'woo' } ),
			kind( 'payment', { import_notice: true } ),
			kind( 'shipping', { import_notice: true } ),
			kind( 'status' ),
		],
		...overrides,
	};
}

const ORDERS = new Set( [ 'order' ] );

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

describe( 'importMappingGaps', () => {
	it( 'reports the unmapped notice kinds of the selected entities', () => {
		const gaps = importMappingGaps(
			makeMappings( {
				payment_map: { '101': 'bacs', '102': 'removed-gateway' },
				shipping_map: {
					'201': 'flat_rate:1',
					'202': 'free_shipping:2',
				},
			} ),
			ORDERS
		);

		expect( gaps ).toEqual( [
			{
				key: 'payment',
				heading: 'Platform payment',
				total: 3,
				unmapped: 2,
			},
		] );
	} );

	it( 'ignores kinds without the notice, and kinds of entities not selected', () => {
		// 注文ステータスは未設定でも既定のステータスに落ちるので案内しない。選んでいない種類のマッピングも数えない。
		expect(
			importMappingGaps( makeMappings(), new Set( [ 'product' ] ) )
		).toEqual( [] );
		expect(
			importMappingGaps(
				makeMappings( {
					payment_map: {
						'101': 'bacs',
						'102': 'cod',
						'103': 'cod',
					},
					shipping_map: {
						'201': 'flat_rate:1',
						'202': 'flat_rate:1',
					},
				} ),
				ORDERS
			)
		).toEqual( [] );
	} );

	it( 'reports a gap for shipping alone', () => {
		const gaps = importMappingGaps(
			makeMappings( {
				payment_map: { '101': 'bacs', '102': 'bacs', '103': 'cod' },
			} ),
			ORDERS
		);

		expect( gaps.map( ( gap ) => [ gap.key, gap.unmapped ] ) ).toEqual( [
			[ 'shipping', 2 ],
		] );
	} );

	it( 'has no gaps when the platform options could not be loaded', () => {
		// 候補の取得に失敗すると REST は空の候補を返す。誤った警告を出さない。
		expect(
			importMappingGaps(
				makeMappings( {
					asp_candidates: {
						category: [],
						payment: [],
						shipping: [],
						status: [],
					},
				} ),
				ORDERS
			)
		).toEqual( [] );
	} );

	it( 'counts a kind keyed on the WooCommerce side from the WooCommerce options', () => {
		const data = makeMappings( {
			woo_candidates: {
				category: candidates( '5', '6' ),
				payment: [],
				shipping: [],
				status: [],
			},
			asp_candidates: {
				category: candidates( '90' ),
				payment: [],
				shipping: [],
				status: [],
			},
			category_map: { '5': '90' },
			kinds: [
				kind( 'category', {
					entity: 'product',
					source_side: 'woo',
					import_notice: true,
				} ),
			],
		} );

		expect( importMappingGaps( data, new Set( [ 'product' ] ) ) ).toEqual( [
			{
				key: 'category',
				heading: 'Platform category',
				total: 2,
				unmapped: 1,
			},
		] );
	} );

	it( 'skips kinds that do not apply or are malformed', () => {
		const data = makeMappings( {
			kinds: [
				kind( 'payment', { import_notice: true, applies: false } ),
				// 真偽値は true だけを真と読む（ページの外の値。原則 9）。
				{
					...kind( 'shipping' ),
					import_notice: 'true',
				} as unknown as MappingKindInfo,
				{ key: 'broken' } as unknown as MappingKindInfo,
			],
		} );

		expect( importMappingGaps( data, ORDERS ) ).toEqual( [] );
	} );

	it( 'falls back to the label or key when the heading is missing', () => {
		const data = makeMappings( {
			kinds: [
				kind( 'payment', { import_notice: true, source_heading: '' } ),
				kind( 'shipping', {
					import_notice: true,
					source_heading: '',
					label: '',
				} ),
			],
		} );

		expect(
			importMappingGaps( data, ORDERS ).map( ( gap ) => gap.heading )
		).toEqual( [ 'payment mapping', 'shipping' ] );
	} );

	it( 'treats a missing or malformed response as having no gaps', () => {
		expect( importMappingGaps( null, ORDERS ) ).toEqual( [] );
		expect( importMappingGaps( {}, ORDERS ) ).toEqual( [] );
		expect( importMappingGaps( { kinds: 'x' }, ORDERS ) ).toEqual( [] );
	} );
} );

describe( 'parseMappingKinds', () => {
	it( 'reads the declared kinds in order', () => {
		const kinds = parseMappingKinds( makeMappings().kinds );

		expect( kinds.map( ( item ) => item.key ) ).toEqual( [
			'category',
			'payment',
			'shipping',
			'status',
		] );
		expect( kinds[ 0 ] ).toEqual(
			kind( 'category', { entity: 'product', source_side: 'woo' } )
		);
	} );

	it( 'drops malformed and duplicate kinds and fails closed on flags and texts', () => {
		const kinds = parseMappingKinds( [
			null,
			{ map_key: 'x_map' },
			{ key: 'nokey' },
			{
				key: 'payment',
				map_key: 'payment_map',
				applies: 'true',
				import_notice: 1,
				source_side: 'sideways',
				label: 5,
			},
			// 同じキー・同じ map_key の 2 つ目以降は捨てる。
			{ key: 'payment', map_key: 'other_map', applies: true },
			{ key: 'other', map_key: 'payment_map', applies: true },
		] );

		expect( kinds ).toEqual( [
			{
				key: 'payment',
				map_key: 'payment_map',
				entity: '',
				source_side: 'asp',
				applies: false,
				import_notice: false,
				label: '',
				description: '',
				source_heading: '',
				target_heading: '',
				unmapped_label: '',
				no_targets_help: '',
			},
		] );
		expect( parseMappingKinds( 'x' ) ).toEqual( [] );
	} );
} );

describe( 'savedMap', () => {
	it( 'copies only the string values the response saved', () => {
		const map = savedMap(
			{ payment_map: { '101': 'bacs', '102': 3, '103': null } },
			'payment_map'
		);

		expect( map ).toEqual( { '101': 'bacs' } );
		expect( savedMap( { payment_map: [ 'x' ] }, 'payment_map' ) ).toEqual(
			{}
		);
		expect( savedMap( null, 'payment_map' ) ).toEqual( {} );
	} );
} );

describe( 'editableMaps', () => {
	it( 'keeps the saved maps of kinds whose section is not shown', () => {
		// カテゴリを作れる接続先ではカテゴリの節を出さないが、保存（PUT）で送り返して保存済みの値を消さない。
		const data = makeMappings( {
			category_map: { '5': '90' },
			payment_map: { '101': 'bacs' },
		} );
		const kinds = parseMappingKinds( [
			kind( 'category', {
				entity: 'product',
				source_side: 'woo',
				applies: false,
			} ),
			kind( 'payment' ),
		] );

		expect( editableMaps( data, kinds ) ).toEqual( {
			category_map: { '5': '90' },
			payment_map: { '101': 'bacs' },
		} );
	} );
} );

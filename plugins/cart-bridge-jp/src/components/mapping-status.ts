import type { MappingCandidate, MappingKindInfo } from '../types';

/**
 * 1 種類のマッピングの設定状況。`total` は行に並べる側の候補（一意な ID）の数、`unmapped` はそのうち使える設定が無いものの数。
 */
export interface MappingCoverage {
	total: number;
	unmapped: number;
}

/**
 * Import タブの事前チェック（R3-0m。R3-6b2 で種類の宣言から数える形にした）で案内する 1 種類。
 */
export interface MappingGap extends MappingCoverage {
	key: string;
	/** 画面に出す名前（その種類の行の列見出し。例: `Platform payment method`）。 */
	heading: string;
}

/**
 * REST の応答（信頼境界の外）から候補の ID を取り出す。配列でない値や、`id` が空でない文字列でない要素は
 * 読み飛ばす（サーバーは正規化して返すが、壊れた応答で画面全体を落とさないため）。
 * @param value
 */
function candidateIds( value: unknown ): string[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	const ids: string[] = [];

	for ( const item of value as unknown[] ) {
		const id = ( item as Partial< MappingCandidate > | null )?.id;

		if ( 'string' === typeof id && '' !== id ) {
			ids.push( id );
		}
	}

	return ids;
}

/**
 * マップの値を取り出す。`map[ id ]` のように読むと、`constructor` などの ID が `Object.prototype` の
 * 継承プロパティを拾ってしまうため、自前のキーだけを読む。値が空でない文字列でなければ未設定とみなす。
 * @param map
 * @param key
 */
function ownMappedValue( map: unknown, key: string ): string | null {
	if ( null === map || 'object' !== typeof map || Array.isArray( map ) ) {
		return null;
	}

	if ( ! Object.prototype.hasOwnProperty.call( map, key ) ) {
		return null;
	}

	const value = ( map as Record< string, unknown > )[ key ];

	return 'string' === typeof value && '' !== value ? value : null;
}

/**
 * 行に並べる側の候補（決済方法なら ASP 側）ごとに、使える設定があるかを数える。設定済みと数えるのは、マップに値があり、その値が
 * 現在の対応先の候補に存在するときだけ。候補に無い値（無効化・削除されたゲートウェイや
 * 配送方法インスタンス）は、インポート時に `Woo\Writer\OrderWriter` が実在チェックで未マッピング扱いに
 * 倒すため、ここでも未マッピングとして数える（フェイルクローズ）。
 * 例外として、配送の値が方式だけの ID（例: `flat_rate`。REST へ直接 PUT したときだけ保存されうる）は、
 * `MethodMap::shipping_method_exists()` は方式が登録されていれば使える値として受け付けるが、Woo 側の候補は
 * `method:instance` 形式しか無いのでここでは未マッピングと数える（案内が出る側の食い違いで、安全側）。
 * @param sources 行に並べる側の候補
 * @param targets 対応先の候補
 * @param map     保存済みのマップ（行の ID → 対応先の ID）
 */
export function mappingCoverage(
	sources: unknown,
	targets: unknown,
	map: unknown
): MappingCoverage {
	const sourceIds = Array.from( new Set( candidateIds( sources ) ) );
	const targetIds = new Set( candidateIds( targets ) );
	let unmapped = 0;

	for ( const id of sourceIds ) {
		const mapped = ownMappedValue( map, id );

		if ( null === mapped || ! targetIds.has( mapped ) ) {
			unmapped++;
		}
	}

	return { total: sourceIds.length, unmapped };
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		'object' === typeof value && null !== value && ! Array.isArray( value )
	);
}

function ownValue( record: unknown, key: string ): unknown {
	return isRecord( record ) &&
		Object.prototype.hasOwnProperty.call( record, key )
		? record[ key ]
		: undefined;
}

function nonEmptyString( value: unknown ): string | null {
	return 'string' === typeof value && '' !== value ? value : null;
}

function stringOr( value: unknown, fallback: string ): string {
	return 'string' === typeof value ? value : fallback;
}

/**
 * `GET /settings/mappings/{platform}` の `kinds`（ページの外の値）を読む。キー・`map_key` が空でない文字列でない項目と、
 * 同じキー・`map_key` の 2 つ目以降は捨てる。真偽値は `true` だけを真と読み（原則 9。`applies` が偽なら節を出さない）、
 * 文言は文字列でなければ空にする。
 * @param value 応答の `kinds`
 */
export function parseMappingKinds( value: unknown ): MappingKindInfo[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	const kinds: MappingKindInfo[] = [];
	const seen = new Set< string >();

	for ( const item of value as unknown[] ) {
		const key = nonEmptyString( ownValue( item, 'key' ) );
		const mapKey = nonEmptyString( ownValue( item, 'map_key' ) );

		if (
			null === key ||
			null === mapKey ||
			seen.has( `key:${ key }` ) ||
			seen.has( `map:${ mapKey }` )
		) {
			continue;
		}

		seen.add( `key:${ key }` );
		seen.add( `map:${ mapKey }` );

		kinds.push( {
			key,
			map_key: mapKey,
			entity: stringOr( ownValue( item, 'entity' ), '' ),
			source_side:
				'woo' === ownValue( item, 'source_side' ) ? 'woo' : 'asp',
			applies: true === ownValue( item, 'applies' ),
			import_notice: true === ownValue( item, 'import_notice' ),
			label: stringOr( ownValue( item, 'label' ), '' ),
			description: stringOr( ownValue( item, 'description' ), '' ),
			source_heading: stringOr( ownValue( item, 'source_heading' ), '' ),
			target_heading: stringOr( ownValue( item, 'target_heading' ), '' ),
			unmapped_label: stringOr( ownValue( item, 'unmapped_label' ), '' ),
			no_targets_help: stringOr(
				ownValue( item, 'no_targets_help' ),
				''
			),
		} );
	}

	return kinds;
}

/**
 * 保存済みのマップ（`{key}_map`）を、自前のキーで値が文字列の項目だけの新しいオブジェクトとして読む（形の違う値は空のマップ）。
 * @param data   応答
 * @param mapKey マップのキー
 */
export function savedMap(
	data: unknown,
	mapKey: string
): Record< string, string > {
	const map = ownValue( data, mapKey );
	const copy: Record< string, string > = {};

	if ( ! isRecord( map ) ) {
		return copy;
	}

	for ( const [ source, target ] of Object.entries( map ) ) {
		if ( 'string' === typeof target ) {
			copy[ source ] = target;
		}
	}

	return copy;
}

/**
 * `GET /settings/mappings/{platform}` の応答から、選ばれた実体の種類が持つ「取込みの前に案内するマッピング」（`kinds` のうち
 * `import_notice` と `applies` が真のもの）の設定状況を数え、未設定があるものだけを返す。応答はページの外の値なので、形の違う
 * 種類は読み飛ばす（`parseMappingKinds()`）。ASP 側の候補が 0 件（取得に失敗したときも REST は空の候補を返す）なら
 * 何も数えないので、誤った警告を出さない。
 * @param data     応答
 * @param selected 選ばれた実体の種類のキー
 */
export function importMappingGaps(
	data: unknown,
	selected: ReadonlySet< string >
): MappingGap[] {
	const gaps: MappingGap[] = [];

	for ( const kind of parseMappingKinds( ownValue( data, 'kinds' ) ) ) {
		if (
			! kind.import_notice ||
			! kind.applies ||
			! selected.has( kind.entity )
		) {
			continue;
		}

		const asp = ownValue( ownValue( data, 'asp_candidates' ), kind.key );
		const woo = ownValue( ownValue( data, 'woo_candidates' ), kind.key );
		const wooSide = 'woo' === kind.source_side;
		const coverage = mappingCoverage(
			wooSide ? woo : asp,
			wooSide ? asp : woo,
			ownValue( data, kind.map_key )
		);

		if ( coverage.unmapped > 0 ) {
			gaps.push( {
				key: kind.key,
				heading:
					nonEmptyString( kind.source_heading ) ??
					nonEmptyString( kind.label ) ??
					kind.key,
				...coverage,
			} );
		}
	}

	return gaps;
}

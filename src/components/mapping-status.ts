import type { MappingCandidate, SettingsMappings } from '../types';

/**
 * 1 種類のマッピング（決済方法・配送方法）の設定状況。`total` は ASP 側の候補（一意な ID）の数、
 * `unmapped` はそのうち使える設定が無いものの数。
 */
export interface MappingCoverage {
	total: number;
	unmapped: number;
}

/**
 * 受注のインポートで使う 2 種類のマッピングの設定状況（R3-0m の Import タブの事前チェック）。
 * `status_map` は未設定でも canonical の既定のステータスに落ちて警告にならないため含めない。
 * `category_map` はエクスポート方向（Woo → ASP）のため含めない。
 */
export interface OrderMappingStatus {
	payment: MappingCoverage;
	shipping: MappingCoverage;
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
 * ASP 側の候補ごとに、使える設定があるかを数える。設定済みと数えるのは、マップに値があり、その値が
 * 現在の WooCommerce 側の候補に存在するときだけ。候補に無い値（無効化・削除されたゲートウェイや
 * 配送方法インスタンス）は、インポート時に `Woo\Writer\OrderWriter` が実在チェックで未マッピング扱いに
 * 倒すため、ここでも未マッピングとして数える（フェイルクローズ）。
 * 例外として、配送の値が方式だけの ID（例: `flat_rate`。REST へ直接 PUT したときだけ保存されうる）は、
 * `MethodMap::shipping_method_exists()` は方式が登録されていれば使える値として受け付けるが、Woo 側の候補は
 * `method:instance` 形式しか無いのでここでは未マッピングと数える（案内が出る側の食い違いで、安全側）。
 * @param sources ASP 側の候補
 * @param targets WooCommerce 側の候補
 * @param map     保存済みのマップ（ASP 側 ID → WooCommerce 側 ID）
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

/**
 * `GET /settings/mappings/{platform}` の応答から、受注のインポートに効く決済・配送マッピングの設定状況を返す。
 * @param data
 */
export function orderMappingStatus(
	data: Partial< SettingsMappings > | null | undefined
): OrderMappingStatus {
	const asp = data?.asp_candidates;
	const woo = data?.woo_candidates;

	return {
		payment: mappingCoverage(
			asp?.payment,
			woo?.payment,
			data?.payment_map
		),
		shipping: mappingCoverage(
			asp?.shipping,
			woo?.shipping,
			data?.shipping_map
		),
	};
}

/**
 * 案内を出すべき未マッピングがあるか。ASP 側の候補が 0 件（取得に失敗したときも REST は空の候補を返す）なら
 * 何も数えないので false になり、誤った警告を出さない。
 * @param status
 */
export function hasOrderMappingGaps( status: OrderMappingStatus ): boolean {
	return status.payment.unmapped > 0 || status.shipping.unmapped > 0;
}

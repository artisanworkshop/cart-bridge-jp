import type { VerificationEntity } from '../types';

/**
 * ASP側（この run で取得した全件）と Woo側（リンク済みで実在する全件）はスコープが違う
 * （後者はプラットフォーム全体・全期間）ため、単なる一致/不一致ではなく差の向きを返す:
 * - `unknown`: Woo 側の実体を確かめられない（その種類がこのサイトに登録されていない〔Pro アドオンを止めた後の過去の受注など。R3-6c1〕か、
 *   確かめるときに失敗した）
 * - `missing`: mapping はあるが Woo 側の実体が無い（要 Rebuild links / 再 import）
 * - `fewer`: 取得件数より Woo 側が少ない（スキップ・警告）
 * - `more`: 取得件数より Woo 側が多い（過去の run で取り込んだ分。ASP 側で減った場合など）
 * - `amount`: 件数は一致するが合計金額が一致しない（金額を突合する種類〈受注など〉だけ）
 * - `reconciled`: 件数・金額とも一致し missing も無い
 */
export type RowStatus =
	| 'reconciled'
	| 'unknown'
	| 'missing'
	| 'fewer'
	| 'more'
	| 'amount';

export function rowStatus(
	row: VerificationEntity,
	amountsComparable: boolean
): RowStatus {
	// 確かめられない行を「Woo 側が少ない」と読まない（null は数値の比較で 0 として扱われる）。
	if ( null === row.existing || null === row.missing ) {
		return 'unknown';
	}

	if ( row.missing > 0 ) {
		return 'missing';
	}

	if ( row.existing < row.processed ) {
		return 'fewer';
	}

	if ( row.existing > row.processed ) {
		return 'more';
	}

	if (
		amountsComparable &&
		null !== row.remote_amount &&
		row.remote_amount !== row.local_amount
	) {
		return 'amount';
	}

	return 'reconciled';
}

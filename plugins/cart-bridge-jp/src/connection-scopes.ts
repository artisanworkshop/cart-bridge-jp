import type { Connection } from './types';

/**
 * 接続済みのトークンに付与されていない、要求するスコープ（`GET /connections` の `missing_scopes`。R3-6c2）を読む純粋関数。
 * 空でなければ画面が再接続を促す。応答はページの外の値なので、`connected` が `true` でない接続・配列でない値は空に読み、
 * 空でない文字列だけを重複なく拾う（案内は付加情報。可否はサーバーが決める）。
 * @param connection 接続（`GET /connections` の 1 項目）
 */
export function missingScopes( connection: Connection | null ): string[] {
	if (
		'object' !== typeof connection ||
		null === connection ||
		true !== connection.connected ||
		! Object.prototype.hasOwnProperty.call( connection, 'missing_scopes' )
	) {
		return [];
	}

	const list: unknown = connection.missing_scopes;

	if ( ! Array.isArray( list ) ) {
		return [];
	}

	return [
		...new Set(
			list.filter(
				( scope ): scope is string =>
					'string' === typeof scope && '' !== scope
			)
		),
	];
}

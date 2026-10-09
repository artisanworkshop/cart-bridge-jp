/**
 * リンク再構築（`POST /tools/rebuild-mappings`）のバッチごとの応答をまとめる純粋関数（Tools タブ。R3-6b2）。応答はページの外の値なので、
 * 形の違う値は読み飛ばす。
 */

export type Counts = Record< string, number >;

/**
 * 件数を前のバッチの分に足す。キーの順は最初に現れた順（サーバーの走査順。Tools タブはこの順で並べる）。数でない値は数えない。
 * @param into
 * @param add
 */
export function mergeCounts( into: Counts, add: unknown ): Counts {
	const merged = { ...into };

	if ( null === add || 'object' !== typeof add || Array.isArray( add ) ) {
		return merged;
	}

	for ( const [ key, value ] of Object.entries( add ) ) {
		if ( 'number' === typeof value && Number.isFinite( value ) ) {
			// 種類のキーは `constructor` などもありうる（`^[a-z][a-z0-9_]{0,19}$`）。継承プロパティを前の件数と読まないよう、自前のものだけ足す
			// （PR #116 G1-2）。書き込みも `__proto__` に当たらない形（登録キーの形に `_` 始まりは無い）。
			const previous = Object.prototype.hasOwnProperty.call( merged, key )
				? merged[ key ]
				: 0;

			merged[ key ] = previous + value;
		}
	}

	return merged;
}

/**
 * 応答の `skipped`（走査に失敗して飛ばした種類のキー）を、重複を除いて前のバッチの分に足す。
 * @param into
 * @param value
 */
export function mergeSkipped( into: string[], value: unknown ): string[] {
	if ( ! Array.isArray( value ) ) {
		return into;
	}

	const merged = [ ...into ];

	for ( const key of value as unknown[] ) {
		if (
			'string' === typeof key &&
			'' !== key &&
			! merged.includes( key )
		) {
			merged.push( key );
		}
	}

	return merged;
}

/**
 * 再構築を始めるときの「飛ばした種類」の一覧。続きから再開する（`cursor` がある）ときは、前の実行で集めた分を引き継ぐ: サーバーは
 * `skipped` をバッチごとにしか返さないので、捨てると上限やエラーで止まる前のバッチの走査の失敗が、再開後の警告から消える
 * （PR #116 G2-B1）。最初から始めるときだけ空にする。
 * @param cursor   再開する位置（最初からなら null）
 * @param previous これまでに集めた一覧
 */
export function skippedAtStart(
	cursor: string | null,
	previous: string[]
): string[] {
	return null === cursor ? [] : previous;
}

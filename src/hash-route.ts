/**
 * 管理画面のタブのハッシュ（`#/mappings` や `#/mappings?platform=colorme`）の組み立てと解析（R3-0m）。
 * `platform` は、別のタブから来たときに選択中のプラットフォームを引き継ぐためのもの（Import タブの案内から
 * Mappings タブへ移ったとき、複数接続していても同じプラットフォームの設定を開く）。受け取る側は、接続済みの
 * プラットフォームに一致するときだけ使う（任意の文字列がハッシュに入りうるため）。
 */
export interface HashRoute {
	tab: string;
	platform: string | null;
}

/**
 * @param hash `window.location.hash`（先頭の `#` を含む。空でもよい）
 */
export function parseHash( hash: string ): HashRoute {
	const path = hash.replace( /^#\/?/, '' );
	const queryStart = path.indexOf( '?' );
	const tab = -1 === queryStart ? path : path.slice( 0, queryStart );
	let platform: string | null = null;

	if ( -1 !== queryStart ) {
		const value = new URLSearchParams( path.slice( queryStart + 1 ) ).get(
			'platform'
		);

		platform = null !== value && '' !== value ? value : null;
	}

	return { tab, platform };
}

/**
 * @param tab      タブの ID（`App.tsx` の `TABS`）
 * @param platform 引き継ぐプラットフォーム（null・空なら付けない）
 */
export function tabHref( tab: string, platform?: string | null ): string {
	if ( null === platform || undefined === platform || '' === platform ) {
		return `#/${ tab }`;
	}

	return `#/${ tab }?${ new URLSearchParams( { platform } ).toString() }`;
}

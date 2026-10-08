import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * 翻訳済みの文を 2 つ連結する。半角スペースを直に挟むと日本語訳で「。 」のように不自然な空白が入るため、
 * 区切りは翻訳者が決められるよう 1 つの文字列（`%1$s %2$s`）にする。3 つ以上は入れ子にする。
 * @param first
 * @param second
 */
export function joinSentences( first: string, second: string ): string {
	return sprintf(
		/* translators: 1: a sentence, 2: the sentence that follows it. Languages that do not separate sentences with a space can drop the space. */
		__( '%1$s %2$s', 'cart-bridge-jp' ),
		first,
		second
	);
}

/**
 * 日時・金額の書式に使う言語。`toLocaleString()`・`Intl.NumberFormat( undefined )` はブラウザの言語になり、
 * WordPress のユーザーの言語（翻訳と同じ言語）と食い違うため、`Admin\Assets::enqueue()` が渡す `cbjpAdmin.locale`
 * （`determine_locale()` の `_` を `-` にした値）を使う。無い・BCP 47 として不正（`pt-PT-ao90` 等）なら undefined
 * （ブラウザの既定）へ倒す。
 */
export function displayLocale(): string | undefined {
	const locale = window.cbjpAdmin?.locale;

	if ( ! locale ) {
		return undefined;
	}

	try {
		return Intl.getCanonicalLocales( locale )[ 0 ];
	} catch {
		return undefined;
	}
}

/**
 * 翻訳済みの語を並べる。`join( ', ' )` だと日本語訳でも区切りが「, 」のまま残るため、区切りを翻訳できる書式（`%1$s, %2$s`）にして
 * 先頭から順に重ねる（区切りだけの msgid は前後の空白を `@wordpress/i18n-no-flanking-whitespace` が拒む）。
 * @param items
 */
export function joinList( items: string[] ): string {
	if ( 0 === items.length ) {
		return '';
	}

	return items.reduce( ( list, item ) =>
		sprintf(
			/* translators: 1: the items of a list so far, 2: the next item. Example: "Products, Customers". */
			_x( '%1$s, %2$s', 'list of items', 'cart-bridge-jp' ),
			list,
			item
		)
	);
}

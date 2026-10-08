<?php
/**
 * `bin/i18n.sh check` が `wp eval-file bin/i18n-check.php <作り直した POT> <コミット済みの POT>` で呼ぶ（R3-2）。
 *
 * 2 つの POT の文字列が同じかを比べ、違えば一覧を出して非ゼロで終わる。比べるのは msgctxt・msgid・msgid_plural と、
 * JS（`build/`）から参照されるか（JS と PHP の間で移ると、管理画面の JSON に入る文字列が変わるため）。行番号・翻訳者コメント・
 * ヘッダ（作成日時）は比べない（コードを動かすたびに変わり、翻訳には影響しない）。
 *
 * @package CartBridgeJP
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * POT の文字列を「キー => JS から参照されるか」で返す。キーは msgctxt・msgid・msgid_plural をつないだもの。
 *
 * @return array<string,bool>
 */
function cbjp_i18n_pot_strings( string $path ): array {
	if ( ! is_readable( $path ) ) {
		WP_CLI::error( "Cannot read {$path}." );
	}

	require_once ABSPATH . WPINC . '/pomo/po.php';

	$po = new PO();

	if ( ! $po->import_from_file( $path ) ) {
		WP_CLI::error( "Cannot parse {$path}." );
	}

	$strings = [];

	foreach ( $po->entries as $entry ) {
		$key = (string) $entry->context . "\4" . $entry->singular . "\0" . (string) $entry->plural;

		$strings[ $key ] = [] !== array_filter(
			$entry->references,
			static fn ( string $reference ): bool => 1 === preg_match( '#^build/.+\.js(?::\d+)?$#', $reference )
		);
	}

	return $strings;
}

/**
 * キーを人が読める 1 行にする（msgctxt は角括弧、msgid_plural は「|」の後ろ）。
 */
function cbjp_i18n_describe( string $key, bool $js ): string {
	[ $context, $rest ]    = explode( "\4", $key, 2 );
	[ $singular, $plural ] = explode( "\0", $rest, 2 );

	return ( '' !== $context ? "[{$context}] " : '' ) . $singular . ( '' !== $plural ? " | {$plural}" : '' ) . ( $js ? ' (JS)' : ' (PHP)' );
}

/**
 * @param array<int,string> $args `wp eval-file` の引数（作り直した POT、コミット済みの POT）。
 */
function cbjp_i18n_check( array $args ): void {
	if ( 2 !== count( $args ) ) {
		WP_CLI::error( 'Usage: wp eval-file bin/i18n-check.php <fresh.pot> <committed.pot>' );
	}

	$fresh     = cbjp_i18n_pot_strings( $args[0] );
	$committed = cbjp_i18n_pot_strings( $args[1] );
	$problems  = [];

	foreach ( $fresh as $key => $js ) {
		if ( ! array_key_exists( $key, $committed ) ) {
			$problems[] = '+ ' . cbjp_i18n_describe( $key, $js );
		} elseif ( $committed[ $key ] !== $js ) {
			$problems[] = '~ ' . cbjp_i18n_describe( $key, $js ) . ' (moved between PHP and JS)';
		}
	}

	foreach ( $committed as $key => $js ) {
		if ( ! array_key_exists( $key, $fresh ) ) {
			$problems[] = '- ' . cbjp_i18n_describe( $key, $js );
		}
	}

	if ( [] !== $problems ) {
		WP_CLI::log( implode( "\n", $problems ) );
		WP_CLI::error(
			sprintf(
				'%d string(s) differ from %s. Run: npm run i18n:pot && npm run i18n:po, translate the new strings, then npm run i18n:compile.',
				count( $problems ),
				$args[1]
			)
		);
	}

	WP_CLI::success( sprintf( '%s is up to date (%d strings).', $args[1], count( $committed ) ) );
}

cbjp_i18n_check( $args ?? [] );

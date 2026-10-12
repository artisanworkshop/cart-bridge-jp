<?php
/**
 * measure-api-manager: 実測した応答の匿名化と、Pro のフィクスチャとの比較（WordPress を使わない。`php/test-anon.php` がホストの php で確かめる）。
 *
 * 匿名化の規則（R3-8c で手で行ったものと同じ。フィクスチャの README の規則）:
 * - サイトの URL（`http://localhost:10010` など）は `https://example.com`
 * - 製品 ID は 1000、注文 ID は 1001・1002…（作った順）、リソース ID は 1・2…、顧客の user_id は 1、存在しない製品 ID は 2000
 * - ダウンロードの URL の `hname`・`hkey` は x の並び
 * - キー・instance・マスターキー（`secrets`）は `<redacted>`（応答には含まれないはずだが、含まれたら残さない）
 *
 * 比較では、実行時間・日付・期限のタイムスタンプ・版（`new_version`・`version`。setup の `--new-version` で変わる）を比べない。
 *
 * @package CartBridgeJP
 */

// 読み込みは require_once で 1 回だけにする（関数はファイルを読んだ時点で宣言されるので、function_exists() の守りでは
// 後ろの定数の定義まで飛ばしてしまう）。

/** 存在しない製品 ID として要求に使う値（measure.php）と、フィクスチャでの値。 */
const CBJP_AM_UNKNOWN_PRODUCT_ID         = 99999;
const CBJP_AM_UNKNOWN_PRODUCT_ID_FIXTURE = 2000;

/**
 * Pro のフィクスチャ（`tests/fixtures/api-manager/<名前>.json`）と、それを作る実測の名前（`measure.php` の出力 `<名前>.json`）。
 *
 * @return array<string,string>
 */
function cbjp_am_fixture_map(): array {
	return [
		'activate_success'                 => 'activate_success',
		'activate_error_already_activated' => 'activate_again_same_key',
		'activate_error_no_resources'      => 'activate_unknown_product',
		'activate_error_unknown_key'       => 'activate_unknown_key',
		'deactivate_success'               => 'deactivate_success',
		'deactivate_error'                 => 'deactivate_again',
		'status_active'                    => 'status_active',
		'status_inactive'                  => 'status_after_deactivate',
		'status_expired'                   => 'status_expired',
		'update_active'                    => 'update_active',
		'update_without_package'           => 'update_expired',
		'update_error_unknown_product'     => 'update_unknown_product',
		'information_active'               => 'information_active',
	];
}

/**
 * 匿名化に使う値を確かめて返す。足りない・形の違う値があれば null（匿名化しないで止める）。
 *
 * @param mixed $ctx `site_url`（string）・`product_id`（int）・`order_ids`・`resource_ids`（int の list）・`user_id`（int）・`secrets`（string の list）。
 * @return array{site_url:string,product_id:int,order_ids:list<int>,resource_ids:list<int>,user_id:int,secrets:list<string>}|null
 */
function cbjp_am_anon_context( $ctx ): ?array {
	if ( ! is_array( $ctx ) || ! is_string( $ctx['site_url'] ?? null ) || 1 !== preg_match( '#\Ahttps?://[a-z0-9.-]+(:[0-9]+)?\z#', $ctx['site_url'] ) ) {
		return null;
	}

	foreach ( [ 'product_id', 'user_id' ] as $key ) {
		if ( ! is_int( $ctx[ $key ] ?? null ) || $ctx[ $key ] <= 0 ) {
			return null;
		}
	}

	foreach ( [ 'order_ids', 'resource_ids' ] as $key ) {
		if ( ! is_array( $ctx[ $key ] ?? null ) || [] === $ctx[ $key ] || ! array_is_list( $ctx[ $key ] ) ) {
			return null;
		}

		foreach ( $ctx[ $key ] as $id ) {
			if ( ! is_int( $id ) || $id <= 0 ) {
				return null;
			}
		}
	}

	if ( ! is_array( $ctx['secrets'] ?? null ) || ! array_is_list( $ctx['secrets'] ) ) {
		return null;
	}

	foreach ( $ctx['secrets'] as $secret ) {
		// 短い値は応答の普通の文字列にも一致してしまうので、秘密として扱えない（setup が作るキー・instance は 32 文字以上）。
		if ( ! is_string( $secret ) || strlen( $secret ) < 16 ) {
			return null;
		}
	}

	return [
		'site_url'     => $ctx['site_url'],
		'product_id'   => $ctx['product_id'],
		'order_ids'    => $ctx['order_ids'],
		'resource_ids' => $ctx['resource_ids'],
		'user_id'      => $ctx['user_id'],
		'secrets'      => $ctx['secrets'],
	];
}

/**
 * 応答（json_decode した値）を匿名化する。
 *
 * @param mixed                $value 応答の値。
 * @param array<string,mixed>  $ctx   `cbjp_am_anon_context()` の戻り値。
 * @param string               $key   親の配列での $value のキー（ID の欄を見分ける）。
 * @return mixed
 */
function cbjp_am_anonymize( $value, array $ctx, string $key = '' ) {
	if ( is_array( $value ) ) {
		$out = [];

		foreach ( $value as $k => $v ) {
			$out[ $k ] = cbjp_am_anonymize( $v, $ctx, (string) $k );
		}

		return $out;
	}

	if ( is_int( $value ) && in_array( $key, [ 'product_id', 'order_id', 'api_resource_id', 'user_id' ], true ) ) {
		return (int) cbjp_am_anon_id( (string) $value, $ctx, $key );
	}

	if ( ! is_string( $value ) ) {
		return $value;
	}

	if ( in_array( $key, [ 'product_id', 'order_id', 'api_resource_id', 'user_id' ], true ) ) {
		return cbjp_am_anon_id( $value, $ctx, $key );
	}

	foreach ( $ctx['secrets'] as $secret ) {
		$value = str_replace( $secret, '<redacted>', $value );
	}

	$value = str_replace( $ctx['site_url'], 'https://example.com', $value );
	// URL の中の ID も、この setup の ID だけをフィクスチャの値にし、知らない ID は 0 にする（別の顧客・製品の ID が返る違いを隠さない。G1-6）。
	$value = (string) preg_replace_callback(
		'/([?&]user_id=)([0-9]+)/',
		static fn( array $m ): string => $m[1] . cbjp_am_anon_id( $m[2], $ctx, 'user_id' ),
		$value
	);
	$value = (string) preg_replace_callback(
		'/([?&]am_download_file=)([0-9]+)/',
		static fn( array $m ): string => $m[1] . cbjp_am_anon_id( $m[2], $ctx, 'product_id' ),
		$value
	);
	$value = (string) preg_replace_callback(
		'/([?&]am_order=)([0-9]+)/',
		static fn( array $m ): string => $m[1] . cbjp_am_anon_id( $m[2], $ctx, 'order_id' ),
		$value
	);
	$value = (string) preg_replace( '/([?&]hname=)[^&]*/', '${1}xxxxxxxxxxxx', $value );
	$value = (string) preg_replace( '/([?&]hkey=)[^&]*/', '${1}xxxxxxxxxxxxxxxx', $value );
	$value = str_replace( 'cart-bridge-jp-pro-' . $ctx['product_id'], 'cart-bridge-jp-pro-1000', $value );
	$value = (string) preg_replace( '/\bproduct ID ' . CBJP_AM_UNKNOWN_PRODUCT_ID . '\b/', 'product ID ' . CBJP_AM_UNKNOWN_PRODUCT_ID_FIXTURE, $value );

	return $value;
}

/**
 * ID の欄の値を、フィクスチャの値に置き換える。知らない ID は `0`（フィクスチャに残さない）。
 *
 * @param array<string,mixed> $ctx
 */
function cbjp_am_anon_id( string $value, array $ctx, string $key ): string {
	$id = ctype_digit( $value ) ? (int) $value : -1;

	switch ( $key ) {
		case 'product_id':
			if ( $id === $ctx['product_id'] ) {
				return '1000';
			}

			return CBJP_AM_UNKNOWN_PRODUCT_ID === $id ? (string) CBJP_AM_UNKNOWN_PRODUCT_ID_FIXTURE : '0';
		case 'order_id':
			$index = array_search( $id, $ctx['order_ids'], true );

			return false === $index ? '0' : (string) ( 1001 + $index );
		case 'api_resource_id':
			$index = array_search( $id, $ctx['resource_ids'], true );

			return false === $index ? '0' : (string) ( 1 + $index );
		case 'user_id':
			return $id === $ctx['user_id'] ? '1' : '0';
	}

	return '0';
}

/**
 * 匿名化した値に、手元の値が残っていないか。残っていた箇所の一覧（空なら無い）。
 *
 * @param mixed               $value
 * @param array<string,mixed> $ctx
 * @return list<string>
 */
function cbjp_am_leaks( $value, array $ctx, string $path = '' ): array {
	if ( is_array( $value ) ) {
		$found = [];

		foreach ( $value as $k => $v ) {
			$sub = '' === $path ? (string) $k : $path . '.' . $k;
			// キーも確かめる（匿名化はキーを変えないので、手元の値がキーに入っていればそのまま残る。R1-6）。
			$found = array_merge( $found, cbjp_am_leaks( (string) $k, $ctx, $sub . ' (key)' ), cbjp_am_leaks( $v, $ctx, $sub ) );
		}

		return $found;
	}

	if ( ! is_string( $value ) ) {
		return [];
	}

	$host     = (string) parse_url( $ctx['site_url'], PHP_URL_HOST );
	$port     = parse_url( $ctx['site_url'], PHP_URL_PORT );
	$problems = [];

	if ( str_contains( $value, $ctx['site_url'] ) || ( null !== $port && str_contains( $value, $host . ':' . $port ) ) ) {
		$problems[] = "{$path}: the local site address";
	}

	foreach ( $ctx['secrets'] as $secret ) {
		if ( str_contains( $value, $secret ) ) {
			$problems[] = "{$path}: a key or instance";
		}
	}

	if ( 1 === preg_match( '/[?&]h(name|key)=(?!x+(&|\z))/', $value ) ) {
		$problems[] = "{$path}: a download hash";
	}

	// 手元の ID と、匿名化した後の値。同じなら（dev サイトの ID が偶然フィクスチャの値と同じ）見分けられないので見ない。
	$ids = [ [ $ctx['product_id'], 1000 ], [ $ctx['user_id'], 1 ] ];

	foreach ( $ctx['order_ids'] as $index => $order_id ) {
		$ids[] = [ $order_id, 1001 + $index ];
	}

	foreach ( $ids as [ $id, $mapped ] ) {
		if ( $id === $mapped ) {
			continue;
		}

		// ID は件数などの数と見分けられないので、URL の引数・ID を含む名前と、配列のキーそのもの（G1-7）だけを見る。
		if ( 1 === preg_match( '/([?&][a-z_]+=|cart-bridge-jp-pro-)' . $id . '(?![0-9])/', $value ) || ( str_ends_with( $path, ' (key)' ) && (string) $id === $value ) ) {
			$problems[] = "{$path}: a local ID ({$id})";
		}
	}

	return $problems;
}

/**
 * 比べない値を印に置き換える（キーがあること自体は比べる）。
 *
 * @param mixed $value
 * @return mixed
 */
function cbjp_am_comparable( $value, string $key = '' ) {
	if ( is_array( $value ) ) {
		$out = [];

		foreach ( $value as $k => $v ) {
			$out[ $k ] = cbjp_am_comparable( $v, (string) $k );
		}

		return $out;
	}

	if ( in_array( $key, [ 'api_call_execution_time', 'new_version', 'version', 'last_updated' ], true ) ) {
		return '<ignored>';
	}

	if ( ! is_string( $value ) ) {
		return $value;
	}

	// 「January 9, 2027」「Renewable until: October 24, 2026」のような日付と、ダウンロードの URL の期限。
	$value = (string) preg_replace( '/\b(January|February|March|April|May|June|July|August|September|October|November|December) [0-9]{1,2}, [0-9]{4}\b/', '<date>', $value );

	return (string) preg_replace( '/([?&]hexpires=)[0-9]+/', '${1}<time>', $value );
}

/**
 * 2 つの値の違い（`path: fixture=… measured=…` の一覧。同じなら空）。キーの順序は比べない。
 *
 * @param mixed $fixture
 * @param mixed $measured
 * @return list<string>
 */
function cbjp_am_diff( $fixture, $measured, string $path = '' ): array {
	if ( is_array( $fixture ) && is_array( $measured ) ) {
		$lines = [];

		foreach ( array_unique( array_merge( array_keys( $fixture ), array_keys( $measured ) ) ) as $k ) {
			$sub = '' === $path ? (string) $k : $path . '.' . $k;

			if ( ! array_key_exists( $k, $fixture ) ) {
				$lines[] = "{$sub}: only in the measured response (" . cbjp_am_short( $measured[ $k ] ) . ')';
			} elseif ( ! array_key_exists( $k, $measured ) ) {
				$lines[] = "{$sub}: only in the fixture (" . cbjp_am_short( $fixture[ $k ] ) . ')';
			} else {
				$lines = array_merge( $lines, cbjp_am_diff( $fixture[ $k ], $measured[ $k ], $sub ) );
			}
		}

		return $lines;
	}

	if ( $fixture === $measured ) {
		return [];
	}

	return [ ( '' === $path ? '(root)' : $path ) . ': fixture=' . cbjp_am_short( $fixture ) . ' measured=' . cbjp_am_short( $measured ) ];
}

/**
 * 差分の表示用に値を短くする。
 *
 * @param mixed $value
 */
function cbjp_am_short( $value ): string {
	$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$json = false === $json ? '?' : $json;

	return strlen( $json ) > 160 ? substr( $json, 0, 157 ) . '...' : $json;
}

/**
 * フィクスチャと同じ書式（タブの字下げ・末尾の改行）の JSON。
 *
 * @param mixed $value
 */
function cbjp_am_fixture_json( $value ): ?string {
	$json = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( false === $json ) {
		return null;
	}

	$tabbed = preg_replace_callback( '/^( {4})+/m', static fn( array $m ): string => str_repeat( "\t", intdiv( strlen( $m[0] ), 4 ) ), $json );

	return null === $tabbed ? null : $tabbed . "\n";
}

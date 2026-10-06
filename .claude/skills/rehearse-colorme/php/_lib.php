<?php
/**
 * rehearse-colorme の共通処理。php/*.php から require_once する（`rehearse.sh php <name>` が wp eval-file で実行する）。
 *
 * 安全規則（SKILL.md「安全規則」）:
 * - ColorMe に触るスクリプトは、引数 `shop=<login_id>` と接続中の店舗（`GET /shop.json` の `login_id`）が一致することを
 *   **肯定形で**確かめてから動く（`cbjp_rh_require_shop()`）。接続先を取り違えて実店舗へ投入・エクスポートしないため。
 *   店舗の login_id は repo に書かず、毎回引数で渡す。
 * - 出力（スナップショット・dry-run の明細）は `.rehearsal/`（gitignore 済み）にだけ書く。
 *
 * @package CartBridgeJP
 */

use CartBridgeJP\Adapters\ColorMe\ColorMeClient;
use CartBridgeJP\Support\TokenStore;

if ( function_exists( 'cbjp_rh_args' ) ) {
	return;
}

/**
 * ColorMe の説明（`$from`）の `<script>`・`<style>` の中身が、取り込んだ Woo の説明（`$stored`）に文字として残っているか（issue #101）。
 *
 * プラグインの処理を使わず、閉じタグまでの素朴な形だけを拾って確かめる。中身の文字列は要素の外の本文にも現れうる（`<p>version 1</p><script>1</script>`）ので、
 * 有無ではなく出現回数で比べる: 要素を除いた ColorMe の説明を kses に通した値（取り込んで期待される説明）より、保存値の方に多く現れれば残っている。
 * kses は文字の `&` `<` `>` を実体参照にし、中身の `a<b && c>d` のような並びをタグとして書き換えるので、中身そのもの・kses を通した中身の両方を、
 * 実体参照のままの値と戻した値の両方で数える。
 *
 * @return array<int,array{element:string,contents:string}>|null 残っていた要素（無ければ空）。PCRE が失敗して確かめられなければ null。
 */
function cbjp_rh_script_style_leaks( string $from, string $stored ): ?array {
	$pattern = '#<(script|style)\b[^>]*>(.*?)</\1\s*>#is';

	if ( false === preg_match_all( $pattern, $from, $elements, PREG_SET_ORDER ) ) {
		return null;
	}

	$outside = preg_replace( $pattern, '', $from );

	if ( null === $outside ) {
		return null;
	}

	$decode   = static fn ( string $html ): string => html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$expected = wp_kses_post( $outside );
	$leaks    = [];

	foreach ( $elements as $element ) {
		$contents = trim( $element[2] );

		if ( '' === $contents ) {
			continue;
		}

		foreach ( array_unique( [ $contents, trim( wp_kses_post( $contents ) ) ] ) as $needle ) {
			if ( '' === $needle ) {
				continue;
			}

			if ( substr_count( $stored, $needle ) > substr_count( $expected, $needle )
				|| substr_count( $decode( $stored ), $needle ) > substr_count( $decode( $expected ), $needle )
			) {
				$leaks[] = [
					'element'  => strtolower( $element[1] ),
					'contents' => $contents,
				];
				break;
			}
		}
	}

	return $leaks;
}

/**
 * `key=value` 形式の位置引数を連想配列にする。`=` の無い引数・重複したキーは誤記として止める。
 *
 * @param array<int,string> $args
 * @param array<int,string> $allowed 受け付けるキー。
 * @return array<string,string>
 */
function cbjp_rh_args( array $args, array $allowed ): array {
	$out = [];

	foreach ( $args as $arg ) {
		$pos = strpos( (string) $arg, '=' );

		if ( false === $pos || 0 === $pos ) {
			cbjp_rh_abort( "argument must be key=value: {$arg}" );
		}

		$key = substr( $arg, 0, $pos );

		if ( ! in_array( $key, $allowed, true ) ) {
			cbjp_rh_abort( "unknown argument '{$key}' (allowed: " . implode( ', ', $allowed ) . ')' );
		}

		if ( array_key_exists( $key, $out ) ) {
			cbjp_rh_abort( "duplicate argument '{$key}'" );
		}

		$out[ $key ] = substr( $arg, $pos + 1 );
	}

	return $out;
}

function cbjp_rh_abort( string $message, int $code = 2 ): never {
	echo "ABORT: {$message}\n";
	exit( $code );
}

function cbjp_rh_client(): ColorMeClient {
	$token = ( new TokenStore( 'colorme' ) )->get();
	$value = is_array( $token ) ? ( $token['access_token'] ?? null ) : null;

	if ( ! is_string( $value ) || '' === $value ) {
		cbjp_rh_abort( 'ColorMe is not connected on this site (no access token).' );
	}

	return ColorMeClient::for_access_token( $value );
}

/**
 * 接続中の店舗が `shop=<login_id>` と一致することを確かめ、`shop.json` の `shop` を返す。
 *
 * @param array<string,string> $opts
 * @return array<string,mixed>
 */
function cbjp_rh_require_shop( array $opts ): array {
	$expected = $opts['shop'] ?? '';

	if ( '' === $expected ) {
		cbjp_rh_abort( 'pass shop=<login_id of the ColorMe TEST shop> (checked against the connected shop before anything runs).' );
	}

	$shop = cbjp_rh_client()->get( 'shop.json' )['shop'] ?? null;

	if ( ! is_array( $shop ) || ! is_string( $shop['login_id'] ?? null ) || '' === $shop['login_id'] ) {
		cbjp_rh_abort( 'could not read login_id from GET /shop.json.' );
	}

	if ( $shop['login_id'] !== $expected ) {
		cbjp_rh_abort( "the connected shop is '{$shop['login_id']}', not '{$expected}'. Refusing to run." );
	}

	return $shop;
}

function cbjp_rh_out_dir(): string {
	$dir = CBJP_PATH . '.rehearsal';

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		cbjp_rh_abort( "could not create {$dir}" );
	}

	// 出力先はプラグインディレクトリの中で、Web から配信される（wp-env は 0.0.0.0 で待ち受けるので同じネットワークからも届く）。
	// スナップショットは会員・受注を含むので、Apache（wp-env の WordPress コンテナ）に配信させない。`rehearse.sh` は PHP を動かす前に、
	// 同じファイルを置いたうえで HTTP で読めないことを確かめる（G3-1）。ここでも書いておく（直接 eval-file した場合の備え）。
	$files = [
		'.htaccess' => "# rehearse-colorme: snapshots hold customer and order data. Never serve this directory.\nRequire all denied\n",
		'index.php' => "<?php\n// Silence is golden.\n",
	];

	foreach ( $files as $name => $content ) {
		if ( ! is_file( "{$dir}/{$name}" ) && false === file_put_contents( "{$dir}/{$name}", $content ) ) {
			cbjp_rh_abort( "could not write {$dir}/{$name}" );
		}
	}

	return $dir;
}

/**
 * スナップショット名（ファイル名の一部）を検証する。
 */
function cbjp_rh_label( string $label ): string {
	if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{1,40}\z/', $label ) ) {
		cbjp_rh_abort( "label must be 1-40 chars of [A-Za-z0-9_-]: '{$label}'" );
	}

	return $label;
}

/**
 * REST を `rest_do_request()` で呼ぶ。GET は set_query_params()、それ以外は set_body_params()
 * （ルートにクエリ文字列を含めると rest_no_route になる。CLAUDE.md）。
 *
 * @param array<string,mixed> $params
 * @return array{status:int,data:mixed}
 */
function cbjp_rh_rest( string $method, string $route, array $params = [] ): array {
	$request = new WP_REST_Request( $method, $route );

	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}

	$response = rest_do_request( $request );

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
}

/**
 * ページングのある一覧（`limit`/`offset`）を最後まで取る。1 ページでも失敗したら例外のまま止める（途中までの一覧を全件と読み違えない）。
 *
 * @param array<string,mixed> $query
 * @return array<int,array<string,mixed>>
 */
function cbjp_rh_get_all( ColorMeClient $client, string $path, string $key, array $query = [] ): array {
	$rows   = [];
	$offset = 0;
	$limit  = 50;

	for ( $page = 0; $page < 200; $page++ ) {
		$body  = $client->get(
			$path,
			array_merge(
				$query,
				[
					'limit'  => $limit,
					'offset' => $offset,
				]
			)
		);
		$chunk = $body[ $key ] ?? null;

		if ( ! is_array( $chunk ) ) {
			throw new RuntimeException( "GET {$path}: no '{$key}' list in the response" );
		}

		foreach ( $chunk as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		$total = $body['meta']['total'] ?? null;

		if ( count( $chunk ) < $limit || ( is_int( $total ) && count( $rows ) >= $total ) ) {
			// 短いページで終わったのに `meta.total` より少ないなら、途中のページが欠けている（スナップショット・差分が件数を取りこぼす）。
			if ( is_int( $total ) && count( $rows ) < $total ) {
				throw new RuntimeException( "GET {$path}: got " . count( $rows ) . " rows but meta.total is {$total}" );
			}

			return $rows;
		}

		$offset += $limit;
	}

	throw new RuntimeException( "GET {$path}: more than 200 pages" );
}

/**
 * スナップショットを読む。
 *
 * @return array<string,mixed>
 */
function cbjp_rh_load_snapshot( string $label ): array {
	$path = cbjp_rh_out_dir() . '/' . cbjp_rh_label( $label ) . '.json';

	if ( ! is_readable( $path ) ) {
		cbjp_rh_abort( "no snapshot: {$path}" );
	}

	$data = wp_json_file_decode( $path, [ 'associative' => true ] );

	if ( ! is_array( $data ) ) {
		cbjp_rh_abort( "unreadable snapshot: {$path}" );
	}

	return $data;
}

/**
 * JSON を書き出す。エンコード・書込みのどちらかが失敗したら止める（失敗したのに「saved」と出して、古いファイルを読み違えないため）。
 *
 * @param mixed $data
 */
function cbjp_rh_write_json( string $path, $data ): void {
	$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( ! is_string( $json ) ) {
		cbjp_rh_abort( "could not encode JSON for {$path}" );
	}

	if ( false === file_put_contents( $path, $json ) ) {
		cbjp_rh_abort( "could not write {$path}" );
	}
}

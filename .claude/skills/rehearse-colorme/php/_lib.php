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
use CartBridgeJP\Woo\Support\HtmlText;

if ( function_exists( 'cbjp_rh_args' ) ) {
	return;
}

/**
 * ColorMe の商品名がストアフロントに表示される文字（R3-1f）。取り込んだ Woo の名前（の表示どおりの文字）と比べる期待値。
 *
 * ColorMe のストアフロントは名前を見出しにエスケープせずに出すので、タグは表示されず実体参照は文字になる。プラグインは WP の HTML API で
 * 文字の部分を取り出す（`HtmlText::visible_text()`）ので、ここは別の実装（libxml の HTML パーサーの DOM）で作る: script・style・textarea・title・xmp・iframe・
 * noembed・noframes（HTML API が中身を文字として返さない要素）と template・noscript（ブラウザが表示しない要素）を除き、
 * `<br>` を空白にし、ブロック要素（`<p>`・`<div>`・`<li>` など）の前後に空白を置いた body の textContent の、HTML の空白の連続を 1 つにして前後を除く。`<` も `&` も無い名前はそのまま、表示される文字が無い名前
 * （タグだけ）は元の値（プラグインと同じく名前を失わない）。libxml は HTML5 の字句解析と細部が違うので、食い違ったら入力を見て判断する。
 */
function cbjp_rh_visible_name( string $name ): string {
	if ( ! str_contains( $name, '<' ) && ! str_contains( $name, '&' ) ) {
		return $name;
	}

	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $name . '</body></html>', LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	$xpath   = new DOMXPath( $dom );
	$hidden  = $xpath->query( '//body//script | //body//style | //body//textarea | //body//title | //body//xmp | //body//iframe | //body//noembed | //body//noframes | //body//template | //body//noscript' );
	$breaks  = $xpath->query( '//body//br' );
	$removed = false === $hidden ? [] : iterator_to_array( $hidden );
	$spaced  = false === $breaks ? [] : iterator_to_array( $breaks );
	$blocks  = [];

	// ブラウザが前後を改行して表示するブロック要素は、前後に空白を置く（語をつなげない）。
	foreach ( [ 'p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'table', 'caption', 'tr', 'td', 'th', 'address', 'article', 'aside', 'figure', 'figcaption', 'footer', 'header', 'main', 'nav', 'section' ] as $tag ) {
		$found  = $dom->getElementsByTagName( $tag );
		$blocks = array_merge( $blocks, iterator_to_array( $found ) );
	}

	foreach ( $removed as $node ) {
		$node->parentNode?->removeChild( $node );
	}

	foreach ( $spaced as $node ) {
		$node->parentNode?->replaceChild( $dom->createTextNode( ' ' ), $node );
	}

	foreach ( $blocks as $node ) {
		$node->parentNode?->insertBefore( $dom->createTextNode( ' ' ), $node );
		$node->parentNode?->insertBefore( $dom->createTextNode( ' ' ), $node->nextSibling );
	}

	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	$text = trim( (string) preg_replace( '/[ \t\n\f\r]+/', ' ', null === $body ? '' : $body->textContent ), " \t\n\f\r" );

	return '' === $text ? $name : $text;
}

/**
 * ColorMe の説明（`$from`）の `<script>`・`<style>` の中身が、取り込んだ Woo の説明（`$stored`）に文字として残っていないか（issue #101）。
 *
 * 期待値はプラグインの除去とは別に作る: WP の HTML API（`WP_HTML_Tag_Processor`。ブラウザと同じ字句解析で、属性値・コメント・文字の `<` を
 * 正しく読む）で `<script>`・`<style>` の中身を空にし、プラグインと同じ浄化（`HtmlText::sanitize_post_html()`。kses と文字の `<` の扱い）を通す。
 * kses と同じく先に制御文字を消す（`<script\0>` を要素として読むため）。保存値と期待値は、kses を変化しなくなるまで掛けてから完全一致で比べる
 * （kses 自体が冪等でない入力があり、WP-Cron では保存時にもう一度 kses が掛かるため）。文字列の有無・出現回数で比べると、中身と同じ文字列が
 * 本文にもある正しい取込みを失敗にし、除いた前後がつながった漏れ（`a<script>ab</script>b` → `aabb`）を見逃す（PR #106 G1-1・G2-1〜3）。
 * 保存時の `wp_unslash()`（バックスラッシュが消える）も期待値に掛ける（G3-1）。対象は投稿の列に保存する説明・短い説明。
 *
 * @return array{elements:int,ok:bool,expected:string} `elements` は HTML API が見つけた要素の数（0 なら比べない）。
 */
function cbjp_rh_script_style_check( string $from, string $stored ): array {
	$processor = new WP_HTML_Tag_Processor( wp_kses_no_null( $from, [ 'slash_zero' => 'keep' ] ) );
	$elements  = 0;

	while ( $processor->next_tag() ) {
		if ( in_array( $processor->get_tag(), [ 'SCRIPT', 'STYLE' ], true ) && $processor->set_modifiable_text( '' ) ) {
			++$elements;
		}
	}

	if ( 0 === $elements ) {
		return [
			'elements' => 0,
			'ok'       => true,
			'expected' => '',
		];
	}

	$stable = static function ( string $html ): string {
		for ( $i = 0; $i < 5; $i++ ) {
			$next = wp_kses_post( $html );

			if ( $next === $html ) {
				break;
			}

			$html = $next;
		}

		return $html;
	};

	// 保存の `wp_insert_post()` はランナーによらず `wp_unslash()` でバックスラッシュを消す（`.claude/rules/woocommerce-api.md`）ので、期待値にも掛ける（PR #106 G3-1）。
	$expected = $stable( wp_unslash( HtmlText::sanitize_post_html( $processor->get_updated_html() ) ) );

	return [
		'elements' => $elements,
		'ok'       => $stable( $stored ) === $expected,
		'expected' => $expected,
	];
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
	// リポジトリのルートの .rehearsal/（この PHP は <root>/.claude/skills/rehearse-colorme/php/）。プラグインのディレクトリ（CBJP_PATH）には書かない。
	$dir = dirname( __DIR__, 4 ) . '/.rehearsal';

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		cbjp_rh_abort( "could not create {$dir}" );
	}

	// 出力先は wp-env のコンテナにマウントされ、Web から配信されうる（wp-env は 0.0.0.0 で待ち受けるので同じネットワークからも届く）。
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

/**
 * JP の税率が 8% の税区分のスラッグ（標準 `''` を除く。名前順）。期待値をプラグインの判定（`Woo\Support\TaxClass`、D26）と別に作るため、
 * 税の設定（`cbjp_rh_tax_setup()` の形）だけから、WooCommerce の `WC_Tax::find_rates()` と `calc_tax()` の規則を書き写して求める
 * （skill-scripts.md: 期待値は独立に作る。PR #107 G1-1: 全行を足すと、同じ優先度の JP の行と国 `''` の行を両方数えて誤る）。
 * - 一致する行: 国が JP か `''`、州が `''` か店舗の州（基準国が JP のときだけ）。郵便番号・市で限定した行が一致の候補にあれば、
 *   一致を再現できないので null（判定できない＝呼び出し側が止める。リハーサルの税設定は限定しない）。
 * - 優先度ごとに 1 行: 国が `''` でない行 → 州が `''` でない行 → ID の小さい行の順で最初のもの（`WC_Tax::sort_rates_callback()`）。
 * - 実効税率: 複合でない税率の合計に、複合の税率を優先度の順に上乗せする（`WC_Tax::calc_exclusive_tax()`）。
 * 行の形が違えば（古いスナップショットなど）null。
 *
 * @param mixed $setup `cbjp_rh_tax_setup()` の戻り値（`snapshot` の `woo.tax_setup`）。
 * @return array<int,string>|null
 */
function cbjp_rh_jp_reduced_classes( $setup ): ?array {
	if ( ! is_array( $setup ) || ! is_string( $setup['base_country'] ?? null ) || ! is_string( $setup['base_state'] ?? null ) || ! is_array( $setup['rates'] ?? null ) ) {
		return null;
	}

	$state    = 'JP' === $setup['base_country'] ? $setup['base_state'] : '';
	$selected = [];

	foreach ( $setup['rates'] as $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		foreach ( [ 'tax_rate_id', 'tax_rate', 'tax_rate_priority', 'tax_rate_compound', 'location_count' ] as $key ) {
			if ( ! is_numeric( $row[ $key ] ?? null ) ) {
				return null;
			}
		}

		foreach ( [ 'tax_rate_class', 'tax_rate_country', 'tax_rate_state' ] as $key ) {
			if ( ! is_string( $row[ $key ] ?? null ) ) {
				return null;
			}
		}

		$class = $row['tax_rate_class'];

		if ( '' === $class || ! in_array( $row['tax_rate_country'], [ 'JP', '' ], true ) || ! in_array( $row['tax_rate_state'], array_unique( [ '', $state ] ), true ) ) {
			continue;
		}

		if ( (int) $row['location_count'] > 0 ) {
			return null;
		}

		$priority = (int) $row['tax_rate_priority'];
		$current  = $selected[ $class ][ $priority ] ?? null;
		$rank     = static fn ( array $r ): array => [ '' === $r['tax_rate_country'] ? 1 : 0, '' === $r['tax_rate_state'] ? 1 : 0, (int) $r['tax_rate_id'] ];

		if ( null === $current || $rank( $row ) < $rank( $current ) ) {
			$selected[ $class ][ $priority ] = $row;
		}
	}

	$classes = [];

	foreach ( $selected as $class => $by_priority ) {
		ksort( $by_priority );
		$total = 0.0;

		foreach ( $by_priority as $row ) {
			if ( 0 === (int) $row['tax_rate_compound'] ) {
				$total += (float) $row['tax_rate'];
			}
		}

		foreach ( $by_priority as $row ) {
			if ( 0 !== (int) $row['tax_rate_compound'] ) {
				$total += ( 100 + $total ) * (float) $row['tax_rate'] / 100;
			}
		}

		if ( 800 === (int) round( $total * 100 ) ) {
			$classes[] = (string) $class;
		}
	}

	sort( $classes, SORT_STRING );

	return $classes;
}

/**
 * 開発サイトの税の設定（`cbjp_rh_jp_reduced_classes()` に渡す形）: 店舗の基準国・州（`woocommerce_default_country` の `国:州`）と、
 * 税率の表の行（郵便番号・市で限定した件数つき）。プラグインのコードは使わない。
 *
 * @return array{base_country:string,base_state:string,rates:array<int,array<string,string>>}
 */
function cbjp_rh_tax_setup(): array {
	global $wpdb;

	$base = explode( ':', (string) get_option( 'woocommerce_default_country', '' ), 2 );
	$rows = $wpdb->get_results(
		"SELECT r.tax_rate_id, r.tax_rate_class, r.tax_rate_country, r.tax_rate_state, r.tax_rate, r.tax_rate_priority, r.tax_rate_compound, COUNT( l.location_id ) AS location_count
		FROM {$wpdb->prefix}woocommerce_tax_rates r LEFT JOIN {$wpdb->prefix}woocommerce_tax_rate_locations l ON l.tax_rate_id = r.tax_rate_id
		GROUP BY r.tax_rate_id ORDER BY r.tax_rate_id",
		ARRAY_A
	);

	return [
		'base_country' => $base[0],
		'base_state'   => $base[1] ?? '',
		'rates'        => is_array( $rows ) ? $rows : [],
	];
}

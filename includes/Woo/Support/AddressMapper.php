<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * Canonicalの住所（`postal`/`pref_id`/`pref_name`/`address1`/`address2`/`country`）を
 * WooCommerceのbilling/shipping配列へ変換する。`Writer\CustomerWriter` / `Writer\OrderWriter` で共用。
 */
final class AddressMapper {

	/**
	 * `pref_id`（1-47=都道府県、48=海外を表す特別値）というエンコーディング自体はColorMe
	 * APIの取り決めであり、Wooの都道府県コード（`JP01`等）との対応関係もColorMe固有の解釈
	 * である。このクラスはWooRepositoryFactory経由で全プラットフォーム共通に使われるため、
	 * `pref_id`スキームを解釈してよい対応済みプラットフォームをここで明示的に限定する
	 * （アーキテクチャ原則1）。将来他ASPの`address`が同名キー`pref_id`を異なる意味で
	 * 使う可能性があり、無条件に解釈すると住所を誤って変換しかねない。
	 *
	 * @var array<int,string>
	 */
	private const PREF_ID_SCHEME_PLATFORMS = [ 'colorme' ];

	/**
	 * 「氏名は姓名を空白区切りで連結した単一文字列」という前提自体もColorMe固有の仕様であり、
	 * 他ASPが姓名を最初から別フィールドで供給する、または異なる区切り規則を持つ可能性がある
	 * （`pref_id`スキームと全く同じリスク構造。アーキテクチャ原則1）。無条件に分割すると
	 * 対応外プラットフォームの氏名を誤って分割しかねないため、対応済みプラットフォームを
	 * ここで明示的に限定する。
	 *
	 * @var array<int,string>
	 */
	private const SINGLE_STRING_NAME_PLATFORMS = [ 'colorme' ];

	/**
	 * ColorMeの`pref_id`（1-47）からWooCommerce/JIS X 0401の都道府県番号（`WC()->countries->
	 * get_states('JP')`のキー`JPxx`と同じ番号。`state_code()`/`pref_id_from_state()`が
	 * `JP%02d`の書式に使う）への対応表。ColorMeのAPIドキュメント（swagger `info.description`に
	 * 埋め込まれた「都道府県コード一覧」表。構造化されたJSONスキーマとしては提供されていない）は
	 * JIS標準の並びと一致しない箇所が多数あり（例: pref_id=4は秋田県だがJIS/Wooの4番は宮城県）、
	 * 番号をそのまま同一視すると約半数の都道府県で誤った住所を送受信する（G3ゲートで判明,
	 * Codex/Copilot, P1）。値はColorMeのAPIドキュメント本文の表を都道府県名で突き合わせて
	 * 書き起こしたもの（`wp eval 'echo wp_json_encode(WC()->countries->get_states("JP"));'`と
	 * ColorMeのswagger descriptionを名前で照合して作成。全47件を検証済み）。
	 *
	 * @var array<int,int> ColorMe pref_id => Woo/JIS番号
	 */
	private const PREF_ID_TO_JIS_NUMBER = [
		1  => 1,
		2  => 2,
		3  => 3,
		4  => 5,
		5  => 4,
		6  => 6,
		7  => 7,
		8  => 8,
		9  => 9,
		10 => 10,
		11 => 11,
		12 => 12,
		13 => 13,
		14 => 14,
		15 => 15,
		16 => 18,
		17 => 17,
		18 => 16,
		19 => 22,
		20 => 19,
		21 => 20,
		22 => 23,
		23 => 21,
		24 => 24,
		25 => 30,
		26 => 25,
		27 => 29,
		28 => 26,
		29 => 27,
		30 => 28,
		31 => 33,
		32 => 34,
		33 => 31,
		34 => 32,
		35 => 35,
		36 => 37,
		37 => 36,
		38 => 38,
		39 => 39,
		40 => 40,
		41 => 41,
		42 => 42,
		43 => 44,
		44 => 43,
		45 => 45,
		46 => 46,
		47 => 47,
	];

	private function __construct() {}

	/**
	 * @param array<string,mixed> $address
	 * @return array{first_name:string,last_name:string,company:string,address_1:string,address_2:string,city:string,state:string,postcode:string,country:string,email:string,phone:string}
	 */
	public static function to_woo( string $platform, array $address, string $full_name, string $email, ?string $phone, ?string $company ): array {
		[ $last_name, $first_name ] = self::split_name( $platform, $full_name );

		return [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'company'    => $company ?? '',
			// ColorMeに市区町村の独立フィールドが無く、address1からの推測分割は誤りを生むため
			// cityは常に空にし、番地含む住所全体をaddress_1へ入れる。
			'address_1'  => Value::string( $address['address1'] ?? null ) ?? '',
			'address_2'  => Value::string( $address['address2'] ?? null ) ?? '',
			'city'       => '',
			'state'      => self::state_code( $platform, $address ),
			'postcode'   => Value::string( $address['postal'] ?? null ) ?? '',
			'country'    => self::is_overseas( $platform, $address ) ? '' : ( Value::string( $address['country'] ?? null ) ?? 'JP' ),
			'email'      => $email,
			'phone'      => $phone ?? '',
		];
	}

	/**
	 * @param array<string,mixed> $address
	 */
	public static function is_overseas( string $platform, array $address ): bool {
		if ( ! in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true ) ) {
			return false;
		}

		return 48 === Value::int( $address['pref_id'] ?? null );
	}

	/**
	 * `pref_id`スキームを解釈するプラットフォームか（`state_code()`/`is_overseas()`が対象とするもの）。
	 * `Woo\Tools\PrefStateRepair`のように、そもそも県コードの解釈自体が成立しないプラットフォームを
	 * 事前に弾きたい呼び出し側が使う。
	 */
	public static function uses_pref_id_scheme( string $platform ): bool {
		return in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true );
	}

	/**
	 * Woo の `state`（`JP01`〜`JP47`）。`to_woo()` がインポート時に書く値と同じ関数で、
	 * 県コード修復ツール（`Woo\Tools\PrefStateRepair`）が「今のコードなら何が書かれるか」を
	 * 得るために公開している（Writer と別ロジックを持たせると食い違うため）。
	 *
	 * @param array<string,mixed> $address
	 */
	public static function state_code( string $platform, array $address ): string {
		if ( ! in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true ) ) {
			return '';
		}

		$pref_id = Value::int( $address['pref_id'] ?? null );

		if ( null === $pref_id ) {
			return '';
		}

		$jis_number = self::PREF_ID_TO_JIS_NUMBER[ $pref_id ] ?? null;

		return null !== $jis_number ? sprintf( 'JP%02d', $jis_number ) : '';
	}

	/**
	 * `state_code()`の逆変換（エクスポート方向）。Wooの`state`（`JP01`〜`JP47`）を
	 * `PREF_ID_TO_JIS_NUMBER`の逆引きでColorMeの`pref_id`（1-47）へ戻す。`state`がこの形式に
	 * 一致しない場合、`country`が非空かつ`'JP'`以外であれば`48`（海外。swaggerの`pref_id`
	 * descriptionが明記する特別値）とみなす。それ以外（`state`不一致・`country`もJPまたは空）は
	 * 変換不能として`null`を返す（呼び出し側がフェイルクローズする）。`$`ではなく`\z`で終端を
	 * 固定する（`$`は末尾改行の直前にもマッチするため、`JP13\n`のような破損値を誤って正常な
	 * `JP13`として解釈しうる。`CustomerTransformer::normalize_tel()`と同じ境界データ問題。
	 * G1ゲートで判明, Copilot）。
	 *
	 * `country`が非JPを明示している場合、`state`が`JPxx`形式に一致してもそれを信用しない
	 * （country優先）。`state`/`country`はWoo側で互いに独立して更新されうる境界データのため、
	 * 国を変更したのに古い`JPxx`の都道府県だけが残る、といった不整合がありうる。この場合に
	 * `state`だけを見て国内住所と誤判定すると、実際は海外の顧客が誤って東京都（等）の国内住所
	 * として無警告でexportされてしまう（G1ゲートで判明, Codex, P2）。
	 */
	public static function pref_id_from_state( string $platform, ?string $state, ?string $country ): ?int {
		if ( ! in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true ) ) {
			return null;
		}

		$conflicts_with_domestic_state = null !== $country && '' !== $country && 'JP' !== $country;

		if ( ! $conflicts_with_domestic_state && null !== $state && 1 === preg_match( '/^JP([0-9]{2})\z/', $state, $matches ) ) {
			$pref_id = self::pref_id_from_jis_number( (int) $matches[1] );

			if ( null !== $pref_id ) {
				return $pref_id;
			}
		}

		if ( $conflicts_with_domestic_state ) {
			return 48;
		}

		return null;
	}

	/**
	 * Wooネイティブの住所（`address_1`/`address_2`/`city`/`state`/`postcode`/`country`キー）から
	 * ColorMeのリクエストペイロードが要求する`postal`/`address1`/`address2`/`pref_id`へ変換する
	 * （`pref_id_from_state()`を内部で使う、エクスポート方向の住所ペイロード組み立て）。`postal`/
	 * `address1`/`pref_id`は3点セットで解決できた場合のみ含める（一部だけ解決できた状態のまま
	 * 送信すると、新しい郵便番号＋古い都道府県のような内部矛盾した住所を書き込みかねないため。
	 * `address2`は補足情報のためこの3点セットとは独立に含めてよい）。全く解決できない場合は
	 * 空配列を返す（新規作成では呼び出し元が必須項目としてフェイルクローズし、更新では省略可能な
	 * 項目としてそのまま使う）。
	 *
	 * `Adapters\ColorMe\Transform\CustomerTransformer`（顧客）・`OrderTransformer`（受注の
	 * ゲスト顧客/配送先）で共有する（D19 PR-Bで確立した「対称の変換を複製すると2箇所が食い違う
	 * リスクを負う」という方針。ASP固有のリクエスト形状に依存するため`pref_id_from_state()`と
	 * 同じプラットフォーム限定ゲートを適用する）。
	 *
	 * @param array<string,mixed> $woo_address `city`/`address_1`/`address_2`/`state`/`postcode`/`country`キー。
	 * @return array{postal?:string,address1?:string,pref_id?:int,address2?:string}
	 */
	public static function to_asp_address_payload( string $platform, array $woo_address ): array {
		if ( ! in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true ) ) {
			return [];
		}

		$postal      = Value::string( $woo_address['postcode'] ?? null );
		$state       = Value::string( $woo_address['state'] ?? null );
		$country     = Value::string( $woo_address['country'] ?? null );
		$pref_id     = self::pref_id_from_state( $platform, $state, $country );
		$is_overseas = 48 === $pref_id;
		$address1    = self::join_address1(
			Value::string( $woo_address['city'] ?? null ),
			Value::string( $woo_address['address_1'] ?? null ),
			$is_overseas
		);

		if ( $is_overseas && null !== $address1 ) {
			// stateがColorMeの都道府県コード形式に一致する場合は付記しない。countryが非JPを
			// 明示しているため海外住所に解決されているが、state自体は国が変更された後も
			// 古い国内都道府県コードだけが残っている陳腐化した値である可能性があるため
			// （元のCustomerTransformerの判断を踏襲）。
			$region_state = null !== $state && 1 === preg_match( '/^JP[0-9]{2}\z/', $state ) ? null : $state;
			$address1     = self::append_region( $address1, $region_state, $country );
		}

		$address2 = Value::string( $woo_address['address_2'] ?? null );

		$payload = [];

		if ( null !== $postal && null !== $address1 && null !== $pref_id ) {
			$payload['postal']   = $postal;
			$payload['address1'] = $address1;
			$payload['pref_id']  = $pref_id;
		}

		if ( null !== $address2 ) {
			$payload['address2'] = $address2;
		}

		return $payload;
	}

	/**
	 * ColorMeの`address1`はswagger上「住所1（**市区町村**・番地）」の1フィールドだが、
	 * WooCommerceのJPロケール（`WC()->countries->get_address_fields('JP')`で実測確認）は
	 * `billing_city`（市区町村。必須）と`billing_address_1`（番地。必須）を別フィールドとして
	 * 扱う。`city`を無視して`address_1`だけを送ると、ネイティブWoo顧客（exportの主対象）の
	 * 住所から市区町村がまるごと欠落する。ColorMe由来の往復顧客は`to_woo()`が`city`を常に
	 * 空文字列にする契約のため、連結しても元の1フィールド文字列のまま変わらない。
	 *
	 * `$is_overseas`（`pref_id=48`）の場合のみ半角スペースを挟む。区切り無しの連結は日本語住所
	 * （区切り無しで読める）でのみ正しく、区切り無しのまま海外住所（例: `city='Los Angeles'`+
	 * `address_1='123 Main St'`）に適用すると単語がくっつき無警告で送信されてしまう。
	 * 日本国内・往復顧客（`$is_overseas=false`）では従来どおり区切り無しを維持する
	 * （既存の往復文字列を変えないため）。
	 */
	private static function join_address1( ?string $city, ?string $street, bool $is_overseas ): ?string {
		$separator = $is_overseas && '' !== ( $city ?? '' ) && '' !== ( $street ?? '' ) ? ' ' : '';
		$joined    = trim( ( $city ?? '' ) . $separator . ( $street ?? '' ) );

		return '' !== $joined ? $joined : null;
	}

	/**
	 * ColorMeの顧客/受注スキームには国・地域（都道府県に相当する行政区分）を運ぶ専用フィールドが
	 * 無いため、海外住所の`state`（例: 'CA'）/`country`（例: 'US'）は`address1`の末尾に
	 * カンマ区切りで付記する以外に保持する場所が無い（`Adapters\ColorMe\Transform`はWC()への
	 * 直接依存を持たないアーキテクチャのため、国名の完全表記への変換は行わずWooの生コードの
	 * まま付記する）。
	 */
	private static function append_region( string $address1, ?string $state, ?string $country ): string {
		$parts = array_values(
			array_filter(
				[ $state, $country ],
				static fn ( ?string $value ): bool => null !== $value && '' !== $value
			)
		);

		return [] !== $parts ? $address1 . ', ' . implode( ', ', $parts ) : $address1;
	}

	/**
	 * `PREF_ID_TO_JIS_NUMBER`の逆引き。`array_flip()`は定数式として使えないため
	 * （PHPのconst初期化子は関数呼び出しを許さない）、リクエスト内で1度だけ計算して
	 * メモ化する。
	 *
	 * @var array<int,int>|null Woo/JIS番号 => ColorMe pref_id
	 */
	private static ?array $jis_number_to_pref_id = null;

	private static function pref_id_from_jis_number( int $jis_number ): ?int {
		if ( null === self::$jis_number_to_pref_id ) {
			self::$jis_number_to_pref_id = array_flip( self::PREF_ID_TO_JIS_NUMBER );
		}

		return self::$jis_number_to_pref_id[ $jis_number ] ?? null;
	}

	/**
	 * ColorMeの氏名は「姓 名」の単一文字列。半角/全角スペースで最初の1回だけ分割し、
	 * 先頭を姓（last_name）、残りを名（first_name）とする。区切りが無ければ全体を姓に入れる。
	 * `SINGLE_STRING_NAME_PLATFORMS`未対応のプラットフォームでは、この分割規則自体が
	 * 妥当か不明なため分割せず全体を姓に入れる（分割に失敗した場合と同じフォールバック）。
	 *
	 * @return array{0:string,1:string} [last_name, first_name]
	 */
	private static function split_name( string $platform, string $full_name ): array {
		if ( ! in_array( $platform, self::SINGLE_STRING_NAME_PLATFORMS, true ) ) {
			return [ $full_name, '' ];
		}

		$parts = preg_split( '/[\s\x{3000}]+/u', trim( $full_name ), 2 );

		if ( false === $parts || ! isset( $parts[0] ) ) {
			return [ $full_name, '' ];
		}

		return [ $parts[0], $parts[1] ?? '' ];
	}
}

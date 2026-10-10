<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * `cbjp_settings_{platform}` オプション（`/settings/mappings/{platform}` REST が書く。UI は管理画面の
 * Mappings タブ。R3-0m）からユーザー設定マッピングを読む（無料版はカテゴリ。`lookup()`・`reverse_lookup()` は
 * 種類によらない汎用の読取りで、Pro アドオンの決済/配送/受注ステータス〔`OrderMethodMap`。R3-6c1 でこのクラスから分けた〕も使う）。
 * 未設定のキーは「未マッピング」経路（`CATEGORY_MAP_UNRESOLVED` 等の警告）に倒れる。
 * 呼び出しのたびにオプションを読むため、run の途中で保存された設定は次のアイテムから効く。
 */
final class MethodMap {

	public function __construct( private readonly string $platform ) {}

	/**
	 * `$map_key`（`payment_map`/`shipping_map`。ASP側ID=>Woo側ID）から、値が`$woo_value`と
	 * 一致するASP側キーを列挙し、ちょうど1件のときだけそのキーを返す。
	 * Pro の受注のマッピング（`MappingKind::map_key()` のキー）も読む汎用の口（R3-6c1。設定の保存形式を Pro に持たせない）。
	 */
	public function reverse_lookup( string $map_key, string $woo_value ): ?string {
		$settings = get_option( "cbjp_settings_{$this->platform}", [] );

		if ( ! is_array( $settings ) || ! is_array( $settings[ $map_key ] ?? null ) ) {
			return null;
		}

		$matches = [];

		foreach ( $settings[ $map_key ] as $asp_id => $mapped_woo_value ) {
			if ( Value::string( $mapped_woo_value ) === $woo_value ) {
				$matches[] = (string) $asp_id;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * Woo側カテゴリID（term_id文字列）に対応するASP側カテゴリID（ユーザー設定マッピング）。
	 * `category_map`は他3マップ（ASP→Woo）と向きが逆（Woo→ASP）で、カラーミーがカテゴリ作成
	 * 不可（`can_create_category=false`）なためエクスポート時に既存ASPカテゴリへ紐付ける
	 * 唯一の手段になる（`docs/01-plan-colorme.md` §5）。
	 */
	public function mapped_asp_category_id( string $woo_term_id ): ?string {
		return $this->lookup( 'category_map', $woo_term_id );
	}

	/**
	 * `$map_key`（`MappingKind::map_key()`。例: `category_map`）で `$key` に設定された値。未設定・文字列にできない値は null。
	 * Pro の受注のマッピングも読む汎用の口（R3-6c1）。
	 */
	public function lookup( string $map_key, string $key ): ?string {
		$settings = get_option( "cbjp_settings_{$this->platform}", [] );

		if ( ! is_array( $settings ) || ! is_array( $settings[ $map_key ] ?? null ) ) {
			return null;
		}

		$value = $settings[ $map_key ][ $key ] ?? null;

		return Value::string( $value );
	}
}

<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

use CartBridgeJP\Adapters\PlatformAdapter;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- 既定実装は引数を使わない（継承した種類が使う）。

/**
 * マッピング設定の 1 種類（`cbjp_settings_{platform}` の `{key}_map`。Mappings タブの 1 節）。`EntityType::mapping_kinds()` が返す
 * （商品の種類はカテゴリ、受注の種類は決済・配送・注文ステータス）。保存・検証は種類によらず同じ（`Admin\RestController`）。
 *
 * **互換方針**: Pro・外部コードはこのクラスを継承する。v1.0.0 公開後は新しいメソッドを既定実装つきで足す（D20）。
 */
abstract class MappingKind {

	/**
	 * Woo 側の値を起点に ASP 側の値を選ぶ（エクスポート方向。例: カテゴリ）。
	 */
	public const SOURCE_WOO = 'woo';

	/**
	 * ASP 側の値を起点に Woo 側の値を選ぶ（取込み方向。例: 決済方法）。
	 */
	public const SOURCE_ASP = 'asp';

	/**
	 * 種類のキー（`category`・`payment` …）。`PlatformAdapter::mapping_candidates()` の候補のキーと同じ。
	 */
	abstract public function key(): string;

	/**
	 * 画面の節の見出し（例: `Payment method mapping`）。呼ばれるたびに翻訳する（結果を保持しない）。
	 */
	abstract public function label(): string;

	/**
	 * 同じ実体の種類の中での並び（小さいほど先）。
	 */
	abstract public function position(): int;

	/**
	 * `SOURCE_WOO` か `SOURCE_ASP`。
	 */
	abstract public function source_side(): string;

	/**
	 * Woo 側の候補。
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	abstract public function woo_candidates(): array;

	/**
	 * option のキー。
	 */
	final public function map_key(): string {
		return $this->key() . '_map';
	}

	/**
	 * この接続先で設定を使うか（画面が節を出すか）。保存・候補の取得はこの値によらず行う。
	 */
	public function applies_to( PlatformAdapter $adapter ): bool {
		return true;
	}

	/**
	 * 取込みの前に未設定の数を案内するか（Import タブ。決済・配送）。案内の文は「未設定でも取り込み、dry-run の警告に出し、設定した後の
	 * 次の取込みで更新する」ことを伝えるので、その扱いになる種類（未設定の警告で checksum を保存しない種類）だけが真を返す。
	 */
	public function import_notice(): bool {
		return false;
	}

	/**
	 * 節の説明（いつ使うか・未設定のときどうなるか）。画面は空なら出さない。
	 */
	public function description(): string {
		return '';
	}

	/**
	 * 行に並べる側（`source_side()`）の列の見出し。
	 */
	public function source_heading(): string {
		return self::SOURCE_WOO === $this->source_side()
			? __( 'WooCommerce value', 'cart-bridge-jp' )
			: __( 'Platform value', 'cart-bridge-jp' );
	}

	/**
	 * 選ばせる側の列の見出し。
	 */
	public function target_heading(): string {
		return self::SOURCE_WOO === $this->source_side()
			? __( 'Platform value', 'cart-bridge-jp' )
			: __( 'WooCommerce value', 'cart-bridge-jp' );
	}

	/**
	 * 「設定しない」の選択肢の表示。
	 */
	public function unmapped_label(): string {
		return __( '— Unmapped —', 'cart-bridge-jp' );
	}

	/**
	 * 選べる対応先が 0 件のときの案内。
	 */
	public function no_targets_help(): string {
		return __( 'There are no options to choose from yet.', 'cart-bridge-jp' );
	}
}

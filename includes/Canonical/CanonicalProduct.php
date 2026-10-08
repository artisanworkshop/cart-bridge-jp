<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;
use CartBridgeJP\Canonical\Concerns\RemoteIdFromExtrasTrait;

/**
 * 正規化された商品モデル。価格は浮動小数点誤差を避けるため文字列で保持する。
 *
 * 価格の契約: `price`/`sale_price`/`variants[].price`/`variants[].sale_price` はいずれも
 * **消費者が実際に支払う税込金額**（`price`＝通常価格、`sale_price`＝セール中の実売価格）。
 * `variants[].sale_price` はセール中のバリエーションにだけ存在するキー（セール外は省略。
 * checksumを不必要に変えないため。読む側は `$variant['sale_price'] ?? null` とする）。
 *
 * 文字列の契約: `name` は**平文**（HTML・実体参照を含めない。`&` はそのまま `&`）。`description` と
 * `extras['short_description']` は HTML。Woo 側は名前を HTML として保存するので、`Woo\Writer\ProductWriter` が
 * 実体参照にし、`Woo\Reader\ProductReader` が平文へ戻す（`Woo\Support\HtmlText`、issue #99）。ASP が名前を
 * 実体参照で返すなら、アダプタが平文へ戻してから渡す（そのまま渡すと二重に符号化され `&amp;` が見える）。ASP が名前を
 * HTML として表示するなら（ColorMe のストアフロント）、アダプタがタグを除き実体参照を戻した表示どおりの文字にしてから渡す（R3-1f）。
 */
final readonly class CanonicalProduct implements CanonicalModel {

	use ChecksumTrait;
	use RemoteIdFromExtrasTrait;

	/**
	 * `$tax_class` の「軽減税率」の記号（標準税率は null）。Woo のスラッグではない（英語でインストールした Woo の既定のスラッグと
	 * 同じ文字列だが、Woo 層は JP の税率で税区分を見分けて相互変換する。`Woo\Support\TaxClass`、D26）。
	 */
	public const TAX_CLASS_REDUCED = 'reduced-rate';

	/**
	 * `extras`は元々このモデルの最終（12番目）の位置指定引数だった。`cbjp/adapters/register`
	 * 経由の外部アダプタ/アドオンが位置引数で `new CanonicalProduct(..., $extras)` のように
	 * 呼び出す可能性があるため、`extras`より後の引数（`requires_shipping`を含む）はすべて
	 * `extras`より後ろに追加すること。`extras`を追い越す位置に新しい引数を挿入すると、
	 * 型不一致（配列がbool引数に渡る等）で外部呼び出し元がTypeErrorになる。
	 *
	 * @param array<int,array<string,mixed>> $images
	 * @param array<int,array<string,mixed>> $variants
	 * @param array<int,array<string,mixed>> $options
	 * @param array<int,string>              $category_refs 連携先カテゴリのremote_id一覧。
	 * @param array<string,mixed>            $extras ASP固有フィールドの退避先。往復移行でのデータ欠損を防ぐ。
	 * @param array<int,string>              $tag_refs 連携先タグのremote_id一覧。
	 * @param ?int                            $weight 重量（グラム単位）。Wooのネイティブな重量設定に対応する。
	 * @param ?string                         $tax_class 税区分。標準税率は null、軽減税率は記号`reduced-rate`
	 *   （`TAX_CLASS_REDUCED`）。**Woo のスラッグではない**: Woo 層が JP の税率（8%）で
	 *   税区分を見分けて相互変換する（D26。日本語でインストールした Woo の軽減税率の税区分は「軽減税」でスラッグが違う）。
	 *   それ以外の値は、エクスポートでは止める警告とセットでしか現れない（Woo 層は `woo:` を付けたスラッグにして、記号と衝突させない）。
	 */
	public function __construct(
		public string $name,
		public ?string $sku,
		public string $price,
		public ?string $sale_price,
		public ?string $description,
		public array $images,
		public array $variants,
		public array $options,
		public array $category_refs,
		public ?int $stock,
		public string $status,
		public array $extras = [],
		public bool $requires_shipping = true,
		public array $tag_refs = [],
		public ?int $weight = null,
		public ?string $tax_class = null
	) {}

	public function to_array(): array {
		return [
			'name'              => $this->name,
			'sku'               => $this->sku,
			'price'             => $this->price,
			'sale_price'        => $this->sale_price,
			'description'       => $this->description,
			'images'            => $this->images,
			'variants'          => $this->variants,
			'options'           => $this->options,
			'category_refs'     => $this->category_refs,
			'stock'             => $this->stock,
			'status'            => $this->status,
			'extras'            => $this->extras,
			'requires_shipping' => $this->requires_shipping,
			'tag_refs'          => $this->tag_refs,
			'weight'            => $this->weight,
			'tax_class'         => $this->tax_class,
		];
	}

	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['name'] ?? '' ),
			isset( $data['sku'] ) ? (string) $data['sku'] : null,
			(string) ( $data['price'] ?? '0' ),
			isset( $data['sale_price'] ) ? (string) $data['sale_price'] : null,
			isset( $data['description'] ) ? (string) $data['description'] : null,
			(array) ( $data['images'] ?? [] ),
			(array) ( $data['variants'] ?? [] ),
			(array) ( $data['options'] ?? [] ),
			(array) ( $data['category_refs'] ?? [] ),
			isset( $data['stock'] ) ? (int) $data['stock'] : null,
			(string) ( $data['status'] ?? 'draft' ),
			(array) ( $data['extras'] ?? [] ),
			(bool) ( $data['requires_shipping'] ?? true ),
			(array) ( $data['tag_refs'] ?? [] ),
			isset( $data['weight'] ) ? (int) $data['weight'] : null,
			isset( $data['tax_class'] ) ? (string) $data['tax_class'] : null
		);
	}
}

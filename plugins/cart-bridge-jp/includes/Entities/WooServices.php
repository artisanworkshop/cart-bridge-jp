<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\Writer\VariationWriter;

/**
 * Writer・Reader のファクトリの 1 回の組み立て（1 ページ）で、種類をまたいで共有する依存（`EntityType::writer()`・`reader()` に渡す）。
 * 同じインスタンスを共有しないと振る舞いが変わるもの（`MediaImporter` は同じ画像の取込みをページ内で覚える）があるため、
 * 種類ごとに作らずここから取る。
 */
final class WooServices {

	private ?MappingRepository $mappings = null;

	private ?ProductResolver $product_resolver = null;

	private ?MethodMap $method_map = null;

	private ?MediaImporter $media = null;

	private ?VariationWriter $variations = null;

	public function __construct( private readonly string $platform ) {}

	public function platform(): string {
		return $this->platform;
	}

	public function mappings(): MappingRepository {
		if ( null === $this->mappings ) {
			$this->mappings = new MappingRepository();
		}

		return $this->mappings;
	}

	public function product_resolver(): ProductResolver {
		if ( null === $this->product_resolver ) {
			$this->product_resolver = new ProductResolver( $this->platform, $this->mappings() );
		}

		return $this->product_resolver;
	}

	public function method_map(): MethodMap {
		if ( null === $this->method_map ) {
			$this->method_map = new MethodMap( $this->platform );
		}

		return $this->method_map;
	}

	/**
	 * @internal 無料版の商品・タームの Writer 用（Pro が使ってよい API には含めない）。
	 */
	public function media(): MediaImporter {
		if ( null === $this->media ) {
			$this->media = new MediaImporter( $this->platform );
		}

		return $this->media;
	}

	/**
	 * @internal 無料版の商品の Writer 用（Pro が使ってよい API には含めない）。
	 */
	public function variations(): VariationWriter {
		if ( null === $this->variations ) {
			$this->variations = new VariationWriter( $this->platform, $this->mappings() );
		}

		return $this->variations;
	}
}

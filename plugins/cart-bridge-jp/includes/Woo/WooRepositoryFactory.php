<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WooWriterFactory;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\Writer\EntityWriter;
use Throwable;

/**
 * platformごとに `WooRepository`（実移行のwriter）を組み立てる既定のファクトリ。
 *
 * Writer は実体の種類（`Entities\EntityType::writer()`。R3-6b1）が作る。1 回の組み立て（1 ページ）につき種類ごとに 1 インスタンスで、
 * 依存（mapping・画像の取込み・商品の解決など）は `Entities\WooServices` で種類をまたいで共有する。外部の種類の `writer()` が
 * 例外を投げたら、その種類だけ外す（書込みは `ENTITY_NOT_SUPPORTED` でスキップされる。ほかの種類のページを巻き込まない）。
 */
final class WooRepositoryFactory implements WooWriterFactory {

	public function for_platform( string $platform ): WooWriter {
		return new WooRepository( new SideEffectGuard(), $this->writers( $platform ), $platform );
	}

	/**
	 * `for_platform()`と同じ`Writer\EntityWriter`群を使うが、`DryRunRepository`は
	 * `write()`ではなく`validate()`しか呼ばないため何も永続化しない（F1-6）。
	 */
	public function for_dry_run( string $platform ): WooWriter {
		return new DryRunRepository( new SideEffectGuard(), $this->writers( $platform ), $platform );
	}

	/**
	 * @return array<string,EntityWriter>
	 */
	private function writers( string $platform ): array {
		$services = new WooServices( $platform );
		$writers  = [];

		foreach ( EntityTypeRegistry::all() as $key => $type ) {
			try {
				$writer = $type->writer( $platform, $services );
			} catch ( Throwable $exception ) {
				( new Logger() )->error(
					'Entity type failed to build its Woo writer.',
					[
						'platform'  => $platform,
						'entity'    => $key,
						'exception' => $exception::class,
					]
				);

				continue;
			}

			if ( $writer instanceof EntityWriter ) {
				$writers[ $key ] = $writer;
			}
		}

		return $writers;
	}
}

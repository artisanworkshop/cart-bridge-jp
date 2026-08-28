<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Woo\WarningCode;

/**
 * dry-run結果（`cbjp_dry_run_items`）のCSVレポート生成（D17「変換結果・警告を全量出力」）。
 * REST配管（`RestController::get_run_report()`）から分離し、PHPUnitから直接検証できるようにする。
 *
 * 保存結果に依存する警告（`Woo\WarningCode`のdocblock参照。保存失敗・名前衝突の実体解決・
 * 画像ダウンロード等）は dry-run では判定していないため、このCSVには現れない。
 */
final class DryRunReportCsv {

	private const PAGE_SIZE = 500;

	/**
	 * 列見出しは機械可読な安定キー。翻訳しない（表計算ソフトのフィルタ・外部ツールが参照するため）。
	 *
	 * @var array<int,string>
	 */
	private const HEADER = [ 'entity', 'remote_id', 'label', 'operation', 'existing_local_id', 'warning_code', 'warning_detail', 'note' ];

	public function __construct( private readonly DryRunItemRepository $items ) {}

	/**
	 * `php://output`へ直接書き出す（全行をメモリに載せない）。
	 */
	public function stream( string $run_id, ?string $entity, bool $only_warnings ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- ファイルシステムではなくHTTPレスポンスのストリーム（php://output）への書込。
		$handle = fopen( 'php://output', 'w' );

		if ( false === $handle ) {
			return;
		}

		// 日本のユーザーがExcelで開くのが主用途のため、BOM無しだと確実に文字化けする。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- ファイルシステムではなくHTTPレスポンスのストリーム（php://output）への書込。
		fwrite( $handle, "\xEF\xBB\xBF" );
		// 第5引数（escape文字）を空文字に固定する: PHP 8.4でデフォルトのバックスラッシュ
		// エスケープが非推奨になるため、また値中のバックスラッシュがエスケープ文字と
		// 誤認されて壊れるのを防ぐため。
		fputcsv( $handle, self::HEADER, ',', '"', '' );

		$this->each_row(
			$run_id,
			$entity,
			$only_warnings,
			static function ( array $row ) use ( $handle ): void {
				fputcsv( $handle, $row, ',', '"', '' );
			}
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ファイルシステムではなくHTTPレスポンスのストリーム（php://output）のクローズ。
		fclose( $handle );
	}

	/**
	 * テスト用: 全行を配列で返す（`stream()`と同じ行生成ロジックを共有する）。
	 *
	 * @return array<int,array<int,string>>
	 */
	public function rows( string $run_id, ?string $entity, bool $only_warnings ): array {
		$rows = [];

		$this->each_row(
			$run_id,
			$entity,
			$only_warnings,
			static function ( array $row ) use ( &$rows ): void {
				$rows[] = $row;
			}
		);

		return $rows;
	}

	/**
	 * @param callable(array<int,string>):void $emit
	 */
	private function each_row( string $run_id, ?string $entity, bool $only_warnings, callable $emit ): void {
		$after_id   = 0;
		$batch_size = self::PAGE_SIZE;

		do {
			$batch      = $this->items->list_after( $run_id, $after_id, self::PAGE_SIZE, $entity );
			$batch_size = count( $batch );

			foreach ( $batch as $item ) {
				$after_id = (int) $item['id'];

				foreach ( $this->rows_for_item( $item, $only_warnings ) as $row ) {
					$emit( $row );
				}
			}
		} while ( self::PAGE_SIZE === $batch_size );
	}

	/**
	 * 1アイテム×1警告=1行に展開する。警告ゼロのアイテムは`only_warnings`がfalseの場合のみ、
	 * 警告列を空にした1行を出す。
	 *
	 * @param array<string,mixed> $item
	 * @return array<int,array<int,string>>
	 */
	private function rows_for_item( array $item, bool $only_warnings ): array {
		$decoded  = json_decode( (string) $item['warnings_json'], true );
		$warnings = is_array( $decoded ) ? $decoded : [];

		$base = [
			(string) $item['entity'],
			(string) $item['remote_id'],
			(string) $item['label'],
			(string) $item['operation'],
			(string) $item['existing_local_id'],
		];

		if ( [] === $warnings ) {
			return $only_warnings ? [] : [ array_map( [ self::class, 'harden' ], array_merge( $base, [ '', '', '' ] ) ) ];
		}

		$rows = [];

		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) ) {
				continue;
			}

			[ $code, $detail ] = WarningCode::split( $warning );
			$note              = WarningCode::indicates_unresolved_reference( [ $warning ] ) ? 'reference_pending_import' : '';

			$rows[] = array_map(
				[ self::class, 'harden' ],
				array_merge( $base, [ $code, $detail ?? '', $note ] )
			);
		}

		return $rows;
	}

	/**
	 * OWASP CSV Injection対策。`=`/`+`/`-`/`@`/タブ/CRで始まるセルは、Excel等が数式として
	 * 評価しうる（`=cmd|'/c calc'!A1`等）。先頭に単一引用符を付けて無害化する。
	 * ASP由来の商品名・警告detailが値に入るため必須。
	 */
	private static function harden( string $value ): string {
		// 制御文字を除去する（改行はCSVとしてはクォートされるが、値の途中にある生の制御文字は
		// 表計算ソフトによって解釈が割れるため）。
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );

		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}

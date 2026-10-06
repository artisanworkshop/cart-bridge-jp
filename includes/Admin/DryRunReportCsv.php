<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Admin;

use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;

/**
 * dry-run結果（`cbjp_dry_run_items`）のCSVレポート生成（D17「変換結果・警告を全量出力」）。
 * REST配管（`RestController::get_run_report()`）から分離し、PHPUnitから直接検証できるようにする。
 *
 * 保存結果に依存する警告（`Woo\WarningCode`のdocblock参照。保存失敗・名前衝突の実体解決・
 * 画像ダウンロード等）は dry-run では判定していないため、このCSVには現れない。
 *
 * 末尾の `severity`・`message`・`action` は `Woo\WarningCatalog` の説明（R3-0k）。同じ警告コードでも取込みと
 * エクスポートで意味が違うため、run の種別から向きを決めて渡す（{@see direction_for_job_type()}）。
 */
final class DryRunReportCsv {

	private const PAGE_SIZE = 500;

	/**
	 * 列見出しは機械可読な安定キー。翻訳しない（表計算ソフトのフィルタ・外部ツールが参照するため）。
	 * 列は末尾に足す（既存の列の位置を変えない）。`severity` の値も翻訳しない安定キーで、`message`・`action` だけが翻訳される。
	 *
	 * @var array<int,string>
	 */
	private const HEADER = [ 'entity', 'remote_id', 'label', 'operation', 'existing_local_id', 'warning_code', 'warning_detail', 'note', 'severity', 'message', 'action' ];

	public function __construct( private readonly DryRunItemRepository $items ) {}

	/**
	 * run の種別（`cbjp_jobs.type`）から警告を説明する向きを決める。dry-run の明細を書くのは dry-run だけだが、
	 * 本実行の run を指定された場合も同じ向きにする。知らない種別は空文字列（カタログは `unknown` で説明する）。
	 */
	public static function direction_for_job_type( string $type ): string {
		return match ( $type ) {
			JobManager::TYPE_DRY_RUN, JobManager::TYPE_IMPORT => WarningCatalog::IMPORT,
			JobManager::TYPE_DRY_RUN_EXPORT, JobManager::TYPE_EXPORT => WarningCatalog::EXPORT,
			default => '',
		};
	}

	/**
	 * `php://output`へ直接書き出す（全行をメモリに載せない）。
	 *
	 * @param string $direction `WarningCatalog::IMPORT`/`EXPORT`（{@see direction_for_job_type()}）。
	 */
	public function stream( string $run_id, ?string $entity, bool $only_warnings, string $direction ): void {
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
			$direction,
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
	 * @param string $direction `WarningCatalog::IMPORT`/`EXPORT`。
	 * @return array<int,array<int,string>>
	 */
	public function rows( string $run_id, ?string $entity, bool $only_warnings, string $direction ): array {
		$rows = [];

		$this->each_row(
			$run_id,
			$entity,
			$only_warnings,
			$direction,
			static function ( array $row ) use ( &$rows ): void {
				$rows[] = $row;
			}
		);

		return $rows;
	}

	/**
	 * @param callable(array<int,string>):void $emit
	 */
	private function each_row( string $run_id, ?string $entity, bool $only_warnings, string $direction, callable $emit ): void {
		$after_id   = 0;
		$batch_size = self::PAGE_SIZE;

		do {
			$batch      = $this->items->list_after( $run_id, $after_id, self::PAGE_SIZE, $entity );
			$batch_size = count( $batch );

			foreach ( $batch as $item ) {
				$after_id = (int) $item['id'];

				foreach ( $this->rows_for_item( $item, $only_warnings, $direction ) as $row ) {
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
	private function rows_for_item( array $item, bool $only_warnings, string $direction ): array {
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
			return $only_warnings ? [] : [ array_map( [ self::class, 'harden' ], array_merge( $base, [ '', '', '', '', '', '' ] ) ) ];
		}

		$rows = [];

		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) ) {
				continue;
			}

			[ $code, $detail ] = WarningCode::split( $warning );
			$note              = match ( true ) {
				WarningCode::indicates_mapping_required( $warning ) => 'mapping_required',
				// D26: WooCommerce の税の設定（軽減税率の税区分と JP の 8% の税率）を先に作るよう促す。
				WarningCode::indicates_tax_setup_required( $warning ) => 'tax_setup_required',
				WarningCode::indicates_pending_export( $warning ) => 'reference_pending_export',
				WarningCode::indicates_order_reference_unresolved( $warning ) => 'reference_unresolved',
				WarningCode::indicates_pending_import( $warning ) => 'reference_pending_import',
				default => '',
			};

			$description = WarningCatalog::describe( $warning, $direction );

			$rows[] = array_map(
				[ self::class, 'harden' ],
				array_merge( $base, [ $code, $detail ?? '', $note, $description['severity'], $description['message'], $description['action'] ] )
			);
		}

		return $rows;
	}

	/**
	 * OWASP CSV Injection対策。`=`/`+`/`-`/`@`で始まるセルは、Excel等が数式として
	 * 評価しうる（`=cmd|'/c calc'!A1`等）。先頭に単一引用符を付けて無害化する。
	 * ASP由来の商品名・警告detailが値に入るため必須。
	 */
	private static function harden( string $value ): string {
		// タブ・CR・LFを含む全てのASCII制御文字を除去する。`fputcsv()`は値中の改行を
		// クォートで囲むためCSVとしては壊れないが、生の改行・タブが値の途中に残っていると
		// 表計算ソフトで1セルが複数行に見えたり、改行区切り前提の後続パーサーで行構造が
		// 崩れて見えることがある。数式判定より前に取り除く（除去後は先頭がタブ/CRになり
		// 得ないため、判定対象は`=`/`+`/`-`/`@`の4種のみでよい）。
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@' ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}

<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\Transform\Cast;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\TokenStore;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * 認証済みの ColorMe API の呼び出しと、応答の共通処理（一覧のエンベロープ・`meta.total`・カーソル・ID 指定取得・行ごとの変換失敗の記録）。
 *
 * `ColorMeAdapter::api()` が返す。Pro アドオンが ColorMe の顧客・受注・クーポンを取得・送信するとき（R3-6c1 の `ColorMeCommerceAdapter`）に、
 * 同じ処理を重複して持たずに使う口（R3-6b1。`docs/03-design-decisions.md` §10.0「Pro が使ってよい無料版の API」）。
 * レート制限はプラットフォーム単位のバケット（`ColorMeClient::for_access_token()`）を共有する。
 *
 * `client()` は呼ぶたびにトークンを読む（接続の解除・再接続をまたいでも古いトークンを使わず、未接続なら呼んだ位置で
 * `not_connected` の `ApiException` を投げる）。
 */
final class ColorMeApi {

	/**
	 * 商品・顧客・在庫の一覧APIページサイズ。`products.json`/`stocks.json` の上限（50）に合わせる
	 * （`customers.json`/`sales.json` は上限100だが、全エンドポイント共通の値に揃える）。
	 */
	public const PAGE_SIZE = 50;

	public function __construct(
		private readonly TokenStore $token_store,
		private readonly Logger $logger
	) {}

	public function client(): ColorMeClient {
		$access_token = (string) ( $this->token_store->get()['access_token'] ?? '' );

		if ( '' === $access_token ) {
			// ステータス 0 は通信断・JSON 破損でも使われるため、呼び出し側（push intent の解除・`Sync\Exporter` 等）が
			// 「再接続が必要」と区別できるよう、未接続であることを文脈で明示する。
			throw new ApiException( 'ColorMe adapter is not connected.', 0, [ 'not_connected' => true ] );
		}

		return ColorMeClient::for_access_token( $access_token );
	}

	/**
	 * `id.json`単体取得エンドポイント共通のラッパー。404は契約どおりnullに変換する。
	 *
	 * @return ?array<string,mixed>
	 */
	public function fetch_single( string $path, string $envelope_key ): ?array {
		try {
			$body = $this->client()->get( $path );
		} catch ( ApiException $exception ) {
			if ( 404 === $exception->status_code() ) {
				return null;
			}

			throw $exception;
		}

		$item = $body[ $envelope_key ] ?? null;

		if ( is_array( $item ) ) {
			return $item;
		}

		// 200応答でも envelope キー自体が欠損、またはその中身が期待した配列でない場合
		// （スキーマ変更・プロキシ異常等）を無言でnullにすると、404（=正当な削除済み）と
		// 区別が付かなくなる。呼び出し側（`Woo\Tools\PushIntentResolver`）はnullを「リモートに実体が
		// 無い」と解釈するため、そのままだと実在する実体を無いものとして扱ってしまう。例外を投げて
		// 呼び出し側に失敗として知らせる（`list_from()`と同じ方針）。
		throw new RuntimeException( "ColorMe \"{$path}\" returned a 200 response but its \"{$envelope_key}\" envelope was missing or not an array." );
	}

	/**
	 * `sale`は`payment_id`/`delivery_id`のみを持ち名称を含まないため、`OrderTransformer`が
	 * 参照する`id => name`マップをここで組み立てる（同クラスdocblock参照）。
	 *
	 * @return array<int,string>
	 */
	public function id_name_map( string $path, string $envelope_key ): array {
		$rows = $this->list_from( $this->client()->get( $path ), $envelope_key );
		$map  = [];

		foreach ( $rows as $row ) {
			$id   = Cast::to_int_or_null( $row['id'] ?? null );
			$name = Cast::to_string_or_null( $row['name'] ?? null );

			if ( null !== $id && null !== $name ) {
				$map[ $id ] = $name;
			}
		}

		return $map;
	}

	/**
	 * 一覧エンベロープキー（例: `products`）はAPI契約上必ず配列で返る前提。キー自体の欠損や
	 * 非配列値はショップの仕様変更・プロキシ異常等によるスキーマ崩壊であり、`[]`（正当な0件）と
	 * 区別せず返すと、呼び出し元がページ終端と誤認しジョブを「完了」させてしまい、
	 * データ欠落がリトライ可能な失敗として表面化しない（フェイルクローズ原則。CLAUDE.md）。
	 * ここで例外を投げ`JobManager`の`catch(Throwable)`でジョブを失敗させる。
	 *
	 * @param array<string,mixed> $body
	 * @return array<int,array<string,mixed>>
	 */
	public function list_from( array $body, string $key ): array {
		$list = $body[ $key ] ?? null;

		if ( ! is_array( $list ) ) {
			throw new RuntimeException( "ColorMe API response is missing the expected \"{$key}\" list envelope." );
		}

		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	public function total_from_meta( array $body ): ?int {
		$meta = $body['meta'] ?? null;

		return is_array( $meta ) ? self::exact_int_or_null( $meta['total'] ?? null ) : null;
	}

	/**
	 * `meta.total`はページング終端の境界値として使うため、`Cast::to_int_or_null()`の暗黙の
	 * 切り捨て（例: 50.5→50件目までしか無いページを「50件で完了」と誤認）をそのまま許すと、
	 * 実際にはより多くの行が残るページを誤って終端と判定しかねない。整数として厳密に
	 * 表現できる値のみ受け付け、小数はnullに倒す（null＝「総件数不明」として`next_cursor()`が
	 * 空ページに達するまで継続する）。
	 */
	public static function exact_int_or_null( mixed $value ): ?int {
		if ( is_string( $value ) && is_numeric( $value ) ) {
			$value = $value + 0;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		return is_float( $value ) && (float) (int) $value === $value ? (int) $value : null;
	}

	/**
	 * `list_from()`はis_array()フィルタ後の配列を返すため、非配列要素が混入したページでは
	 * その件数がAPI側の実際のページ内行数より少なくなりうる。カーソルのoffset計算を
	 * フィルタ後の件数で行うと、次ページのoffsetがAPI側の絶対位置より手前になり、
	 * 除外された行を含むページと次のページが重複し、重複取込・重複書込を招く
	 * （upsertのため実害は軽微だが、レート制限を無駄に消費する）。offset計算には
	 * 必ずフィルタ前の生の行数を使う。
	 *
	 * @param array<string,mixed> $body
	 */
	public function raw_row_count( array $body, string $key ): int {
		$list = $body[ $key ] ?? null;

		return is_array( $list ) ? count( $list ) : 0;
	}

	/**
	 * `meta.total`が得られる場合はそれで終端判定し、得られない場合（categories/groups/
	 * shop_couponsの単発取得を除くページング系）はページサイズ未満の取得件数を終端の合図にする。
	 *
	 * `$raw_count`は`list_from()`によるフィルタ前の生の行数（`raw_row_count()`）を渡すこと。
	 * フィルタ後の件数を渡すと、次ページのoffsetがAPI側の絶対位置より手前になり重複取得を招く
	 * （offset計算はAPI側のページ内行数と対応させる必要があるため）。
	 */
	public function next_cursor( int $offset, int $raw_count, ?int $total ): ?Cursor {
		// 0件取得時は無条件に終端とする。`meta.total`がoffsetより大きい値を報告していても
		// （並行削除等で0件になった場合）offsetを進めるすべが無く、同じoffsetのCursorを返すと
		// JobManagerが同一ページを無限に再エンキューし続けてしまう。
		if ( 0 === $raw_count ) {
			return null;
		}

		$next_offset = $offset + $raw_count;

		// `meta.total`が負値、またはここまでの累計行数（`$next_offset`）にも満たない不整合な値
		// （スキーマ崩壊・プロキシ異常等）の場合は、totalを信頼できないとみなし「総件数不明」と
		// 同じ扱い（下のフォールバック＝空ページに達するまで継続）に倒す。不整合なtotalを
		// そのまま終端判定に使うと、実際にはまだ残っている行を含むページを「完了」と誤認し、
		// 静かな部分移行を招く（フェイルクローズ原則）。
		if ( null !== $total && $total >= $next_offset ) {
			return $next_offset < $total ? new Cursor( [ 'offset' => $next_offset ] ) : null;
		}

		// `meta.total`が得られない（または上記で信頼できないと判定された）場合、「取得件数が
		// ページサイズ未満＝最終ページ」とは推測しない（APIが実際にはページサイズ分の行を
		// 返していても、`$raw_count`自体がそのままページサイズと一致しない構成のエンドポイントが
		// ありうるため）。0件になるまで走査を続ける（安全側=継続に倒す）。
		return new Cursor( [ 'offset' => $next_offset ] );
	}

	/**
	 * 1行の変換失敗（例: id欠損の`RuntimeException`）でページ全体を落とさないための共通ラッパー。
	 * `Importer`の1件例外保護は`WooWriter::write()`周りにしか無く、fetch/transform段はここで担う。
	 *
	 * @template T
	 *
	 * @param array<int,array<string,mixed>>   $raw_items
	 * @param callable(array<string,mixed>):?T $transform
	 * @return array<int,T>
	 */
	public function transform_rows( array $raw_items, callable $transform, string $entity ): array {
		return $this->transform_rows_flat(
			$raw_items,
			static function ( array $raw ) use ( $transform ): array {
				$item = $transform( $raw );

				return null !== $item ? [ $item ] : [];
			},
			$entity
		);
	}

	/**
	 * `transform_rows()`の1件=0..N件版（category/stockのように1行から複数モデルを生成する場合）。
	 *
	 * @template T
	 *
	 * @param array<int,array<string,mixed>>             $raw_items
	 * @param callable(array<string,mixed>):array<int,T> $transform
	 * @return array<int,T>
	 */
	public function transform_rows_flat( array $raw_items, callable $transform, string $entity ): array {
		$result = [];

		foreach ( $raw_items as $raw ) {
			try {
				$items = $transform( $raw );

				// Pro アドオンが渡すコールバックの戻り値の型は docblock の契約でしかない（原則 8）。配列でなければ、この 1 行の変換失敗として
				// 記録して飛ばす（追加の時点で TypeError になり、ページ全体を落とさないように）。
				if ( ! is_array( $items ) ) {
					throw new UnexpectedValueException( 'The transform callback must return an array of models.' );
				}
			} catch ( Throwable $exception ) {
				$this->log_transform_failure( $entity, $raw, $exception );
				continue;
			}

			array_push( $result, ...array_values( $items ) );
		}

		return $result;
	}

	/**
	 * `Support\Logger`の個人情報禁止ルール（`Importer`の同種catch節と同じ方針）に従い、
	 * remote_idと例外クラス名のみを記録する（例外メッセージ自体は含めない）。
	 *
	 * @param array<string,mixed> $raw
	 */
	public function log_transform_failure( string $entity, array $raw, Throwable $exception ): void {
		$this->logger->error(
			"Failed to transform a ColorMe \"{$entity}\" row.",
			[
				// `??`は空文字を「設定済み」とみなし`id_big`へフォールバックしない
				// （`id`が空文字の壊れた行でカテゴリー由来の`id_big`を拾えなくなる）ため、
				// `Cast::first_non_empty()`で空文字も未設定として扱う。
				'remote_id' => Cast::first_non_empty( $raw['id'] ?? null, $raw['id_big'] ?? null ),
				'exception' => $exception::class,
			]
		);
	}
}

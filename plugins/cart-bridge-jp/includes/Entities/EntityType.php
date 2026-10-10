<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\EntityWriter;
use RuntimeException;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- 既定実装は引数を使わない（継承した種類が使う）。

/**
 * 移行する実体の種類（カテゴリ・商品・顧客・受注 …）。取込み・エクスポート・ツール・警告・マッピングで種類ごとに違う部分をまとめる
 * （R3-6b1。`docs/03-design-decisions.md` §10.0「実体の種類の拡張点」）。
 *
 * 無料版の商品系（カテゴリ・タグ・商品・在庫・レビュー）は `EntityTypeRegistry` が内部で登録し、それ以外は
 * `cbjp/entity_types/register` フィルターで登録する（Pro アドオンが顧客・受注・クーポンを足す口。R3-6c まで無料版自身もこの口から登録する）。
 *
 * **互換方針（D20 と同じ）**: Pro・外部コードはこのクラスを継承する。抽象メソッドは `key()`・`label()`・`position()` だけで、
 * ほかは既定実装を持つ。v1.0.0 公開後はシグネチャを変えず、新しいメソッドは既定実装つきで足す（`EntityTypeContractTest` が固定する）。
 * 既定実装は「その種類では扱わない」ことを、今の実体ごとの分岐の既定のアームと同じ結果で表す。null・空配列など正常な結果と区別できない値を
 * 既定にしない（原則 9。例: `fetch_by_remote_id()` の null は「リモートに無い」の意味なので、既定は例外）。
 *
 * 呼び出し側（`JobManager`・`Importer`・`Exporter`・ツール・REST）は、外部の種類のメソッドが例外を投げても全体を落とさないよう、
 * 能力の判定・一覧の組み立てでは握って「非対応」に倒す（原則 8）。1 件の処理中の例外は従来どおりジョブ・アイテムの失敗になる。
 */
abstract class EntityType {

	/**
	 * 種類のキー。ジョブの `entity`・`cbjp_mappings.entity_type`・dry-run の行・push intent に保存される（DB の列は varchar(20)）。
	 * `^[a-z][a-z0-9_]{0,19}$`。一度公開したキーは変えない。
	 */
	abstract public function key(): string;

	/**
	 * 画面に出す名前（複数形。例: `Orders`）。呼ばれるたびに翻訳する（言語を切り替えて書く CSV があるため、結果を保持しない）。
	 */
	abstract public function label(): string;

	/**
	 * 実行順（小さいほど先）。取込み・エクスポートともこの順にジョブを作る。参照先を先に移すため、無料版の種類は
	 * category 10・tag 20・product 30・stock 60・review 80、顧客・受注・クーポンは customer 40・order 50・coupon 70（公開の契約）。
	 */
	abstract public function position(): int;

	/**
	 * この接続先から取り込めるか（能力の判定）。
	 */
	public function supports_import( PlatformAdapter $adapter ): bool {
		return false;
	}

	/**
	 * 接続先から 1 ページ取得する。
	 *
	 * @throws RuntimeException 取り込めない種類（`supports_import()` が偽の種類はジョブにならないので、通常は届かない）。
	 */
	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		throw new RuntimeException( "Entity \"{$this->key()}\" is not a cursor-walk entity." );
	}

	/**
	 * Woo へ書き込む Writer。null の種類は書かずに `ENTITY_NOT_SUPPORTED` でスキップする（`Woo\WooRepository`）。
	 * ファクトリの 1 回の組み立て（1 ページ）につき 1 回呼ばれ、ページ内の全アイテムで同じインスタンスを使う。
	 */
	public function writer( string $platform, WooServices $services ): ?EntityWriter {
		return null;
	}

	/**
	 * この接続先へエクスポートできるか（能力の判定。`reader()` を持つ種類だけが真を返すこと）。
	 */
	public function supports_export( PlatformAdapter $adapter ): bool {
		return false;
	}

	/**
	 * エクスポートがベータ扱いか（D24。画面が「Beta」と表示し、既定では選ばない）。可否そのものは `supports_export()` が決める。
	 */
	public function is_export_beta( PlatformAdapter $adapter ): bool {
		return false;
	}

	/**
	 * Export タブの選択肢の説明（何をするか。例: 受注は「接続先に売上を作る」）。画面は空なら出さない。ベータの注意書きは画面が足す。
	 * 呼ばれるたびに翻訳する（結果を保持しない）。
	 */
	public function export_description( PlatformAdapter $adapter ): string {
		return '';
	}

	/**
	 * Woo から読み出す Reader（エクスポート用）。`writer()` と同じく 1 ページに 1 回。
	 */
	public function reader( string $platform, WooServices $services ): ?EntityReader {
		return null;
	}

	/**
	 * 接続先へ送る。`$remote_id` が null なら作成、そうでなければ更新（`PlatformAdapter::push_product()` の契約〔D21-A〕に従う）。
	 * 既定は送らずにスキップする（今の `AdapterPlatformWriter` の既定のアームと同じ）。
	 */
	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		return new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] );
	}

	/**
	 * 作成を伴う送信の前に push intent を残すか（D21-B。`push()` が `$remote_id` で作成・更新を分ける種類）。
	 */
	public function records_push_intent(): bool {
		return false;
	}

	/**
	 * リモートの実体を ID で 1 件取得する（push intent の「リンクして解除」が実在を確かめる。404 は null）。
	 *
	 * @throws UnsupportedOperationException 取得できない種類（解除は LINK_UNSUPPORTED になる）。
	 * @throws ApiException 接続先のエラー（未接続・レート制限・5xx。解除はその区分で失敗する）。
	 * @throws RateLimitExhaustedException クライアント側のスロットル。
	 */
	public function fetch_by_remote_id( PlatformAdapter $adapter, string $remote_id ): ?CanonicalModel {
		throw new UnsupportedOperationException( $adapter->id(), 'fetch_' . $this->key() . '_by_remote_id' );
	}

	/**
	 * push intent の一覧に出す Woo 側の実体の説明（`Woo\Tools\PushIntentPresenter`）。`summary` は画面に出す 1 行（店舗が ASP の管理画面で
	 * 実体を探す手がかり。R3-6b2）、`details` はその元の値。個人情報（メール・受注番号）を含みうるので画面と REST の応答だけに使い、ログに渡さない。
	 *
	 * @return array{exists:bool,edit_url:?string,summary:string,details:array<string,mixed>}
	 */
	public function describe_local( int $local_id ): array {
		return [
			'exists'   => false,
			'edit_url' => null,
			'summary'  => '',
			'details'  => [],
		];
	}

	/**
	 * D25: mapping が指す Woo の実体がエクスポートで結ばれたか（取込みで上書きしない）。`$platform` が空の判定は窓口
	 * （`Woo\Support\EntityOrigin::is_linked_by_export()`）が先に行う。
	 */
	public function is_linked_by_export( string $platform, int $local_id ): bool {
		return false;
	}

	/**
	 * リンク再構築（`Woo\Tools\MappingRebuilder`）が走査する Woo 側の実体。
	 *
	 * @return array<int,LinkSource>
	 */
	public function link_sources(): array {
		return [];
	}

	/**
	 * 移行後検証レポート: mapping が指す Woo の実体のうち、今も実在するもの。null は「確かめられない」（既定）。
	 *
	 * @param array<int,int> $local_ids
	 * @return array<int,int>|null
	 */
	public function existing_local_ids( array $local_ids ): ?array {
		return null;
	}

	/**
	 * 移行後検証レポート: 取り込んだアイテムの ASP 側の金額（1/100 単位）。金額を突合しない種類は null。
	 */
	public function remote_amount( CanonicalModel $item ): ?int {
		return null;
	}

	/**
	 * 移行後検証レポート: 実在する Woo の実体の金額の合計と通貨。金額を突合しない種類は null。
	 *
	 * @param array<int,int> $local_ids
	 * @return array{total_minor:int,currencies:array<int,string>}|null
	 */
	public function local_amount_summary( array $local_ids ): ?array {
		return null;
	}

	/**
	 * dry-run の CSV の `label` 列（人が読める識別子）。個人情報（氏名・メール）を含みうる種類は空にする（`Sync\DryRunLabel`）。
	 */
	public function dry_run_label( CanonicalModel $item ): string {
		return '';
	}

	/**
	 * この種類が出す警告コードと、その判定の印（`WarningFlag`）。無料版の一覧にあるコードへの印は無視される。
	 * 印の無いコードも、カタログの文言があるなら空の配列で載せる（`WarningCatalogTest` が両方向の文言を確かめる）。
	 *
	 * @return array<string,array<int,string>> コード => 印の一覧。
	 */
	public function warning_flags(): array {
		return [];
	}

	/**
	 * 警告の店舗向けの説明（dry-run の CSV）。`$row_entity` はその警告を持つ行の種類。説明しないコードは null。
	 * 呼ばれるたびに翻訳する（結果を保持しない）。
	 */
	public function describe_warning( string $code, bool $import, string $row_entity ): ?WarningText {
		return null;
	}

	/**
	 * この種類が使うマッピング設定（`cbjp_settings_{platform}` の `{key}_map`）。
	 *
	 * @return array<int,MappingKind>
	 */
	public function mapping_kinds(): array {
		return [];
	}
}

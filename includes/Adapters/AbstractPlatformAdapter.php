<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * 外部（Pro版・サードパーティ）アダプタが継承すべき基底クラス（`docs/03-design-decisions.md` §2 D20）。
 *
 * v1.0.0 公開後、`PlatformAdapter` に新しいメソッドを足す場合は必ずここへ既定実装を同時に置く
 * （既定実装が無い抽象メソッドの追加は、このクラスを継承した外部実装を fatal にしない、という
 * 互換保証を破る）。既定実装は原則 `UnsupportedOperationException` を投げる。ただし null・空配列
 * など「正常な結果」と区別できない戻り値を既定にしないこと（原則9。フェイルクローズ回避を防ぐ）。
 *
 * v1.0.0 公開前はこのクラスへ既定実装を置かずインターフェースへ直接追加してよい（D19/D20が許容する
 * 期間中の前例: `mapping_candidates()` D19、`push_order()`の`$remote_id`追加 #45、
 * `fetch_order_by_remote_id()` #46）。`AbstractPlatformAdapterTest::BASELINE` を更新すること。
 *
 * `push_*()` を実装するときの契約（D21-A。`PlatformAdapter::push_product()` のdocblock参照）:
 * 作成（`$remote_id === null`）が確定した後に起きた例外は、素のまま外へ出さず
 * {@see PartialPushException}（remote_id付き）に包んで投げる。`Sync\Exporter` がそのremote_idを
 * 書き留め、次回のexportを作成ではなく更新にするため、重複作成を防げる。これは例外クラスと
 * 挙動の契約の追加であり、`PlatformAdapter` のシグネチャは変えない（D20 の BASELINE は不変）。
 */
abstract class AbstractPlatformAdapter implements PlatformAdapter {}
